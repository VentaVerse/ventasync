<?php

namespace App\Channels\Listings;

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
        'price_asc' => ['price', 'asc', 'Master Catalog price, low to high'],
        'price_desc' => ['price', 'desc', 'Master Catalog price, high to low'],
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

    public static function joinStore(Builder $query, int $storeId, array $listing, string $productId = 'p.product_id'): Builder
    {
        $query->leftJoin($listing['table'] . ' as sl', function ($join) use ($listing, $storeId, $productId) {
            $join->on('sl.product_id', '=', $productId);
            if ($storeId > 0) {
                $join->where('sl.' . $listing['store'], '=', $storeId);
            }
        });

        return $query;
    }

    public static function soldLast30Days(string $storeKey, string $productId = 'p.product_id'): Builder
    {
        $counted = DB::table('order_statuses')->where('add_revenue', 1)->distinct()->pluck('order_status_id')->all();

        $sold = DB::table('order_products as sop')
            ->join('orders as so', 'so.order_id', '=', 'sop.order_id')
            ->whereColumn('sop.product_id', $productId)
            ->where('so.date_added', '>=', now()->subDays(30))
            ->whereIn('so.order_status_id', $counted ?: [0])
            ->selectRaw('COALESCE(SUM(sop.quantity), 0)');
        StoreKey::apply($sold, [$storeKey], 'so');

        return $sold;
    }

    public static function storePrice(string $listing, string $product = 'p'): Expression
    {
        $hasVariations = "EXISTS (SELECT 1 FROM `product_variation_values` spov WHERE spov.product_id = {$product}.product_id)";
        $start = "(CASE WHEN {$listing}.price IS NOT NULL AND {$listing}.price > 0 AND NOT {$hasVariations} THEN {$listing}.price ELSE {$product}.price END)";

        return DB::raw("({$start} + {$start} * COALESCE({$listing}.markup_percent, 0) / 100 + COALESCE({$listing}.markup_fixed, 0))");
    }
}
