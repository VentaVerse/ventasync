<?php

namespace App\Integrations\Contracts;

/**
 * Optional sub-contract for integrations whose extension maintains its own
 * SKU↔catalog-product mapping (e.g. Lazada's seller_sku → LazadaProductVariant →
 * catalog product_id).
 *
 * Implementing this lets core's stock-adjustment, order-history, and other
 * SKU-driven services resolve a marketplace SKU back to the catalog product
 * without core knowing about the extension's models.
 *
 * Integrations that don't have a separate SKU map (Shopee, TikTok, OpenCart,
 * Venta, Pedallion — they use the catalog SKU directly) simply don't
 * implement this interface and are skipped by IntegrationRegistry::skuResolvers().
 */
interface SkuResolver
{
    /**
     * Given a marketplace SKU, return the catalog product mapping or null
     * when this extension doesn't recognise the SKU.
     *
     * @return array{product_id: int, product_option_value_id: ?int}|null
     */
    public function resolveCatalogProduct(string $sku): ?array;
}
