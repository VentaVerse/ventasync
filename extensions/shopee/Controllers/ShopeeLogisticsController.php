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
        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->route('ext.shopee.logistics.index')
                ->with('error', 'Missing Shopee settings. Configure Partner ID, Partner Key, Access Token and Shop ID first.');
        }

        $path = '/api/v2/logistics/get_channel_list';

        $result = $client->shopGet(
            $setting->mode ?? 'sandbox',
            (int) $setting->partner_id,
            (string) $setting->partner_key,
            (string) $setting->access_token,
            (int) $setting->shop_id,
            $path
        );

        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.logistics.fetch',
            'method' => 'GET',
            'api_path' => $path,
            'auth_required' => true,
            'request_params' => [],
            'response_status' => $result['status'] ?? null,
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null,
            'user_id' => auth()->id(),
        ]);

        if (!($result['ok'] ?? false)) {
            $body = $result['body'] ?? null;
            $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'API call failed')) : 'API call failed';
            return redirect()->route('ext.shopee.logistics.index')
                ->with('error', 'Failed to fetch logistics: ' . $msg);
        }

        $body = $result['body'] ?? [];
        $list = ($body['response'] ?? $body)['logistics_channel_list'] ?? [];

        if (empty($list)) {
            return redirect()->route('ext.shopee.logistics.index')
                ->with('error', 'API returned no logistics channels.');
        }

        $saved = 0;
        foreach ($list as $ch) {
            $channelId = (int) ($ch['logistics_channel_id'] ?? 0);
            if (!$channelId) continue;

            ShopeeLogistic::updateOrCreate(
                ['logistics_channel_id' => $channelId],
                [
                    'logistics_channel_name' => (string) ($ch['logistics_channel_name'] ?? ''),
                    'cod_enabled' => (bool) ($ch['cod_enabled'] ?? false),
                    'enabled' => (bool) ($ch['enabled'] ?? false),
                    'force_enable' => (bool) ($ch['force_enable'] ?? false),
                    'fee_type' => (string) ($ch['fee_type'] ?? ''),
                    'weight_limit' => $ch['weight_limit'] ?? null,
                    'item_max_dimension' => $ch['item_max_dimension'] ?? null,
                    'volume_limit' => $ch['volume_limit'] ?? null,
                    'mask_channel_id' => (int) ($ch['mask_channel_id'] ?? 0),
                    'logistics_description' => (string) ($ch['logistics_description'] ?? ''),
                    'support_pre_order' => (bool) ($ch['support_pre_order'] ?? false),
                    'support_cross_border' => (bool) ($ch['support_cross_border'] ?? false),
                    'raw_data' => $ch,
                ]
            );
            $saved++;
        }

        return redirect()->route('ext.shopee.logistics.index')
            ->with('status', "Fetched {$saved} logistics channel(s) from Shopee.");
    }

}
