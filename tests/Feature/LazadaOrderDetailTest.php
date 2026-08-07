<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\LazadaExtension;
use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaOrderProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Lazada order detail page, rebuilt on the shared `od-` detail anatomy
 * inside the seller center (Task 3 of the order-detail redesign).
 *
 * Fixture discipline is ShopeeOrderDetailTest's, which is ChannelWorkspace
 * Test's: this file builds its own permission groups and its own orders
 * rather than leaning on whatever the test schema happens to hold, and the
 * setUp below is that file's, for the same reasons its docblocks give (the
 * extension must be installed, enabled and REGISTERED, and the router's name
 * lookups rebuilt, before any Lazada route resolves by name).
 *
 * No fixture here carries Lazada credentials, so LazadaOrderController@show
 * never calls the live API: `$hasCreds` is false, the detail pull, the item
 * sync and the fee extraction are all skipped, and every figure asserted
 * below comes from the stored payload. That is deliberate - a test that could
 * reach the network proves nothing repeatable about a view.
 */
class LazadaOrderDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');

        $this->app->register(LazadaExtension::class);

        // Routes registered after the RouteCollection last built its name
        // lookup table are invisible to Route::has(), which ChannelWorkspace
        // calls for every sidebar destination. See ChannelWorkspaceTest.
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    /** Read tier: can open the page, holds nothing that writes. */
    private function viewer(): User
    {
        $group = UserGroup::create(['name' => 'Lazada Detail Viewers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_lazada', 'view_lazada_orders'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /** Manage tier: the refresh pull and every marketplace write are gated on this. */
    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Lazada Detail Managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_lazada', 'manage_lazada_orders'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * An order in the shape LazadaOrderController@show reads it: `region`
     * 'ph' because with no LazadaSetting row the controller falls back to
     * 'ph'. Two product rows of one SKU, because Lazada emits one row per
     * unit and consolidating them is what this page does.
     */
    private function order(string $status = 'packed', array $overrides = []): LazadaOrder
    {
        $orderId = $overrides['order_id'] ?? 'LZ-DETAIL-0001';

        $order = LazadaOrder::create(array_merge([
            'region' => 'ph',
            'order_id' => $orderId,
            'status' => $status,
            'order_created_at' => '2026-08-03 10:11:00',
            'order_updated_at' => '2026-08-06 16:20:00',
            'raw' => [
                'order_number' => $orderId,
                'statuses' => [$status],
                'currency' => 'PHP',
                'customer_name' => 'Maria Santos',
                'shipping_provider' => 'LEX PH',
                'tracking_code' => 'LZDPH884120394',
                'address_shipping' => [
                    'first_name' => 'Maria',
                    'phone' => '+639175550123',
                    'address1' => '221 Rizal Avenue, Barangay Bagumbayan',
                    'address2' => 'Unit 4B, Sunrise Residences',
                    'city' => 'Quezon City',
                    'province' => 'Metro Manila',
                    'post_code' => '1101',
                    'country' => 'Philippines',
                ],
            ],
            'fees' => [
                'subtotal' => 3400.00,
                'paid_total' => 3240.00,
                'shipping' => 95.00,
                'voucher_seller' => 160.00,
                'voucher_platform' => 90.00,
                'wallet_credits' => 25.00,
                'commission' => -162.00,
                'payment_fee' => -64.80,
                'shipping_service_cost' => -95.00,
                'shipping_discount_seller' => 40.00,
                'shipping_discount_platform' => 55.00,
                'other_fees' => ['sponsored_product_fee' => -18.50],
                'transaction_lines' => [
                    [
                        'fee_type' => 16,
                        'fee_name' => 'Commission',
                        'amount' => -162.00,
                        'sku' => 'SKU-LZ-01',
                        'order_item_id' => '900001',
                        'transaction_number' => 'TX-1',
                        'transaction_date' => '2026-08-05',
                    ],
                    [
                        'fee_type' => 112,
                        'fee_name' => '',
                        'amount' => -18.50,
                        'sku' => '',
                        'order_item_id' => '900002',
                        'transaction_number' => 'TX-3',
                        'transaction_date' => '2026-08-06',
                    ],
                ],
            ],
        ], $overrides));

        foreach ([1, 2] as $n) {
            LazadaOrderProduct::create([
                'lazada_order_id' => $order->id,
                'order_item_id' => 900000 + $n,
                'sku' => 'SKU-LZ-01',
                'name' => 'Meridian Ceramic Pour-Over Dripper',
                'variation' => 'Slate, 02 size',
                'quantity' => 1,
                'item_price' => 1700.00,
                'paid_price' => 1620.00,
                'image' => 'https://lzd-img.test/dripper.jpg',
                'raw' => [
                    'seller_sku' => 'SKU-LZ-01',
                    'item_price' => 1700.00,
                    'paid_price' => 1620.00,
                    'shipping_amount' => 47.50,
                    'voucher_seller' => 80.00,
                    'voucher_platform' => 45.00,
                    'shipping_service_cost' => 47.50,
                    'status' => $status,
                ],
            ]);
        }

        return $order->fresh('products');
    }

    // ------------------------------------------------------------------
    // The page renders inside the seller center
    // ------------------------------------------------------------------

    public function test_the_detail_page_renders_inside_the_workspace_for_the_read_tier(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->viewer())->get('/lazada/orders/'.$order->order_id);

        $response->assertOk();

        // Channel sidebar present, global sidebar absent, no cross-marketplace
        // strip: the same three discriminators ChannelWorkspaceTest uses.
        $response->assertSee('x-cnav', false);
        $response->assertSee('All channels');
        $response->assertDontSee('data-nav', false);
        $response->assertDontSee('All marketplaces');

        // On the shared detail anatomy, outside the .x-legacy boundary.
        $response->assertSee('class="od-page"', false);
        $response->assertDontSee('x-content x-legacy', false);

        // Breadcrumb leaf is the order number.
        $response->assertSee('<span class="x-crumb__part x-crumb__part--current">'.$order->order_id.'</span>', false);
    }

    public function test_the_page_carries_every_order_figure(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->viewer())->get('/lazada/orders/'.$order->order_id);

        $response->assertOk();

        // Identity and stamps
        $response->assertSee($order->order_id);
        $response->assertSee('To arrange shipment');   // ChannelStatusTone label for `packed`
        $response->assertSee('2026-08-03 10:11');
        $response->assertSee('2026-08-06 16:20');

        // Item line: two Lazada rows of one SKU consolidate to one line of
        // qty 2, with both order item ids kept.
        $response->assertSee('Meridian Ceramic Pour-Over Dripper');
        $response->assertSee('SKU-LZ-01');
        $response->assertSee('Slate, 02 size');
        $response->assertSee('https://lzd-img.test/dripper.jpg', false);
        $response->assertSee('900001, 900002');
        $response->assertSee(\App\Support\Money::foreign(1700.00, 'PHP'));   // unit price
        $response->assertSee(\App\Support\Money::foreign(3240.00, 'PHP'));   // paid total

        // Per-item price breakdown
        $response->assertSee('Price breakdown by order item (2)');
        $response->assertSee('Voucher (seller)');
        $response->assertSee(\App\Support\Money::foreign(1620.00, 'PHP'));

        // Customer and delivery address
        $response->assertSee('Maria Santos');
        $response->assertSee('+639175550123');
        $response->assertSee('221 Rizal Avenue, Barangay Bagumbayan');
        $response->assertSee('Unit 4B, Sunrise Residences');
        $response->assertSee('Quezon City Metro Manila 1101 Philippines');

        // Shipping
        $response->assertSee('LEX PH');
        $response->assertSee('LZDPH884120394');

        // Fee ledger, both sides of the rule and the estimate
        $response->assertSee('Subtotal (item prices)');
        $response->assertSee(\App\Support\Money::foreign(3400.00, 'PHP'));
        $response->assertSee('Paid total');
        $response->assertSee('Commission');
        $response->assertSee('Payment fee');
        $response->assertSee('Shipping service cost');
        $response->assertSee('Shipping discount (seller)');
        $response->assertSee('Sponsored Product Fee');
        $response->assertSee('Estimated net');
        // 3240 - 162 - 64.80 - 95 - 18.50, the previous page's own arithmetic.
        $response->assertSee(\App\Support\Money::foreign(2899.70, 'PHP'));

        // Fee transactions
        $response->assertSee('Lazada platform fees');
        $response->assertSee('Fee type 112');
        $response->assertSee('2026-08-05');

        // Raw payload disclosure
        $response->assertSee('Show the JSON Lazada returned');
    }

    public function test_the_page_renders_its_empty_states_without_items_or_fees(): void
    {
        $order = LazadaOrder::create([
            'region' => 'ph',
            'order_id' => 'LZ-EMPTY-000001',
            'status' => 'pending',
            'raw' => ['order_number' => 'LZ-EMPTY-000001', 'statuses' => ['pending']],
        ]);

        $response = $this->actingAs($this->viewer())->get('/lazada/orders/'.$order->order_id);

        $response->assertOk();
        $response->assertSee('No items synced yet. Items will be fetched on the next order sync.');
        $response->assertSee('No fee data yet.');
        // A missing figure says so rather than printing a number nobody has.
        $response->assertSee('Not recorded');
        // No fee block means no transaction section and no breakdown.
        $response->assertDontSee('Lazada platform fees');
        $response->assertDontSee('Price breakdown by order item');
    }

    // ------------------------------------------------------------------
    // Tier split, both directions, against the real middleware
    // ------------------------------------------------------------------

    public function test_write_tier_controls_render_only_for_the_manage_tier(): void
    {
        $order = $this->order();
        $refreshUrl = route('ext.lazada.orders.show', ['orderId' => $order->order_id, 'refresh' => 1]);

        $asManager = $this->actingAs($this->manager())->get('/lazada/orders/'.$order->order_id);
        $asManager->assertOk();
        $asManager->assertSee($refreshUrl, false);
        $asManager->assertSee('Refresh from Lazada');

        $asViewer = $this->actingAs($this->viewer())->get('/lazada/orders/'.$order->order_id);
        $asViewer->assertOk();
        $asViewer->assertDontSee($refreshUrl, false);
        $asViewer->assertDontSee('Refresh from Lazada');

        // The read tier loses controls, not information: every figure the
        // manager sees is still on their page.
        $asViewer->assertSee('Meridian Ceramic Pour-Over Dripper');
        $asViewer->assertSee('Estimated net');
        $asViewer->assertSee('LZDPH884120394');
    }

    public function test_the_waybill_and_tracking_are_read_tier_controls(): void
    {
        $order = $this->order();

        // Both routes sit in the read tier of extensions/lazada/routes/web.php
        // (a stored PDF and a courier lookup change nothing), so the viewer
        // keeps them. That is the previous page's behaviour too: it offered
        // both to everyone.
        $response = $this->actingAs($this->viewer())->get('/lazada/orders/'.$order->order_id);

        $response->assertSee(route('ext.lazada.orders.awb', ['orderId' => $order->order_id]), false);
        $response->assertSee('btnLzLogistics', false);
        $response->assertSee(route('ext.lazada.orders.logistics_trace', ['orderId' => $order->order_id]), false);
    }

    public function test_the_read_tier_is_refused_by_the_real_middleware_on_the_rts_post(): void
    {
        $order = $this->order();

        $denied = $this->actingAs($this->viewer())
            ->postJson('/lazada/orders/'.$order->order_id.'/rts');

        $denied->assertStatus(403);
        $denied->assertJson(['error' => 'permission_denied']);
    }

    public function test_the_manage_tier_passes_the_middleware_on_the_same_post(): void
    {
        $order = $this->order();

        // No credentials are configured in the test schema, so the controller
        // redirects back with its own "Missing Lazada credentials" result.
        // That is the point: the response comes from the CONTROLLER, which
        // means the gate let this user through, where the read tier above
        // never reached it.
        $allowed = $this->actingAs($this->manager())
            ->postJson('/lazada/orders/'.$order->order_id.'/rts');

        $allowed->assertStatus(302);
        $allowed->assertSessionHas('lazada_orders_last_result.ok', false);
    }

    // ------------------------------------------------------------------
    // Standing constraints this page must not break
    // ------------------------------------------------------------------

    public function test_the_page_uses_no_native_dialogs_and_no_inline_handlers(): void
    {
        $order = $this->order('delivered', ['order_id' => 'LZ-DETAIL-0002']);

        $response = $this->actingAs($this->manager())->get('/lazada/orders/'.$order->order_id);

        $response->assertOk();
        $response->assertDontSee('confirm(', false);
        $response->assertDontSee('alert(', false);
        $response->assertDontSee('onerror=', false);
        $response->assertDontSee('onclick=', false);
        // No page-level inline script: the tracking flow is the lazada-orders
        // module's, bound on this page's own root id.
        $response->assertSee('id="lazada-order-detail-page"', false);
    }

    public function test_the_band_link_on_the_orders_list_now_navigates_in_place(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->viewer())->get('/lazada/orders');

        $response->assertOk();
        // The stopgap is retired: the order number opens the detail page in
        // this same workspace, so the sidebar and the filtered list survive.
        $response->assertSee(
            '<a class="co-sn" href="'.route('ext.lazada.orders.show', ['orderId' => $order->order_id]).'">'.$order->order_id.'</a>',
            false
        );
    }
}
