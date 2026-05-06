<?php

namespace App\Integrations\Contracts;

/**
 * Optional sub-contract for marketplaces that want to control how their
 * order reference is rendered in core views (orders index, order detail,
 * reports). The catalog stores marketplace_order_id as the marketplace's
 * stable API id (often a long opaque global value), but the value an
 * operator wants to *see* is usually the merchant-facing display number
 * (Shopify "#1001", Lazada "PH-1234567") plus a clickable link back to
 * the marketplace's seller portal for that order.
 *
 * Each implementer returns either null (this isn't my source) or a
 * compact array describing how to render it.
 */
interface MarketplaceOrderRefRenderer
{
    /**
     * Given a catalog order's marketplace_source string and the stored
     * marketplace_order_id, return:
     *   ['display' => '#1001', 'url' => 'https://...']
     * or null if this integration doesn't own the source.
     *
     * @return array{display:string,url:?string}|null
     */
    public function renderOrderRef(string $marketplaceSource, string $marketplaceOrderId): ?array;
}
