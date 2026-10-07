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

        $pfx = (string) config('catalog.prefix');
        $lang = (int) config('catalog.default_language_id');
        $t = $query->getModel()->getTable();
        $name = self::compact('pd.name');
        $sku = self::compact($t . '.sku');
        $model = self::compact($t . '.model');
        $brand = self::compact('m.name');
        $variationSku = self::compact('pov.sku');

        foreach ($words as $word) {
            $like = '%' . $word . '%';
            $query->where(function ($q) use ($like, $pfx, $t, $lang, $name, $sku, $model, $brand, $variationSku) {
                $q->whereRaw("{$sku} LIKE ?", [$like])
                    ->orWhereRaw("{$model} LIKE ?", [$like])
                    ->orWhereExists(fn ($d) => $d->selectRaw('1')->from($pfx . 'product_description as pd')
                        ->whereColumn('pd.product_id', $t . '.product_id')->where('pd.language_id', $lang)
                        ->whereRaw("{$name} LIKE ?", [$like]))
                    ->orWhereExists(fn ($d) => $d->selectRaw('1')->from($pfx . 'manufacturer as m')
                        ->whereColumn('m.manufacturer_id', $t . '.manufacturer_id')
                        ->whereRaw("{$brand} LIKE ?", [$like]))
                    ->orWhereExists(fn ($d) => $d->selectRaw('1')->from($pfx . 'product_option_value as pov')
                        ->whereColumn('pov.product_id', $t . '.product_id')
                        ->whereRaw("{$variationSku} LIKE ?", [$like]));
            });
        }

        $whole = implode('', $words);
        $query->orderByRaw(
            "CASE WHEN {$sku} = ? OR {$model} = ? THEN 0 "
            . "WHEN EXISTS (SELECT 1 FROM {$pfx}product_description pd WHERE pd.product_id = {$t}.product_id AND pd.language_id = ? AND {$name} LIKE ?) THEN 1 "
            . 'ELSE 2 END',
            [$whole, $whole, $lang, '%' . $whole . '%']
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
