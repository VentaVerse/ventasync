<?php

namespace App\Integrations\Listings;

use App\Support\StoreKey;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ListingSort
{
    public const DEFAULT = 'added_desc';

    private const OPTIONS = [
        'added_desc' => ['added', 'desc', 'Newest added here'],
        'added_asc' => ['added', 'asc', 'Oldest added here'],
        'name_asc' => ['name', 'asc', 'Name, A to Z'],
        'name_desc' => ['name', 'desc', 'Name, Z to A'],
        'catalog_desc' => ['catalog_added', 'desc', 'Newest in the Master Catalog'],
        'catalog_asc' => ['catalog_added', 'asc', 'Oldest in the Master Catalog'],
        'price_asc' => ['price', 'asc', 'Catalog price, low to high'],
        'price_desc' => ['price', 'desc', 'Catalog price, high to low'],
        'store_price_asc' => ['store_price', 'asc', 'Price on this store, low to high'],
        'store_price_desc' => ['store_price', 'desc', 'Price on this store, high to low'],
        'stock_asc' => ['stock', 'asc', 'Stock, low to high'],
        'stock_desc' => ['stock', 'desc', 'Stock, high to low'],
        'sku_asc' => ['sku', 'asc', 'SKU'],
        'brand_asc' => ['brand', 'asc', 'Brand'],
        'pushed_desc' => ['pushed', 'desc', 'Last pushed to the store'],
        'sold_desc' => ['sold', 'desc', 'Best sellers, last 30 days'],
    ];

    public static function chosen(Request $request, string $page): string
    {
        $key = 'listing_sort.' . $page;
        $asked = (string) $request->query('order', '');

        if (isset(self::OPTIONS[$asked])) {
            $request->session()->put($key, $asked);

            return $asked;
        }

        $remembered = (string) $request->session()->get($key, '');

        return isset(self::OPTIONS[$remembered]) ? $remembered : self::DEFAULT;
    }

    public static function options(array $columns): array
    {
        $out = [];
        foreach (self::OPTIONS as $option => [$column, , $label]) {
            if (in_array($column, $columns, true)) {
                $out[$option] = $label;
            }
        }

        return $out;
    }

    public static function apply(Builder $query, string $option, array $columns, string $productId = 'p.product_id'): Builder
    {
        [$column, $direction] = self::OPTIONS[$option] ?? self::OPTIONS[self::DEFAULT];
        $source = $columns[$column] ?? $columns['added'] ?? $productId;

        if ($source instanceof Builder) {
            $query->orderBy($source, $direction);
        } else {
            $sql = $source instanceof Expression ? $source->getValue($query->getGrammar()) : $query->getGrammar()->wrap($source);
            $query->orderByRaw("({$sql}) IS NULL")->orderByRaw("{$sql} {$direction}");
        }

        return $query->orderBy($productId, $direction === 'asc' ? 'asc' : 'desc');
    }

    public static function joinStore(Builder $query, int $storeId, array $listing, array $group, string $productId = 'p.product_id'): Builder
    {
        $query->leftJoin($listing['table'] . ' as sl', function ($join) use ($listing, $storeId, $productId) {
            $join->on('sl.product_id', '=', $productId);
            if ($storeId > 0) {
                $join->where('sl.' . $listing['store'], '=', $storeId);
            }
        });

        return self::joinGroupMarkup($query, $storeId, $group, $productId);
    }

    public static function joinGroupMarkup(Builder $query, int $storeId, array $group, string $productId = 'p.product_id'): Builder
    {
        $markup = DB::table($group['pivot'] . ' as sgp')
            ->join($group['table'] . ' as sgt', 'sgt.id', '=', 'sgp.' . $group['key'])
            ->when($storeId > 0, fn ($q) => $q->where('sgt.' . $group['store'], $storeId))
            ->groupBy('sgp.product_id')
            ->selectRaw('sgp.product_id, MAX(sgt.markup_percent) AS markup_percent, MAX(sgt.markup_fixed) AS markup_fixed');

        return $query->leftJoinSub($markup, 'sg', 'sg.product_id', '=', $productId);
    }

    public static function soldLast30Days(string $storeKey, string $productId = 'p.product_id'): Builder
    {
        $pfx = (string) config('catalog.prefix');
        $counted = DB::table($pfx . 'order_status')->where('add_revenue', 1)->distinct()->pluck('order_status_id')->all();

        $sold = DB::table($pfx . 'order_product as sop')
            ->join($pfx . 'order as so', 'so.order_id', '=', 'sop.order_id')
            ->whereColumn('sop.product_id', $productId)
            ->where('so.date_added', '>=', now()->subDays(30))
            ->whereIn('so.order_status_id', $counted ?: [0])
            ->selectRaw('COALESCE(SUM(sop.quantity), 0)');
        StoreKey::apply($sold, [$storeKey], 'so');

        return $sold;
    }

    public static function storePrice(string $listing, ?string $group = null, string $product = 'p'): Expression
    {
        $pfx = (string) config('catalog.prefix');
        $hasVariations = "EXISTS (SELECT 1 FROM `{$pfx}product_option_value` spov WHERE spov.product_id = {$product}.product_id)";
        $start = "(CASE WHEN {$listing}.price IS NOT NULL AND {$listing}.price > 0 AND NOT {$hasVariations} THEN {$listing}.price ELSE {$product}.price END)";
        $rule = fn (string $alias) => "({$start} + {$start} * COALESCE({$alias}.markup_percent, 0) / 100 + COALESCE({$alias}.markup_fixed, 0))";

        $sql = "(CASE WHEN {$listing}.markup_percent IS NOT NULL OR {$listing}.markup_fixed IS NOT NULL THEN " . $rule($listing);
        if ($group !== null) {
            $sql .= " WHEN {$group}.markup_percent IS NOT NULL OR {$group}.markup_fixed IS NOT NULL THEN " . $rule($group);
        }
        $sql .= " ELSE {$start} END)";

        return DB::raw($sql);
    }
}
