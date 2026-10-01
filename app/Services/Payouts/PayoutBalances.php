<?php

namespace App\Services\Payouts;

use Illuminate\Support\Facades\Cache;

/**
 * The money at each marketplace, kept so that no page waits on it (the owner,
 * 2026-10-01).
 *
 * A store's own Payouts page reads it when what is kept is older than five
 * minutes: someone who opened that page asked for it. The dashboard and the
 * report never wait: they show what is kept, with its age, and when it is
 * older than five minutes they ask for a fresh one after the page has been
 * sent, so the next look has it. A refusal is kept one minute only.
 */
class PayoutBalances
{
    public const FRESH_FOR = 300;

    /** What is kept is shown up to a day old, always with its age. */
    private const KEEP_FOR = 86400;

    private const REFUSAL_TTL = 60;

    private const MIN_REFRESH_AGE = 60;

    /** Read now when stale (a store page). */
    public function current(PayoutChannel $channel, object $store, bool $force = false): array
    {
        $kept = $this->kept($channel, $store);
        $age = $kept ? time() - (int) ($kept['checked_at'] ?? 0) : PHP_INT_MAX;

        if ($kept && $age < self::FRESH_FOR && ! ($force && $age >= self::MIN_REFRESH_AGE)) {
            return $kept;
        }

        $fresh = $this->read($channel, $store);

        // A refusal never replaces a good figure: the good one is shown, with its age.
        return ($fresh['ok'] ?? false) || ! ($kept['ok'] ?? false) ? $fresh : $kept + ['stale_error' => $fresh['error'] ?? null];
    }

    /**
     * What is kept, without waiting (dashboard, report). Null when nothing
     * has been read yet; a fresh read is then due after the response.
     */
    public function kept(PayoutChannel $channel, object $store, bool $refreshIfStale = false): ?array
    {
        $kept = Cache::get($this->key($channel, $store));
        $kept = is_array($kept) ? $kept : null;

        if ($refreshIfStale && (! $kept || time() - (int) ($kept['checked_at'] ?? 0) >= self::FRESH_FOR)
            && Cache::add($this->key($channel, $store) . ':reading', 1, 120)) {
            app()->terminating(function () use ($channel, $store) {
                try {
                    $this->read($channel, $store);
                } finally {
                    Cache::forget($this->key($channel, $store) . ':reading');
                }
            });
        }

        return $kept;
    }

    /** Read now and keep it: the hourly Sync Payouts calls this, so no page meets an empty one. */
    public function refresh(PayoutChannel $channel, object $store): array
    {
        return $this->read($channel, $store);
    }

    private function read(PayoutChannel $channel, object $store): array
    {
        try {
            $fresh = $channel->readBalance($store);
        } catch (\Throwable $e) {
            report($e);
            $fresh = ['ok' => false, 'error' => 'The marketplace could not be reached.'];
        }
        $fresh['checked_at'] = time();

        if ($fresh['ok'] ?? false) {
            Cache::put($this->key($channel, $store), $fresh, self::KEEP_FOR);
        } elseif (! (Cache::get($this->key($channel, $store))['ok'] ?? false)) {
            Cache::put($this->key($channel, $store), $fresh, self::REFUSAL_TTL);
        }

        return $fresh;
    }

    private function key(PayoutChannel $channel, object $store): string
    {
        return 'payouts:balance:' . $channel->id . ':' . (int) $store->id;
    }
}
