<?php

namespace App\Services;

use App\Models\Currency;
use InvalidArgumentException;

class OrderCurrencyService
{
    public const MONEY_SCALE = 4;

    public const RATE_SCALE = 8;

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
            'is_default' => $isDefault,
        ];
    }
}
