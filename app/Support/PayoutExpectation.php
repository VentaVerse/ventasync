<?php

namespace App\Support;

final class PayoutExpectation
{
    public static function for(string $channel, ?string $feesJson, ?string $rawJson = null): array
    {
        $fees = self::decode($feesJson);

        $reported = match ($channel) {
            'shopee' => self::shopee($fees),
            'lazada' => self::lazada($fees),
            'tiktok' => self::tiktok($fees),
            default => null,
        };
        if ($reported !== null) {
            return ['amount' => round($reported, 2), 'estimated' => false];
        }

        $total = self::orderTotal($channel, self::decode($rawJson));

        return ['amount' => $total !== null ? round($total, 2) : null, 'estimated' => $total !== null];
    }

    public static function reported(string $channel, ?string $feesJson): bool
    {
        $expected = self::for($channel, $feesJson);

        return $expected['amount'] !== null && ! $expected['estimated'];
    }

    private static function shopee(array $fees): ?float
    {
        $income = is_array($fees['order_income'] ?? null) ? $fees['order_income'] : $fees;

        return is_numeric($income['escrow_amount'] ?? null) ? (float) $income['escrow_amount'] : null;
    }

    private static function lazada(array $fees): ?float
    {
        foreach (['transactions', 'transaction_lines'] as $key) {
            $lines = $fees[$key] ?? null;
            if (is_array($lines) && $lines !== []) {
                $sum = 0.0;
                $any = false;
                foreach ($lines as $line) {
                    $amount = is_array($line) ? ($line['amount'] ?? null) : null;
                    $amount = is_string($amount) ? str_replace(',', '', $amount) : $amount;
                    if (is_numeric($amount)) {
                        $sum += (float) $amount;
                        $any = true;
                    }
                }
                if ($any) {
                    return $sum;
                }
            }
        }

        if (is_array($fees['other_fees'] ?? null) && $fees['other_fees'] !== []) {
            $sum = 0.0;
            foreach (['commission', 'payment_fee', 'shipping_service_cost'] as $bucket) {
                $sum += is_numeric($fees[$bucket] ?? null) ? (float) $fees[$bucket] : 0.0;
            }
            foreach ($fees['other_fees'] as $amount) {
                $sum += is_numeric($amount) ? (float) $amount : 0.0;
            }

            return $sum;
        }

        return null;
    }

    private static function tiktok(array $fees): ?float
    {
        return is_numeric($fees['settlement_amount'] ?? null) ? (float) $fees['settlement_amount'] : null;
    }

    private static function orderTotal(string $channel, array $raw): ?float
    {
        $value = match ($channel) {
            'shopee' => $raw['total_amount'] ?? null,
            'lazada' => $raw['price'] ?? null,
            'tiktok' => ($raw['payment_info'] ?? $raw['payment'] ?? [])['total_amount'] ?? null,
            default => null,
        };
        if (is_string($value)) {
            $value = str_replace(',', '', $value);
        }

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }
}
