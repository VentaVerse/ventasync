<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\Catalog\Order;
use App\Models\User;
use Extensions\ventacart\Models\VentaCartOrder;
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Services\VentaCart\VentaCartClient;
use Extensions\ventacart\Services\VentaCart\VentaCartOrderSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class VentaCartCustomerGroupTest extends TestCase
{
    use RefreshDatabase;

    private function sync(): array
    {
        $setting = VentaCartSetting::create(['store_name' => 'Group store', 'base_url' => 'https://ventacart.example.test', 'api_token' => 'secret']);
        $client = app(VentaCartClient::class, ['setting' => $setting]);

        return [new VentaCartOrderSync($client, $setting), $setting];
    }

    private function upsert(VentaCartOrderSync $sync, array $raw): void
    {
        $method = new ReflectionMethod(VentaCartOrderSync::class, 'upsertOrder');
        $method->invoke($sync, $raw, ['by_id' => [], 'by_name' => []]);
    }

    public function test_the_group_name_lands_on_both_order_rows_and_a_resync_updates_it(): void
    {
        [$sync, $setting] = $this->sync();
        $raw = ['id' => 777001, 'order_number' => 2001, 'status' => 'pending', 'status_id' => 1, 'totals' => ['total' => 500], 'items' => [],
            'customer_group' => 'Reseller'];

        $this->upsert($sync, $raw);

        $order = Order::where('marketplace_source', 'ventacart:' . $setting->id)->where('marketplace_order_id', '777001')->firstOrFail();
        $this->assertSame('Reseller', $order->customer_group);
        $this->assertSame(0, (int) $order->customer_group_id, 'the id stays 0: nothing here to point at (#132)');
        $this->assertSame('Reseller', VentaCartOrder::where('ventacart_order_id', 777001)->value('customer_group'));

        $this->upsert($sync, ['customer_group' => 'Wholesale'] + $raw);
        $this->assertSame('Wholesale', $order->fresh()->customer_group);

        $this->upsert($sync, ['id' => 777002, 'order_number' => 2002, 'status' => 'pending', 'status_id' => 1, 'totals' => ['total' => 1], 'items' => [], 'customer_group' => null]);
        $this->assertNull(Order::where('marketplace_order_id', '777002')->value('customer_group'));
    }

    public function test_the_order_page_names_the_group(): void
    {
        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('ventacart');
        $manager->enable('ventacart');
        $this->app->register(\Extensions\ventacart\VentaCartExtension::class);
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
