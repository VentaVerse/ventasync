<?php

namespace App\Services;

use App\Integrations\IntegrationRegistry;
use App\Integrations\Push\PushLedger;

class VariationForgetService
{
    public static function snapshot(int $productId): array
    {
        return PushLedger::erpVariationSkus([$productId], (string) config('catalog.prefix'))[$productId] ?? [];
    }

    public function reconcile(int $productId, array $before): array
    {
        $after = array_map(fn ($s) => strtolower(trim((string) $s)), self::snapshot($productId));
        $removed = [];
        foreach ($before as $sku) {
            $sku = trim((string) $sku);
            if ($sku !== '' && ! in_array(strtolower($sku), $after, true)) {
                $removed[] = $sku;
            }
        }
        if ($removed === []) {
            return [];
        }
        \App\Integrations\Listings\ListingVariations::forget($productId, $removed);
        foreach (app(IntegrationRegistry::class)->variationForgetters() as $forgetter) {
            $forgetter->forgetVariations($productId, $removed);
        }

        return $removed;
    }
}
