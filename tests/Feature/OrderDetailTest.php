<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use App\Models\Catalog\OrderOption;
use App\Models\Catalog\OrderProduct;
use App\Models\Catalog\OrderTotal;
use App\Models\OrderPayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The core Sales order detail page, rebuilt on layouts.shell and the `od-`
 * component anatomy (task 1 of the order-detail redesign).
 *
 * What these tests are actually protecting:
 *
 *  1. The page still renders for the READ tier. `orders.show` is gated
 *     `perm:view_orders|manage_orders`, so an operator who can only look at
 *     orders must still get a page, not a 403.
 *
 *  2. Mutating controls appear for the MANAGE tier and for nobody else,
 *     asserted in both directions. This is the behaviour change the rebuild
 *     makes: `$canManageOrders` was hardcoded `true` in the old view, under
 *     a comment claiming the controls were hidden for read-tier operators.
 *     They were not - every form rendered for everyone and only failed on
 *     submit. Both the UI assertions and the live POST assertions below run
 *     against the real route middleware, never a stand-in.
 *
 *  3. A foreign-currency order shows BOTH figures. The order fixture is
 *     shaped like #44294's normalized form (foreign_* columns populated,
 *     `currency_value` holding units of the default currency per 1 unit of
 *     the order currency), because that order is the one the currency
 *     project's invariants were derived from. The default-currency figure
 *     leads and the as-transacted figure sits beside it; neither is ever
 *     fabricated, which is why the third currency test asserts that an
 *     order with no recorded rate shows a pending marker instead of a
 *     converted number.
 *
 * Fixtures are built here rather than looked up (the ChannelWorkspaceTest
 * discipline): `ventasync_test` seeds no admin, and a test that leans on
 * whatever permissions some existing account happens to hold is not
 * asserting anything about the gate it claims to test.
 */
class OrderDetailTest extends TestCase
{
    use RefreshDatabase;

    private string $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = (string) config('catalog.prefix');
        // Money caches the default symbol/code for the lifetime of the
        // process; RefreshDatabase rebuilds `currencies` between tests, so
        // a cache carried over from a previous test would be stale.
        Money::flushCache();
    }

    /**
     * Read tier: can open the page, can change nothing.
     *
     * `view_orders` is the key `routes/web.php` already names on the
     * orders.show route (`perm:view_orders|manage_orders`) and that
     * PaletteController already checks. It is now seeded by
     * `2026_08_07_190000_seed_view_orders_permission.php`, additive and
     * granted to nobody, so the row exists on every migrated database but
     * changes no one's access on its own. This fixture still creates it
     * directly with `firstOrCreate` rather than depending on that migration
     * having run, for the same reason every other test file in this suite
     * builds its own permission groups instead of leaning on migration
     * state: a test that could pass or fail depending on which migrations
     * happen to be applied is not asserting anything stable about the gate
     * it claims to test.
     */
    private function viewer(): User
    {
        Permission::firstOrCreate(['key' => 'view_sales/order']);

        $group = UserGroup::create(['name' => 'Order Viewers']);
        $group->permissions()->attach(
            Permission::where('key', 'view_sales/order')->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /** Manage tier: every mutating route on this page is perm:manage_orders. */
    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Order Managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_api/order', 'manage_sales/order', 'manage_sales/order_payment'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * The `order` table is OpenCart's, so almost every column is NOT NULL
     * with no default. This is the same complete attribute set
     * OrderController::store() writes, so a fixture order is shaped exactly
     * like one the application itself would create.
     *
     * @return array<string, mixed>
     */
    private function orderColumnDefaults(): array
    {
        return [
            'invoice_no' => 0, 'invoice_prefix' => '', 'store_id' => 0, 'store_name' => '',
            'store_url' => '', 'customer_id' => 0, 'customer_group_id' => 0,
            'firstname' => '', 'lastname' => '', 'email' => '', 'telephone' => '',
            'fax' => '', 'custom_field' => '',
            'payment_firstname' => '', 'payment_lastname' => '', 'payment_company' => '',
            'payment_address_1' => '', 'payment_address_2' => '', 'payment_city' => '',
            'payment_postcode' => '', 'payment_country' => '', 'payment_country_id' => 0,
            'payment_zone' => '', 'payment_zone_id' => 0, 'payment_address_format' => '',
            'payment_custom_field' => '', 'payment_method' => '', 'payment_code' => '',
            'shipping_firstname' => '', 'shipping_lastname' => '', 'shipping_company' => '',
            'shipping_address_1' => '', 'shipping_address_2' => '', 'shipping_city' => '',
            'shipping_postcode' => '', 'shipping_country' => '', 'shipping_country_id' => 0,
            'shipping_zone' => '', 'shipping_zone_id' => 0, 'shipping_address_format' => '',
            'shipping_custom_field' => '', 'shipping_method' => '', 'shipping_code' => '',
            'shipping_cost' => 0, 'comment' => '', 'total' => 0,
            'affiliate_id' => 0, 'commission' => 0, 'marketing_id' => 0, 'tracking' => '',
            'language_id' => (int) config('catalog.default_language_id', 1),
            'currency_id' => 0, 'currency_code' => 'PHP', 'currency_value' => 1,
            'ip' => '', 'forwarded_ip' => '', 'user_agent' => '', 'accept_language' => '',
            'courier_id' => 0, 'tracking_number' => '', 'oe_import' => 0,
            'order_status_id' => 1,
            'date_added' => '2026-08-05 10:15:00',
            'date_modified' => '2026-08-05 10:15:00',
        ];
    }

    /**
     * A default-currency order with two items, an option, a fee, a payment
     * and a history entry - enough that every block of the page renders.
     */
    private function seedPhpOrder(array $overrides = []): Order
    {
        $order = Order::create(array_merge($this->orderColumnDefaults(), [
            'firstname' => 'Juan',
            'lastname' => 'Dela Cruz',
            'email' => 'juan@example.test',
            'telephone' => '0917 555 0101',
            'payment_firstname' => 'Juan',
            'payment_lastname' => 'Dela Cruz',
            'payment_address_1' => '12 Mabini Street',
            'payment_city' => 'Quezon City',
            'payment_postcode' => '1100',
            'payment_country' => 'Philippines',
            'payment_method' => 'Bank Transfer',
            'shipping_firstname' => 'Juan',
            'shipping_lastname' => 'Dela Cruz',
            'shipping_address_1' => '12 Mabini Street',
            'shipping_city' => 'Quezon City',
            'shipping_postcode' => '1100',
            'shipping_country' => 'Philippines',
            'shipping_method' => 'Flat Shipping Rate',
            'shipping_cost' => 150.00,
            'tracking_number' => 'PX123456789PH',
            'comment' => 'Leave with the guard.',
            'total' => 2650.00,
            'order_status_id' => 1,
            'currency_id' => 0,
            'currency_code' => 'PHP',
            'currency_value' => 1,
            'date_added' => '2026-08-05 10:15:00',
            'date_modified' => '2026-08-05 10:15:00',
            'track_payments' => true,
        ], $overrides));

        $first = OrderProduct::create([
            'order_id' => $order->order_id,
            'product_id' => 0,
            'name' => 'Trail Runner Cap',
            'model' => 'TRC-001',
            'quantity' => 2,
            'price' => 800.00,
            'total' => 1600.00,
            'reward' => 0,
            'cost' => 500.00,
        ]);

        OrderOption::create([
            'order_id' => $order->order_id,
            'order_product_id' => $first->order_product_id,
            'product_option_id' => 0,
            'product_option_value_id' => 0,
            'name' => 'Colour',
            'value' => 'Olive',
            'type' => 'select',
        ]);

        OrderProduct::create([
            'order_id' => $order->order_id,
            'product_id' => 0,
            'name' => 'Merino Sock',
            'model' => 'MS-204',
            'quantity' => 3,
            'price' => 300.00,
            'total' => 900.00,
            'reward' => 0,
            'cost' => 180.00,
        ]);

        OrderTotal::create([
            'order_id' => $order->order_id,
            'code' => 'sub_total',
            'title' => 'Sub-Total',
            'value' => 2500.00,
            'sort_order' => 1,
        ]);
        OrderTotal::create([
            'order_id' => $order->order_id,
            'code' => 'shipping',
            'title' => 'Flat Shipping Rate',
            'value' => 150.00,
            'sort_order' => 3,
        ]);
        OrderTotal::create([
            'order_id' => $order->order_id,
            'code' => 'total',
            'title' => 'Total',
            'value' => 2650.00,
            'sort_order' => 9,
        ]);

        DB::table('order_fees')->insert([
            'order_id' => $order->order_id,
            'label' => 'Platform commission',
            'amount' => 132.50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        OrderPayment::create([
            'order_id' => $order->order_id,
            'amount' => 1000.00,
            'payment_method' => 'GCash',
            'paid_at' => '2026-08-06',
            'reference_no' => 'GC-99881',
            'notes' => 'Partial',
            'created_at' => now(),
        ]);

        OrderHistory::create([
            'order_id' => $order->order_id,
            'order_status_id' => 1,
            'user_id' => 0,
            'user_name' => 'Sync',
            'notify' => 0,
            'comment' => 'Order created.',
            'date_added' => '2026-08-05 10:15:00',
        ]);

        return $order->refresh();
    }

    /**
     * The #44294 shape: a USD order normalized by the currency project.
     *
     * `currency_value` is units of the default currency per 1 USD, the
     * money columns hold the authoritative default-currency amounts, and
     * the `foreign_*` columns hold the as-transacted USD figures. The
     * numbers below are chosen so the two currencies can never be confused
     * for each other in an assertion: 61.61429452 x $5,000 = P308,071.47.
     */
    private function seedForeignOrder(): Order
    {
        $rate = 61.61429452;

        $order = Order::create(array_merge($this->orderColumnDefaults(), [
            'firstname' => 'Ada',
            'lastname' => 'Reyes',
            'email' => 'ada@example.test',
            'payment_method' => 'Wire',
            'shipping_method' => 'Air freight',
            'shipping_cost' => 0,
            'total' => 308071.47,
            'foreign_total' => 5000.00,
            'currency_id' => (int) DB::table('currencies')->where('code', 'USD')->value('id'),
            'currency_code' => 'USD',
            'currency_value' => $rate,
            'date_added' => '2026-08-05 09:00:00',
            'date_modified' => '2026-08-05 09:00:00',
            'track_payments' => false,
        ]));

        OrderProduct::create([
            'order_id' => $order->order_id,
            'product_id' => 0,
            'name' => 'Bulk Component',
            'model' => 'BC-500',
            'quantity' => 2,
            'price' => 154035.735,       // P per unit
            'foreign_price' => 2500.00,  // $ per unit, as transacted
            'total' => 308071.47,
            'foreign_total' => 5000.00,
            'reward' => 0,
            'cost' => 100000.00,
        ]);

        OrderTotal::create([
            'order_id' => $order->order_id,
            'code' => 'sub_total',
            'title' => 'Sub-Total',
            'value' => 308071.47,
            'sort_order' => 1,
        ]);
        OrderTotal::create([
            'order_id' => $order->order_id,
            'code' => 'total',
            'title' => 'Total',
            'value' => 308071.47,
            'sort_order' => 9,
        ]);

        return $order->refresh();
    }

    // ── Rendering ───────────────────────────────────────────────

    public function test_the_page_renders_for_a_read_tier_operator(): void
    {
        $order = $this->seedPhpOrder();

        $response = $this->actingAs($this->viewer())->get(route('orders.show', $order->order_id));

        $response->assertOk();
        // On the new shell, on the new component anatomy.
        $response->assertSee('class="bl-app', false);
        $response->assertSee('od-page', false);
        $response->assertSee('#' . $order->order_id);
    }

    public function test_the_page_shows_every_order_figure(): void
    {
        $order = $this->seedPhpOrder();

        $response = $this->actingAs($this->manager())->get(route('orders.show', $order->order_id));

        $response->assertOk();

        // Items: name, model/SKU, variation, quantity, unit price, line total.
        $response->assertSee('Trail Runner Cap');
        $response->assertSee('TRC-001');
        $response->assertSee('Colour: Olive');
        $response->assertSee('Merino Sock');
        $response->assertSee(Money::base(800.00));   // unit price
        $response->assertSee(Money::base(1600.00));  // line total
        // Per-item economics: unit cost, COGS, profit, margin, markup.
        $response->assertSee(Money::base(500.00));   // unit cost
        $response->assertSee(Money::base(1000.00));  // COGS on line 1
        $response->assertSee('37.5%');               // margin on line 1

        // Ledger: order totals, order total, COGS, shipping cost, fee, profit.
        $response->assertSee('Sub-Total');
        $response->assertSee(Money::base(2500.00));
        $response->assertSee(Money::base(2650.00));
        $response->assertSee('Platform commission');
        $response->assertSee('-' . Money::base(132.50));
        $response->assertSee('-' . Money::base(150.00));
        $response->assertSee('Net profit');

        // Customer, addresses, methods, tracking.
        $response->assertSee('Juan Dela Cruz');
        $response->assertSee('juan@example.test');
        $response->assertSee('0917 555 0101');
        $response->assertSee('12 Mabini Street');
        $response->assertSee('Bank Transfer');
        $response->assertSee('Flat Shipping Rate');
        $response->assertSee('PX123456789PH');
        $response->assertSee('Leave with the guard.');

        // Payments.
        $response->assertSee('GCash');
        $response->assertSee('GC-99881');
        $response->assertSee(Money::base(1000.00));  // paid
        $response->assertSee(Money::base(1650.00));  // remaining balance

        // History.
        $response->assertSee('Order created.');
        $response->assertSee('by Sync', false);
    }

    public function test_an_order_with_no_products_still_renders(): void
    {
        $order = Order::create(array_merge($this->orderColumnDefaults(), [
            'firstname' => 'Empty',
            'lastname' => 'Order',
        ]));

        $response = $this->actingAs($this->manager())->get(route('orders.show', $order->order_id));

        $response->assertOk();
        $response->assertSee('No products on this order.');
        $response->assertSee('No history records.');
    }

    /** #138 on the order view: a line whose SKU is a variation shows that variation's picture, not the product's. */
    public function test_a_variation_line_shows_the_variations_picture(): void
    {
        $order = $this->seedPhpOrder();
        $pfx = (string) config('catalog.prefix');
        $line = DB::table($pfx . 'order_product')->where('order_id', $order->order_id)->first();
        // A real catalog product with a picture, and one variation with its own.
        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'VAR-PARENT', 'sku' => 'VAR-PARENT', 'quantity' => 5, 'price' => 100, 'status' => 1, 'image' => 'catalog/parent.png',
            'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table('product_option_combinations')->insert([
            'product_id' => $productId, 'sku' => 'VAR-RED-1', 'quantity' => 1, 'absolute_price' => 100, 'image' => 'catalog/red.png',
            'status' => 1, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table($pfx . 'order_product')->where('order_product_id', $line->order_product_id)->update(['product_id' => $productId, 'model' => 'VAR-RED-1']);

        $html = $this->actingAs($this->viewer())->get(route('orders.show', $order->order_id))->assertOk()->getContent();

        $this->assertStringContainsString('storage/catalog/red.png', $html, 'the variation\'s own picture');
        $this->assertStringNotContainsString('storage/catalog/parent.png', $html);
    }

    // ── Gating, both directions, against the real middleware ────

    public function test_mutating_controls_render_for_the_manage_tier(): void
    {
        $order = $this->seedPhpOrder();

        $response = $this->actingAs($this->manager())->get(route('orders.show', $order->order_id));

        $response->assertOk();
        $response->assertSee(route('orders.edit', $order->order_id), false);
        $response->assertSee(route('orders.update_status', $order->order_id), false);
        $response->assertSee(route('orders.toggle_override', $order->order_id), false);
        $response->assertSee(route('orders.store_fee', $order->order_id), false);
        $response->assertSee(route('orders.store_payment', $order->order_id), false);
        $response->assertSee(route('orders.toggle_payments', $order->order_id), false);

        $feeId = (int) DB::table('order_fees')->where('order_id', $order->order_id)->value('id');
        $paymentId = (int) OrderPayment::where('order_id', $order->order_id)->value('id');
        $response->assertSee(route('orders.destroy_fee', [$order->order_id, $feeId]), false);
        $response->assertSee(route('orders.destroy_payment', [$order->order_id, $paymentId]), false);

        // The four click-to-edit figures.
        $response->assertSee('data-edit="cost"', false);
        $response->assertSee('data-edit="shipping"', false);
        $response->assertSee('data-edit="fee-amount"', false);
        $response->assertSee('data-edit="fee-label"', false);

        // Destructive actions keep their confirm convention; no native dialogs.
        $response->assertSee('data-confirm="Remove this fee?"', false);
        $response->assertSee('data-confirm="Delete this payment?"', false);
        $response->assertSee('data-confirm="Disable payment tracking for this order?"', false);
        $response->assertDontSee('confirm(', false);
    }

    public function test_mutating_controls_are_absent_for_the_read_tier(): void
    {
        $order = $this->seedPhpOrder();

        $response = $this->actingAs($this->viewer())->get(route('orders.show', $order->order_id));

        $response->assertOk();
        $response->assertDontSee(route('orders.edit', $order->order_id), false);
        $response->assertDontSee(route('orders.update_status', $order->order_id), false);
        $response->assertDontSee(route('orders.toggle_override', $order->order_id), false);
        $response->assertDontSee(route('orders.store_fee', $order->order_id), false);
        $response->assertDontSee(route('orders.store_payment', $order->order_id), false);
        $response->assertDontSee(route('orders.toggle_payments', $order->order_id), false);
        $response->assertDontSee('data-edit=', false);
        $response->assertDontSee('data-confirm=', false);

        // The figures those controls sit on are still there - a read-tier
        // operator loses the controls, not the information.
        $response->assertSee(Money::base(500.00));   // unit cost
        $response->assertSee('Platform commission');
        $response->assertSee(Money::base(1000.00));  // the recorded payment
    }

    public function test_the_backfill_control_only_appears_when_a_cost_is_missing(): void
    {
        $order = $this->seedPhpOrder();
        $manager = $this->manager();

        // Every line has a cost, so there is nothing to backfill.
        $this->actingAs($manager)
            ->get(route('orders.show', $order->order_id))
            ->assertDontSee(route('orders.backfill_costs', $order->order_id), false);

        OrderProduct::where('order_id', $order->order_id)->update(['cost' => 0]);

        $this->actingAs($manager)
            ->get(route('orders.show', $order->order_id))
            ->assertSee(route('orders.backfill_costs', $order->order_id), false);
    }

    public function test_the_read_tier_is_refused_by_the_middleware_on_a_status_post(): void
    {
        $order = $this->seedPhpOrder();

        $response = $this->actingAs($this->viewer())->post(
            route('orders.update_status', $order->order_id),
            ['order_status_id' => 5, 'comment' => 'should not land']
        );

        // EnsurePermission redirects an HTML caller back with a flashed
        // error rather than rendering a 403 page.
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(1, (int) $order->fresh()->order_status_id);
        $this->assertSame(1, OrderHistory::where('order_id', $order->order_id)->count());
    }

    public function test_the_manage_tier_can_post_a_status_change(): void
    {
        $order = $this->seedPhpOrder();

        $response = $this->actingAs($this->manager())->post(
            route('orders.update_status', $order->order_id),
            ['order_status_id' => 5, 'comment' => 'Packed and handed over']
        );

        $response->assertRedirect(route('orders.show', $order->order_id));
        $this->assertSame(5, (int) $order->fresh()->order_status_id);
        $this->assertSame(2, OrderHistory::where('order_id', $order->order_id)->count());
    }

    public function test_the_read_tier_is_refused_on_the_inline_cost_endpoint(): void
    {
        $order = $this->seedPhpOrder();
        $line = OrderProduct::where('order_id', $order->order_id)->first();

        $response = $this->actingAs($this->viewer())->postJson(
            route('orders.update_product_cost', $order->order_id),
            ['order_product_id' => $line->order_product_id, 'cost' => 1]
        );

        $response->assertStatus(403);
        $this->assertEqualsWithDelta(500.00, (float) $line->fresh()->cost, 0.001);
    }

    // ── Dual currency ───────────────────────────────────────────

    public function test_a_foreign_order_shows_both_the_default_and_as_transacted_figures(): void
    {
        $order = $this->seedForeignOrder();

        $response = $this->actingAs($this->manager())->get(route('orders.show', $order->order_id));

        $response->assertOk();

        // Order total: default currency leads, as-transacted sits beside it.
        $response->assertSee(Money::base(308071.47));
        $response->assertSee(Money::foreign(5000.00, 'USD'));

        // Line item unit price, both currencies.
        $response->assertSee(Money::base(154035.735));
        $response->assertSee(Money::foreign(2500.00, 'USD'));

        // The rate note names the currency, the rate and the date it came
        // from, in plain words - never a fabricated figure. The rate and the
        // date each sit in their own <span class="od-rate"> so they render
        // in the mono face like every other numeral here, which is why this
        // is asserted in order rather than as one string.
        $response->assertSeeInOrder([
            '1 USD =',
            Money::defaultSymbol() . '61.61429452',
            'Based on',
            '2026-08-05',
            'currency rate',
        ]);

        // Both <x-money> lines are present on the page, not just one.
        $response->assertSee('money-primary', false);
        $response->assertSee('money-secondary', false);
        $response->assertDontSee('money-pending', false);
    }

    public function test_a_default_currency_order_shows_no_second_figure(): void
    {
        $order = $this->seedPhpOrder();

        $response = $this->actingAs($this->manager())->get(route('orders.show', $order->order_id));

        $response->assertOk();
        $response->assertSee('money-primary', false);
        $response->assertDontSee('money-secondary', false);
        $response->assertDontSee('money-pending', false);
        $response->assertDontSee('currency rate');
    }

    public function test_a_foreign_order_with_no_recorded_rate_shows_a_pending_marker(): void
    {
        $order = $this->seedForeignOrder();
        // The pre-normalization shape: a genuinely foreign order whose
        // foreign_* columns were never filled in.
        Order::where('order_id', $order->order_id)->update(['foreign_total' => null]);
        OrderProduct::where('order_id', $order->order_id)
            ->update(['foreign_price' => null, 'foreign_total' => null]);

        $response = $this->actingAs($this->manager())->get(route('orders.show', $order->order_id));

        $response->assertOk();
        $response->assertSee('Exchange rate not yet recorded');
        $response->assertSee('money-pending', false);
        // No converted figure is invented in place of the missing one.
        $response->assertDontSee(Money::foreign(5000.00, 'USD'));
        $response->assertDontSee('money-secondary', false);
    }

    public function test_the_currency_note_is_absent_for_a_blank_currency_code(): void
    {
        // Pre-currency-feature legacy rows carry an empty currency_code.
        // That is a default-currency order, not "foreign with no name".
        $order = $this->seedPhpOrder(['currency_code' => '']);

        $response = $this->actingAs($this->manager())->get(route('orders.show', $order->order_id));

        $response->assertOk();
        $response->assertDontSee('currency-banner', false);
        $response->assertDontSee('money-pending', false);
    }
}
