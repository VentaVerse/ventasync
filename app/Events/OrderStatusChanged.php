<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Log;

class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly int $oldStatusId,
        public readonly int $newStatusId,
    ) {
    }

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
