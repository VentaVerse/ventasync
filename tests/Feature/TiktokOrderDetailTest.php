<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Models\TikTokOrderProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\TiktokExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The TikTok order detail page, rebuilt on the shared `od-` detail anatomy
 * inside the seller center (Task 3 of the order-detail redesign).
 *
 * Fixture discipline is ShopeeOrderDetailTest's, which is ChannelWorkspace
 * Test's: this file builds its own permission groups and its own orders
 * rather than leaning on whatever the test schema happens to hold, and the
 * setUp below is that file's, for the same reasons its docblocks give (the
 * extension must be installed, enabled and REGISTERED, and the router's name
 * lookups rebuilt, before any TikTok route resolves by name).
 *
 * TikTokOrderController@show only calls the API when `?refresh=1` is asked
 * for, and no test here asks, so every figure asserted below comes from the
 * stored payload. That is deliberate - a test that could reach the network
 * proves nothing repeatable about a view.
 */
class TiktokOrderDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');

        $this->app->register(TiktokExtension::class);

        // Routes registered after the RouteCollection last built its name
        // lookup table are invisible to Route::has(), which ChannelWorkspace
        // calls for every sidebar destination. See ChannelWorkspaceTest.
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    /** Read tier: can open the page, holds nothing that writes. */
    private function viewer(): User
    {
        $group = UserGroup::create(['name' => 'TikTok Detail Viewers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_tiktok', 'view_tiktok_orders'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /** Manage tier: the refresh pull and the ship POST are gated on this. */
    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'TikTok Detail Managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_tiktok', 'manage_tiktok_orders'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * An order in the shape TikTokOrderController@show reads it. Two product
     * rows, the second carrying the variation string "blank" that this
     * channel treats as no variation at all.
     */
    private function order(string $status = 'AWAITING_COLLECTION', array $overrides = []): TikTokOrder
    {
        $orderId = $overrides['order_id'] ?? 'TT7788990011223';

        $order = TikTokOrder::create(array_merge([
            'order_id' => $orderId,
            'status' => $status,
            'buyer_name' => 'k***n',
            'order_created_at' => '2026-08-04 08:30:00',
            'order_updated_at' => '2026-08-06 19:05:00',
            'raw' => [
                'id' => $orderId,
                'status' => $status,
                'buyer_name' => 'k***n',
                'shipping_provider' => 'Flash Express',
                'tracking_number' => 'TTPH5581209934',
                'recipient_address' => [
                    'name' => 'Karen Lim',
                    'phone_number' => '(+63)9175559988',
                    'full_address' => '88 Katipunan Avenue, Barangay Loyola Heights',
                    'city' => 'Quezon City',
                    'state' => 'Metro Manila',
                    'zipcode' => '1108',
                    'region_code' => 'PH',
                ],
                'payment' => [
                    'currency' => 'PHP',
                    'total_amount' => '1899.00',
                    'shipping_fee' => '78.00',
                    'seller_discount' => '150.00',
                    'platform_discount' => '60.00',
                ],
            ],
            'fees' => [
                'revenue' => 1899.00,
                'commission' => -94.95,
                'transaction_fee' => -37.98,
                'shipping_fee' => -78.00,
                'platform_discount' => -60.00,
                'settlement_amount' => 1628.07,
            ],
            'payout_status' => 'Paid',
            'paid_at' => '2026-08-07 00:00:00',
        ], $overrides));

        TikTokOrderProduct::create([
            'tiktok_order_id' => $order->id,
            'order_line_item_id' => 'LI-1',
            'sku' => 'SKU-TT-01',
            'name' => 'Halcyon Linen Throw Blanket',
            'variation' => 'Oatmeal / Large',
            'quantity' => 1,
            'item_price' => 1299.00,
            'sale_price' => 1199.00,
            'image' => 'https://tt-img.test/blanket.jpg',
        ]);

        TikTokOrderProduct::create([
            'tiktok_order_id' => $order->id,
            'order_line_item_id' => 'LI-2',
            'sku' => 'SKU-TT-02',
            'name' => 'Halcyon Linen Cushion Cover',
            'variation' => 'blank',
            'quantity' => 2,
            'item_price' => 600.00,
            'sale_price' => 0.0,
            'image' => 'https://tt-img.test/cushion.jpg',
        ]);

        return $order->fresh('products');
    }

    // ------------------------------------------------------------------
    // The page renders inside the seller center
    // ------------------------------------------------------------------

    public function test_the_detail_page_renders_inside_the_workspace_for_the_read_tier(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->viewer())->get('/tiktok/orders/'.$order->id.'/show');

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

        $response = $this->actingAs($this->viewer())->get('/tiktok/orders/'.$order->id.'/show');

        $response->assertOk();

        // Identity and stamps
        $response->assertSee($order->order_id);
        $response->assertSee('To hand over');        // ChannelStatusTone label for AWAITING_COLLECTION
        $response->assertSee('2026-08-04 08:30');
        $response->assertSee('2026-08-06 19:05');

        // Item lines
        $response->assertSee('Halcyon Linen Throw Blanket');
        $response->assertSee('SKU-TT-01');
        $response->assertSee('Oatmeal / Large');
        $response->assertSee('https://tt-img.test/blanket.jpg', false);
        $response->assertSee(\App\Support\Money::foreign(1299.00, 'PHP'));
        $response->assertSee(\App\Support\Money::foreign(1199.00, 'PHP'));
        $response->assertSee('Halcyon Linen Cushion Cover');
        // "blank" is one of the strings this channel treats as no variation,
        // so the second item gets no variation chip. Matched as an element's
        // whole text rather than as a substring, because `target="_blank"` on
        // the waybill link contains those five letters too.
        $response->assertDontSee('<span class="co-item__var">blank</span>', false);

        // Customer and delivery address
        $response->assertSee('k***n');
        $response->assertSee('Karen Lim');
        $response->assertSee('(+63)9175559988');
        $response->assertSee('88 Katipunan Avenue, Barangay Loyola Heights');
        $response->assertSee('Quezon City Metro Manila 1108 PH');

        // Shipping
        $response->assertSee('Flash Express');
        $response->assertSee('TTPH5581209934');

        // Payment
        $response->assertSee('Order total');
        $response->assertSee(\App\Support\Money::foreign(1899.00, 'PHP'));
        $response->assertSee('Seller discount');
        $response->assertSee(\App\Support\Money::foreign(150.00, 'PHP'));

        // Fees and settlement
        $response->assertSee('Revenue');
        $response->assertSee('Commission');
        $response->assertSee(\App\Support\Money::foreign(94.95, 'PHP'));
        $response->assertSee('Transaction fee');
        $response->assertSee('Total fees');
        $response->assertSee(\App\Support\Money::foreign(210.93, 'PHP'));
        $response->assertSee('Settlement');
        $response->assertSee(\App\Support\Money::foreign(1628.07, 'PHP'));
        $response->assertSee('Paid');
        $response->assertSee('Paid out Aug 07, 2026');

        // Raw payload disclosure
        $response->assertSee('Show the JSON TikTok returned');
    }

    public function test_the_page_renders_its_empty_states_without_items_or_fees(): void
    {
        $order = TikTokOrder::create([
            'order_id' => 'TT0000000000001',
            'status' => 'UNPAID',
            'raw' => ['id' => 'TT0000000000001', 'status' => 'UNPAID'],
        ]);

        $response = $this->actingAs($this->viewer())->get('/tiktok/orders/'.$order->id.'/show');

        $response->assertOk();
        $response->assertSee('No items synced yet.');
        $response->assertSee('No payment figures from TikTok yet.');
        $response->assertSee('No fee data yet.');
        // A missing figure says so rather than printing a number nobody has.
        $response->assertSee('Not recorded');
    }

    // ------------------------------------------------------------------
    // Tier split, both directions, against the real middleware
    // ------------------------------------------------------------------

    public function test_write_tier_controls_render_only_for_the_manage_tier(): void
    {
        $order = $this->order();
        $refreshUrl = route('ext.tiktok.orders.show', ['id' => $order->id, 'refresh' => 1]);

        $asManager = $this->actingAs($this->manager())->get('/tiktok/orders/'.$order->id.'/show');
        $asManager->assertOk();
        $asManager->assertSee($refreshUrl, false);
        $asManager->assertSee('Refresh from TikTok');

        $asViewer = $this->actingAs($this->viewer())->get('/tiktok/orders/'.$order->id.'/show');
        $asViewer->assertOk();
        $asViewer->assertDontSee($refreshUrl, false);
        $asViewer->assertDontSee('Refresh from TikTok');

        // The read tier loses controls, not information: every figure the
        // manager sees is still on their page.
        $asViewer->assertSee('Halcyon Linen Throw Blanket');
        $asViewer->assertSee('Settlement');
        $asViewer->assertSee('TTPH5581209934');
    }

    public function test_waybill_and_tracking_appear_only_once_there_is_a_parcel(): void
    {
        $withParcel = $this->order('AWAITING_COLLECTION');
        $noParcel = $this->order('AWAITING_SHIPMENT', ['order_id' => 'TT7788990011224']);

        $viewer = $this->viewer();

        // Both are read-tier routes, so the read tier keeps them.
        $seen = $this->actingAs($viewer)->get('/tiktok/orders/'.$withParcel->id.'/show');
        $seen->assertSee(route('ext.tiktok.orders.awb', $withParcel->id), false);
        $seen->assertSee('btnTtTracking', false);
        $seen->assertSee(route('ext.tiktok.orders.tracking', $withParcel->id), false);

        $unseen = $this->actingAs($viewer)->get('/tiktok/orders/'.$noParcel->id.'/show');
        $unseen->assertDontSee(route('ext.tiktok.orders.awb', $noParcel->id), false);
        $unseen->assertDontSee('btnTtTracking', false);
    }

    public function test_the_read_tier_is_refused_by_the_real_middleware_on_the_ship_post(): void
    {
        $order = $this->order();

        $denied = $this->actingAs($this->viewer())
            ->postJson('/tiktok/orders/'.$order->id.'/ship');

        $denied->assertStatus(403);
        $denied->assertJson(['error' => 'permission_denied']);
    }

    public function test_the_manage_tier_passes_the_middleware_on_the_same_post(): void
    {
        $order = $this->order();

        // A settings row with no credentials in it: enough for the
        // controller's own creds() precondition to pass (it 404s when the row
        // is absent entirely), not enough for any network call to be made.
        \Extensions\tiktok\Models\TikTokSetting::create(['mode' => 'sandbox']);

        // The fixture's payload carries no `packages` node, so the controller
        // redirects back with its own "No packages found" result before it
        // ever reaches the API client. That is the point: the response comes
        // from the CONTROLLER, which means the gate let this user through,
        // where the read tier above never reached it.
        $allowed = $this->actingAs($this->manager())
            ->postJson('/tiktok/orders/'.$order->id.'/ship');

        $allowed->assertStatus(302);
        $allowed->assertSessionHas('tiktok_orders_last_result.ok', false);
    }

    // ------------------------------------------------------------------
    // F2: the refresh pull-and-persist path is manage-tier only. The write
    // branch in TikTokOrderController@show must never run for a view-tier
    // operator, even with `?refresh=1` on the URL.
    // ------------------------------------------------------------------

    public function test_view_tier_get_with_refresh_param_pulls_nothing_and_writes_nothing(): void
    {
        TikTokSetting::create([
            'mode' => 'live',
            'app_key' => 'test-app-key',
            'app_secret' => 'test-app-secret',
            'access_token' => 'test-access-token',
            'shop_cipher' => 'test-shop-cipher',
        ]);
        $order = $this->order('AWAITING_COLLECTION');
        $rawBefore = $order->raw;
        $statusBefore = $order->status;

        Http::fake();

        $response = $this->actingAs($this->viewer())
            ->get('/tiktok/orders/'.$order->id.'/show?refresh=1');

        $response->assertOk();
        Http::assertNothingSent();
        $fresh = $order->fresh();
        $this->assertSame($statusBefore, $fresh->status);
        $this->assertSame($rawBefore, $fresh->raw);
    }

    public function test_manage_tier_get_with_refresh_param_still_pulls_and_persists(): void
    {
        TikTokSetting::create([
            'mode' => 'live',
            'app_key' => 'test-app-key',
            'app_secret' => 'test-app-secret',
            'access_token' => 'test-access-token',
            'shop_cipher' => 'test-shop-cipher',
        ]);
        $order = $this->order('AWAITING_COLLECTION', ['order_id' => 'TT7788990011299']);

        Http::fake([
            '*/order/202309/orders*' => Http::response([
                'data' => ['orders' => [[
                    'status' => 'AWAITING_SHIPMENT',
                    'recipient_address' => ['name' => 'Karen Lim'],
                ]]],
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $response = $this->actingAs($this->manager())
            ->get('/tiktok/orders/'.$order->id.'/show?refresh=1');

        $response->assertOk();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/order/202309/orders'));
        $this->assertSame('AWAITING_SHIPMENT', $order->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Standing constraints this page must not break
    // ------------------------------------------------------------------

    public function test_the_page_uses_no_native_dialogs_and_no_inline_handlers(): void
    {
        $order = $this->order('COMPLETED', ['order_id' => 'TT7788990011225']);

        $response = $this->actingAs($this->manager())->get('/tiktok/orders/'.$order->id.'/show');

        $response->assertOk();
        $response->assertDontSee('confirm(', false);
        $response->assertDontSee('alert(', false);
        $response->assertDontSee('onerror=', false);
        $response->assertDontSee('onclick=', false);
        // No page-level inline script: the tracking flow is the tiktok-orders
        // module's, bound on this page's own root id.
        $response->assertSee('id="tiktok-order-detail-page"', false);
    }

    public function test_the_band_link_on_the_orders_list_now_navigates_in_place(): void
    {
        $order = $this->order();

        // The list defaults to the To Ship tab's to_pack step, which is
        // AWAITING_SHIPMENT only; this fixture is AWAITING_COLLECTION, so the
        // All tab is what lists it.
        $response = $this->actingAs($this->viewer())->get('/tiktok/orders?tab=ALL');

        $response->assertOk();
        // The stopgap is retired: the order number opens the detail page in
        // this same workspace, so the sidebar and the filtered list survive.
        $response->assertSee(
            '<a class="co-sn" href="'.route('ext.tiktok.orders.show', $order->id).'">'.$order->order_id.'</a>',
            false
        );
    }
}
