<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

final class PayoutStatus
{
    public const NOT_SHIPPED = 'Not shipped';

    public const IN_TRANSIT = 'In Transit';

    public const RELEASING = 'Releasing';

    public const PAID = 'Paid';

    public const FAILED = 'Payment failed';

    public const NO_PAYOUT = 'No payout found';

    public const CANCELLED = 'Cancelled';

    public const RETURNED = 'Returned';

    public const DEFAULT_LOOKBACK_DAYS = 90;

    public static function all(): array
    {
        return [self::NOT_SHIPPED, self::IN_TRANSIT, self::RELEASING, self::PAID,
            self::FAILED, self::NO_PAYOUT, self::CANCELLED, self::RETURNED];
    }

    public static function lookbackDays(mixed $days): int
    {
        $days = (int) $days;

        return $days >= 1 ? min($days, 365) : self::DEFAULT_LOOKBACK_DAYS;
    }

    public static function retireOlderThan(Builder $waiting, int $days, string $createdColumn = 'order_created_at'): int
    {
        return (clone $waiting)
            ->where($createdColumn, '<', now()->subDays($days))
            ->where(fn ($q) => $q->whereNull('payout_status')->orWhereNotIn('payout_status', [self::PAID, self::NO_PAYOUT, self::RETURNED]))
            ->update(['payout_status' => self::NO_PAYOUT, 'paid_at' => null]);
    }
}
