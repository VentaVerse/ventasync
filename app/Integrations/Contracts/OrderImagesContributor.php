<?php

namespace App\Integrations\Contracts;

/**
 * Optional sub-contract for marketplaces whose orders carry product images
 * that core's order list/show views should display, especially when the
 * catalog `product_id` on an order_product is 0 (typical for marketplace
 * orders synced before the product was created in the catalog).
 *
 * Lazada is the canonical case: lazada_order_products has its own image
 * column that survives independently of the catalog. Each marketplace can
 * contribute images for orders sourced from itself.
 *
 * Contributors that don't ship images (or rely on the catalog's product
 * image) simply don't implement this.
 */
interface OrderImagesContributor
{
    /**
     * Given a list of catalog order ids, return order_product_id → image URL.
     * Implementations should filter to orders that actually came from this
     * marketplace (typically by joining on catalog_order_id).
     *
     * @param int[] $catalogOrderIds
     * @return array<int, string>
     */
    public function imagesForCatalogOrders(array $catalogOrderIds): array;
}
