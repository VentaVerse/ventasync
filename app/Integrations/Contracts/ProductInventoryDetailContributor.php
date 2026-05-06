<?php

namespace App\Integrations\Contracts;

/**
 * Optional sub-contract for integrations that contribute supplemental
 * inventory detail to the product edit form — currently used by the
 * Warehousing extension to render a per-warehouse quantity breakdown
 * below the qty field ("Warehouse A: 5 | Warehouse B: 3").
 *
 * Returns null when the contributor has nothing to show for the given
 * product (e.g. the product isn't tracked in any warehouse).
 */
interface ProductInventoryDetailContributor
{
    public function inventoryBreakdownFor(int $productId): ?string;
}
