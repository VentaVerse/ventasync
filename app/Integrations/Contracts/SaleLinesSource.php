<?php

namespace App\Integrations\Contracts;

interface SaleLinesSource
{
    public function saleLinesForOrder(int $coreOrderId, float $itemsSubtotal, float $shipping): ?array;
}
