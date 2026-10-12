<?php

namespace Extensions\OpenCart\Services\OpenCart;

use Extensions\OpenCart\Models\OpenCartProductGroupProduct;
use Extensions\OpenCart\Models\OpenCartListingLink;
use Extensions\OpenCart\Models\OpenCartStore;
use Illuminate\Support\Facades\DB;

final class OpenCartProductRemover
{
    public function presence(array $productIds): array
    {
        $links = OpenCartListingLink::query()->whereIn('product_id', $productIds)->get();
        $stores = $this->stores($links->pluck('store_id'));
        $out = [];
        foreach ($links as $link) {
            $out[(int) $link->product_id][] = [
                'channel' => 'OpenCart',
                'store' => $stores[(int) $link->store_id] ?? 'store #' . (int) $link->store_id,
                'item' => (string) $link->opencart_product_id,
                'live' => null,
            ];
        }

        return $out;
    }

    public function remove(int $productId): array
    {
        $links = OpenCartListingLink::query()->where('product_id', $productId)->get();
        $stores = $this->stores($links->pluck('store_id'));
        $stays = [];
        foreach ($links->pluck('store_id')->unique() as $storeId) {
            $stays['OpenCart (' . ($stores[(int) $storeId] ?? 'store #' . (int) $storeId) . ')'] = true;
        }
        OpenCartListingLink::query()->where('product_id', $productId)->delete();
        OpenCartProductGroupProduct::query()->where('product_id', $productId)->delete();

        return array_keys($stays);
    }

    public function stranded(): array
    {
        $ids = [];
        foreach (['ch_opencart_listing_links', 'ch_opencart_product_group_products'] as $table) {
            $ids = array_merge($ids, DB::table($table)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('products as op')->whereColumn('op.product_id', $table . '.product_id'))
                ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all());
        }

        return array_values(array_unique($ids));
    }

    private function stores($ids): array
    {
        return OpenCartStore::query()->whereIn('id', collect($ids)->filter()->unique()->all())
            ->get()->mapWithKeys(fn ($s) => [(int) $s->id => (string) ($s->store_name ?: 'OpenCart store #' . $s->id)])->all();
    }
}
