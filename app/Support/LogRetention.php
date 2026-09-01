<?php

namespace App\Support;

use App\Extensions\ExtensionManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which diagnostic log tables may be trimmed, and how far back.
 *
 * NOTHING HERE NAMES AN EXTENSION'S TABLE. Core owns api_request_logs, the
 * record of calls made INTO this ERP; every sync log and per-marketplace API
 * log belongs to the extension that writes it and is declared in that
 * extension's own manifest:
 *
 *     "logs": {
 *         "venta_sync_logs": { "label": "Sync runs", "kind": "sync" },
 *         "venta_api_logs":  { "label": "API calls", "keep_days": 14 }
 *     }
 *
 * collected here the same way ApiScopeRegistry collects api_scopes, so a
 * channel that is not installed contributes nothing and a channel added
 * tomorrow is swept without core being edited. Syncing is not a core
 * feature and core does not carry a list of who does it (the owner, 2026-09-01).
 *
 * AN EXPLICIT DECLARATION, NEVER A PATTERN. order_history, stock_history and
 * purchase_order_logs all have log-ish names and are business records that
 * must never be swept; activity_logs is a third family with its own
 * retention and its own command (activity-log:purge).
 */
final class LogRetention
{
    public const LEVELS = [
        'all'      => 'Every run',
        'failures' => 'Only runs that had a problem',
        'off'      => 'Nothing',
    ];

    /**
     * Every table that may be trimmed, core's and every enabled extension's.
     *
     * @return array<string, array{label: string, keep_days: int, owner: string}>
     */
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

    /** The default for a store that has not chosen: all, failures, off. */
    public static function level(): string
    {
        return self::normalise(config('logs.sync_level', 'all'));
    }

    /**
     * The level that governs THIS run's log. A store may set its own on its
     * own settings page, because one busy channel should not have to make
     * every other channel quiet; a store that has not chosen follows the
     * configured default.
     *
     * The row is asked, not told: any sync log model may answer
     * syncLogLevel(), and one that cannot simply follows the default.
     */
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

    /**
     * Whether this sync-run row is worth keeping now that it has finished.
     * A run still in flight, or one that crashed without ever writing its
     * ending, is always kept: that row IS the evidence. The age purge sweeps
     * it later like anything else.
     */
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

        // 'failures': a clean finish is not worth a row.
        return $status === 'completed' && (int) ($log->records_failed ?? 0) === 0;
    }

    /**
     * Trim every declared table to its retention.
     *
     * Deleted in batches because the first run on a live box is deleting
     * hundreds of thousands of rows, and one unbounded DELETE holds a lock
     * long enough for a sync cron to time out behind it.
     *
     * @return array<string, int> table => rows removed (only non-zero)
     */
    public static function purge(?int $overrideDays = null): array
    {
        $removed = [];

        foreach (self::tables() as $table => $meta) {
            if (! Schema::hasTable($table)) {
                continue;   // declared by a manifest, not migrated here
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
