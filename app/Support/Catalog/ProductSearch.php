<?php

namespace App\Support\Catalog;

use Illuminate\Database\Eloquent\Builder;

final class ProductSearch
{
    public static function apply(Builder $query, string $search): Builder
    {
        $words = self::words($search);
        if ($words === []) {
            return $query;
        }
        $lang = (int) config('catalog.default_language_id');
        $t = $query->getModel()->getTable();
        $name = self::compact('pd.name');
        $sku = self::compact($t . '.sku');
        $brand = self::compact('m.name');
        $variationSku = self::compact('pov.sku');

        foreach ($words as $word) {
            $like = '%' . $word . '%';
            $query->where(function ($q) use ($like, $t, $lang, $name, $sku, $brand, $variationSku) {
                $q->whereRaw("{$sku} LIKE ?", [$like])
                    ->orWhereExists(fn ($d) => $d->selectRaw('1')->from('product_descriptions as pd')
                        ->whereColumn('pd.product_id', $t . '.product_id')->where('pd.language_id', $lang)
                        ->whereRaw("{$name} LIKE ?", [$like]))
                    ->orWhereExists(fn ($d) => $d->selectRaw('1')->from('brands as m')
                        ->whereColumn('m.brand_id', $t . '.brand_id')
                        ->whereRaw("{$brand} LIKE ?", [$like]))
                    ->orWhereExists(fn ($d) => $d->selectRaw('1')->from('product_variation_values as pov')
                        ->whereColumn('pov.product_id', $t . '.product_id')
                        ->whereRaw("{$variationSku} LIKE ?", [$like]));
            });
        }

        $whole = implode('', $words);
        $query->orderByRaw(
            "CASE WHEN {$sku} = ? THEN 0 "
            . "WHEN EXISTS (SELECT 1 FROM product_descriptions pd WHERE pd.product_id = {$t}.product_id AND pd.language_id = ? AND {$name} LIKE ?) THEN 1 "
            . 'ELSE 2 END',
            [$whole, $lang, '%' . $whole . '%']
        );

        return $query;
    }

    public static function words(string $search): array
    {
        $words = [];
        foreach (preg_split('/\s+/u', mb_strtolower($search)) ?: [] as $word) {
            $word = preg_replace('/[^\p{L}\p{N}]+/u', '', $word);
            if ($word !== null && $word !== '') {
                $words[] = $word;
            }
        }

        return array_values(array_unique($words));
    }

    private static function compact(string $column): string
    {
        return "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), ' ', ''), '-', ''), '_', ''), '.', ''), '/', ''))";
    }
}
