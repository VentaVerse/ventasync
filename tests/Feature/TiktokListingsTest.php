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
        TikTokCategory::create(['id' => '900009', 'name' => 'Guitar Accessories', 'parent_id' => null, 'is_leaf' => true]);
        TikTokCategoryTemplate::create(['category_id' => '900009', 'attributes' => [], 'fetched_at' => now()]);

        $dir = public_path('storage/catalog');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->imageFile = $dir . '/__tt-listing-test.png';
        $im = imagecreatetruecolor(4, 4);
        imagepng($im, $this->imageFile);
        imagedestroy($im);

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
        $group = TikTokProductGroup::firstOrCreate(['name' => 'Scope group'], ['tiktok_category_id' => '900009']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $productId, 'sync_status' => 'pending']);

        return $productId;
    }

    public function test_the_index_reads_the_mirror_and_asks_tiktok_for_nothing(): void
    {
        $live = $this->seedProduct('L');
        TikTokListing::create(['product_id' => $live, 'tiktok_product_id' => 'tt-live', 'tiktok_sku_id' => 's1', 'tiktok_category_id' => '900009',
            'last_pushed_at' => now(), 'last_push_source' => 'listing', 'live_status' => 'ACTIVATE', 'live_checked_at' => '2026-08-28 08:30:00']);
        $gone = $this->seedProduct('G');
        TikTokListing::create(['product_id' => $gone, 'tiktok_product_id' => 'tt-gone', 'tiktok_category_id' => '900009', 'live_status' => 'MISSING', 'live_checked_at' => '2026-08-28 08:30:00']);
        $fresh = $this->seedProduct('F');
        TikTokListing::create(['product_id' => $fresh, 'tiktok_product_id' => 'tt-new', 'tiktok_category_id' => '900009']);
        $ready = $this->seedProduct('R');
        TikTokListing::create(['product_id' => $ready, 'tiktok_category_id' => '900009']);
        $bare = $this->seedProduct('B');
        TikTokListing::create(['product_id' => $bare]);

        $r = $this->actingAs($this->manager())->get(route('ext.tiktok.products.index'));

        $r->assertOk();
        $r->assertSee('lss__label">All<', false);
        $r->assertSee('class="x-segment-bar"', false);
        $r->assertSee('<span>All</span>', false);
        $r->assertDontSee('x-segment-group__k', false);
        $r->assertDontSee('<span>All products</span>', false);
        $r->assertSee('as of 08:30, Aug 28');
        $r->assertSee('Refresh from TikTok Shop', false);
        $r->assertSee('Refresh from TikTok Shop');
        $r->assertSee('Live');
        $r->assertSee('Not found on TikTok Shop');
        $r->assertSee('Not checked yet');
        $r->assertDontSee('a TikTok category');
        $r->assertDontSee('Missing: a TikTok category');
        $r->assertSee('Send to TikTok Shop');
        $r->assertSee('Under review');
        $r->assertSee('Deactivate on TikTok Shop');
        $this->assertCount(0, $this->callsFor('POST', '/product/202309/products/search'));
    }

    public function test_a_listing_never_checked_is_filled_on_first_view_and_only_once(): void
    {
        $fresh = $this->seedProduct('F');
        TikTokListing::create(['product_id' => $fresh, 'tiktok_product_id' => 'tt-new', 'tiktok_category_id' => '900009']);
        $calls = 0;
        $this->callResponses['GET /product/202309/products/tt-new'] = function () use (&$calls) {
            $calls++;
            return ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['id' => 'tt-new', 'status' => 'IN_REVIEW', 'title' => 'F', 'skus' => []]]];
        };

        $r = $this->actingAs($this->manager())->get(route('ext.tiktok.products.index'));
        $r->assertOk();
        $r->assertDontSee('Not checked yet');
        $this->assertSame(1, $calls);
        $this->assertSame('IN_REVIEW', TikTokListing::query()->where('tiktok_product_id', 'tt-new')->value('live_status'));

        $this->actingAs($this->manager())->get(route('ext.tiktok.products.index'))->assertOk();
        $this->assertSame(1, $calls, 'the second view reads the mirror and asks nothing');
    }

    public function test_refresh_walks_the_shop_and_writes_every_listings_status(): void
    {
        $live = $this->seedProduct('L');
        TikTokListing::create(['product_id' => $live, 'tiktok_product_id' => 'tt-live', 'tiktok_category_id' => '900009']);
        $gone = $this->seedProduct('G');
        TikTokListing::create(['product_id' => $gone, 'tiktok_product_id' => 'tt-gone', 'tiktok_category_id' => '900009']);
        $pivotOnly = $this->seedProduct('P');
        TikTokProductGroupProduct::query()->where('product_id', $pivotOnly)->update(['tiktok_product_id' => 'tt-off', 'sync_status' => 'synced']);

        $r = $this->actingAs($this->manager())->post(route('ext.tiktok.products.refresh_status'));

        $r->assertSessionHas('status');
        $this->assertStringContainsString('1 live', (string) session('status'));
        $this->assertStringContainsString('1 unlisted', (string) session('status'));
        $this->assertCount(2, $this->callsFor('POST', '/product/202309/products/search'));
        $this->assertSame('ACTIVATE', TikTokListing::where('tiktok_product_id', 'tt-live')->value('live_status'));
        $this->assertNull(TikTokListing::where('product_id', $gone)->value('tiktok_product_id'), 'the dead link was dropped by the check');
        $this->assertStringContainsString('1 no longer on TikTok Shop', (string) session('status'));
        $this->assertSame('SELLER_DEACTIVATED', TikTokListing::where('product_id', $pivotOnly)->value('live_status'), 'the pivot-only product got a listing row and its status');
        $this->assertNotNull(TikTokListing::where('tiktok_product_id', 'tt-live')->value('live_checked_at'));
    }

    public function test_refresh_leaves_the_mirror_alone_when_tiktok_does_not_answer(): void
    {
        $live = $this->seedProduct('L');
        TikTokListing::create(['product_id' => $live, 'tiktok_product_id' => 'tt-live', 'live_status' => 'ACTIVATE']);
        $this->callResponses['POST /product/202309/products/search'] = ['ok' => false, 'status' => 500, 'body' => ['code' => 1, 'message' => 'boom']];

        $r = $this->actingAs($this->manager())->post(route('ext.tiktok.products.refresh_status'));

        $r->assertSessionHas('error');
        $this->assertStringContainsString('TikTok Shop did not answer', (string) session('error'));
        $this->assertSame('ACTIVATE', TikTokListing::where('tiktok_product_id', 'tt-live')->value('live_status'));
    }

    public function test_refresh_reads_the_attribute_sheets_its_listings_need(): void
    {
        $own = $this->seedProduct('S');
        TikTokListing::create(['product_id' => $own, 'tiktok_category_id' => '900111']);
        TikTokProductGroup::create(['name' => 'Unread group', 'tiktok_category_id' => '900222']);
        $this->callResponses['GET /product/202309/categories/900111/attributes'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['attributes' => [['id' => '101', 'name' => 'Material', 'type' => 'PRODUCT_PROPERTY', 'values' => []]]]]];
        $this->callResponses['GET /product/202309/categories/900222/attributes'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 36009004, 'message' => 'Category not found']];

        $this->actingAs($this->manager())->post(route('ext.tiktok.products.refresh_status'))->assertSessionHas('status');

        $status = (string) session('status');
        $this->assertStringContainsString('Read 1 attribute sheet.', $status);
        $this->assertStringContainsString('1 attribute sheet could not be read.', $status);
        $this->assertNotNull(TikTokCategoryTemplate::where('category_id', '900111')->first());
        $this->assertNull(TikTokCategoryTemplate::where('category_id', '900222')->first(), 'a refused read files nothing');
        $this->assertCount(0, $this->callsFor('GET', '/product/202309/categories/900009/attributes'), 'a sheet already read is not read again');
        $state = app(\Extensions\tiktok\Services\TikTok\TikTokListingReadiness::class)->forProducts([$own]);
        $this->assertNotContains(\App\Integrations\Listings\ListingState::GAP_SHEET, array_column($state[$own]['gaps'], 'code'));
    }

    public function test_the_scheduled_refresh_reads_them_too(): void
    {
        $own = $this->seedProduct('C');
        TikTokListing::create(['product_id' => $own, 'tiktok_category_id' => '900111']);
        $this->callResponses['GET /product/202309/categories/900111/attributes'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['attributes' => []]]];

        $this->artisan('tiktok:refresh-listing-status')->assertExitCode(0);

        $this->assertNotNull(TikTokCategoryTemplate::where('category_id', '900111')->first());
    }

    public function test_a_tiktok_tab_narrows_the_table_by_the_mirror(): void
    {
        $live = $this->seedProduct('L');
        TikTokListing::create(['product_id' => $live, 'tiktok_product_id' => 'tt-live', 'live_status' => 'ACTIVATE']);
        $off = $this->seedProduct('O');
        TikTokListing::create(['product_id' => $off, 'tiktok_product_id' => 'tt-off', 'live_status' => 'SELLER_DEACTIVATED']);

        $r = $this->actingAs($this->manager())->get(route('ext.tiktok.products.index', ['tiktok_tab' => 'deactivated']));

        $r->assertOk();
        $r->assertSee('TikTok listing product O');
        $r->assertDontSee('TikTok listing product L');
        $this->assertCount(0, $this->callsFor('POST', '/product/202309/products/search'));
    }

    public function test_the_listing_page_reads_live_state_and_saves_its_settings(): void
    {
        $productId = $this->seedProduct('P');
        TikTokListing::create(['product_id' => $productId, 'tiktok_product_id' => 'tt-live', 'tiktok_sku_id' => 's1', 'last_pushed_at' => now(), 'last_push_source' => 'listing']);

        $r = $this->actingAs($this->manager())->get(route('ext.tiktok.listings.edit', $productId));
        $r->assertOk();
        $r->assertDontSee('On TikTok Shop right now', false);
        $r->assertDontSee('Live on TikTok');
        $r->assertSee('LIVE-SKU');
        $r->assertSee('Deactivate on TikTok Shop');
        $this->assertSame('ACTIVATE', TikTokListing::query()->where('tiktok_product_id', 'tt-live')->value('live_status'));

        $this->actingAs($this->manager())->put(route('ext.tiktok.listings.update', $productId), [
            'tiktok_category_id' => '900009', 'brand_id' => 'b-1', 'brand_name' => 'Valeton',
            'markup_percent' => 10, 'title' => 'My own title', 'attributes' => ['100001' => 'v-ph'],
        ])->assertSessionHas('status');

        $listing = TikTokListing::where('product_id', $productId)->first();
        $this->assertNull($listing->tiktok_category_id);
        $inherit = app(\Extensions\tiktok\Services\TikTok\TikTokInheritedSettings::class);
        $this->assertSame('900009', (string) $inherit->fill($listing, $inherit->forProducts([$productId])[$productId] ?? null)['listing']->tiktok_category_id);
        $this->assertSame('Valeton', $listing->brand_name);
        $this->assertSame(10.0, $listing->markup_percent);
        $this->assertSame('My own title', $listing->title);
        $this->assertSame(['100001' => 'v-ph'], $listing->attribute_values);
        $this->assertSame('tt-live', $listing->tiktok_product_id, 'Saving settings never touches the truth.');
    }

    public function test_the_one_click_push_uses_the_listings_own_settings_and_records_the_truth(): void
    {
        $productId = $this->seedProduct('D', 200);
        TikTokListing::create(['product_id' => $productId, 'tiktok_category_id' => '900009', 'brand_id' => 'b-1', 'brand_name' => 'Valeton', 'markup_percent' => 50, 'title' => 'A title long enough for TikTok Shop']);
        $this->callResponses['POST /product/202309/products'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['product_id' => 'tt-new', 'skus' => [['id' => 'sku-n', 'seller_sku' => 'TTL-SKU-D']]]]];

        $this->actingAs($this->manager())->post(route('ext.tiktok.products.push_direct', $productId))->assertSessionHas('status');

        $creates = $this->callsFor('POST', '/product/202309/products');
        $this->assertCount(1, $creates);
        $body = $creates[0]['body'];
        $this->assertSame('900009', $body['category_id']);
        $this->assertSame('b-1', $body['brand_id']);
        $this->assertSame('A title long enough for TikTok Shop', $body['title']);
        $this->assertSame('300', $body['skus'][0]['price']['amount']);
        $listing = TikTokListing::where('product_id', $productId)->first();
        $this->assertSame('tt-new', $listing->tiktok_product_id);
        $this->assertSame('listing', $listing->last_push_source);
        $this->assertSame('tt-new', TikTokProductGroupProduct::where('product_id', $productId)->value('tiktok_product_id'), 'The group pivot learns the truth too.');
    }

    public function test_an_unready_push_is_refused_before_any_call(): void
    {
        $productId = $this->seedProduct('U');
        DB::table((string) config('catalog.prefix') . 'product')->where('product_id', $productId)->update(['status' => 0]);

        $r = $this->actingAs($this->manager())->post(route('ext.tiktok.products.push_direct', $productId));

        $r->assertSessionHas('error');
        $this->assertStringContainsString('product in catalog is disabled', session('error'));
        $this->assertSame([], $this->callsFor('POST', '/product/202309/products'));
    }

    public function test_a_listings_own_price_is_the_starting_price_the_rule_is_added_to(): void
    {
        $productId = $this->seedProduct('OP', 300);
        TikTokListing::create(['product_id' => $productId, 'tiktok_category_id' => '900009', 'price' => 500, 'markup_percent' => 10]);
        $this->callResponses['POST /product/202309/products'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['product_id' => 'tt-own', 'skus' => [['id' => 'sku-o', 'seller_sku' => 'TTL-SKU-OP']]]]];

        $page = $this->actingAs($this->manager())->get(route('ext.tiktok.listings.edit', $productId));
        $page->assertOk();
        $page->assertSee('name="price"', false);
        $page->assertSee('550.00');

        $this->actingAs($this->manager())->post(route('ext.tiktok.products.push_direct', $productId))->assertSessionHas('status');

        $creates = $this->callsFor('POST', '/product/202309/products');
        $this->assertCount(1, $creates);
        $this->assertSame('550', $creates[0]['body']['skus'][0]['price']['amount']);
    }

    public function test_a_blank_price_starts_from_the_catalog_price(): void
    {
        $productId = $this->seedProduct('BP', 300);
        TikTokListing::create(['product_id' => $productId, 'tiktok_category_id' => '900009', 'markup_percent' => 10]);
        $this->callResponses['POST /product/202309/products'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['product_id' => 'tt-blank', 'skus' => [['id' => 'sku-b', 'seller_sku' => 'TTL-SKU-BP']]]]];

        $this->actingAs($this->manager())->post(route('ext.tiktok.products.push_direct', $productId))->assertSessionHas('status');

        $this->assertSame('330', $this->callsFor('POST', '/product/202309/products')[0]['body']['skus'][0]['price']['amount']);
    }

    public function test_save_and_push_updates_a_live_product_and_returns_to_the_search(): void
    {
        $productId = $this->seedProduct('SP', 300);
        TikTokListing::create(['product_id' => $productId, 'tiktok_product_id' => 'tt-sp', 'tiktok_sku_id' => 's-sp',
            'tiktok_category_id' => '900009', 'last_pushed_at' => now(), 'last_push_source' => 'listing']);
        $back = '/channels/tiktok/1/products?search=sp';

        $this->actingAs($this->manager())
            ->put(route('ext.tiktok.listings.update', $productId), ['push_after' => 1, 'back' => $back])
            ->assertRedirect($back)
            ->assertSessionHas('status');

        $this->assertCount(1, $this->callsFor('PUT', '/product/202309/products/tt-sp'));
    }

    public function test_the_listing_page_update_starts_from_the_listings_own_price(): void
    {
        $productId = $this->seedProduct('UP', 300);
        TikTokListing::create(['product_id' => $productId, 'tiktok_product_id' => 'tt-up', 'tiktok_sku_id' => 's-up',
            'price' => 500, 'markup_percent' => 10, 'last_pushed_at' => now(), 'last_push_source' => 'listing']);

        $this->actingAs($this->manager())->post(route('ext.tiktok.listings.push_update', $productId))->assertSessionHas('status');

        $edits = $this->callsFor('PUT', '/product/202309/products/tt-up');
        $this->assertCount(1, $edits);
        $this->assertSame('s-up', $edits[0]['body']['skus'][0]['id']);
        $this->assertSame('550', $edits[0]['body']['skus'][0]['price']['amount']);
    }

    public function test_a_variation_product_has_no_price_field_and_keeps_no_price(): void
    {
        $productId = $this->seedProduct('VP', 300);
        DB::table('product_option_combinations')->insert([
            'product_id' => $productId, 'sku' => 'TTL-VP-RED', 'quantity' => 2, 'absolute_price' => 0,
            'status' => 1, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $page = $this->actingAs($this->manager())->get(route('ext.tiktok.listings.edit', $productId));
        $page->assertOk();
        $page->assertDontSee('name="price"', false);
        $page->assertSee('name="markup_percent"', false);

        $this->actingAs($this->manager())->put(route('ext.tiktok.listings.update', $productId), [
            'tiktok_category_id' => '900009', 'price' => 500, 'markup_percent' => 10,
        ])->assertSessionHas('status');

        $this->assertNull(TikTokListing::where('product_id', $productId)->value('price'));
    }

    public function test_the_parcel_sends_the_listings_own_figures_and_blank_ones_follow_the_catalog(): void
    {
        $productId = $this->seedProduct('PC', 300);

        $this->actingAs($this->manager())->put(route('ext.tiktok.listings.update', $productId), [
            'tiktok_category_id' => '900009', 'weight' => 0, 'package_length' => 0,
        ])->assertSessionHasErrors(['weight', 'package_length']);

        $this->actingAs($this->manager())->put(route('ext.tiktok.listings.update', $productId), [
            'tiktok_category_id' => '900009', 'price' => '', 'weight' => '2.5', 'package_length' => '30', 'package_width' => '', 'package_height' => '',
        ])->assertSessionHas('status');

        $listing = TikTokListing::where('product_id', $productId)->first();
        $this->assertSame(2.5, $listing->weight);
        $this->assertSame(30, $listing->package_length);
        $this->assertNull($listing->package_width);
        $this->assertNull($listing->package_height);
        $this->assertNull($listing->price);

        $this->callResponses['POST /product/202309/products'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['product_id' => 'tt-parcel', 'skus' => [['id' => 'sku-p', 'seller_sku' => 'TTL-SKU-PC']]]]];
        $this->actingAs($this->manager())->post(route('ext.tiktok.products.push_direct', $productId))->assertSessionHas('status');

        $body = $this->callsFor('POST', '/product/202309/products')[0]['body'];
        $this->assertSame(['unit' => 'GRAM', 'value' => '2500'], $body['package_weight']);
        $this->assertSame(['unit' => 'CENTIMETER', 'length' => '30', 'width' => '10', 'height' => '10'], $body['package_dimensions']);
        $this->assertSame('300', $body['skus'][0]['price']['amount'], 'a blank Price is the catalog price');
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
        $this->assertSame('SELLER_DEACTIVATED', TikTokListing::where('tiktok_product_id', 'tt-live')->value('live_status'));
    }

    public function test_bulk_toggle_deactivates_the_selection_in_one_call_and_names_what_it_skipped(): void
    {
        $a = $this->seedProduct('A');
        TikTokListing::create(['product_id' => $a, 'tiktok_product_id' => 'tt-a', 'live_status' => 'ACTIVATE']);
        $b = $this->seedProduct('B');
        TikTokListing::create(['product_id' => $b, 'tiktok_product_id' => 'tt-b', 'live_status' => 'ACTIVATE']);
        $bare = $this->seedProduct('C');
        $this->callResponses['POST /product/202309/products/deactivate'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => []]];

        $r = $this->actingAs($this->manager())
            ->post(route('ext.tiktok.products.bulk_toggle'), ['action' => 'deactivate', 'product_ids' => [$a, $b, $bare]]);

        $calls = $this->callsFor('POST', '/product/202309/products/deactivate');
        $this->assertCount(1, $calls, 'one call for the whole selection');
        $this->assertEqualsCanonicalizing(['tt-a', 'tt-b'], $calls[0]['body']['product_ids']);
        $r->assertSessionHas('status');
        $flash = (string) session('status');
        $this->assertStringContainsString('Deactivated 2 items on TikTok Shop', $flash);
        $this->assertStringContainsString('1 skipped, not on TikTok Shop', $flash);
        $this->assertSame('SELLER_DEACTIVATED', TikTokListing::where('tiktok_product_id', 'tt-a')->value('live_status'));
    }

    public function test_bulk_toggle_with_nothing_on_tiktok_refuses_plainly(): void
    {
        $bare = $this->seedProduct('Z');
        $this->actingAs($this->manager())
            ->post(route('ext.tiktok.products.bulk_toggle'), ['action' => 'activate', 'product_ids' => [$bare]])
            ->assertSessionHas('error');
        $this->assertCount(0, $this->callsFor('POST', '/product/202309/products/activate'));
    }

    public function test_a_view_only_operator_reads_the_index_without_verbs(): void
    {
        $productId = $this->seedProduct('V');
        TikTokListing::create(['product_id' => $productId, 'tiktok_product_id' => 'tt-live']);

        $r = $this->actingAs($this->userWith(['view_tiktok/product']))->get(route('ext.tiktok.products.index'));

        $r->assertOk();
        $r->assertSee('TikTok listing product V');
        $r->assertDontSee('Send to TikTok Shop');
    }
    public function test_a_row_shows_the_stores_own_title_and_the_search_finds_it(): void
    {
        $titled = $this->seedProduct('T');
        $plain = $this->seedProduct('P');
        TikTokListing::create(['product_id' => $titled, 'title' => 'Qable IC50 Pro']);
        TikTokListing::create(['product_id' => $plain]);
        $other = TikTokSetting::create([
            'mode' => 'production', 'app_key' => 'k2', 'app_secret' => 's2',
            'access_token' => 't2', 'refresh_token' => 'r2', 'shop_cipher' => 'c2', 'expires_at' => now()->addDays(3),
        ]);
        TikTokListing::create(['tiktok_setting_id' => $other->id, 'product_id' => $plain, 'title' => 'Qable elsewhere']);
        $group = TikTokProductGroup::query()->where('name', 'Scope group')->firstOrFail();
        $user = $this->userWith(['manage_tiktok/product', 'view_tiktok/product', 'view_tiktok/product_group']);

        foreach ([route('ext.tiktok.products.index'), route('ext.tiktok.product-groups.products', ['id' => $group->id])] as $url) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('Qable IC50 Pro')
                ->assertSee('TikTok listing product P')
                ->assertDontSee('TikTok listing product T')
                ->assertDontSee('Qable elsewhere');
        }

        foreach ([route('ext.tiktok.products.index', ['q' => 'Qable']), route('ext.tiktok.product-groups.products', ['id' => $group->id, 'q' => 'Qable'])] as $url) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('Qable IC50 Pro')
                ->assertDontSee('TikTok listing product P');
        }
    }
}
