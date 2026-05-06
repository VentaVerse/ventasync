<?php

namespace App\Services;

use App\Integrations\IntegrationRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Thin facade that fans a SKU change out to every marketplace integration
 * via the IntegrationRegistry. Per-marketplace push logic now lives inside
 * each extension (Lazada, Shopee, OpenCart) so core has zero awareness of
 * which marketplaces ship SKU mirroring.
 *
 * Each integration that wants to react to SKU changes implements
 * App\Integrations\Contracts\SkuSyncContributor and is collected here.
 */
class SkuSyncService
{
    /**
     * Sync SKU changes to every connected marketplace.
     *
     * @param int   $productId   The catalog product_id
     * @param array $skuChanges  ['product_sku' => ['old'=>..,'new'=>..] | null, 'option_skus' => [povId => ['old'=>..,'new'=>..]]]
     * @return array              Human-readable messages keyed by integration id
     */
    public function syncSkuChanges(int $productId, array $skuChanges): array
    {
        if (empty($skuChanges['product_sku']) && empty($skuChanges['option_skus'])) {
            return [];
        }

        // Skip disabled products
        $pfx = (string) config('catalog.prefix');
        $status = DB::table($pfx . 'product')->where('product_id', $productId)->value('status');
        if ((int) $status === 0) {
            return [];
        }

        $messages = [];
        foreach (app(IntegrationRegistry::class)->skuSyncContributors() as $contributor) {
            $key = $contributor->integrationId();
            $message = $contributor->pushSkuChanges($productId, $skuChanges);
            if ($message !== null) {
                $messages[$key] = $message;
            }
        }

        return $messages;
    }
}
