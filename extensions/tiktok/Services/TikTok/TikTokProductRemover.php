<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Illuminate\Support\Facades\DB;

final class TikTokProductRemover
{
    private const GONE = ['MISSING', 'DELETED', 'PLATFORM_DEACTIVATED', 'FREEZE'];

    public function presence(array $productIds): array
    {
        $out = [];
        $stores = [];
        foreach ($this->holdings($productIds) as $h) {
            $stores[$h['store_id']] ??= $this->storeName($h['store_id']);
            $status = strtoupper((string) $h['live_status']);
            $out[$h['product_id']][] = [
                'channel' => 'TikTok Shop',
                'store' => $stores[$h['store_id']],
                'item' => $h['tiktok_product_id'],
                'live' => $status === '' ? null : !in_array($status, self::GONE, true),
            ];
        }

        return $out;
    }

    public function remove(int $productId): array
    {
        $stays = [];
        foreach ($this->holdings([$productId]) as $h) {
            if (!in_array(strtoupper((string) $h['live_status']), self::GONE, true)) {
                $stays['TikTok Shop (' . $this->storeName($h['store_id']) . ')'] = true;
            }
        }
        foreach (TikTokSetting::allStores() as $store) {
            app(TikTokListingStates::class)->forStore($store)->clearErrors([$productId]);
        }
        TikTokListing::query()->where('product_id', $productId)->delete();
        TikTokProductGroupProduct::query()->where('product_id', $productId)->delete();

        return array_keys($stays);
    }

    public function stranded(): array
    {
        $pfx = (string) config('catalog.prefix');
        $ids = [];
        foreach (['tiktok_listings', 'tiktok_product_group_products'] as $table) {
            $ids = array_merge($ids, DB::table($table)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($pfx . 'product as op')->whereColumn('op.product_id', $table . '.product_id'))
                ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all());
        }

        return array_values(array_unique($ids));
    }

    private function holdings(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $out = [];
        $seen = [];
        $add = function (int $pid, int $storeId, string $ttId, ?string $status) use (&$out, &$seen) {
            $key = $pid . ':' . $storeId . ':' . $ttId;
            if ($ttId === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = ['product_id' => $pid, 'store_id' => $storeId, 'tiktok_product_id' => $ttId, 'live_status' => $status];
        };

        foreach (TikTokListing::query()->whereIn('product_id', $productIds)->whereNotNull('tiktok_product_id')->get() as $l) {
            $add((int) $l->product_id, (int) $l->tiktok_setting_id, (string) $l->tiktok_product_id, $l->live_status);
        }
        $pivots = DB::table('tiktok_product_group_products as pv')
            ->join('tiktok_product_groups as g', 'g.id', '=', 'pv.tiktok_product_group_id')
            ->whereIn('pv.product_id', $productIds)->whereNotNull('pv.tiktok_product_id')
            ->get(['pv.product_id', 'pv.tiktok_product_id', 'g.tiktok_setting_id']);
        foreach ($pivots as $p) {
            $add((int) $p->product_id, (int) $p->tiktok_setting_id, (string) $p->tiktok_product_id, null);
        }

        return $out;
    }

    private function storeName(int $storeId): string
    {
        $s = TikTokSetting::query()->find($storeId);

        return (string) ($s?->store_name ?: ($s?->shop_name ?: 'TikTok store #' . $storeId));
    }
}
