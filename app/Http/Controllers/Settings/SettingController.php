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
    public function edit(Request $request)
    {
        $setting = Setting::singleton();
        $activeTab = $request->route('tab', 'general');

        return view('settings.edit', compact('setting', 'activeTab'));
    }

    public function redirectLegacyEdit(Request $request)
    {
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
            'activity_log_retention_days' => ['required', 'integer', 'min:' . min(7, $this->activityCap()), 'max:' . $this->activityCap()],
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
            'remove'              => ['nullable', 'in:logo,favicon'],
            'image_thumb_size'    => ['nullable', 'integer', 'between:' . implode(',', \App\Services\Media\ImageCache::LIMITS['thumb'])],
            'image_preview_size'  => ['nullable', 'integer', 'between:' . implode(',', \App\Services\Media\ImageCache::LIMITS['preview'])],
            'image_push_size'     => ['nullable', 'integer', 'between:' . implode(',', \App\Services\Media\ImageCache::LIMITS['push'])],
            'logo_nav_h'          => ['nullable', 'integer', 'between:' . implode(',', \App\Services\Media\BrandImage::LIMITS['h'])],
            'logo_login_h'        => ['nullable', 'integer', 'between:' . implode(',', \App\Services\Media\BrandImage::LIMITS['h'])],
        ]);
        foreach ([
            'image_thumb_size', 'image_preview_size', 'image_push_size',
            'logo_nav_h', 'logo_login_h',
        ] as $size) {
            if (! isset($data[$size])) {
                unset($data[$size]);
            }
        }

        $remove = (string) $request->input('remove', '');
        foreach (['logo' => 'logo_path', 'favicon' => 'favicon_path'] as $what => $column) {
            if ($remove === $what && $setting->{$column}) {
                Storage::disk('public')->delete($setting->{$column});
                $data[$column] = null;
            }
        }

        if ($request->hasFile('logo')) {
            if ($setting->logo_path) {
                Storage::disk('public')->delete($setting->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('company', 'public');
        }

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

        unset($data['remove']);
        $setting->update($data);

        MailConfigServiceProvider::flushCache();
        \App\Services\Media\ImageCache::forget();
        \App\Services\Media\BrandImage::forget();

        $changes = ActivityLogger::diff($original, $setting->getAttributes(), [
            'company_name', 'company_email', 'phone', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
            'from_email', 'timezone', 'activity_log_retention_days', 'image_thumb_size', 'image_preview_size', 'image_push_size',
            'mail_mailer', 'mail_host', 'mail_port', 'mail_username', 'mail_encryption', 'mail_from_address', 'mail_from_name',
        ]);
        ActivityLogger::log('updated', 'Setting', $setting->id, 'Website Settings', $changes);

        return back()->with('success', match ($remove) {
            'logo' => 'Logo removed.',
            'favicon' => 'Favicon removed.',
            default => 'Settings updated.',
        });
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

    private function activityCap(): int
    {
        $cap = \App\Plans\Plan::limit('activity_days');

        return $cap === null ? 3650 : max(1, $cap);
    }
}
