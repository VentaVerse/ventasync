<?php

namespace App\Services\Payouts;

use App\Support\PayoutExpectation;
use App\Support\PayoutStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PayoutAttention
{
    public const OVERDUE_DAYS = 30;

    private const SHORT_TOLERANCE = 1.00;

    private const BADGE_TTL = 600;

    private const STALE_DONE_DAYS = 60;

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

    public function badge(array $d, int $storeId): ?int
    {
        return $this->summary($d, $storeId)['not_paid_count'] ?? null;
    }

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

    public static function compare(array $a, array $b): int
    {
        $order = ['overdue' => 0, 'short' => 1, 'failed' => 2, 'nopayout' => 3, 'refunded' => 4];

        return [$order[$a['flag']], -$a['waiting_days']] <=> [$order[$b['flag']], -$b['waiting_days']];
    }

    private static function summaryKey(string $channel, int $storeId): string
    {
        return "payouts:summary:{$channel}:{$storeId}";
    }

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
