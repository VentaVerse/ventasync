<?php

namespace App\Integrations\Contracts;

/**
 * Optional sub-contract for integrations that need to react to a catalog SKU
 * change — e.g. Lazada updates its variant table and pushes to the Lazada
 * API; Shopee updates its product links and pushes via the Shopee API;
 * OpenCart pushes the product to each connected store.
 *
 * Core's SkuSyncService dispatches to every contributor and collects their
 * messages. Integrations that don't ship product → marketplace mirroring
 * (TikTok, Pedallion, Venta) simply don't implement this and are skipped.
 */
interface SkuSyncContributor
{
    /**
     * React to a SKU change on a catalog product. Return a short
     * human-readable status string ("OK", "Failed", "API error", etc.) or
     * null if this contributor has nothing to do for the given product.
     *
     * @param int   $productId The catalog product_id
     * @param array $skuChanges {
     *     product_sku?: array{old: string, new: string},
     *     option_skus?: array<int, array{old: string, new: string}>,
     * }
     */
    public function pushSkuChanges(int $productId, array $skuChanges): ?string;
}
