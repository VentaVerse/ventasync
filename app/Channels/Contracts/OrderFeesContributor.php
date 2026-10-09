<?php

namespace App\Channels\Contracts;

use App\Models\Catalog\Order;

interface OrderFeesContributor
{
    public function feesForOrder(Order $order): ?array;
}
