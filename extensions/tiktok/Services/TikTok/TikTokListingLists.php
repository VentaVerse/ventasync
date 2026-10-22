<?php

namespace Extensions\TikTok\Services\TikTok;

use App\Channels\Contracts\ListingLists;
use Extensions\TikTok\Models\TikTokStore;

final class TikTokListingLists implements ListingLists
{
    public function names(): array
    {
        return ['categories'];
    }

    public function refresh(string $list): array
    {
        $raw = TikTokStore::defaultStore();
        $c = $raw ? TikTokStoreSetup::creds($raw) : null;
        if (! $raw || ! $c || $c['app_key'] === '' || $c['app_secret'] === '' || $c['token'] === '') {
            return ['ok' => false, 'count' => 0, 'message' => 'This store is not connected to TikTok Shop.'];
        }

        return app(TikTokStoreSetup::class)->categories($raw);
    }
}
