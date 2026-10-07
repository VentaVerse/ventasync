<?php

namespace Extensions\ventacart\Services\VentaCart;

use App\Integrations\Listings\CatalogGaps;

final class VentaCartListingReadiness
{
    public static function forProducts(array $productIds): array
    {
        $out = [];
        foreach (CatalogGaps::forProducts($productIds, [CatalogGaps::ENABLED, CatalogGaps::SKU]) as $pid => $gaps) {
            $out[$pid] = ['ready' => $gaps === [], 'missing' => array_map(fn ($g) => $g['label'], $gaps), 'gaps' => $gaps];
        }

        return $out;
    }
}
