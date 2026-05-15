<?php

namespace App\Integrations\Contracts;

/**
 * Optional sub-contract for integrations that need to populate the
 * "Source" filter dropdown on the unified Orders list with one or more
 * selectable options.
 *
 * Single-account marketplaces (Lazada, Shopee, TikTok, Shopify) contribute
 * one option whose value is the marketplace_source string used on Order
 * rows (e.g. 'shopee'). Multi-store integrations (OpenCart, Venta)
 * contribute one option per enabled store with compound values
 * (e.g. 'opencart:1', 'venta:3').
 *
 * Each option is shaped:
 *   [
 *     'value'       => 'shopee'   // matches Order::marketplace_source verbatim
 *     'label'       => 'Shopee'   // user-facing label
 *     'badge_class' => 'badge-orange'  // CSS class for the source-column badge (optional)
 *   ]
 *
 * Extending MarketplaceSourceLabelResolver means every options provider
 * is also a label resolver — so resolution of a single source string
 * stays consistent with the dropdown enumeration.
 */
interface MarketplaceSourceOptionsProvider extends MarketplaceSourceLabelResolver
{
    /**
     * @return array<int, array{value: string, label: string, badge_class?: string}>
     */
    public function availableSourceOptions(): array;
}
