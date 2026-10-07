<?php

namespace App\Integrations\Contracts;

interface SkuSyncContributor
{
    public function pushSkuChanges(int $productId, array $skuChanges): ?string;
}
