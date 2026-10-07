<?php

namespace App\Services\Orders;

use App\Services\OrderCurrencyService;
use Illuminate\Support\Facades\DB;

final class OrderLineRevenue
{
    public static function foreign(int $coreOrderId): float
    {
        $pfx = (string) config('catalog.prefix');
        $rate = (float) (DB::table($pfx . 'order')->where('order_id', $coreOrderId)->value('currency_value') ?: 1);
        $base = (float) DB::table($pfx . 'order_product')->where('order_id', $coreOrderId)->sum('total');

        return OrderCurrencyService::toForeign($base, $rate);
    }
}
