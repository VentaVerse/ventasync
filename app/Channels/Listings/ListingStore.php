<?php

namespace App\Channels\Listings;

final class ListingStore
{
    public static function id(string $channel): int
    {
        $key = $channel . '.route-store';
        if (app()->bound($key)) {
            return (int) (app($key)->id ?? 0);
        }

        return (int) (request()->route('store') ?? 0);
    }
}
