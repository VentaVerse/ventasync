<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * An order moved into a status, from wherever it was written: a page, the
 * app, the API, or a channel sync. A new order counts as a move from no
 * status (0). Core only announces it; whatever listens decides what to do.
 *
 * Eloquent saves announce through App\Observers\OrderObserver. A sync that
 * writes the order table directly must call fire() itself after its write
 * has committed.
 */
class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly int $oldStatusId,
        public readonly int $newStatusId,
    ) {
    }

    /** Announce a move, never letting a listener break the write that caused it. */
    public static function fire(int $orderId, int $oldStatusId, int $newStatusId): void
    {
        if ($orderId <= 0 || $oldStatusId === $newStatusId) {
            return;
        }

        try {
            event(new self($orderId, $oldStatusId, $newStatusId));
        } catch (\Throwable $e) {
            Log::warning("Order #{$orderId} status announcement failed: " . $e->getMessage());
        }
    }
}
