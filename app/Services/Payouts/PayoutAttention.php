<?php

namespace App\Services\Payouts;

use App\Support\PayoutExpectation;
use App\Support\PayoutStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The orders a marketplace has not truly paid a store for (the owner,
 * 2026-10-01): "If an order isn't paid even if the status says it's paid,
 * it should be tagged." One classifier for Shopee, Lazada and TikTok; each
 * channel's Payouts controller hands it a descriptor of its own table.
 *
 *   overdue    done more than OVERDUE_DAYS ago, still not credited
 *   nopayout   Sync Payouts looked back as far as it reads and found nothing
 *   failed     the marketplace says the payment failed
 *   short      credited, but less than the payout promised (Shopee only:
 *              the one channel whose credit is a separate figure)
 *   refunded   refunded to the buyer after a return and the marketplace
 *              kept the shipping: money already taken, NOT a debt, so it is
 *              counted apart from "not paid"
 *
 * "Done" is the order's last update: for a completed or delivered order,
 * that is the day it reached that state.
 */
class PayoutAttention
{
    /** Shopee's slowest release seen is 26 days; past this, an order is overdue. */
    public const OVERDUE_DAYS = 30;

    /** Paid within this of the promise is paid; rounding is not a shortfall. */
    private const SHORT_TOLERANCE = 1.00;

    private const BADGE_TTL = 600;

    /** Done this long after it was placed means touched later, not done then. */
    private const STALE_DONE_DAYS = 60;

    /**
     * @param  array{channel: string, table: string, store_column: string, id_column: string, payable: list<string>, received_column?: ?string, short_expected_sql?: ?string}  $d
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>, not_paid: float, not_paid_count: int, charged: float, charged_count: int}
     */
    public function forStore(array $d, int $storeId): array
    {
        $overdueBefore = now()->subDays(self::OVERDUE_DAYS);

        $candidates = DB::table($d['table'])
            ->where($d['store_column'], $storeId)
            ->whereIn('status', $d['payable'])
            ->where(fn ($q) => $q->whereNull('payout_status')->orWhere('payout_status', '!=', PayoutStatus::PAID))
            ->orderBy('order_updated_at')
            ->get(array_merge(
                ['id', $d['id_column'] . ' as ref', 'status', 'payout_status', 'order_created_at', 'order_updated_at', 'fees', 'raw', 'catalog_order_id'],
                ! empty($d['received_column']) ? [$d['received_column'] . ' as received'] : [],
            ));

        $rows = [];
        foreach ($candidates as $o) {
            $expected = PayoutExpectation::for($d['channel'], $o->fees, $o->raw);
            $amount = $expected['amount'];
            $done = $this->date($o->order_updated_at) ?? $this->date($o->order_created_at);

            /* A return Sync Payouts settled (2026-10-02): refunded to the
             * buyer, closed with what the marketplace kept or paid. It is
             * listed only when money was kept, by that net amount; a return
             * that cost nothing, or that compensation outweighed, is done. */
            if ($o->payout_status === PayoutStatus::RETURNED) {
                $net = isset($o->received) && $o->received !== null ? (float) $o->received : $amount;
                if ($net !== null && $net < 0) {
                    $rows[] = $this->row('refunded', $o, $done, $net, false, null);
                }

                continue;
            }

            $flag = match (true) {
                $amount !== null && $amount < 0 && ! $expected['estimated'] => 'refunded',
                $o->payout_status === PayoutStatus::FAILED => 'failed',
                $o->payout_status === PayoutStatus::NO_PAYOUT => 'nopayout',
                $done !== null && $done->lt($overdueBefore) => 'overdue',
                default => null,
            };
            if ($flag === null) {
                continue;
            }

            $rows[] = $this->row($flag, $o, $done, $amount, $expected['estimated'], $flag === 'refunded' ? null : 0.0);
        }

        foreach ($this->paidShort($d, $storeId) as $o) {
            $done = $this->date($o->order_updated_at) ?? $this->date($o->order_created_at);
            $rows[] = $this->row('short', $o, $done, (float) $o->expected, false, (float) $o->received);
        }

        $counts = array_fill_keys(['overdue', 'nopayout', 'failed', 'short', 'refunded'], 0);
        $notPaid = 0.0;
        $charged = 0.0;
        foreach ($rows as $r) {
            $counts[$r['flag']]++;
            if ($r['flag'] === 'refunded') {
                $charged += abs((float) $r['expected']);
            } else {
                $notPaid += max(0.0, (float) $r['expected'] - (float) ($r['received'] ?? 0));
            }
        }

        usort($rows, [self::class, 'compare']);

        $summary = [
            'not_paid' => round($notPaid, 2),
            'not_paid_count' => count($rows) - $counts['refunded'],
            'charged' => round($charged, 2),
            'charged_count' => $counts['refunded'],
        ];
        Cache::put(self::summaryKey($d['channel'], $storeId), $summary, self::BADGE_TTL);

        return ['rows' => $rows, 'counts' => $counts] + $summary;
    }

    /**
     * A store's totals, at most ten minutes old, for the dashboard and its
     * menu. Never throws: a menu or a dashboard must not fail because a
     * count could not be had.
     *
     * @param  array<string, mixed>  $d
     * @return array{not_paid: float, not_paid_count: int, charged: float, charged_count: int}|null
     */
    public function summary(array $d, int $storeId): ?array
    {
        try {
            return Cache::remember(self::summaryKey($d['channel'], $storeId), self::BADGE_TTL, function () use ($d, $storeId) {
                $all = $this->forStore($d, $storeId);

                return array_intersect_key($all, array_flip(['not_paid', 'not_paid_count', 'charged', 'charged_count']));
            });
        } catch (\Throwable) {
            return null;
        }
    }

    /** The store's unpaid count for its menu. */
    public function badge(array $d, int $storeId): ?int
    {
        return $this->summary($d, $storeId)['not_paid_count'] ?? null;
    }

    /**
     * What each flag is called, its tone, and the sentence under the filter.
     * One wording for a store's page and the report.
     *
     * @return array<string, array{label: string, tone: string, blurb: string}>
     */
    public static function flags(string $who = 'the marketplace'): array
    {
        $days = self::OVERDUE_DAYS;

        return [
            'overdue' => ['label' => 'Overdue', 'tone' => 'warning',
                'blurb' => "Done more than {$days} days ago and still not credited. A normal release takes days, not a month."],
            'short' => ['label' => 'Paid short', 'tone' => 'warning',
                'blurb' => "Credited, but less than the payout {$who} showed when the order completed."],
            'failed' => ['label' => 'Payment failed', 'tone' => 'danger',
                'blurb' => "{$who} says it tried to pay these and the payment failed."],
            'nopayout' => ['label' => 'No payout found', 'tone' => 'danger',
                'blurb' => "Sync Payouts looked back as far as it reads and found no payment. Worth raising with {$who}."],
            'refunded' => ['label' => 'Refunded · shipping charged', 'tone' => 'info',
                'blurb' => "Returned and refunded to the buyer in full; {$who} kept the shipping from your balance. Nothing is owed: it has already been taken."],
        ];
    }

    /** Missing money first, the longest unpaid first within it; refunds last. */
    public static function compare(array $a, array $b): int
    {
        $order = ['overdue' => 0, 'short' => 1, 'failed' => 2, 'nopayout' => 3, 'refunded' => 4];

        return [$order[$a['flag']], -$a['waiting_days']] <=> [$order[$b['flag']], -$b['waiting_days']];
    }

    private static function summaryKey(string $channel, int $storeId): string
    {
        return "payouts:summary:{$channel}:{$storeId}";
    }

    /**
     * Paid orders credited less than promised, compared in SQL so the fee
     * JSON of every paid order is never loaded.
     *
     * @param  array<string, mixed>  $d
     * @return iterable<object>
     */
    private function paidShort(array $d, int $storeId): iterable
    {
        $received = $d['received_column'] ?? null;
        $expectedSql = $d['short_expected_sql'] ?? null;
        if ($received === null || $expectedSql === null) {
            return [];
        }

        return DB::table($d['table'])
            ->where($d['store_column'], $storeId)
            ->where('payout_status', PayoutStatus::PAID)
            ->whereNotNull($received)
            ->whereRaw("CAST({$expectedSql} AS DECIMAL(12,2)) - {$received} > ?", [self::SHORT_TOLERANCE])
            ->orderByDesc('paid_at')
            ->limit(200)
            ->get([
                'id', $d['id_column'] . ' as ref', 'status', 'payout_status', 'order_created_at', 'order_updated_at', 'catalog_order_id',
                DB::raw("CAST({$expectedSql} AS DECIMAL(12,2)) as expected"),
                DB::raw("{$received} as received"),
            ]);
    }

    /**
     * Days unpaid counts from the day the order was done, and only where
     * money is still missing: a refund is finished business, so it has none
     * (the owner, 2026-10-01: "how is 268 days relevant?"). The order date is
     * what a row shows, because "done" is the order's last update, which a
     * marketplace can touch years later.
     */
    private function row(string $flag, object $o, ?CarbonInterface $done, ?float $expected, bool $estimated, ?float $received): array
    {
        // A last update long after the order was placed is the marketplace
        // touching an old order, not the order being done: count from the
        // order date then (Lazada's 2021-2023 orders were touched in 2026).
        $placed = $this->date($o->order_created_at);
        $from = $done;
        if ($placed && (! $done || $placed->diffInDays($done) > self::STALE_DONE_DAYS)) {
            $from = $placed;
        }
        $days = $from ? (int) $from->diffInDays(now()) : 0;

        return [
            'flag' => $flag,
            'id' => (int) $o->id,
            'ref' => (string) $o->ref,
            'catalog_order_id' => $o->catalog_order_id ? (int) $o->catalog_order_id : null,
            'placed' => $placed,
            'done' => $done,
            'waiting_days' => $days,
            'days_unpaid' => $flag === 'refunded' ? null : $days,
            'expected' => $expected,
            'estimated' => $estimated,
            'received' => $received,
        ];
    }

    private function date(mixed $value): ?CarbonInterface
    {
        if (! $value) {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
