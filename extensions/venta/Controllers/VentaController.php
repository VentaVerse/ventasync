<?php

namespace Extensions\venta\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\venta\Models\VentaApiLog;
use Extensions\venta\Models\VentaOrderStatusMap;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Models\VentaSyncLog;
use Extensions\venta\Services\Venta\VentaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VentaController extends Controller
{
    public function index()
    {
        $stores = VentaSetting::query()->orderBy('id')->get();

        $recentLogs = VentaSyncLog::query()
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $apiLogs = VentaApiLog::query()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $orderStatusMaps = VentaOrderStatusMap::query()
            ->orderBy('venta_status_id')
            ->get()
            ->groupBy('venta_setting_id');

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $erpOrderStatuses = DB::table($pfx . 'order_status')
            ->where('language_id', $langId)
            ->orderBy('order_status_id')
            ->get(['order_status_id', 'name']);

        return view('ext-venta::settings.index', compact('stores', 'recentLogs', 'apiLogs', 'orderStatusMaps', 'erpOrderStatuses'));
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'store_id'       => ['nullable', 'integer'],
            'store_name'     => ['nullable', 'string', 'max:128'],
            'base_url'       => ['required', 'string', 'max:255'],
            'api_token'      => ['required', 'string', 'max:255'],
            'enabled'        => ['nullable'],
            'warehouse_id'   => ['nullable', 'integer'],
            'sync_last_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $data['enabled'] = $request->has('enabled');

        $storeId = $request->input('store_id');

        if ($storeId) {
            $setting = VentaSetting::findOrFail((int) $storeId);
            $setting->update([
                'store_name'     => $data['store_name'] ?? '',
                'base_url'       => $data['base_url'],
                'api_token'      => $data['api_token'],
                'enabled'        => $data['enabled'],
                'warehouse_id'   => $data['warehouse_id'] ?? $setting->warehouse_id,
                'sync_last_days' => $data['sync_last_days'] ?? $setting->sync_last_days,
            ]);
            ActivityLogger::log('updated', 'Venta Store', $setting->id, $setting->store_name ?? $setting->base_url);
        } else {
            $setting = VentaSetting::create([
                'store_name'     => $data['store_name'] ?? '',
                'base_url'       => $data['base_url'],
                'api_token'      => $data['api_token'],
                'enabled'        => $data['enabled'],
                'warehouse_id'   => $data['warehouse_id'] ?? null,
                'sync_last_days' => $data['sync_last_days'] ?? 30,
            ]);
            ActivityLogger::log('created', 'Venta Store', $setting->id, $setting->store_name ?? $setting->base_url);
        }

        return redirect()->route('ext.venta.index')->with('status', 'Venta store saved.');
    }

    public function destroy($id)
    {
        $setting = VentaSetting::findOrFail((int) $id);
        $name = $setting->store_name ?? $setting->base_url;
        $setting->delete();

        ActivityLogger::log('deleted', 'Venta Store', (int) $id, $name);

        return redirect()->route('ext.venta.index')->with('status', 'Store deleted.');
    }

    public function testConnection(Request $request)
    {
        $storeId = $request->input('store_id');
        $baseUrl = $request->input('base_url');
        $apiToken = $request->input('api_token');

        if (!$storeId && $baseUrl && $apiToken) {
            $setting = new VentaSetting([
                'base_url'  => $baseUrl,
                'enabled'   => true,
            ]);
            $setting->setRawApiToken($apiToken);
        } else {
            $setting = $storeId
                ? VentaSetting::find((int) $storeId)
                : VentaSetting::query()->first();
        }

        if (!$setting) {
            return response()->json(['ok' => false, 'error' => 'Not configured']);
        }

        $client = new VentaClient($setting);
        $result = $client->ping();

        return response()->json($result);
    }

    public function fetchCategories(Request $request)
    {
        $storeId = $request->input('store_id');
        if (!$storeId) {
            return response()->json(['ok' => false, 'error' => 'Store ID is required.'], 422);
        }

        $setting = VentaSetting::find((int) $storeId);
        if (!$setting) {
            return response()->json(['ok' => false, 'error' => 'Store not found.'], 404);
        }

        $client = new VentaClient($setting);
        $result = $client->getCategories();

        if (!$result['ok']) {
            return response()->json(['ok' => false, 'error' => 'API call failed: ' . ($result['body']['error'] ?? 'Unknown error')]);
        }

        $categories = $result['body'];
        if (!is_array($categories)) {
            $categories = [];
        }

        return response()->json([
            'ok'         => true,
            'categories' => $categories,
            'count'      => count($categories),
        ]);
    }

    public function fetchVentaStatuses(Request $request)
    {
        $storeId = $request->input('store_id');
        if (!$storeId) {
            return response()->json(['error' => 'Store ID is required.'], 422);
        }

        $setting = VentaSetting::find((int) $storeId);
        if (!$setting) {
            return response()->json(['error' => 'Store not found.'], 404);
        }

        $client = new VentaClient($setting);
        $result = $client->get('order-statuses');

        if (!$result['ok']) {
            return response()->json(['error' => 'API call failed: ' . ($result['body']['error'] ?? 'Unknown error')], 500);
        }

        // Venta returns a flat array or wrapped in data key
        $statuses = $result['body']['data'] ?? $result['body'] ?? [];

        if (!is_array($statuses) || empty($statuses)) {
            return response()->json(['error' => 'No statuses returned from Venta.'], 404);
        }

        return response()->json(['statuses' => $statuses]);
    }

    public function saveOrderStatusMap(Request $request)
    {
        $storeId = $request->input('store_id');
        if (!$storeId) {
            return response()->json(['error' => 'Store ID is required.'], 422);
        }

        $setting = VentaSetting::find((int) $storeId);
        if (!$setting) {
            return response()->json(['error' => 'Store not found.'], 404);
        }

        $mappings = $request->input('mappings', []);

        foreach ($mappings as $m) {
            $ventaStatusId = (int) ($m['venta_status_id'] ?? 0);
            if ($ventaStatusId <= 0) continue;

            VentaOrderStatusMap::updateOrCreate(
                [
                    'venta_setting_id' => $setting->id,
                    'venta_status_id'  => $ventaStatusId,
                ],
                [
                    'venta_status_name' => $m['venta_status_name'] ?? '',
                    'order_status_id'   => (int) ($m['order_status_id'] ?? 0),
                ]
            );
        }

        return response()->json(['status' => 'Order status mapping saved.']);
    }
}
