<?php

namespace Extensions\shopee\Services;

use App\Integrations\Listings\ListingSort;
use App\Support\StoreKey;
use Illuminate\Database\Query\Builder;

/**
 * What the Sort menu orders this store's products by, on the Listings page
 * and on a product group's page (which puts its own "added" and "pushed"
 * first). The shared behaviour is ListingSort's.
 */
final class ShopeeListingSort
{
    /**
     * @param  string  $listing  alias of this store's listing row in the query
     * @return array<string, mixed>
     */
    public static function columns(int $storeId, string $listing = 'sl'): array
    {
        return [
            'added' => $listing . '.created_at',
            'name' => 'pd.name',
            'catalog_added' => 'p.date_added',
            'price' => 'p.price',
            'store_price' => ListingSort::storePrice($listing, 'sg'),
            'stock' => 'p.quantity',
            'sku' => 'p.sku',
            'brand' => 'm.name',
            'pushed' => $listing . '.last_pushed_at',
            'sold' => ListingSort::soldLast30Days(StoreKey::of('shopee', $storeId)),
        ];
    }

    /** Joins this store's listing row as `sl` and the group markup as `sg`. */
    public static function join(Builder $query, int $storeId): void
    {
        ListingSort::joinStore($query, $storeId, ['table' => 'shopee_listings', 'store' => 'shopee_setting_id'], self::GROUP);
    }

    /** Joins only the group markup, for a query that already has the listing row. */
    public static function joinGroup(Builder $query, int $storeId): void
    {
        ListingSort::joinGroupMarkup($query, $storeId, self::GROUP);
    }

    private const GROUP = ['pivot' => 'shopee_product_group_products', 'table' => 'shopee_product_groups', 'key' => 'shopee_product_group_id', 'store' => 'shopee_setting_id'];
}
