<?php

namespace App\Support;

final class StoreKey
{
    private const SOURCE_NAMES_STORE = ['opencart', 'ventacart', 'shopify', 'woocommerce'];

    public const MANUAL = 'manual';

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
                    if ($p['source'] === '') {
                        $w->whereNull($col('marketplace_source'))->orWhere($col('marketplace_source'), '');

                        return;
                    }
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
