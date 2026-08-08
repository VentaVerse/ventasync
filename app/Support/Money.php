<?php

namespace App\Support;

use App\Models\Currency;

/**
 * Single funnel for every money render in the UI.
 *
 * Deliberately mirrors OpenCart's `system/library/cart/currency.php::format()`
 * - one function every money value passes through, so a bare, unlabelled
 * number can never reach the page again. That ambiguity (which currency is
 * this number in?) is what let opposite-direction conversion bugs hide on
 * the order detail page for months. From here on, a bare `number_format()`
 * call on a money value in a Blade view is a review defect - use `base()` /
 * `foreign()` here, or the `<x-money>` component for the on-page dual
 * (PHP + as-transacted foreign) presentation.
 *
 * `currencies` has a single `symbol` column - no `symbol_left`/`symbol_right`
 * split and no per-currency `decimal_place` the way OpenCart's table has.
 * Every configured currency here is prefixed, 2-decimal. Do not add those
 * columns; nothing in this codebase needs anything more exotic than
 * PHP/USD/CNY-style "symbol immediately before the number".
 */
class Money
{
    private static ?string $defaultSymbolCache = null;

    private static ?string $defaultCodeCache = null;

    /** @var array<string, string|null> currency code (uppercased) => symbol, or null if unconfigured */
    private static array $symbolCache = [];

    /**
     * The default (base/PHP) currency's symbol, resolved from the
     * `currencies` table - never hardcoded, so a future re-basing of the
     * default currency does not require touching every view.
     */
    public static function defaultSymbol(): string
    {
        if (self::$defaultSymbolCache !== null) {
            return self::$defaultSymbolCache;
        }

        $symbol = Currency::where('is_default', 1)->value('symbol');

        return self::$defaultSymbolCache = $symbol !== null ? (string) $symbol : '₱';
    }

    /**
     * The default currency's code, resolved from the `currencies` table and
     * cached for the request - <x-money> calls this once per row on list
     * pages (orders index, dashboard), so it must not re-query per row.
     */
    public static function defaultCode(): string
    {
        if (self::$defaultCodeCache !== null) {
            return self::$defaultCodeCache;
        }

        $code = Currency::where('is_default', 1)->value('code');

        return self::$defaultCodeCache = $code !== null ? (string) $code : 'PHP';
    }

    /**
     * Symbol for an arbitrary currency code, or null if that code isn't
     * configured in `currencies` (e.g. a marketplace order carrying a code
     * nobody has set up locally yet).
     */
    private static function lookupSymbol(string $code): ?string
    {
        $code = strtoupper($code);

        if (array_key_exists($code, self::$symbolCache)) {
            return self::$symbolCache[$code];
        }

        $symbol = Currency::whereRaw('UPPER(code) = ?', [$code])->value('symbol');

        return self::$symbolCache[$code] = $symbol !== null ? (string) $symbol : null;
    }

    /** Public symbol lookup, falling back to the code itself when unconfigured. */
    public static function symbolFor(?string $code): string
    {
        if (!$code) {
            return '';
        }

        return self::lookupSymbol($code) ?? strtoupper($code);
    }

    /** Clears the request-lifetime symbol cache. Test use only. */
    public static function flushCache(): void
    {
        self::$defaultSymbolCache = null;
        self::$defaultCodeCache = null;
        self::$symbolCache = [];
    }

    /**
     * Format an amount already in the default (PHP) currency: "₱1,234.56",
     * or "-₱1,234.56" for a loss/negative figure - the sign sits before the
     * symbol, matching how every accounting figure in this app reads.
     */
    public static function base(float $amount): string
    {
        $sign = $amount < 0 ? '-' : '';

        return $sign . self::defaultSymbol() . number_format(abs($amount), 2);
    }

    /**
     * Abbreviated default-currency figure for an AXIS TICK and nothing else:
     * "₱0" / "₱750" / "₱12.5K" / "₱4.2M".
     *
     * Exists so a chart's value scale can resolve its symbol here rather
     * than in a Blade - a view that calls defaultSymbol() and assembles its
     * own string is how a hardcoded "₱" gets back in, and the view sweep
     * rejects it for that reason. Never use this for a figure a person acts
     * on: an abbreviated amount has thrown away the pesos, and every such
     * figure on a page belongs in base(). Charts abbreviate the scale and
     * spell the value out in full in the tooltip and the table beside it.
     */
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

    /**
     * Format an amount in a named foreign currency: "$1,234.56" when the
     * code is configured, or "XYZ 1,234.56" (space-separated) when it
     * isn't - an unresolved raw code reads better with a separator than
     * jammed against the number.
     */
    public static function foreign(float $amount, string $code): string
    {
        $sign = $amount < 0 ? '-' : '';
        $symbol = self::lookupSymbol($code);
        $formatted = number_format(abs($amount), 2);

        return $symbol !== null
            ? $sign . $symbol . $formatted
            : $sign . strtoupper($code) . ' ' . $formatted;
    }

    /**
     * Plain-text dual representation for non-HTML contexts (activity log
     * entries, exports, CLI output): "₱308,071.47 ($5,000.00)", or just the
     * base figure when there's no foreign amount to show. The on-page
     * two-line presentation lives in <x-money> - it needs markup this
     * method deliberately does not produce.
     */
    public static function dual(float $php, ?float $foreign, ?string $foreignCode): string
    {
        $base = self::base($php);

        if ($foreign === null || !$foreignCode) {
            return $base;
        }

        return $base . ' (' . self::foreign($foreign, $foreignCode) . ')';
    }
}
