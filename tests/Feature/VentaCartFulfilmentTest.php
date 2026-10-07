<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\ventacart\Models\VentaCartOrder;
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Services\VentaCart\VentaCartClient;
use Extensions\ventacart\Services\VentaCart\VentaCartOrderSync;
use Extensions\ventacart\VentaCartExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VentaCartFulfilmentTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://one.ventacart.test';

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('ventacart');
        $manager->enable('ventacart');

        $this->app->register(VentaCartExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();

        Http::preventStrayRequests();

        Http::fake([self::BASE . '/api/v1/order-statuses' => fn () => Http::response(['data' => $this->statusRows])]);
    }

    private array $statusRows = [];

    protected function tearDown(): void
    {
        $dir = storage_path('app/ventacart-awb');
        if (is_dir($dir)) {
            foreach (glob($dir . '/*/*.pdf') ?: [] as $f) {
                @unlink($f);
            }
        }

        parent::tearDown();
    }

    private array $users = [];

    private function userWith(array $keys): User
    {
        $name = 'VentaCart fulfilment ' . implode('-', $keys);

        if (! isset($this->users[$name])) {
            $group = UserGroup::create(['name' => $name]);
            $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());
            $this->users[$name] = User::factory()->create(['user_group_id' => $group->id]);
        }

        return $this->users[$name];
    }

    protected function setUpTraits()
    {
        $this->users = [];

        return parent::setUpTraits();
    }

    private function packer(): User
    {
        return $this->userWith(['view_ventacart/order', 'manage_ventacart/order']);
    }

    private function viewer(): User
    {
        return $this->userWith(['view_ventacart/order']);
    }

    private function store(): VentaCartSetting
    {
        return VentaCartSetting::create([
            'store_name' => 'Gear Depot',
            'base_url' => self::BASE,
            'api_token' => 'ventacart-token-one',
            'enabled' => true,
        ]);
    }

    private function order(VentaCartSetting $store, string $status, array $extra = []): VentaCartOrder
    {
        return VentaCartOrder::create(array_merge([
            'ventacart_setting_id' => $store->id,
            'ventacart_order_id' => 700001,
            'ventacart_order_number' => 700001,
            'status' => $status,
            'status_id' => 35,
            'customer_name' => 'Depot Buyer',
            'total' => 500,
            'order_created_at' => now()->subHour(),
        ], $extra));
    }

    private function orderPayload(string $status, array $courier = null): array
    {
        $raw = [
            'id' => 700001,
            'order_number' => 700001,
            'status' => $status,
            'status_id' => 23,
            'totals' => ['total' => 500],
            'items' => [],
            'contact' => ['name' => 'Depot Buyer', 'email' => 'buyer@example.test'],
        ];
        if ($courier !== null) {
            $raw['courier'] = $courier;
        }

        return $raw;
    }

    public function test_booking_records_the_courier_and_moves_the_row_to_handover_at_once(): void
    {
        $store = $this->store();
        $order = $this->order($store, 'Order Processed');

        Http::fake([
            self::BASE . '/api/v1/orders/700001/book-courier' => Http::response([
                'success' => true, 'already_booked' => false, 'order_id' => 700001,
                'courier' => 'spx', 'tracking_number' => 'SPX0001', 'status' => 'booked',
            ]),
            self::BASE . '/api/v1/orders/700001' => Http::response($this->orderPayload('Order Being Shipped')),
        ]);

        $response = $this->actingAs($this->packer())
            ->postJson(route('ext.ventacart.orders.book', [$store->id, $order->id]), ['courier' => 'spx', 'insure' => true, 'weight_kg' => '1.5']);

        $response->assertOk()
            ->assertJson(['ok' => true, 'courier' => 'spx', 'tracking_number' => 'SPX0001'])
            ->assertJsonPath('awb_url', route('ext.ventacart.orders.awb', [$store->id, $order->id]));

        $order->refresh();
        $this->assertSame('spx', $order->courier_provider);
        $this->assertSame('SPX0001', $order->courier_tracking_number);
        $this->assertSame('booked', $order->courier_status);
        $this->assertNotNull($order->courier_booked_at);
        $this->assertSame('Order Being Shipped', $order->status);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/book-courier')) {
                return false;
            }
            $body = $request->data();

            return $body['courier'] === 'spx' && $body['insure'] === true && $body['weight_kg'] === '1.5'
                && ! array_key_exists('length_cm', $body) && ! array_key_exists('service', $body)
                && $request->hasHeader('Authorization', 'Bearer ventacart-token-one');
        });
    }

    public function test_a_refusal_comes_back_in_the_storefronts_own_words_and_changes_nothing(): void
    {
        $store = $this->store();
        $order = $this->order($store, 'Order Processed');

        Http::fake([
            self::BASE . '/api/v1/orders/700001/book-courier' => Http::response(['error' => 'Selected courier is not available', 'code' => 'courier_unavailable'], 409),
        ]);

        $this->actingAs($this->packer())
            ->postJson(route('ext.ventacart.orders.book', [$store->id, $order->id]), ['courier' => 'lbc'])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'Selected courier is not available']);

        $order->refresh();
        $this->assertNull($order->courier_tracking_number);
        $this->assertSame('Order Processed', $order->status);
    }

    public function test_the_view_tier_cannot_book_or_cancel(): void
    {
        $store = $this->store();
        $order = $this->order($store, 'Order Processed');
        Http::fake();

        $this->actingAs($this->viewer())
            ->postJson(route('ext.ventacart.orders.book', [$store->id, $order->id]), ['courier' => 'spx'])
            ->assertForbidden();

        $this->actingAs($this->viewer())
            ->post(route('ext.ventacart.orders.cancel_booking', [$store->id, $order->id]))
            ->assertRedirect()
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_cancelling_clears_the_booking_and_the_row_returns_to_pack(): void
    {
        $store = $this->store();
        $order = $this->order($store, 'Order Being Shipped', [
            'courier_provider' => 'spx', 'courier_tracking_number' => 'SPX0001', 'courier_status' => 'booked', 'courier_booked_at' => now(),
        ]);

        Http::fake([
            self::BASE . '/api/v1/orders/700001/cancel-booking' => Http::response(['success' => true, 'order_id' => 700001, 'message' => 'Booking cancelled.']),
            self::BASE . '/api/v1/orders/700001' => Http::response($this->orderPayload('Order Processed')),
        ]);

        $this->actingAs($this->packer())
            ->from(route('ext.ventacart.orders.index', $store->id))
            ->post(route('ext.ventacart.orders.cancel_booking', [$store->id, $order->id]))
            ->assertRedirect(route('ext.ventacart.orders.index', $store->id))
            ->assertSessionHas('status', 'Booking cancelled.');

        $order->refresh();
        $this->assertNull($order->courier_provider);
        $this->assertNull($order->courier_tracking_number);
        $this->assertSame('Order Processed', $order->status);
    }

    public function test_the_waybill_is_fetched_once_and_served_from_the_cache_after(): void
    {
        $store = $this->store();
        $order = $this->order($store, 'Order Being Shipped', ['courier_provider' => 'spx', 'courier_tracking_number' => 'SPX0001']);

        Http::fake([
            self::BASE . '/api/v1/orders/700001/label*' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $this->actingAs($this->packer())
            ->getJson(route('ext.ventacart.orders.awb', [$store->id, $order->id]))
            ->assertOk()
            ->assertJson(['ok' => true, 'ready' => true]);

        $response = $this->actingAs($this->packer())->get(route('ext.ventacart.orders.awb', [$store->id, $order->id]));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));

        Http::assertSentCount(1);
    }

    public function test_the_waybill_follows_a_url_when_the_storefront_hands_one_back(): void
    {
        $store = $this->store();
        $order = $this->order($store, 'Order Being Shipped', ['courier_provider' => 'quadx', 'courier_tracking_number' => 'QX0001']);

        Http::fake([
            self::BASE . '/api/v1/orders/700001/label*' => Http::response(['success' => true, 'order_id' => 700001, 'url' => 'https://labels.quadx.test/QX0001.pdf']),
            'https://labels.quadx.test/QX0001.pdf' => Http::response('%PDF-1.4 quadx', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $this->actingAs($this->viewer())
            ->get(route('ext.ventacart.orders.awb', [$store->id, $order->id]))
            ->assertOk();
    }

    public function test_an_order_the_erp_has_no_booking_for_is_still_asked_about_at_the_store(): void
    {
        $store = $this->store();
        $order = $this->order($store, 'Order Processed');

        Http::fake([
            self::BASE . '/api/v1/orders/700001/label*' => Http::response(['error' => 'No courier booking to print'], 422),
        ]);

        $this->actingAs($this->packer())
            ->getJson(route('ext.ventacart.orders.awb', [$store->id, $order->id]))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'ready' => false, 'message' => 'No courier booking to print']);

        Http::assertSentCount(1);
    }

    public function test_serviceability_and_tracking_pass_the_storefronts_answer_through(): void
    {
        $store = $this->store();
        $order = $this->order($store, 'Order Being Shipped', ['courier_provider' => 'spx', 'courier_tracking_number' => 'SPX0001']);

        Http::fake([
            self::BASE . '/api/v1/orders/700001/serviceability' => Http::response([
                'success' => true, 'order_id' => 700001, 'all_refused' => false,
                'couriers' => [
                    'spx' => ['status' => 'serviceable', 'kind' => null, 'reason' => null, 'fees' => ['box' => 85.0], 'currency' => 'PHP', 'from' => 85.0],
                    'quadx' => ['status' => 'not_serviceable', 'kind' => 'destination', 'reason' => 'Out of coverage', 'fees' => [], 'currency' => 'PHP', 'from' => null],
                ],
            ]),
            self::BASE . '/api/v1/orders/700001/tracking' => Http::response([
                'success' => true, 'order_id' => 700001, 'courier' => 'spx', 'tracking_number' => 'SPX0001', 'status' => 'in_transit',
                'timeline' => [['status' => 'picked_up', 'at' => 1756300000], ['status' => 'in_transit', 'at' => 1756350000]],
                'links' => ['delivery' => 'https://spx.test/track/SPX0001'],
            ]),
        ]);

        $this->actingAs($this->viewer())
            ->getJson(route('ext.ventacart.orders.serviceability', [$store->id, $order->id]))
            ->assertOk()
            ->assertJsonPath('couriers.spx.from', 85)
            ->assertJsonPath('couriers.quadx.kind', 'destination');

        $this->actingAs($this->viewer())
            ->getJson(route('ext.ventacart.orders.tracking', [$store->id, $order->id]))
            ->assertOk()
            ->assertJsonPath('status', 'in_transit')
            ->assertJsonCount(2, 'timeline');

        $this->assertSame('in_transit', $order->fresh()->courier_status);
    }

    public function test_the_row_offers_the_steps_own_verbs(): void
    {
        $store = $this->store();
        $toPack = $this->order($store, 'Order Processed');
        $booked = VentaCartOrder::create([
            'ventacart_setting_id' => $store->id, 'ventacart_order_id' => 700002, 'ventacart_order_number' => 700002,
            'status' => 'Order Being Shipped', 'status_id' => 23, 'customer_name' => 'Shelf Buyer', 'total' => 10,
            'order_created_at' => now(), 'courier_provider' => 'spx', 'courier_tracking_number' => 'SPX0002',
        ]);
        Http::fake();

        $pack = $this->actingAs($this->packer())
            ->get(route('ext.ventacart.orders.index', [$store->id, 'tab' => 'TO_SHIP', 'pending_sub' => 'to_pack']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('btnVentaBook', $pack);
        $this->assertStringContainsString('data-parcel-for=', $pack);
        $this->assertStringContainsString('id="vtBookParcel"', $pack);
        $this->assertStringContainsString(route('ext.ventacart.orders.book', [$store->id, $toPack->id]), $pack);
        $this->assertStringNotContainsString('Cancel booking', $pack);
        $this->assertStringContainsString('id="vtLoadingOverlay" class="modal-backdrop" data-busy-overlay', $pack);
        $this->assertStringContainsString('data-busy="Fetching orders"', $pack);

        $handover = $this->actingAs($this->packer())
            ->get(route('ext.ventacart.orders.index', [$store->id, 'tab' => 'TO_SHIP', 'pending_sub' => 'to_handover']))
            ->assertOk()->getContent();

        $this->assertStringContainsString(route('ext.ventacart.orders.awb', [$store->id, $booked->id]), $handover);
        $this->assertStringContainsString('btnVentaTracking', $handover);
        $this->assertStringContainsString('data-busy="Cancelling the booking"', $handover);
        $this->assertStringContainsString('SPX Express', $handover);
        $this->assertStringContainsString('SPX0002', $handover);
        $this->assertStringNotContainsString('btnVentaBook', $handover);

        $viewer = $this->actingAs($this->viewer())
            ->get(route('ext.ventacart.orders.index', [$store->id, 'tab' => 'TO_SHIP', 'pending_sub' => 'to_handover']))
            ->assertOk()->getContent();

        $this->assertStringContainsString(route('ext.ventacart.orders.awb', [$store->id, $booked->id]), $viewer);
        $this->assertStringNotContainsString('Cancel booking', $viewer);
        $this->assertStringNotContainsString('vtBookModal', $viewer);
    }

    public function test_a_shipment_by_hand_is_recorded_with_its_couriers_name_and_moves_the_row(): void
    {
        $store = $this->store();
        $order = $this->order($store, 'Order Processed');

        Http::fake([
            self::BASE . '/api/v1/shipping-couriers*' => Http::response(['data' => [
                ['id' => 3, 'name' => 'LBC Branch', 'tracking_url' => null, 'is_active' => true],
                ['id' => 4, 'name' => 'Retired courier', 'tracking_url' => null, 'is_active' => false],
            ]]),
            self::BASE . '/api/v1/orders/700001/book-manual' => Http::response([
                'success' => true, 'order_id' => 700001,
                'courier' => ['manual' => true, 'manual_courier' => 'LBC Branch', 'tracking_number' => 'LBC-77'],
            ]),
            self::BASE . '/api/v1/orders/700001' => Http::response($this->orderPayload('Order Being Shipped')),
        ]);

        $this->actingAs($this->packer())
            ->getJson(route('ext.ventacart.orders.shipping_couriers', $store->id))
            ->assertOk()
            ->assertJsonCount(1, 'couriers')
            ->assertJsonPath('couriers.0.name', 'LBC Branch');

        $this->actingAs($this->packer())
            ->postJson(route('ext.ventacart.orders.book_manual', [$store->id, $order->id]), [
                'shipping_courier_id' => 3, 'courier_name' => 'LBC Branch', 'tracking_number' => 'LBC-77', 'comment' => 'Rider took it at 3pm',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'courier_name' => 'LBC Branch', 'tracking_number' => 'LBC-77']);

        $order->refresh();
        $this->assertTrue($order->isManualShipment());
        $this->assertSame('LBC Branch', $order->courier_name);
        $this->assertSame('LBC-77', $order->courier_tracking_number);
        $this->assertSame('Order Being Shipped', $order->status);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/book-manual')) {
                return false;
            }
            $body = $request->data();

            return $body['shipping_courier_id'] === 3 && $body['tracking_number'] === 'LBC-77'
                && $body['comment'] === 'Rider took it at 3pm' && ! array_key_exists('courier_name', $body);
        });

        $html = $this->actingAs($this->packer())
            ->get(route('ext.ventacart.orders.index', [$store->id, 'tab' => 'TO_SHIP', 'pending_sub' => 'to_handover']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('LBC Branch', $html);
        $this->assertStringContainsString('LBC-77', $html);
        $this->assertStringContainsString('Clear shipment', $html);
        $this->assertStringNotContainsString('Print waybill', $html);
        $this->assertStringNotContainsString('btnVentaTracking', $html);
        $this->assertStringNotContainsString('Cancel booking', $html);
    }

    public function test_clearing_a_shipment_by_hand_returns_the_row_to_pack(): void
    {
        $store = $this->store();
        $order = $this->order($store, 'Order Being Shipped', [
            'courier_provider' => 'manual', 'courier_name' => 'LBC Branch', 'courier_tracking_number' => 'LBC-77', 'courier_status' => 'manual', 'courier_booked_at' => now(),
        ]);

        Http::fake([
            self::BASE . '/api/v1/orders/700001/clear-manual' => Http::response(['success' => true, 'message' => 'Shipment cleared.']),
            self::BASE . '/api/v1/orders/700001' => Http::response($this->orderPayload('Order Processed')),
        ]);

        $this->actingAs($this->packer())
            ->post(route('ext.ventacart.orders.clear_manual', [$store->id, $order->id]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Shipment cleared.');

        $order->refresh();
        $this->assertNull($order->courier_provider);
        $this->assertNull($order->courier_name);
        $this->assertSame('Order Processed', $order->status);
    }

    public function test_a_pull_files_the_storefronts_own_manual_shipment_under_its_couriers_name(): void
    {
        $store = $this->store();
        $this->order($store, 'Order Processed');

        Http::fake([
            self::BASE . '/api/v1/orders/700001' => Http::response($this->orderPayload('Order Being Shipped', [
                'provider' => null, 'tracking_number' => 'JRS-1', 'status' => null, 'booked_at' => null, 'manual' => true, 'manual_courier' => 'JRS Express',
            ])),
        ]);

        $row = (new VentaCartOrderSync(new VentaCartClient($store), $store))->pullOne(700001);

        $this->assertTrue($row->isManualShipment());
        $this->assertSame('JRS Express', $row->courier_name);
        $this->assertSame('JRS-1', $row->courier_tracking_number);
    }

    public function test_serviceability_carries_each_couriers_choices_and_defaults_cash_on_pickup_for_quadx(): void
    {
        $store = $this->store();
        $order = $this->order($store, 'Order Processed');

        Http::fake([
            self::BASE . '/api/v1/orders/700001/serviceability' => Http::response([
                'success' => true, 'order_id' => 700001, 'all_refused' => false,
                'couriers' => ['quadx' => ['status' => 'serviceable', 'kind' => null, 'reason' => null, 'fees' => ['box' => 85.0], 'currency' => 'PHP', 'from' => 85.0]],
            ]),
        ]);

        $json = $this->actingAs($this->packer())
            ->getJson(route('ext.ventacart.orders.serviceability', [$store->id, $order->id]))
            ->assertOk()
            ->assertJsonPath('couriers.quadx.name', 'QuadX')
            ->assertJsonPath('couriers.quadx.defaults.shipping_payment', 'cash')
            ->assertJsonPath('couriers.quadx.defaults.service', 'next_day')
            ->json();

        $this->assertSame(['same_day_pickup', 'next_day'], array_column($json['couriers']['quadx']['service_types'], 'value'));
        $this->assertContains('box', array_column($json['couriers']['quadx']['parcel_options'], 'value'));
        $this->assertSame('cash', $json['couriers']['quadx']['shipping_payment_options'][0]['value']);
    }

    public function test_the_storefronts_own_lists_win_over_the_erps_copy(): void
    {
        $store = $this->store();
        $order = $this->order($store, 'Order Processed');

        Http::fake([
            self::BASE . '/api/v1/orders/700001/serviceability' => Http::response([
                'success' => true, 'all_refused' => false,
                'couriers' => ['spx' => [
                    'status' => 'serviceable', 'fees' => [], 'currency' => 'PHP', 'from' => null,
                    'name' => 'SPX', 'parcel_options' => [['value' => 'preset-a', 'label' => 'Small box product group']],
                    'defaults' => ['parcel' => 'preset-a'],
                ]],
            ]),
        ]);

        $this->actingAs($this->packer())
            ->getJson(route('ext.ventacart.orders.serviceability', [$store->id, $order->id]))
            ->assertOk()
            ->assertJsonPath('couriers.spx.name', 'SPX')
            ->assertJsonPath('couriers.spx.parcel_options.0.value', 'preset-a')
            ->assertJsonPath('couriers.spx.defaults.parcel', 'preset-a')
            ->assertJsonPath('couriers.spx.defaults.service', 'standard');
    }

    public function test_pickup_addresses_come_default_first_and_an_absent_endpoint_is_an_empty_list(): void
    {
        $store = $this->store();

        Http::fake([
            self::BASE . '/api/v1/pickup-addresses' => Http::response(['data' => [
                ['id' => 2, 'label' => 'Warehouse B', 'address_1' => '12 Depot Rd', 'city' => 'Pasig', 'is_default' => false],
                ['id' => 1, 'label' => 'Main store', 'address_1' => '1 Shop St', 'city' => 'Makati', 'is_default' => true],
            ]]),
        ]);

        $this->actingAs($this->packer())
            ->getJson(route('ext.ventacart.orders.pickup_addresses', $store->id))
            ->assertOk()
            ->assertJsonPath('addresses.0.id', 1)
            ->assertJsonPath('addresses.0.is_default', true)
            ->assertJsonPath('addresses.1.summary', '12 Depot Rd, Pasig');

    }

    public function test_a_store_without_the_addresses_endpoint_offers_no_choice(): void
    {
        $store = $this->store();
        Http::fake([self::BASE . '/api/v1/pickup-addresses' => Http::response(['error' => 'Not found'], 404)]);

        $json = $this->actingAs($this->packer())
            ->getJson(route('ext.ventacart.orders.pickup_addresses', $store->id))
            ->assertOk()
            ->json();

        $this->assertFalse($json['ok']);
        $this->assertSame([], $json['addresses']);
    }

    public function test_a_booking_carries_the_choices_the_storefronts_own_form_takes(): void
    {
        $store = $this->store();
        $order = $this->order($store, 'Order Processed');

        Http::fake([
            self::BASE . '/api/v1/orders/700001/estimate' => Http::response([
                'success' => true, 'courier' => 'quadx', 'amount' => 85.0, 'currency' => 'PHP', 'label' => '₱85.00', 'insurance' => 12.0, 'total' => 97.0,
            ]),
            self::BASE . '/api/v1/orders/700001/book-courier' => Http::response([
                'success' => true, 'order_id' => 700001, 'courier' => 'quadx', 'tracking_number' => 'QX1', 'status' => 'for_pickup',
            ]),
            self::BASE . '/api/v1/orders/700001' => Http::response($this->orderPayload('Order Being Shipped')),
        ]);

        $choices = ['courier' => 'quadx', 'pickup_address_id' => 1, 'service' => 'same_day_pickup', 'parcel' => 'medium-pouch', 'shipping_payment' => 'cash', 'insure' => true];

        $this->actingAs($this->packer())
            ->postJson(route('ext.ventacart.orders.estimate', [$store->id, $order->id]), $choices)
            ->assertOk()
            ->assertJson(['ok' => true, 'amount' => 85.0, 'insurance' => 12.0, 'total' => 97.0]);

        $this->actingAs($this->packer())
            ->postJson(route('ext.ventacart.orders.book', [$store->id, $order->id]), $choices)
            ->assertOk();

        foreach (['estimate', 'book-courier'] as $path) {
            Http::assertSent(function ($request) use ($path) {
                if (! str_ends_with($request->url(), '/' . $path)) {
                    return false;
                }
                $body = $request->data();

                return $body['pickup_address_id'] === 1 && $body['service'] === 'same_day_pickup'
                    && $body['parcel'] === 'medium-pouch' && $body['shipping_payment'] === 'cash' && $body['insure'] === true;
            });
        }
    }

    public function test_the_stores_own_placement_decides_the_step_and_the_booking_says_where_it_landed(): void
    {
        $store = $this->store();
        $order = $this->order($store, 'Order Processed');

        $this->statusRows = [
            ['id' => 35, 'name' => 'Order Processed', 'placement' => 'to_pack'],
            ['id' => 77, 'name' => 'Rider Booked', 'placement' => 'to_handover'],
        ];
        Http::fake([
            self::BASE . '/api/v1/orders/700001/book-courier' => Http::response([
                'success' => true, 'order_id' => 700001, 'courier' => 'quadx', 'tracking_number' => 'QX1', 'status' => 'for_pickup',
            ]),
            self::BASE . '/api/v1/orders/700001' => Http::response($this->orderPayload('Rider Booked')),
        ]);

        $this->actingAs($this->packer())
            ->postJson(route('ext.ventacart.orders.book', [$store->id, $order->id]), ['courier' => 'quadx'])
            ->assertOk()
            ->assertJsonPath('message', 'Booked. The store moved it to "Rider Booked", so it is in To Handover.');

        $this->assertStringContainsString('so it is in To Handover', (string) session('status'));

        $html = $this->actingAs($this->packer())
            ->get(route('ext.ventacart.orders.index', [$store->id, 'tab' => 'TO_SHIP', 'pending_sub' => 'to_handover']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('700001', $html);
        $this->assertMatchesRegularExpression('/<span>To Handover<\/span>\s*<span class="x-segment__count">1<\/span>/', $html);
    }

    public function test_a_status_the_store_places_elsewhere_is_named_as_such_after_booking(): void
    {
        $store = $this->store();
        $order = $this->order($store, 'Order Processed');

        $this->statusRows = [['id' => 40, 'name' => 'Assigning Rider', 'placement' => 'to_pack']];
        Http::fake([
            self::BASE . '/api/v1/orders/700001/book-courier' => Http::response([
                'success' => true, 'order_id' => 700001, 'courier' => 'quadx', 'tracking_number' => 'QX1', 'status' => 'for_pickup',
            ]),
            self::BASE . '/api/v1/orders/700001' => Http::response($this->orderPayload('Assigning Rider')),
        ]);

        $this->actingAs($this->packer())
            ->postJson(route('ext.ventacart.orders.book', [$store->id, $order->id]), ['courier' => 'quadx'])
            ->assertOk();

        $this->assertStringContainsString('which it places under To Pack, not To Handover', (string) session('warning'));
        $this->assertNull(session('status'));
    }

    public function test_a_pull_takes_the_booking_from_the_payload_once_the_storefront_sends_it(): void
    {
        $store = $this->store();
        $this->order($store, 'Order Processed');

        Http::fake([
            self::BASE . '/api/v1/orders/700001' => Http::response($this->orderPayload('Order Being Shipped', [
                'provider' => 'quadx', 'tracking_number' => 'QX9', 'status' => 'for_pickup', 'booked_at' => '2026-08-28T08:00:00+08:00', 'manual' => false,
            ])),
        ]);

        $sync = new VentaCartOrderSync(new VentaCartClient($store), $store);
        $row = $sync->pullOne(700001);

        $this->assertNotNull($row);
        $this->assertSame('quadx', $row->courier_provider);
        $this->assertSame('QX9', $row->courier_tracking_number);
        $this->assertSame('for_pickup', $row->courier_status);
        $this->assertSame('Order Being Shipped', $row->status);

        Http::fake([
            self::BASE . '/api/v1/orders/700001' => Http::response($this->orderPayload('Order Being Shipped')),
        ]);
        $row = (new VentaCartOrderSync(new VentaCartClient($store), $store))->pullOne(700001);
        $this->assertSame('QX9', $row->courier_tracking_number);
    }
}
