<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Support\Catalog\ProductImages;
use Extensions\tiktok\Models\TikTokCategory;
use Extensions\tiktok\Models\TikTokCategoryTemplate;
use Extensions\tiktok\Models\TikTokDescriptionImage;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Extensions\tiktok\Services\TikTok\TikTokDescriptionImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TiktokDescriptionImagesTest extends TestCase
{
    use RefreshDatabase;

    private const DESC_UPLOAD = 'POST /product/202309/images/upload?use_case=DESCRIPTION_IMAGE';

    private array $sentCalls = [];
    public array $callResponses = [];
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(\Extensions\tiktok\TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        TikTokSetting::create([
            'mode' => 'production', 'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c', 'expires_at' => now()->addDays(3),
        ]);
        TikTokCategory::create(['id' => '900009', 'name' => 'Guitar Accessories', 'parent_id' => null, 'is_leaf' => true]);
        TikTokCategoryTemplate::create(['category_id' => '900009', 'attributes' => [], 'fetched_at' => now()]);

        $this->picture('catalog/__tt-desc-main.png', 4, [0, 0, 0]);
        $this->picture('catalog/__tt-desc-a.png', 6, [255, 0, 0]);
        $this->picture('catalog/__tt-desc-b.png', 8, [0, 0, 255]);

        $this->callResponses[self::DESC_UPLOAD] = function (array $body): array {
            $n = count($this->callsFor(self::DESC_UPLOAD));

            return ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => [
                'uri' => 'tos-desc-' . $n, 'url' => "https://p16-oec-sg.ibyteimg.com/tos-desc-{$n}\n",
                'width' => 640, 'height' => 480, 'use_case' => 'DESCRIPTION_IMAGE',
            ]]];
        };

        $test = $this;
        $fake = new class($test) extends TikTokClient {
            public function __construct(private $test) {}
            public function get(string $appKey, string $appSecret, string $accessToken, string $path, array $extraParams = [], ?string $shopCipher = null): array
            { return $this->test->answer('GET', $path, $extraParams); }
            public function post(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            { return $this->test->answer('POST', $path, $body); }
            public function put(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            { return $this->test->answer('PUT', $path, $body); }
            public function uploadImage(string $appKey, string $appSecret, string $accessToken, string $imageData, ?string $shopCipher = null): array
            { return $this->test->answer('POST', '/product/202309/images/upload', ['bytes' => strlen($imageData)]); }
            public function uploadDescriptionImage(string $appKey, string $appSecret, string $accessToken, string $imageData, ?string $shopCipher = null): array
            { return $this->test->answer('POST', '/product/202309/images/upload?use_case=DESCRIPTION_IMAGE', ['bytes' => strlen($imageData)]); }
        };
        $this->app->instance(TikTokClient::class, $fake);

        $this->callResponses['POST /product/202309/products'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['product_id' => 'tt-desc', 'skus' => [['id' => 'sku-d', 'seller_sku' => 'TTD-SKU']]]]];
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            Storage::disk('public')->delete($path);
        }
        parent::tearDown();
    }

    public function answer(string $method, string $path, array $body): array
    {
        $key = $method . ' ' . $path;
        $this->sentCalls[] = ['key' => $key, 'body' => $body];
        $answer = $this->callResponses[$key] ?? null;
        if ($answer instanceof \Closure) {
            return $answer($body);
        }

        return $answer ?? ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['uri' => 'img-' . count($this->sentCalls)]]];
    }

    private function callsFor(string $key): array
    {
        return array_values(array_filter($this->sentCalls, fn ($c) => $c['key'] === $key));
    }

    private function picture(string $path, int $side, array $rgb): void
    {
        $im = imagecreatetruecolor($side, $side);
        imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
        ob_start();
        imagepng($im);
        Storage::disk('public')->put($path, (string) ob_get_clean());
        $this->files[] = $path;
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'TikTok description pictures']);
        $group->permissions()->attach(Permission::whereIn('key', ['manage_tiktok/product', 'view_tiktok/product', 'manage_tiktok/listing', 'view_tiktok/listing'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $description): int
    {
        $pfx = (string) config('catalog.prefix');
        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'TTD-1', 'sku' => 'TTD-SKU', 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => 'catalog/__tt-desc-main.png', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'manufacturer_id' => 0, 'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'TikTok description pictures product', 'description' => $description,
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);
        $group = TikTokProductGroup::firstOrCreate(['name' => 'Scope group'], ['tiktok_category_id' => '900009']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $productId, 'sync_status' => 'pending']);
        TikTokListing::create(['product_id' => $productId, 'tiktok_category_id' => '900009', 'title' => 'A title long enough for TikTok Shop']);

        return $productId;
    }

    private function twoPictures(): string
    {
        return '<p>Words.</p><img src="' . e(ProductImages::url('catalog/__tt-desc-a.png')) . '" alt="red">'
            . '<p>More words.</p><img src="' . e(ProductImages::url('catalog/__tt-desc-b.png')) . '">';
    }

    public function test_a_description_with_two_pictures_uploads_both_and_sends_tiktoks_urls_with_their_size(): void
    {
        $productId = $this->seedProduct($this->twoPictures());

        $this->actingAs($this->manager())->post(route('ext.tiktok.products.push_direct', $productId))->assertSessionHas('status');

        $this->assertCount(2, $this->callsFor(self::DESC_UPLOAD));
        $description = $this->callsFor('POST /product/202309/products')[0]['body']['description'];
        $this->assertStringContainsString('<img src="https://p16-oec-sg.ibyteimg.com/tos-desc-1" width="640" height="480">', $description);
        $this->assertStringContainsString('<img src="https://p16-oec-sg.ibyteimg.com/tos-desc-2" width="640" height="480">', $description);
        $this->assertStringNotContainsString('/storage/', $description, 'Our own URL is never sent to TikTok.');
        $this->assertStringContainsString('<p>More words.</p>', $description);
        $this->assertSame(2, TikTokDescriptionImage::count());
    }

    public function test_a_second_push_reuses_the_pictures_without_uploading_again(): void
    {
        $productId = $this->seedProduct($this->twoPictures());
        $user = $this->manager();
        $this->actingAs($user)->post(route('ext.tiktok.products.push_direct', $productId))->assertSessionHas('status');
        $first = $this->callsFor('POST /product/202309/products')[0]['body']['description'];

        $this->actingAs($user)->post(route('ext.tiktok.listings.push_update', $productId))->assertSessionHas('status');

        $this->assertCount(2, $this->callsFor(self::DESC_UPLOAD), 'Nothing is uploaded a second time.');
        $edit = $this->callsFor('PUT /product/202309/products/tt-desc');
        $this->assertCount(1, $edit);
        $this->assertSame($first, $edit[0]['body']['description']);
    }

    public function test_a_refused_picture_is_left_out_and_the_push_says_so(): void
    {
        $productId = $this->seedProduct($this->twoPictures());
        $this->callResponses[self::DESC_UPLOAD] = function (array $body): array {
            return count($this->callsFor(self::DESC_UPLOAD)) === 1
                ? ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['uri' => 'tos-ok', 'url' => 'https://p16-oec-sg.ibyteimg.com/tos-ok', 'width' => 300, 'height' => 200]]]
                : ['ok' => true, 'status' => 200, 'body' => ['code' => 12019116, 'message' => 'The image dimensions exceed the allowed range.']];
        };

        $r = $this->actingAs($this->manager())->post(route('ext.tiktok.products.push_direct', $productId));

        $r->assertSessionHas('warning');
        $this->assertStringContainsString('1 picture in the description could not be sent to TikTok Shop and was left out.', session('warning'));
        $description = $this->callsFor('POST /product/202309/products')[0]['body']['description'];
        $this->assertSame(1, substr_count($description, '<img'));
        $this->assertStringContainsString('<img src="https://p16-oec-sg.ibyteimg.com/tos-ok" width="300" height="200">', $description);
        $this->assertStringNotContainsString('/storage/', $description);
        $this->assertSame('tt-desc', TikTokListing::where('product_id', $productId)->value('tiktok_product_id'), 'The push still goes.');
        $this->assertNull(TikTokListing::where('product_id', $productId)->value('last_push_error'), 'A push that went is not a red line.');
        $this->assertSame(1, TikTokDescriptionImage::count(), 'A refused picture is not remembered.');
    }

    public function test_a_picture_from_elsewhere_is_left_out_and_one_on_tiktok_already_is_kept(): void
    {
        $productId = $this->seedProduct('<p>Words.</p><img src="https://example.test/x.png"><img src="https://p16-oec-sg.ibyteimg.com/held" width="10" height="10">');

        $this->actingAs($this->manager())->post(route('ext.tiktok.products.push_direct', $productId))->assertSessionHas('warning');

        $this->assertCount(0, $this->callsFor(self::DESC_UPLOAD));
        $description = $this->callsFor('POST /product/202309/products')[0]['body']['description'];
        $this->assertStringNotContainsString('example.test', $description);
        $this->assertStringContainsString('https://p16-oec-sg.ibyteimg.com/held', $description);
    }

    public function test_only_our_own_storage_maps_to_a_path_and_never_climbs_out_of_it(): void
    {
        $this->assertSame('catalog/a b.png', TikTokDescriptionImages::ownPath(ProductImages::url('catalog/a b.png')));
        $this->assertSame('catalog/x.png', TikTokDescriptionImages::ownPath('/storage/catalog/x.png'));
        $this->assertNull(TikTokDescriptionImages::ownPath(asset('storage') . '/catalog/../../.env'));
        $this->assertNull(TikTokDescriptionImages::ownPath(asset('storage') . '/catalog/%2e%2e/%2e%2e/.env'));
        $this->assertNull(TikTokDescriptionImages::ownPath('https://elsewhere.test/storage/catalog/x.png'));
    }
}
