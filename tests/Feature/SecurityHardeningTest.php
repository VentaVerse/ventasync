<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\Catalog\Product;
use App\Models\Extension;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Dedicated hardening commit for the three pre-existing vulnerabilities the
 * automated review surfaced during the IA migration (2026-08-08,
 * SECURITY REVIEW in progress.md): SSRF in ProductImageController::importUrl,
 * zip slip / path traversal in ExtensionController::install, and an open
 * redirect via `_return` in ProductController::update. This range is
 * deliberate behaviour change, so it carries its own tests rather than
 * riding the migration's behaviour-freeze coverage.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    /** extensions/{id} directories this test wrote to the real repo tree. */
    private array $extensionDirsToClean = [];

    /** tmp/product-images/{token} directories written to the public disk. */
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

    // -----------------------------------------------------------------
    // Fix 1: SSRF in Catalog\ProductImageController::importUrl
    // -----------------------------------------------------------------

    public function test_import_url_rejects_loopback_address_without_making_a_request(): void
    {
        Http::fake();

        $user = $this->userWithPermission('manage_products');

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

        $user = $this->userWithPermission('manage_products');

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

        $user = $this->userWithPermission('manage_products');
        $token = 'sec-test-public-' . uniqid();
        $this->tmpImageDirsToClean[] = 'tmp/product-images/' . preg_replace('/[^A-Za-z0-9_-]/', '', $token);

        // A bare public IP literal, so the test needs no real DNS resolution
        // and stays deterministic: it is not in any blocked range, and the
        // actual HTTP call is intercepted by Http::fake above.
        $response = $this->actingAs($user)->postJson(route('products.images.import_url'), [
            'url' => 'http://8.8.8.8/image.jpg',
            'token' => $token,
        ]);

        $response->assertStatus(200)->assertJson(['ok' => true, 'kind' => 'temp']);
        Http::assertSent(function ($request) {
            return $request->url() === 'http://8.8.8.8/image.jpg';
        });
    }

    // -----------------------------------------------------------------
    // Fix 2: zip slip / path traversal in Settings\ExtensionController::install
    // -----------------------------------------------------------------

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
        $user = $this->userWithPermission('manage_website_settings');

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
        $user = $this->userWithPermission('manage_website_settings');

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
        $user = $this->userWithPermission('manage_website_settings');

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

            // Not asserting an Extension DB row here: ExtensionManager is a
            // process-lifetime singleton whose manifest cache is warmed at
            // boot, before this test ever creates the fixture's directory,
            // so install()'s own getManifest() lookup misses and it returns
            // false without writing a row - a pre-existing quirk of that
            // cache, orthogonal to path safety, and out of this commit's
            // scope. What this fix actually guarantees - the archive is
            // accepted and its files land intact at the real destination -
            // is what these two assertions check.
            $response->assertRedirect();
            $response->assertSessionHas('success');
            $this->assertFileExists(base_path("extensions/{$id}/extension.json"));
        } finally {
            @unlink($zipPath);
        }
    }

    // -----------------------------------------------------------------
    // Fix 3: open redirect via `_return` in Catalog\ProductController::update
    // -----------------------------------------------------------------

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
            '_return' => $returnUrl,
        ];
    }

    public function test_update_return_url_falls_back_for_absolute_off_site_url(): void
    {
        $user = $this->userWithPermission('manage_products');
        $product = $this->testProduct();

        $response = $this->actingAs($user)->put(
            route('products.update', $product->product_id),
            $this->updatePayload($product, 'https://evil.example')
        );

        $response->assertRedirect(route('products.index'));
    }

    public function test_update_return_url_falls_back_for_protocol_relative_url(): void
    {
        $user = $this->userWithPermission('manage_products');
        $product = $this->testProduct();

        $response = $this->actingAs($user)->put(
            route('products.update', $product->product_id),
            $this->updatePayload($product, '//evil.example')
        );

        $response->assertRedirect(route('products.index'));
    }

    public function test_update_return_url_is_honoured_when_site_relative(): void
    {
        $user = $this->userWithPermission('manage_products');
        $product = $this->testProduct();

        $response = $this->actingAs($user)->put(
            route('products.update', $product->product_id),
            $this->updatePayload($product, '/catalog/products?page=2')
        );

        $response->assertRedirect('/catalog/products?page=2');
    }
}
