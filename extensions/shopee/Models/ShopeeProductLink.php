<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeProductLink extends Model
{
    use \Extensions\shopee\Models\Concerns\BelongsToShopeeStore;

    protected $table = 'shopee_product_links';

    protected $fillable = [
        'shopee_setting_id',
        'product_id',
        'shopee_item_id',
        'shopee_model_id',
        'sku',
        'live_status',
        'live_checked_at',
        'last_synced_at',
        'last_sync_action',
        'last_sync_ok',
        'last_sync_error_code',
        'last_sync_error_message',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'live_checked_at' => 'datetime',
        'last_sync_ok' => 'boolean',
    ];

    public static function mirrorCounts(array $tabMap, ?array $productIds = null): array
    {
        $byStatus = static::query()
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds ?: [0]))
            ->whereNotNull('live_status')
            ->selectRaw('live_status, COUNT(DISTINCT product_id) as n')
            ->groupBy('live_status')
            ->pluck('n', 'live_status');

        $out = [];
        foreach ($tabMap as $tabKey => $status) {
            $out[$tabKey] = (int) ($byStatus[$status] ?? 0);
        }

        return $out;
    }

    // Clear the link, the group pivot and the item cache together, for this store only.
    // A stale id in any of them re-attaches the product; another store's link must survive.
    public static function unlinkProduct(int $productId, int $storeId): int
    {
        $mine = fn () => static::query()->withoutGlobalScope('shopeeStore')
            ->where('shopee_setting_id', $storeId)->where('product_id', $productId);

        foreach ($mine()->get() as $link) {
            ShopeeItemCache::query()
                ->where('shopee_item_id', $link->shopee_item_id)
                ->where('sku', $link->sku)
                ->delete();
        }

        $removed = $mine()->delete();

        ShopeeProductGroupProduct::query()
            ->where('product_id', $productId)
            ->whereIn('shopee_product_group_id', ShopeeProductGroup::query()
                ->withoutGlobalScope('shopeeStore')->where('shopee_setting_id', $storeId)->select('id'))
            ->update([
                'shopee_item_id' => null,
                'sync_status' => 'unlinked',
                'push_error' => null,
            ]);

        app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)->forStore($storeId)->clearErrors([$productId]);

        return $removed;
    }
}
