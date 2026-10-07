<?php

namespace App\Support;

final class StoreLabel
{
    public static function of(string $channel, ?string $store): string
    {
        $channel = trim($channel);
        $store = trim((string) $store);

        if ($store === '' || strcasecmp($store, $channel) === 0) {
            return $channel;
        }
        if (stripos($store, $channel . ': ') === 0) {
            return $store;
        }

        return $channel . ': ' . $store;
    }
}
