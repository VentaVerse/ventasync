<?php

namespace App\Services\Orders;

use App\Channels\ChannelRegistry;
use App\Services\OrderCurrencyService;
use Illuminate\Support\Facades\DB;

final class SaleLinesWriter
{
    public function __construct(private readonly ChannelRegistry $registry)
    {
    }

    public function forOrder(int $coreOrderId): bool
    {
        $order = DB::table('orders')->where('order_id', $coreOrderId)->first(['order_id', 'currency_value']);
        if (! $order) {
            return false;
        }

        $rate = (float) ($order->currency_value ?: 1);
        $items = (float) DB::table('order_products')->where('order_id', $coreOrderId)->sum('total');
        $shipping = (float) DB::table('order_totals')->where('order_id', $coreOrderId)->where('code', 'shipping')->value('value');

        foreach ($this->registry->saleLinesSources() as $source) {
            $lines = $source->saleLinesForOrder(
                $coreOrderId,
                OrderCurrencyService::toForeign($items, $rate),
                OrderCurrencyService::toForeign($shipping, $rate),
            );
            if ($lines === null) {
                continue;
            }

            DB::transaction(function () use ( $coreOrderId, $lines, $rate) {
                DB::table('order_totals')
                    ->where('order_id', $coreOrderId)
                    ->whereNotIn('code', array_column(ChannelFeeNormalizer::CODES, 0))
                    ->delete();
                $sortOrder = 1;
                foreach ($lines as $line) {
                    DB::table('order_totals')->insert([
                        'order_id' => $coreOrderId,
                        'code' => $line['code'],
                        'title' => $line['title'],
                        'value' => OrderCurrencyService::toBase((float) $line['value'], $rate),
                        'sort_order' => $sortOrder++,
                    ]);
                }
            });

            return true;
        }

        return false;
    }
}
