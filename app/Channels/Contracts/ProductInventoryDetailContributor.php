<?php

namespace App\Channels\Contracts;

interface ProductInventoryDetailContributor
{
    public function inventoryBreakdownFor(int $productId): ?string;
}
