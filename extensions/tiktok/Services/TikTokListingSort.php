<?php

namespace Extensions\TikTok\Services;

use App\Channels\Listings\ListingSort;
use App\Support\StoreKey;
use Illuminate\Database\Query\Builder;

final class TikTokListingSort
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
            'sold' => ListingSort::soldLast30Days(StoreKey::of('tiktok', $storeId)),
        ];
    }

    public static function join(Builder $query, int $storeId): void
    {
        ListingSort::joinStore($query, $storeId, ['table' => 'ch_tiktok_listings', 'store' => 'store_id'], self::GROUP);
    }

    public static function joinGroup(Builder $query, int $storeId): void
    {
        ListingSort::joinGroupMarkup($query, $storeId, self::GROUP);
    }

    private const GROUP = ['pivot' => 'ch_tiktok_product_group_products', 'table' => 'ch_tiktok_product_groups', 'key' => 'tiktok_product_group_id', 'store' => 'store_id'];
}
