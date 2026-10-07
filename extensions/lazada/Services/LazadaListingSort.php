<?php

namespace Extensions\lazada\Services;

use App\Integrations\Listings\ListingSort;
use App\Support\StoreKey;
use Illuminate\Database\Query\Builder;

final class LazadaListingSort
{
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
            'sold' => ListingSort::soldLast30Days(StoreKey::of('lazada', $storeId)),
        ];
    }

    public static function join(Builder $query, int $storeId): void
    {
        ListingSort::joinStore($query, $storeId, ['table' => 'lazada_products', 'store' => 'lazada_setting_id'], self::GROUP);
    }

    public static function joinGroup(Builder $query, int $storeId): void
    {
        ListingSort::joinGroupMarkup($query, $storeId, self::GROUP);
    }

    private const GROUP = ['pivot' => 'lazada_product_group_products', 'table' => 'lazada_product_groups', 'key' => 'lazada_product_group_id', 'store' => 'lazada_setting_id'];
}
