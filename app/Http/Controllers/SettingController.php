<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Providers\MailConfigServiceProvider;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
            'company_email'               => ['nullable', 'email', 'max:255'],
            'phone'                       => ['nullable', 'string', 'max:32'],
            'address_line1'               => ['nullable', 'string', 'max:255'],
            'address_line2'               => ['nullable', 'string', 'max:255'],
            'city'                        => ['nullable', 'string', 'max:128'],
            'state'                       => ['nullable', 'string', 'max:128'],
            'postal_code'                 => ['nullable', 'string', 'max:20'],
            'country'                     => ['nullable', 'string', 'max:128'],
            'from_email'                  => ['required', 'email', 'max:255'],
            'timezone'                    => ['required', 'string', 'timezone'],
            'activity_log_retention_days' => ['required', 'integer', 'min:7', 'max:3650'],
            'logo'                        => ['nullable', 'image', 'max:2048'],
            'mail_mailer'         => ['required', 'in:smtp,sendmail'],
            'mail_host'           => ['nullable', 'string', 'max:190'],
            'mail_port'           => ['nullable', 'integer', 'between:1,65535'],
            'mail_username'       => ['nullable', 'string', 'max:190'],
            'mail_password'       => ['nullable', 'string', 'max:190'],
            'mail_encryption'     => ['nullable', 'in:tls,ssl'],
            'mail_from_address'   => ['nullable', 'email', 'max:190'],
            'mail_from_name'      => ['nullable', 'string', 'max:190'],
            'clear_mail_password' => ['nullable', 'boolean'],
        ]);

        if ($request->hasFile('logo')) {
            if ($setting->logo_path) {
                Storage::disk('public')->delete($setting->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('company', 'public');
        }

        if ($request->boolean('clear_mail_password')) {
            $data['mail_password'] = null;
        } elseif (empty($data['mail_password'])) {
            unset($data['mail_password']);
        }
        unset($data['clear_mail_password']);

        $setting->update($data);

        MailConfigServiceProvider::flushCache();

        $changes = ActivityLogger::diff($original, $setting->getAttributes(), [
            'company_name', 'company_email', 'phone', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
            'from_email', 'timezone', 'activity_log_retention_days',
            'mail_mailer', 'mail_host', 'mail_port', 'mail_username', 'mail_encryption', 'mail_from_address', 'mail_from_name',
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

    public function sendTestMail(Request $request)
    {
        $data = $request->validate([
            'to' => ['required', 'email', 'max:190'],
        ]);

        try {
            Mail::raw(
                "This is a test message from your ERP.\n\nIf you received this, your mail settings are working correctly.",
                function ($message) use ($data) {
                    $message->to($data['to'])->subject('ERP test email');
                }
            );

            ActivityLogger::log('tested', 'Setting', null, 'Mail Settings', ['to' => $data['to']]);

            return response()->json([
                'ok'      => true,
                'message' => 'Test email sent to ' . $data['to'] . '.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
