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
        $group = TikTokProductGroup::firstOrCreate(['name' => 'Scope group'], ['tiktok_category_id' => '900009']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $productId, 'sync_status' => 'pending']);

        return $productId;
    }

    /**
     * Since 2026-08-28 the strip and the chips read the MIRROR on the
     * listing rows. A page view asks TikTok for nothing; the Refresh button
     * and the 30-minute automation walk the shop and write the mirror.
     */
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
        // On the store with a blank record (#201): what Add products leaves behind.
        $bare = $this->seedProduct('B');
        TikTokListing::create(['product_id' => $bare]);

        $r = $this->actingAs($this->manager())->get(route('ext.tiktok.products.index'));

        $r->assertOk();
        // One strip since #201: "All" is this store's own list, then
        // TikTok's buckets; icon-only refresh and Repair links beside the
        // freshness stamp (the Shopee shape).
        $r->assertSee('<span>All</span>', false);
        $r->assertDontSee('x-segment-group__k', false);
        $r->assertDontSee('All products');
        $r->assertSee('as of 08:30, Aug 28');
        $r->assertSee('aria-label="Refresh statuses from TikTok Shop"', false);
        $r->assertSee('Repair links');
        $r->assertSee('Live');                        // tt-live, from the mirror
        $r->assertSee('Not found on TikTok Shop');    // tt-gone, from the mirror
        $r->assertSee('Not checked yet');             // tt-new, never refreshed
        $r->assertSee('Ready to push');
        // The bare record inherits its group's category (a group is a preset
        // the product inherits, 2026-09-05), so nothing here lacks one.
        $r->assertDontSee('Missing: a TikTok category');
        $r->assertSee('Push to TikTok Shop');
        $r->assertSee('Under review');                // the tab bar exists
        $r->assertSee('Deactivate on TikTok Shop');   // the row verb for a live row
        $this->assertCount(0, $this->callsFor('POST', '/product/202309/products/search'));
    }

    /** A listing a push just gave a product id has no status; the page asks TikTok once for it, then reads the mirror. */
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
        // On TikTok through a group pivot only: the refresh gives it a listing row.
        $pivotOnly = $this->seedProduct('P');
        TikTokProductGroupProduct::query()->where('product_id', $pivotOnly)->update(['tiktok_product_id' => 'tt-off', 'sync_status' => 'synced']);

        $r = $this->actingAs($this->manager())->post(route('ext.tiktok.products.refresh_status'));

        $r->assertSessionHas('status');
        $this->assertStringContainsString('1 live', (string) session('status'));
        // 'unlisted', the engine's word - the row badge and the tab say it too (#184 D).
        $this->assertStringContainsString('1 unlisted', (string) session('status'));
        $this->assertCount(1, $this->callsFor('POST', '/product/202309/products/search'));
        $this->assertSame('ACTIVATE', TikTokListing::where('tiktok_product_id', 'tt-live')->value('live_status'));
        $this->assertSame('MISSING', TikTokListing::where('tiktok_product_id', 'tt-gone')->value('live_status'));
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
        $r->assertSee('Fetched from TikTok Shop as this page loaded.');
        $r->assertSee('Live on TikTok');
        $r->assertSee('LIVE-SKU');
        $r->assertSee('Deactivate on TikTok Shop');
        // And what it read is written to the mirror, so the lists agree at once.
        $this->assertSame('ACTIVATE', TikTokListing::query()->where('tiktok_product_id', 'tt-live')->value('live_status'));

        $this->actingAs($this->manager())->put(route('ext.tiktok.listings.update', $productId), [
            'tiktok_category_id' => '900009', 'brand_id' => 'b-1', 'brand_name' => 'Valeton',
            'markup_percent' => 10, 'title' => 'My own title', 'attributes' => ['100001' => 'v-ph'],
        ])->assertSessionHas('status');

        $listing = TikTokListing::where('product_id', $productId)->first();
        $this->assertSame('900009', $listing->tiktok_category_id);
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
        TikTokListing::create(['product_id' => $new, 'tiktok_category_id' => '900009']);
        $listed = $this->seedProduct('E');
        TikTokListing::create(['product_id' => $listed, 'tiktok_category_id' => '900009', 'tiktok_product_id' => 'tt-live', 'tiktok_sku_id' => 's1']);
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
        // Written through to the mirror.
        $this->assertSame('SELLER_DEACTIVATED', TikTokListing::where('tiktok_product_id', 'tt-live')->value('live_status'));
    }

    /** The selection bar's Deactivate / Activate (the owner, 2026-08-28): one call for the whole selection. */
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
        $r->assertDontSee('Push to TikTok Shop');
    }
}
