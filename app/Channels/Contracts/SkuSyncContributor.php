<?php

namespace App\Channels\Contracts;

interface SkuSyncContributor
{
    public function pushSkuChanges(int $productId, array $skuChanges): ?string;
}
