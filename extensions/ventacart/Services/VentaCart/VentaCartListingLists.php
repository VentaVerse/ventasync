<?php

namespace Extensions\VentaCart\Services\VentaCart;

use App\Channels\Contracts\ListingLists;
use App\Channels\Listings\ListingStore;
use Extensions\VentaCart\Models\VentaCartStore;

final class VentaCartListingLists implements ListingLists
{
    public function names(): array
    {
        return ['categories'];
    }

    public function refresh(string $list): array
    {
        $store = VentaCartStore::query()->find(ListingStore::id('ventacart'));
        if (! $store) {
            return ['ok' => false, 'count' => 0, 'message' => 'This store is not connected to VentaCart.'];
        }

        return app(VentaCartStoreSetup::class)->categories($store);
    }
}
