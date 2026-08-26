<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\tiktok\Models\TikTokCategory;
use Extensions\tiktok\Models\TikTokCategoryTemplate;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TikTok listings parity (#156): the index with TikTok's own status on
 * this load, readiness before a push, the listing page's settings, the
 * one-click push on the product's OWN settings, Send that updates in
 * place, and Activate/Deactivate. The listing record is the truth once
 * a push has written it; pivots answer for older pushes.
 */
class TiktokListingsTest extends TestCase
{
    use RefreshDatabase;

    private array $sentCalls = [];
    public array $callResponses = [];
    private string $imageFile = '';
    private int $groupSeq = 0;

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
        TikTokCategory::create(['id' => 'cat-9', 'name' => 'Guitar Accessories', 'parent_id' => null, 'is_leaf' => true]);
        TikTokCategoryTemplate::create(['category_id' => 'cat-9', 'attributes' => [], 'fetched_at' => now()]);

        $dir = public_path('storage/catalog');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->imageFile = $dir . '/__tt-listing-test.png';
        $im = imagecreatetruecolor(4, 4);
        imagepng($im, $this->imageFile);
        imagedestroy($im);

        // The shop as TikTok reports it: two products, one live, one deactivated.
        $this->callResponses['POST /product/202309/products/search'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['products' => [['id' => 'tt-live', 'status' => 'ACTIVATE'], ['id' => 'tt-off', 'status' => 'SELLER_DEACTIVATED']]]]];
        $this->callResponses['GET /product/202309/products/tt-live'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['status' => 'ACTIVATE', 'title' => 'Live on TikTok', 'skus' => [['id' => 's1', 'seller_sku' => 'LIVE-SKU', 'price' => ['sale_price' => '350'], 'inventory' => [['quantity' => 4]]]]]]];

        $test = $this;
        $fake = new class($test) extends TikTokClient {
            public function __construct(private $test) {}
            public function get(string $appKey, string $appSecret, string $accessToken, string $path, array $extraParams = [], ?string $shopCipher = null): array
            { return $this->test->answer('GET', $path, $extraParams); }
            public function post(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            { return $this->test->answer('POST', $path, $body); }
            public function put(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            { return $this->test->answer('PUT', $path, $body); }
            public function delete(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            { return $this->test->answer('DELETE', $path, $body); }
            public function uploadImage(string $appKey, string $appSecret, string $accessToken, string $imageData, ?string $shopCipher = null): array
            { return $this->test->answer('POST', '/product/202309/images/upload', ['bytes' => strlen($imageData)]); }
        };
        $this->app->instance(TikTokClient::class, $fake);
    }

    protected function tearDown(): void
    {
        if ($this->imageFile !== '' && file_exists($this->imageFile)) {
            unlink($this->imageFile);
        }
        parent::tearDown();
    }

    public function answer(string $method, string $path, array $body): array
    {
        $this->sentCalls[] = ['method' => $method, 'path' => $path, 'body' => $body];
        $answer = $this->callResponses[$method . ' ' . $path] ?? null;
        if ($answer instanceof \Closure) {
            return $answer($body);
        }

        return $answer ?? ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['uri' => 'img-' . count($this->sentCalls)]]];
    }

    private function callsFor(string $method, string $path): array
    {
        return array_values(array_filter($this->sentCalls, fn ($c) => $c['method'] === $method && $c['path'] === $path));
    }

    private function userWith(array $keys): User
    {
        $group = UserGroup::create(['name' => 'TikTok listings ' . (++$this->groupSeq)]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function manager(): User
    {
        return $this->userWith(['manage_tiktok/product', 'view_tiktok/product', 'manage_tiktok/listing', 'view_tiktok/listing']);
    }

    private function seedProduct(string $suffix = 'A', float $price = 300): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'TTL-' . $suffix, 'sku' => 'TTL-SKU-' . $suffix, 'quantity' => 5, 'price' => $price,
            'status' => 1, 'image' => 'catalog/__tt-listing-test.png', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'manufacturer_id' => 0, 'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'TikTok listing product ' . $suffix, 'description' => 'Words.',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);
        // Every product in scope sits in a group.
        $group = TikTokProductGroup::firstOrCreate(['name' => 'Scope group'], ['tiktok_category_id' => 'cat-9']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $productId, 'sync_status' => 'pending']);

        return $productId;
    }

    public function test_the_index_wears_tiktoks_live_status_and_readiness(): void
    {
        $live = $this->seedProduct('L');
        TikTokListing::create(['product_id' => $live, 'tiktok_product_id' => 'tt-live', 'tiktok_sku_id' => 's1', 'tiktok_category_id' => 'cat-9', 'last_pushed_at' => now(), 'last_push_source' => 'listing']);
        $gone = $this->seedProduct('G');
        TikTokListing::create(['product_id' => $gone, 'tiktok_product_id' => 'tt-gone', 'tiktok_category_id' => 'cat-9']);
        $ready = $this->seedProduct('R');
        TikTokListing::create(['product_id' => $ready, 'tiktok_category_id' => 'cat-9']);
        $bare = $this->seedProduct('B');

        $r = $this->actingAs($this->manager())->get(route('ext.tiktok.products.index'));

        $r->assertOk();
        $r->assertSee('Live');                        // tt-live's status, from TikTok on this load
        $r->assertSee('Not found on TikTok Shop');    // tt-gone: TikTok answered and it was not there
        $r->assertSee('Ready to push');               // category set, sheet read, image present
        $r->assertSee('Missing: a TikTok category');  // the bare product
        // #149 (2026-08-26): the row menu's push opens the listing page,
        // where the push button sits under what goes up; no one-click here.
        $r->assertSee('Push to TikTok Shop');
        $r->assertDontSee('Push this product to TikTok Shop now?');
        $r->assertSee('Under review');                // the tab bar exists
        $this->assertCount(1, $this->callsFor('POST', '/product/202309/products/search'));
    }

    public function test_the_listing_page_reads_live_state_and_saves_its_settings(): void
    {
        $productId = $this->seedProduct('P');
        TikTokListing::create(['product_id' => $productId, 'tiktok_product_id' => 'tt-live', 'tiktok_sku_id' => 's1', 'last_pushed_at' => now(), 'last_push_source' => 'listing']);

        $r = $this->actingAs($this->manager())->get(route('ext.tiktok.listings.edit', $productId));
        $r->assertOk();
        $r->assertSee('Fetched from TikTok Shop as this page loaded.');
        $r->assertSee('Live on TikTok');
        $r->assertSee('LIVE-SKU');
        $r->assertSee('Deactivate on TikTok Shop');

        $this->actingAs($this->manager())->put(route('ext.tiktok.listings.update', $productId), [
            'tiktok_category_id' => 'cat-9', 'brand_id' => 'b-1', 'brand_name' => 'Valeton',
            'markup_percent' => 10, 'title' => 'My own title', 'attributes' => ['100001' => 'v-ph'],
        ])->assertSessionHas('status');

        $listing = TikTokListing::where('product_id', $productId)->first();
        $this->assertSame('cat-9', $listing->tiktok_category_id);
        $this->assertSame('Valeton', $listing->brand_name);
        $this->assertSame(10.0, $listing->markup_percent);
        $this->assertSame('My own title', $listing->title);
        $this->assertSame(['100001' => 'v-ph'], $listing->attribute_values);
        $this->assertSame('tt-live', $listing->tiktok_product_id, 'Saving settings never touches the truth.');
    }

    public function test_the_one_click_push_uses_the_listings_own_settings_and_records_the_truth(): void
    {
        $productId = $this->seedProduct('D', 200);
        TikTokListing::create(['product_id' => $productId, 'tiktok_category_id' => 'cat-9', 'brand_id' => 'b-1', 'brand_name' => 'Valeton', 'markup_percent' => 50, 'title' => 'A title long enough for TikTok Shop']);
        $this->callResponses['POST /product/202309/products'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['product_id' => 'tt-new', 'skus' => [['id' => 'sku-n', 'seller_sku' => 'TTL-SKU-D']]]]];

        $this->actingAs($this->manager())->post(route('ext.tiktok.products.push_direct', $productId))->assertSessionHas('status');

        $creates = $this->callsFor('POST', '/product/202309/products');
        $this->assertCount(1, $creates);
        $body = $creates[0]['body'];
        $this->assertSame('cat-9', $body['category_id']);
        $this->assertSame('b-1', $body['brand_id']);
        $this->assertSame('A title long enough for TikTok Shop', $body['title']);
        $this->assertSame('300', $body['skus'][0]['price']['amount']);   // 200 + 50%
        $listing = TikTokListing::where('product_id', $productId)->first();
        $this->assertSame('tt-new', $listing->tiktok_product_id);
        $this->assertSame('listing', $listing->last_push_source);
        $this->assertSame('tt-new', TikTokProductGroupProduct::where('product_id', $productId)->value('tiktok_product_id'), 'The group pivot learns the truth too.');
    }

    public function test_an_unready_push_is_refused_before_any_call(): void
    {
        $productId = $this->seedProduct('U');

        $r = $this->actingAs($this->manager())->post(route('ext.tiktok.products.push_direct', $productId));

        $r->assertSessionHas('error');
        $this->assertStringContainsString('a TikTok category', session('error'));
        $this->assertSame([], $this->callsFor('POST', '/product/202309/products'));
    }

    public function test_send_creates_the_new_and_updates_the_listed_in_place(): void
    {
        $new = $this->seedProduct('N');
        TikTokListing::create(['product_id' => $new, 'tiktok_category_id' => 'cat-9']);
        $listed = $this->seedProduct('E');
        TikTokListing::create(['product_id' => $listed, 'tiktok_category_id' => 'cat-9', 'tiktok_product_id' => 'tt-live', 'tiktok_sku_id' => 's1']);
        $this->callResponses['POST /product/202309/products'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['product_id' => 'tt-created', 'skus' => [['id' => 'sku-c', 'seller_sku' => 'TTL-SKU-N']]]]];
        $this->callResponses['PUT /product/202309/products/tt-live'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => []]];

        $r = $this->actingAs($this->manager())->post(route('ext.tiktok.products.bulk_send'), ['product_ids' => [$new, $listed]]);

        $r->assertSessionHas('status');
        $this->assertStringContainsString('1 created, 1 already listed and updated in place', session('status'));
        $this->assertCount(1, $this->callsFor('POST', '/product/202309/products'));
        $this->assertCount(1, $this->callsFor('PUT', '/product/202309/products/tt-live'));
        $this->assertSame('s1', $this->callsFor('PUT', '/product/202309/products/tt-live')[0]['body']['skus'][0]['id'] ?? null, 'The update edits the SKU it already has.');
    }

    public function test_activate_and_deactivate_pass_the_product_id_through(): void
    {
        $productId = $this->seedProduct('T');
        TikTokListing::create(['product_id' => $productId, 'tiktok_product_id' => 'tt-live']);
        $this->callResponses['POST /product/202309/products/deactivate'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => []]];

        $this->actingAs($this->manager())
            ->post(route('ext.tiktok.listings.toggle', $productId), ['action' => 'deactivate'])
            ->assertSessionHas('status');

        $this->assertSame(['product_ids' => ['tt-live']], $this->callsFor('POST', '/product/202309/products/deactivate')[0]['body']);
    }

    public function test_a_view_only_operator_reads_the_index_without_verbs(): void
    {
        $productId = $this->seedProduct('V');
        TikTokListing::create(['product_id' => $productId, 'tiktok_product_id' => 'tt-live']);

        $r = $this->actingAs($this->userWith(['view_tiktok/product']))->get(route('ext.tiktok.products.index'));

        $r->assertOk();
        $r->assertSee('TikTok listing product V');
        $r->assertDontSee('Send to TikTok Shop');
        $r->assertDontSee('Push to TikTok Shop');
    }
}
