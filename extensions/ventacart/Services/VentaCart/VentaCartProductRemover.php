<?php

namespace Extensions\ventacart\Services\VentaCart;

use Extensions\ventacart\Models\VentaCartListing;
use Extensions\ventacart\Models\VentaCartProductGroupProduct;
use Extensions\ventacart\Models\VentaCartProductLink;
use Extensions\ventacart\Models\VentaCartSetting;
use Illuminate\Support\Facades\DB;

final class VentaCartProductRemover
{
    public function presence(array $productIds): array
    {
        $links = VentaCartProductLink::query()->whereIn('product_id', $productIds)->get();
        if ($links->isEmpty()) {
            return [];
        }
        $stores = $this->stores($links->pluck('ventacart_setting_id'));
        $live = VentaCartListing::query()->whereIn('product_id', $productIds)->get()
            ->keyBy(fn ($l) => (int) $l->ventacart_setting_id . ':' . (int) $l->product_id);

        $out = [];
        foreach ($links as $link) {
            $status = strtoupper((string) ($live->get((int) $link->ventacart_setting_id . ':' . (int) $link->product_id)?->live_status ?? ''));
            $out[(int) $link->product_id][] = [
                'channel' => 'VentaCart',
                'store' => $stores[(int) $link->ventacart_setting_id] ?? 'store #' . (int) $link->ventacart_setting_id,
                'item' => (string) ($link->ventacart_product_id ?: $link->sku),
                'live' => $status === '' ? null : !in_array($status, ['MISSING', 'DELETED'], true),
            ];
        }

        return $out;
    }

    public function remove(int $productId): array
    {
        $links = VentaCartProductLink::query()->where('product_id', $productId)->get();
        $stores = $this->stores($links->pluck('ventacart_setting_id'));
        $stays = [];
        foreach ($links as $link) {
            if (trim((string) $link->sku) !== '' || $link->ventacart_product_id) {
                $stays['VentaCart (' . ($stores[(int) $link->ventacart_setting_id] ?? 'store #' . (int) $link->ventacart_setting_id) . ')'] = true;
            }
        }
        $storeIds = $links->pluck('ventacart_setting_id')
            ->merge(VentaCartListing::query()->where('product_id', $productId)->pluck('ventacart_setting_id'))
            ->map(fn ($v) => (int) $v)->filter()->unique();
        foreach ($storeIds as $storeId) {
            VentaCartListingStates::for($storeId)->clearErrors([$productId]);
        }
        VentaCartProductLink::query()->where('product_id', $productId)->delete();
        VentaCartListing::query()->where('product_id', $productId)->delete();
        VentaCartProductGroupProduct::query()->where('product_id', $productId)->delete();

        return array_keys($stays);
    }

    public function stranded(): array
    {
        $pfx = (string) config('catalog.prefix');
        $ids = [];
        foreach (['ventacart_product_links', 'ventacart_listings', 'ventacart_product_group_products'] as $table) {
            $ids = array_merge($ids, DB::table($table)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($pfx . 'product as op')->whereColumn('op.product_id', $table . '.product_id'))
                ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all());
        }

        return array_values(array_unique($ids));
    }

    private function stores($ids): array
    {
        return VentaCartSetting::query()->whereIn('id', collect($ids)->filter()->unique()->all())
            ->get()->mapWithKeys(fn ($s) => [(int) $s->id => (string) ($s->store_name ?: 'VentaCart store #' . $s->id)])->all();
    }
}
