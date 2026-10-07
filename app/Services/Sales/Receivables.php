<?php

namespace App\Services\Sales;

use App\Models\Catalog\Order;
use App\Models\OrderPayment;
use App\Services\ActivityLogger;
use App\Support\Actor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class Receivables
{
    public const METHODS = ['Bank Transfer', 'GCash', 'Cash', 'Check', 'Maya', 'Other'];

    private const AGES = ['0_30' => [0, 30], '31_60' => [31, 60], '61_90' => [61, 90], 'over_90' => [91, PHP_INT_MAX]];

    public function __construct(private readonly CustomerLedger $ledger)
    {
    }

    public function rows(array $customerKeys = [], array $orderIds = [], ?string $from = null, ?string $to = null, ?string $search = null): Collection
    {
        $pfx = (string) config('catalog.prefix');
        $lang = (int) config('catalog.default_language_id');

        $query = DB::table($pfx . 'order as o')
            ->leftJoin($pfx . 'order_status as os', fn ($j) => $j->on('os.order_status_id', '=', 'o.order_status_id')->where('os.language_id', '=', $lang))
            ->leftJoin(DB::raw('(SELECT order_id, SUM(amount) AS paid, COUNT(*) AS payments, MAX(paid_at) AS last_paid_at FROM order_payments GROUP BY order_id) as pay'),
                'pay.order_id', '=', 'o.order_id')
            ->where('o.track_payments', true);

        if ($orderIds !== []) {
            $query->whereIn('o.order_id', $orderIds);
        }
        if ($from) {
            $query->where('o.date_added', '>=', $from . ' 00:00:00');
        }
        if ($to) {
            $query->where('o.date_added', '<=', $to . ' 23:59:59');
        }
        if ($search = trim((string) $search)) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $query->where(function ($q) use ($search, $like) {
                $q->where('o.order_id', ctype_digit($search) ? (int) $search : 0)
                    ->orWhere('o.marketplace_order_id', 'like', $like)
                    ->orWhere('o.firstname', 'like', $like)
                    ->orWhere('o.lastname', 'like', $like)
                    ->orWhereRaw("CONCAT(COALESCE(o.firstname,''), ' ', COALESCE(o.lastname,'')) LIKE ?", [$like]);
            });
        }

        $orders = $query->orderBy('o.date_added')->orderBy('o.order_id')->get([
            'o.order_id', 'o.date_added', 'o.firstname', 'o.lastname', 'o.email', 'o.telephone',
            'o.store_name', 'o.marketplace_source', 'o.marketplace_order_id', 'os.name as status', 'o.total',
            'pay.paid', 'pay.payments', 'pay.last_paid_at',
        ]);

        $keys = array_values(array_unique(array_map(fn ($k) => trim((string) $k), $customerKeys)));
        $today = now()->startOfDay();

        return $orders
            ->map(function ($o) use ($today) {
                $total = round((float) $o->total, 2);
                $paid = round((float) ($o->paid ?? 0), 2);

                return [
                    'order_id' => (int) $o->order_id,
                    'date' => substr((string) $o->date_added, 0, 10),
                    'customer' => trim(($o->firstname ?? '') . ' ' . ($o->lastname ?? '')),
                    'customer_key' => $this->ledger->identity($o),
                    'store' => $o->store_name ?: ($o->marketplace_source ?: 'Manual'),
                    'marketplace_order_id' => $o->marketplace_order_id ?: null,
                    'status' => $o->status,
                    'total' => $total,
                    'paid' => $paid,
                    'balance' => round(max(0, $total - $paid), 2),
                    'is_paid' => $total > 0 && $paid >= $total - 0.005,
                    'payments' => (int) ($o->payments ?? 0),
                    'last_paid_at' => $o->last_paid_at ? substr((string) $o->last_paid_at, 0, 10) : null,
                    'days_outstanding' => (int) \Carbon\CarbonImmutable::parse($o->date_added)->startOfDay()->diffInDays($today),
                ];
            })
            ->when($keys !== [], fn ($rows) => $rows->filter(fn ($r) => in_array($r['customer_key'], $keys, true)))
            ->values();
    }

    public function totals(Collection $rows): array
    {
        $unpaid = $rows->reject(fn ($r) => $r['is_paid']);
        $aging = [];
        foreach (self::AGES as $name => [$min, $max]) {
            $aging[$name] = round((float) $unpaid->filter(fn ($r) => $r['days_outstanding'] >= $min && $r['days_outstanding'] <= $max)->sum('balance'), 2);
        }

        return [
            'orders' => $rows->count(),
            'unpaid_orders' => $unpaid->count(),
            'paid_orders' => $rows->count() - $unpaid->count(),
            'total' => round((float) $rows->sum('total'), 2),
            'paid' => round((float) $rows->sum('paid'), 2),
            'owed' => round((float) $unpaid->sum('balance'), 2),
            'aging' => $aging,
            'by_customer' => $unpaid->groupBy(fn ($r) => $r['customer_key'] ?? 'order:' . $r['order_id'])
                ->map(fn (Collection $g, $key) => [
                    'customer' => $g->first()['customer'],
                    'customer_key' => str_starts_with((string) $key, 'order:') ? null : $key,
                    'orders' => $g->count(),
                    'owed' => round((float) $g->sum('balance'), 2),
                    'oldest' => $g->min('date'),
                ])
                ->sortByDesc('owed')->values()->all(),
        ];
    }

    public function ofOrder(int $orderId): ?array
    {
        $row = $this->rows([], [$orderId])->first();
        if ($row === null) {
            return null;
        }

        return [
            'total' => $row['total'],
            'paid' => $row['paid'],
            'balance' => $row['balance'],
            'is_paid' => $row['is_paid'],
            'payments' => OrderPayment::where('order_id', $orderId)->orderBy('paid_at')->orderBy('id')->get()
                ->map(fn (OrderPayment $p) => [
                    'payment_id' => (int) $p->id,
                    'date' => $p->paid_at?->toDateString(),
                    'amount' => round((float) $p->amount, 2),
                    'method' => $p->payment_method,
                    'reference' => $p->reference_no,
                    'notes' => $p->notes,
                ])->all(),
        ];
    }

    public function split(Collection $rows, float $amount): array
    {
        $left = round($amount, 2);
        $plan = [];
        foreach ($rows->reject(fn ($r) => $r['is_paid'] || $r['balance'] <= 0) as $row) {
            if ($left <= 0) {
                break;
            }
            $take = round(min($left, $row['balance']), 2);
            $plan[] = [
                'order_id' => $row['order_id'],
                'date' => $row['date'],
                'customer' => $row['customer'],
                'amount' => $take,
                'balance_before' => $row['balance'],
                'balance_after' => round($row['balance'] - $take, 2),
            ];
            $left = round($left - $take, 2);
        }

        return $plan;
    }

    public function record(array $orderIds, float $amount, string $method, string $date, ?string $reference, ?string $notes, string $reason): array
    {
        return DB::transaction(function () use ($orderIds, $amount, $method, $date, $reference, $notes, $reason) {
            Order::whereIn('order_id', $orderIds)->lockForUpdate()->get(['order_id']);

            $rows = $this->rows([], $orderIds);
            $owed = round((float) $rows->reject(fn ($r) => $r['is_paid'])->sum('balance'), 2);
            if ($amount > $owed + 0.005) {
                throw new \DomainException('Only ' . number_format($owed, 2) . ' is owed on ' . (count($orderIds) === 1 ? 'that order' : 'those orders')
                    . ', less than ' . number_format($amount, 2) . '. Nothing was recorded.');
            }

            $plan = $this->split($rows, $amount);
            $shared = count($plan) > 1
                ? 'Part of one payment of ' . number_format($amount, 2) . ' over orders #' . implode(', #', array_column($plan, 'order_id')) . '.'
                : null;
            $actor = Actor::current();

            foreach ($plan as $i => $part) {
                $payment = OrderPayment::create([
                    'order_id' => $part['order_id'],
                    'amount' => $part['amount'],
                    'payment_method' => $method,
                    'paid_at' => $date,
                    'reference_no' => $reference,
                    'notes' => trim(implode(' ', array_filter([$notes, $shared]))) ?: null,
                    'created_by' => $actor->userId,
                    'created_at' => now(),
                ]);
                $plan[$i]['payment_id'] = (int) $payment->id;

                ActivityLogger::log('created', 'Order Payment', $part['order_id'],
                    '#' . $part['order_id'] . ' - ' . number_format($part['amount'], 2) . ' via ' . $method . '. Reason: ' . $reason,
                    ['balance' => [number_format($part['balance_before'], 2, '.', ''), number_format($part['balance_after'], 2, '.', '')]]);
            }

            return $plan;
        });
    }
}
