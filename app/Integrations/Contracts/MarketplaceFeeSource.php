<?php

namespace App\Integrations\Contracts;

interface MarketplaceFeeSource
{
    public function feeBucketsForOrder(int $coreOrderId): ?array;
}
