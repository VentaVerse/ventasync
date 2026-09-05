<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The individual listing page (quest 1, 2026-08-24): one product on Shopee,
 * shown the way the seller center shows it. The live half is fetched from
 * the marketplace on every load and NEVER substituted with stale data; the
 * record half edits the listing's own settings and content, where an empty
 * content field means "follows the catalog".
 */
class ShopeeListingPageTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{path: string, body: array}> */
    private array $sentPayloads = [];

    /** What the fake marketplace answers per GET path. */
    public array $getResponses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        ShopeeSetting::query()->create([
            'partner_id' => 1001, 'partner_key' => 'k', 'shop_id' => 2002,
            'access_token' => 't', 'refresh_token' => 'r', 'mode' => 'production',
        ]);

        $test = $this;
        $fake = new class($test) extends ShopeeClient {
            public function __construct(private $test) {}
            public function shopPost(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = [], array $body = []): array
            {
                $this->test->recordPayload($path, $body);

                return ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_id' => 990077]]];
            }
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                $answer = $this->test->getResponses[$path] ?? null;
                if ($answer instanceof \Closure) {
                    return $answer($extraQuery);
                }

                return $answer
                    ?? ['ok' => false, 'status' => 500, 'body' => ['message' => 'unexpected GET ' . $path]];
            }
            public function signShop(int $partnerId, string $partnerKey, string $path, int $timestamp, string $accessToken, int $shopId): string
            {
                return 'test-sign';
            }
        };
        $this->app->instance(ShopeeClient::class, $fake);
    }

    public function recordPayload(string $path, array $body): void
    {
        $this->sentPayloads[] = ['path' => $path, 'body' => $body];
    }

    /**
     * Warming a brand list (the owner, 2026-09-05): a first-time list is read
     * one page per request so the page can show progress; the search then
     * answers from the cache, and a category read to the end answers at
     * once.
     */
    public function test_a_brand_list_is_warmed_one_page_at_a_time_and_then_served_from_the_cache(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        $this->getResponses['/api/v2/product/get_brand_list'] = function (array $q) {
            $offset = (int) ($q['offset'] ?? 0);

            return ['ok' => true, 'status' => 200, 'body' => ['response' => [
                'brand_list' => $offset === 0
                    ? [['brand_id' => 11, 'original_brand_name' => 'Boss'], ['brand_id' => 12, 'original_brand_name' => 'Ibanez']]
                    : [['brand_id' => 13, 'original_brand_name' => 'Walrus Audio']],
                'has_next_page' => $offset === 0,
            ]]];
        };
        $user = $this->manager();

        $first = $this->actingAs($user)->getJson(route('ext.shopee.products.brandsForCategory', ['category_id' => 100013, 'warm' => 1, 'offset' => 0]))
            ->assertOk()->json();
        $this->assertFalse($first['complete']);
        $this->assertSame(2, $first['read']);
        $this->assertSame(100, $first['next_offset']);

        $second = $this->actingAs($user)->getJson(route('ext.shopee.products.brandsForCategory', ['category_id' => 100013, 'warm' => 1, 'offset' => 100]))
            ->assertOk()->json();
        $this->assertTrue($second['complete']);
        $this->assertSame(3, $second['read']);

        // Read to the end: the next warm answers at once, no call to Shopee.
        $this->getResponses['/api/v2/product/get_brand_list'] = ['ok' => false, 'status' => 500, 'body' => ['message' => 'must not be called']];
        $again = $this->actingAs($user)->getJson(route('ext.shopee.products.brandsForCategory', ['category_id' => 100013, 'warm' => 1, 'offset' => 0]))
            ->assertOk()->json();
        $this->assertTrue($again['complete']);

        $search = $this->actingAs($user)->getJson(route('ext.shopee.products.brandsForCategory', ['category_id' => 100013, 'q' => 'wal', 'limit' => 25]))
            ->assertOk()->json();
        $this->assertTrue($search['cached']);
        $this->assertSame(['Walrus Audio'], array_column($search['brands'], 'name'));
    }

    private int $groupSeq = 0;

    private function userWith(array $keys): User
    {
        $group = UserGroup::create(['name' => 'Listing page group ' . (++$this->groupSeq)]);
        $group->permissions()->attach(
            Permission::whereIn('key', $keys)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function manager(): User
    {
        return $this->userWith(['manage_shopee/product', 'view_shopee/product']);
    }

    private function seedProduct(float $price = 300.0): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'LSTP-1', 'sku' => 'LSTP-SKU-1', 'quantity' => 5, 'price' => $price,
            'status' => 1, 'image' => 'catalog/none.png', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);

        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'Listing page product', 'description' => 'The catalog wording.',
            'meta_title' => 'Listing page product', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    private function baseInfoAnswer(array $overrides = []): array
    {
        $item = array_merge([
            'item_id' => 990077, 'item_status' => 'NORMAL',
            'item_name' => 'Listing page product', 'item_sku' => 'LSTP-SKU-1',
            'has_model' => false, 'has_promotion' => false, 'update_time' => 1756000000,
            'image' => ['image_url_list' => []],
            'price_info' => [['original_price' => 450.0, 'current_price' => 450.0]],
            'stock_info_v2' => ['summary_info' => ['total_available_stock' => 7]],
        ], $overrides);

        return ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_list' => [$item]]]];
    }

    public function test_an_unpushed_product_shows_readiness_instead_of_a_live_state(): void
    {
        $productId = $this->seedProduct();

        $r = $this->actingAs($this->manager())
            ->get(route('ext.shopee.listings.edit', $productId));

        $r->assertOk();
        $r->assertSee('Not on Shopee yet');
        $r->assertSee('a Shopee category');
        $r->assertDontSee('On Shopee right now');
    }

    public function test_saving_stores_settings_and_blank_content_follows_the_catalog(): void
    {
        $productId = $this->seedProduct();
        $user = $this->manager();

        $this->actingAs($user)->post(route('ext.shopee.listings.update', $productId), [
            'shopee_category_id' => 100013,
            'logistic_ids' => [8003],
            'markup_percent' => 10,
            'item_name' => 'A Shopee-only title',
            'description' => '',
            'weight' => '',
        ]);

        $listing = ShopeeListing::query()->where('product_id', $productId)->firstOrFail();
        $this->assertSame(100013, (int) $listing->shopee_category_id);
        $this->assertSame([8003], $listing->logistic_ids);
        $this->assertSame(10.0, (float) $listing->markup_percent);
        $this->assertSame('A Shopee-only title', $listing->item_name);
        $this->assertNull($listing->description, 'Blank means: follows the catalog.');
        $this->assertNull($listing->weight);

        // Emptying a filled field ends the divergence.
        $this->actingAs($user)->post(route('ext.shopee.listings.update', $productId), [
            'shopee_category_id' => 100013,
            'logistic_ids' => [8003],
            'item_name' => '',
        ]);

        $this->assertNull($listing->fresh()->item_name, 'Clearing the field must return it to the catalog.');
    }

    public function test_a_linked_product_shows_shopees_live_answer(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);
        $this->getResponses['/api/v2/product/get_item_base_info'] = $this->baseInfoAnswer();

        $r = $this->actingAs($this->manager())
            ->get(route('ext.shopee.listings.edit', $productId));

        $r->assertOk();
        $r->assertSee('On Shopee right now');
        $r->assertSee('Live');
        $r->assertSee('Unlist from Shopee');
        // And what it read is written to the mirror, so the lists agree at once.
        $this->assertSame('NORMAL', ShopeeProductLink::query()->where('shopee_item_id', 990077)->value('live_status'));
    }

    public function test_when_shopee_does_not_answer_the_page_says_so_instead_of_substituting(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);
        $this->getResponses['/api/v2/product/get_item_base_info'] =
            ['ok' => false, 'status' => 500, 'body' => ['message' => 'read timeout']];

        $r = $this->actingAs($this->manager())
            ->get(route('ext.shopee.listings.edit', $productId));

        $r->assertOk();
        $r->assertSee('Shopee did not answer');
        $r->assertDontSee('Unlist from Shopee');
    }

    public function test_the_toggle_reaches_unlist_item_with_the_asked_direction(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.toggle', $productId), ['action' => 'unlist']);

        $sent = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/unlist_item');
        $this->assertNotNull($sent, 'The toggle must reach unlist_item.');
        $this->assertSame(990077, (int) $sent['body']['item_list'][0]['item_id']);
        $this->assertTrue((bool) $sent['body']['item_list'][0]['unlist']);
        $r->assertSessionHas('status');
    }

    /** get_item_list answers: counts per status, and one page of ids. */
    private function fakeItemList(array $idsByStatus): void
    {
        $this->getResponses['/api/v2/product/get_item_list'] = function (array $q) use ($idsByStatus) {
            $ids = $idsByStatus[$q['item_status'] ?? ''] ?? [];

            return ['ok' => true, 'status' => 200, 'body' => ['response' => [
                'total_count' => count($ids),
                'item' => ((int) ($q['page_size'] ?? 1)) === 1
                    ? array_slice(array_map(fn ($i) => ['item_id' => $i, 'item_status' => 'X'], $ids), 0, 1)
                    : array_map(fn ($i) => ['item_id' => $i, 'item_status' => 'X'], $ids),
                'has_next_page' => false,
            ]]];
        };
    }

    public function test_the_index_tabs_count_the_mirror(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create(['product_id' => $productId, 'shopee_item_id' => 111, 'sku' => 'A', 'live_status' => 'NORMAL']);

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.products.index'));

        $r->assertOk();
        $r->assertSee('Under review');
        $r->assertSee('shopee_tab=live', false);
        $this->assertEmpty($this->sentPayloads);
    }

    /**
     * Since 2026-08-28 the strip and the chips read the MIRROR on the link
     * rows. A page view asks Shopee for nothing; the Refresh button and the
     * 30-minute automation walk the shop and write the mirror.
     */
    public function test_the_page_reads_the_mirror_and_asks_shopee_for_nothing(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 111, 'sku' => 'LSTP-SKU-1',
            'live_status' => 'UNLIST', 'live_checked_at' => '2026-08-28 08:30:00',
        ]);
        // No get_item_list or get_item_base_info registered: the page must not need them.

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.products.index'));

        $r->assertOk();
        // One strip since #201: "All" is this store's own list, then
        // Shopee's buckets. The catalogue framing is gone with the
        // catalogue-as-table.
        $r->assertSee('<span>All</span>', false);
        $r->assertDontSee('x-segment-group__k', false); // no "Your catalogue" / "On Shopee" labels
        $r->assertDontSee('All products');
        $r->assertSee('as of 08:30, Aug 28');
        // Icon-only since the cleanup (2026-09-03); the words live in the
        // accessible name.
        $r->assertSee('aria-label="Refresh statuses from Shopee"', false);
        $r->assertSee('Repair links');
        $r->assertSee('shopee_tab=unlisted', false);
        $r->assertSee('Unlisted');
        $r->assertSee('Relist on Shopee');
        $this->assertEmpty($this->sentPayloads);
    }

    /** A link a push just created has no status; the page asks Shopee once for those rows only, then reads the mirror. */
    public function test_a_link_never_checked_is_filled_on_first_view_and_only_once(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create(['product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1']);
        $calls = 0;
        $this->getResponses['/api/v2/product/get_item_base_info'] = function () use (&$calls) {
            $calls++;
            return $this->baseInfoAnswer(['item_status' => 'REVIEWING']);
        };

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.products.index'));
        $r->assertOk();
        $r->assertSee('Under review');
        $r->assertDontSee('Not checked yet');
        $this->assertSame(1, $calls);
        $this->assertSame('REVIEWING', ShopeeProductLink::where('shopee_item_id', 990077)->value('live_status'));

        $this->actingAs($this->manager())->get(route('ext.shopee.products.index'))->assertOk();
        $this->assertSame(1, $calls, 'the second view reads the mirror and asks nothing');
    }

    public function test_a_link_shopee_will_not_answer_for_reads_not_checked_yet(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create(['product_id' => $productId, 'shopee_item_id' => 111, 'sku' => 'LSTP-SKU-1']);
        // No get_item_base_info registered: the fake refuses; the row stays blank rather than guessed.

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.products.index'));

        $r->assertOk();
        $r->assertSee('Not checked yet');
        $r->assertSee('not refreshed yet');
    }

    public function test_refresh_walks_the_shop_and_writes_every_links_status(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create(['product_id' => $productId, 'shopee_item_id' => 111, 'sku' => 'LSTP-SKU-1']);
        ShopeeProductLink::query()->create(['product_id' => $productId, 'shopee_item_id' => 424242, 'sku' => 'GONE']);
        $this->fakeItemList(['NORMAL' => [111, 222], 'UNLIST' => [333], 'BANNED' => [], 'REVIEWING' => [], 'SELLER_DELETE' => [], 'SHOPEE_DELETE' => []]);

        $r = $this->actingAs($this->manager())->post(route('ext.shopee.products.refresh_status'));

        $r->assertSessionHas('status');
        $this->assertStringContainsString('1 live', (string) session('status'));
        $this->assertSame('NORMAL', ShopeeProductLink::where('shopee_item_id', 111)->value('live_status'));
        $this->assertSame('MISSING', ShopeeProductLink::where('shopee_item_id', 424242)->value('live_status'), 'an item the shop no longer returns');
        $this->assertNotNull(ShopeeProductLink::where('shopee_item_id', 111)->value('live_checked_at'));
    }

    public function test_refresh_leaves_the_mirror_alone_when_shopee_does_not_answer(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 111, 'sku' => 'LSTP-SKU-1', 'live_status' => 'NORMAL',
        ]);
        // No get_item_list response registered: the fake answers ok=false.

        $r = $this->actingAs($this->manager())->post(route('ext.shopee.products.refresh_status'));

        $r->assertSessionHas('error');
        $this->assertStringContainsString('Shopee did not answer', (string) session('error'));
        $this->assertSame('NORMAL', ShopeeProductLink::where('shopee_item_id', 111)->value('live_status'));
    }

    public function test_a_shopee_tab_narrows_the_table_by_the_mirror(): void
    {
        $inTab = $this->seedProduct();
        $pfx = (string) config('catalog.prefix');
        ShopeeProductLink::query()->create(['product_id' => $inTab, 'shopee_item_id' => 111, 'sku' => 'TAB-IN', 'live_status' => 'NORMAL']);
        $outOfTab = DB::table($pfx . 'product')->insertGetId([
            'model' => 'LSTP-2', 'sku' => 'TAB-OUT', 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => '', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $outOfTab, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Unlisted elsewhere product', 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);
        ShopeeProductLink::query()->create(['product_id' => $outOfTab, 'shopee_item_id' => 333, 'sku' => 'TAB-OUT', 'live_status' => 'UNLIST']);

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.products.index', ['shopee_tab' => 'live']));

        $r->assertOk();
        $r->assertSee('Listing page product');
        $r->assertDontSee('Unlisted elsewhere product');
        $this->assertEmpty($this->sentPayloads);
    }

    public function test_a_toggle_writes_through_to_the_mirror(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1', 'live_status' => 'NORMAL',
        ]);

        $this->actingAs($this->manager())->post(route('ext.shopee.listings.toggle', $productId), ['action' => 'unlist']);

        $this->assertSame('UNLIST', ShopeeProductLink::where('shopee_item_id', 990077)->value('live_status'));
    }

    /** The selection bar's Unlist / Relist (the owner, 2026-08-28): one unlist_item call for the whole selection. */
    public function test_bulk_toggle_unlists_the_selection_in_one_call_and_names_what_it_skipped(): void
    {
        $a = $this->seedProduct();
        $pfx = (string) config('catalog.prefix');
        $b = DB::table($pfx . 'product')->insertGetId([
            'model' => 'LSTP-2', 'sku' => 'LSTP-SKU-2', 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => '', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        ShopeeProductLink::query()->create(['product_id' => $a, 'shopee_item_id' => 111, 'sku' => 'LSTP-SKU-1']);
        ShopeeProductLink::query()->create(['product_id' => $b, 'shopee_item_id' => 222, 'sku' => 'LSTP-SKU-2']);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.bulk_toggle'), ['action' => 'unlist', 'product_ids' => [$a, $b, 4040]]);

        $sent = collect($this->sentPayloads)->where('path', '/api/v2/product/unlist_item');
        $this->assertCount(1, $sent, 'one call for the whole selection');
        $items = collect($sent->first()['body']['item_list']);
        $this->assertEqualsCanonicalizing([111, 222], $items->pluck('item_id')->map('intval')->all());
        $this->assertTrue($items->every(fn ($i) => $i['unlist'] === true));

        $r->assertSessionHas('status');
        $flash = (string) session('status');
        $this->assertStringContainsString('Unlisted 2 items from Shopee', $flash);
        $this->assertStringContainsString('1 skipped, not on Shopee', $flash);
    }

    public function test_bulk_toggle_with_nothing_on_shopee_refuses_plainly(): void
    {
        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.bulk_toggle'), ['action' => 'list', 'product_ids' => [4040]])
            ->assertSessionHas('error');
        $this->assertEmpty(collect($this->sentPayloads)->where('path', '/api/v2/product/unlist_item'));
    }

    public function test_push_update_sends_the_merged_listing_onto_the_live_item(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
            'item_name' => 'A Shopee-only title',
            // description left null: the catalog's wording goes.
        ]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $productId));

        $sent = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item');
        $this->assertNotNull($sent, 'The update must reach update_item.');
        $this->assertSame(990077, (int) $sent['body']['item_id']);
        $this->assertSame('A Shopee-only title', $sent['body']['item_name'], 'The override goes.');
        $this->assertSame('The catalog wording.', $sent['body']['description'], 'Null means the catalog goes.');
        $this->assertSame(100013, (int) $sent['body']['category_id']);
        $this->assertSame(8003, (int) $sent['body']['logistic_info'][0]['logistic_id']);
        $this->assertArrayNotHasKey('original_price', $sent['body'], 'Price never rides a content update.');
        $this->assertSame(0, (int) $sent['body']['brand']['brand_id'],
            'The brand block always rides an update - Shopee refuses one without it.');
        $this->assertSame('No Brand', $sent['body']['brand']['original_brand_name']);
        $this->assertArrayNotHasKey('seller_stock', $sent['body'], 'Stock never rides a content update.');
        $r->assertSessionHas('status');

        $listing = ShopeeListing::query()->where('product_id', $productId)->firstOrFail();
        $this->assertSame('listing', $listing->last_push_source);
        $this->assertNotNull($listing->last_pushed_at);
    }

    public function test_push_update_refuses_a_product_that_is_not_on_shopee(): void
    {
        $productId = $this->seedProduct();

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $productId));

        $this->assertNull(collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item'));
        $r->assertSessionHas('error');
    }

    public function test_a_listing_with_a_brand_pushes_it_by_name(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);
        \Extensions\shopee\Models\ShopeeBrand::query()->create([
            'category_id' => 106755, 'brand_id' => 5511, 'name' => 'G7th',
        ]);
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'shopee_category_id' => 106755, 'logistic_ids' => [41003],
            'shopee_brand_id' => 5511,
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $productId));

        $sent = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item');
        $this->assertSame(5511, (int) $sent['body']['brand']['brand_id']);
        $this->assertSame('G7th', $sent['body']['brand']['original_brand_name']);
    }

    public function test_the_brand_search_answers_this_categorys_brands_only(): void
    {
        \Extensions\shopee\Models\ShopeeBrand::query()->create(['category_id' => 106755, 'brand_id' => 5511, 'name' => 'G7th']);
        \Extensions\shopee\Models\ShopeeBrand::query()->create(['category_id' => 106755, 'brand_id' => 5512, 'name' => 'Dunlop']);

        $r = $this->actingAs($this->userWith(['view_shopee/product']))
            ->get(route('ext.shopee.products.brandsForCategory', ['category_id' => 106755, 'q' => 'G7', 'limit' => 25]));

        $r->assertOk();
        $r->assertJson(['ok' => true]);
        $brands = $r->json('brands');
        $this->assertCount(1, $brands);
        $this->assertSame('G7th', $brands[0]['name']);
    }

    public function test_the_category_picker_endpoints_answer_to_the_products_key(): void
    {
        // The push form and listing page's combobox search under THEIR OWN
        // permission key: an operator with shopee/product but no
        // shopee/product_group must not need the group key to pick a
        // category.
        $this->actingAs($this->userWith(['view_shopee/product']))
            ->get(route('ext.shopee.products.searchCategories', ['q' => 'guitar']))
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_a_check_stamps_the_product_not_the_page_it_ran_from(): void
    {
        /* Stage 2 of the one-truth repointing: "checked 37 minutes ago" is
         * a fact about the PRODUCT. It lands on the listing record - where
         * every group page and the Listings page read it - and the pivot's
         * per-group copy stops being written. */
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);
        $group = \Extensions\shopee\Models\ShopeeProductGroup::create([
            'name' => 'Check stampers',
            'shopee_category_id' => 100013, 'logistic_ids' => [41003],
        ]);
        DB::table('shopee_product_group_products')->insert([
            'shopee_product_group_id' => $group->id, 'product_id' => $productId,
        ]);
        // The batch prefill confirms the item alive.
        $this->getResponses['/api/v2/product/get_item_base_info'] = $this->baseInfoAnswer();

        $this->actingAs($this->userWith([
            'manage_shopee/product', 'view_shopee/product',
            'manage_shopee/product_group', 'view_shopee/product_group',
        ]))->post(route('ext.shopee.product-groups.check', $group->id), ['ids' => [$productId]]);

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        $this->assertNotNull($listing, 'The check must leave its stamp on the product record.');
        $this->assertNotNull($listing->last_checked_at);

        $pivot = DB::table('shopee_product_group_products')
            ->where('shopee_product_group_id', $group->id)
            ->where('product_id', $productId)->first();
        $this->assertNull($pivot->last_confirmed_at,
            'The per-group copy of the checked fact must no longer be written.');
    }

    /**
     * The workbench check (Phase F, #184): the group page's sweep with no
     * group anywhere in sight, on the shared ShopeeLinkCheck. It also
     * replaced Match Shopee IDs, which could only fill in blanks.
     */
    public function test_the_workbench_check_needs_no_group(): void
    {
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);
        $this->getResponses['/api/v2/product/get_item_base_info'] = $this->baseInfoAnswer();

        $r = $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.check'), ['product_ids' => [$productId]]);

        $r->assertSessionHas('status');
        $this->assertStringContainsString('1 still on Shopee', session('status'));
        $this->assertNotNull(ShopeeListing::query()->where('product_id', $productId)->value('last_checked_at'));
    }

    public function test_the_workbench_offers_the_check_and_match_ids_is_gone(): void
    {
        $this->seedProduct();
        $html = $this->actingAs($this->manager())
            ->get(route('ext.shopee.products.index'))->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, route('ext.shopee.products.check')),
            'the head checks every linked product, the bar checks the selection');
        $this->assertStringNotContainsString('Match Shopee IDs', $html,
            'Match Shopee IDs is back; the check replaced it because it could only fill in blanks, never question an id a row already had');
    }

    /** A group is a preset the product inherits (the owner, 2026-09-05): a workbench push fills a blank listing from its group. */
    public function test_a_workbench_push_takes_the_groups_category_and_couriers_when_the_listing_has_none(): void
    {
        $productId = $this->seedProduct();
        ShopeeListing::query()->create(['product_id' => $productId]);
        $group = \Extensions\shopee\Models\ShopeeProductGroup::create(['name' => 'Pedals preset', 'shopee_category_id' => 100013, 'logistic_ids' => [8003]]);
        \Extensions\shopee\Models\ShopeeProductGroupProduct::create(['shopee_product_group_id' => $group->id, 'product_id' => $productId]);
        \Extensions\shopee\Models\ShopeeCategoryTemplate::query()->firstOrCreate(['category_id' => 100013], ['attributes' => []]);
        \Extensions\shopee\Models\ShopeeCategory::create(['category_id' => 100013, 'parent_id' => null, 'name' => 'Pedals', 'level' => 0, 'leaf' => true]);
        \Extensions\shopee\Models\ShopeeLogistic::create(['logistics_channel_id' => 8003, 'logistics_channel_name' => 'J&T Express', 'enabled' => true]);
        @mkdir(public_path('image/catalog'), 0777, true);
        file_put_contents(public_path('image/catalog/__inherit-test.png'), 'png');
        DB::table(config('catalog.prefix') . 'product')->where('product_id', $productId)->update(['image' => 'catalog/__inherit-test.png']);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['response' => ['image_info' => ['image_id' => 'img-inherit']]], 200)]);
        $this->getResponses['/api/v2/product/get_item_base_info'] = ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_list' => []]]];

        $this->actingAs($this->manager())->post(route('ext.shopee.products.bulk_push'), ['product_ids' => [$productId]]);

        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add, 'the push went, with the group filling the blanks');
        $this->assertSame(100013, (int) $add['body']['category_id']);
        $this->assertSame([8003], array_column($add['body']['logistic_info'], 'logistic_id'));

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        $this->assertNull($listing->shopee_category_id, 'the listing\'s own record stays blank: the group is a preset, not a write');
        $this->assertSame('Pedals preset', $listing->last_push_settings['inherited_from']['category']);
    }

    public function test_bulk_send_updates_already_listed_selections_instead_of_skipping(): void
    {
        /* Critique P1 (2026-08-25): the index bulk used to skip anything
         * with a link row forever - a listing deleted in Seller Centre was
         * skipped on every push with no way to notice. Send semantics: an
         * already-listed selection gets a content UPDATE. */
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);
        // The store has been set up: with nothing fetched the push stops before this (StoreSetupTest).
        \Extensions\shopee\Models\ShopeeCategory::create(['category_id' => 100013, 'parent_id' => null, 'name' => 'Pedals', 'level' => 0, 'leaf' => true]);
        \Extensions\shopee\Models\ShopeeLogistic::create(['logistics_channel_id' => 8003, 'logistics_channel_name' => 'J&T Express', 'enabled' => true]);

        $r = $this->actingAs($this->manager())->post(route('ext.shopee.products.bulk_push'), [
            'product_ids' => [$productId],
        ]);

        $update = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/update_item');
        $this->assertNotNull($update, 'An already-listed selection must be updated, not silently skipped.');
        $this->assertSame(990077, (int) $update['body']['item_id']);
        $this->assertStringContainsString('updated in place', session('status') ?? session('error') ?? '');
    }

    /**
     * #151 (2026-08-26): a multi-select push opens one combined review form
     * for the selection, and what it states rides the whole batch without
     * being written onto any listing.
     */
    public function test_a_multi_select_push_reviews_as_one_form_and_the_shared_rule_rides_the_batch(): void
    {
        $productId = $this->seedProduct();
        ShopeeListing::query()->create([
            'product_id' => $productId, 'shopee_category_id' => 100013, 'logistic_ids' => [8003],
            'markup_percent' => 0, 'markup_fixed' => 0,
        ]);
        \Extensions\shopee\Models\ShopeeCategoryTemplate::query()->firstOrCreate(['category_id' => 100013], ['attributes' => []]);
        \Extensions\shopee\Models\ShopeeCategory::create(['category_id' => 100013, 'parent_id' => null, 'name' => 'Pedals', 'level' => 0, 'leaf' => true]);
        \Extensions\shopee\Models\ShopeeLogistic::create([
            'logistics_channel_id' => 8003, 'logistics_channel_name' => 'J&T Express', 'enabled' => true,
            'cod_enabled' => true, 'force_enable' => false, 'mask_channel_id' => 0,
        ]);
        // A real image for the push to upload, and the upload answered.
        @mkdir(public_path('image/catalog'), 0777, true);
        file_put_contents(public_path('image/catalog/__bulk-review-test.png'), 'png');
        DB::table(config('catalog.prefix') . 'product')->where('product_id', $productId)->update(['image' => 'catalog/__bulk-review-test.png']);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['response' => ['image_info' => ['image_id' => 'img-bulk']]], 200)]);
        $core = (float) DB::table(config('catalog.prefix') . 'product')->where('product_id', $productId)->value('price');

        // The bar opens the form for the ticked products, and the form names them.
        $form = $this->actingAs($this->manager())
            ->get(route('ext.shopee.products.bulk_push_form', ['product_ids' => [$productId]]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Push 1 product to Shopee', $form);
        $this->assertStringContainsString('name="shared_markup_percent"', $form);
        $this->assertStringContainsString('name="shared_logistic_ids[]"', $form);
        $this->assertStringContainsString('New listing', $form);

        $this->getResponses['/api/v2/product/get_item_base_info'] = ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_list' => []]]];
        $this->actingAs($this->manager())->post(route('ext.shopee.products.bulk_push'), [
            'product_ids' => [$productId],
            'shared_markup_percent' => 10,
            'shared_markup_fixed' => 5,
        ]);

        $add = collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add, 'the batch pushed');
        $this->assertSame(round($core * 1.10 + 5, 2), (float) $add['body']['original_price'], 'priced by the shared rule, not the listing\'s own');

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        $this->assertSame(0.0, (float) $listing->markup_percent, 'the shared rule was sent, never written onto the listing');
        $this->assertSame('bulk', $listing->last_push_source);
        $this->assertSame(10.0, (float) $listing->last_push_settings['markup_percent'], 'and recorded as what was sent');
        @unlink(public_path('image/catalog/__bulk-review-test.png'));
    }

    public function test_the_row_menu_push_asks_before_publishing(): void
    {
        // Critique P1: the index kebab's one-click push published a live
        // listing with no confirmation while its neighbours asked.
        $productId = $this->seedProduct();
        // A catch-all group brings the unlinked product into the index's scope.
        \Extensions\shopee\Models\ShopeeProductGroup::create([
            'name' => 'Confirm scope',
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
        ]);
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
        ]);
        $this->fakeItemList(['NORMAL' => [], 'UNLIST' => [], 'BANNED' => [], 'REVIEWING' => []]);
        $this->getResponses['/api/v2/product/get_item_base_info'] =
            ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_list' => []]]];

        /* The one-editor merge (2026-09-03) settled #149's concern the
           other way around: the row pushes directly ONLY when readiness
           says Shopee would accept it, and then it asks first - the very
           confirmation this critique wanted. The listing above is ready
           (its category's sheet is seeded empty), so the row must offer
           the confirmed direct push. */
        \Extensions\shopee\Models\ShopeeCategoryTemplate::query()->firstOrCreate(['category_id' => 100013], ['attributes' => []]);

        $this->actingAs($this->manager())
            ->get(route('ext.shopee.products.index'))
            ->assertSee(route('ext.shopee.products.push_direct', $productId), false)
            // The ask is the review MODAL now (cleanup 7+9): the form
            // carries the review endpoint and shows what goes up before
            // it goes; without JS it still pushes natively.
            ->assertSee('data-push-review="' . route('ext.shopee.products.push_review', $productId) . '"', false);
    }

    public function test_an_unready_rows_push_leads_into_the_listing_editor(): void
    {
        /* The other half of the merge: no settings at all means no direct
           push - the same words lead into the editor, which names what is
           missing (proper error handling, the owner 2026-09-03). */
        $productId = $this->seedProduct();
        // On the store (#201) with a blank record - exactly what Add products leaves behind.
        \Extensions\shopee\Models\ShopeeListing::create(['product_id' => $productId]);
        \Extensions\shopee\Models\ShopeeProductGroup::create([
            'name' => 'Unready scope',
            'shopee_category_id' => 100013, 'logistic_ids' => [8003],
        ]);
        $this->fakeItemList(['NORMAL' => [], 'UNLIST' => [], 'BANNED' => [], 'REVIEWING' => []]);
        $this->getResponses['/api/v2/product/get_item_base_info'] =
            ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_list' => []]]];

        $this->actingAs($this->manager())
            ->get(route('ext.shopee.products.index'))
            ->assertDontSee(route('ext.shopee.products.push_direct', $productId), false)
            ->assertSee('Not ready yet - the listing page names what is still missing', false);
    }

    public function test_the_item_id_links_to_the_live_listing(): void
    {
        // Critique P2: the operator's terminal goal is the live marketplace
        // page; the id is now the door to it.
        $productId = $this->seedProduct();
        ShopeeProductLink::query()->create([
            'product_id' => $productId, 'shopee_item_id' => 990077, 'sku' => 'LSTP-SKU-1',
        ]);
        $this->getResponses['/api/v2/product/get_item_base_info'] = $this->baseInfoAnswer();

        $this->actingAs($this->manager())
            ->get(route('ext.shopee.listings.edit', $productId))
            ->assertSee('https://shopee.ph/product/2002/990077', false);
    }

    public function test_a_view_only_operator_reads_the_page_without_verbs(): void
    {
        // Every screen serves two permission worlds: the view tier reads
        // the same page with every field disabled and no verbs, instead
        // of a 403 from a menu that offered the link (critique 2026-08-25).
        $productId = $this->seedProduct();

        $r = $this->actingAs($this->userWith(['view_shopee/product']))
            ->get(route('ext.shopee.listings.edit', $productId));

        $r->assertOk();
        $r->assertSee('You can read this.');
        $r->assertDontSee('Push to Shopee');
        $r->assertDontSee('Save listing');
    }
}
