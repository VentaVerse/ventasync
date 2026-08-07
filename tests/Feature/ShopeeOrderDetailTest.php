<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeOrderProduct;
use Extensions\shopee\Models\ShopeeReturn;
use Extensions\shopee\ShopeeExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Shopee order detail page, rebuilt on the shared `od-` detail anatomy
 * inside the seller center (Task 2 of the order-detail redesign).
 *
 * Fixture discipline is ChannelWorkspaceTest's: this file builds its own
 * permission groups and its own orders rather than leaning on whatever the
 * test schema happens to hold, and the setUp below is that file's, for the
 * same reasons its docblocks give (the extension must be installed, enabled
 * and REGISTERED, and the router's name lookups rebuilt, before any Shopee
 * route resolves by name).
 *
 * No fixture here carries Shopee credentials, so ShopeeOrderController@show
 * never calls the live API: `$hasCreds` is false, the refresh and escrow
 * pulls are both skipped, and every figure asserted below comes from the
 * stored payload. That is deliberate - a test that could reach the network
 * proves nothing repeatable about a view.
 */
class ShopeeOrderDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');

        $this->app->register(ShopeeExtension::class);

        // Routes registered after the RouteCollection last built its name
        // lookup table are invisible to Route::has(), which ChannelWorkspace
        // calls for every sidebar destination. See ChannelWorkspaceTest.
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    /** Read tier: can open the page, holds nothing that writes. */
    private function viewer(): User
    {
        $group = UserGroup::create(['name' => 'Shopee Detail Viewers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_shopee', 'view_shopee_orders'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /** Manage tier: the ship POST and the refresh pull are gated on this. */
    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Shopee Detail Managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_shopee', 'manage_shopee_orders'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * An order in the shape ShopeeOrderController@show reads it: `region`
     * 'ph' because with no ShopeeSetting row activeAuth() returns a null
     * region and the controller falls back to 'ph'.
     */
    private function order(string $status = 'READY_TO_SHIP', array $overrides = []): ShopeeOrder
    {
        $order = ShopeeOrder::create(array_merge([
            'region' => 'ph',
            'order_sn' => '250807ABCDEF01',
            'status' => $status,
            'order_created_at' => '2026-08-05 09:15:00',
            'order_updated_at' => '2026-08-06 14:40:00',
            'raw' => [
                'order_status' => $status,
                'buyer_username' => 'juan_dela_cruz',
                'currency' => 'PHP',
                'total_amount' => 2480.00,
                'payment_method' => 'Cash on Delivery',
                'shipping_carrier' => 'J&T Express',
                'tracking_no' => 'SPXPH0392841',
                'recipient_address' => [
                    'name' => 'Juan Dela Cruz',
                    'phone' => '+639171234567',
                    'full_address' => '14 Mabini Street, Barangay Poblacion',
                    'city' => 'Makati',
                    'state' => 'Metro Manila',
                    'zipcode' => '1210',
                    'country' => 'PH',
                ],
            ],
            'fees' => [
                'original_price' => 2600.00,
                'buyer_total_amount' => 2480.00,
                'seller_discount' => 120.00,
                'buyer_paid_shipping_fee' => 80.00,
                'commission_fee' => 124.00,
                'service_fee' => 49.60,
                'seller_transaction_fee' => 24.80,
                'escrow_amount' => 2201.60,
            ],
            'buyer_invoice' => [
                'is_requested' => true,
                'name' => 'Dela Cruz Trading',
                'type' => 'business',
                'tin' => '123-456-789-000',
                'email' => 'billing@delacruz.test',
                'phone' => '+63288881234',
                'address' => ['full' => '14 Mabini Street, Makati'],
            ],
        ], $overrides));

        ShopeeOrderProduct::create([
            'shopee_order_id' => $order->id,
            'item_id' => 88001,
            'model_id' => 99001,
            'sku' => 'SKU-ALPHA-01',
            'name' => 'Alpha Wireless Keyboard',
            'variation' => 'Graphite, US layout',
            'quantity' => 2,
            'price' => 1240.00,
            'image' => 'https://cf.shopee.ph/file/alpha-keyboard',
        ]);

        return $order->fresh('products');
    }

    // ------------------------------------------------------------------
    // The page renders inside the seller center
    // ------------------------------------------------------------------

    public function test_the_detail_page_renders_inside_the_workspace_for_the_read_tier(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->viewer())->get('/shopee/orders/'.$order->order_sn);

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
        $response->assertSee('<span class="x-crumb__part x-crumb__part--current">'.$order->order_sn.'</span>', false);
    }

    public function test_the_page_carries_every_order_figure(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->viewer())->get('/shopee/orders/'.$order->order_sn);

        $response->assertOk();

        // Identity and stamps
        $response->assertSee($order->order_sn);
        $response->assertSee('Ready to ship');       // ChannelStatusTone label for READY_TO_SHIP
        $response->assertSee('2026-08-05 09:15');
        $response->assertSee('2026-08-06 14:40');

        // Item line
        $response->assertSee('Alpha Wireless Keyboard');
        $response->assertSee('SKU-ALPHA-01');
        $response->assertSee('Graphite, US layout');
        $response->assertSee('https://cf.shopee.ph/file/alpha-keyboard', false);
        $response->assertSee(\App\Support\Money::foreign(1240.00, 'PHP'));

        // Customer and delivery address
        $response->assertSee('juan_dela_cruz');
        $response->assertSee('Juan Dela Cruz');
        $response->assertSee('+639171234567');
        $response->assertSee('14 Mabini Street, Barangay Poblacion');
        $response->assertSee('Makati Metro Manila 1210 PH');

        // Shipping
        $response->assertSee('J&amp;T Express', false);
        $response->assertSee('SPXPH0392841');

        // Payment facts, the same two the fulfilment list prints
        $response->assertSee(\App\Support\Money::foreign(2480.00, 'PHP'));
        $response->assertSee('Cash on Delivery');

        // Escrow block, both sides of the rule and the net figure
        $response->assertSee('Original price');
        $response->assertSee(\App\Support\Money::foreign(2600.00, 'PHP'));
        $response->assertSee('Seller discount');
        $response->assertSee('Commission fee');
        $response->assertSee('Service fee');
        $response->assertSee('Transaction fee');
        $response->assertSee('Net to seller');
        $response->assertSee(\App\Support\Money::foreign(2201.60, 'PHP'));

        // Invoice request
        $response->assertSee('Requested by buyer');
        $response->assertSee('Dela Cruz Trading');
        $response->assertSee('123-456-789-000');
        $response->assertSee('billing@delacruz.test');

        // Raw payload disclosure
        $response->assertSee('Show the JSON Shopee returned');
    }

    public function test_the_page_renders_its_empty_states_without_items_fees_or_invoice(): void
    {
        $order = ShopeeOrder::create([
            'region' => 'ph',
            'order_sn' => '250807EMPTY001',
            'status' => 'UNPAID',
            'raw' => ['order_status' => 'UNPAID'],
        ]);

        $response = $this->actingAs($this->viewer())->get('/shopee/orders/'.$order->order_sn);

        $response->assertOk();
        $response->assertSee('No items synced yet. Items will be fetched on the next order sync.');
        $response->assertSee('No escrow or fee data yet.');
        $response->assertSee('Invoice information not available from Shopee.');
        // A missing figure says so rather than printing a number nobody has.
        $response->assertSee('Not recorded');
    }

    public function test_a_return_renders_with_its_items_and_negotiation_history(): void
    {
        $order = $this->order('COMPLETED');

        ShopeeReturn::create([
            'region' => 'ph',
            'return_sn' => '2608070RETURN1',
            'order_sn' => $order->order_sn,
            'shopee_order_id' => $order->id,
            'status' => 'REQUESTED',
            'reason' => 'ITEM_WRONG',
            'reason_text' => 'Buyer received the wrong layout.',
            'refund_amount' => 1240.00,
            'currency' => 'PHP',
            'items' => [
                ['name' => 'Alpha Wireless Keyboard', 'quantity' => 1, 'item_price' => 1240.00],
            ],
            'negotiation' => [
                ['role' => 'BUYER', 'create_time' => 1754553600, 'offer_amount' => 1240.00, 'reason' => 'Wrong item sent'],
            ],
            'return_created_at' => '2026-08-07 08:00:00',
            'return_updated_at' => '2026-08-07 09:00:00',
        ]);

        $response = $this->actingAs($this->viewer())->get('/shopee/orders/'.$order->order_sn);

        $response->assertOk();
        $response->assertSee('Returns and refunds');
        $response->assertSee('2608070RETURN1');
        $response->assertSee('ITEM_WRONG');
        $response->assertSee('Buyer received the wrong layout.');
        $response->assertSee(\App\Support\Money::foreign(1240.00, 'PHP'));
        $response->assertSee('Negotiation history (1)');
        $response->assertSee('BUYER');
        $response->assertSee('Wrong item sent');
    }

    // ------------------------------------------------------------------
    // Tier split, both directions, against the real middleware
    // ------------------------------------------------------------------

    public function test_write_tier_controls_render_only_for_the_manage_tier(): void
    {
        $order = $this->order('READY_TO_SHIP');
        $refreshUrl = route('ext.shopee.orders.show', ['orderSn' => $order->order_sn, 'refresh' => 1]);

        $asManager = $this->actingAs($this->manager())->get('/shopee/orders/'.$order->order_sn);
        $asManager->assertOk();
        $asManager->assertSee($refreshUrl, false);
        $asManager->assertSee('btnArrangeShipment', false);
        $asManager->assertSee('data-order-sn="'.$order->order_sn.'"', false);

        $asViewer = $this->actingAs($this->viewer())->get('/shopee/orders/'.$order->order_sn);
        $asViewer->assertOk();
        $asViewer->assertDontSee($refreshUrl, false);
        $asViewer->assertDontSee('btnArrangeShipment', false);

        // The read tier loses controls, not information: every figure the
        // manager sees is still on their page.
        $asViewer->assertSee('Alpha Wireless Keyboard');
        $asViewer->assertSee('Net to seller');
        $asViewer->assertSee('SPXPH0392841');
    }

    public function test_arrange_shipment_appears_only_on_a_to_pack_order(): void
    {
        $toPack = $this->order('READY_TO_SHIP');
        $shipped = $this->order('SHIPPED', ['order_sn' => '250807SHIPPED1']);

        $manager = $this->manager();

        $this->actingAs($manager)->get('/shopee/orders/'.$toPack->order_sn)
            ->assertSee('btnArrangeShipment', false);

        $this->actingAs($manager)->get('/shopee/orders/'.$shipped->order_sn)
            ->assertDontSee('btnArrangeShipment', false);
    }

    public function test_waybill_and_tracking_appear_only_once_there_is_a_parcel(): void
    {
        $toPack = $this->order('READY_TO_SHIP');
        $shipped = $this->order('SHIPPED', ['order_sn' => '250807SHIPPED2']);

        $viewer = $this->viewer();

        // Both are read-tier routes, so the read tier keeps them.
        $withParcel = $this->actingAs($viewer)->get('/shopee/orders/'.$shipped->order_sn);
        $withParcel->assertSee(route('ext.shopee.orders.awb', ['orderSn' => $shipped->order_sn]), false);
        $withParcel->assertSee('btnShopeeTracking', false);
        $withParcel->assertSee(route('ext.shopee.orders.tracking_info', ['orderSn' => $shipped->order_sn]), false);

        $noParcel = $this->actingAs($viewer)->get('/shopee/orders/'.$toPack->order_sn);
        $noParcel->assertDontSee(route('ext.shopee.orders.awb', ['orderSn' => $toPack->order_sn]), false);
        $noParcel->assertDontSee('btnShopeeTracking', false);
    }

    public function test_the_read_tier_is_refused_by_the_real_middleware_on_the_ship_post(): void
    {
        $order = $this->order('READY_TO_SHIP');

        // The ship modal posts with Accept: application/json, so a denial
        // arrives as the middleware's JSON envelope rather than a redirect.
        $denied = $this->actingAs($this->viewer())
            ->postJson('/shopee/orders/'.$order->order_sn.'/ship', ['shipping_type' => 'dropoff']);

        $denied->assertStatus(403);
        $denied->assertJson(['error' => 'permission_denied']);
    }

    public function test_the_manage_tier_passes_the_middleware_on_the_same_post(): void
    {
        $order = $this->order('READY_TO_SHIP');

        // No credentials are configured in the test schema, so the
        // controller's own precondition answers 422. That is the point: the
        // response comes from the CONTROLLER, which means the gate let this
        // user through, where the read tier above never reached it.
        $allowed = $this->actingAs($this->manager())
            ->postJson('/shopee/orders/'.$order->order_sn.'/ship', ['shipping_type' => 'dropoff']);

        $allowed->assertStatus(422);
        $allowed->assertJson(['ok' => false]);
    }

    // ------------------------------------------------------------------
    // Standing constraints this page must not break
    // ------------------------------------------------------------------

    public function test_the_page_uses_no_native_dialogs_and_no_inline_handlers(): void
    {
        $order = $this->order('COMPLETED');

        $response = $this->actingAs($this->manager())->get('/shopee/orders/'.$order->order_sn);

        $response->assertOk();
        $response->assertDontSee('confirm(', false);
        $response->assertDontSee('alert(', false);
        $response->assertDontSee('onerror=', false);
        $response->assertDontSee('onclick=', false);
        // No page-level inline script: the ship and tracking flows are the
        // shopee-orders module's, bound on this page's own root id.
        $response->assertSee('id="shopee-order-detail-page"', false);
    }

    public function test_the_band_link_on_the_orders_list_now_navigates_in_place(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->viewer())->get('/shopee/orders');

        $response->assertOk();
        // The stopgap is retired: the order number opens the detail page in
        // this same workspace, so the sidebar and the filtered list survive.
        $response->assertSee(
            '<a class="co-sn" href="'.route('ext.shopee.orders.show', ['orderSn' => $order->order_sn]).'">'.$order->order_sn.'</a>',
            false
        );
    }
}
