<?php

namespace App\Services\Orders;

use App\Integrations\IntegrationRegistry;
use App\Services\OrderCurrencyService;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites an order's Sale lines from what its channel already stores.
 *
 * Only the Sale rows in order_total change. The order's product lines and
 * their cost, its stock, its status, order.total and the normalized fee rows
 * are left exactly as they are, which is why this exists rather than a re-sync:
 * a re-sync rebuilds the product lines and re-reads their cost from today's
 * catalogue.
 */
final class SaleLinesWriter
{
    public function __construct(private readonly IntegrationRegistry $registry)
    {
    }

    /** True when a channel answered for the order and its Sale lines were rewritten. */
    public function forOrder(int $coreOrderId): bool
    {
        $pfx = (string) config('catalog.prefix');
        $order = DB::table($pfx . 'order')->where('order_id', $coreOrderId)->first(['order_id', 'currency_value']);
        if (! $order) {
            return false;
        }

        $rate = (float) ($order->currency_value ?: 1);
        $items = (float) DB::table($pfx . 'order_product')->where('order_id', $coreOrderId)->sum('total');
        $shipping = (float) DB::table($pfx . 'order_total')->where('order_id', $coreOrderId)->where('code', 'shipping')->value('value');

        foreach ($this->registry->saleLinesSources() as $source) {
            $lines = $source->saleLinesForOrder(
                $coreOrderId,
                OrderCurrencyService::toForeign($items, $rate),
                OrderCurrencyService::toForeign($shipping, $rate),
            );
            if ($lines === null) {
                continue;
            }

            DB::transaction(function () use ($pfx, $coreOrderId, $lines, $rate) {
                DB::table($pfx . 'order_total')
                    ->where('order_id', $coreOrderId)
                    ->whereNotIn('code', array_column(MarketplaceFeeNormalizer::CODES, 0))
                    ->delete();
                $sortOrder = 1;
                foreach ($lines as $line) {
                    DB::table($pfx . 'order_total')->insert([
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
