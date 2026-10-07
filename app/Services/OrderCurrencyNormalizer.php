<?php

namespace App\Services;

use InvalidArgumentException;

class OrderCurrencyNormalizer
{
    public const CLASS_DEFAULT   = 'default';
    public const CLASS_LEGACY    = 'legacy';
    public const CLASS_LOST_RATE = 'lost_rate';

    public static function classify(string $currencyCode, float $currencyValue, string $defaultCode): string
    {
        if (strcasecmp($currencyCode, $defaultCode) === 0) {
            return self::CLASS_DEFAULT;
        }

        return OrderCurrencyService::isDefaultRate($currencyValue)
            ? self::CLASS_LOST_RATE
            : self::CLASS_LEGACY;
    }

    public static function planLegacyOrder(float $orderTotal, float $oldRate): array
    {
        self::assertOldRate($oldRate);

        return [
            'foreign_total'  => round($orderTotal * $oldRate, OrderCurrencyService::MONEY_SCALE),
            'currency_value' => round(1 / $oldRate, OrderCurrencyService::RATE_SCALE),
        ];
    }

    public static function planLegacyProduct(float $price, float $total, float $oldRate): array
    {
        self::assertOldRate($oldRate);

        return [
            'foreign_price' => round($price * $oldRate, OrderCurrencyService::MONEY_SCALE),
            'foreign_total' => round($total * $oldRate, OrderCurrencyService::MONEY_SCALE),
        ];
    }

    private static function assertOldRate(float $oldRate): void
    {
        if ($oldRate <= 0) {
            throw new InvalidArgumentException("Legacy currency_value must be positive, got {$oldRate}.");
        }
    }
}
