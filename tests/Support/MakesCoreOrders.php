<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait MakesCoreOrders
{
    protected function coreOrder(array $overrides = []): int
    {
        $revenueStatus = (int) (DB::table('order_statuses')->where('add_revenue', 1)->value('order_status_id') ?: 1);
        $now = now();

        $blank = array_fill_keys([
            'store_name', 'firstname', 'lastname', 'email', 'telephone', 'payment_firstname', 'payment_lastname', 'payment_company', 'payment_address_1', 'payment_address_2', 'payment_city',
            'payment_postcode', 'payment_country', 'payment_zone', 'payment_method',
            'shipping_firstname', 'shipping_lastname', 'shipping_company', 'shipping_address_1', 'shipping_address_2',
            'shipping_city', 'shipping_postcode', 'shipping_country', 'shipping_zone', 'shipping_method', 'customer_note', 'ip', 'tracking_number',
        ], '');

        $row = array_merge($blank, [
            'store_id' => 0, 'currency_id' => 1, 'currency_code' => 'PHP',
            'currency_value' => 1, 'courier_id' => 0, 'total' => 100, 'order_status_id' => $revenueStatus,
            'channel' => '', 'channel_order_id' => uniqid('t'), 'firstname' => 'Test', 'lastname' => 'Buyer',
            'date_added' => $now, 'date_modified' => $now,
        ], $overrides);

        return (int) DB::table('orders')->insertGetId($row);
    }

    protected function coreOrderLine(int $orderId, float $price = 100, float $cost = 40, int $qty = 1): void
    {
        DB::table('order_products')->insert([
            'order_id' => $orderId, 'product_id' => 1, 'name' => 'Thing', 'sku' => 'T', 'quantity' => $qty,
            'price' => $price, 'total' => $price * $qty, 'cost' => $cost,
        ]);
    }
}
