<?php

namespace App\Channels\Contracts;

use App\Models\Catalog\Order;

interface OrderActionContributor
{
    public function orderActions(Order $order): array;

    public function orderHeadNote(Order $order): ?string;
}
