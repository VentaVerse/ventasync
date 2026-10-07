<?php

namespace App\Support;

use App\Extensions\ExtensionManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class LogRetention
{
    public const LEVELS = [
        'all'      => 'Every run',
        'failures' => 'Only runs that had a problem',
        'off'      => 'Nothing',
    ];

    public static function tables(): array
    {
        $default = (int) config('logs.default_keep_days', 30);
        $out = [];

        foreach ((array) config('logs.core', []) as $table => $days) {
            $out[$table] = ['label' => $table, 'keep_days' => (int) $days, 'owner' => 'core'];
        }

        $extensions = app(ExtensionManager::class);
        foreach ($extensions->getEnabledIds() as $id) {
            $manifest = $extensions->getManifest($id);
            foreach ((array) ($manifest['logs'] ?? []) as $table => $meta) {
                $meta = is_array($meta) ? $meta : [];
                $out[$table] = [
                    'label' => (string) ($meta['label'] ?? $table),
                    'keep_days' => (int) ($meta['keep_days'] ?? $default),
                    'owner' => $id,
                ];
            }
        }

        return $out;
    }

    public static function level(): string
    {
        return self::normalise(config('logs.sync_level', 'all'));
    }

    public static function levelFor(Model $log): string
    {
        if (method_exists($log, 'syncLogLevel')) {
            $own = $log->syncLogLevel();
            if (is_string($own) && array_key_exists($own, self::LEVELS)) {
                return $own;
            }
        }

        return self::level();
    }

    public static function shouldForget(Model $log): bool
    {
        $level = self::levelFor($log);
        if ($level === 'all') {
            return false;
        }

        $status = (string) ($log->status ?? '');
        if (! in_array($status, ['completed', 'failed'], true)) {
            return false;
        }

        if ($level === 'off') {
            return true;
        }

        return $status === 'completed' && (int) ($log->records_failed ?? 0) === 0;
    }

    // Delete in batches: one unbounded DELETE locks long enough for sync crons to time out.
    public static function purge(?int $overrideDays = null): array
    {
        $removed = [];

        foreach (self::tables() as $table => $meta) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $cutoff = now()->subDays($overrideDays ?? $meta['keep_days']);

            $count = 0;
            do {
                $batch = DB::table($table)->where('created_at', '<', $cutoff)->limit(5000)->delete();
                $count += $batch;
            } while ($batch > 0);

            if ($count > 0) {
                $removed[$table] = $count;
            }
        }

        return $removed;
    }

    private static function normalise(?string $level): string
    {
        return array_key_exists((string) $level, self::LEVELS) ? (string) $level : 'all';
    }
}
