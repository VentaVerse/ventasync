<?php

namespace App\Channels\Contracts;

interface SaleLinesSource
{
    public function saleLinesForOrder(int $coreOrderId, float $itemsSubtotal, float $shipping): ?array;
}
