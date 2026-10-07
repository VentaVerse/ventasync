<?php

namespace Extensions\shopee\Controllers;

use App\Http\Controllers\Controller;

use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeLogistic;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
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
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->route('ext.shopee.logistics.index')
                ->with('error', "Missing Shopee {$modeLabel} settings. Configure Partner ID, Partner Key, Access Token and Shop ID first.");
        }

        $out = app(\Extensions\shopee\Services\Shopee\ShopeeStoreSetup::class)->couriers($auth);

        return redirect()->route('ext.shopee.logistics.index')
            ->with($out['ok'] ? 'status' : 'error', $out['message']);
    }

}
