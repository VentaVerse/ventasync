<?php

namespace App\Services\Catalog;

use App\Channels\ChannelRegistry;

final class VariationChanges
{
    public static function skuRenamed(int $productId, string $oldSku, string $newSku): void
    {
        if ($oldSku === '' || $oldSku === $newSku) {
            return;
        }
        foreach (app(ChannelRegistry::class)->variationChangeListeners() as $listener) {
            $listener->variationSkuRenamed($productId, $oldSku, $newSku);
        }
    }

    public static function valuesDeleted(int $productId, array $productVariationValueIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productVariationValueIds))));
        if ($ids === []) {
            return;
        }
        foreach (app(ChannelRegistry::class)->variationChangeListeners() as $listener) {
            $listener->variationValuesDeleted($productId, $ids);
        }
    }
}
