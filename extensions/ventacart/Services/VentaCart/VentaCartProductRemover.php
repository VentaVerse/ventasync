<?php

namespace Extensions\VentaCart\Services\VentaCart;

use Extensions\VentaCart\Models\VentaCartListing;
use Extensions\VentaCart\Models\VentaCartProductGroupProduct;
use Extensions\VentaCart\Models\VentaCartListingLink;
use Extensions\VentaCart\Models\VentaCartStore;
use Illuminate\Support\Facades\DB;

final class VentaCartProductRemover
{
    public function presence(array $productIds): array
    {
        $links = VentaCartListingLink::query()->whereIn('product_id', $productIds)->get();
        if ($links->isEmpty()) {
            return [];
        }
        $stores = $this->stores($links->pluck('store_id'));
        $live = VentaCartListing::query()->whereIn('product_id', $productIds)->get()
            ->keyBy(fn ($l) => (int) $l->store_id . ':' . (int) $l->product_id);

        $out = [];
        foreach ($links as $link) {
            $status = strtoupper((string) ($live->get((int) $link->store_id . ':' . (int) $link->product_id)?->live_status ?? ''));
            $out[(int) $link->product_id][] = [
                'channel' => 'VentaCart',
                'store' => $stores[(int) $link->store_id] ?? 'store #' . (int) $link->store_id,
                'item' => (string) ($link->ventacart_product_id ?: $link->sku),
                'live' => $status === '' ? null : !in_array($status, ['MISSING', 'DELETED'], true),
            ];
        }

        return $out;
    }

    public function remove(int $productId): array
    {
        $links = VentaCartListingLink::query()->where('product_id', $productId)->get();
        $stores = $this->stores($links->pluck('store_id'));
        $stays = [];
        foreach ($links as $link) {
            if (trim((string) $link->sku) !== '' || $link->ventacart_product_id) {
                $stays['VentaCart (' . ($stores[(int) $link->store_id] ?? 'store #' . (int) $link->store_id) . ')'] = true;
            }
        }
        $storeIds = $links->pluck('store_id')
            ->merge(VentaCartListing::query()->where('product_id', $productId)->pluck('store_id'))
            ->map(fn ($v) => (int) $v)->filter()->unique();
        foreach ($storeIds as $storeId) {
            VentaCartListingStates::for($storeId)->clearErrors([$productId]);
        }
        VentaCartListingLink::query()->where('product_id', $productId)->delete();
        VentaCartListing::query()->where('product_id', $productId)->delete();
        VentaCartProductGroupProduct::query()->where('product_id', $productId)->delete();

        return array_keys($stays);
    }

    public function stranded(): array
    {
        $ids = [];
        foreach (['ch_ventacart_listing_links', 'ch_ventacart_listings', 'ch_ventacart_product_group_products'] as $table) {
            $ids = array_merge($ids, DB::table($table)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('products as op')->whereColumn('op.product_id', $table . '.product_id'))
                ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all());
        }

        return array_values(array_unique($ids));
    }

    private function stores($ids): array
    {
        return VentaCartStore::query()->whereIn('id', collect($ids)->filter()->unique()->all())
            ->get()->mapWithKeys(fn ($s) => [(int) $s->id => (string) ($s->store_name ?: 'VentaCart store #' . $s->id)])->all();
    }
}
