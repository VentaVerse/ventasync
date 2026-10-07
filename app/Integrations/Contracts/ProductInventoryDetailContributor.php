<?php

namespace App\Integrations\Contracts;

interface ProductInventoryDetailContributor
{
    public function inventoryBreakdownFor(int $productId): ?string;
}
