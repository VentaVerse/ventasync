<?php

namespace App\Services\Payouts;

class PayoutRegistry
{
    private array $channels = [];

    public function register(PayoutChannel $channel): void
    {
        $this->channels[$channel->id] = $channel;
    }

    public function channel(string $id): ?PayoutChannel
    {
        return $this->channels[$id] ?? null;
    }

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

                continue;
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
