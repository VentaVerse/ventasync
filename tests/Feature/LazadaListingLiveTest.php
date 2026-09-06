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

    /** Optional POST answers, keyed by api path; default stays the generic ok. */
    public array $postResponses = [];

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

                $answer = $this->test->postResponses[$apiPath] ?? null;
                if ($answer instanceof \Closure) {
                    return $answer($params);
                }

                return $answer ?? ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => []]];
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
        $page->assertSee('Save and push to Lazada');
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
        // Per-store Listings (#201): the page is this store's own table, with
        // Add products as the header's primary button (Draft C's slide-over)
        // - the "Your catalogue / On Lazada / All products" framing and the
        // two-tab treatment both retired.
        $r->assertSee('Add products');
        $r->assertSee('Pending QC');
        $r->assertSee('as of 08:30, Aug 28');
        // Icon-only refresh and Repair links on the strip, the Shopee shape
        // ported back (2026-09-05); the words live in the accessible name.
        $r->assertSee('aria-label="Refresh statuses from Lazada"', false);
        $r->assertSee('Repair links');
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
        // One latest error per product (the owner, 2026-09-05): the group page
        // draws the shared Last sync cell from the listing row; this group's
        // older attempt is not the product's story once the listing pushed.
        $r->assertSee('Last sync');
        $r->assertSee('Synced');
        $r->assertDontSee('Special characters');
    }

    /**
     * A group push builds with the group's category and attribute values
     * WITHOUT them ever touching the listing's rows (#104). The old
     * implementation overwrote the rows and restored them after the build;
     * a crash between the two corrupted the listing permanently. Now the
     * overrides ride the builder in memory: the wire carries the group's
     * values, the database keeps the listing's own.
     */
    public function test_a_group_push_carries_group_values_without_writing_them(): void
    {
        $productId = $this->seedProduct('Group-dressed product', 'GRP-DRESS');
        $pfx = (string) config('catalog.prefix');
        DB::table($pfx . 'product')->where('product_id', $productId)->update(['image' => 'catalog/x.png']);

        $listing = LazadaProduct::query()->create([
            'product_id' => $productId, 'primary_category_id' => 111,
        ]);
        // markup_fixed is deliberately not mass-assignable; seed it directly.
        $listing->forceFill(['markup_fixed' => 5])->save();
        \Extensions\lazada\Models\LazadaProductAttribute::create([
            'lazada_product_id' => $listing->id, 'attribute_key' => 'material', 'value' => 'Maple',
        ]);

        $group = \Extensions\lazada\Models\LazadaProductGroup::create([
            'name' => 'Dressing group', 'lazada_category_id' => 222,
            'markup_fixed' => 20,
        ]);
        \Extensions\lazada\Models\LazadaProductGroupProduct::create([
            'lazada_product_group_id' => $group->id, 'product_id' => $productId,
            'lazada_product_id' => $listing->id, 'sync_status' => 'pending',
        ]);
        \Extensions\lazada\Models\LazadaProductGroupAttribute::create([
            'lazada_product_group_id' => $group->id, 'attribute_key' => 'material', 'value' => 'Rosewood',
        ]);
        // The GROUP's category has a cached template with no questions, so
        // the mandatory gate passes and the build reaches the wire.
        \Extensions\lazada\Models\LazadaCategoryTemplate::create([
            'region' => 'ph', 'primary_category_id' => 222,
            'template_body' => ['data' => ['attributes' => []]], 'fetched_at' => now(),
        ]);

        // The image inlink migration gets a real-shaped answer, so the
        // push reaches /product/create.
        $this->postResponses['/image/migrate'] = ['ok' => true, 'status' => 200, 'body' => [
            'code' => '0', 'data' => ['image' => ['url' => 'https://sg-live.slatic.net/p/fake.jpg']],
        ]];

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.product-groups.push', $group->id), ['ids' => [$productId]])
            ->assertRedirect();

        $create = collect($this->sentPosts)->firstWhere('path', '/product/create');
        $this->assertNotNull($create, 'the push must reach /product/create');
        $payload = (string) ($create['params']['payload'] ?? '');
        $this->assertStringContainsString('Rosewood', $payload, "the wire carries the group's value");
        $this->assertStringContainsString('222', $payload, "the wire carries the group's category");

        $listing->refresh();
        $this->assertSame(111, (int) $listing->primary_category_id, "the listing's own category is untouched");
        $this->assertSame(5.0, (float) $listing->markup_fixed);
        $this->assertSame('Maple', \Extensions\lazada\Models\LazadaProductAttribute::query()
            ->where('lazada_product_id', $listing->id)->where('attribute_key', 'material')->value('value'),
            "the listing's own attribute value is untouched");
    }

    public function test_readiness_names_gaps_on_the_index(): void
    {
        /* #101, Lazada half: the index answers "can this upload succeed"
         * before anything is pressed, and a bulk upload over an unready
         * selection refuses to start, naming what each needs. */
        $productId = $this->seedProduct('Unready lazada product', 'LZT-UNREADY');
        $listing = LazadaProduct::query()->create(['product_id' => $productId]);
        // The store has fetched its categories: the gap is this listing's own (with none fetched the upload stops earlier, StoreSetupTest).
        \Extensions\lazada\Models\LazadaCategory::create(['category_id' => 222, 'name' => 'Pedals', 'leaf' => true, 'var' => false, 'parent_id' => null, 'level' => 0]);

        $r = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'));
        $r->assertOk();
        $r->assertSee('Missing:');
        $r->assertSee('a Lazada category');
        // No multi-tick push on the Listings page (the owner, 2026-09-06): many
        // products go up from a product group's page.
        $r->assertDontSee('bulk/upload');
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

        // Activate: /product/update, carrying the attributes and the SKUs
        // Lazada itself just returned. It no longer depends on cached
        // variant rows, so a listing whose variants were never cached is
        // still switchable; Lazada is the one that names its SKUs.
        $this->sentPosts = [];
        $this->getResponses['/product/item/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
            'item_id' => 990077,
            'attributes' => ['name' => 'Toggle me', 'brand' => 'No Brand'],
            'skus' => [['SkuId' => 555111, 'SellerSku' => 'LZT-TOG', 'quantity' => 3, 'price' => 110]],
        ]]];

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.toggle', $listing->id), ['action' => 'activate']);
        $sent = collect($this->sentPosts)->firstWhere('path', '/product/update');
        $this->assertNotNull($sent, 'Activate must reach /product/update.');
        $payload = json_decode($sent['params']['payload'], true);
        $this->assertSame('Toggle me', $payload['Request']['Product']['Attributes']['name'], 'Attributes travel or Lazada answers E001');
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
        // And what it read is written to the mirror, so the lists agree at once.
        $this->assertSame('active', LazadaProduct::query()->where('lazada_item_id', '990077')->value('live_status'));
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

    /**
     * Reactivating a live listing (the owner, live, 2026-09-01). /product/update
     * is not a patch endpoint: it validates the Product node it is given, so
     * a status-only payload came back
     *
     *     E001: Parameter Attributes is mandatory
     *
     * on a product that was already uploaded and merely switched off. The
     * attributes now travel with the call, read back from Lazada rather than
     * rebuilt from the ERP, so pressing Activate changes the status and
     * nothing else.
     */
    public function test_activate_carries_the_listings_own_attributes_back_to_lazada(): void
    {
        $productId = $this->seedProduct('Valeton GP-200', 'GP200');
        $listing = LazadaProduct::query()->create([
            'product_id' => $productId, 'lazada_item_id' => '1830026816', 'live_status' => 'inactive',
        ]);

        $this->getResponses['/product/item/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
            'item_id' => 1830026816,
            'attributes' => [
                'name' => 'Valeton GP-200 as Lazada holds it',
                'description' => '<p>Live copy</p>',
                'brand' => 'Valeton',
                'warranty_type' => 'Local supplier warranty',
                'short_description' => '',
            ],
            'skus' => [[
                'SkuId' => 7785126563, 'SellerSku' => 'GP200-BLACK',
                'quantity' => 7, 'price' => 21990,
                'Url' => 'https://www.lazada.com.ph/products/x.html', 'ShopSku' => '123_PH_456',
            ]],
        ]]];

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.toggle', $listing->id), ['action' => 'activate'])
            ->assertRedirect();

        $update = collect($this->sentPosts)->firstWhere('path', '/product/update');
        $this->assertNotNull($update, 'activate goes through /product/update');

        $payload = json_decode($update['params']['payload'], true);
        $product = $payload['Request']['Product'];

        // The thing the marketplace refused without.
        $this->assertArrayHasKey('Attributes', $product);
        $this->assertSame('Valeton GP-200 as Lazada holds it', $product['Attributes']['name']);
        $this->assertSame('Valeton', $product['Attributes']['brand']);
        $this->assertArrayNotHasKey('short_description', $product['Attributes'], 'empty values are not sent back');

        // Exactly one thing changes, and price and stock ride along untouched
        // so a status flip cannot blank them.
        $sku = $product['Skus']['Sku'][0];
        $this->assertSame('active', $sku['Status']);
        $this->assertSame(7785126563, $sku['SkuId']);
        $this->assertSame('GP200-BLACK', $sku['SellerSku']);
        $this->assertSame(7, $sku['Quantity']);
        $this->assertSame(21990, $sku['price']);
        $this->assertArrayNotHasKey('Url', $sku, 'read-only fields are not sent back');
        $this->assertArrayNotHasKey('ShopSku', $sku);

        $this->assertSame('active', $listing->fresh()->live_status);
    }

    public function test_activate_refuses_rather_than_sending_a_product_lazada_did_not_confirm(): void
    {
        $productId = $this->seedProduct('Ghost', 'GHOST');
        $listing = LazadaProduct::query()->create([
            'product_id' => $productId, 'lazada_item_id' => '999', 'live_status' => 'inactive',
        ]);

        $this->getResponses['/product/item/get'] = ['ok' => true, 'status' => 200, 'body' => [
            'code' => '0', 'data' => ['item_id' => 999, 'attributes' => [], 'skus' => []],
        ]];

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.toggle', $listing->id), ['action' => 'activate'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull(collect($this->sentPosts)->firstWhere('path', '/product/update'), 'nothing is sent when the read came back empty');
        $this->assertSame('inactive', $listing->fresh()->live_status, 'the mirror is not moved on a refusal');
    }

    /** Deactivate is a real endpoint and stays a one-field call. */
    public function test_deactivate_still_sends_only_the_item_id(): void
    {
        $productId = $this->seedProduct('Valeton GP-5', 'GP5');
        $listing = LazadaProduct::query()->create([
            'product_id' => $productId, 'lazada_item_id' => '222', 'live_status' => 'active',
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.toggle', $listing->id), ['action' => 'deactivate'])
            ->assertRedirect();

        $call = collect($this->sentPosts)->firstWhere('path', '/product/deactivate');
        $this->assertNotNull($call);
        $this->assertSame(
            ['Request' => ['Product' => ['ItemId' => 222]]],
            json_decode($call['params']['payload'], true)
        );
        $this->assertSame('inactive', $listing->fresh()->live_status);
    }
}
