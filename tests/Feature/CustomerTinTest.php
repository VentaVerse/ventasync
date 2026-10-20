<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTinTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_customer_tin_is_saved_on_the_order_and_shown_on_it(): void
    {
        $this->artisan('permissions:sync-catalog');
        $group = UserGroup::create(['name' => 'Sales']);
        $group->permissions()->attach(Permission::whereIn('key', ['view_sales/order', 'manage_sales/order'])->pluck('id')->all());
        $this->actingAs(User::factory()->create(['user_group_id' => $group->id]));

        $status = OrderStatus::create(['language_id' => 1, 'name' => 'Pending', 'subtract_stock' => 0, 'add_revenue' => 0]);
        $order = Order::create([
            'store_id' => 0, 'store_name' => '', 'firstname' => 'Maria', 'lastname' => 'Santos', 'payment_method' => '', 'shipping_method' => '',
            'courier_id' => 0, 'tracking_number' => '', 'email' => '', 'telephone' => '',
            'payment_firstname' => 'Maria', 'payment_lastname' => 'Santos', 'payment_company' => '',
            'payment_address_1' => '', 'payment_address_2' => '', 'payment_city' => '', 'payment_postcode' => '',
            'payment_country' => '', 'payment_zone' => '', 'payment_cost' => 0,
            'shipping_firstname' => 'Maria', 'shipping_lastname' => 'Santos', 'shipping_company' => '',
            'shipping_address_1' => '', 'shipping_address_2' => '', 'shipping_city' => '', 'shipping_postcode' => '',
            'shipping_country' => '', 'shipping_zone' => '', 'shipping_cost' => 0,
            'customer_note' => '', 'total' => 100, 'foreign_total' => null, 'extra_cost' => 0, 'order_status_id' => $status->order_status_id,
            'currency_id' => 1, 'currency_code' => 'PHP', 'currency_value' => 1, 'ip' => '',
            'date_added' => now(), 'date_modified' => now(), 'track_payments' => true,
        ]);

        $this->get(route('orders.edit', $order->order_id))->assertOk()->assertSee('Customer TIN');

        $this->put(route('orders.update', $order->order_id), [
            'firstname' => 'Maria', 'lastname' => 'Santos', 'order_status_id' => $status->order_status_id,
            'customer_tin' => ' 987-654-321-00000 ', 'total' => 100,
        ])->assertSessionHasNoErrors();

        $this->assertSame('987-654-321-00000', $order->fresh()->customer_tin);
        $this->get(route('orders.show', $order->order_id))->assertSee('987-654-321-00000');
    }
}
