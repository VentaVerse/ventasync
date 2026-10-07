<?php

namespace App\Services\Payouts;

use App\Support\PayoutStatus as PayoutStatusAlias;

class PayoutOverview
{
    public function __construct(
        private readonly PayoutRegistry $registry,
        private readonly PayoutBalances $balances,
        private readonly PayoutAttention $attention,
    ) {
    }

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
                'url' => $this->reportUrl($user, $channel, $store) ?? route($channel->pageRoute, ['store' => (int) $store->id]),
                'unpaid_url' => $this->reportUrl($user, $channel, $store, unpaid: true),
                'report_url' => $this->reportUrl($user, $channel, $store),
            ];
        }

        return $cards;
    }

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
