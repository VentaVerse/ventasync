<?php

namespace Extensions\lazada\Services\Lazada;

use App\Services\ActivityLogger;
use App\Support\MarketplaceLink;
use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductAttribute;
use Extensions\lazada\Models\LazadaProductGroupProduct;
use Extensions\lazada\Models\LazadaProductVariant;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Facades\DB;

/**
 * Lazada's half of the catalog delete (ProductRemover, 2026-09-05): the
 * item is removed on every store that holds it, then every local row
 * goes. A store that refuses keeps its rows so the retry sees them.
 */
final class LazadaProductRemover
{
    private const GONE = ['DELETED', 'MISSING', 'NOT_FOUND'];

    public function __construct(
        private readonly LazadaClient $client,
        private readonly LazadaItemCache $itemCache,
        private readonly LazadaPushPayload $payload,
    ) {}

    /** @see \App\Integrations\Contracts\ProductRemover::productPresence */
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

        $rows = LazadaProduct::query()->where('product_id', $productId)->get();
        $stores = $this->stores($rows->pluck('lazada_setting_id'));

        foreach ($rows as $row) {
            $storeId = (int) $row->lazada_setting_id;
            $label = 'Lazada (' . ($stores[$storeId] ?? 'store #' . $storeId) . ')';
            $itemId = trim((string) ($row->lazada_item_id ?? ''));
            $onLazada = $itemId !== '' && is_null($row->lazada_deleted_at)
                && !in_array(strtoupper((string) ($row->live_status ?? '')), self::GONE, true);

            if ($onLazada) {
                $setting = LazadaSetting::query()->find($storeId)?->decrypted();
                $creds = LazadaSetting::activeCredentials($setting);
                if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
                    $errors[] = $label . ' is not connected, so item ' . $itemId . ' could not be removed there.';
                    continue;
                }
                $error = $this->removeAtLazada($row, $productId, $setting, $creds, $itemId, $label);
                if ($error !== null) {
                    $errors[] = $error;
                    continue;
                }
                $removed[] = $label . ' item ' . $itemId;
                ActivityLogger::log('deleted', 'Lazada Product', $productId, 'Removed item ' . $itemId . ' from ' . $label);
            }

            LazadaProductAttribute::query()->where('lazada_product_id', $row->id)->delete();
            LazadaProductVariant::query()->where('lazada_product_id', $row->id)->delete();
            LazadaProductGroupProduct::query()->where('lazada_product_id', $row->id)->delete();
            $row->delete();
        }

        if ($errors === []) {
            LazadaProductGroupProduct::query()->where('product_id', $productId)->delete();
        }

        return ['ok' => $errors === [], 'removed' => $removed, 'kept' => [], 'errors' => $errors];
    }

    /** @see \App\Integrations\Contracts\ProductRemover::strandedProductIds */
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

    /**
     * POST /product/remove, by sku ids when Lazada can name them, else by
     * seller SKUs. Returns the refusal in words, or null when the item is
     * gone (removed now, or already missing there).
     */
    private function removeAtLazada(LazadaProduct $row, int $productId, object $setting, array $creds, string $itemId, string $label): ?string
    {
        $skuIdList = [];
        try {
            $skuIdList = $this->itemCache->fetchSkuIdListByItemId(
                $this->client, (string) $setting->region, (string) $creds['app_key'],
                (string) $creds['app_secret'], (string) $creds['access_token'], $itemId
            );
        } catch (\Throwable $e) {
            // Fall through to seller SKUs.
        }

        $sellerSkus = [];
        if ($skuIdList === []) {
            $pfx = (string) config('catalog.prefix');
            $main = trim((string) (DB::table($pfx . 'product')->where('product_id', $productId)->value('sku') ?? ''));
            foreach ($this->payload->getErpVariantStockByProductId($productId) as $v) {
                $s = trim((string) ($v['seller_sku'] ?? ''));
                if ($s !== '') {
                    $sellerSkus[] = $s;
                }
            }
            if ($main !== '') {
                $sellerSkus[] = $main;
            }
            $sellerSkus = array_values(array_unique($sellerSkus));
            if ($sellerSkus === []) {
                return $label . ': item ' . $itemId . ' has no SKU to remove it by.';
            }
        }

        $apiPath = '/product/remove';
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => (string) round(microtime(true) * 1000),
            'access_token' => (string) $creds['access_token'],
        ];
        if ($skuIdList !== []) {
            $params['sku_id_list'] = json_encode($skuIdList, JSON_UNESCAPED_SLASHES);
        } else {
            $params['seller_sku_list'] = json_encode($sellerSkus, JSON_UNESCAPED_SLASHES);
        }
        $params['sign'] = $this->client->sign($apiPath, $params, (string) $creds['app_secret']);

        try {
            $result = $this->client->post((string) $setting->region, $apiPath, $params);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'status' => 0, 'body' => ['code' => 'transport', 'message' => \App\Support\TransportError::plain($e, 'Lazada')]];
        }
        LazadaApiLog::safeCreate([
            'lazada_setting_id' => (int) $setting->id,
            'pack' => 'lazada.product.remove', 'method' => 'POST', 'api_path' => $apiPath, 'auth_required' => true,
            'request_params' => $params, 'response_status' => (int) ($result['status'] ?? 0),
            'ok' => (bool) ($result['ok'] ?? false), 'response_body' => $result['body'] ?? $result, 'user_id' => auth()->id(),
        ]);

        $e = $this->payload->extractLazadaError($result);
        if (!empty($e['ok'])) {
            return null;
        }
        $text = \App\Support\MarketplaceAnswer::errorText('Lazada', $result);

        return MarketplaceLink::looksMissingRemotely($text) ? null : $label . ': ' . $text;
    }

    /** @return array<int, string> */
    private function stores($ids): array
    {
        return LazadaSetting::query()->whereIn('id', collect($ids)->filter()->unique()->all())
            ->get()->mapWithKeys(fn ($s) => [(int) $s->id => (string) ($s->store_name ?: 'Lazada store #' . $s->id)])->all();
    }
}
