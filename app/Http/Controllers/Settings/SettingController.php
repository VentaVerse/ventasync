<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Providers\MailConfigServiceProvider;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    /**
     * IA migration (2026-08-08, Task 3), spec decision 4: this method now
     * backs four real routes (settings.general/.website/.mail/.maintenance),
     * one per tab, sharing this action and the one view. The active tab is
     * resolved from the route (a ->defaults('tab', …) on each registration
     * in routes/web.php), never from the query string. Falls back to
     * 'general' so a direct call without a bound route default still
     * renders something sane.
     */
    public function edit(Request $request)
    {
        $setting = Setting::singleton();
        $activeTab = $request->route('tab', 'general');

        return view('settings.edit', compact('setting', 'activeTab'));
    }

    /**
     * The pre-split settings URI. Kept, not deleted, because
     * layouts/app.blade.php (protected, byte-identical to the session
     * baseline) still calls route('settings.edit') for the sidebar link and
     * the legacy $__bcRoutes breadcrumb table, and route NAMES are frozen
     * for the duration of this migration. 301s a bare visit to the first
     * tab, and an old ?tab=… link to that same tab's new route.
     */
    public function redirectLegacyEdit(Request $request)
    {
        // Literal route() calls per branch on purpose, not a variable name
        // built up and passed once: RouteNameSweepTest's static half only
        // verifies clean `route('literal')` call sites, and Task 0's own
        // motivating incident was a route name assembled dynamically that
        // nothing had asserted against.
        return match ($request->query('tab')) {
            'website' => redirect()->route('settings.website', [], 301),
            'mail' => redirect()->route('settings.mail', [], 301),
            'maintenance' => redirect()->route('settings.maintenance', [], 301),
            default => redirect()->route('settings.general', [], 301),
        };
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
            'favicon'                     => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif,svg,webp,ico,x-icon,vnd.microsoft.icon', 'max:512'],
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

        // Favicon: upload replaces. If no new file is sent, the existing
        // favicon stays untouched (no UI option to revert to default -
        // operators can upload again to replace).
        if ($request->hasFile('favicon')) {
            if ($setting->favicon_path) {
                Storage::disk('public')->delete($setting->favicon_path);
            }
            $data['favicon_path'] = $request->file('favicon')->store('company', 'public');
        }
        unset($data['favicon']);

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
