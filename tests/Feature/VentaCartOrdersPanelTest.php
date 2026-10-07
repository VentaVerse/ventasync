<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\ventacart\Models\VentaCartOrder;
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\VentaCartExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VentaCartOrdersPanelTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('ventacart');
        $manager->enable('ventacart');

        $this->app->register(VentaCartExtension::class);

        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function viewer(): User
    {
        $group = UserGroup::create(['name' => 'VentaCart Panel Viewers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_ventacart/settings', 'view_ventacart/order'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function store(): VentaCartSetting
    {
        return VentaCartSetting::create([
            'store_name' => 'Gear Depot',
            'base_url' => 'https://one.ventacart.test',
            'api_token' => 'ventacart-token-one',
            'enabled' => true,
        ]);
    }

    private function order(VentaCartSetting $store, string $status, string $customer = 'Depot Buyer'): VentaCartOrder
    {
        $seq = ++$this->seq;

        return VentaCartOrder::create([
            'ventacart_setting_id' => $store->id,
            'ventacart_order_id' => 700000 + $seq,
            'ventacart_order_number' => 700000 + $seq,
            'status' => $status,
            'status_id' => 1,
            'customer_name' => $customer,
            'total' => 100,
            'order_created_at' => now()->subMinutes($seq),
        ]);
    }

    private function strip(string $html): string
    {
        $this->assertSame(1, preg_match('/<nav class="x-segment" aria-label="Order status">(.*?)<\/nav>/s', $html, $m),
            'No status strip rendered.');

        return $m[1];
    }

    public function test_every_tab_is_there_at_zero_on_an_empty_store(): void
    {
        $store = $this->store();

        $response = $this->actingAs($this->viewer())->get(route('ext.ventacart.orders.index', $store->id));

        $response->assertOk();
        $strip = $this->strip($response->getContent());

        foreach (['All', 'Unpaid', 'To Ship', 'Shipping', 'Delivered', 'Cancelled', 'Failed Delivery'] as $label) {
            $this->assertStringContainsString('<span>' . $label . '</span>', $strip, $label . ' is missing from the strip');
        }

        $this->assertSame(7, substr_count($strip, '<span class="x-segment__count">0</span>'));
        $this->assertSame(7, substr_count($strip, 'href="'));

        $this->assertStringNotContainsString('<span>Other</span>', $strip);
    }

    public function test_unpaid_sits_between_all_and_to_ship(): void
    {
        $store = $this->store();

        $strip = $this->strip($this->actingAs($this->viewer())->get(route('ext.ventacart.orders.index', $store->id))->getContent());

        $all = strpos($strip, '<span>All</span>');
        $unpaid = strpos($strip, '<span>Unpaid</span>');
        $toShip = strpos($strip, '<span>To Ship</span>');

        $this->assertNotFalse($all);
        $this->assertNotFalse($unpaid);
        $this->assertNotFalse($toShip);
        $this->assertTrue($all < $unpaid && $unpaid < $toShip, 'Unpaid must be between All and To Ship');
    }

    public function test_landing_is_to_ship_with_to_pack_open(): void
    {
        $store = $this->store();
        $this->order($store, 'Order Processed', 'Bench Buyer');
        $this->order($store, 'Start Pickup', 'Shelf Buyer');
        $this->order($store, 'Delivered', 'Done Buyer');

        $response = $this->actingAs($this->viewer())->get(route('ext.ventacart.orders.index', $store->id));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/class="x-segment__item is-active"\s+aria-current="true"\s*>\s*<span>To Ship<\/span>/', $html);
        $this->assertStringContainsString('aria-label="To Ship step"', $html);
        $this->assertMatchesRegularExpression('/class="x-segment__item is-active"\s+aria-current="step"\s*>\s*<span>To Pack<\/span>/', $html);

        $this->assertStringContainsString('Bench Buyer', $html);
        $this->assertStringNotContainsString('Shelf Buyer', $html);
        $this->assertStringNotContainsString('Done Buyer', $html);

        $this->assertStringContainsString('<input type="hidden" name="tab" value="TO_SHIP">', $html);
        $this->assertStringContainsString('<input type="hidden" name="pending_sub" value="to_pack">', $html);
    }

    public function test_start_pickup_counts_under_to_handover_and_order_processed_under_to_pack(): void
    {
        $store = $this->store();
        $this->order($store, 'Order Processed');
        $this->order($store, 'Processing');
        $this->order($store, 'Start Pickup');
        $this->order($store, 'Pending');
        $this->order($store, 'Delivered');

        $response = $this->actingAs($this->viewer())->get(route('ext.ventacart.orders.index', $store->id));
        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/<span>To Ship<\/span>\s*<span class="x-segment__count">3<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span>To Pack<\/span>\s*<span class="x-segment__count">2<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span>To Handover<\/span>\s*<span class="x-segment__count">1<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span>Unpaid<\/span>\s*<span class="x-segment__count">1<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span>Delivered<\/span>\s*<span class="x-segment__count">1<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span>All<\/span>\s*<span class="x-segment__count">5<\/span>/', $html);

        $steps = VentaCartExtension::stepCounts($store->id);
        $this->assertSame(2, $steps['to_pack']);
        $this->assertSame(1, $steps['to_handover']);
        $this->assertSame(1, $steps['unpaid']);
    }

    public function test_to_handover_lists_only_the_parcels_on_the_shelf(): void
    {
        $store = $this->store();
        $this->order($store, 'Order Processed', 'Bench Buyer');
        $this->order($store, 'Start Pickup', 'Shelf Buyer');

        $html = $this->actingAs($this->viewer())
            ->get(route('ext.ventacart.orders.index', ['store' => $store->id, 'tab' => 'TO_SHIP', 'pending_sub' => 'to_handover']))
            ->getContent();

        $this->assertStringContainsString('Shelf Buyer', $html);
        $this->assertStringNotContainsString('Bench Buyer', $html);
        $this->assertMatchesRegularExpression('/class="x-segment__item is-active"\s+aria-current="step"\s*>\s*<span>To Handover<\/span>/', $html);
    }

    public function test_a_status_link_still_filters_and_opens_its_own_tab(): void
    {
        $store = $this->store();
        $viewer = $this->viewer();
        $this->order($store, 'Delivered', 'Done Buyer');
        $this->order($store, 'Order Processed', 'Bench Buyer');
        $this->order($store, 'Start Pickup', 'Shelf Buyer');

        $delivered = $this->actingAs($viewer)
            ->get(route('ext.ventacart.orders.index', ['store' => $store->id, 'status' => 'Delivered']))
            ->getContent();

        $this->assertStringContainsString('Done Buyer', $delivered);
        $this->assertStringNotContainsString('Bench Buyer', $delivered);
        $this->assertMatchesRegularExpression('/class="x-segment__item is-active"\s+aria-current="true"\s*>\s*<span>Delivered<\/span>/', $delivered);

        $shelf = $this->actingAs($viewer)
            ->get(route('ext.ventacart.orders.index', ['store' => $store->id, 'status' => 'Start Pickup']))
            ->getContent();

        $this->assertStringContainsString('Shelf Buyer', $shelf);
        $this->assertStringNotContainsString('Bench Buyer', $shelf);
        $this->assertMatchesRegularExpression('/class="x-segment__item is-active"\s+aria-current="step"\s*>\s*<span>To Handover<\/span>/', $shelf);
    }

    public function test_a_status_the_map_does_not_place_shows_up_under_other(): void
    {
        $store = $this->store();
        $this->order($store, 'Some Store Invented This', 'Odd Buyer');

        $html = $this->actingAs($this->viewer())
            ->get(route('ext.ventacart.orders.index', ['store' => $store->id, 'tab' => 'OTHER']))
            ->getContent();

        $this->assertMatchesRegularExpression('/<span>Other<\/span>\s*<span class="x-segment__count">1<\/span>/', $html);
        $this->assertStringContainsString('Odd Buyer', $html);
    }

    public function test_all_shows_everything(): void
    {
        $store = $this->store();
        $this->order($store, 'Order Processed', 'Bench Buyer');
        $this->order($store, 'Delivered', 'Done Buyer');

        $html = $this->actingAs($this->viewer())
            ->get(route('ext.ventacart.orders.index', ['store' => $store->id, 'tab' => 'ALL']))
            ->getContent();

        $this->assertStringContainsString('Bench Buyer', $html);
        $this->assertStringContainsString('Done Buyer', $html);
    }
}
