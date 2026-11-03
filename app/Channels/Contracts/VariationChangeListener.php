<?php

namespace App\Channels\Contracts;

interface VariationChangeListener
{
    public function variationSkuRenamed(int $productId, string $oldSku, string $newSku): void;

    public function variationValuesDeleted(int $productId, array $productVariationValueIds): void;
}
