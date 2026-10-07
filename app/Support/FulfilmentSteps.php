<?php

namespace App\Support;

final class FulfilmentSteps
{
    private const STEPS = [
        'all' => 'All',
        'unpaid' => 'Unpaid',
        'to_ship' => 'To Ship',
        'to_pack' => 'To Pack',
        'to_handover' => 'To Handover',
        'ready' => 'Ready for pickup',
        'shipping' => 'Shipping',
        'in_transit' => 'In transit',
        'delivered' => 'Delivered',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'failed' => 'Failed Delivery',
        'other' => 'Other',
    ];

    private const STATUS_STEPS = [
        'ventacart.orders' => [
            'UNPAID' => 'unpaid',
            'QUOTE_READY_FOR_PAYMENT' => 'unpaid',
            'PENDING' => 'unpaid',
            'FOR_VERIFICATION' => 'unpaid',

            'PROCESSED' => 'to_pack',
            'PAYMENT_RECEIVED' => 'to_pack',
            'ITEM_RESERVED' => 'to_pack',
            'ORDER_PROCESSED' => 'to_pack',
            'ARRIVED_AT_NGD' => 'to_pack',
            'ASSIGNED' => 'to_pack',
            'ACCEPTED' => 'to_pack',
            'ASSIGNING_RIDER' => 'to_pack',
            'PROCESSING' => 'to_pack',

            'ORDER_BEING_SHIPPED' => 'to_handover',
            'START_PICKUP' => 'to_handover',
            'DONE_PICKUP' => 'to_handover',
            'PICKUP_FAILED' => 'to_handover',

            'IN_TRANSIT_TO_MANILA' => 'shipping',
            'DELIVERY_FAILED' => 'shipping',
            'SHIPPED' => 'shipping',
            'IN_TRANSIT' => 'shipping',

            'DELIVERED' => 'delivered',
            'COMPLETE' => 'delivered',
            'COMPLETED' => 'delivered',

            'DENIED' => 'cancelled',
            'CANCELED_REVERSAL' => 'cancelled',
            'FAILED' => 'cancelled',
            'REFUNDED' => 'cancelled',
            'REVERSED' => 'cancelled',
            'CHARGEBACK' => 'cancelled',
            'EXPIRED' => 'cancelled',
            'VOIDED' => 'cancelled',
            'CANCELED' => 'cancelled',
            'CANCELLED' => 'cancelled',

            'RETURNING' => 'failed',
            'RETURNED' => 'failed',
            'LOST' => 'failed',
            'DAMAGED' => 'failed',
        ],
    ];

    public static function placements(): array
    {
        return ['unpaid', 'to_pack', 'to_handover', 'shipping', 'delivered', 'cancelled', 'failed', 'other'];
    }

    public static function bucket(string $surface, iterable $statusCounts, ?callable $resolver = null): array
    {
        $out = [];
        foreach (self::placements() as $step) {
            $out[$step] = ['count' => 0, 'statuses' => []];
        }

        foreach ($statusCounts as $status => $count) {
            $status = (string) $status;
            $step = ($resolver ? $resolver($status) : null) ?? self::stepFor($surface, $status) ?? 'other';

            if (! isset($out[$step])) {
                $step = 'other';
            }

            $out[$step]['count'] += (int) $count;
            $out[$step]['statuses'][] = $status;
        }

        return $out;
    }

    public static function stepFor(string $surface, ?string $status): ?string
    {
        $key = strtoupper(str_replace(' ', '_', trim((string) $status)));

        return self::STATUS_STEPS[$surface][$key] ?? null;
    }

    public static function label(string $step): string
    {
        return self::STEPS[$step] ?? $step;
    }

    public static function knows(string $step): bool
    {
        return isset(self::STEPS[$step]);
    }

    public static function rank(string $step): int
    {
        $rank = array_search($step, array_keys(self::STEPS), true);

        return $rank === false ? count(self::STEPS) - 1 : $rank;
    }

    public static function tabs(array $keyToStep): array
    {
        uasort($keyToStep, fn ($a, $b) => self::rank($a) <=> self::rank($b));

        return array_map(fn ($step) => self::label($step), $keyToStep);
    }

    public static function order(iterable $statuses, string $surface): array
    {
        $out = [];

        foreach ($statuses as $key => $value) {
            $out[(string) $key] = $value;
        }

        $seen = array_keys($out);

        uksort($out, function ($a, $b) use ($surface, $seen) {
            $stepA = self::stepFor($surface, $a);
            $stepB = self::stepFor($surface, $b);
            $rankA = $stepA === null ? PHP_INT_MAX : self::rank($stepA);
            $rankB = $stepB === null ? PHP_INT_MAX : self::rank($stepB);

            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            return array_search($a, $seen, true) <=> array_search($b, $seen, true);
        });

        return $out;
    }
}
