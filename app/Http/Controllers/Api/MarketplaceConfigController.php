<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Schema;

class MarketplaceConfigController extends Controller
{
    public function index()
    {
        return response()->json([
            'lazada'          => $this->isMarketplaceActive(\Extensions\lazada\Models\LazadaSetting::class, 'lazada_settings'),
            'shopee'          => $this->isMarketplaceActive(\Extensions\shopee\Models\ShopeeSetting::class, 'shopee_settings'),
            'tiktok'          => $this->isMarketplaceActive(\Extensions\tiktok\Models\TikTokSetting::class, 'tiktok_settings'),
            'opencart_stores' => $this->getExtensionStores(\Extensions\opencart\Models\OpenCartSetting::class, 'opencart_settings'),
            'venta_stores'    => $this->getExtensionStores(\Extensions\venta\Models\VentaSetting::class, 'venta_settings'),
        ]);
    }

    /**
     * Check if a marketplace (Lazada/Shopee/TikTok) is active.
     *
     * Active = record exists AND the relevant access_token is non-null
     * (sandbox_access_token when mode is 'sandbox', access_token otherwise).
     */
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

    /**
     * Get enabled stores from an extension settings table (OpenCart / Venta).
     *
     * Returns an array of [{id, name}] for each enabled store,
     * or an empty array if the extension is not installed.
     */
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
