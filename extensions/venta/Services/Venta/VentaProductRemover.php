<?php

namespace Extensions\venta\Services\Venta;

use Extensions\venta\Models\VentaListing;
use Extensions\venta\Models\VentaProductGroupProduct;
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Illuminate\Support\Facades\DB;

final class VentaProductRemover
{
    public function presence(array $productIds): array
    {
        $links = VentaProductLink::query()->whereIn('product_id', $productIds)->get();
        if ($links->isEmpty()) {
            return [];
        }
        $stores = $this->stores($links->pluck('venta_setting_id'));
        $live = VentaListing::query()->whereIn('product_id', $productIds)->get()
            ->keyBy(fn ($l) => (int) $l->venta_setting_id . ':' . (int) $l->product_id);

        $out = [];
        foreach ($links as $link) {
            $status = strtoupper((string) ($live->get((int) $link->venta_setting_id . ':' . (int) $link->product_id)?->live_status ?? ''));
            $out[(int) $link->product_id][] = [
                'channel' => 'Venta',
                'store' => $stores[(int) $link->venta_setting_id] ?? 'store #' . (int) $link->venta_setting_id,
                'item' => (string) ($link->venta_product_id ?: $link->sku),
                'live' => $status === '' ? null : !in_array($status, ['MISSING', 'DELETED'], true),
            ];
        }

        return $out;
    }

    public function remove(int $productId): array
    {
        $links = VentaProductLink::query()->where('product_id', $productId)->get();
        $stores = $this->stores($links->pluck('venta_setting_id'));
        $stays = [];
        foreach ($links as $link) {
            if (trim((string) $link->sku) !== '' || $link->venta_product_id) {
                $stays['Venta (' . ($stores[(int) $link->venta_setting_id] ?? 'store #' . (int) $link->venta_setting_id) . ')'] = true;
            }
        }
        $storeIds = $links->pluck('venta_setting_id')
            ->merge(VentaListing::query()->where('product_id', $productId)->pluck('venta_setting_id'))
            ->map(fn ($v) => (int) $v)->filter()->unique();
        foreach ($storeIds as $storeId) {
            VentaListingStates::for($storeId)->clearErrors([$productId]);
        }
        VentaProductLink::query()->where('product_id', $productId)->delete();
        VentaListing::query()->where('product_id', $productId)->delete();
        VentaProductGroupProduct::query()->where('product_id', $productId)->delete();

        return array_keys($stays);
    }

    public function stranded(): array
    {
        $pfx = (string) config('catalog.prefix');
        $ids = [];
        foreach (['venta_product_links', 'venta_listings', 'venta_product_group_products'] as $table) {
            $ids = array_merge($ids, DB::table($table)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($pfx . 'product as op')->whereColumn('op.product_id', $table . '.product_id'))
                ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all());
        }

        return array_values(array_unique($ids));
    }

    private function stores($ids): array
    {
        return VentaSetting::query()->whereIn('id', collect($ids)->filter()->unique()->all())
            ->get()->mapWithKeys(fn ($s) => [(int) $s->id => (string) ($s->store_name ?: 'Venta store #' . $s->id)])->all();
    }
}
