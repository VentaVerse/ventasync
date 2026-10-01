<?php

namespace App\Services\Payouts;

use App\Support\PayoutStatus as PayoutStatusAlias;

/**
 * Every store's payouts at once (the owner, 2026-10-01): the panels on the
 * Payouts report, a store's Overview and the dashboard rail. Only stores
 * whose payouts this person may see, only stores that are switched on;
 * nothing here waits on a marketplace (PayoutBalances::kept).
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
                // The Payouts report, already run for this store (2026-10-02),
                // or the store's Overview for someone who cannot open Reports.
                'url' => $this->reportUrl($user, $channel, $store) ?? route($channel->pageRoute, ['store' => (int) $store->id]),
                // Its unpaid orders: the same report, narrowed to the payout
                // states an unpaid order is in.
                'unpaid_url' => $this->reportUrl($user, $channel, $store, unpaid: true),
                'report_url' => $this->reportUrl($user, $channel, $store),
            ];
        }

        return $cards;
    }

    /**
     * Reports > Payouts, run for one store, all time: opening it shows that
     * store's report at once. Null when the Reports extension is off or this
     * person cannot read reports, so no panel ever links to a door shut.
     */
    public function reportUrl(?object $user, PayoutChannel $channel, object $store, bool $unpaid = false): ?string
    {
        if (! \Illuminate\Support\Facades\Route::has('ext.reports.payouts')
            || ! ($user && method_exists($user, 'hasPermission') && $user->hasPermission('view_reports/report'))) {
            return null;
        }

        return route('ext.reports.payouts', array_filter([
            'store' => [$channel->id . ':' . (int) $store->id],
            'date_from' => \Extensions\reports\Services\ReportPeriod::ALL_TIME_FROM,
            'date_to' => now()->format('Y-m-d'),
            'payout_status' => $unpaid ? [PayoutStatusAlias::RELEASING, PayoutStatusAlias::NO_PAYOUT, PayoutStatusAlias::FAILED] : null,
        ]));
    }
}
