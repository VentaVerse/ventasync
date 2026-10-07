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

class TiktokOrderDetailTest extends TestCase
{
    use RefreshDatabase;

    private TikTokSetting $tiktokStore;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');

        $this->app->register(TiktokExtension::class);

        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->tiktokStore = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
    }

    private function viewer(): User
    {
        $group = UserGroup::create(['name' => 'TikTok Detail Viewers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_tiktok/dashboard', 'view_tiktok/settings', 'view_tiktok/product_group', 'view_tiktok/order'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'TikTok Detail Managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_tiktok/dashboard', 'view_tiktok/settings', 'view_tiktok/product_group', 'manage_tiktok/order'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

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

    public function test_the_detail_page_renders_inside_the_workspace_for_the_read_tier(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->viewer())->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders/'.$order->id.'/show');

        $response->assertOk();

        $response->assertSee('x-chnav', false);
        $response->assertSee('All channels');
        $response->assertSee('data-nav', false);
        $response->assertDontSee('All marketplaces');

        $response->assertSee('class="od-page"', false);
        $response->assertDontSee('x-content x-legacy', false);

        $response->assertSee('<span class="x-crumb__part x-crumb__part--current">'.$order->order_id.'</span>', false);
    }

    public function test_the_page_carries_every_order_figure(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->viewer())->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders/'.$order->id.'/show');

        $response->assertOk();

        $response->assertSee($order->order_id);
        $response->assertSee('To hand over');
        $response->assertSee('2026-08-04 08:30');
        $response->assertSee('2026-08-06 19:05');

        $response->assertSee('Halcyon Linen Throw Blanket');
        $response->assertSee('SKU-TT-01');
        $response->assertSee('Oatmeal / Large');
        $response->assertSee('https://tt-img.test/blanket.jpg', false);
        $response->assertSee(\App\Support\Money::foreign(1299.00, 'PHP'));
        $response->assertSee(\App\Support\Money::foreign(1199.00, 'PHP'));
        $response->assertSee('Halcyon Linen Cushion Cover');
        $response->assertDontSee('<span class="co-item__var">blank</span>', false);

        $response->assertSee('k***n');
        $response->assertSee('Karen Lim');
        $response->assertSee('(+63)9175559988');
        $response->assertSee('88 Katipunan Avenue, Barangay Loyola Heights');
        $response->assertSee('Quezon City Metro Manila 1108 PH');

        $response->assertSee('Flash Express');
        $response->assertSee('TTPH5581209934');

        $response->assertSee('Order total');
        $response->assertSee(\App\Support\Money::foreign(1899.00, 'PHP'));
        $response->assertSee('Seller discount');
        $response->assertSee(\App\Support\Money::foreign(150.00, 'PHP'));

        $response->assertSee('Total revenue');
        $response->assertSee('Commission');
        $response->assertSee(\App\Support\Money::foreign(94.95, 'PHP'));
        $response->assertSee('Transaction fee');
        $response->assertSee('Total fees');
        $response->assertSee(\App\Support\Money::foreign(210.93, 'PHP'));
        $response->assertSee('Settlement');
        $response->assertSee(\App\Support\Money::foreign(1628.07, 'PHP'));
        $response->assertSee('Paid');
        $response->assertSee('Paid out Aug 07, 2026');

        $response->assertSee('Show the JSON TikTok returned');
    }

    public function test_the_page_renders_its_empty_states_without_items_or_fees(): void
    {
        $order = TikTokOrder::create([
            'order_id' => 'TT0000000000001',
            'status' => 'UNPAID',
            'raw' => ['id' => 'TT0000000000001', 'status' => 'UNPAID'],
        ]);

        $response = $this->actingAs($this->viewer())->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders/'.$order->id.'/show');

        $response->assertOk();
        $response->assertSee('No items synced yet.');
        $response->assertSee('No payment figures from TikTok yet.');
        $response->assertSee('TikTok has not settled this order yet.');
        $response->assertSee('Not recorded');
    }

    public function test_write_tier_controls_render_only_for_the_manage_tier(): void
    {
        $order = $this->order();
        $refreshUrl = route('ext.tiktok.orders.show', ['id' => $order->id, 'refresh' => 1]);

        $asManager = $this->actingAs($this->manager())->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders/'.$order->id.'/show');
        $asManager->assertOk();
        $asManager->assertSee($refreshUrl, false);
        $asManager->assertSee('Refresh from TikTok');

        $asViewer = $this->actingAs($this->viewer())->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders/'.$order->id.'/show');
        $asViewer->assertOk();
        $asViewer->assertDontSee($refreshUrl, false);
        $asViewer->assertDontSee('Refresh from TikTok');

        $asViewer->assertSee('Halcyon Linen Throw Blanket');
        $asViewer->assertSee('Settlement');
        $asViewer->assertSee('TTPH5581209934');
    }

    public function test_waybill_and_tracking_appear_only_once_there_is_a_parcel(): void
    {
        $withParcel = $this->order('AWAITING_COLLECTION');
        $noParcel = $this->order('AWAITING_SHIPMENT', ['order_id' => 'TT7788990011224']);

        $viewer = $this->viewer();

        $seen = $this->actingAs($viewer)->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders/'.$withParcel->id.'/show');
        $seen->assertSee(route('ext.tiktok.orders.awb', $withParcel->id), false);
        $seen->assertSee('btnTtTracking', false);
        $seen->assertSee(route('ext.tiktok.orders.tracking', $withParcel->id), false);

        $unseen = $this->actingAs($viewer)->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders/'.$noParcel->id.'/show');
        $unseen->assertDontSee(route('ext.tiktok.orders.awb', $noParcel->id), false);
        $unseen->assertDontSee('btnTtTracking', false);
    }

    public function test_the_read_tier_is_refused_by_the_real_middleware_on_the_ship_post(): void
    {
        $order = $this->order();

        $denied = $this->actingAs($this->viewer())
            ->postJson('/channels/tiktok/'.$this->tiktokStore->id.'/orders/'.$order->id.'/ship');

        $denied->assertStatus(403);
        $denied->assertJson(['error' => 'permission_denied']);
    }

    public function test_the_manage_tier_passes_the_middleware_on_the_same_post(): void
    {
        $order = $this->order();

        $allowed = $this->actingAs($this->manager())
            ->postJson('/channels/tiktok/'.$this->tiktokStore->id.'/orders/'.$order->id.'/ship');

        $allowed->assertStatus(302);
        $allowed->assertSessionHas('tiktok_orders_last_result.ok', false);
    }

    public function test_view_tier_get_with_refresh_param_pulls_nothing_and_writes_nothing(): void
    {
        TikTokSetting::query()->orderBy('id')->firstOrFail()->fill([
            'mode' => 'live',
            'app_key' => 'test-app-key',
            'app_secret' => 'test-app-secret',
            'access_token' => 'test-access-token',
            'shop_cipher' => 'test-shop-cipher',
        ])->save();
        $order = $this->order('AWAITING_COLLECTION');
        $rawBefore = $order->raw;
        $statusBefore = $order->status;

        Http::fake();

        $response = $this->actingAs($this->viewer())
            ->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders/'.$order->id.'/show?refresh=1');

        $response->assertOk();
        Http::assertNothingSent();
        $fresh = $order->fresh();
        $this->assertSame($statusBefore, $fresh->status);
        $this->assertSame($rawBefore, $fresh->raw);
    }

    public function test_manage_tier_get_with_refresh_param_still_pulls_and_persists(): void
    {
        TikTokSetting::query()->orderBy('id')->firstOrFail()->fill([
            'mode' => 'live',
            'app_key' => 'test-app-key',
            'app_secret' => 'test-app-secret',
            'access_token' => 'test-access-token',
            'shop_cipher' => 'test-shop-cipher',
        ])->save();
        $order = $this->order('AWAITING_COLLECTION', ['order_id' => 'TT7788990011299']);

        Http::fake([
            '*/order/202507/orders*' => Http::response([
                'code' => 0,
                'message' => 'Success',
                'data' => ['orders' => [[
                    'id' => 'TT7788990011299',
                    'status' => 'AWAITING_SHIPMENT',
                    'recipient_address' => ['name' => 'Karen Lim'],
                ]]],
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $response = $this->actingAs($this->manager())
            ->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders/'.$order->id.'/show?refresh=1');

        $response->assertOk();
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/order/202507/orders?')
            && str_contains($request->url(), 'ids=TT7788990011299'));
        Http::assertSentCount(1);
        $this->assertSame('AWAITING_SHIPMENT', $order->fresh()->status);
        $this->assertSame('Karen Lim', $order->fresh()->buyer_name);
    }

    public function test_a_refusal_in_the_body_leaves_the_order_and_says_so(): void
    {
        TikTokSetting::query()->orderBy('id')->firstOrFail()->fill([
            'mode' => 'live',
            'app_key' => 'test-app-key',
            'app_secret' => 'test-app-secret',
            'access_token' => 'test-access-token',
            'shop_cipher' => 'test-shop-cipher',
        ])->save();
        $order = $this->order('AWAITING_COLLECTION', ['order_id' => 'TT7788990011300']);

        Http::fake(['*' => Http::response(['code' => 36009004, 'message' => 'Invalid order id.', 'data' => (object) []], 200)]);

        $this->actingAs($this->manager())
            ->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders/'.$order->id.'/show?refresh=1')
            ->assertOk()
            ->assertViewHas('api_error', true);

        $this->assertSame('AWAITING_COLLECTION', $order->fresh()->status);
    }

    public function test_the_page_uses_no_native_dialogs_and_no_inline_handlers(): void
    {
        $order = $this->order('COMPLETED', ['order_id' => 'TT7788990011225']);

        $response = $this->actingAs($this->manager())->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders/'.$order->id.'/show');

        $response->assertOk();
        $response->assertDontSee('confirm(', false);
        $response->assertDontSee('alert(', false);
        $response->assertDontSee('onerror=', false);
        $response->assertDontSee('onclick=', false);
        $response->assertSee('id="tiktok-order-detail-page"', false);
    }

    public function test_the_band_link_on_the_orders_list_now_navigates_in_place(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->viewer())->get('/channels/tiktok/'.$this->tiktokStore->id.'/orders?tab=ALL');

        $response->assertOk();
        $response->assertSee(
            '<a class="co-sn" href="'.route('ext.tiktok.orders.show', $order->id).'">'.$order->order_id.'</a>',
            false
        );
    }
}
