<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductAttribute;
use Extensions\lazada\Models\LazadaProductGroupProduct;
use Extensions\lazada\Models\LazadaProductVariant;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Facades\DB;

final class LazadaProductRemover
{
    private const GONE = ['DELETED', 'MISSING', 'NOT_FOUND'];

    public function presence(array $productIds): array
    {
        $rows = LazadaProduct::query()->whereIn('product_id', $productIds)
            ->whereNotNull('lazada_item_id')->whereNull('lazada_deleted_at')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $stores = $this->stores($rows->pluck('lazada_setting_id'));

        $out = [];
        foreach ($rows as $row) {
            $status = strtoupper((string) ($row->live_status ?? ''));
            $out[(int) $row->product_id][] = [
                'channel' => 'Lazada',
                'store' => $stores[(int) $row->lazada_setting_id] ?? 'store #' . (int) $row->lazada_setting_id,
                'item' => (string) $row->lazada_item_id,
                'live' => $status === '' ? null : !in_array($status, self::GONE, true),
            ];
        }

        return $out;
    }

    public function remove(int $productId): array
    {
        $rows = LazadaProduct::query()->where('product_id', $productId)->get();
        $stores = $this->stores($rows->pluck('lazada_setting_id'));
        foreach ($rows->pluck('lazada_setting_id')->filter()->unique() as $storeId) {
            LazadaListingStates::on((int) $storeId)->clearErrors([$productId]);
        }
        $stays = [];
        foreach ($rows as $row) {
            $storeId = (int) $row->lazada_setting_id;
            $itemId = trim((string) ($row->lazada_item_id ?? ''));
            $onLazada = $itemId !== '' && is_null($row->lazada_deleted_at)
                && !in_array(strtoupper((string) ($row->live_status ?? '')), self::GONE, true);
            if ($onLazada) {
                $stays['Lazada (' . ($stores[$storeId] ?? 'store #' . $storeId) . ')'] = true;
            }
            LazadaProductAttribute::query()->where('lazada_product_id', $row->id)->delete();
            LazadaProductVariant::query()->where('lazada_product_id', $row->id)->delete();
            LazadaProductGroupProduct::query()->where('lazada_product_id', $row->id)->delete();
            $row->delete();
        }
        LazadaProductGroupProduct::query()->where('product_id', $productId)->delete();

        return array_keys($stays);
    }

    public function stranded(): array
    {
        $pfx = (string) config('catalog.prefix');
        $ids = [];
        foreach (['lazada_products', 'lazada_product_group_products'] as $table) {
            $ids = array_merge($ids, DB::table($table)
                ->whereNotNull('product_id')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($pfx . 'product as op')->whereColumn('op.product_id', $table . '.product_id'))
                ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all());
        }

        return array_values(array_unique($ids));
    }

    private function stores($ids): array
    {
        return LazadaSetting::query()->whereIn('id', collect($ids)->filter()->unique()->all())
            ->get()->mapWithKeys(fn ($s) => [(int) $s->id => (string) ($s->store_name ?: 'Lazada store #' . $s->id)])->all();
    }
}
