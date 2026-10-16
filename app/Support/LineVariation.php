<?php

namespace App\Support;

final class LineVariation
{
    public static function label(?string $sku, ?string $storeText = null, ?int $salesOrderId = null, ?string $name = null): string
    {
        $sku = trim((string) $sku);
        $pairs = OrderSnapshot::savedPairs($salesOrderId, $sku, $name)
            ?? ($sku !== '' ? (CatalogVariations::forSkus([$sku])[$sku] ?? []) : []);
        if ($pairs === []) {
            return trim((string) $storeText);
        }

        return implode(', ', array_map(
            fn ($p) => trim((string) $p['name']) !== '' ? trim((string) $p['name']) . ':' . trim((string) $p['value']) : trim((string) $p['value']),
            $pairs
        ));
    }
}
