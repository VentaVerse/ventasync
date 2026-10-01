<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * WHERE A MARKETPLACE ORDER'S MONEY IS, ONE MEANING FOR EVERY CHANNEL
 * (the owner, 2026-10-01).
 *
 * Shopee, Lazada and TikTok Shop each have their own words and their own
 * stages, and "Paid" used to mean two different things here: money released
 * into the seller's Shopee wallet, but Lazada's bank payout. Now every
 * channel means the same:
 *
 *   Not shipped      not handed to the courier yet
 *   In Transit       with the courier
 *   Releasing        delivered or completed; the marketplace still holds the money
 *   Paid             released to the seller's balance on the marketplace, per
 *                    order, dated by the marketplace:
 *                      Shopee  the escrow credited to the wallet (ESCROW_VERIFIED_ADD)
 *                      Lazada  the sale credited (Item Price Credit)
 *                      TikTok  the statement settled to the balance
 *                    A withdrawal to the bank is a lump sum of many orders,
 *                    recorded by no order, so it is not an order status.
 *   Payment failed   the marketplace says the transfer failed
 *   No payout found  still not Paid this long after the order: a payout job
 *                    stops reading it every hour, and raising the job's
 *                    look-back reads it again
 *   Cancelled        cancelled; no payout
 *   Returned         returned, lost or never delivered; no payout
 *
 * Only Paid, Releasing, Payment failed and No payout found are ever stored,
 * by each channel's Sync Payouts job; the rest are read from the order's own
 * status by the payouts report.
 */
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

    /** How far back a Sync Payouts job reads, unless its look-back says otherwise. */
    public const DEFAULT_LOOKBACK_DAYS = 90;

    /** Every status, in the order money moves; the report's filter lists them so. */
    public static function all(): array
    {
        return [self::NOT_SHIPPED, self::IN_TRANSIT, self::RELEASING, self::PAID,
            self::FAILED, self::NO_PAYOUT, self::CANCELLED, self::RETURNED];
    }

    /** The look-back a run uses: the job's, within 1 to 365 days. */
    public static function lookbackDays(mixed $days): int
    {
        $days = (int) $days;

        return $days >= 1 ? min($days, 365) : self::DEFAULT_LOOKBACK_DAYS;
    }

    /**
     * Mark the orders still not Paid this long after they were placed as
     * No payout found, so a run stops reading them. $waiting is the channel's
     * own query for its orders that can be paid out.
     *
     * @return int how many were marked
     */
    public static function retireOlderThan(Builder $waiting, int $days, string $createdColumn = 'order_created_at'): int
    {
        return (clone $waiting)
            ->where($createdColumn, '<', now()->subDays($days))
            ->where(fn ($q) => $q->whereNull('payout_status')->orWhereNotIn('payout_status', [self::PAID, self::NO_PAYOUT]))
            ->update(['payout_status' => self::NO_PAYOUT, 'paid_at' => null]);
    }
}
