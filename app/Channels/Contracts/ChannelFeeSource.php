<?php

namespace App\Channels\Contracts;

interface ChannelFeeSource
{
    public function feeBucketsForOrder(int $coreOrderId): ?array;
}
