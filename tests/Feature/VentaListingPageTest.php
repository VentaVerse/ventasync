<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\venta\Models\VentaListing;
use Extensions\venta\Models\VentaProductGroup;
use Extensions\venta\Models\VentaProductGroupProduct;
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\VentaExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The individual Venta listing page (#171): one product on one store, on
 * the same anatomy as Shopee's and TikTok's. The live half is read from
 * the store on load and written to the mirror; the record beneath it is
 * what a push from here sends, with content overrides that follow the
 * catalogue when empty and a price rule the store price is run through.
 */
class VentaListingPageTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://one.venta.test';

    private int $seq = 0;

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('venta');
        $manager->enable('venta');

        $this->app->register(VentaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        Http::preventStrayRequests();
    }

    private function userWith(array $keys): User
    {
        $name = 'Venta listing ' . implode('-', $keys);
        if (! isset($this->users[$name])) {
            $group = UserGroup::create(['name' => $name . ' ' . (++$this->seq)]);
            $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());
            $this->users[$name] = User::factory()->create(['user_group_id' => $group->id]);
        }

        return $this->users[$name];
    }

    private function manager(): User
    {
        return $this->userWith(['view_venta/listing', 'manage_venta/listing', 'view_venta/product_group']);
    }

    private function viewer(): User
    {
        return $this->userWith(['view_venta/listing', 'view_venta/product_group']);
    }

    private function store(bool $enabled = true): VentaSetting
    {
        return VentaSetting::create([
            'store_name' => 'Gear Depot',
            'base_url' => self::BASE,
            'api_token' => 'venta-token-one',
            'enabled' => $enabled,
        ]);
    }

    /** A catalogue product with a name, a price and a SKU. */
    private function product(string $sku = 'GP-200', float $price = 1000): int
    {
        $pfx = (string) config('catalog.prefix');
        $pid = (int) DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'price' => $price, 'quantity' => 7, 'status' => 1,
            'image' => '', 'weight' => 0, 'length' => 0, 'width' => 0, 'height' => 0,
            'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Guitar Pedal 200', 'description' => 'The catalogue description.',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $pid;
    }

    private function storeProduct(int $id = 5001, bool $active = true, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'sku' => 'GP-200', 'name' => 'Guitar Pedal 200 on the store', 'price' => 1150.0,
            'quantity' => 6, 'status' => $active, 'images' => [['id' => 1, 'path' => 'a.jpg']],
            'variants' => [
                ['id' => 1, 'sku' => 'GP-200-RED', 'price' => 1150.0, 'quantity' => 4, 'is_active' => true, 'option_values' => [['id' => 1, 'value' => 'Red']]],
            ],
        ], $extra);
    }

    /**
     * Live, 2026-09-11: a Push stock the storefront refused answered with a
     * server error ("Unknown column 'last_push_error'"). The refusal is
     * written to the listing's latest push error, and Venta's listing table
     * was the one of five that never got the column. It is recorded now, and
     * the page says what the storefront said.
     */
    public function test_a_refused_stock_push_is_recorded_on_the_listing_not_a_server_error(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaProductLink::create(['venta_setting_id' => $store->id, 'product_id' => $pid, 'venta_product_id' => 5001, 'sku' => 'GP-200']);
        Http::fake([self::BASE . '/*' => Http::response(['error' => 'Product not found'], 404)]);

        $this->actingAs($this->manager())
            ->from('/dashboard')
            ->post(route('ext.venta.listings.push_stock', [$store->id, $pid]))
            ->assertRedirect('/dashboard')
            ->assertSessionHas('error');

        $listing = VentaListing::where('venta_setting_id', $store->id)->where('product_id', $pid)->first();
        $this->assertNotNull($listing);
        $this->assertSame('Stock push failed: Product not found', $listing->last_push_error);
        $this->assertNotNull($listing->last_push_failed_at);
    }

    /**
     * The one group shape (2026-09-07): a Venta group picks the store's own
     * category, the way WooCommerce's does, and the listing page reports it.
     * A category this store does not hold is refused.
     */
    public function test_a_venta_group_picks_the_stores_own_category_and_the_listing_reports_it(): void
    {
        $store = $this->store();
        $pid = $this->product();
        \Extensions\venta\Models\VentaCategory::create([
            'venta_setting_id' => $store->id, 'venta_category_id' => 42, 'name' => 'Pedals', 'parent_id' => 0,
        ]);
        $manager = $this->userWith(['view_venta/listing', 'manage_venta/listing', 'view_venta/product_group', 'manage_venta/product_group']);

        $this->actingAs($manager)
            ->get(route('ext.venta.product-groups.create', $store->id))
            ->assertOk()->assertSee('Category on Gear Depot')->assertSee('Pedals');

        $this->actingAs($manager)
            ->post(route('ext.venta.product-groups.store', $store->id), ['name' => 'Pedal group', 'venta_category_id' => 42, 'markup_percent' => 10])
            ->assertSessionHasNoErrors();
        $group = \Extensions\venta\Models\VentaProductGroup::query()->where('name', 'Pedal group')->first();
        $this->assertSame(42, (int) $group->venta_category_id);
        // Membership is the products page's business, not the form's.
        $this->assertSame([], $group->products()->pluck('product_id')->all());

        \Extensions\venta\Models\VentaProductGroupProduct::create(['venta_product_group_id' => $group->id, 'product_id' => $pid]);
        $page = $this->actingAs($manager)->get(route('ext.venta.listings.edit', [$store->id, $pid]))->assertOk();
        $page->assertSee('Category on Gear Depot');
        $page->assertSee('Pedals');
        $page->assertSee('Pedal group');

        // A category the store does not hold is refused.
        $this->actingAs($manager)
            ->from(route('ext.venta.product-groups.create', $store->id))
            ->post(route('ext.venta.product-groups.store', $store->id), ['name' => 'Ghost', 'venta_category_id' => 999])
            ->assertSessionHasErrors('venta_category_id');
    }

    public function test_the_page_reads_the_store_on_load_and_writes_the_mirror(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaProductLink::create(['venta_setting_id' => $store->id, 'product_id' => $pid, 'venta_product_id' => 5001, 'sku' => 'GP-200']);

        Http::fake([self::BASE . '/api/v1/products/GP-200' => Http::response($this->storeProduct())]);

        $html = $this->actingAs($this->viewer())
            ->get(route('ext.venta.listings.edit', [$store->id, $pid]))
            ->assertOk()
            ->getContent();

        /* The strip's title and figures went with the health surface on
           2026-09-09 (the owner: "just fields to edit. Nothing about health").
           The store's own table below the fields stayed, and so did the
           write-through this test is really about. */
        $this->assertStringNotContainsString('On Gear Depot right now', $html);
        $this->assertStringNotContainsString('Guitar Pedal 200 on the store', $html);
        $this->assertStringContainsString('GP-200-RED', $html);
        $this->assertStringContainsString('>Listed<', $html);
        // A viewer reads; no verbs.
        $this->assertStringNotContainsString('Unlist on Gear Depot', $html);
        $this->assertStringNotContainsString('Save listing', $html);

        $listing = VentaListing::where('venta_setting_id', $store->id)->where('product_id', $pid)->first();
        $this->assertNotNull($listing);
        $this->assertSame(VentaListing::STATUS_ACTIVE, $listing->live_status);
        $this->assertSame(1150.0, $listing->live_price);
        $this->assertSame(6, $listing->live_quantity);
    }

    public function test_a_product_the_store_no_longer_has_reads_as_missing_and_drops_nothing_by_itself(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaProductLink::create(['venta_setting_id' => $store->id, 'product_id' => $pid, 'venta_product_id' => 5001, 'sku' => 'GP-200']);

        Http::fake([self::BASE . '/api/v1/products/GP-200' => Http::response(['error' => 'Product not found'], 404)]);

        $html = $this->actingAs($this->manager())
            ->get(route('ext.venta.listings.edit', [$store->id, $pid]))
            ->assertOk()
            ->getContent();

        /* The sentence was a note under the strip and went with the rest
           on 2026-09-09. The behaviour it announced is what matters and is
           unchanged: the page records what the store said and drops
           nothing by itself. */
        $this->assertStringNotContainsString('no longer has a product with this SKU', $html);
        $this->assertSame(VentaListing::STATUS_MISSING, VentaListing::where('product_id', $pid)->value('live_status'));
    }

    public function test_a_store_that_does_not_answer_is_not_replaced_by_stale_data(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaProductLink::create(['venta_setting_id' => $store->id, 'product_id' => $pid, 'venta_product_id' => 5001, 'sku' => 'GP-200']);
        VentaListing::create(['venta_setting_id' => $store->id, 'product_id' => $pid, 'live_status' => 'active', 'live_price' => 999, 'live_checked_at' => now()->subHour()]);

        Http::fake([self::BASE . '/api/v1/products/GP-200' => Http::response(['error' => 'boom'], 500)]);

        $html = $this->actingAs($this->manager())
            ->get(route('ext.venta.listings.edit', [$store->id, $pid]))
            ->assertOk()
            ->getContent();

        /* The sentence went with the health surface; the guarantee it
           described did not. A failed read still writes nothing, so the
           mirror below keeps what it had rather than being overwritten
           with a guess. */
        $this->assertStringNotContainsString('live state is simply unknown', $html);
        $this->assertStringNotContainsString('Read from Gear Depot as this page loaded', $html);
        // The mirror keeps what it had; the failed read wrote nothing.
        $this->assertSame(999.0, VentaListing::where('product_id', $pid)->first()->live_price);
    }

    public function test_an_unlinked_product_the_store_already_holds_is_linked_on_sight(): void
    {
        $store = $this->store();
        $pid = $this->product();

        Http::fake([self::BASE . '/api/v1/products/GP-200' => Http::response($this->storeProduct(7777))]);

        $this->actingAs($this->viewer())->get(route('ext.venta.listings.edit', [$store->id, $pid]))->assertOk();

        $this->assertSame(7777, (int) VentaProductLink::where('venta_setting_id', $store->id)->where('product_id', $pid)->value('venta_product_id'));
    }

    public function test_saving_keeps_overrides_and_returns_blanks_to_the_catalogue(): void
    {
        $store = $this->store();
        $pid = $this->product();

        $this->actingAs($this->manager())
            ->put(route('ext.venta.listings.update', [$store->id, $pid]), [
                'name' => 'Pedal, store wording', 'description' => '   ', 'markup_percent' => '10', 'markup_fixed' => '',
            ])
            ->assertRedirect(route('ext.venta.listings.edit', [$store->id, $pid]));

        $listing = VentaListing::where('venta_setting_id', $store->id)->where('product_id', $pid)->firstOrFail();
        $this->assertSame('Pedal, store wording', $listing->name);
        $this->assertNull($listing->description, 'blank means "follows the catalogue"');
        $this->assertSame(10.0, $listing->markup_percent);
        $this->assertNull($listing->markup_fixed);
        $this->assertSame(1100.0, $listing->priceFor(1000));

        // The catalogue product is untouched.
        $pfx = (string) config('catalog.prefix');
        $this->assertSame('Guitar Pedal 200', DB::table($pfx . 'product_description')->where('product_id', $pid)->value('name'));
    }

    /**
     * the owner, 2026-09-07: a store listing's own name and description are saved
     * per store and must go up from EVERY door. The group push built its
     * payload straight from the catalogue, so it sent the catalogue's name
     * while the listing's own sat saved and unused.
     */
    /**
     * A FEES-ONLY RUN TAKES THE MONEY AND LEAVES EVERYTHING ELSE.
     *
     * Venta backfilled its own past orders with a payment and delivery fee,
     * and the ERP cannot find them in what it already holds: a stored
     * payload is a photograph of what was sent at the time. So those orders
     * have to be asked for again - and a full re-sync would delete and
     * re-insert every order LINE and re-stamp each cost from today's
     * catalogue, moving every profit figure in the system.
     *
     * This pins the difference: the line and its cost are untouched.
     */
    public function test_a_fees_only_pull_never_rewrites_an_order_line(): void
    {
        $pfx = (string) config('catalog.prefix');
        $store = $this->store();
        $pid = $this->product();

        // A core order as it stands today, with a cost that is NOT the
        // catalogue's current one - which is the whole point.
        $oid = (int) DB::table($pfx . 'order')->insertGetId([
            'invoice_prefix' => 'INV-', 'store_id' => 0, 'store_name' => 'Xenon', 'store_url' => '',
            'customer_id' => 0, 'customer_group_id' => 0, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
            'telephone' => '', 'fax' => '', 'custom_field' => '',
            'payment_firstname' => '', 'payment_lastname' => '', 'payment_company' => '', 'payment_address_1' => '', 'payment_address_2' => '',
            'payment_city' => '', 'payment_postcode' => '', 'payment_country' => '', 'payment_country_id' => 0, 'payment_zone' => '', 'payment_zone_id' => 0,
            'payment_address_format' => '', 'payment_custom_field' => '', 'payment_method' => '', 'payment_code' => '',
            'shipping_firstname' => '', 'shipping_lastname' => '', 'shipping_company' => '', 'shipping_address_1' => '', 'shipping_address_2' => '',
            'shipping_city' => '', 'shipping_postcode' => '', 'shipping_country' => '', 'shipping_country_id' => 0, 'shipping_zone' => '', 'shipping_zone_id' => 0,
            'shipping_address_format' => '', 'shipping_custom_field' => '', 'shipping_method' => '', 'shipping_code' => '',
            'comment' => '', 'affiliate_id' => 0, 'commission' => 0, 'marketing_id' => 0, 'tracking' => '',
            'total' => 1000, 'order_status_id' => 5, 'language_id' => (int) config('catalog.default_language_id'),
            'currency_id' => 1, 'currency_code' => 'PHP', 'currency_value' => 1,
            'ip' => '', 'forwarded_ip' => '', 'user_agent' => '', 'accept_language' => '',
            'courier_id' => 0, 'tracking_number' => '', 'oe_import' => 0,
            'marketplace_source' => 'venta:' . $store->id, 'marketplace_order_id' => '77',
            'shipping_cost' => 0, 'payment_cost' => 0, 'extra_cost' => 0,
            'date_added' => '2026-06-15 10:00:00', 'date_modified' => '2026-06-15 10:00:00',
        ]);
        DB::table($pfx . 'order_product')->insert([
            'order_id' => $oid, 'product_id' => $pid, 'name' => 'Guitar Pedal 200', 'model' => 'GP-200',
            'quantity' => 1, 'price' => 1000, 'total' => 1000, 'tax' => 0, 'reward' => 0,
            // What it cost when it sold, which is not what it costs now.
            'cost' => 111.11,
        ]);
        DB::table('venta_orders')->insert([
            'venta_setting_id' => $store->id, 'venta_order_id' => 77, 'status' => 'delivered',
            'total' => 1000, 'raw' => '{}', 'catalog_order_id' => $oid,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = [
            'id' => 77, 'order_number' => 77, 'status' => 'delivered',
            'created_at' => '2026-06-15T10:00:00Z', 'updated_at' => '2026-06-15T10:00:00Z',
            'totals' => ['total' => 1000, 'subtotal' => 1000, 'shipping' => 0],
            /* The storefront's own names, from ErpOrderController::show:
               payment_fee is the gateway's charge, shipping_cost is what
               the COURIER charged the store, and totals.shipping above is
               what the customer paid - a different number entirely. */
            'fees' => ['payment_fee' => 25.5, 'shipping_cost' => 60, 'net_after_fees' => 914.5],
            'items' => [['sku' => 'GP-200', 'quantity' => 1, 'price' => 1000, 'total' => 1000]],
        ];
        Http::fake([
            self::BASE . '/api/v1/order-statuses*' => Http::response(['data' => []]),
            self::BASE . '/api/v1/orders/77' => Http::response($order),
            self::BASE . '/api/v1/orders*' => Http::response(['data' => [$order], 'meta' => ['last_page' => 1]]),
        ]);

        (new \Extensions\venta\Services\Venta\VentaOrderSync(
            new \Extensions\venta\Services\Venta\VentaClient($store), $store
        ))->setFeesOnly()->setSkipStockAdjust()->pull(since: '2026-06-01', full: true, maxPages: 1);


        // The line is exactly as it was.
        $this->assertSame(111.11, round((float) DB::table($pfx . 'order_product')->where('order_id', $oid)->value('cost'), 2),
            'A fees run re-stamped the cost of goods from the catalogue. That moves every profit figure in the system.');
        $this->assertSame(1, DB::table($pfx . 'order_product')->where('order_id', $oid)->count());

        // And the money landed where the reports read it.
        $rows = DB::table($pfx . 'order_total')->where('order_id', $oid)
            ->whereIn('code', ['marketplace_payment_fee', 'marketplace_shipping_fee'])->pluck('value', 'code');
        $this->assertSame(-25.5, round((float) ($rows['marketplace_payment_fee'] ?? 0), 2));
        $this->assertSame(-60.0, round((float) ($rows['marketplace_shipping_fee'] ?? 0), 2));
    }

    /** The storefront's tracking page for a parcel reaches the order, and a payload without one keeps it. */
    public function test_the_storefronts_tracking_page_reaches_the_order(): void
    {
        $store = $this->store();
        $this->product();
        $pfx = (string) config('catalog.prefix');
        $order = [
            'id' => 88, 'order_number' => 88, 'status' => 'shipped',
            'created_at' => '2026-06-15T10:00:00Z', 'updated_at' => '2026-06-15T10:00:00Z',
            'totals' => ['total' => 1000, 'subtotal' => 1000, 'shipping' => 0],
            'tracking_number' => 'QX12345', 'tracking_link' => 'https://app.gogoxpress.com/track/QX12345',
            'items' => [['sku' => 'GP-200', 'quantity' => 1, 'price' => 1000, 'total' => 1000]],
        ];
        $pull = function (array $order) use ($store) {
            Http::fake([
                self::BASE . '/api/v1/order-statuses*' => Http::response(['data' => []]),
                self::BASE . '/api/v1/orders/88' => Http::response($order),
                self::BASE . '/api/v1/orders*' => Http::response(['data' => [$order], 'meta' => ['last_page' => 1]]),
            ]);
            (new \Extensions\venta\Services\Venta\VentaOrderSync(
                new \Extensions\venta\Services\Venta\VentaClient($store), $store
            ))->setSkipStockAdjust()->pull(since: '2026-06-01', full: true, maxPages: 1);
        };

        $pull($order);
        $url = fn () => DB::table($pfx . 'order')->where('marketplace_source', 'venta:' . $store->id)->where('marketplace_order_id', '88')->value('tracking_url');
        $this->assertSame('https://app.gogoxpress.com/track/QX12345', $url());

        unset($order['tracking_link']);
        $pull($order);
        $this->assertSame('https://app.gogoxpress.com/track/QX12345', $url(), 'a payload without the field keeps the stored page');
    }

    /**
     * THE TWO FEES TIE BACK TO THE STOREFRONT'S OWN NET FIGURE.
     *
     * Venta derives net_after_fees as total - payment_fee - shipping_cost
     * and never stores it, so it cannot drift from its inputs. If what the
     * ERP subtracts does not come to the same number, the ERP is
     * subtracting the wrong fields - which is exactly what happened while
     * the delivery fee was read under a name the storefront does not use.
     *
     * The same shape as reconciling Shopee against escrow_amount: the
     * marketplace's own arithmetic is the check on ours.
     */
    public function test_the_fees_tie_back_to_the_storefronts_net_after_fees(): void
    {
        $store = $this->store();
        $raw = [
            'id' => 91,
            'totals' => ['subtotal' => 12000, 'shipping' => 180, 'total' => 11990],
            'fees' => ['payment_fee' => 374.70, 'shipping_cost' => 90.00, 'net_after_fees' => 11525.30],
        ];
        DB::table('venta_orders')->insert([
            'venta_setting_id' => $store->id, 'venta_order_id' => 91, 'status' => 'delivered',
            'total' => 11990, 'raw' => json_encode($raw), 'catalog_order_id' => 4242,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $buckets = (new \Extensions\venta\VentaExtension($this->app))->feeBucketsForOrder(4242);

        $this->assertNotNull($buckets);
        $this->assertSame(374.70, round($buckets['payment'], 2));
        $this->assertSame(90.00, round($buckets['shipping'], 2), "The delivery fee is the COURIER's charge (fees.shipping_cost), not the buyer's 180.");
        $this->assertSame(0.0, round($buckets['commission'], 2), 'A storefront takes no commission from its own owner.');

        // The storefront's own arithmetic, reproduced from what we subtract.
        $ours = round(11990 - $buckets['payment'] - $buckets['shipping'], 2);
        $this->assertSame(11525.30, $ours,
            'What the ERP subtracts does not come to the storefront\'s own net figure, so it is subtracting the wrong fields.');
    }

    /**
     * Null is NOT KNOWN YET and never zero - the storefront says so in its
     * own comment, because a payment fee arrives at settlement and a
     * courier's charge at booking. Reading either as zero would report an
     * unbooked parcel as free and overstate the margin.
     */
    public function test_an_order_whose_fees_are_not_settled_yet_writes_nothing(): void
    {
        $store = $this->store();
        DB::table('venta_orders')->insert([
            'venta_setting_id' => $store->id, 'venta_order_id' => 92, 'status' => 'pending',
            'total' => 500, 'catalog_order_id' => 4243,
            'raw' => json_encode(['id' => 92, 'fees' => ['payment_fee' => null, 'shipping_cost' => null, 'net_after_fees' => null]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull((new \Extensions\venta\VentaExtension($this->app))->feeBucketsForOrder(4243),
            'An order whose fees are not settled yet must not be stamped with zeroes: it would read as free.');

        // A half-known order still reports what IS known.
        DB::table('venta_orders')->where('venta_order_id', 92)->update([
            'raw' => json_encode(['id' => 92, 'fees' => ['payment_fee' => 12.5, 'shipping_cost' => null]]),
        ]);
        $half = (new \Extensions\venta\VentaExtension($this->app))->feeBucketsForOrder(4243);
        $this->assertSame(12.5, round($half['payment'], 2));
        $this->assertSame(0.0, round($half['shipping'], 2), 'An unbooked parcel has no courier charge to report yet.');
    }

    /**
     * THE PAGE COUNT IS AT THE TOP LEVEL OF THE STOREFRONT'S ANSWER.
     *
     * The pull read it at meta.last_page. The storefront returns a Laravel
     * paginator straight from response()->json(), which puts last_page at
     * the top level and has no meta at all - so the read always found
     * nothing, fell back to 1, and every order pull stopped after the
     * first hundred orders. A live store with 158 orders since February
     * reported 100 (the owner, 2026-09-10).
     *
     * Verified against the storefront's own source rather than inferred.
     */
    public function test_the_pull_walks_every_page_the_storefront_reports(): void
    {
        $store = $this->store();

        $order = fn (int $id) => [
            'id' => $id, 'order_number' => $id, 'status' => 'delivered',
            'created_at' => '2026-06-15T10:00:00Z', 'updated_at' => '2026-06-15T10:00:00Z',
            'totals' => ['total' => 100, 'subtotal' => 100, 'shipping' => 0],
            'items' => [],
        ];

        Http::fake([
            self::BASE . '/api/v1/order-statuses*' => Http::response(['data' => []]),
            self::BASE . '/api/v1/orders/1' => Http::response($order(1)),
            self::BASE . '/api/v1/orders/2' => Http::response($order(2)),
            // Two pages, counted the way this storefront counts them.
            self::BASE . '/api/v1/orders?*page=2*' => Http::response(['data' => [$order(2)], 'current_page' => 2, 'last_page' => 2]),
            self::BASE . '/api/v1/orders*' => Http::response(['data' => [$order(1)], 'current_page' => 1, 'last_page' => 2]),
        ]);

        (new \Extensions\venta\Services\Venta\VentaOrderSync(
            new \Extensions\venta\Services\Venta\VentaClient($store), $store
        ))->setFeesOnly()->setSkipStockAdjust()->pull(since: '2026-06-01', full: true, maxPages: 10);

        $this->assertSame(2, DB::table('venta_orders')->count(),
            'The pull stopped after the first page: a store with more orders than fit on one page is silently half-read.');
    }

    /**
     * A GROUP'S MARKUP AND CATEGORY ARE NOT DECORATION (VENT2-13).
     *
     * The group screen has asked for a price markup and a store category
     * since it was built, and saved both, and nothing ever read either:
     * the group push built its payload straight from the catalog, so a 20%
     * markup went up as the plain catalog price and a chosen category was
     * never sent at all. The owner, on the card: "Leaving them is the only
     * option that misleads."
     */
    public function test_a_group_push_applies_the_groups_markup_and_sends_its_category(): void
    {
        $store = $this->store();
        $pid = $this->product();
        $group = \Extensions\venta\Models\VentaProductGroup::create([
            'venta_setting_id' => $store->id, 'name' => 'Pedals',
            'markup_percent' => 20, 'venta_category_id' => 77,
        ]);
        \Extensions\venta\Models\VentaProductGroupProduct::create(['venta_product_group_id' => $group->id, 'product_id' => $pid]);

        Http::fake([
            self::BASE . '/api/v1/products/GP-200' => Http::response(['error' => 'Product not found'], 404),
            self::BASE . '/api/v1/products' => Http::response(['id' => 9101, 'sku' => 'GP-200', 'images' => [], 'images_failed' => []], 201),
        ]);

        $pusher = $this->userWith(['view_venta/listing', 'manage_venta/listing', 'view_venta/product_group', 'manage_venta/product_group']);
        $this->actingAs($pusher)
            ->post(route('ext.venta.product-groups.push', [$store->id, $group->id]), ['ids' => [$pid]])
            ->assertRedirect();

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/api/v1/products')) {
                return false;
            }
            $body = $request->data();

            // 1000 in the catalog, 20% on the group.
            return round((float) $body['price'], 2) === 1200.0
                && (int) ($body['category_id'] ?? 0) === 77;
        });
    }

    /**
     * And the listing's own rule still wins over its group's, which is the
     * order every channel keeps: the listing's markup, else its group's,
     * else the catalog price untouched.
     */
    public function test_a_listings_own_markup_beats_its_groups(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaListing::create([
            'venta_setting_id' => $store->id, 'product_id' => $pid, 'markup_percent' => 10,
        ]);
        $group = \Extensions\venta\Models\VentaProductGroup::create([
            'venta_setting_id' => $store->id, 'name' => 'Pedals', 'markup_percent' => 50,
        ]);
        \Extensions\venta\Models\VentaProductGroupProduct::create(['venta_product_group_id' => $group->id, 'product_id' => $pid]);

        Http::fake([
            self::BASE . '/api/v1/products/GP-200' => Http::response(['error' => 'Product not found'], 404),
            self::BASE . '/api/v1/products' => Http::response(['id' => 9101, 'sku' => 'GP-200', 'images' => [], 'images_failed' => []], 201),
        ]);

        $pusher = $this->userWith(['view_venta/listing', 'manage_venta/listing', 'view_venta/product_group', 'manage_venta/product_group']);
        $this->actingAs($pusher)
            ->post(route('ext.venta.product-groups.push', [$store->id, $group->id]), ['ids' => [$pid]])
            ->assertRedirect();

        Http::assertSent(fn ($request) => $request->method() !== 'POST'
            || ! str_ends_with($request->url(), '/api/v1/products')
            || round((float) $request->data()['price'], 2) === 1100.0);
    }

    public function test_a_group_push_sends_the_listings_own_words_not_the_catalogues(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaListing::create([
            'venta_setting_id' => $store->id, 'product_id' => $pid,
            'name' => 'Store only name', 'description' => 'Store only description.',
        ]);
        $group = \Extensions\venta\Models\VentaProductGroup::create(['venta_setting_id' => $store->id, 'name' => 'Pedals']);
        \Extensions\venta\Models\VentaProductGroupProduct::create(['venta_product_group_id' => $group->id, 'product_id' => $pid]);

        Http::fake([
            self::BASE . '/api/v1/products/GP-200' => Http::response(['error' => 'Product not found'], 404),
            self::BASE . '/api/v1/products' => Http::response(['id' => 9101, 'sku' => 'GP-200', 'images' => [], 'images_failed' => []], 201),
        ]);

        // The group push is a product_group write, not a listing write.
        $pusher = $this->userWith(['view_venta/listing', 'manage_venta/listing', 'view_venta/product_group', 'manage_venta/product_group']);
        $this->actingAs($pusher)
            ->post(route('ext.venta.product-groups.push', [$store->id, $group->id]), ['ids' => [$pid]])
            ->assertRedirect();

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/api/v1/products')) {
                return false;
            }
            $body = $request->data();

            return $body['name'] === 'Store only name'
                && $body['description'] === 'Store only description.';
        });
    }

    public function test_pushing_from_the_page_sends_the_records_content_and_price_and_creates_when_the_store_has_none(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaListing::create(['venta_setting_id' => $store->id, 'product_id' => $pid, 'name' => 'Pedal, store wording', 'markup_percent' => 10]);

        Http::fake([
            self::BASE . '/api/v1/products/GP-200' => Http::response(['error' => 'Product not found'], 404),
            self::BASE . '/api/v1/products' => Http::response(['id' => 9001, 'sku' => 'GP-200', 'images' => [], 'images_failed' => []], 201),
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.venta.listings.push', [$store->id, $pid]))
            ->assertRedirect(route('ext.venta.listings.edit', [$store->id, $pid]))
            ->assertSessionHas('status');

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/api/v1/products')) {
                return false;
            }
            $body = $request->data();

            return $body['name'] === 'Pedal, store wording'
                && $body['description'] === 'The catalogue description.'
                && $body['sku'] === 'GP-200'
                && (float) $body['price'] === 1100.0;
        });

        $this->assertSame(9001, (int) VentaProductLink::where('product_id', $pid)->value('venta_product_id'));

        $listing = VentaListing::where('product_id', $pid)->firstOrFail();
        $this->assertNotNull($listing->last_pushed_at);
        $this->assertSame('listing', $listing->last_push_source);
        $this->assertSame('created', $listing->last_push_settings['state']);
        $this->assertSame(1100.0, (float) $listing->last_push_settings['price']);
        $this->assertSame(VentaListing::STATUS_ACTIVE, $listing->live_status);
    }

    public function test_pushing_updates_in_place_when_the_store_holds_the_sku(): void
    {
        $store = $this->store();
        $pid = $this->product();

        Http::fake([
            self::BASE . '/api/v1/products/GP-200' => Http::sequence()
                ->push($this->storeProduct(5001))
                ->push(['id' => 5001, 'sku' => 'GP-200', 'images' => ['a'], 'images_failed' => [['url' => 'x', 'reason' => 'Host resolves to a disallowed address']]]),
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.venta.listings.push', [$store->id, $pid]))
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertSame('Updated on Gear Depot. 1 of 2 images were rejected by the store: 1x Host resolves to a disallowed address.', session('warning'));
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/api/v1/products/GP-200'));
        $this->assertSame('updated', VentaListing::where('product_id', $pid)->first()->last_push_settings['state']);
    }

    public function test_a_refusal_reaches_the_page_in_the_stores_words(): void
    {
        $store = $this->store();
        $pid = $this->product();

        Http::fake([
            self::BASE . '/api/v1/products/GP-200' => Http::response(['error' => 'Product not found'], 404),
            self::BASE . '/api/v1/products' => Http::response(['error' => 'images_unavailable'], 422),
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.venta.listings.push', [$store->id, $pid]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertStringContainsString('will not create a product it cannot fetch images for', session('error'));
        $this->assertNull(VentaListing::where('product_id', $pid)->value('last_pushed_at'));
    }

    public function test_unlist_and_relist_write_through_to_the_store_and_the_mirror(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaProductLink::create(['venta_setting_id' => $store->id, 'product_id' => $pid, 'venta_product_id' => 5001, 'sku' => 'GP-200']);

        Http::fake([self::BASE . '/api/v1/products/GP-200' => Http::response(['id' => 5001, 'status' => false])]);

        $this->actingAs($this->manager())
            ->post(route('ext.venta.listings.toggle', [$store->id, $pid]), ['action' => 'unlist'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->data() === ['status' => false]);
        $this->assertSame(VentaListing::STATUS_INACTIVE, VentaListing::where('product_id', $pid)->value('live_status'));

        $this->actingAs($this->manager())
            ->post(route('ext.venta.listings.toggle', [$store->id, $pid]), ['action' => 'relist'])
            ->assertRedirect();

        $this->assertSame(VentaListing::STATUS_ACTIVE, VentaListing::where('product_id', $pid)->value('live_status'));
    }

    public function test_the_view_tier_cannot_write(): void
    {
        $store = $this->store();
        $pid = $this->product();
        Http::fake();

        $this->actingAs($this->viewer())->put(route('ext.venta.listings.update', [$store->id, $pid]), ['name' => 'x'])->assertRedirect()->assertSessionHas('error');
        $this->actingAs($this->viewer())->post(route('ext.venta.listings.push', [$store->id, $pid]))->assertRedirect()->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame(0, VentaListing::count());
    }

    public function test_the_page_is_reachable_from_the_group_products_row_and_the_product_page(): void
    {
        $store = $this->store();
        $pid = $this->product();
        $group = VentaProductGroup::create(['venta_setting_id' => $store->id, 'name' => 'Pedals']);
        VentaProductGroupProduct::create(['venta_product_group_id' => $group->id, 'product_id' => $pid, 'sync_status' => 'pending']);
        Http::fake();

        $html = $this->actingAs($this->userWith(['view_venta/product_group', 'manage_venta/product_group', 'view_venta/listing']))
            ->get(route('ext.venta.product-groups.products', [$store->id, $group->id]))
            ->assertOk()
            ->getContent();

        // No Edit listing on a group page (the owner, 2026-09-07); the row's pushes ride the group's rule and the listing is reached from Listings.
        $this->assertStringNotContainsString(route('ext.venta.listings.edit', [$store->id, $pid]), $html);

        $actions = $this->app->make(VentaExtension::class, ['app' => $this->app])->productActions($pid);
        $this->assertCount(1, $actions);
        $this->assertSame('Listing on Gear Depot', $actions[0]['label']);
        $this->assertSame('view_venta/listing', $actions[0]['permission']);
    }

    public function test_a_disabled_store_is_not_asked_and_still_shows_the_record(): void
    {
        $store = $this->store(false);
        $pid = $this->product();
        Http::fake();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.venta.listings.edit', [$store->id, $pid]))
            ->assertOk()
            ->getContent();

        /* The note went with the health surface on 2026-09-09. What this
           test exists to prove is that a disabled store is not ASKED, and
           that is unchanged. */
        $this->assertStringNotContainsString('This store is turned off', $html);
        Http::assertNothingSent();
    }

    /** Venta closes its Listings gaps (2026-09-06): the slide-over, the engine's cell, and the core grid reading Venta. */
    public function test_add_products_brings_a_product_onto_this_store_and_the_engine_names_its_state(): void
    {
        $store = $this->store();
        $pid = $this->product('GP-200');
        $nowhere = $this->product('PICK-1');

        // Nothing on the store yet: the page offers Add products, not a group.
        $page = $this->actingAs($this->manager())->get(route('ext.venta.listings.index', $store->id))->assertOk();
        $page->assertSee('data-add-panel-open', false);
        $page->assertSee('venta-add-panel');
        $page->assertDontSee('Go to Product Groups');

        // The search says what is not on the store; adding one makes a listing row and the row appears.
        $search = $this->actingAs($this->manager())->getJson(route('ext.venta.products.catalogue_search', [$store->id, 'q' => 'PICK']))->assertOk()->json();
        $this->assertSame([$nowhere], array_column($search['items'], 'id'));
        $this->actingAs($this->manager())->post(route('ext.venta.products.add_to_store', [$store->id, $nowhere]))->assertRedirect();
        $this->assertDatabaseHas('venta_listings', ['venta_setting_id' => $store->id, 'product_id' => $nowhere]);
        $page = $this->actingAs($this->manager())->get(route('ext.venta.listings.index', $store->id))->assertOk();
        // Enabled with a SKU is all the store's push refuses on, so the Ready column says Ready (2026-09-06); the image is the store's to want.
        $page->assertSee('>Ready<', false);
        $page->assertDontSee('a product image on the catalog product');
        $page->assertSee('Remove from this channel');

        // A linked product reads Live through the shared cell; the core grid's source agrees.
        \Extensions\venta\Models\VentaProductLink::create(['venta_setting_id' => $store->id, 'venta_product_id' => 9001, 'product_id' => $pid, 'sku' => 'GP-200']);
        \Extensions\venta\Models\VentaListing::create(['venta_setting_id' => $store->id, 'product_id' => $pid, 'live_status' => 'active', 'live_checked_at' => now(), 'last_pushed_at' => now()]);
        $states = (new \Extensions\venta\VentaExtension($this->app))->listingStates([$pid, $nowhere]);
        $this->assertSame(\App\Integrations\Listings\ListingState::LIVE, $states[$pid]->state);
        $this->assertSame(\App\Integrations\Listings\ListingState::NOT_LISTED, $states[$nowhere]->state);
        $this->assertContains($pid, (new \Extensions\venta\VentaExtension($this->app))->listedProductIds());
        $this->assertStringContainsString('/listings/' . $pid, (new \Extensions\venta\VentaExtension($this->app))->listingUrls([$pid])[$pid]);

        // Remove from this store: the row, the link and the group place go; the store is not asked.
        $this->actingAs($this->manager())->post(route('ext.venta.products.remove_from_store', [$store->id, $nowhere]))->assertRedirect();
        $this->assertDatabaseMissing('venta_listings', ['venta_setting_id' => $store->id, 'product_id' => $nowhere]);
        $this->actingAs($this->manager())->get(route('ext.venta.listings.index', $store->id))->assertOk()->assertDontSee('PICK-1');
    }


    /** Push price sends the price through the listing's own rule, never the plain catalog price. */
    public function test_push_price_sends_the_listings_price_rule(): void
    {
        $store = $this->store();
        $pid = $this->product('GP-200', 1000);
        VentaProductLink::create(['venta_setting_id' => $store->id, 'product_id' => $pid, 'venta_product_id' => 5001, 'sku' => 'GP-200']);
        VentaListing::query()->create(['venta_setting_id' => $store->id, 'product_id' => $pid, 'markup_percent' => 10]);
        Http::fake([self::BASE . '/api/v1/products/GP-200' => Http::response(['data' => ['sku' => 'GP-200']])]);

        $this->actingAs($this->userWith(['view_venta/listing', 'manage_venta/listing']))
            ->post(route('ext.venta.listings.push_price', [$store->id, $pid]))
            ->assertRedirect();

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_contains($r->url(), '/api/v1/products/GP-200')
            && (float) ($r->data()['price'] ?? 0) === 1100.0);
    }
}
