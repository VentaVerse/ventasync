<?php

namespace App\Channels\Contracts;

interface ProductStockDetailContributor
{
    public function stockBreakdownFor(int $productId): ?string;
}
