<?php

namespace Extensions\Shopee\Services\Shopee;

use App\Channels\Contracts\ListingLists;
use Extensions\Shopee\Models\ShopeeLogistic;
use Extensions\Shopee\Models\ShopeeStore;

final class ShopeeListingLists implements ListingLists
{
    public function names(): array
    {
        return ['categories', 'couriers'];
    }

    public function refresh(string $list): array
    {
        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (! $store || ! $auth['complete']) {
            return ['ok' => false, 'count' => 0, 'message' => 'This store is not connected to Shopee.'];
        }
        $setup = app(ShopeeStoreSetup::class);
        if ($list === 'categories') {
            return $setup->categories($auth);
        }

        return $setup->couriers($auth) + ['items' => self::couriers()];
    }

    public static function couriers(): array
    {
        return ShopeeLogistic::query()->orderByDesc('enabled')->orderBy('logistics_channel_name')->get()
            ->map(fn ($l) => [
                'logistic_id' => (int) $l->logistics_channel_id,
                'logistic_name' => (string) $l->logistics_channel_name,
                'enabled' => (bool) ($l->enabled || $l->force_enable),
            ])->all();
    }
}
