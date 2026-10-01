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
            ->get(['id', $d['id_column'] . ' as ref', 'status', 'payout_status', 'order_created_at', 'order_updated_at', 'fees', 'raw', 'catalog_order_id']);

        $rows = [];
        foreach ($candidates as $o) {
            $expected = PayoutExpectation::for($d['channel'], $o->fees, $o->raw);
            $amount = $expected['amount'];
            $done = $this->date($o->order_updated_at) ?? $this->date($o->order_created_at);

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

        $order = ['overdue' => 0, 'short' => 1, 'failed' => 2, 'nopayout' => 3, 'refunded' => 4];
        usort($rows, fn ($a, $b) => [$order[$a['flag']], -$a['waiting_days']] <=> [$order[$b['flag']], -$b['waiting_days']]);

        $notPaidCount = count($rows) - $counts['refunded'];
        Cache::put(self::badgeKey($d['channel'], $storeId), $notPaidCount, self::BADGE_TTL);

        return [
            'rows' => $rows,
            'counts' => $counts,
            'not_paid' => round($notPaid, 2),
            'not_paid_count' => $notPaidCount,
            'charged' => round($charged, 2),
            'charged_count' => $counts['refunded'],
        ];
    }

    /**
     * The store's unpaid count for its menu, at most ten minutes old. Never
     * throws: a menu must not fail because a count could not be had.
     *
     * @param  array<string, mixed>  $d
     */
    public function badge(array $d, int $storeId): ?int
    {
        try {
            return Cache::remember(self::badgeKey($d['channel'], $storeId), self::BADGE_TTL,
                fn () => $this->forStore($d, $storeId)['not_paid_count']);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function badgeKey(string $channel, int $storeId): string
    {
        return "payouts:attention:{$channel}:{$storeId}";
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

    private function row(string $flag, object $o, ?CarbonInterface $done, ?float $expected, bool $estimated, ?float $received): array
    {
        return [
            'flag' => $flag,
            'id' => (int) $o->id,
            'ref' => (string) $o->ref,
            'catalog_order_id' => $o->catalog_order_id ? (int) $o->catalog_order_id : null,
            'done' => $done,
            'waiting_days' => $done ? (int) $done->diffInDays(now()) : 0,
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
