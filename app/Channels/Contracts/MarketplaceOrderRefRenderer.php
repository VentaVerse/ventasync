<?php

namespace App\Channels\Contracts;

interface MarketplaceOrderRefRenderer
{
    public function renderOrderRef(string $orderChannel, string $marketplaceOrderId): ?array;
}
