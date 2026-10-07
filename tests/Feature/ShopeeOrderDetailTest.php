<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeOrderProduct;
use Extensions\shopee\Models\ShopeeReturn;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\ShopeeExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

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

        ShopeeSetting::create(['mode' => 'production', 'store_name' => 'Main store']);

        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function viewer(): User
    {
        $group = UserGroup::create(['name' => 'Shopee Detail Viewers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_shopee/dashboard', 'view_shopee/settings', 'view_shopee/category', 'view_shopee/category_attribute', 'view_shopee/logistics', 'view_shopee/product_group', 'view_shopee/product', 'view_shopee/order'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Shopee Detail Managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_shopee/dashboard', 'view_shopee/settings', 'view_shopee/category', 'view_shopee/category_attribute', 'view_shopee/logistics', 'view_shopee/product_group', 'view_shopee/product', 'manage_shopee/order'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

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

    public function test_the_detail_page_renders_inside_the_workspace_for_the_read_tier(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->viewer())->get(route('ext.shopee.orders.show', $order->order_sn));

        $response->assertOk();

        $response->assertSee('x-chnav', false);
        $response->assertSee('All channels');
        $response->assertSee('data-nav', false);
        $response->assertDontSee('All marketplaces');

        $response->assertSee('class="od-page"', false);
        $response->assertDontSee('x-content x-legacy', false);

        $response->assertSee('<span class="x-crumb__part x-crumb__part--current">'.$order->order_sn.'</span>', false);
    }

    public function test_the_page_carries_every_order_figure(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->viewer())->get(route('ext.shopee.orders.show', $order->order_sn));

        $response->assertOk();

        $response->assertSee($order->order_sn);
        $response->assertSee('Ready to ship');
        $response->assertSee('2026-08-05 09:15');
        $response->assertSee('2026-08-06 14:40');

        $response->assertSee('Alpha Wireless Keyboard');
        $response->assertSee('SKU-ALPHA-01');
        $response->assertSee('Graphite, US layout');
        $response->assertSee('https://cf.shopee.ph/file/alpha-keyboard', false);
        $response->assertSee(\App\Support\Money::foreign(1240.00, 'PHP'));

        $response->assertSee('juan_dela_cruz');
        $response->assertSee('Juan Dela Cruz');
        $response->assertSee('+639171234567');
        $response->assertSee('14 Mabini Street, Barangay Poblacion');
        $response->assertSee('Makati Metro Manila 1210 PH');

        $response->assertSee('J&amp;T Express', false);
        $response->assertSee('SPXPH0392841');

        $response->assertSee(\App\Support\Money::foreign(2480.00, 'PHP'));
        $response->assertSee('Cash on Delivery');

        $response->assertSee('Order income');
        $response->assertSee('Merchandise Subtotal');
        $response->assertSee('Commission Fee');
        $response->assertSee('Service Fee');
        $response->assertSee('Transaction Fee');
        $response->assertSee('Estimated Order Income');
        $response->assertSee(\App\Support\Money::foreign(2201.60, 'PHP'));

        $response->assertSee('Requested by buyer');
        $response->assertSee('Dela Cruz Trading');
        $response->assertSee('123-456-789-000');
        $response->assertSee('billing@delacruz.test');

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

        $response = $this->actingAs($this->viewer())->get(route('ext.shopee.orders.show', $order->order_sn));

        $response->assertOk();
        $response->assertSee('No items synced yet. Items will be fetched on the next order sync.');
        $response->assertSee('Shopee has not settled this order yet.');
        $response->assertSee('Not available from Shopee');
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

        $response = $this->actingAs($this->viewer())->get(route('ext.shopee.orders.show', $order->order_sn));

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

    public function test_write_tier_controls_render_only_for_the_manage_tier(): void
    {
        $order = $this->order('READY_TO_SHIP');
        $refreshUrl = route('ext.shopee.orders.show', ['orderSn' => $order->order_sn, 'refresh' => 1]);

        $asManager = $this->actingAs($this->manager())->get(route('ext.shopee.orders.show', $order->order_sn));
        $asManager->assertOk();
        $asManager->assertSee($refreshUrl, false);
        $asManager->assertSee('btnArrangeShipment', false);
        $asManager->assertSee('data-order-sn="'.$order->order_sn.'"', false);

        $asViewer = $this->actingAs($this->viewer())->get(route('ext.shopee.orders.show', $order->order_sn));
        $asViewer->assertOk();
        $asViewer->assertDontSee($refreshUrl, false);
        $asViewer->assertDontSee('btnArrangeShipment', false);

        $asViewer->assertSee('Alpha Wireless Keyboard');
        $asViewer->assertSee('Estimated Order Income');
        $asViewer->assertSee('SPXPH0392841');
    }

    public function test_arrange_shipment_appears_only_on_a_to_pack_order(): void
    {
        $toPack = $this->order('READY_TO_SHIP');
        $shipped = $this->order('SHIPPED', ['order_sn' => '250807SHIPPED1']);

        $manager = $this->manager();

        $this->actingAs($manager)->get(route('ext.shopee.orders.show', $toPack->order_sn))
            ->assertSee('btnArrangeShipment', false);

        $this->actingAs($manager)->get(route('ext.shopee.orders.show', $shipped->order_sn))
            ->assertDontSee('btnArrangeShipment', false);
    }

    public function test_waybill_and_tracking_appear_only_once_there_is_a_parcel(): void
    {
        $toPack = $this->order('READY_TO_SHIP');
        $shipped = $this->order('SHIPPED', ['order_sn' => '250807SHIPPED2']);

        $viewer = $this->viewer();

        $withParcel = $this->actingAs($viewer)->get(route('ext.shopee.orders.show', $shipped->order_sn));
        $withParcel->assertSee(route('ext.shopee.orders.awb', ['orderSn' => $shipped->order_sn]), false);
        $withParcel->assertSee('btnShopeeTracking', false);
        $withParcel->assertSee(route('ext.shopee.orders.tracking_info', ['orderSn' => $shipped->order_sn]), false);

        $noParcel = $this->actingAs($viewer)->get(route('ext.shopee.orders.show', $toPack->order_sn));
        $noParcel->assertDontSee(route('ext.shopee.orders.awb', ['orderSn' => $toPack->order_sn]), false);
        $noParcel->assertDontSee('btnShopeeTracking', false);
    }

    public function test_the_read_tier_is_refused_by_the_real_middleware_on_the_ship_post(): void
    {
        $order = $this->order('READY_TO_SHIP');

        $denied = $this->actingAs($this->viewer())
            ->postJson(route('ext.shopee.orders.ship', $order->order_sn), ['shipping_type' => 'dropoff']);

        $denied->assertStatus(403);
        $denied->assertJson(['error' => 'permission_denied']);
    }

    public function test_the_manage_tier_passes_the_middleware_on_the_same_post(): void
    {
        $order = $this->order('READY_TO_SHIP');

        $allowed = $this->actingAs($this->manager())
            ->postJson(route('ext.shopee.orders.ship', $order->order_sn), ['shipping_type' => 'dropoff']);

        $allowed->assertStatus(422);
        $allowed->assertJson(['ok' => false]);
    }

    private function configureShopeeCredentials(): ShopeeSetting
    {
        $s = ShopeeSetting::query()->orderBy('id')->firstOrNew();
        $s->fill([
            'mode' => 'production',
            'partner_id' => 100001,
            'partner_key' => 'test-partner-key',
            'shop_id' => 200002,
            'access_token' => 'test-access-token',
            'region' => 'ph',
        ])->save();

        return $s;
    }

    public function test_view_tier_get_with_refresh_param_pulls_nothing_and_writes_nothing(): void
    {
        $this->configureShopeeCredentials();
        $order = $this->order('READY_TO_SHIP');
        $rawBefore = $order->raw;
        $apiLogCountBefore = ShopeeApiLog::count();
        $orderCountBefore = ShopeeOrder::count();
        $productCountBefore = ShopeeOrderProduct::count();

        Http::fake();

        $response = $this->actingAs($this->viewer())
            ->get(route('ext.shopee.orders.show', $order->order_sn).'?refresh=1');

        $response->assertOk();
        Http::assertNothingSent();
        $this->assertSame($apiLogCountBefore, ShopeeApiLog::count());
        $this->assertSame($orderCountBefore, ShopeeOrder::count());
        $this->assertSame($productCountBefore, ShopeeOrderProduct::count());
        $this->assertSame($rawBefore, $order->fresh()->raw);
    }

    public function test_manage_tier_get_with_refresh_param_still_pulls_and_persists(): void
    {
        $this->configureShopeeCredentials();
        $order = $this->order('READY_TO_SHIP');

        Http::fake([
            '*get_order_detail*' => Http::response([
                'response' => [
                    'order_list' => [[
                        'order_status' => 'SHIPPED',
                        'tracking_no' => 'SPXPH9999999',
                        'item_list' => [],
                    ]],
                ],
            ], 200),
            '*get_escrow_detail*' => Http::response(['response' => []], 200),
            '*' => Http::response([], 200),
        ]);

        $response = $this->actingAs($this->manager())
            ->get(route('ext.shopee.orders.show', $order->order_sn).'?refresh=1');

        $response->assertOk();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'get_order_detail'));
        $this->assertSame('SHIPPED', $order->fresh()->status);
    }

    public function test_the_page_uses_no_native_dialogs_and_no_inline_handlers(): void
    {
        $order = $this->order('COMPLETED');

        $response = $this->actingAs($this->manager())->get(route('ext.shopee.orders.show', $order->order_sn));

        $response->assertOk();
        $response->assertDontSee('confirm(', false);
        $response->assertDontSee('alert(', false);
        $response->assertDontSee('onerror=', false);
        $response->assertDontSee('onclick=', false);
        $response->assertSee('id="shopee-order-detail-page"', false);
    }

    public function test_the_band_link_on_the_orders_list_now_navigates_in_place(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->viewer())->get(route('ext.shopee.orders.index'));

        $response->assertOk();
        $response->assertSee(
            '<a class="co-sn" href="'.route('ext.shopee.orders.show', ['orderSn' => $order->order_sn]).'">'.$order->order_sn.'</a>',
            false
        );
    }
}
