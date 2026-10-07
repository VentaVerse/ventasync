<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\LazadaExtension;
use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaOrderProduct;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LazadaOrderDetailTest extends TestCase
{
    use RefreshDatabase;

    private LazadaSetting $lazadaStore;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');

        $this->app->register(LazadaExtension::class);

        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->lazadaStore = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
    }

    private function viewer(): User
    {
        $group = UserGroup::create(['name' => 'Lazada Detail Viewers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_lazada/dashboard', 'view_lazada/settings', 'view_lazada/product', 'view_lazada/brand', 'view_lazada/category', 'view_lazada/category_attribute', 'view_lazada/product_group', 'view_lazada/order'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Lazada Detail Managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_lazada/dashboard', 'view_lazada/settings', 'view_lazada/product', 'view_lazada/brand', 'view_lazada/category', 'view_lazada/category_attribute', 'view_lazada/product_group', 'manage_lazada/order'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

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

    public function test_the_detail_page_renders_inside_the_workspace_for_the_read_tier(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->viewer())->get('/channels/lazada/'.$this->lazadaStore->id.'/orders/'.$order->order_id);

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

        $response = $this->actingAs($this->viewer())->get('/channels/lazada/'.$this->lazadaStore->id.'/orders/'.$order->order_id);

        $response->assertOk();

        $response->assertSee($order->order_id);
        $response->assertSee('To arrange shipment');
        $response->assertSee('2026-08-03 10:11');
        $response->assertSee('2026-08-06 16:20');

        $response->assertSee('Meridian Ceramic Pour-Over Dripper');
        $response->assertSee('SKU-LZ-01');
        $response->assertSee('Slate, 02 size');
        $response->assertSee('https://lzd-img.test/dripper.jpg', false);
        $response->assertSee('900001, 900002');
        $response->assertSee(\App\Support\Money::foreign(1700.00, 'PHP'));
        $response->assertSee(\App\Support\Money::foreign(3240.00, 'PHP'));

        $response->assertSee('Price breakdown by order item (2)');
        $response->assertSee('Voucher (seller)');
        $response->assertSee(\App\Support\Money::foreign(1620.00, 'PHP'));

        $response->assertSee('Maria Santos');
        $response->assertSee('+639175550123');
        $response->assertSee('221 Rizal Avenue, Barangay Bagumbayan');
        $response->assertSee('Unit 4B, Sunrise Residences');
        $response->assertSee('Quezon City Metro Manila 1101 Philippines');

        $response->assertSee('LEX PH');
        $response->assertSee('LZDPH884120394');

        $response->assertSee('Order income');
        $response->assertSee('Commission');
        $response->assertSee(\App\Support\Money::foreign(-162.00, 'PHP'));
        $response->assertSee('Fee type 112');

        $response->assertSee('Lazada platform fees');
        $response->assertSee('Fee type 112');
        $response->assertSee('2026-08-05');

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

        $response = $this->actingAs($this->viewer())->get('/channels/lazada/'.$this->lazadaStore->id.'/orders/'.$order->order_id);

        $response->assertOk();
        $response->assertSee('No items synced yet. Items will be fetched on the next order sync.');
        $response->assertSee('Lazada has not settled this order yet.');
        $response->assertSee('Not recorded');
        $response->assertDontSee('Lazada platform fees');
        $response->assertDontSee('Price breakdown by order item');
    }

    public function test_a_reported_transaction_shows_the_breakdown_not_the_empty_state(): void
    {
        $order = LazadaOrder::create([
            'region' => 'ph',
            'order_id' => 'LZ-ARRAYFEES-01',
            'status' => 'packed',
            'raw' => ['order_number' => 'LZ-ARRAYFEES-01', 'address_shipping' => ['first_name' => 'Test']],
            'fees' => [
                'transaction_lines' => [
                    [
                        'fee_type' => 16,
                        'fee_name' => 'Commission',
                        'amount' => -50.00,
                        'sku' => 'SKU-1',
                        'order_item_id' => '1',
                        'transaction_number' => 'TX-1',
                        'transaction_date' => '2026-08-05',
                    ],
                ],
                'other_fees' => ['misc_fee' => -5.00],
            ],
        ]);

        LazadaOrderProduct::create([
            'lazada_order_id' => $order->id,
            'order_item_id' => 900001,
            'sku' => 'SKU-1',
            'name' => 'Test Product',
            'quantity' => 1,
            'item_price' => 500.00,
            'paid_price' => 480.00,
            'raw' => ['item_price' => 500.00, 'paid_price' => 480.00],
        ]);

        $response = $this->actingAs($this->viewer())->get('/channels/lazada/'.$this->lazadaStore->id.'/orders/'.$order->order_id);

        $response->assertOk();
        $response->assertSee('Order income');
        $response->assertSee(\App\Support\Money::foreign(-50.00, 'PHP'));
        $response->assertDontSee('Lazada has not settled this order yet.');
        $response->assertDontSee('Lazada platform fees');
        $response->assertDontSee('Price breakdown by order item');
    }

    public function test_write_tier_controls_render_only_for_the_manage_tier(): void
    {
        $order = $this->order();
        $refreshUrl = route('ext.lazada.orders.show', ['orderId' => $order->order_id, 'refresh' => 1]);

        $asManager = $this->actingAs($this->manager())->get('/channels/lazada/'.$this->lazadaStore->id.'/orders/'.$order->order_id);
        $asManager->assertOk();
        $asManager->assertSee($refreshUrl, false);
        $asManager->assertSee('Refresh from Lazada');

        $asViewer = $this->actingAs($this->viewer())->get('/channels/lazada/'.$this->lazadaStore->id.'/orders/'.$order->order_id);
        $asViewer->assertOk();
        $asViewer->assertDontSee($refreshUrl, false);
        $asViewer->assertDontSee('Refresh from Lazada');

        $asViewer->assertSee('Meridian Ceramic Pour-Over Dripper');
        $asViewer->assertSee('Order income');
        $asViewer->assertSee('LZDPH884120394');
    }

    public function test_the_waybill_and_tracking_are_read_tier_controls(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->viewer())->get('/channels/lazada/'.$this->lazadaStore->id.'/orders/'.$order->order_id);

        $response->assertSee(route('ext.lazada.orders.awb', ['orderId' => $order->order_id]), false);
        $response->assertSee('btnLzLogistics', false);
        $response->assertSee(route('ext.lazada.orders.logistics_trace', ['orderId' => $order->order_id]), false);
    }

    public function test_the_read_tier_is_refused_by_the_real_middleware_on_the_rts_post(): void
    {
        $order = $this->order();

        $denied = $this->actingAs($this->viewer())
            ->postJson('/channels/lazada/'.$this->lazadaStore->id.'/orders/'.$order->order_id.'/rts');

        $denied->assertStatus(403);
        $denied->assertJson(['error' => 'permission_denied']);
    }

    public function test_the_manage_tier_passes_the_middleware_on_the_same_post(): void
    {
        $order = $this->order();

        $allowed = $this->actingAs($this->manager())
            ->postJson('/channels/lazada/'.$this->lazadaStore->id.'/orders/'.$order->order_id.'/rts');

        $allowed->assertStatus(302);
        $allowed->assertSessionHas('lazada_orders_last_result.ok', false);
    }

    private function configureLazadaCredentials(): LazadaSetting
    {
        $s = LazadaSetting::query()->orderBy('id')->firstOrFail();
        $s->fill([
            'mode' => 'live',
            'region' => 'ph',
            'app_key' => 'test-app-key',
            'app_secret' => 'test-app-secret',
            'access_token' => 'test-access-token',
        ])->save();

        return $s;
    }

    private function thinOrder(string $orderId = 'LZ-THIN-0001'): LazadaOrder
    {
        return LazadaOrder::create([
            'region' => 'ph',
            'order_id' => $orderId,
            'status' => 'unpaid',
            'raw' => ['order_number' => $orderId],
        ]);
    }

    public function test_view_tier_plain_get_on_a_thin_order_pulls_nothing_and_writes_nothing(): void
    {
        $this->configureLazadaCredentials();
        $order = $this->thinOrder();

        Http::fake();

        $response = $this->actingAs($this->viewer())->get('/channels/lazada/'.$this->lazadaStore->id.'/orders/'.$order->order_id);

        $response->assertOk();
        Http::assertNothingSent();
        $fresh = $order->fresh();
        $this->assertSame(['order_number' => $order->order_id], $fresh->raw);
        $this->assertSame(0, $fresh->products()->count());
        $this->assertTrue(empty($fresh->fees));
    }

    public function test_manage_tier_plain_get_on_a_thin_order_still_pulls_and_persists(): void
    {
        $this->configureLazadaCredentials();
        $order = $this->thinOrder('LZ-THIN-0002');

        Http::fake([
            '*/order/get*' => Http::response([
                'data' => ['order' => ['statuses' => ['shipped'], 'address_shipping' => ['first_name' => 'Test']]],
            ], 200),
            '*/order/items/get*' => Http::response(['data' => []], 200),
            '*/finance/transaction/details/get*' => Http::response(['data' => []], 200),
            '*' => Http::response([], 200),
        ]);

        $response = $this->actingAs($this->manager())->get('/channels/lazada/'.$this->lazadaStore->id.'/orders/'.$order->order_id);

        $response->assertOk();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/order/get'));
        $this->assertArrayHasKey('_detail', $order->fresh()->raw);
    }

    public function test_the_page_uses_no_native_dialogs_and_no_inline_handlers(): void
    {
        $order = $this->order('delivered', ['order_id' => 'LZ-DETAIL-0002']);

        $response = $this->actingAs($this->manager())->get('/channels/lazada/'.$this->lazadaStore->id.'/orders/'.$order->order_id);

        $response->assertOk();
        $response->assertDontSee('confirm(', false);
        $response->assertDontSee('alert(', false);
        $response->assertDontSee('onerror=', false);
        $response->assertDontSee('onclick=', false);
        $response->assertSee('id="lazada-order-detail-page"', false);
    }

    public function test_the_band_link_on_the_orders_list_now_navigates_in_place(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->viewer())->get('/channels/lazada/'.$this->lazadaStore->id.'/orders');

        $response->assertOk();
        $response->assertSee(
            '<a class="co-sn" href="'.route('ext.lazada.orders.show', ['orderId' => $order->order_id]).'">'.$order->order_id.'</a>',
            false
        );
    }
}
