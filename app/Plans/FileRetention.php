<?php

namespace App\Plans;

use Illuminate\Support\Facades\File;

final class FileRetention
{
    public static function purge(): array
    {
        $logDays = max(1, (int) config('logging.channels.daily.days', 14));

        return array_filter([
            'error log' => self::rotateErrorLog($logDays),
            'old log file' => self::removeIfOlder(storage_path('logs/laravel.log'), $logDays),
            'waybills' => self::purgeWaybills(),
        ]);
    }

    public static function rotateErrorLog(int $days): int
    {
        $current = storage_path('logs/error.log');

        if (is_file($current) && filesize($current) > 0) {
            $dated = storage_path('logs/error-' . now()->toDateString() . '.log');
            File::append($dated, (string) File::get($current));
            file_put_contents($current, '', LOCK_EX);
        }

        $removed = 0;
        foreach (glob(storage_path('logs/error-*.log')) ?: [] as $file) {
            $removed += self::removeIfOlder($file, $days);
        }

        return $removed;
    }

    public static function purgeWaybills(?string $root = null): int
    {
        $days = Plan::limit('awb_days');
        if ($days === null) {
            return 0;
        }

        $removed = 0;
        foreach (glob(($root ?? storage_path('app')) . '/*-awb', GLOB_ONLYDIR) ?: [] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                $removed += self::removeIfOlder($file->getPathname(), max(1, $days));
            }
        }

        return $removed;
    }

    private static function removeIfOlder(string $path, int $days): int
    {
        if (! is_file($path) || filemtime($path) >= now()->subDays($days)->getTimestamp()) {
            return 0;
        }

        return @unlink($path) ? 1 : 0;
    }
}
