<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\Catalog\Order;
use App\Models\User;
use Extensions\venta\Models\VentaOrder;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaClient;
use Extensions\venta\Services\Venta\VentaOrderSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * #129, 2026-08-26. Venta sends the customer group the order was placed
 * under, by name. The sync writes that name onto the core order row and
 * the Venta mirror, a re-sync updates it, and the order page shows it.
 * No group entity, no mapping.
 */
class VentaCustomerGroupTest extends TestCase
{
    use RefreshDatabase;

    private function sync(): array
    {
        $setting = VentaSetting::create(['store_name' => 'Group store', 'base_url' => 'https://venta.example.test', 'api_token' => 'secret']);
        $client = app(VentaClient::class, ['setting' => $setting]);

        return [new VentaOrderSync($client, $setting), $setting];
    }

    private function upsert(VentaOrderSync $sync, array $raw): void
    {
        $method = new ReflectionMethod(VentaOrderSync::class, 'upsertOrder');
        $method->invoke($sync, $raw, ['by_id' => [], 'by_name' => []]);
    }

    public function test_the_group_name_lands_on_both_order_rows_and_a_resync_updates_it(): void
    {
        [$sync, $setting] = $this->sync();
        $raw = ['id' => 777001, 'order_number' => 2001, 'status' => 'pending', 'status_id' => 1, 'totals' => ['total' => 500], 'items' => [],
            'customer_group' => 'Reseller'];

        $this->upsert($sync, $raw);

        $order = Order::where('marketplace_source', 'venta:' . $setting->id)->where('marketplace_order_id', '777001')->firstOrFail();
        $this->assertSame('Reseller', $order->customer_group);
        $this->assertSame(0, (int) $order->customer_group_id, 'the id stays 0: nothing here to point at (#132)');
        $this->assertSame('Reseller', VentaOrder::where('venta_order_id', 777001)->value('customer_group'));

        // The backfill is a re-sync: an order synced before Venta sent the
        // group takes the name on its next pass.
        $this->upsert($sync, ['customer_group' => 'Wholesale'] + $raw);
        $this->assertSame('Wholesale', $order->fresh()->customer_group);

        // Venta never captured one: null, never a guess.
        $this->upsert($sync, ['id' => 777002, 'order_number' => 2002, 'status' => 'pending', 'status_id' => 1, 'totals' => ['total' => 1], 'items' => [], 'customer_group' => null]);
        $this->assertNull(Order::where('marketplace_order_id', '777002')->value('customer_group'));
    }

    public function test_the_order_page_names_the_group(): void
    {
        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('venta');
        $manager->enable('venta');
        $this->app->register(\Extensions\venta\VentaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        [$sync, $setting] = $this->sync();
        $this->upsert($sync, ['id' => 777003, 'order_number' => 2003, 'status' => 'pending', 'status_id' => 1, 'totals' => ['total' => 500], 'items' => [],
            'customer_group' => 'Reseller', 'contact' => ['name' => 'Ana Reyes', 'email' => 'ana@example.test']]);
        $order = Order::where('marketplace_order_id', '777003')->firstOrFail();

        $group = UserGroup::create(['name' => 'Group readers']);
        $group->permissions()->attach(Permission::whereIn('key', ['view_sales/order'])->pluck('id')->all());
        $user = User::factory()->create(['user_group_id' => $group->id]);

        $html = $this->actingAs($user)->get(route('orders.show', $order->order_id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/od-fact__k">Customer group<\/span>\s*<span class="od-fact__v">Reseller</', $html);
    }
}
