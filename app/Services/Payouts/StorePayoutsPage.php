<?php

namespace App\Services\Payouts;

use App\Models\ScheduledJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * One store's Payouts page, under its Sell menu (the owner, 2026-10-01). The
 * same page for Shopee, Lazada and TikTok: each channel's controller hands
 * over its attention descriptor and a reader for its live balance, and
 * this puts the two together.
 *
 * The balance is read from the marketplace and kept five minutes, so a page
 * moving between filters does not re-ask it each time. A refusal is kept
 * one minute only, so the next look tries again soon.
 */
class StorePayoutsPage
{
    private const BALANCE_TTL = 300;

    private const REFUSAL_TTL = 60;

    /** A forced re-read is honoured only when the kept one is older than this. */
    private const MIN_REFRESH_AGE = 60;

    public function __construct(private readonly PayoutAttention $attention)
    {
    }

    /**
     * @param  array<string, mixed>  $descriptor  the channel's PayoutSync::attention()
     * @param  callable(): array  $readBalance  the channel's live balance read
     * @param  list<array<string, mixed>>  $extraFacts  channel facts from the ERP's own data
     */
    public function view(array $descriptor, object $store, string $channelName, callable $readBalance, array $extraFacts = []): View
    {
        $channel = (string) $descriptor['channel'];
        $storeId = (int) $store->id;

        $balance = $this->balance($channel, $storeId, $readBalance, request()->boolean('refresh'));
        if ($balance['ok'] ?? false) {
            $balance['facts'] = array_merge($balance['facts'] ?? [], $extraFacts);
        }

        $attention = $this->attention->forStore($descriptor, $storeId);

        $flag = (string) request()->query('flag', 'all');
        if ($flag !== 'all' && ! array_key_exists($flag, $attention['counts'])) {
            $flag = 'all';
        }

        $job = ScheduledJob::query()
            ->where('command', $channel . ':sync-payouts')
            ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $storeId))
            ->orderByRaw('store_id IS NULL')
            ->first();

        $user = auth()->user();
        $canRun = $job !== null && $user !== null
            && ($user->hasPermission("manage_{$channel}/settings") || $user->hasPermission("manage_{$channel}"));

        return view('payouts.store', [
            'channel' => $channel,
            'channelName' => $channelName,
            'store' => $store,
            'storeName' => (string) ($store->store_name ?: 'Store #' . $storeId),
            'balance' => $balance,
            'attention' => $attention,
            'rows' => $flag === 'all' ? $attention['rows'] : array_values(array_filter($attention['rows'], fn ($r) => $r['flag'] === $flag)),
            'flag' => $flag,
            'descriptor' => $descriptor,
            'job' => $job,
            'canRun' => $canRun,
            'overdueDays' => PayoutAttention::OVERDUE_DAYS,
            'hasShort' => ($descriptor['received_column'] ?? null) !== null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function balance(string $channel, int $storeId, callable $read, bool $refresh): array
    {
        $key = "payouts:balance:{$channel}:{$storeId}";
        $kept = Cache::get($key);

        if (is_array($kept) && ! ($refresh && time() - (int) ($kept['checked_at'] ?? 0) >= self::MIN_REFRESH_AGE)) {
            return $kept;
        }

        try {
            $fresh = $read();
        } catch (\Throwable $e) {
            report($e);
            $fresh = ['ok' => false, 'error' => 'The marketplace could not be reached.'];
        }
        $fresh['checked_at'] = time();
        Cache::put($key, $fresh, ($fresh['ok'] ?? false) ? self::BALANCE_TTL : self::REFUSAL_TTL);

        return $fresh;
    }
}
