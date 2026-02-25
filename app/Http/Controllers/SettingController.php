<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    public function edit()
    {
        $setting = Setting::singleton();
        return view('settings.edit', compact('setting'));
    }

    public function update(Request $request)
    {
        $setting = Setting::singleton();
        $original = $setting->getAttributes();

        $data = $request->validate([
            'company_name'                => ['required', 'string', 'max:255'],
            'from_email'                  => ['required', 'email', 'max:255'],
            'timezone'                    => ['required', 'string', 'timezone'],
            'activity_log_retention_days' => ['required', 'integer', 'min:7', 'max:3650'],
            'logo'                        => ['nullable', 'image', 'max:2048'],
            'software_logo'               => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('logo')) {
            if ($setting->logo_path) {
                Storage::disk('public')->delete($setting->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('company', 'public');
        }


        if ($request->hasFile('software_logo')) {
            if ($setting->software_logo_path) {
                Storage::disk('public')->delete($setting->software_logo_path);
            }
            $data['software_logo_path'] = $request->file('software_logo')->store('software', 'public');
        }

        $setting->update($data);

        $changes = ActivityLogger::diff($original, $setting->getAttributes(), [
            'company_name', 'from_email', 'timezone', 'activity_log_retention_days',
        ]);
        ActivityLogger::log('updated', 'Setting', $setting->id, 'Website Settings', $changes);

        return back()->with('success', 'Settings updated.');
    }

    public function purgeRaw(Request $request)
    {
        $request->validate(['days' => ['required', 'integer', 'min' => 1]]);

        $cutoff = now()->subDays((int) $request->days);

        $tables = [
            'lazada_orders',
            'lazada_order_products',
            'lazada_order_items',
            'lazada_reverse_orders',
            'shopee_orders',
            'shopee_order_products',
            'shopee_returns',
        ];

        $total = 0;

        foreach ($tables as $table) {
            $affected = DB::table($table)
                ->whereNotNull('raw')
                ->where('created_at', '<', $cutoff)
                ->update(['raw' => null]);

            $total += $affected;
        }

        ActivityLogger::log('purged', 'Setting', null, 'Raw marketplace data (' . $request->days . ' days)');

        return response()->json([
            'ok'      => true,
            'message' => "Purged raw data from {$total} rows (older than {$request->days} days).",
        ]);
    }
}
