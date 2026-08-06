<?php

namespace App\Support;

/**
 * Maps an order status name to a <x-ui.badge> tone.
 *
 * Status is the single field a person scans a long order list for, so it
 * has to carry a colour signal, not just a word. Grouped by real-world
 * meaning rather than by exact string, since `order_status` is a live table
 * an admin can rename or add to from Settings, and this mapping should keep
 * working under close variants rather than break on a spelling difference.
 *
 * Single funnel for this decision - the order detail page needs the same
 * tone for the same status, so it should call here too rather than repeat
 * the list inline.
 */
class OrderStatusTone
{
    /** Terminal, successful outcomes. */
    private const SUCCESS = ['complete', 'completed', 'delivered', 'shipped'];

    /** Still in flight or waiting on someone to act. */
    private const WARNING = [
        'pending', 'processing', 'unpaid', 'for verification',
        'to confirm receive', 'return in progress',
    ];

    /** Failed, reversed, or otherwise did not go through. */
    private const DANGER = [
        'canceled', 'cancelled', 'denied', 'failed', 'refunded',
        'returned', 'reversed',
    ];

    /**
     * Tone for a status name, or 'neutral' for anything not recognised
     * above rather than guessing - an unmapped status should read as
     * unremarkable, not accidentally alarming or reassuring.
     */
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

        if (in_array($key, self::DANGER, true)) {
            return 'danger';
        }

        return 'neutral';
    }
}
