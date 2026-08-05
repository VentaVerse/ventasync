<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Classifies existing orders by currency convention and plans the change-set
 * that normalizes them.
 *
 * Two conventions exist in the data:
 *   LEGACY (OpenCart/marketplace sync): money columns hold DEFAULT currency,
 *       currency_value is "foreign per 1 default" (e.g. 0.021 USD per PHP).
 *   LOST_RATE (ERP-created before this fix): money columns hold FOREIGN
 *       currency, currency_value is a meaningless 1.
 *
 * Everything here is pure: no DB, no config, no clock.
 */
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

    /**
     * @return array{foreign_total: float, currency_value: float}
     */
    public static function planLegacyOrder(float $orderTotal, float $oldRate): array
    {
        self::assertOldRate($oldRate);

        return [
            // Derived with the OLD rate, before inversion.
            'foreign_total'  => round($orderTotal * $oldRate, OrderCurrencyService::MONEY_SCALE),
            'currency_value' => round(1 / $oldRate, OrderCurrencyService::RATE_SCALE),
        ];
    }

    /**
     * @return array{foreign_price: float, foreign_total: float}
     */
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
