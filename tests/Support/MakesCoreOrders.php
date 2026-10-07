<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait MakesCoreOrders
{
    protected function coreOrder(array $overrides = []): int
    {
        $pfx = (string) config('catalog.prefix');
        $revenueStatus = (int) (DB::table($pfx . 'order_status')->where('add_revenue', 1)->value('order_status_id') ?: 1);
        $now = now();

        $blank = array_fill_keys([
            'invoice_prefix', 'store_name', 'store_url', 'firstname', 'lastname', 'email', 'telephone', 'fax', 'custom_field',
            'payment_firstname', 'payment_lastname', 'payment_company', 'payment_address_1', 'payment_address_2', 'payment_city',
            'payment_postcode', 'payment_country', 'payment_zone', 'payment_address_format', 'payment_custom_field', 'payment_method',
            'payment_code', 'shipping_firstname', 'shipping_lastname', 'shipping_company', 'shipping_address_1', 'shipping_address_2',
            'shipping_city', 'shipping_postcode', 'shipping_country', 'shipping_zone', 'shipping_address_format', 'shipping_custom_field',
            'shipping_method', 'shipping_code', 'comment', 'tracking', 'ip', 'forwarded_ip', 'user_agent', 'accept_language', 'tracking_number',
        ], '');

        $row = array_merge($blank, [
            'store_id' => 0, 'payment_country_id' => 0, 'payment_zone_id' => 0, 'shipping_country_id' => 0, 'shipping_zone_id' => 0,
            'affiliate_id' => 0, 'commission' => 0, 'marketing_id' => 0, 'language_id' => 1, 'currency_id' => 1, 'currency_code' => 'PHP',
            'currency_value' => 1, 'courier_id' => 0, 'oe_import' => 0, 'total' => 100, 'order_status_id' => $revenueStatus,
            'marketplace_source' => '', 'marketplace_order_id' => uniqid('t'), 'firstname' => 'Test', 'lastname' => 'Buyer',
            'date_added' => $now, 'date_modified' => $now,
        ], $overrides);

        return (int) DB::table($pfx . 'order')->insertGetId($row);
    }

    protected function coreOrderLine(int $orderId, float $price = 100, float $cost = 40, int $qty = 1): void
    {
        DB::table(config('catalog.prefix') . 'order_product')->insert([
            'order_id' => $orderId, 'product_id' => 1, 'name' => 'Thing', 'model' => 'T', 'quantity' => $qty,
            'price' => $price, 'total' => $price * $qty, 'tax' => 0, 'reward' => 0, 'cost' => $cost,
        ]);
    }
}
