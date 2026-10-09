<?php

namespace Extensions\Lazada\Services\Lazada;

use Extensions\Lazada\Models\LazadaListing;
use Extensions\Lazada\Models\LazadaListingAttribute;
use Extensions\Lazada\Models\LazadaProductGroupProduct;
use Extensions\Lazada\Models\LazadaListingVariation;
use Extensions\Lazada\Models\LazadaStore;
use Illuminate\Support\Facades\DB;

final class LazadaProductRemover
{
    private const GONE = ['DELETED', 'MISSING', 'NOT_FOUND'];

    public function presence(array $productIds): array
    {
        $rows = LazadaListing::query()->whereIn('product_id', $productIds)
            ->whereNotNull('lazada_item_id')->whereNull('lazada_deleted_at')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $stores = $this->stores($rows->pluck('store_id'));

        $out = [];
        foreach ($rows as $row) {
            $status = strtoupper((string) ($row->live_status ?? ''));
            $out[(int) $row->product_id][] = [
                'channel' => 'Lazada',
                'store' => $stores[(int) $row->store_id] ?? 'store #' . (int) $row->store_id,
                'item' => (string) $row->lazada_item_id,
                'live' => $status === '' ? null : !in_array($status, self::GONE, true),
            ];
        }

        return $out;
    }

    public function remove(int $productId): array
    {
        $rows = LazadaListing::query()->where('product_id', $productId)->get();
        $stores = $this->stores($rows->pluck('store_id'));
        foreach ($rows->pluck('store_id')->filter()->unique() as $storeId) {
            LazadaListingStates::on((int) $storeId)->clearErrors([$productId]);
        }
        $stays = [];
        foreach ($rows as $row) {
            $storeId = (int) $row->store_id;
            $itemId = trim((string) ($row->lazada_item_id ?? ''));
            $onLazada = $itemId !== '' && is_null($row->lazada_deleted_at)
                && !in_array(strtoupper((string) ($row->live_status ?? '')), self::GONE, true);
            if ($onLazada) {
                $stays['Lazada (' . ($stores[$storeId] ?? 'store #' . $storeId) . ')'] = true;
            }
            LazadaListingAttribute::query()->where('lazada_listing_id', $row->id)->delete();
            LazadaListingVariation::query()->where('lazada_listing_id', $row->id)->delete();
            LazadaProductGroupProduct::query()->where('lazada_listing_id', $row->id)->delete();
            $row->delete();
        }
        LazadaProductGroupProduct::query()->where('product_id', $productId)->delete();

        return array_keys($stays);
    }

    public function stranded(): array
    {
        $ids = [];
        foreach (['ch_lazada_listings', 'ch_lazada_product_group_products'] as $table) {
            $ids = array_merge($ids, DB::table($table)
                ->whereNotNull('product_id')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('products as op')->whereColumn('op.product_id', $table . '.product_id'))
                ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all());
        }

        return array_values(array_unique($ids));
    }

    private function stores($ids): array
    {
        return LazadaStore::query()->whereIn('id', collect($ids)->filter()->unique()->all())
            ->get()->mapWithKeys(fn ($s) => [(int) $s->id => (string) ($s->store_name ?: 'Lazada store #' . $s->id)])->all();
    }
}
