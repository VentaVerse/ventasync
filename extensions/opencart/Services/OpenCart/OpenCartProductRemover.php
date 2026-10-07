<?php

namespace Extensions\opencart\Services\OpenCart;

use Extensions\opencart\Models\OpenCartProductGroupProduct;
use Extensions\opencart\Models\OpenCartProductLink;
use Extensions\opencart\Models\OpenCartSetting;
use Illuminate\Support\Facades\DB;

final class OpenCartProductRemover
{
    public function presence(array $productIds): array
    {
        $links = OpenCartProductLink::query()->whereIn('product_id', $productIds)->get();
        $stores = $this->stores($links->pluck('opencart_setting_id'));
        $out = [];
        foreach ($links as $link) {
            $out[(int) $link->product_id][] = [
                'channel' => 'OpenCart',
                'store' => $stores[(int) $link->opencart_setting_id] ?? 'store #' . (int) $link->opencart_setting_id,
                'item' => (string) $link->oc_product_id,
                'live' => null,
            ];
        }

        return $out;
    }

    public function remove(int $productId): array
    {
        $links = OpenCartProductLink::query()->where('product_id', $productId)->get();
        $stores = $this->stores($links->pluck('opencart_setting_id'));
        $stays = [];
        foreach ($links->pluck('opencart_setting_id')->unique() as $storeId) {
            $stays['OpenCart (' . ($stores[(int) $storeId] ?? 'store #' . (int) $storeId) . ')'] = true;
        }
        OpenCartProductLink::query()->where('product_id', $productId)->delete();
        OpenCartProductGroupProduct::query()->where('product_id', $productId)->delete();

        return array_keys($stays);
    }

    public function stranded(): array
    {
        $pfx = (string) config('catalog.prefix');
        $ids = [];
        foreach (['opencart_product_links', 'opencart_product_group_products'] as $table) {
            $ids = array_merge($ids, DB::table($table)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($pfx . 'product as op')->whereColumn('op.product_id', $table . '.product_id'))
                ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all());
        }

        return array_values(array_unique($ids));
    }

    private function stores($ids): array
    {
        return OpenCartSetting::query()->whereIn('id', collect($ids)->filter()->unique()->all())
            ->get()->mapWithKeys(fn ($s) => [(int) $s->id => (string) ($s->store_name ?: 'OpenCart store #' . $s->id)])->all();
    }
}
