<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lazada parity for the live listing surfaces (2026-08-24): the listings
 * index wears the seller center's tabs answered by /products/get on every
 * load, uploaded rows show Lazada's live status (or "Not found on Lazada"
 * when a successful answer omits them), and the edit page opens with the
 * item's live state from /product/item/get. When Lazada does not answer,
 * every surface says so instead of substituting stale data.
 */
class LazadaListingLiveTest extends TestCase
{
    use RefreshDatabase;

    /** What the fake marketplace answers, keyed by api path. Closures get the params. */
    public array $getResponses = [];

    /** @var array<int, array{path: string, params: array}> */
    public array $sentPosts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        LazadaSetting::query()->create([
            'mode' => 'live', 'region' => 'ph',
            'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r',
        ]);

        $test = $this;
        $fake = new class($test) extends LazadaClient {
            public function __construct(private $test) {}
            public function post(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                $this->test->sentPosts[] = ['path' => $apiPath, 'params' => $params];

                return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => []]];
            }
            public function get(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                $answer = $this->test->getResponses[$apiPath] ?? null;
                if ($answer instanceof \Closure) {
                    return $answer($params);
                }

                return $answer
                    ?? ['ok' => false, 'status' => 500, 'body' => ['code' => '500', 'message' => 'unexpected GET ' . $apiPath]];
            }
            public function sign(string $apiPath, array $params, string $appSecret): string
            {
                return 'test-sign';
            }
        };
        $this->app->instance(LazadaClient::class, $fake);
    }

    private int $groupSeq = 0;

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Lazada live group ' . (++$this->groupSeq)]);
        $group->permissions()->attach(
            Permission::whereIn('key', [
                'manage_lazada/product', 'view_lazada/product',
                'manage_lazada/product_group', 'view_lazada/product_group',
            ])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $name, string $sku): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => '', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => $name, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    /** /products/get answers: counts and ids per filter, plus sku lookups. */
    private function fakeProductsGet(array $idsByFilter, array $statusById = []): void
    {
        $this->getResponses['/products/get'] = function (array $params) use ($idsByFilter, $statusById) {
            if (!empty($params['sku_seller_list'])) {
                $products = [];
                foreach ($statusById as $id => $status) {
                    $products[] = ['item_id' => $id, 'status' => $status];
                }

                return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
                    'total_products' => count($products), 'products' => $products,
                ]]];
            }

            $ids = $idsByFilter[$params['filter'] ?? ''] ?? [];
            $products = array_map(fn ($i) => ['item_id' => $i, 'status' => 'x'], $ids);

            return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
                'total_products' => count($ids),
                'products' => ((int) ($params['limit'] ?? 1)) === 1 ? array_slice($products, 0, 1) : $products,
            ]]];
        };
    }

    /** #149 (2026-08-26): the row menu opens the listing page, where the push button now lives; no one-click upload from the index. */
    public function test_the_row_menu_push_opens_the_listing_page_and_the_page_carries_the_push(): void
    {
        $productId = $this->seedProduct('One push product', 'LZT-ONEPUSH');
        $listing = LazadaProduct::query()->create(['product_id' => $productId]);
        $this->fakeProductsGet(['live' => [], 'inactive' => [], 'pending' => [], 'rejected' => [], 'sold-out' => [], 'deleted' => []]);

        $index = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'))->assertOk();
        $index->assertSee('Push to Lazada');
        $index->assertDontSee('Upload to Lazada');

        $page = $this->actingAs($this->manager())->get(route('ext.lazada.products.edit', $listing->id))->assertOk();
        $page->assertSee(route('ext.lazada.products.upload', $listing->id), false);
    }

    /**
     * Since 2026-08-28 the strip and the chips read the MIRROR on the
     * listing rows. A page view asks Lazada for nothing; the Refresh button
     * and the 30-minute automation walk the shop and write the mirror.
     */
    public function test_the_page_reads_the_mirror_and_asks_lazada_for_nothing(): void
    {
        $productId = $this->seedProduct('Mirrored product', 'LZT-MIR');
        LazadaProduct::query()->create([
            'product_id' => $productId, 'lazada_item_id' => '111',
            'live_status' => 'inactive', 'live_checked_at' => '2026-08-28 08:30:00',
        ]);
        // No /products/get response registered: the page must not need one.

        $r = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'));

        $r->assertOk();
        $r->assertSee('Your catalogue');
        $r->assertSee('On Lazada');
        $r->assertSee('All products');
        $r->assertSee('Pending QC');
        $r->assertSee('as of 08:30, Aug 28');
        $r->assertSee('Refresh from Lazada');
        $r->assertSee('lazada_tab=active', false);
        $r->assertSee('Activate on Lazada');
        $this->assertSame([], $this->sentPosts);
    }

    /** A row a push just gave an item id has no status; the page asks Lazada once for it, then reads the mirror. */
    public function test_an_uploaded_row_never_checked_is_filled_on_first_view_and_only_once(): void
    {
        $productId = $this->seedProduct('Unchecked product', 'LZT-UNC');
        LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '111']);
        $calls = 0;
        $this->getResponses['/product/item/get'] = function () use (&$calls) {
            $calls++;
            return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
                'item_id' => 111, 'status' => 'pending', 'attributes' => ['name' => 'Unchecked product'],
                'skus' => [['SellerSku' => 'LZT-UNC', 'Status' => 'pending']],
            ]]];
        };

        $r = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'));
        $r->assertOk();
        $r->assertSee('Pending QC');
        $r->assertDontSee('Not checked yet');
        $this->assertSame(1, $calls);
        $this->assertSame('pending', LazadaProduct::query()->where('lazada_item_id', '111')->value('live_status'));

        $this->actingAs($this->manager())->get(route('ext.lazada.products.index'))->assertOk();
        $this->assertSame(1, $calls, 'the second view reads the mirror and asks nothing');
    }

    public function test_an_uploaded_row_lazada_will_not_answer_for_reads_not_checked_yet(): void
    {
        $productId = $this->seedProduct('Unchecked product', 'LZT-UNC');
        LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '111']);
        // No /product/item/get registered: the fake refuses; the row stays blank rather than guessed.

        $r = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'));

        $r->assertOk();
        $r->assertSee('Not checked yet');
        $r->assertSee('not refreshed yet');
    }

    public function test_a_tab_narrows_the_table_by_the_mirror(): void
    {
        $inTab = $this->seedProduct('Active on Lazada product', 'LZT-IN');
        LazadaProduct::query()->create(['product_id' => $inTab, 'lazada_item_id' => '111', 'live_status' => 'active']);
        $outOfTab = $this->seedProduct('Inactive elsewhere product', 'LZT-OUT');
        LazadaProduct::query()->create(['product_id' => $outOfTab, 'lazada_item_id' => '333', 'live_status' => 'inactive']);

        $r = $this->actingAs($this->manager())
            ->get(route('ext.lazada.products.index', ['lazada_tab' => 'active']));

        $r->assertOk();
        $r->assertSee('Active on Lazada product');
        $r->assertDontSee('Inactive elsewhere product');
        $r->assertSee('Deactivate on Lazada');
    }

    public function test_refresh_walks_the_shop_and_writes_every_rows_status(): void
    {
        $a = $this->seedProduct('Refreshed product', 'LZT-A');
        LazadaProduct::query()->create(['product_id' => $a, 'lazada_item_id' => '111']);
        $ghost = $this->seedProduct('Refresh residue product', 'LZT-GHOST');
        LazadaProduct::query()->create(['product_id' => $ghost, 'lazada_item_id' => '424242']);
        $this->getResponses['/products/get'] = function (array $params) {
            $products = ($params['filter'] ?? '') === 'all'
                ? [['item_id' => 111, 'status' => 'active'], ['item_id' => 222, 'status' => 'inactive']]
                : [];
            return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => ['total_products' => count($products), 'products' => $products]]];
        };

        $r = $this->actingAs($this->manager())->post(route('ext.lazada.products.refresh_status'));

        $r->assertSessionHas('status');
        $this->assertStringContainsString('1 active', (string) session('status'));
        $this->assertStringContainsString('1 not found on Lazada', (string) session('status'));
        $this->assertSame('active', LazadaProduct::where('lazada_item_id', '111')->value('live_status'));
        $this->assertSame('missing', LazadaProduct::where('lazada_item_id', '424242')->value('live_status'));
        $this->assertNotNull(LazadaProduct::where('lazada_item_id', '111')->value('live_checked_at'));

        $page = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'));
        $page->assertSee('Not found on Lazada');
        $page->assertDontSee('>Listed<', false);
    }

    public function test_refresh_leaves_the_mirror_alone_when_lazada_does_not_answer(): void
    {
        $productId = $this->seedProduct('Still visible product', 'LZT-KEEP');
        LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '111', 'live_status' => 'active']);
        // No /products/get response registered: the fake answers ok=false.

        $r = $this->actingAs($this->manager())->post(route('ext.lazada.products.refresh_status'));

        $r->assertSessionHas('error');
        $this->assertStringContainsString('Lazada did not answer', (string) session('error'));
        $this->assertSame('active', LazadaProduct::where('lazada_item_id', '111')->value('live_status'));

        // And the page still paints from what it has.
        $page = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'));
        $page->assertOk();
        $page->assertSee('Still visible product');
    }

    public function test_a_toggle_writes_through_to_the_mirror(): void
    {
        $productId = $this->seedProduct('Write-through product', 'LZT-WT');
        $listing = LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '990077', 'live_status' => 'active']);

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.toggle', $listing->id), ['action' => 'deactivate']);

        $this->assertSame('inactive', $listing->fresh()->live_status);
    }

    /** The selection bar's Deactivate / Activate (the owner, 2026-08-28): one call per listing, one summary. */
    public function test_bulk_toggle_deactivates_the_selection_and_names_what_it_skipped(): void
    {
        $a = $this->seedProduct('Bulk A', 'LZT-BA');
        $la = LazadaProduct::query()->create(['product_id' => $a, 'lazada_item_id' => '111', 'live_status' => 'active']);
        $b = $this->seedProduct('Bulk B', 'LZT-BB');
        $lb = LazadaProduct::query()->create(['product_id' => $b, 'lazada_item_id' => '222', 'live_status' => 'active']);
        $c = $this->seedProduct('Bulk C, not on Lazada', 'LZT-BC');
        $lc = LazadaProduct::query()->create(['product_id' => $c]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.lazada.products.bulk_toggle'), ['action' => 'deactivate', 'listing_ids' => [$la->id, $lb->id, $lc->id]]);

        $sent = collect($this->sentPosts)->where('path', '/product/deactivate');
        $this->assertCount(2, $sent, 'Lazada deactivates one ItemId per call');
        $ids = $sent->map(fn ($s) => (int) json_decode($s['params']['payload'], true)['Request']['Product']['ItemId'])->all();
        $this->assertEqualsCanonicalizing([111, 222], $ids);

        $r->assertSessionHas('status');
        $flash = (string) session('status');
        $this->assertStringContainsString('Deactivated 2 items on Lazada', $flash);
        $this->assertStringContainsString('1 skipped, not on Lazada', $flash);
        $this->assertSame('inactive', $la->fresh()->live_status);
        $this->assertSame('inactive', $lb->fresh()->live_status);
    }

    public function test_bulk_toggle_with_nothing_on_lazada_refuses_plainly(): void
    {
        $c = $this->seedProduct('Bulk C, not on Lazada', 'LZT-BC');
        $lc = LazadaProduct::query()->create(['product_id' => $c]);

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.products.bulk_toggle'), ['action' => 'activate', 'listing_ids' => [$lc->id]])
            ->assertSessionHas('error');
        $this->assertSame([], $this->sentPosts);
    }

    public function test_a_group_page_tells_the_products_truth_not_its_own_memory(): void
    {
        /* The one-truth port (2026-08-25), same disease the owner caught on
         * Shopee's GP-180: this group's push failed, then the product went
         * up elsewhere - the group page must read the LISTING record, with
         * the group's failed attempt named as exactly that. */
        $productId = $this->seedProduct('Truthful group product', 'LZT-TRUTH');
        $listing = LazadaProduct::query()->create([
            'product_id' => $productId, 'lazada_item_id' => '990077',
            'last_pushed_at' => now()->subMinutes(5), 'last_push_source' => 'listing',
        ]);
        $group = \Extensions\lazada\Models\LazadaProductGroup::create([
            'name' => 'Truth group', 'lazada_category_id' => 12345,
        ]);
        \Extensions\lazada\Models\LazadaProductGroupProduct::create([
            'lazada_product_group_id' => $group->id, 'product_id' => $productId,
            'lazada_product_id' => $listing->id,
            'sync_status' => 'error', 'push_error' => 'Special characters are not allowed.',
            'last_pushed_at' => now()->subMinutes(37),
        ]);

        $r = $this->actingAs($this->manager())
            ->get(route('ext.lazada.product-groups.products', $group->id));

        $r->assertOk();
        $r->assertSee('990077');
        $r->assertSee('Listed');
        $r->assertDontSee('>Failed<', false);
        $r->assertSee("This group's last push", false);
        $r->assertSee('Special characters');
        $r->assertSee('from its listing');
    }

    public function test_readiness_names_gaps_and_the_bulk_upload_refuses_to_start(): void
    {
        /* #101, Lazada half: the index answers "can this upload succeed"
         * before anything is pressed, and a bulk upload over an unready
         * selection refuses to start, naming what each needs. */
        $productId = $this->seedProduct('Unready lazada product', 'LZT-UNREADY');
        $listing = LazadaProduct::query()->create(['product_id' => $productId]);

        $r = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'));
        $r->assertOk();
        $r->assertSee('Missing:');
        $r->assertSee('a Lazada category');

        $r2 = $this->actingAs($this->manager())->post(route('ext.lazada.products.bulk_upload'), [
            'listing_ids' => [$listing->id],
        ]);
        $r2->assertSessionHas('error');
        $this->assertStringContainsString('Nothing was uploaded', session('error'));
        $this->assertStringContainsString("#{$productId} needs", session('error'));
    }

    public function test_the_toggle_speaks_lazadas_two_verbs(): void
    {
        $productId = $this->seedProduct('Toggle product', 'LZT-TOG');
        $listing = LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '990077']);

        // Deactivate: the documented endpoint, ItemId mandatory.
        $r = $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.toggle', $listing->id), ['action' => 'deactivate']);
        $sent = collect($this->sentPosts)->firstWhere('path', '/product/deactivate');
        $this->assertNotNull($sent, 'Deactivate must reach /product/deactivate.');
        $payload = json_decode($sent['params']['payload'], true);
        $this->assertSame(990077, (int) $payload['Request']['Product']['ItemId']);
        $r->assertSessionHas('status');

        // Activate without cached SKU ids: refused with the reason, nothing sent.
        $this->sentPosts = [];
        $r2 = $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.toggle', $listing->id), ['action' => 'activate']);
        $this->assertSame([], $this->sentPosts, 'Activate must not fire without SKU ids.');
        $r2->assertSessionHas('error');

        // Activate with a cached SKU id: /product/update, SKU-level Status active.
        \Extensions\lazada\Models\LazadaProductVariant::query()->create([
            'lazada_product_id' => $listing->id, 'seller_sku' => 'LZT-TOG', 'sku_id' => 555111,
        ]);
        $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.toggle', $listing->id), ['action' => 'activate']);
        $sent = collect($this->sentPosts)->firstWhere('path', '/product/update');
        $this->assertNotNull($sent, 'Activate must reach /product/update.');
        $payload = json_decode($sent['params']['payload'], true);
        $this->assertSame(555111, (int) $payload['Request']['Product']['Skus']['Sku'][0]['SkuId']);
        $this->assertSame('active', $payload['Request']['Product']['Skus']['Sku'][0]['Status']);
    }

    public function test_a_discount_rides_the_price_update_as_a_computed_sale_price(): void
    {
        /* Quest 2, #146: the rule (percent + window) is stored; the sale
         * price is computed at push time from the same final price a price
         * sync would send - Lazada's SalePrice/SaleStartDate/SaleEndDate
         * wire, the seller center's own "Edit Special Price". */
        $productId = $this->seedProduct('Discounted product', 'LZT-SALE');
        $listing = LazadaProduct::query()->create([
            'product_id' => $productId, 'lazada_item_id' => '990077',
            'sale_percent' => 20,   // sale price 88.00 off the 110.00 final
            'sale_starts_at' => now()->addDay(), 'sale_ends_at' => now()->addDays(8),
        ]);
        // markup fields are deliberately not mass-assignable
        $listing->forceFill(['markup_percent' => 10])->save(); // final price 110.00
        \Extensions\lazada\Models\LazadaProductVariant::query()->create([
            'lazada_product_id' => $listing->id, 'seller_sku' => 'LZT-SALE', 'sku_id' => 555222,
        ]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.push_discount', $listing->id), ['action' => 'push']);

        $sent = collect($this->sentPosts)->firstWhere('path', '/product/price_quantity/update');
        $this->assertNotNull($sent, 'The discount must ride the price update.');
        $xml = $sent['params']['payload'];
        $this->assertStringContainsString('<SkuId>555222</SkuId>', $xml);
        $this->assertStringContainsString('<Price>110.00</Price>', $xml);
        $this->assertStringContainsString('<SalePrice>88.00</SalePrice>', $xml);
        $this->assertStringContainsString('<SaleStartDate>' . now()->addDay()->format('Y-m-d'), $xml);
        $this->assertStringContainsString('<SaleEndDate>' . now()->addDays(8)->format('Y-m-d'), $xml);
        $r->assertSessionHas('status');

        // Ending sends SalePrice 0 and clears the rule.
        $this->sentPosts = [];
        $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.push_discount', $listing->id), ['action' => 'end']);
        $sent = collect($this->sentPosts)->firstWhere('path', '/product/price_quantity/update');
        $this->assertStringContainsString('<SalePrice>0</SalePrice>', $sent['params']['payload']);
        $this->assertNull($listing->fresh()->sale_percent, 'Ending clears the stored rule.');
    }

    public function test_a_discount_without_its_window_is_refused_with_the_reason(): void
    {
        $productId = $this->seedProduct('Ruleless product', 'LZT-NORULE');
        $listing = LazadaProduct::query()->create([
            'product_id' => $productId, 'lazada_item_id' => '990077',
        ]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.push_discount', $listing->id), ['action' => 'push']);

        $this->assertSame([], $this->sentPosts, 'Nothing may be sent without a saved rule.');
        $r->assertSessionHas('error');
        $this->assertStringContainsString('Set the discount first', session('error'));
    }

    public function test_the_edit_page_opens_with_the_items_live_state(): void
    {
        $productId = $this->seedProduct('Live edit product', 'LZT-LIVE');
        $listing = LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '990077']);

        $this->getResponses['/product/item/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
            'item_id' => 990077, 'status' => 'active', 'subStatus' => '',
            'attributes' => ['name' => 'Live edit product'],
            'skus' => [[
                'SellerSku' => 'LZT-LIVE', 'price' => 450.0, 'special_price' => 0,
                'quantity' => 7, 'Status' => 'active',
            ]],
        ]]];

        $r = $this->actingAs($this->manager())
            ->get(route('ext.lazada.products.edit', $listing->id));

        $r->assertOk();
        $r->assertSee('On Lazada right now');
        $r->assertSee('LZT-LIVE');
    }

    public function test_the_edit_page_says_so_when_lazada_does_not_answer(): void
    {
        $productId = $this->seedProduct('Unreachable product', 'LZT-DOWN');
        $listing = LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '990077']);
        // No /product/item/get response registered: the fake answers ok=false.

        $r = $this->actingAs($this->manager())
            ->get(route('ext.lazada.products.edit', $listing->id));

        $r->assertOk();
        $r->assertSee('Lazada did not answer');
    }
}
