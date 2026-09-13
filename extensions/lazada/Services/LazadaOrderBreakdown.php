<?php

namespace Extensions\lazada\Services;

use App\Services\Orders\Breakdown\OrderBreakdown;

/**
 * A Lazada order's income as Lazada's finance statement lists it: every
 * transaction under Lazada's own name, in the order Lazada sent them, so a
 * fee Lazada adds appears the day it is charged. The bottom line is what the
 * transactions come to.
 */
final class LazadaOrderBreakdown
{
    /** @param  array<string, mixed>  $fees  the order's stored fee record */
    public static function from(array $fees): ?OrderBreakdown
    {
        $transactions = is_array($fees['transactions'] ?? null) ? $fees['transactions'] : [];
        if ($transactions === []) {
            return null;
        }
        $breakdown = new OrderBreakdown('Order income');
        $sum = 0.0;
        foreach ($transactions as $transaction) {
            $amount = round((float) ($transaction['amount'] ?? 0), 2);
            $breakdown->line((string) ($transaction['name'] ?? ''), $amount);
            $sum += $amount;
        }

        return $breakdown->total('Total', $sum);
    }
}
