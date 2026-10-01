<?php

namespace App\Services\Payouts;

use App\Models\ScheduledJob;
use Illuminate\View\View;

/**
 * One store's Payouts page, under its Sell menu (the owner, 2026-10-01). The
 * same page for every channel in the PayoutRegistry: the money at the
 * marketplace now (PayoutBalances, read here when older than five minutes,
 * because someone opened this page to see it), and the orders it has not
 * truly paid for (PayoutAttention).
 */
class StorePayoutsPage
{
    public function __construct(
        private readonly PayoutAttention $attention,
        private readonly PayoutBalances $balances,
    ) {
    }

    public function view(PayoutChannel $channel, object $store): View
    {
        $storeId = (int) $store->id;

        $balance = $this->balances->current($channel, $store, request()->boolean('refresh'));
        if ($balance['ok'] ?? false) {
            $balance['facts'] = array_merge($balance['facts'] ?? [], $channel->extraFacts($store));
        }

        $attention = $this->attention->forStore($channel->attention, $storeId);
        $attention['rows'] = array_map(fn ($r) => $r + ['order_url' => route($channel->attention['order_route'], [
            'store' => $storeId,
            $channel->attention['order_param'] => ($channel->attention['order_key'] ?? 'ref') === 'id' ? $r['id'] : $r['ref'],
        ])], $attention['rows']);

        $flag = (string) request()->query('flag', 'all');
        if ($flag !== 'all' && ! array_key_exists($flag, $attention['counts'])) {
            $flag = 'all';
        }

        $job = ScheduledJob::query()
            ->where('command', $channel->id . ':sync-payouts')
            ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $storeId))
            ->orderByRaw('store_id IS NULL')
            ->first();

        $user = auth()->user();
        $canRun = $job !== null && $user !== null
            && ($user->hasPermission("manage_{$channel->id}/settings") || $user->hasPermission("manage_{$channel->id}"));

        return view('payouts.store', [
            'channel' => $channel->id,
            'channelName' => $channel->name,
            'store' => $store,
            'storeName' => (string) ($store->store_name ?: 'Store #' . $storeId),
            'balance' => $balance,
            'attention' => $attention,
            'rows' => $flag === 'all' ? $attention['rows'] : array_values(array_filter($attention['rows'], fn ($r) => $r['flag'] === $flag)),
            'flag' => $flag,
            'descriptor' => $channel->attention,
            'job' => $job,
            'canRun' => $canRun,
            'overdueDays' => PayoutAttention::OVERDUE_DAYS,
            'hasShort' => ($channel->attention['received_column'] ?? null) !== null,
        ]);
    }
}
