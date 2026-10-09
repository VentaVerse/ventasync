<?php

namespace App\Channels\Contracts;

interface MarketplaceFeeSource
{
    public function feeBucketsForOrder(int $coreOrderId): ?array;
}
