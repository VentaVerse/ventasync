<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeItemCache;
use Extensions\shopee\Models\ShopeeProductLink;

class ShopeeModelLinkRepair
{
    public function __construct(private readonly ShopeeClient $client)
    {
    }

    public function forItem(array $auth, int $productId, int $itemId, array $skus): array
    {
        $modelRes = $this->client->shopGet(
            (string) $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
            (string) $auth['access_token'], (int) $auth['shop_id'],
            '/api/v2/product/get_model_list', ['item_id' => $itemId]
        );

        $body = $modelRes['body'] ?? [];
        if (!($modelRes['ok'] ?? false) || (($body['error'] ?? '') !== '' && ($body['error'] ?? null) !== null)) {
            return ['error' => (string) ($body['message'] ?? 'no answer')];
        }

        $models = ($body['response'] ?? $body)['model'] ?? [];

        if (empty($models)) {
            $removed = (int) ShopeeProductLink::query()
                ->where('product_id', $productId)
                ->where('shopee_item_id', $itemId)
                ->whereNotNull('shopee_model_id')
                ->delete();
            ShopeeItemCache::query()
                ->where('shopee_item_id', $itemId)
                ->whereNotNull('shopee_model_id')
                ->delete();

            ShopeeProductLink::updateOrCreate(
                ['product_id' => $productId, 'shopee_item_id' => $itemId, 'shopee_model_id' => null],
                ['sku' => $skus[0] ?? '', 'last_synced_at' => now(), 'last_sync_ok' => true, 'last_sync_error_code' => null, 'last_sync_error_message' => null]
            );
            ShopeeListingStates::rememberHeld($this->storeId($auth), $productId, []);

            return ['mode' => 'item', 'linked' => 1, 'removed' => $removed, 'unmatched' => []];
        }

        $removed = (int) ShopeeProductLink::query()
            ->where('product_id', $productId)
            ->where('shopee_item_id', $itemId)
            ->whereNull('shopee_model_id')
            ->delete();

        $linked = 0;
        $unmatched = [];
        $activeModelIds = [];

        foreach ($models as $model) {
            $modelSku = strtolower(trim($model['model_sku'] ?? ''));
            $modelId = (int) ($model['model_id'] ?? 0);
            if ($modelSku === '' || $modelId === 0) {
                continue;
            }

            $activeModelIds[] = $modelId;

            ShopeeItemCache::query()->updateOrCreate(
                ['shopee_item_id' => $itemId, 'shopee_model_id' => $modelId],
                ['sku' => $model['model_sku']]
            );

            if (in_array($modelSku, $skus, true)) {
                ShopeeProductLink::updateOrCreate(
                    ['product_id' => $productId, 'shopee_item_id' => $itemId, 'shopee_model_id' => $modelId],
                    ['sku' => $model['model_sku'], 'last_synced_at' => now(), 'last_sync_ok' => true, 'last_sync_error_code' => null, 'last_sync_error_message' => null]
                );
                $linked++;
            } else {
                $unmatched[] = (string) $model['model_sku'];
            }
        }

        if (!empty($activeModelIds)) {
            $removed += (int) ShopeeProductLink::query()
                ->where('product_id', $productId)
                ->where('shopee_item_id', $itemId)
                ->whereNotNull('shopee_model_id')
                ->whereNotIn('shopee_model_id', $activeModelIds)
                ->delete();

            ShopeeItemCache::query()
                ->where('shopee_item_id', $itemId)
                ->whereNotNull('shopee_model_id')
                ->whereNotIn('shopee_model_id', $activeModelIds)
                ->delete();
        }

        ShopeeListingStates::rememberHeld($this->storeId($auth), $productId, array_map(fn ($m) => (string) ($m['model_sku'] ?? ''), $models));

        return ['mode' => 'models', 'linked' => $linked, 'removed' => $removed, 'unmatched' => $unmatched];
    }

    private function storeId(array $auth): int
    {
        return (int) ($auth['store_id'] ?? 0) ?: (int) (\Extensions\shopee\Models\ShopeeSetting::defaultStore()?->id ?? 0);
    }
}
