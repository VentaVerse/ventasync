<?php

namespace App\Observers;

use App\Events\OrderStatusChanged;
use App\Models\Catalog\Order;

class OrderObserver
{
    public function created(Order $order): void
    {
        OrderStatusChanged::fire((int) $order->order_id, 0, (int) $order->order_status_id);
    }

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('order_status_id')) {
            return;
        }

        OrderStatusChanged::fire(
            (int) $order->order_id,
            (int) ($order->getOriginal('order_status_id') ?? 0),
            (int) $order->order_status_id,
        );
    }
}
