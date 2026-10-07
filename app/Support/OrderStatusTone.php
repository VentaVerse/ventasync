<?php

namespace App\Support;

class OrderStatusTone
{
    private const SUCCESS = ['complete', 'completed', 'delivered', 'shipped'];

    private const WARNING = [
        'pending', 'unpaid', 'for verification',
        'ready to ship', 'to handover',
    ];
    private const INFO = [
        'processing', 'to confirm receive', 'return in progress',
    ];

    private const DANGER = [
        'canceled', 'cancelled', 'denied', 'failed', 'refunded',
        'returned', 'reversed',
    ];

    public static function for(?string $statusName): string
    {
        $key = strtolower(trim((string) $statusName));

        if ($key === '') {
            return 'neutral';
        }

        if (in_array($key, self::SUCCESS, true)) {
            return 'success';
        }

        if (in_array($key, self::WARNING, true)) {
            return 'warning';
        }
        if (in_array($key, self::INFO, true)) {
            return 'info';
        }

        if (in_array($key, self::DANGER, true)) {
            return 'danger';
        }

        return 'neutral';
    }
}
