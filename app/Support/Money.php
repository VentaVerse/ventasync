<?php

namespace App\Support;

use App\Models\Currency;

class Money
{
    private static ?string $defaultSymbolCache = null;

    private static ?string $defaultCodeCache = null;

    // Marketplace amounts carry thousands commas ("-2,251.57"); strip them before casting or fees truncate.
    public static function parse(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return (float) str_replace([',', ' '], '', (string) ($value ?? ''));
    }

    private static array $symbolCache = [];

    public static function defaultSymbol(): string
    {
        if (self::$defaultSymbolCache !== null) {
            return self::$defaultSymbolCache;
        }

        $symbol = Currency::where('is_default', 1)->value('symbol');

        return self::$defaultSymbolCache = $symbol !== null ? (string) $symbol : '₱';
    }

    public static function defaultCode(): string
    {
        if (self::$defaultCodeCache !== null) {
            return self::$defaultCodeCache;
        }

        $code = Currency::where('is_default', 1)->value('code');

        return self::$defaultCodeCache = $code !== null ? (string) $code : 'PHP';
    }

    private static function lookupSymbol(string $code): ?string
    {
        $code = strtoupper($code);

        if (array_key_exists($code, self::$symbolCache)) {
            return self::$symbolCache[$code];
        }

        $symbol = Currency::whereRaw('UPPER(code) = ?', [$code])->value('symbol');

        return self::$symbolCache[$code] = $symbol !== null ? (string) $symbol : null;
    }

    public static function symbolFor(?string $code): string
    {
        if (!$code) {
            return '';
        }

        return self::lookupSymbol($code) ?? strtoupper($code);
    }

    public static function flushCache(): void
    {
        self::$defaultSymbolCache = null;
        self::$defaultCodeCache = null;
        self::$symbolCache = [];
    }

    public static function base(float $amount): string
    {
        $sign = $amount < 0 ? '-' : '';

        return $sign . self::defaultSymbol() . number_format(abs($amount), 2);
    }

    public static function compactAxis(float $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $abs = abs($amount);
        $symbol = self::defaultSymbol();

        $trim = fn (float $v) => rtrim(rtrim(number_format($v, 1), '0'), '.');

        if ($abs >= 1000000) {
            return $sign . $symbol . $trim($abs / 1000000) . 'M';
        }

        if ($abs >= 1000) {
            return $sign . $symbol . $trim($abs / 1000) . 'K';
        }

        return $sign . $symbol . number_format($abs, 0);
    }

    public static function foreign(float $amount, string $code): string
    {
        $sign = $amount < 0 ? '-' : '';
        $symbol = self::lookupSymbol($code);
        $formatted = number_format(abs($amount), 2);

        return $symbol !== null
            ? $sign . $symbol . $formatted
            : $sign . strtoupper($code) . ' ' . $formatted;
    }

    public static function dual(float $php, ?float $foreign, ?string $foreignCode): string
    {
        $base = self::base($php);

        if ($foreign === null || !$foreignCode) {
            return $base;
        }

        return $base . ' (' . self::foreign($foreign, $foreignCode) . ')';
    }
}
