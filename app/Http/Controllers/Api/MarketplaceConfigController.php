<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Schema;

class MarketplaceConfigController extends Controller
{
    public function index()
    {
        return response()->json([
            'lazada'          => $this->isMarketplaceActive(\Extensions\Lazada\Models\LazadaStore::class, 'ch_lazada_stores'),
            'shopee'          => $this->isMarketplaceActive(\Extensions\Shopee\Models\ShopeeStore::class, 'ch_shopee_stores'),
            'tiktok'          => $this->isMarketplaceActive(\Extensions\TikTok\Models\TikTokStore::class, 'ch_tiktok_stores'),
            'opencart_stores' => $this->getExtensionStores(\Extensions\OpenCart\Models\OpenCartStore::class, 'ch_opencart_stores'),
            'ventacart_stores'    => $this->getExtensionStores(\Extensions\VentaCart\Models\VentaCartStore::class, 'ch_ventacart_stores'),
            'lazada_stores'   => $this->getExtensionStores(\Extensions\Lazada\Models\LazadaStore::class, 'ch_lazada_stores'),
            'shopee_stores'   => $this->getExtensionStores(\Extensions\Shopee\Models\ShopeeStore::class, 'ch_shopee_stores'),
            'tiktok_stores'   => $this->getExtensionStores(\Extensions\TikTok\Models\TikTokStore::class, 'ch_tiktok_stores'),
        ]);
    }

    private function isMarketplaceActive(string $modelClass, string $table): bool
    {
        if (!class_exists($modelClass) || !Schema::hasTable($table)) {
            return false;
        }

        $setting = $modelClass::first();

        if (!$setting) {
            return false;
        }

        $tokenField = $setting->mode === 'sandbox'
            ? 'sandbox_access_token'
            : 'access_token';

        return !empty($setting->{$tokenField});
    }

    private function getExtensionStores(string $modelClass, string $table): array
    {
        if (!class_exists($modelClass) || !Schema::hasTable($table)) {
            return [];
        }

        return $modelClass::where('enabled', true)
            ->get(['id', 'store_name'])
            ->map(fn ($store) => [
                'id'   => $store->id,
                'name' => $store->store_name,
            ])
            ->values()
            ->toArray();
    }
}
