<?php

namespace Extensions\ventacart\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\ventacart\Models\VentaCartApiLog;
use Extensions\ventacart\Models\VentaCartBrand;
use Extensions\ventacart\Models\VentaCartCategory;
use Extensions\ventacart\Models\VentaCartOrderStatusMap;
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Models\VentaCartSyncLog;
use Extensions\ventacart\Services\VentaCart\VentaCartClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VentaCartController extends Controller
{
    public function index()
    {
        $first = VentaCartSetting::query()->orderBy('id')->first();
        if ($first) {
            return redirect()->route('ext.ventacart.settings.show', ['store' => $first->id]);
        }
        return redirect()->route('channels.module', ['module' => 'ventacart']);
    }

    public function showSettings(int $store)
    {
        $active = VentaCartSetting::findOrFail($store);

        $stores = collect([$active]);

        $syncLogs = VentaCartSyncLog::query()
            ->where('ventacart_setting_id', $active->id)
            ->orderByDesc('created_at')
            ->paginate(15, ['*'], 'syncPage')
            ->withQueryString();

        $syncLogCount = VentaCartSyncLog::where('ventacart_setting_id', $active->id)->count();

        $apiLogs = \App\Support\ApiLogPanel::paginate(
            VentaCartApiLog::query()->where('ventacart_setting_id', $active->id),
            'endpoint',
            fn ($q) => $q->where('ok', 0)
        );

        $orderStatusMaps = VentaCartOrderStatusMap::query()
            ->where('ventacart_setting_id', $active->id)
            ->orderBy('ventacart_status_id')
            ->get()
            ->groupBy('ventacart_setting_id');

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $erpOrderStatuses = DB::table($pfx . 'order_status')
            ->where('language_id', $langId)
            ->orderBy('order_status_id')
            ->get(['order_status_id', 'name']);

        $singleStore = true;

        return view('ext-ventacart::settings.index', compact('stores', 'syncLogs', 'syncLogCount', 'apiLogs', 'orderStatusMaps', 'erpOrderStatuses', 'singleStore'));
    }

    public function createStore(Request $request)
    {
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:128'],
            'base_url'   => ['required', 'url', 'max:255'],
        ]);

        $setting = VentaCartSetting::create([
            'store_name'     => $data['store_name'],
            'base_url'       => rtrim($data['base_url'], '/'),
            'api_token'      => '',
            'enabled'        => false,
            'sync_last_days' => 30,
        ]);

        ActivityLogger::log('created', 'VentaCart', $setting->id, $setting->store_name);

        return redirect()
            ->route('ext.ventacart.settings.show', ['store' => $setting->id])
            ->with('status', 'Store created. Add the API token to start syncing.');
    }

    public function save(Request $request)
    {
        $storeId = $request->input('store_id');
        $existing = $storeId ? VentaCartSetting::findOrFail((int) $storeId) : null;
        $hasStoredToken = $existing !== null && $existing->api_token !== null && $existing->api_token !== '';

        $data = $request->validate([
            'store_id'       => ['nullable', 'integer'],
            'store_name'     => ['nullable', 'string', 'max:128'],
            'brand_color'    => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'base_url'       => ['required', 'string', 'max:255'],
            'api_token'      => [$hasStoredToken ? 'nullable' : 'required', 'string', 'max:255'],
            'enabled'        => ['nullable'],
            'warehouse_id'   => ['nullable', 'integer'],
            'sync_last_days'   => ['nullable', 'integer', 'min:1', 'max:365'],
            'sync_orders_from' => ['nullable', 'date'],
        ]);

        $data['enabled'] = $request->has('enabled');

        if ($existing) {
            $setting = $existing;
            $setting->fill([
                'store_name'       => $data['store_name'] ?? '',
                'brand_color'      => $data['brand_color'] ?? $setting->brand_color,
                'base_url'         => $data['base_url'],
                'enabled'          => $data['enabled'],
                'warehouse_id'     => $data['warehouse_id'] ?? $setting->warehouse_id,
                'sync_last_days'   => $data['sync_last_days'] ?? $setting->sync_last_days,
                'sync_orders_from' => $data['sync_orders_from'] ?? $setting->sync_orders_from,
            ]);
            if (!empty($data['api_token'])) {
                $setting->api_token = $data['api_token'];
            }
            $setting->save();
            ActivityLogger::log('updated', 'VentaCart', $setting->id, $setting->store_name ?? $setting->base_url);
        } else {
            $setting = VentaCartSetting::create([
                'store_name'       => $data['store_name'] ?? '',
                'brand_color'      => $data['brand_color'] ?? null,
                'base_url'         => $data['base_url'],
                'api_token'        => $data['api_token'],
                'enabled'          => $data['enabled'],
                'warehouse_id'     => $data['warehouse_id'] ?? null,
                'sync_last_days'   => $data['sync_last_days'] ?? 30,
                'sync_orders_from' => $data['sync_orders_from'] ?? null,
            ]);
            ActivityLogger::log('created', 'VentaCart', $setting->id, $setting->store_name ?? $setting->base_url);
        }

        $setUp = trim((string) $setting->api_token) !== ''
            && ! VentaCartCategory::query()->where('ventacart_setting_id', $setting->id)->exists();

        return redirect()
            ->route('ext.ventacart.settings.show', ['store' => $setting->id])
            ->with('status', 'VentaCart store saved.')
            ->with('settings_setup', $setUp);
    }

    public function destroy($id, \Extensions\ventacart\Services\VentaCart\VentaCartStoreDeleter $deleter)
    {
        $setting = VentaCartSetting::findOrFail((int) $id);
        $name = $setting->store_name ?? $setting->base_url;
        $removed = $deleter->delete($setting);

        ActivityLogger::log('deleted', 'VentaCart', (int) $id, $name, ['removed' => $removed]);

        return redirect()->route('ext.ventacart.index')->with('status', 'Store deleted.');
    }

    public function destroyStore(Request $request, int $store, \Extensions\ventacart\Services\VentaCart\VentaCartStoreDeleter $deleter)
    {
        $setting = VentaCartSetting::findOrFail($store);
        $request->validate([
            'confirm_name' => ['required', 'string', function ($attribute, $value, $fail) use ($setting) {
                if ((string) $value !== (string) $setting->store_name) {
                    $fail('Type the store name exactly as it is shown to confirm.');
                }
            }],
        ]);
        $name = (string) $setting->store_name;
        $id = (int) $setting->id;
        $removed = $deleter->delete($setting);
        ActivityLogger::log('ventacart.store.deleted', 'ventacart_settings', $id, $name, ['removed' => $removed]);

        return redirect()->route('channels.index')
            ->with('status', "Deleted VentaCart store {$name}. Sales already imported keep its name.");
    }

    public function testConnection(Request $request)
    {
        $storeId = $request->input('store_id');
        $baseUrl = $request->input('base_url');
        $apiToken = $request->input('api_token');

        if (!$storeId && $baseUrl && $apiToken) {
            $setting = new VentaCartSetting([
                'base_url'  => $baseUrl,
                'enabled'   => true,
            ]);
            $setting->setRawApiToken($apiToken);
        } else {
            $setting = $storeId
                ? VentaCartSetting::find((int) $storeId)
                : VentaCartSetting::query()->first();
        }

        if (!$setting) {
            return response()->json(['ok' => false, 'error' => 'Not configured']);
        }

        $client = new VentaCartClient($setting);
        $result = $client->ping();
        if (! empty($result['ok']) && $setting->exists) {
            $setting->forceFill(['connected_at' => now()])->save();
        }

        return response()->json($result);
    }

    public function setupStep(int $store, string $step, \Extensions\ventacart\Services\VentaCart\VentaCartStoreSetup $setup)
    {
        $setting = VentaCartSetting::findOrFail($store);
        if (trim((string) $setting->api_token) === '' || trim((string) $setting->base_url) === '') {
            return response()->json(['ok' => false, 'count' => 0, 'message' => 'This store is not connected yet. Save its address and API token first.'], 422);
        }
        $out = match ($step) {
            'categories' => $setup->categories($setting),
            'brands' => $setup->brands($setting),
            'statuses' => $setup->statuses($setting),
            default => null,
        };
        abort_if($out === null, 404);

        return response()->json($out);
    }

    public function fetchCategories(Request $request, \Extensions\ventacart\Services\VentaCart\VentaCartStoreSetup $setup)
    {
        $storeId = $request->input('store_id');
        if (!$storeId) {
            return response()->json(['ok' => false, 'error' => 'Store ID is required.'], 422);
        }
        $setting = VentaCartSetting::find((int) $storeId);
        if (!$setting) {
            return response()->json(['ok' => false, 'error' => 'Store not found.'], 404);
        }
        $out = $setup->categories($setting);
        if (! $out['ok']) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'error' => $out['message']])
                : back()->with('error', $out['message']);
        }
        if (! $request->expectsJson()) {
            return back()->with('status', $out['count'] . ' ' . \Illuminate\Support\Str::plural('category', $out['count']) . ' read from ' . ($setting->store_name ?: 'the store') . '.');
        }

        return response()->json(['ok' => true, 'count' => $out['count']]);
    }

    public function fetchBrands(Request $request, \Extensions\ventacart\Services\VentaCart\VentaCartStoreSetup $setup)
    {
        $storeId = $request->input('store_id');
        if (!$storeId) {
            return response()->json(['ok' => false, 'error' => 'Store ID is required.'], 422);
        }
        $setting = VentaCartSetting::find((int) $storeId);
        if (!$setting) {
            return response()->json(['ok' => false, 'error' => 'Store not found.'], 404);
        }
        $out = $setup->brands($setting);

        return $out['ok']
            ? response()->json(['ok' => true, 'count' => $out['count']])
            : response()->json(['ok' => false, 'error' => $out['message']]);
    }

    public function fetchVentaStatuses(Request $request)
    {
        $storeId = $request->input('store_id');
        if (!$storeId) {
            return response()->json(['error' => 'Store ID is required.'], 422);
        }

        $setting = VentaCartSetting::find((int) $storeId);
        if (!$setting) {
            return response()->json(['error' => 'Store not found.'], 404);
        }

        $out = app(\Extensions\ventacart\Services\VentaCart\VentaCartStoreSetup::class)->statuses($setting);
        if (! $out['ok']) {
            return response()->json(['error' => $out['message']], str_contains($out['message'], 'no order statuses') ? 404 : 500);
        }

        $persisted = VentaCartOrderStatusMap::where('ventacart_setting_id', $setting->id)
            ->orderBy('ventacart_status_id')
            ->get(['ventacart_status_id', 'ventacart_status_name', 'order_status_id'])
            ->map(fn ($r) => [
                'id'              => $r->ventacart_status_id,
                'name'            => $r->ventacart_status_name,
                'order_status_id' => (int) $r->order_status_id,
            ])
            ->all();

        return response()->json(['statuses' => $persisted]);
    }

    public function saveOrderStatusMap(Request $request)
    {
        $storeId = $request->input('store_id');
        if (!$storeId) {
            return response()->json(['error' => 'Store ID is required.'], 422);
        }

        $setting = VentaCartSetting::find((int) $storeId);
        if (!$setting) {
            return response()->json(['error' => 'Store not found.'], 404);
        }

        $mappings = $request->input('mappings', []);

        foreach ($mappings as $m) {
            $ventaCartStatusId = (int) ($m['ventacart_status_id'] ?? 0);
            if ($ventaCartStatusId <= 0) continue;

            VentaCartOrderStatusMap::updateOrCreate(
                [
                    'ventacart_setting_id' => $setting->id,
                    'ventacart_status_id'  => $ventaCartStatusId,
                ],
                [
                    'ventacart_status_name' => $m['ventacart_status_name'] ?? '',
                    'order_status_id'   => (int) ($m['order_status_id'] ?? 0),
                ]
            );
        }

        return response()->json(['status' => 'Order status mapping saved.']);
    }

    public function setApiLogMode(Request $request, $id)
    {
        $data = $request->validate(['mode' => 'required|in:' . implode(',', \App\Support\ApiLogMode::MODES)]);
        $store = VentaCartSetting::findOrFail($id);
        $store->update(['api_log_mode' => $data['mode']]);

        return redirect()->route('ext.ventacart.settings.show', ['store' => $store->id, 'tab' => 'logs'])
            ->with('status', ($store->store_name ?: 'VentaCart') . ' API log now records: ' . \App\Support\ApiLogMode::labels()[$data['mode']] . '.');
    }

    public function setSyncLogLevel(Request $request, $id)
    {
        $store = VentaCartSetting::findOrFail($id);

        $data = $request->validate([
            'sync_log_level' => ['nullable', 'string', 'in:' . implode(',', array_keys(\App\Support\LogRetention::LEVELS))],
        ]);

        $level = $data['sync_log_level'] ?? null;
        $store->update(['sync_log_level' => $level ?: null]);

        $said = $level ? \App\Support\LogRetention::LEVELS[$level] : 'the default in Settings';

        return redirect()->route('ext.ventacart.settings.show', [$store->id, 'tab' => 'sync'])
            ->with('status', 'Sync log for ' . $store->store_name . ' now records: ' . lcfirst($said) . '.');
    }

    public function clearSyncLogs($id)
    {
        $store = VentaCartSetting::findOrFail($id);
        $count = VentaCartSyncLog::where('ventacart_setting_id', $store->id)->count();
        VentaCartSyncLog::where('ventacart_setting_id', $store->id)->delete();

        \App\Services\ActivityLogger::log('purged', 'VentaCartSetting', $store->id, 'Sync log (' . number_format($count) . ' rows)');

        return redirect()->route('ext.ventacart.settings.show', [$store->id, 'tab' => 'sync'])
            ->with('status', number_format($count) . ' sync log ' . \Illuminate\Support\Str::plural('row', $count) . ' deleted for ' . $store->store_name . '.');
    }

    public function clearApiLogs($id)
    {
        $store = VentaCartSetting::findOrFail($id);
        $count = VentaCartApiLog::where('ventacart_setting_id', $store->id)->count();
        VentaCartApiLog::where('ventacart_setting_id', $store->id)->delete();

        return redirect()->route('ext.ventacart.settings.show', ['store' => $store->id, 'tab' => 'logs'])
            ->with('status', $count . ' API log(s) deleted for ' . $store->store_name . '.');
    }
}
