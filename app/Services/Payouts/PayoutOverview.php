<?php

namespace App\Services\Payouts;

/**
 * Every store's payouts at once (the owner, 2026-10-01): the store cards on the
 * Payouts report and the dashboard rail, and the report's one Needs
 * attention list across all stores. Only stores whose payouts this person
 * may see, only stores that are switched on; nothing here waits on a
 * marketplace (PayoutBalances::kept).
 */
class PayoutOverview
{
    public function __construct(
        private readonly PayoutRegistry $registry,
        private readonly PayoutBalances $balances,
        private readonly PayoutAttention $attention,
    ) {
    }

    /**
     * One card per store.
     *
     * @param  array<string, list<int|null>>|null  $only  channel => store ids, from a Store filter
     * @return list<array<string, mixed>>
     */
    public function cards(?object $user, ?array $only = null): array
    {
        $cards = [];
        foreach ($this->registry->storesFor($user, $only) as ['channel' => $channel, 'store' => $store]) {
            $balance = $this->balances->kept($channel, $store, refreshIfStale: true);
            if ($balance !== null && ($balance['ok'] ?? false)) {
                $balance['facts'] = array_merge($balance['facts'] ?? [], $channel->extraFacts($store));
            }
            $cards[] = [
                'channel' => $channel,
                'store' => $store,
                'storeName' => (string) ($store->store_name ?: 'Store #' . $store->id),
                'balance' => $balance,
                'summary' => $this->attention->summary($channel->attention, (int) $store->id),
                'url' => route($channel->pageRoute, ['store' => (int) $store->id]),
            ];
        }

        return $cards;
    }

    /**
     * The unpaid orders of every store, in one list.
     *
     * @param  array<string, list<int|null>>|null  $only
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>, not_paid: float, not_paid_count: int, charged: float, charged_count: int, stores_owing: int, has_short: bool}
     */
    public function attention(?object $user, ?array $only = null): array
    {
        $rows = [];
        $counts = array_fill_keys(['overdue', 'nopayout', 'failed', 'short', 'refunded'], 0);
        $notPaid = 0.0;
        $charged = 0.0;
        $owing = 0;
        $hasShort = false;

        foreach ($this->registry->storesFor($user, $only) as ['channel' => $channel, 'store' => $store]) {
            $one = $this->attention->forStore($channel->attention, (int) $store->id);
            $hasShort = $hasShort || ($channel->attention['received_column'] ?? null) !== null;
            foreach ($one['counts'] as $k => $n) {
                $counts[$k] += $n;
            }
            $notPaid += $one['not_paid'];
            $charged += $one['charged'];
            $owing += $one['not_paid_count'] > 0 ? 1 : 0;

            $storeName = (string) ($store->store_name ?: 'Store #' . $store->id);
            foreach ($one['rows'] as $r) {
                $value = ($channel->attention['order_key'] ?? 'ref') === 'id' ? $r['id'] : $r['ref'];
                $rows[] = $r + [
                    'channel' => $channel,
                    'storeName' => $storeName,
                    'order_url' => route($channel->attention['order_route'], [
                        'store' => (int) $store->id,
                        $channel->attention['order_param'] => $value,
                    ]),
                ];
            }
        }

        usort($rows, [PayoutAttention::class, 'compare']);

        return [
            'rows' => $rows,
            'counts' => $counts,
            'not_paid' => round($notPaid, 2),
            'not_paid_count' => count($rows) - $counts['refunded'],
            'charged' => round($charged, 2),
            'charged_count' => $counts['refunded'],
            'stores_owing' => $owing,
            'has_short' => $hasShort,
        ];
    }
}
