<?php

namespace App\Services;

use App\Models\LazadaApiLog;
use App\Models\LazadaProduct;
use App\Models\LazadaProductVariant;
use App\Models\LazadaSetting;
use App\Models\ShopeeApiLog;
use App\Models\ShopeeProductLink;
use App\Models\ShopeeSetting;
use App\Services\Lazada\LazadaClient;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SkuSyncService
{
    /**
     * Sync SKU changes to all connected platforms.
     *
     * @param int   $productId   The ERP product_id
     * @param array $skuChanges  ['product_sku' => ['old'=>..,'new'=>..] | null, 'option_skus' => [povId => ['old'=>..,'new'=>..]]]
     * @return array              Human-readable messages per platform
     */
    public function syncSkuChanges(int $productId, array $skuChanges): array
    {
        $messages = [];

        if (empty($skuChanges['product_sku']) && empty($skuChanges['option_skus'])) {
            return $messages;
        }

        // Skip disabled products
        $pfx = (string) config('catalog.prefix');
        $status = DB::table($pfx . 'product')->where('product_id', $productId)->value('status');
        if ((int) $status === 0) {
            return $messages;
        }

        $messages['opencart'] = $this->syncOpenCart($productId);
        $messages['lazada'] = $this->syncLazada($productId, $skuChanges);
        $messages['shopee'] = $this->syncShopee($productId, $skuChanges);

        return array_filter($messages);
    }

    private function syncOpenCart(int $productId): ?string
    {
        if (!class_exists(\Extensions\opencart\Models\OpenCartSetting::class)) {
            return null;
        }

        $stores = \Extensions\opencart\Models\OpenCartSetting::where('enabled', true)->get();
        if ($stores->isEmpty()) {
            return null;
        }

        $results = [];
        foreach ($stores as $setting) {
            $link = \Extensions\opencart\Models\OpenCartProductLink::where('opencart_setting_id', $setting->id)
                ->where('product_id', $productId)
                ->first();
            if (!$link) {
                continue;
            }

            try {
                $client = new \Extensions\opencart\Services\OpenCart\OpenCartClient($setting);
                $sync = new \Extensions\opencart\Services\OpenCart\OpenCartProductSync($client, $setting);
                $result = $sync->push([$productId]);
                $ok = ($result['failed'] ?? 0) === 0;
                $results[] = $setting->store_name . ': ' . ($ok ? 'OK' : 'Failed');
            } catch (\Throwable $e) {
                Log::warning('SKU sync to OpenCart failed', [
                    'store' => $setting->store_name,
                    'product_id' => $productId,
                    'error' => $e->getMessage(),
                ]);
                $results[] = $setting->store_name . ': Error';
            }
        }

        return empty($results) ? null : 'OpenCart: ' . implode(', ', $results);
    }

    private function syncLazada(int $productId, array $skuChanges): ?string
    {
        $listing = LazadaProduct::where('product_id', $productId)->first();
        if (!$listing) {
            return null;
        }

        $results = [];

        // Update product-level SKU in lazada_product_variants
        if (!empty($skuChanges['product_sku'])) {
            $old = $skuChanges['product_sku']['old'];
            $new = $skuChanges['product_sku']['new'];

            $updated = LazadaProductVariant::where('lazada_product_id', $listing->id)
                ->where('seller_sku', $old)
                ->update(['seller_sku' => $new]);

            if ($updated > 0) {
                $results[] = "seller_sku updated ({$old} -> {$new})";
            }

            // Best-effort API push
            if (!empty($listing->lazada_item_id)) {
                $apiResult = $this->lazadaUpdateSellerSku($listing, $old, $new);
                if ($apiResult) {
                    $results[] = $apiResult;
                }
            }
        }

        // Update option-level SKUs in lazada_product_variants
        if (!empty($skuChanges['option_skus'])) {
            foreach ($skuChanges['option_skus'] as $povId => $change) {
                $old = $change['old'];
                $new = $change['new'];

                $variant = LazadaProductVariant::where('lazada_product_id', $listing->id)
                    ->where(function ($q) use ($old, $povId) {
                        $q->where('seller_sku', $old)
                          ->orWhere('product_option_value_id', $povId);
                    })
                    ->first();

                if ($variant) {
                    $variant->seller_sku = $new;
                    $variant->save();
                    $results[] = "variant SKU ({$old} -> {$new})";
                }
            }
        }

        // Update sync status on listing
        try {
            $listing->update([
                'last_synced_at' => now(),
                'last_sync_action' => 'sku_sync',
                'last_sync_ok' => true,
            ]);
        } catch (\Throwable $e) {
            // ignore
        }

        return empty($results) ? null : 'Lazada: ' . implode('; ', $results);
    }

    private function lazadaUpdateSellerSku(LazadaProduct $listing, string $oldSku, string $newSku): ?string
    {
        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->access_token || !$setting->app_key) {
            return null;
        }

        try {
            $client = new LazadaClient();
            $apiPath = '/product/update';

            $itemId = htmlspecialchars((string) $listing->lazada_item_id, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $newSkuEsc = htmlspecialchars($newSku, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $payloadXml = '<Request><Product>'
                . '<ItemId>' . $itemId . '</ItemId>'
                . '<Skus><Sku>'
                . '<SellerSku>' . $newSkuEsc . '</SellerSku>'
                . '</Sku></Skus>'
                . '</Product></Request>';

            $params = [
                'app_key'      => (string) $setting->app_key,
                'sign_method'  => 'sha256',
                'timestamp'    => (string) round(microtime(true) * 1000),
                'access_token' => (string) $setting->access_token,
                'payload'      => $payloadXml,
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);

            $result = $client->post((string) $setting->region, $apiPath, $params);

            LazadaApiLog::safeCreate([
                'pack'            => 'lazada.product.update.sku_sync',
                'method'          => 'POST',
                'api_path'        => $apiPath,
                'auth_required'   => true,
                'request_params'  => $params,
                'response_status' => (int) ($result['status'] ?? 0),
                'ok'              => (bool) ($result['ok'] ?? false),
                'response_body'   => $result['body'] ?? null,
                'user_id'         => auth()->id(),
            ]);

            $body = $result['body'] ?? [];
            $code = is_array($body) ? ($body['code'] ?? null) : null;

            if (($result['ok'] ?? false) && ($code === '0' || $code === 0 || $code === null)) {
                return 'API OK';
            }

            $msg = is_array($body) ? ($body['message'] ?? 'API error') : 'API error';
            Log::info('Lazada SellerSku update response', ['code' => $code, 'message' => $msg]);
            return 'API: ' . $msg;
        } catch (\Throwable $e) {
            Log::warning('Lazada SellerSku update failed', ['error' => $e->getMessage()]);
            return 'API error';
        }
    }

    private function syncShopee(int $productId, array $skuChanges): ?string
    {
        $links = ShopeeProductLink::where('product_id', $productId)->get();
        if ($links->isEmpty()) {
            return null;
        }

        $results = [];

        // Push item_sku change for product-level SKU
        if (!empty($skuChanges['product_sku'])) {
            $newSku = $skuChanges['product_sku']['new'];
            $itemLinks = $links->whereNull('shopee_model_id')->unique('shopee_item_id');

            foreach ($itemLinks as $link) {
                $itemId = (int) $link->shopee_item_id;
                if ($itemId <= 0) continue;

                $apiResult = $this->shopeeUpdateItemSku($link, $itemId, $newSku);
                if ($apiResult) {
                    $results[] = $apiResult;
                }
            }
        }

        // Option SKU changes: local update already done in transaction
        if (!empty($skuChanges['option_skus'])) {
            $results[] = 'option SKU(s) updated locally';
        }

        return empty($results) ? null : 'Shopee: ' . implode('; ', $results);
    }

    private function shopeeUpdateItemSku(ShopeeProductLink $link, int $itemId, string $newSku): ?string
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return null;
        }

        try {
            $client = new ShopeeClient();
            $path = '/api/v2/product/update_item';
            $body = [
                'item_id'  => $itemId,
                'item_sku' => $newSku,
            ];

            $result = $client->shopPost(
                $setting->mode ?? 'live',
                (int) $setting->partner_id,
                (string) $setting->partner_key,
                (string) $setting->access_token,
                (int) $setting->shop_id,
                $path,
                [],
                $body
            );

            ShopeeApiLog::safeCreate([
                'pack'            => 'shopee.product.update_item.sku_sync',
                'method'          => 'POST',
                'api_path'        => $path,
                'auth_required'   => true,
                'request_params'  => $body,
                'response_status' => (int) ($result['status'] ?? 0),
                'ok'              => (bool) ($result['ok'] ?? false),
                'response_body'   => $result['body'] ?? null,
                'user_id'         => auth()->id(),
            ]);

            $isOk = ($result['ok'] ?? false) && (($result['body']['error'] ?? '') === '' || ($result['body']['error'] ?? null) === null);

            $link->update([
                'last_synced_at'          => now(),
                'last_sync_action'        => 'sku_sync',
                'last_sync_ok'            => $isOk,
                'last_sync_error_code'    => $isOk ? null : (string) ($result['body']['error'] ?? ''),
                'last_sync_error_message' => $isOk ? null : (string) ($result['body']['message'] ?? ''),
            ]);

            return "Item {$itemId}: " . ($isOk ? 'OK' : 'Failed');
        } catch (\Throwable $e) {
            Log::warning('Shopee item_sku update failed', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            return "Item {$itemId}: Error";
        }
    }
}
