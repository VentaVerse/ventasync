<?php

namespace App\Integrations\Contracts;

/**
 * A catalog variation delete forgets the variation on every channel
 * (the owner, 2026-09-07), the delete rule one level down from ProductRemover.
 *
 * When a variation leaves the catalog, every row a channel keeps about it
 * inside VentaSync goes with it: Shopee's model link, Lazada's variant
 * cache row, TikTok's SKU map key, WooCommerce's variation link. It never
 * calls a marketplace: the variation the seller still has on the store
 * stays there until they remove it on the store's own pages, and the
 * next push says so by name. Without this the stale row read as "on the
 * store but not in the catalogue" and the product failed until someone
 * pressed Refresh.
 */
interface VariationForgetter
{
    /**
     * @param list<string> $skus the variation SKUs the catalog no longer holds for this product
     */
    public function forgetVariations(int $productId, array $skus): void;
}
