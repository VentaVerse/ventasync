<?php

namespace Extensions\venta\Services\Venta;

use App\Services\ActivityLogger;
use Extensions\venta\Models\VentaListing;
use Extensions\venta\Models\VentaProductGroupProduct;
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Illuminate\Support\Facades\DB;

/**
 * Venta's half of the catalog delete (ProductRemover, 2026-09-05): the
 * product is deleted on every storefront that holds it, then every
 * local row goes. A store that refuses keeps its rows so the retry sees
 * them.
 */
final class VentaProductRemover
{
    /** @see \App\Integrations\Contracts\ProductRemover::productPresence */
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
                'removable' => true,
            ];
        }

        return $out;
    }

    /** @see \App\Integrations\Contracts\ProductRemover::removeProduct */
    public function remove(int $productId): array
    {
        $removed = [];
        $errors = [];

        $links = VentaProductLink::query()->where('product_id', $productId)->get();
        $stores = $this->stores($links->pluck('venta_setting_id'));

        foreach ($links as $link) {
            $storeId = (int) $link->venta_setting_id;
            $label = 'Venta (' . ($stores[$storeId] ?? 'store #' . $storeId) . ')';
            $sku = trim((string) $link->sku);
            $setting = VentaSetting::query()->find($storeId);
            if ($sku !== '') {
                if (!$setting || trim((string) $setting->api_token) === '') {
                    $errors[] = $label . ' is not connected, so ' . $sku . ' could not be deleted there.';
                    continue;
                }
                try {
                    $result = (new VentaClient($setting))->delete('products/' . rawurlencode($sku));
                } catch (\Throwable $e) {
                    $result = ['ok' => false, 'status' => 0, 'body' => ['error' => \App\Support\TransportError::plain($e, 'Venta')]];
                }
                $gone = ($result['ok'] ?? false) || (int) ($result['status'] ?? 0) === 404;
                if (!$gone) {
                    $errors[] = $label . ': ' . (string) ($result['body']['error'] ?? $result['body']['message'] ?? 'no answer.');
                    continue;
                }
                ActivityLogger::log('deleted', 'Venta Product', $productId, 'Deleted ' . $sku . ' from ' . $label);
                $removed[] = $label . ' product ' . $sku;
            }
            VentaListing::query()->where('product_id', $productId)->where('venta_setting_id', $storeId)->delete();
            VentaProductGroupProduct::query()->where('product_id', $productId)
                ->whereIn('venta_product_group_id', DB::table('venta_product_groups')->where('venta_setting_id', $storeId)->select('id'))
                ->delete();
            $link->delete();
        }

        if ($errors === []) {
            VentaListing::query()->where('product_id', $productId)->delete();
            VentaProductGroupProduct::query()->where('product_id', $productId)->delete();
        }

        return ['ok' => $errors === [], 'removed' => $removed, 'kept' => [], 'errors' => $errors];
    }

    /** @see \App\Integrations\Contracts\ProductRemover::strandedProductIds */
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

    /** @return array<int, string> */
    private function stores($ids): array
    {
        return VentaSetting::query()->whereIn('id', collect($ids)->filter()->unique()->all())
            ->get()->mapWithKeys(fn ($s) => [(int) $s->id => (string) ($s->store_name ?: 'Venta store #' . $s->id)])->all();
    }
}
