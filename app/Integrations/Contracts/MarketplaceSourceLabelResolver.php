<?php

namespace App\Integrations\Contracts;

/**
 * Optional sub-contract for integrations that can render a human-readable
 * label for a catalog order's marketplace_source string.
 *
 * Example: a Venta order has marketplace_source = "venta:3" — the Venta
 * integration resolves that to its store_name. An OpenCart order has
 * marketplace_source = "opencart:1" — OpenCart resolves to its store_name.
 * Direct marketplaces (Lazada, Shopee, TikTok) just resolve to their
 * brand name when source matches.
 *
 * Each resolver returns null when it doesn't recognize the source string.
 * Callers iterate the registry and use the first non-null result.
 */
interface MarketplaceSourceLabelResolver
{
    public function resolveSourceLabel(string $source): ?string;
}
