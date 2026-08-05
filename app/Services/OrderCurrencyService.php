<?php

namespace App\Services;

use App\Models\Currency;
use InvalidArgumentException;

/**
 * Currency conversion for orders.
 *
 * Rate semantic: `rate` is ALWAYS "units of default currency per 1 unit of the
 * order currency". PHP (default) = 1.0, USD = 61.61429452.
 *
 * Static methods are pure and DB-free so they can be unit-tested; instance
 * methods resolve against the `currencies` table.
 */
class OrderCurrencyService
{
    /** Money columns are decimal(15,4). */
    public const MONEY_SCALE = 4;

    /** currency_value is decimal(15,8). */
    public const RATE_SCALE = 8;

    /** Tolerance for comparing a rate against the default 1.0. */
    private const RATE_EPSILON = 0.00000001;

    public static function toBase(float $foreign, float $rate): float
    {
        self::assertRate($rate);

        return round($foreign * $rate, self::MONEY_SCALE);
    }

    public static function toForeign(float $base, float $rate): float
    {
        self::assertRate($rate);

        return round($base / $rate, self::MONEY_SCALE);
    }

    public static function isDefaultRate(float $rate): bool
    {
        return abs($rate - 1.0) < self::RATE_EPSILON;
    }

    private static function assertRate(float $rate): void
    {
        if ($rate <= 0) {
            throw new InvalidArgumentException("Exchange rate must be positive, got {$rate}.");
        }
    }

    public function defaultCode(): string
    {
        $default = Currency::where('is_default', 1)->first();

        if (!$default) {
            throw new InvalidArgumentException('No default currency is configured.');
        }

        return (string) $default->code;
    }

    /**
     * Resolve a currency code to its id and the rate to record on the order.
     *
     * @param  float|null  $overrideRate  Operator-supplied rate; falls back to the stored feed rate.
     * @return array{id: int, code: string, rate: float, is_default: bool}
     */
    public function resolve(string $code, ?float $overrideRate = null): array
    {
        $currency = Currency::where('code', $code)->first();

        if (!$currency) {
            throw new InvalidArgumentException("Unknown currency code: {$code}.");
        }

        $isDefault = (bool) $currency->is_default;

        $rate = $overrideRate !== null
            ? $overrideRate
            : (float) $currency->exchange_rate;

        if ($isDefault) {
            $rate = 1.0;
        }

        self::assertRate($rate);

        return [
            'id'         => (int) $currency->id,
            'code'       => (string) $currency->code,
            'rate'       => round($rate, self::RATE_SCALE),
            // Whether to store the dual-currency figures at all is a matter of
            // currency IDENTITY, not of what the resolved rate happens to
            // equal. isDefaultRate($rate) alone is unsafe here: an operator
            // (or a marketplace feed) can legitimately submit rate=1 for a
            // genuinely foreign currency, which would otherwise masquerade as
            // a PHP order (foreign_* left NULL, foreign figures written into
            // the PHP columns).
            'is_default' => $isDefault,
        ];
    }
}
