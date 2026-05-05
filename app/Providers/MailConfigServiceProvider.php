<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

class MailConfigServiceProvider extends ServiceProvider
{
    private const CACHE_KEY_SCHEMA = 'mail_config.schema_ready';
    private const CACHE_KEY_SETTING = 'mail_config.setting';
    private const CACHE_TTL = 300;

    public function boot(): void
    {
        try {
            $schemaReady = Cache::rememberForever(
                self::CACHE_KEY_SCHEMA,
                fn () => Schema::hasTable('settings') && Schema::hasColumn('settings', 'mail_mailer')
            );

            if (! $schemaReady) {
                return;
            }

            $setting = Cache::remember(
                self::CACHE_KEY_SETTING,
                self::CACHE_TTL,
                fn () => Setting::query()->first()
            );

            if (! $setting) {
                return;
            }

            $config = $this->app['config'];

            if (! empty($setting->mail_mailer)) {
                $config->set('mail.default', $setting->mail_mailer);
            }

            $smtpKeys = [
                'mail_host'       => 'mail.mailers.smtp.host',
                'mail_port'       => 'mail.mailers.smtp.port',
                'mail_username'   => 'mail.mailers.smtp.username',
                'mail_password'   => 'mail.mailers.smtp.password',
                'mail_encryption' => 'mail.mailers.smtp.encryption',
            ];

            foreach ($smtpKeys as $col => $cfgKey) {
                $value = $setting->{$col};
                if ($value !== null && $value !== '') {
                    $config->set($cfgKey, $value);
                }
            }

            if (! empty($setting->mail_from_address)) {
                $config->set('mail.from.address', $setting->mail_from_address);
            }
            if (! empty($setting->mail_from_name)) {
                $config->set('mail.from.name', $setting->mail_from_name);
            }
        } catch (Throwable $e) {
            // Never block boot — fall back to .env values.
        }
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY_SETTING);
    }
}
