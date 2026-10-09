<?php

namespace Extensions\Shopee\Controllers;

use App\Http\Controllers\Controller;

use Extensions\Shopee\Models\ShopeeApiLog;
use Extensions\Shopee\Models\ShopeeLogistic;
use Extensions\Shopee\Models\ShopeeStore;
use Extensions\Shopee\Services\Shopee\ShopeeClient;
use Illuminate\Http\Request;

class ShopeeLogisticsController extends Controller
{
    public function index()
    {
        $channels = ShopeeLogistic::query()
            ->orderByDesc('enabled')
            ->orderBy('logistics_channel_name')
            ->get();

        return view('ext-shopee::logistics.index', [
            'channels' => $channels,
        ]);
    }

    public function fetch(ShopeeClient $client)
    {
        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->route('ext.shopee.logistics.index')
                ->with('error', "Missing Shopee {$modeLabel} settings. Configure Partner ID, Partner Key, Access Token and Shop ID first.");
        }

        $out = app(\Extensions\Shopee\Services\Shopee\ShopeeStoreSetup::class)->couriers($auth);

        return redirect()->route('ext.shopee.logistics.index')
            ->with($out['ok'] ? 'status' : 'error', $out['message']);
    }

}
