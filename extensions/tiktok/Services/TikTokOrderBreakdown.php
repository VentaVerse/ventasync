<?php

namespace Extensions\tiktok\Services;

use App\Services\Orders\Breakdown\OrderBreakdown;

/**
 * A TikTok Shop order's settlement, from the statement TikTok returns once
 * it settles the order. The bottom line is TikTok's settlement amount; what
 * TikTok deducted or added beyond the listed lines shows as the not-itemised
 * line.
 */
final class TikTokOrderBreakdown
{
    /** @param  array<string, mixed>  $fees  the order's stored fee record */
    public static function from(array $fees): ?OrderBreakdown
    {
        if (! isset($fees['settlement_amount'], $fees['revenue'])) {
            return null;
        }
        $a = fn (string $key): float => abs((float) ($fees[$key] ?? 0));

        return (new OrderBreakdown('Settlement'))
            ->line('Total revenue', (float) $fees['revenue'])
            ->group('Total fees', [
                ['Commission fee', -$a('commission')],
                ['Transaction fee', -$a('transaction_fee')],
                ['Shipping fee', -$a('shipping_fee')],
            ])
            ->total('Total settlement amount', (float) $fees['settlement_amount']);
    }
}
