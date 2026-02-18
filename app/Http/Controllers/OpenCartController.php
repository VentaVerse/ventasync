<?php

namespace App\Http\Controllers;

use App\Models\OpenCartSetting;
use App\Models\OpenCartSyncLog;
use App\Services\OpenCart\OpenCartClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class OpenCartController extends Controller
{
    public function index()
    {
        $stores = OpenCartSetting::query()->orderBy('id')->get();

        $recentLogs = OpenCartSyncLog::query()
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return view('opencart.index', compact('stores', 'recentLogs'));
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'store_id'   => ['nullable', 'integer'],
            'store_name' => ['nullable', 'string', 'max:128'],
            'base_url'   => ['required', 'string', 'max:255'],
            'api_key'    => ['required', 'string', 'max:128'],
            'enabled'    => ['nullable'],
        ]);

        $data['enabled'] = $request->has('enabled') ? true : false;

        $storeId = $request->input('store_id');

        if ($storeId) {
            $setting = OpenCartSetting::findOrFail((int) $storeId);
            $setting->update([
                'store_name' => $data['store_name'] ?? '',
                'base_url'   => $data['base_url'],
                'api_key'    => $data['api_key'],
                'enabled'    => $data['enabled'],
            ]);
        } else {
            OpenCartSetting::create([
                'store_name' => $data['store_name'] ?? '',
                'base_url'   => $data['base_url'],
                'api_key'    => $data['api_key'],
                'enabled'    => $data['enabled'],
            ]);
        }

        return redirect()->route('opencart.index')->with('status', 'OpenCart store saved.');
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
        $setting = $storeId
            ? OpenCartSetting::find((int) $storeId)
            : OpenCartSetting::query()->first();

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

        $allowed = ['products', 'orders', 'categories', 'manufacturers', 'options', 'all'];
        if (!in_array($entity, $allowed)) {
            return back()->with('error', 'Invalid sync entity.');
        }

        if (!$storeId) {
            return back()->with('error', 'Store ID is required.');
        }

        try {
            Artisan::call('opencart:sync', [
                'entity'  => $entity,
                '--store' => (int) $storeId,
                '--full'  => true,
            ]);

            $output = Artisan::output();

            return back()->with('status', "Sync ({$entity}) completed for store #{$storeId}. " . trim($output));
        } catch (\Throwable $e) {
            return back()->with('error', 'Sync failed: ' . $e->getMessage());
        }
    }

    public function pushNow(Request $request)
    {
        $storeId = $request->input('store_id');

        if (!$storeId) {
            return back()->with('error', 'Store ID is required.');
        }

        $setting = OpenCartSetting::where('id', (int) $storeId)->where('enabled', true)->first();

        if (!$setting) {
            return back()->with('error', "Store #{$storeId} not found or disabled.");
        }

        try {
            Artisan::call('opencart:push', [
                '--store' => (int) $storeId,
                '--all'   => true,
            ]);

            $output = Artisan::output();

            return back()->with('status', "Push completed for store #{$storeId}. " . trim($output));
        } catch (\Throwable $e) {
            return back()->with('error', 'Push failed: ' . $e->getMessage());
        }
    }
}
