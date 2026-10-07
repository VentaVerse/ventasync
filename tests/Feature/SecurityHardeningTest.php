<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\Catalog\Product;
use App\Models\Extension;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private array $extensionDirsToClean = [];

    private array $tmpImageDirsToClean = [];

    protected function tearDown(): void
    {
        foreach ($this->extensionDirsToClean as $id) {
            File::deleteDirectory(base_path('extensions/' . $id));
        }

        foreach ($this->tmpImageDirsToClean as $dir) {
            Storage::disk('public')->deleteDirectory($dir);
        }

        parent::tearDown();
    }

    private function userWithPermission(string $key): User
    {
        $group = UserGroup::create(['name' => 'Security Test ' . $key . ' ' . uniqid()]);

        $group->permissions()->attach(
            Permission::where('key', $key)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function tinyJpegBytes(): string
    {
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($im);
        $bytes = ob_get_clean();

        return $bytes;
    }

    public function test_import_url_rejects_loopback_address_without_making_a_request(): void
    {
        Http::fake();

        $user = $this->userWithPermission('manage_catalog/product_image');

        $response = $this->actingAs($user)->postJson(route('products.images.import_url'), [
            'url' => 'http://127.0.0.1/image.jpg',
            'token' => 'sec-test-loopback',
        ]);

        $response->assertStatus(422)->assertJson(['ok' => false]);
        Http::assertNothingSent();
    }

    public function test_import_url_rejects_private_range_address_without_making_a_request(): void
    {
        Http::fake();

        $user = $this->userWithPermission('manage_catalog/product_image');

        $response = $this->actingAs($user)->postJson(route('products.images.import_url'), [
            'url' => 'http://192.168.1.50/image.jpg',
            'token' => 'sec-test-private',
        ]);

        $response->assertStatus(422)->assertJson(['ok' => false]);
        Http::assertNothingSent();
    }

    public function test_import_url_still_succeeds_for_a_legitimate_public_url(): void
    {
        Http::fake([
            '*' => Http::response($this->tinyJpegBytes(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $user = $this->userWithPermission('manage_catalog/product_image');
        $token = 'sec-test-public-' . uniqid();
        $this->tmpImageDirsToClean[] = 'tmp/product-images/' . preg_replace('/[^A-Za-z0-9_-]/', '', $token);

        $response = $this->actingAs($user)->postJson(route('products.images.import_url'), [
            'url' => 'http://8.8.8.8/image.jpg',
            'token' => $token,
        ]);

        $response->assertStatus(200)->assertJson(['ok' => true, 'kind' => 'temp']);
        Http::assertSent(function ($request) {
            return $request->url() === 'http://8.8.8.8/image.jpg';
        });
    }

    private function buildZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sechardening') . '.zip';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    private function uploadedZip(string $path, string $clientName): UploadedFile
    {
        return new UploadedFile($path, $clientName, 'application/zip', null, true);
    }

    public function test_install_rejects_archive_with_traversal_entry_and_writes_nothing_outside_tmp(): void
    {
        $user = $this->userWithPermission('manage_settings/extension');

        $zipPath = $this->buildZip([
            '../evil.txt' => 'pwned',
            'extension.json' => json_encode(['id' => 'sec_test_zipslip', 'name' => 'Zip Slip']),
        ]);

        $tmpDirsBefore = glob(storage_path('app/tmp-ext-*'));

        try {
            $response = $this->actingAs($user)->post(route('extensions.install'), [
                'file' => $this->uploadedZip($zipPath, 'zipslip.zip'),
            ]);

            $response->assertStatus(422);
            $this->assertFileDoesNotExist(storage_path('app/evil.txt'));
            $this->assertSame(
                $tmpDirsBefore,
                glob(storage_path('app/tmp-ext-*')),
                'no orphaned extraction directory should survive a rejected archive'
            );
            $this->assertNull(Extension::find('sec_test_zipslip'));
        } finally {
            @unlink($zipPath);
        }
    }

    public function test_install_rejects_manifest_id_containing_traversal(): void
    {
        $user = $this->userWithPermission('manage_settings/extension');

        $zipPath = $this->buildZip([
            'extension.json' => json_encode(['id' => '../../x', 'name' => 'Bad Id']),
        ]);

        $extensionsBefore = scandir(base_path('extensions'));

        try {
            $response = $this->actingAs($user)->post(route('extensions.install'), [
                'file' => $this->uploadedZip($zipPath, 'badid.zip'),
            ]);

            $response->assertStatus(422);
            $this->assertSame($extensionsBefore, scandir(base_path('extensions')));
        } finally {
            @unlink($zipPath);
        }
    }

    public function test_install_still_works_for_a_clean_fixture_archive(): void
    {
        $user = $this->userWithPermission('manage_settings/extension');

        $id = 'sec_test_clean_fixture';
        $this->extensionDirsToClean[] = $id;

        $zipPath = $this->buildZip([
            'extension.json' => json_encode([
                'id' => $id,
                'name' => 'Security Hardening Fixture',
                'version' => '1.0.0',
            ]),
        ]);

        try {
            $response = $this->actingAs($user)->post(route('extensions.install'), [
                'file' => $this->uploadedZip($zipPath, 'clean.zip'),
            ]);

            $response->assertRedirect();
            $response->assertSessionHas('success');
            $this->assertFileExists(base_path("extensions/{$id}/extension.json"));
        } finally {
            @unlink($zipPath);
        }
    }

    private function testProduct(): Product
    {
        return Product::create([
            'model' => 'SEC-HARDEN-' . uniqid(),
            'sku' => 'SEC-HARDEN-SKU-' . uniqid(),
            'price' => 10,
            'quantity' => 1,
            'status' => 1,
        ]);
    }

    private function updatePayload(Product $product, string $returnUrl): array
    {
        return [
            'name' => 'Security Hardening Test Product',
            'model' => $product->model,
            'sku' => $product->sku,
            'price' => 10,
            'quantity' => 1,
            'status' => 1,
            'weight' => '0.5', 'length' => '10', 'width' => '5', 'height' => '3',
            'description' => 'A dependable test product description that comfortably clears the eighty character floor the marketplaces set.',
            'images_json' => '["catalog/demo.jpg"]',
            '_return' => $returnUrl,
        ];
    }

    public function test_update_return_url_falls_back_for_absolute_off_site_url(): void
    {
        $user = $this->userWithPermission('manage_catalog/product');
        $product = $this->testProduct();

        $response = $this->actingAs($user)->put(
            route('products.update', $product->product_id),
            $this->updatePayload($product, 'https://evil.example')
        );

        $response->assertRedirect(route('products.index'));
    }

    public function test_update_return_url_falls_back_for_protocol_relative_url(): void
    {
        $user = $this->userWithPermission('manage_catalog/product');
        $product = $this->testProduct();

        $response = $this->actingAs($user)->put(
            route('products.update', $product->product_id),
            $this->updatePayload($product, '//evil.example')
        );

        $response->assertRedirect(route('products.index'));
    }

    public function test_update_return_url_is_honoured_when_site_relative(): void
    {
        $user = $this->userWithPermission('manage_catalog/product');
        $product = $this->testProduct();

        $response = $this->actingAs($user)->put(
            route('products.update', $product->product_id),
            $this->updatePayload($product, '/catalog/products?page=2')
        );

        $response->assertRedirect('/catalog/products?page=2');
    }

    private function xssAndLegitPayload(): string
    {
        return '<p>Legit paragraph with enough honest words about the product to clear the eighty character description floor.</p><b>Bold text</b><ul><li>One</li><li>Two</li></ul>'
            . '<a href="https://example.com/page">Good link</a>'
            . '<script>alert(2)</script>'
            . '<img src="javascript:alert(3)" onerror="alert(4)">'
            . '<a href="javascript:alert(5)">bad link</a>';
    }

    private function expectedSanitizedPayload(): string
    {
        return '<p>Legit paragraph with enough honest words about the product to clear the eighty character description floor.</p><b>Bold text</b><ul><li>One</li><li>Two</li></ul>'
            . '<a href="https://example.com/page">Good link</a><img><a>bad link</a>';
    }

    private function legitOnlyPayload(): string
    {
        return '<p>Legit paragraph with enough honest words about the product to clear the eighty character description floor.</p><b>Bold text</b><ul><li>One</li><li>Two</li></ul>'
            . '<a href="https://example.com/page">Good link</a>';
    }

    private function createCategory(User $user, string $name): int
    {
        $response = $this->actingAs($user)->post(route('categories.store'), [
            'name' => $name,
            'status' => '1',
        ]);
        $response->assertRedirect(route('categories.index'));

        return (int) DB::table(config('catalog.prefix').'category_description')
            ->where('name', $name)->value('category_id');
    }

    public function test_product_store_sanitises_description_stripping_script_onerror_and_javascript_href(): void
    {
        $user = $this->userWithPermission('manage_catalog/product');

        $response = $this->actingAs($user)->post(route('products.store'), [
            'name' => 'Sec Test XSS Product ' . uniqid(),
            'model' => 'SEC-XSS-' . uniqid(),
            'sku' => 'SEC-XSS-SKU-' . uniqid(),
            'price' => 10,
            'quantity' => 1,
            'status' => 1,
            'weight' => '0.5', 'length' => '10', 'width' => '5', 'height' => '3',
            'description' => $this->xssAndLegitPayload(),
            'images_json' => '["catalog/demo.jpg"]',
        ]);

        $response->assertRedirect(route('products.index'));

        $stored = DB::table(config('catalog.prefix').'product_description')
            ->where('meta_title', 'like', 'Sec Test XSS Product %')
            ->value('description');

        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertSame($this->expectedSanitizedPayload(), $stored);
    }

    public function test_product_update_sanitises_description_stripping_script_onerror_and_javascript_href(): void
    {
        $user = $this->userWithPermission('manage_catalog/product');
        $product = $this->testProduct();

        $response = $this->actingAs($user)->put(
            route('products.update', $product->product_id),
            array_merge($this->updatePayload($product, '/catalog/products'), [
                'description' => $this->xssAndLegitPayload(),
            ])
        );

        $response->assertRedirect('/catalog/products');

        $langId = (int) config('catalog.default_language_id');
        $stored = DB::table(config('catalog.prefix').'product_description')
            ->where('product_id', $product->product_id)
            ->where('language_id', $langId)
            ->value('description');

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertSame($this->expectedSanitizedPayload(), $stored);
    }

    public function test_product_update_preserves_legitimate_formatting_byte_for_byte(): void
    {
        $user = $this->userWithPermission('manage_catalog/product');
        $product = $this->testProduct();

        $response = $this->actingAs($user)->put(
            route('products.update', $product->product_id),
            array_merge($this->updatePayload($product, '/catalog/products'), [
                'description' => $this->legitOnlyPayload(),
            ])
        );

        $response->assertRedirect('/catalog/products');

        $langId = (int) config('catalog.default_language_id');
        $stored = DB::table(config('catalog.prefix').'product_description')
            ->where('product_id', $product->product_id)
            ->where('language_id', $langId)
            ->value('description');

        $this->assertSame($this->legitOnlyPayload(), $stored);
    }

    public function test_category_store_sanitises_description_stripping_script_onerror_and_javascript_href(): void
    {
        $user = $this->userWithPermission('manage_catalog/category');
        $name = 'Sec Test XSS Category ' . uniqid();

        $response = $this->actingAs($user)->post(route('categories.store'), [
            'name' => $name,
            'status' => '1',
            'description' => $this->xssAndLegitPayload(),
        ]);

        $response->assertRedirect(route('categories.index'));

        $stored = DB::table(config('catalog.prefix').'category_description')
            ->where('name', $name)->value('description');

        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertSame($this->expectedSanitizedPayload(), $stored);
    }

    public function test_category_update_sanitises_description_stripping_script_onerror_and_javascript_href(): void
    {
        $user = $this->userWithPermission('manage_catalog/category');
        $categoryId = $this->createCategory($user, 'Sec Test Category Base ' . uniqid());

        $response = $this->actingAs($user)->put(route('categories.update', $categoryId), [
            'name' => 'Sec Test Category Updated ' . uniqid(),
            'status' => '1',
            'description' => $this->xssAndLegitPayload(),
        ]);

        $response->assertRedirect(route('categories.index'));

        $langId = (int) config('catalog.default_language_id');
        $stored = DB::table(config('catalog.prefix').'category_description')
            ->where('category_id', $categoryId)
            ->where('language_id', $langId)
            ->value('description');

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertSame($this->expectedSanitizedPayload(), $stored);
    }

    public function test_product_edit_page_neutralises_a_hostile_description_planted_directly_in_the_database(): void
    {
        $user = $this->userWithPermission('manage_catalog/product');
        $product = $this->testProduct();
        $langId = (int) config('catalog.default_language_id');
        $pfx = config('catalog.prefix');

        DB::table($pfx.'product_description')->insert([
            'product_id' => $product->product_id,
            'language_id' => $langId,
            'name' => 'Sec Render Test Product',
            'description' => $this->xssAndLegitPayload(),
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        $html = $this->actingAs($user)->get(route('products.edit', $product->product_id))->getContent();

        $this->assertStringNotContainsString('<script>alert(2)</script>', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);

        $rawStored = DB::table($pfx.'product_description')
            ->where('product_id', $product->product_id)->where('language_id', $langId)
            ->value('description');
        $this->assertSame($this->xssAndLegitPayload(), $rawStored);
    }

    public function test_category_edit_page_neutralises_a_hostile_description_planted_directly_in_the_database(): void
    {
        $user = $this->userWithPermission('manage_catalog/category');
        $categoryId = $this->createCategory($user, 'Sec Render Test Category ' . uniqid());
        $langId = (int) config('catalog.default_language_id');
        $pfx = config('catalog.prefix');

        DB::table($pfx.'category_description')
            ->where('category_id', $categoryId)->where('language_id', $langId)
            ->update(['description' => $this->xssAndLegitPayload()]);

        $html = $this->actingAs($user)->get(route('categories.edit', $categoryId))->getContent();

        $this->assertStringNotContainsString('<script>alert(2)</script>', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);

        $rawStored = DB::table($pfx.'category_description')
            ->where('category_id', $categoryId)->where('language_id', $langId)
            ->value('description');
        $this->assertSame($this->xssAndLegitPayload(), $rawStored);
    }

    public function test_the_sanitiser_keeps_plain_text_and_text_after_the_last_element(): void
    {
        $this->assertSame("A warm overdrive.\nTwo lines", \App\Support\HtmlSanitizer::sanitize("A warm overdrive.\nTwo lines"));
        $this->assertSame('<p>Hi <b>there</b></p> tail', \App\Support\HtmlSanitizer::sanitize('<p>Hi <script>x()</script><b>there</b></p> tail'));
        $this->assertSame('Fish &amp; chips &lt;3', \App\Support\HtmlSanitizer::sanitize('Fish & chips <3'));
        $this->assertSame('', \App\Support\HtmlSanitizer::sanitize("  \n "));
        $this->assertStringNotContainsString('<div>', \App\Support\HtmlSanitizer::sanitize('plain'), 'the wrapper never leaks');
    }
}
