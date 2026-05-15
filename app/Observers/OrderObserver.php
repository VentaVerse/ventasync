<?php

namespace App\Observers;

use App\Models\Catalog\Order;
use App\Services\FcmService;

/**
 * Centralized FCM notification trigger for order lifecycle events.
 *
 * Any code path that creates or updates a core Order via Eloquent
 * (Order::create, $order->save(), $order->update($attrs)) automatically
 * fires this observer. Marketplace sync services and controllers no longer
 * need to call FcmService directly.
 *
 * Caveat: query-builder calls like Order::where(...)->update(...) and
 * DB::table('order')->update(...) do NOT trigger Eloquent observers.
 * Code paths that bypass Eloquent (OpenCart/Venta sync) keep their direct
 * FcmService calls — see comments at those call sites.
 */
class OrderObserver
{
    public function created(Order $order): void
    {
        // Treat a new order as a transition from "no status" (0) to its initial status.
        // FcmService::notifyIfNeeded() will silently skip unless the status name is
        // one of the configured CONFIRMED/CANCELLED list.
        $this->dispatch($order, 0, (int) $order->order_status_id);
    }

    public function updated(Order $order): void
    {
        if (!$order->wasChanged('order_status_id')) {
            return;
        }

        $oldStatusId = (int) ($order->getOriginal('order_status_id') ?? 0);
        $newStatusId = (int) $order->order_status_id;

        $this->dispatch($order, $oldStatusId, $newStatusId);
    }

    private function dispatch(Order $order, int $oldStatusId, int $newStatusId): void
    {
        if ($oldStatusId === $newStatusId) {
            return;
        }

        try {
            $firstProduct = $order->products()->first();
            $itemName = $firstProduct
                ? trim($firstProduct->name ?? '')
                : trim(($order->firstname ?? '') . ' ' . ($order->lastname ?? ''));

            $total = number_format((float) $order->total, 2) . ' ' . ($order->currency_code ?: 'PHP');

            (new FcmService())->notifyIfNeeded(
                (int) $order->order_id,
                $oldStatusId,
                $newStatusId,
                $itemName,
                $total,
                $order->marketplace_source ?? '',
            );
        } catch (\Throwable $e) {
            // Never let notification failure break the order save flow.
            \Illuminate\Support\Facades\Log::warning(
                "OrderObserver FCM dispatch failed for order #{$order->order_id}: " . $e->getMessage()
            );
        }
    }
}
