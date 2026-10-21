<?php

namespace App\Channels\Listings;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class VariationPrices
{
    public const TABLE = 'ch_listing_variation_prices';

    public static function rules(): array
    {
        return [
            'variation_prices' => 'nullable|array',
            'variation_prices.*.sku' => 'required|string|max:100',
            'variation_prices.*.price' => 'nullable|numeric|min:0|max:99999999',
        ];
    }

    public static function submitted(Request $request): ?array
    {
        if (! $request->has('variation_prices')) {
            return null;
        }

        $out = [];
        foreach ((array) $request->input('variation_prices', []) as $row) {
            if (is_array($row) && trim((string) ($row['sku'] ?? '')) !== '') {
                $out[trim((string) $row['sku'])] = $row['price'] ?? null;
            }
        }

        return $out;
    }

    public static function forProducts(string $channel, int $storeId, array $productIds): array
    {
        $out = [];
        foreach (DB::table(self::TABLE)
            ->where('channel', $channel)->where('store_id', $storeId)
            ->whereIn('product_id', $productIds ?: [0])
            ->get(['product_id', 'sku', 'price']) as $r) {
            $out[(int) $r->product_id][$r->sku] = (float) $r->price;
        }

        return $out;
    }

    public static function base(array $own, int $productId, ?string $sku, float $catalog): float
    {
        return $own[$productId][ListingVariations::key($sku)] ?? $catalog;
    }

    public static function catalog(array $productIds): array
    {
        $parents = DB::table('products')->whereIn('product_id', $productIds ?: [0])->pluck('price', 'product_id');
        $out = [];
        $combos = DB::table('product_variations')->whereIn('product_id', $productIds ?: [0])->get(['product_id', 'sku', 'absolute_price']);
        $withCombos = $combos->pluck('product_id')->map(fn ($v) => (int) $v)->flip()->all();
        foreach ($combos as $c) {
            $out[(int) $c->product_id][ListingVariations::key($c->sku)] = self::orParent((float) $c->absolute_price, (float) ($parents[$c->product_id] ?? 0));
        }
        foreach (DB::table('product_variation_values')->whereIn('product_id', $productIds ?: [0])->get(['product_id', 'sku', 'absolute_price']) as $v) {
            if (! isset($withCombos[(int) $v->product_id])) {
                $out[(int) $v->product_id][ListingVariations::key($v->sku)] = self::orParent((float) $v->absolute_price, (float) ($parents[$v->product_id] ?? 0));
            }
        }

        return $out;
    }

    public static function save(string $channel, int $storeId, int $productId, array $prices): void
    {
        $known = self::catalog([$productId])[$productId] ?? [];
        DB::transaction(function () use ($channel, $storeId, $productId, $prices, $known) {
            foreach ($prices as $sku => $price) {
                if (! isset($known[ListingVariations::key($sku)])) {
                    continue;
                }
                self::set($channel, $storeId, $productId, (string) $sku, $price === null || $price === '' ? null : (float) $price);
            }
        });
    }

    public static function set(string $channel, int $storeId, int $productId, string $sku, ?float $price): void
    {
        $key = ListingVariations::key($sku);
        if ($key === '' || $storeId <= 0) {
            return;
        }
        $where = ['channel' => $channel, 'store_id' => $storeId, 'product_id' => $productId, 'sku' => $key];
        if ($price === null) {
            DB::table(self::TABLE)->where($where)->delete();

            return;
        }
        $now = now();
        DB::table(self::TABLE)->upsert([$where + ['price' => $price, 'created_at' => $now, 'updated_at' => $now]],
            ['channel', 'store_id', 'product_id', 'sku'], ['price', 'updated_at']);
    }

    public static function forget(int $productId, array $skus): void
    {
        $keys = array_values(array_filter(array_map(fn ($s) => ListingVariations::key($s), $skus)));
        if ($keys !== []) {
            DB::table(self::TABLE)->where('product_id', $productId)->whereIn('sku', $keys)->delete();
        }
    }

    public static function forgetProducts(array $productIds): void
    {
        DB::table(self::TABLE)->whereIn('product_id', $productIds ?: [0])->delete();
    }

    private static function orParent(float $price, float $parent): float
    {
        return $price > 0 ? $price : $parent;
    }
}
