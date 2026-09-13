<?php

namespace App\Services;

use App\Integrations\IntegrationRegistry;
use App\Integrations\Push\PushLedger;

/**
 * Notices which variation SKUs a catalog save removed and tells every
 * channel to forget them (VariationForgetter). Variations are deleted in
 * several places inside a product save and an options save, so the
 * service does not watch the deletes: it snapshots the catalog's
 * variation SKUs before the save and compares after.
 */
class VariationForgetService
{
    /** @return list<string> the product's variation SKUs right now */
    public static function snapshot(int $productId): array
    {
        return PushLedger::erpVariationSkus([$productId], (string) config('catalog.prefix'))[$productId] ?? [];
    }

    /**
     * Compare the snapshot with the catalog now and fan the removed SKUs out.
     *
     * @param list<string> $before
     * @return list<string> the SKUs forgotten
     */
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
