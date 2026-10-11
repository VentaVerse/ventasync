<?php

namespace App\Services;

use App\Channels\ChannelRegistry;
use App\Channels\Push\PushLedger;

class VariationForgetService
{
    public static function snapshot(int $productId): array
    {
        return PushLedger::erpVariationSkus([$productId])[$productId] ?? [];
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
        \App\Channels\Listings\ListingVariations::forget($productId, $removed);
        foreach (app(ChannelRegistry::class)->variationForgetters() as $forgetter) {
            $forgetter->forgetVariations($productId, $removed);
        }

        return $removed;
    }
}
