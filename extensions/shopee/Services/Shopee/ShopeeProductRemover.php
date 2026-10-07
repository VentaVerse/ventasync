<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeItemCache;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductGroupProduct;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Support\Facades\DB;

final class ShopeeProductRemover
{
    private const GONE = ['BANNED', 'SELLER_DELETE', 'SHOPEE_DELETE', 'MISSING'];

    public function presence(array $productIds): array
    {
        $links = ShopeeProductLink::query()->whereIn('product_id', $productIds)->whereNotNull('shopee_item_id')->get();
        if ($links->isEmpty()) {
            return [];
        }
        $stores = $this->stores($links->pluck('shopee_setting_id'));

        $out = [];
        $seen = [];
        foreach ($links as $link) {
            $key = (int) $link->product_id . ':' . (int) $link->shopee_setting_id . ':' . (int) $link->shopee_item_id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $status = strtoupper((string) ($link->live_status ?? ''));
            $out[(int) $link->product_id][] = [
                'channel' => 'Shopee',
                'store' => $stores[(int) $link->shopee_setting_id] ?? 'store #' . (int) $link->shopee_setting_id,
                'item' => (string) $link->shopee_item_id,
                'live' => $status === '' ? null : !in_array($status, self::GONE, true),
            ];
        }

        return $out;
    }

    public function remove(int $productId): array
    {
        $links = ShopeeProductLink::query()->where('product_id', $productId)->get();
        $stores = $this->stores($links->pluck('shopee_setting_id'));
        $stays = [];

        $storeIds = $links->pluck('shopee_setting_id')
            ->merge(ShopeeListing::query()->where('product_id', $productId)->pluck('shopee_setting_id'))
            ->map(fn ($v) => (int) $v)->filter()->unique();
        foreach ($storeIds as $storeId) {
            app(ShopeeListingStates::class)->forStore($storeId)->clearErrors([$productId]);
        }
        foreach ($links->filter(fn ($l) => (int) $l->shopee_item_id > 0) as $link) {
            $gone = in_array(strtoupper((string) ($link->live_status ?? '')), self::GONE, true);
            if (!$gone) {
                $stays['Shopee (' . ($stores[(int) $link->shopee_setting_id] ?? 'store #' . (int) $link->shopee_setting_id) . ')'] = true;
            }
            ShopeeItemCache::query()->where('shopee_setting_id', (int) $link->shopee_setting_id)->where('shopee_item_id', (int) $link->shopee_item_id)->delete();
        }
        ShopeeProductLink::query()->where('product_id', $productId)->delete();
        ShopeeListing::query()->where('product_id', $productId)->delete();
        ShopeeProductGroupProduct::query()->where('product_id', $productId)->delete();

        return array_keys($stays);
    }

    public function stranded(): array
    {
        $pfx = (string) config('catalog.prefix');
        $ids = [];
        foreach (['shopee_product_links', 'shopee_listings', 'shopee_product_group_products'] as $table) {
            $ids = array_merge($ids, DB::table($table)
                ->whereNotNull('product_id')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($pfx . 'product as op')->whereColumn('op.product_id', $table . '.product_id'))
                ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all());
        }

        return array_values(array_unique($ids));
    }

    private function stores($ids): array
    {
        return ShopeeSetting::query()->whereIn('id', collect($ids)->filter()->unique()->all())
            ->get()->mapWithKeys(fn ($s) => [(int) $s->id => (string) ($s->store_name ?: 'Shopee store #' . $s->id)])->all();
    }
}
