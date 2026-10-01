<?php

namespace App\Observers;

use App\Events\OrderStatusChanged;
use App\Models\Catalog\Order;

/**
 * Announces every status move made through Eloquent (Order::create,
 * $order->save(), $order->update()). Query-builder writes do not reach an
 * observer; the syncs that use them announce with OrderStatusChanged::fire().
 */
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
