<?php

namespace Extensions\opencart\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Catalog\OrderStatus;
use App\Services\ActivityLogger;
use Extensions\opencart\Models\OpenCartOrderStatusMap;
use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Models\OpenCartSyncLog;
use Extensions\opencart\Services\OpenCart\OpenCartClient;
use Extensions\opencart\Services\OpenCart\OpenCartOrderSync;
use Extensions\opencart\Services\OpenCart\OpenCartProductSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OpenCartController extends Controller
{
    public function index()
    {
        $first = OpenCartSetting::query()->orderBy('id')->first();
        if ($first) {
            return redirect()->route('ext.opencart.settings.show', ['store' => $first->id]);
        }
        return redirect()->route('channels.module', ['module' => 'opencart']);
    }

    public function showSettings(int $store)
    {
        $active = OpenCartSetting::findOrFail($store);
        $stores = collect([$active]);

        $recentLogs = OpenCartSyncLog::query()
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $orderStatusMaps = OpenCartOrderStatusMap::query()
            ->where('opencart_setting_id', $active->id)
            ->orderBy('oc_status_id')
            ->get()
            ->groupBy('opencart_setting_id');

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $erpOrderStatuses = DB::table($pfx . 'order_status')
            ->where('language_id', $langId)
            ->orderBy('order_status_id')
            ->get(['order_status_id', 'name']);

        $singleStore = true;

        return view('ext-opencart::index', compact('stores', 'recentLogs', 'orderStatusMaps', 'erpOrderStatuses', 'singleStore'));
    }

    public function createStore(Request $request)
    {
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:128'],
            'base_url'   => ['required', 'url', 'max:255'],
        ]);

        $setting = OpenCartSetting::create([
            'store_name' => $data['store_name'],
            'base_url'   => rtrim($data['base_url'], '/'),
            'api_key'    => '',
            'enabled'    => false,
        ]);

        ActivityLogger::log('created', 'OpenCart Store', $setting->id, $setting->store_name);

        return redirect()
            ->route('ext.opencart.settings.show', ['store' => $setting->id])
            ->with('status', 'Store created. Add the API key to start syncing.');
    }

    public function save(Request $request)
    {
        $storeId = $request->input('store_id');
        $existing = $storeId ? OpenCartSetting::findOrFail((int) $storeId) : null;
        $hasStoredKey = $existing !== null && $existing->api_key !== null && $existing->api_key !== '';

        $data = $request->validate([
            'store_id'         => ['nullable', 'integer'],
            'store_name'       => ['nullable', 'string', 'max:128'],
            'brand_color'      => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'base_url'         => ['required', 'string', 'max:255'],
            'api_key'          => [$hasStoredKey ? 'nullable' : 'required', 'string', 'max:128'],
            'enabled'          => ['nullable'],
            'sync_orders_from' => ['nullable', 'date'],
        ]);

        $data['enabled'] = $request->has('enabled') ? true : false;

        if ($existing) {
            $setting = $existing;
            $setting->fill([
                'store_name'       => $data['store_name'] ?? '',
                'brand_color'      => $data['brand_color'] ?? $setting->brand_color,
                'base_url'         => $data['base_url'],
                'enabled'          => $data['enabled'],
                'sync_orders_from' => $data['sync_orders_from'] ?? $setting->sync_orders_from,
            ]);
            if (!empty($data['api_key'])) {
                $setting->api_key = $data['api_key'];
            }
            $setting->save();
            ActivityLogger::log('updated', 'OpenCart Store', $setting->id, $setting->store_name ?? $setting->base_url);
        } else {
            $setting = OpenCartSetting::create([
                'store_name'       => $data['store_name'] ?? '',
                'brand_color'      => $data['brand_color'] ?? null,
                'base_url'         => $data['base_url'],
                'api_key'          => $data['api_key'],
                'enabled'          => $data['enabled'],
                'sync_orders_from' => $data['sync_orders_from'] ?? null,
            ]);
            ActivityLogger::log('created', 'OpenCart Store', $setting->id, $setting->store_name ?? $setting->base_url);
        }

        return redirect()
            ->route('ext.opencart.settings.show', ['store' => $setting->id])
            ->with('status', 'OpenCart store saved.');
    }

    public function saveReviewSettings(Request $request)
    {
        $request->validate([
            'store_id' => ['required', 'integer'],
        ]);

        $setting = OpenCartSetting::findOrFail((int) $request->input('store_id'));

        $setting->update([
            'review_auto_approve' => $request->has('review_auto_approve'),
        ]);

        return redirect()
            ->route('ext.opencart.settings.show', ['store' => $setting->id])
            ->with('status', 'Review settings saved.');
    }

    public function saveSyncDate(Request $request)
    {
        $request->validate([
            'store_id'         => ['required', 'integer'],
            'sync_orders_from' => ['nullable', 'date'],
            'sync_last_days'   => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $setting = OpenCartSetting::findOrFail((int) $request->input('store_id'));

        $setting->update([
            'sync_orders_from' => $request->input('sync_orders_from') ?: $setting->sync_orders_from,
            'sync_last_days'   => $request->input('sync_last_days') ?: null,
        ]);

        $parts = [];
        if ($request->filled('sync_orders_from')) {
            $parts[] = "fixed date set to {$request->input('sync_orders_from')}";
        }
        if ($request->filled('sync_last_days')) {
            $parts[] = "rolling window set to last {$request->input('sync_last_days')} days";
        }

        $msg = $parts ? 'Order sync updated: ' . implode(', ', $parts) . '.' : 'Order sync settings saved.';

        return redirect()->route('ext.opencart.settings.show', ['store' => $setting->id])->with('status', $msg);
    }

    public function destroy($id, \Extensions\opencart\Services\OpenCart\OpenCartStoreDeleter $deleter)
    {
        $setting = OpenCartSetting::findOrFail((int) $id);
        $name = $setting->store_name ?? $setting->base_url;
        $removed = $deleter->delete($setting);

        ActivityLogger::log('deleted', 'OpenCart Store', (int) $id, $name, ['removed' => $removed]);

        return redirect()->route('ext.opencart.index')->with('status', 'Store deleted.');
    }

    public function destroyStore(Request $request, int $store, \Extensions\opencart\Services\OpenCart\OpenCartStoreDeleter $deleter)
    {
        $setting = OpenCartSetting::findOrFail($store);
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
        ActivityLogger::log('opencart.store.deleted', 'opencart_settings', $id, $name, ['removed' => $removed]);

        return redirect()->route('channels.index')
            ->with('status', "Deleted OpenCart store {$name}. Sales already imported keep its name.");
    }

    public function testConnection(Request $request)
    {
        $storeId = $request->input('store_id');
        $baseUrl = $request->input('base_url');
        $apiKey  = $request->input('api_key');

        if (!$storeId && $baseUrl && $apiKey) {
            $setting = new OpenCartSetting([
                'base_url' => $baseUrl,
                'api_key'  => $apiKey,
                'enabled'  => true,
            ]);
            $setting->setRawApiKey($apiKey);
        } else {
            $setting = $storeId
                ? OpenCartSetting::find((int) $storeId)
                : OpenCartSetting::query()->first();
        }

        if (!$setting) {
            return response()->json(['ok' => false, 'error' => 'Not configured']);
        }

        $client = new OpenCartClient($setting);
        $result = $client->ping();

        return response()->json($result);
    }

    public function syncNow(Request $request)
    {
        $entity = $request->input('entity', 'all');
        $storeId = $request->input('store_id');
        $wantsJson = $request->expectsJson();

        $allowed = ['products', 'orders', 'categories', 'manufacturers', 'options', 'all'];
        if (!in_array($entity, $allowed)) {
            return $wantsJson
                ? response()->json(['error' => 'Invalid sync entity.'])
                : back()->with('error', 'Invalid sync entity.');
        }

        if (!$storeId) {
            return $wantsJson
                ? response()->json(['error' => 'Store ID is required.'])
                : back()->with('error', 'Store ID is required.');
        }

        try {
            $before = now();

            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
            $hasDateOverride = $entity === 'orders' && ($dateFrom || $dateTo);

            if ($hasDateOverride) {
                $setting = OpenCartSetting::where('id', (int) $storeId)->where('enabled', true)->firstOrFail();
                $client = new OpenCartClient($setting);
                $sync = new OpenCartOrderSync($client, $setting);

                $tz = new \DateTimeZone('Asia/Manila');
                $modifiedSince = $dateFrom
                    ? (new \DateTime($dateFrom, $tz))->setTime(0, 0, 0)->format('Y-m-d H:i:s')
                    : null;
                $modifiedBefore = $dateTo
                    ? (new \DateTime($dateTo, $tz))->setTime(23, 59, 59)->format('Y-m-d H:i:s')
                    : null;

                if ($request->input('no_stock')) {
                    $sync->setSkipStockAdjust(true);
                }

                $sync->pull($modifiedSince, 1, null, false, 0, $modifiedBefore);
            } else {
                $artisanArgs = [
                    'entity'  => $entity,
                    '--store' => (int) $storeId,
                    '--full'  => (bool) $request->input('full', false),
                ];

                if ($request->input('no_stock')) {
                    $artisanArgs['--no-stock'] = true;
                }

                Artisan::call('opencart:sync', $artisanArgs);
            }

            $logs = OpenCartSyncLog::where('opencart_setting_id', (int) $storeId)
                ->where('created_at', '>=', $before)
                ->orderBy('id')
                ->get();

            if ($logs->isEmpty()) {
                $msg = "Sync ({$entity}) completed.";
                return $wantsJson
                    ? response()->json(['status' => $msg])
                    : back()->with('status', $msg);
            }

            $parts = [];
            foreach ($logs as $log) {
                $label = ucfirst($log->entity_type ?? 'unknown');
                $counts = [];
                if ($log->records_created > 0) $counts[] = "{$log->records_created} created";
                if ($log->records_updated > 0) $counts[] = "{$log->records_updated} updated";
                if ($log->records_failed > 0)  $counts[] = "{$log->records_failed} failed";
                if (empty($counts) && $log->records_processed > 0) {
                    $counts[] = "{$log->records_processed} processed";
                } elseif (empty($counts)) {
                    $counts[] = "0 records";
                }
                $parts[] = "{$label}: " . implode(', ', $counts);
            }

            $msg = "Sync completed: " . implode(' | ', $parts);

            return $wantsJson
                ? response()->json(['status' => $msg])
                : back()->with('status', $msg);
        } catch (\Throwable $e) {
            $msg = 'Sync failed: ' . $e->getMessage();
            return $wantsJson
                ? response()->json(['error' => $msg], 500)
                : back()->with('error', $msg);
        }
    }

    public function pushNow(Request $request)
    {
        $storeId = $request->input('store_id');
        $wantsJson = $request->expectsJson();

        if (!$storeId) {
            return $wantsJson
                ? response()->json(['error' => 'Store ID is required.'])
                : back()->with('error', 'Store ID is required.');
        }

        $setting = OpenCartSetting::where('id', (int) $storeId)->where('enabled', true)->first();

        if (!$setting) {
            $msg = "Store #{$storeId} not found or disabled.";
            return $wantsJson
                ? response()->json(['error' => $msg])
                : back()->with('error', $msg);
        }

        try {
            $before = now();

            Artisan::call('opencart:push', [
                '--store' => (int) $storeId,
                '--all'   => true,
            ]);

            $logs = OpenCartSyncLog::where('opencart_setting_id', (int) $storeId)
                ->where('created_at', '>=', $before)
                ->orderBy('id')
                ->get();

            if ($logs->isEmpty()) {
                return $wantsJson
                    ? response()->json(['status' => 'Push completed.'])
                    : back()->with('status', 'Push completed.');
            }

            $parts = [];
            foreach ($logs as $log) {
                $label = ucfirst($log->entity_type ?? 'unknown');
                $counts = [];
                if ($log->records_created > 0) $counts[] = "{$log->records_created} created";
                if ($log->records_updated > 0) $counts[] = "{$log->records_updated} updated";
                if ($log->records_failed > 0)  $counts[] = "{$log->records_failed} failed";
                if (empty($counts) && $log->records_processed > 0) {
                    $counts[] = "{$log->records_processed} processed";
                } elseif (empty($counts)) {
                    $counts[] = "0 records";
                }
                $parts[] = "{$label}: " . implode(', ', $counts);
            }

            $msg = "Push completed: " . implode(' | ', $parts);

            return $wantsJson
                ? response()->json(['status' => $msg])
                : back()->with('status', $msg);
        } catch (\Throwable $e) {
            $msg = 'Push failed: ' . $e->getMessage();
            return $wantsJson
                ? response()->json(['error' => $msg], 500)
                : back()->with('error', $msg);
        }
    }

    public function pushProduct(int $store, int $product)
    {
        $setting = OpenCartSetting::where('id', $store)->where('enabled', true)->first();
        if (!$setting) {
            return back()->with('error', "Store #{$store} not found or disabled.");
        }

        try {
            $client = new OpenCartClient($setting);
            $ping = $client->ping();
            if (!$ping['ok']) {
                return back()->with('error', 'Cannot connect to OpenCart: ' . ($ping['body']['error'] ?? 'unknown'));
            }

            $sync = new OpenCartProductSync($client, $setting);
            $result = $sync->push([$product]);

            $pfx = config('catalog.prefix');
            $langId = (int) config('catalog.default_language_id');
            $name = DB::table($pfx . 'product_description')
                ->where('product_id', $product)
                ->where('language_id', $langId)
                ->value('name') ?? "Product #{$product}";

            if ($result['failed'] > 0) {
                $err = $result['errors'][0] ?? 'Unknown error';
                return back()->with('error', "Failed to push {$name}: {$err}");
            }

            $action = $result['created'] > 0 ? 'Created' : 'Updated';
            return back()->with('status', "{$action} \"{$name}\" on {$setting->store_name}.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Push failed: ' . $e->getMessage());
        }
    }

    public function pushQtyNow(Request $request)
    {
        $storeId = $request->input('store_id');
        $wantsJson = $request->expectsJson();

        if (!$storeId) {
            return $wantsJson
                ? response()->json(['error' => 'Store ID is required.'])
                : back()->with('error', 'Store ID is required.');
        }

        $setting = OpenCartSetting::where('id', (int) $storeId)->where('enabled', true)->first();

        if (!$setting) {
            $msg = "Store #{$storeId} not found or disabled.";
            return $wantsJson
                ? response()->json(['error' => $msg])
                : back()->with('error', $msg);
        }

        try {
            $client = new OpenCartClient($setting);
            $sync = new OpenCartProductSync($client, $setting);
            $log = $sync->pushQuantities();

            if ($log->status === 'failed') {
                $msg = 'Push Qty failed: ' . ($log->error_message ?? 'Unknown error');
                return $wantsJson
                    ? response()->json(['error' => $msg])
                    : back()->with('error', $msg);
            }

            $counts = [];
            if ($log->records_updated > 0) $counts[] = "{$log->records_updated} updated";
            if ($log->records_failed > 0)  $counts[] = "{$log->records_failed} failed";
            if (empty($counts)) {
                $counts[] = ($log->records_processed > 0 ? "{$log->records_processed} processed" : '0 linked products');
            }

            $msg = 'Push Qty completed: ' . implode(', ', $counts);

            return $wantsJson
                ? response()->json(['status' => $msg])
                : back()->with('status', $msg);
        } catch (\Throwable $e) {
            $msg = 'Push Qty failed: ' . $e->getMessage();
            return $wantsJson
                ? response()->json(['error' => $msg], 500)
                : back()->with('error', $msg);
        }
    }

    public function verifyPassword(Request $request)
    {
        $request->validate(['password' => 'required|string']);

        $user = $request->user();

        if (!$user || !Hash::check($request->input('password'), $user->password)) {
            return response()->json(['ok' => false, 'error' => 'Incorrect password.']);
        }

        return response()->json(['ok' => true]);
    }

    public function fetchOcStatuses(Request $request)
    {
        $storeId = $request->input('store_id');
        if (!$storeId) {
            return response()->json(['error' => 'Store ID is required.'], 422);
        }

        $setting = OpenCartSetting::find((int) $storeId);
        if (!$setting) {
            return response()->json(['error' => 'Store not found.'], 404);
        }

        $client = new OpenCartClient($setting);
        $result = $client->getOrderStatuses();

        if (!$result['ok']) {
            return response()->json(['error' => 'API call failed: ' . ($result['body']['error'] ?? 'Unknown error')], 500);
        }

        $statuses = $result['body']['data'] ?? $result['body'] ?? [];
        if (!is_array($statuses) || empty($statuses)) {
            return response()->json(['error' => 'No statuses returned from OpenCart. The API endpoint may not be available.'], 404);
        }

        $apiIds = [];
        foreach ($statuses as $s) {
            $ocId = (int) ($s['order_status_id'] ?? $s['id'] ?? 0);
            if ($ocId <= 0) continue;
            $name = (string) ($s['name'] ?? $s['label'] ?? '');

            $row = OpenCartOrderStatusMap::firstOrNew([
                'opencart_setting_id' => $setting->id,
                'oc_status_id'        => $ocId,
            ]);

            if (!$row->exists) {
                $row->order_status_id = 0;
            }
            $row->oc_status_name = $name;
            $row->save();

            $apiIds[] = $ocId;
        }

        OpenCartOrderStatusMap::where('opencart_setting_id', $setting->id)
            ->whereNotIn('oc_status_id', $apiIds ?: [0])
            ->delete();

        $persisted = OpenCartOrderStatusMap::where('opencart_setting_id', $setting->id)
            ->orderBy('oc_status_id')
            ->get(['oc_status_id', 'oc_status_name', 'order_status_id'])
            ->map(fn ($r) => [
                'order_status_id'    => (int) $r->oc_status_id,
                'name'               => $r->oc_status_name,
                'erp_order_status_id' => (int) $r->order_status_id,
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

        $setting = OpenCartSetting::find((int) $storeId);
        if (!$setting) {
            return response()->json(['error' => 'Store not found.'], 404);
        }

        $mappings = $request->input('mappings', []);

        foreach ($mappings as $m) {
            $ocStatusId = (int) ($m['oc_status_id'] ?? 0);
            if ($ocStatusId <= 0) continue;

            OpenCartOrderStatusMap::updateOrCreate(
                [
                    'opencart_setting_id' => $setting->id,
                    'oc_status_id'        => $ocStatusId,
                ],
                [
                    'oc_status_name'  => $m['oc_status_name'] ?? '',
                    'order_status_id' => (int) ($m['order_status_id'] ?? 0),
                ]
            );
        }

        return response()->json(['status' => 'Order status mapping saved.']);
    }
}
