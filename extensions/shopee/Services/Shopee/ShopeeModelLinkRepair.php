<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeItemCache;
use Extensions\shopee\Models\ShopeeProductLink;

/**
 * Make the link rows match what Shopee actually holds (Phase E, #184,
 * #190): one get_model_list read, then the rows follow the answer -
 * matched models linked by SKU, models Shopee no longer returns pruned
 * (the deleted-1pc-variation cleanup), a stale parent-only link dropped
 * when the item turns out to be model-level, and stale model links
 * dropped when it turns out to be item-level.
 *
 * This is the repair "Sync ID" always performed, extracted so a PUSH can
 * run it mid-flight: a stock or price call refused with a model-level
 * error re-reads, rewires and retries once instead of telling the
 * operator to unlink and start over. It returns data, never a redirect,
 * and it branches on SHOPEE's answer - the old inline version branched
 * on whether the ERP had option SKUs, which is why an ERP-flat item that
 * grew variations in Seller Centre could never be repaired.
 *
 * Honesty rule: an unanswered call changes nothing. Rows are only
 * touched once Shopee has actually answered.
 */
class ShopeeModelLinkRepair
{
    public function __construct(private readonly ShopeeClient $client)
    {
    }

    /**
     * @param array $auth mode / partner_id / partner_key / access_token / shop_id
     * @param list<string> $skus the ERP's SKUs for this product, lowercased
     * @return array{mode: 'models'|'item', linked: int, removed: int, unmatched: list<string>}
     *         or array{error: string} when Shopee did not answer
     */
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
            /* Item-level: one parent link is the whole truth. Model links
             * for this item are leftovers of a shape it no longer has. */
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

            return ['mode' => 'item', 'linked' => 1, 'removed' => $removed, 'unmatched' => []];
        }

        /* Model-level: the parent-only link is stale by definition. */
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

        // Models Shopee no longer returns take their link and cache rows with them.
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

        return ['mode' => 'models', 'linked' => $linked, 'removed' => $removed, 'unmatched' => $unmatched];
    }
}
