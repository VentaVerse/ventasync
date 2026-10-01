<?php

namespace App\Services\Payouts;

/**
 * The marketplaces that pay out per order, as their extensions registered
 * them (PayoutChannel). An extension that is off registers nothing, a store
 * that is off is not listed, and a new store is listed the moment it is
 * switched on: every payout surface reads this, so all of them agree.
 */
class PayoutRegistry
{
    /** @var array<string, PayoutChannel> */
    private array $channels = [];

    public function register(PayoutChannel $channel): void
    {
        $this->channels[$channel->id] = $channel;
    }

    public function channel(string $id): ?PayoutChannel
    {
        return $this->channels[$id] ?? null;
    }

    /**
     * Every switched-on store of every channel this person may see the
     * payouts of, in channel order (Shopee, Lazada, TikTok Shop).
     *
     * @param  array<string, list<int|null>>|null  $only  channel => store ids (null id = every store of it); null = all
     * @return list<array{channel: PayoutChannel, store: object}>
     */
    public function storesFor(?object $user, ?array $only = null): array
    {
        $order = ['shopee' => 0, 'lazada' => 1, 'tiktok' => 2];
        $channels = $this->channels;
        uasort($channels, fn ($a, $b) => ($order[$a->id] ?? 9) <=> ($order[$b->id] ?? 9));

        $out = [];
        foreach ($channels as $channel) {
            if (! $channel->visibleTo($user)) {
                continue;
            }
            if ($only !== null && ! array_key_exists($channel->id, $only)) {
                continue;
            }
            try {
                $stores = $channel->stores();
            } catch (\Throwable $e) {
                report($e);

                continue;   // one channel's broken table never hides the others
            }
            $ids = $only[$channel->id] ?? [null];
            foreach ($stores as $store) {
                if (in_array(null, $ids, true) || in_array((int) $store->id, $ids, true)) {
                    $out[] = ['channel' => $channel, 'store' => $store];
                }
            }
        }

        return $out;
    }
}
