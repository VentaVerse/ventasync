<?php

namespace Extensions\Lazada\Services\Lazada;

use App\Channels\Contracts\ListingLists;
use Extensions\Lazada\Models\LazadaStore;

final class LazadaListingLists implements ListingLists
{
    public function names(): array
    {
        return ['categories', 'brands'];
    }

    public function refresh(string $list): array
    {
        $store = LazadaStore::defaultStore();
        $setting = $store?->decrypted();
        $creds = LazadaStore::activeCredentials($setting);
        if (! $store || ! $setting || ! $setting->region || empty($creds['app_key']) || empty($creds['app_secret'])) {
            return ['ok' => false, 'count' => 0, 'message' => 'This store is not connected to Lazada.'];
        }
        $setup = app(LazadaStoreSetup::class);

        return $list === 'categories' ? $setup->categories($store, $creds) : $setup->brands($store, $creds);
    }
}
