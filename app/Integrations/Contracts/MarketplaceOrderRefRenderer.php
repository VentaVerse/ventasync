<?php

namespace App\Integrations\Contracts;

interface MarketplaceOrderRefRenderer
{
    public function renderOrderRef(string $marketplaceSource, string $marketplaceOrderId): ?array;
}
