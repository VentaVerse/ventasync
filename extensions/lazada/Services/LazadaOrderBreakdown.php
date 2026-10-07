<?php

namespace Extensions\lazada\Services;

use App\Services\Orders\Breakdown\OrderBreakdown;

final class LazadaOrderBreakdown
{
    public static function from(array $fees): ?OrderBreakdown
    {
        $transactions = is_array($fees['transactions'] ?? null) ? $fees['transactions'] : self::byName($fees['transaction_lines'] ?? null);
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

    private static function byName(mixed $lines): array
    {
        $totals = [];
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $name = (string) ($line['fee_name'] ?? '') !== '' ? (string) $line['fee_name'] : 'Fee type ' . (int) ($line['fee_type'] ?? 0);
            $totals[$name] = ($totals[$name] ?? 0) + \App\Support\Money::parse($line['amount'] ?? 0);
        }

        return array_map(fn ($name, $amount) => ['name' => (string) $name, 'amount' => round($amount, 2)], array_keys($totals), array_values($totals));
    }
}
