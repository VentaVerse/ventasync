<?php

namespace App\Integrations\Contracts;

use App\Models\Catalog\Order;

interface OrderFeesContributor
{
    public function feesForOrder(Order $order): ?array;
}
