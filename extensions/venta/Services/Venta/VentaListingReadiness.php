<?php

namespace Extensions\venta\Services\Venta;

use App\Integrations\Listings\CatalogGaps;

/**
 * Can this product's push to the Venta store succeed? Answered from the ERP's own
 * records, mirroring EXACTLY what the push refuses before sending: the
 * catalog product enabled (a disabled product is never pushed anywhere)
 * and a SKU, which the store files products by. The store fills in
 * everything else, so nothing else is a gap. One computation for the
 * Ready column and every push path (the owner, 2026-09-06: Ready has to be
 * absolutely sure); an update runs the same checklist.
 */
final class VentaListingReadiness
{
    /**
     * @param list<int> $productIds
     * @return array<int, array{ready: bool, missing: array<string>, gaps: list<array{code: string, label: string}>}>
     */
    public static function forProducts(array $productIds): array
    {
        $out = [];
        foreach (CatalogGaps::forProducts($productIds, [CatalogGaps::ENABLED, CatalogGaps::SKU]) as $pid => $gaps) {
            $out[$pid] = ['ready' => $gaps === [], 'missing' => array_map(fn ($g) => $g['label'], $gaps), 'gaps' => $gaps];
        }

        return $out;
    }
}
