<?php

namespace App\Http\Controllers;

use App\Models\Catalog\OrderStatus;
use App\Models\OpenCartOrderStatusMap;
use App\Models\OpenCartSetting;
use App\Models\OpenCartSyncLog;
use App\Services\OpenCart\OpenCartClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OpenCartController extends Controller
{
    public function index()
    {
        $stores = OpenCartSetting::query()->orderBy('id')->get();

        $recentLogs = OpenCartSyncLog::query()
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $orderStatusMaps = OpenCartOrderStatusMap::query()
            ->orderBy('oc_status_id')
            ->get()
            ->groupBy('opencart_setting_id');

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $erpOrderStatuses = DB::table($pfx . 'order_status')
            ->where('language_id', $langId)
            ->orderBy('order_status_id')
            ->get(['order_status_id', 'name']);

        return view('opencart.index', compact('stores', 'recentLogs', 'orderStatusMaps', 'erpOrderStatuses'));
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'store_id'         => ['nullable', 'integer'],
            'store_name'       => ['nullable', 'string', 'max:128'],
            'base_url'         => ['required', 'string', 'max:255'],
            'api_key'          => ['required', 'string', 'max:128'],
            'enabled'          => ['nullable'],
            'sync_orders_from' => ['nullable', 'date'],
        ]);

        $data['enabled'] = $request->has('enabled') ? true : false;

        $storeId = $request->input('store_id');

        if ($storeId) {
            $setting = OpenCartSetting::findOrFail((int) $storeId);
            $setting->update([
                'store_name'       => $data['store_name'] ?? '',
                'base_url'         => $data['base_url'],
                'api_key'          => $data['api_key'],
                'enabled'          => $data['enabled'],
                'sync_orders_from' => $data['sync_orders_from'] ?? $setting->sync_orders_from,
            ]);
        } else {
            $setting = OpenCartSetting::create([
                'store_name'       => $data['store_name'] ?? '',
                'base_url'         => $data['base_url'],
                'api_key'          => $data['api_key'],
                'enabled'          => $data['enabled'],
                'sync_orders_from' => $data['sync_orders_from'] ?? null,
            ]);
        }

        $savedStoreId = $setting->id;
        return redirect()->route('opencart.index', $savedStoreId ? ['store' => $savedStoreId] : [])->with('status', 'OpenCart store saved.');
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

        return back()->with('status', $msg);
    }

    public function destroy($id)
    {
        $setting = OpenCartSetting::findOrFail((int) $id);
        $setting->delete();

        return redirect()->route('opencart.index')->with('status', 'Store deleted.');
    }

    public function testConnection(Request $request)
    {
        $storeId = $request->input('store_id');
        $baseUrl = $request->input('base_url');
        $apiKey  = $request->input('api_key');

        // Allow testing with raw base_url + api_key (before store is saved)
        if (!$storeId && $baseUrl && $apiKey) {
            $setting = new OpenCartSetting([
                'base_url' => $baseUrl,
                'api_key'  => $apiKey,
                'enabled'  => true,
            ]);
            // Skip encryption mutator for unsaved instance
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

            // Date override for orders: bypass artisan and call sync service directly
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
            $hasDateOverride = $entity === 'orders' && ($dateFrom || $dateTo);

            if ($hasDateOverride) {
                $setting = OpenCartSetting::where('id', (int) $storeId)->where('enabled', true)->firstOrFail();
                $client = new \App\Services\OpenCart\OpenCartClient($setting);
                $sync = new \App\Services\OpenCart\OpenCartOrderSync($client, $setting);

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

                // Initial import: skip stock adjustments for historical orders
                if ($request->input('no_stock')) {
                    $artisanArgs['--no-stock'] = true;
                }

                Artisan::call('opencart:sync', $artisanArgs);
            }

            // Build summary from sync logs created during this run
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

            $msg = "Sync completed — " . implode(' | ', $parts);

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

            // Build summary from sync logs created during this run
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

            $msg = "Push completed — " . implode(' | ', $parts);

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
            $sync = new \App\Services\OpenCart\OpenCartProductSync($client, $setting);
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

            $msg = 'Push Qty completed — ' . implode(', ', $counts);

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

    /**
     * Verify the current user's password (for Initial Import unlock).
     */
    public function verifyPassword(Request $request)
    {
        $request->validate(['password' => 'required|string']);

        $user = $request->user();

        if (!$user || !Hash::check($request->input('password'), $user->password)) {
            return response()->json(['ok' => false, 'error' => 'Incorrect password.']);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Fetch order statuses from the remote OpenCart store via API.
     */
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

        // If the API doesn't return a data wrapper, try the body directly
        if (!is_array($statuses) || empty($statuses)) {
            return response()->json(['error' => 'No statuses returned from OpenCart. The API endpoint may not be available.'], 404);
        }

        return response()->json(['statuses' => $statuses]);
    }

    /**
     * Save order status mapping for a store.
     */
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
