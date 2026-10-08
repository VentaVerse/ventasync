<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Schema;

class MarketplaceConfigController extends Controller
{
    public function index()
    {
        return response()->json([
            'lazada'          => $this->isMarketplaceActive(\Extensions\Lazada\Models\LazadaSetting::class, 'lazada_settings'),
            'shopee'          => $this->isMarketplaceActive(\Extensions\Shopee\Models\ShopeeSetting::class, 'shopee_settings'),
            'tiktok'          => $this->isMarketplaceActive(\Extensions\TikTok\Models\TikTokSetting::class, 'tiktok_settings'),
            'opencart_stores' => $this->getExtensionStores(\Extensions\OpenCart\Models\OpenCartSetting::class, 'opencart_settings'),
            'ventacart_stores'    => $this->getExtensionStores(\Extensions\VentaCart\Models\VentaCartSetting::class, 'ventacart_settings'),
            'lazada_stores'   => $this->getExtensionStores(\Extensions\Lazada\Models\LazadaSetting::class, 'lazada_settings'),
            'shopee_stores'   => $this->getExtensionStores(\Extensions\Shopee\Models\ShopeeSetting::class, 'shopee_settings'),
            'tiktok_stores'   => $this->getExtensionStores(\Extensions\TikTok\Models\TikTokSetting::class, 'tiktok_settings'),
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
