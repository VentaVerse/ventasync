<?php

namespace App\Integrations\Listings;

final class ListingStore
{
    public static function id(string $integration): int
    {
        $key = $integration . '.route-store';
        if (app()->bound($key)) {
            return (int) (app($key)->id ?? 0);
        }

        return (int) (request()->route('store') ?? 0);
    }
}
