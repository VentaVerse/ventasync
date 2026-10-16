<?php

namespace Extensions\VentaCart\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\VentaCart\Models\VentaCartApiLog;
use Extensions\VentaCart\Models\VentaCartBrand;
use Extensions\VentaCart\Models\VentaCartCategory;
use Extensions\VentaCart\Models\VentaCartOrderStatusMap;
use Extensions\VentaCart\Models\VentaCartStore;
use Extensions\VentaCart\Models\VentaCartSyncLog;
use Extensions\VentaCart\Services\VentaCart\VentaCartClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VentaCartController extends Controller
{
    public function index()
    {
        $first = VentaCartStore::query()->orderBy('id')->first();
        if ($first) {
            return redirect()->route('ext.ventacart.settings.show', ['store' => $first->id]);
        }
        return redirect()->route('channels.module', ['module' => 'ventacart']);
    }

    public function showSettings(int $store)
    {
        $active = VentaCartStore::findOrFail($store);

        $stores = collect([$active]);

        $syncLogs = VentaCartSyncLog::query()
            ->where('store_id', $active->id)
            ->orderByDesc('created_at')
            ->paginate(15, ['*'], 'syncPage')
            ->withQueryString();

        $syncLogCount = VentaCartSyncLog::where('store_id', $active->id)->count();

        $apiLogs = \App\Support\ApiLogPanel::paginate(
            VentaCartApiLog::query()->where('store_id', $active->id),
            'endpoint',
            fn ($q) => $q->where('ok', 0)
        );

        $orderStatusMaps = VentaCartOrderStatusMap::query()
            ->where('store_id', $active->id)
            ->orderBy('ventacart_status_id')
            ->get()
            ->groupBy('store_id');

        $langId = (int) config('catalog.default_language_id');
        $salesOrderStatuses = DB::table('order_statuses')
            ->where('language_id', $langId)
            ->orderBy('order_status_id')
            ->get(['order_status_id', 'name']);

        $singleStore = true;

        return view('ext-ventacart::settings.index', compact('stores', 'syncLogs', 'syncLogCount', 'apiLogs', 'orderStatusMaps', 'salesOrderStatuses', 'singleStore'));
    }

    public function createStore(Request $request)
    {
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:128'],
            'base_url'   => ['required', 'url', 'max:255', new \App\Rules\StoreAddress],
        ]);

        $store = VentaCartStore::create([
            'store_name'     => $data['store_name'],
            'base_url'       => rtrim($data['base_url'], '/'),
            'api_token'      => '',
            'enabled'        => false,
            'sync_last_days' => 30,
        ]);

        ActivityLogger::log('created', 'VentaCart', $store->id, $store->store_name);

        return redirect()
            ->route('ext.ventacart.settings.show', ['store' => $store->id])
            ->with('status', 'Store created. Add the API token to start syncing.');
    }

    public function save(Request $request)
    {
        $storeId = $request->input('store_id');
        $existing = $storeId ? VentaCartStore::findOrFail((int) $storeId) : null;
        $hasStoredToken = $existing !== null && $existing->api_token !== null && $existing->api_token !== '';

        $data = $request->validate([
            'store_id'       => ['nullable', 'integer'],
            'store_name'     => ['nullable', 'string', 'max:128'],
            'brand_color'    => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'base_url'       => ['required', 'string', 'max:255', new \App\Rules\StoreAddress],
            'api_token'      => [$hasStoredToken ? 'nullable' : 'required', 'string', 'max:255'],
            'enabled'        => ['nullable'],
            'warehouse_id'   => ['nullable', 'integer'],
            'sync_last_days'   => ['nullable', 'integer', 'min:1', 'max:365'],
            'sync_orders_from' => ['nullable', 'date'],
        ]);

        $data['enabled'] = $request->has('enabled');

        if ($existing) {
            $store = $existing;
            $store->fill([
                'store_name'       => $data['store_name'] ?? '',
                'brand_color'      => $data['brand_color'] ?? $store->brand_color,
                'base_url'         => $data['base_url'],
                'enabled'          => $data['enabled'],
                'warehouse_id'     => $data['warehouse_id'] ?? $store->warehouse_id,
                'sync_last_days'   => $data['sync_last_days'] ?? $store->sync_last_days,
                'sync_orders_from' => $data['sync_orders_from'] ?? $store->sync_orders_from,
            ]);
            if (!empty($data['api_token'])) {
                $store->api_token = $data['api_token'];
            }
            $store->save();
            ActivityLogger::log('updated', 'VentaCart', $store->id, $store->store_name ?? $store->base_url);
        } else {
            $store = VentaCartStore::create([
                'store_name'       => $data['store_name'] ?? '',
                'brand_color'      => $data['brand_color'] ?? null,
                'base_url'         => $data['base_url'],
                'api_token'        => $data['api_token'],
                'enabled'          => $data['enabled'],
                'warehouse_id'     => $data['warehouse_id'] ?? null,
                'sync_last_days'   => $data['sync_last_days'] ?? 30,
                'sync_orders_from' => $data['sync_orders_from'] ?? null,
            ]);
            ActivityLogger::log('created', 'VentaCart', $store->id, $store->store_name ?? $store->base_url);
        }

        $setUp = trim((string) $store->api_token) !== ''
            && ! VentaCartCategory::query()->where('store_id', $store->id)->exists();

        return redirect()
            ->route('ext.ventacart.settings.show', ['store' => $store->id])
            ->with('status', 'VentaCart store saved.')
            ->with('settings_setup', $setUp);
    }

    public function destroy($id, \Extensions\VentaCart\Services\VentaCart\VentaCartStoreDeleter $deleter)
    {
        $store = VentaCartStore::findOrFail((int) $id);
        $name = $store->store_name ?? $store->base_url;
        $removed = $deleter->delete($store);

        ActivityLogger::log('deleted', 'VentaCart', (int) $id, $name, ['removed' => $removed]);

        return redirect()->route('ext.ventacart.index')->with('status', 'Store deleted.');
    }

    public function destroyStore(Request $request, int $store, \Extensions\VentaCart\Services\VentaCart\VentaCartStoreDeleter $deleter)
    {
        $setting = VentaCartStore::findOrFail($store);
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
        ActivityLogger::log('ventacart.store.deleted', 'VentaCart Store', $id, $name, ['removed' => $removed]);

        return redirect()->route('channels.index')
            ->with('status', "Deleted VentaCart store {$name}. Sales already imported keep its name.");
    }

    public function testConnection(Request $request)
    {
        $storeId = $request->input('store_id');
        $baseUrl = $request->input('base_url');
        $apiToken = $request->input('api_token');

        if (!$storeId && $baseUrl && $apiToken) {
            $store = new VentaCartStore([
                'base_url'  => $baseUrl,
                'enabled'   => true,
            ]);
            $store->setRawApiToken($apiToken);
        } else {
            $store = $storeId
                ? VentaCartStore::find((int) $storeId)
                : VentaCartStore::query()->first();
        }

        if (!$store) {
            return response()->json(['ok' => false, 'error' => 'Not configured']);
        }

        $client = new VentaCartClient($store);
        $result = $client->ping();
        if (! empty($result['ok']) && $store->exists) {
            $store->forceFill(['connected_at' => now()])->save();
        }

        return response()->json($result);
    }

    public function setupStep(int $store, string $step, \Extensions\VentaCart\Services\VentaCart\VentaCartStoreSetup $setup)
    {
        $setting = VentaCartStore::findOrFail($store);
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

    public function fetchCategories(Request $request, \Extensions\VentaCart\Services\VentaCart\VentaCartStoreSetup $setup)
    {
        $storeId = $request->input('store_id');
        if (!$storeId) {
            return response()->json(['ok' => false, 'error' => 'Store ID is required.'], 422);
        }
        $store = VentaCartStore::find((int) $storeId);
        if (!$store) {
            return response()->json(['ok' => false, 'error' => 'Store not found.'], 404);
        }
        $out = $setup->categories($store);
        if (! $out['ok']) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'error' => $out['message']])
                : back()->with('error', $out['message']);
        }
        if (! $request->expectsJson()) {
            return back()->with('status', $out['count'] . ' ' . \Illuminate\Support\Str::plural('category', $out['count']) . ' read from ' . ($store->store_name ?: 'the store') . '.');
        }

        return response()->json(['ok' => true, 'count' => $out['count']]);
    }

    public function fetchBrands(Request $request, \Extensions\VentaCart\Services\VentaCart\VentaCartStoreSetup $setup)
    {
        $storeId = $request->input('store_id');
        if (!$storeId) {
            return response()->json(['ok' => false, 'error' => 'Store ID is required.'], 422);
        }
        $store = VentaCartStore::find((int) $storeId);
        if (!$store) {
            return response()->json(['ok' => false, 'error' => 'Store not found.'], 404);
        }
        $out = $setup->brands($store);

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

        $store = VentaCartStore::find((int) $storeId);
        if (!$store) {
            return response()->json(['error' => 'Store not found.'], 404);
        }

        $out = app(\Extensions\VentaCart\Services\VentaCart\VentaCartStoreSetup::class)->statuses($store);
        if (! $out['ok']) {
            return response()->json(['error' => $out['message']], str_contains($out['message'], 'no order statuses') ? 404 : 500);
        }

        $persisted = VentaCartOrderStatusMap::where('store_id', $store->id)
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

        $store = VentaCartStore::find((int) $storeId);
        if (!$store) {
            return response()->json(['error' => 'Store not found.'], 404);
        }

        $mappings = $request->input('mappings', []);

        foreach ($mappings as $m) {
            $ventaCartStatusId = (int) ($m['ventacart_status_id'] ?? 0);
            if ($ventaCartStatusId <= 0) continue;

            VentaCartOrderStatusMap::updateOrCreate(
                [
                    'store_id' => $store->id,
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
        $store = VentaCartStore::findOrFail($id);
        $store->update(['api_log_mode' => $data['mode']]);

        return redirect()->route('ext.ventacart.settings.show', ['store' => $store->id, 'tab' => 'logs'])
            ->with('status', ($store->store_name ?: 'VentaCart') . ' API log now records: ' . \App\Support\ApiLogMode::labels()[$data['mode']] . '.');
    }

    public function setSyncLogLevel(Request $request, $id)
    {
        $store = VentaCartStore::findOrFail($id);

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
        $store = VentaCartStore::findOrFail($id);
        $count = VentaCartSyncLog::where('store_id', $store->id)->count();
        VentaCartSyncLog::where('store_id', $store->id)->delete();

        \App\Services\ActivityLogger::log('purged', 'VentaCartStore', $store->id, 'Sync log (' . number_format($count) . ' rows)');

        return redirect()->route('ext.ventacart.settings.show', [$store->id, 'tab' => 'sync'])
            ->with('status', number_format($count) . ' sync log ' . \Illuminate\Support\Str::plural('row', $count) . ' deleted for ' . $store->store_name . '.');
    }

    public function clearApiLogs($id)
    {
        $store = VentaCartStore::findOrFail($id);
        $count = VentaCartApiLog::where('store_id', $store->id)->count();
        VentaCartApiLog::where('store_id', $store->id)->delete();

        return redirect()->route('ext.ventacart.settings.show', ['store' => $store->id, 'tab' => 'logs'])
            ->with('status', $count . ' API log(s) deleted for ' . $store->store_name . '.');
    }
}
