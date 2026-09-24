<?php

namespace App\Support\Catalog;

use Illuminate\Database\Eloquent\Builder;

/**
 * Finds products the way a person types them: every word has to appear in
 * the name, SKU, model, brand or a variation's SKU, in any order, with
 * spaces, hyphens, dots, slashes and underscores ignored, so "ISO 8",
 * "ISO-8" and "iso8" ask for the same thing. The best matches come first.
 */
final class ProductSearch
{
    /**
     * Narrow and order a product query. A search with no words changes nothing.
     *
     * @param  Builder  $query  a query over the catalog product model
     */
    public static function apply(Builder $query, string $search): Builder
    {
        $words = self::words($search);
        if ($words === []) {
            return $query;
        }

        $pfx = (string) config('catalog.prefix');
        $lang = (int) config('catalog.default_language_id');
        // The product table as the model names it, prefix and all.
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

        // Best first: the SKU or model exactly, then a name holding the
        // words together ("iso8"), then everything else.
        $whole = implode('', $words);
        $query->orderByRaw(
            "CASE WHEN {$sku} = ? OR {$model} = ? THEN 0 "
            . "WHEN EXISTS (SELECT 1 FROM {$pfx}product_description pd WHERE pd.product_id = {$t}.product_id AND pd.language_id = ? AND {$name} LIKE ?) THEN 1 "
            . 'ELSE 2 END',
            [$whole, $whole, $lang, '%' . $whole . '%']
        );

        return $query;
    }

    /**
     * The words of a search, each reduced to letters and digits in lower case.
     *
     * @return list<string>
     */
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

    /** A column in lower case with its spaces, hyphens, dots, slashes and underscores taken out. */
    private static function compact(string $column): string
    {
        return "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), ' ', ''), '-', ''), '_', ''), '.', ''), '/', ''))";
    }
}
