<?php

namespace App\Support;

/**
 * A store key names one store wherever a filter travels.
 *
 * "shopee:2" is source "shopee" and store_id 2 on the core order.
 * "opencart:1" is a source that already names its store: OpenCart, Venta
 * and Shopify write the store into marketplace_source and keep store_id
 * for their own numbers. "shopee" alone means every store of the channel,
 * which is what a link saved before per-store reports meant.
 *
 * Nothing else parses the string (per-store reports, the owner, 2026-09-05).
 */
final class StoreKey
{
    /** Channels whose marketplace_source already carries the store. */
    private const SOURCE_NAMES_STORE = ['opencart', 'venta', 'shopify', 'woocommerce'];

    /** Orders entered in VentaSync itself: no channel, no store. */
    public const MANUAL = 'manual';

    /** @return array{source:string, store_id:?int}|null */
    public static function parse(string $key): ?array
    {
        if ($key === self::MANUAL) {
            return ['source' => '', 'store_id' => null];
        }

        if (! preg_match('/^([a-z][a-z0-9_-]*)(?::(\d+))?$/', $key, $m)) {
            return null;
        }
        $channel = $m[1];
        $id = isset($m[2]) ? (int) $m[2] : null;

        if (in_array($channel, self::SOURCE_NAMES_STORE, true)) {
            return ['source' => $key, 'store_id' => null];
        }

        return ['source' => $channel, 'store_id' => $id];
    }

    public static function of(string $source, int $storeId): string
    {
        return str_contains($source, ':') ? $source : $source . ':' . $storeId;
    }

    /**
     * Narrow an order query to any of the given keys. Unknown keys are
     * dropped; an empty list leaves the query alone.
     */
    public static function apply($query, array $keys, string $alias = 'o'): void
    {
        $parsed = array_values(array_filter(array_map(fn ($k) => self::parse((string) $k), $keys)));
        if ($parsed === []) {
            return;
        }
        $col = fn (string $name) => $alias !== '' ? "{$alias}.{$name}" : $name;
        $query->where(function ($q) use ($parsed, $col) {
            foreach ($parsed as $p) {
                $q->orWhere(function ($w) use ($p, $col) {
                    // A manual order carries no source at all.
                    if ($p['source'] === '') {
                        $w->whereNull($col('marketplace_source'))->orWhere($col('marketplace_source'), '');

                        return;
                    }
                    /* "opencart" alone is every OpenCart store. Their
                       sources carry the store ("opencart:1"), so the exact
                       match a bare channel gets found none of them. */
                    if (in_array($p['source'], self::SOURCE_NAMES_STORE, true)) {
                        $w->where($col('marketplace_source'), $p['source'])
                            ->orWhere($col('marketplace_source'), 'like', $p['source'] . ':%');

                        return;
                    }
                    $w->where($col('marketplace_source'), $p['source']);
                    if ($p['store_id'] !== null) {
                        $w->where($col('store_id'), $p['store_id']);
                    }
                });
            }
        });
    }
}
