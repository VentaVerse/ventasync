<?php

namespace App\Integrations\Listings;

final class BlankFillRefusal
{
    public static function sentence(string $channel, ?string $reason = null): string
    {
        $reason = trim((string) $reason);
        $said = $reason !== '' ? ' ' . rtrim($reason, '.') . '.' : '';

        return $channel . ' did not answer for the listings whose status is not shown yet.' . $said
            . ' Press Refresh, or wait for the scheduled refresh.';
    }
}
