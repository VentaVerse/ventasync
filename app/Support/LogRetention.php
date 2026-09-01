<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which log tables may be trimmed, how far back, and how much a sync run is
 * worth keeping at all.
 *
 * AN EXPLICIT LIST, NOT A PATTERN. Half the tables with "log" or "history" in
 * the name are business records that must never be swept: order_history,
 * stock_history and purchase_order_logs are what the reports and the audit
 * trail are made of. Only these two families are diagnostics:
 *
 *   SYNC  one row per sync RUN this ERP starts (did the 2am order pull work,
 *         what did it bring). Written by our own crons about our own work.
 *   API   one row per individual HTTP call: outbound, the calls we make to a
 *         marketplace, and inbound, api_request_logs, the calls other
 *         software (the mobile app, an assistant over MCP) makes to us.
 *
 * activity_logs is a third family and already has its own retention and its
 * own command (activity-log:purge); it is deliberately not repeated here.
 */
final class LogRetention
{
    /** Sync-run tables, governed by sync_log_retention_days and the level. */
    public const SYNC_TABLES = [
        'venta_sync_logs',
        'opencart_sync_log',
        'shopify_sync_logs',
    ];

    /** Per-call tables, governed by api_log_retention_days. */
    public const API_TABLES = [
        'api_request_logs',
        'venta_api_logs',
        'shopee_api_logs',
        'lazada_api_logs',
        'tiktok_api_logs',
        'shopify_api_logs',
        'pedallion_api_logs',
    ];

    public const LEVELS = [
        'all'      => 'Every run',
        'failures' => 'Only runs that had a problem',
        'off'      => 'Nothing',
    ];

    /** What a sync run is worth keeping: all, failures, off. */
    public static function level(): string
    {
        $level = (string) (self::setting()->sync_log_level ?? 'all');

        return array_key_exists($level, self::LEVELS) ? $level : 'all';
    }

    public static function syncDays(): int
    {
        return max(1, (int) (self::setting()->sync_log_retention_days ?? 30));
    }

    public static function apiDays(): int
    {
        return max(1, (int) (self::setting()->api_log_retention_days ?? 30));
    }

    /**
     * Whether this sync-run row is worth keeping now that it has finished.
     * A run still in flight, or one that crashed without ever writing its
     * ending, is always kept: that row IS the evidence. The age purge sweeps
     * it later like anything else.
     */
    public static function shouldForget(Model $log): bool
    {
        $level = self::level();
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
     * Trim every registered table to its retention.
     *
     * Deleted in batches because the first run on a live box is deleting
     * hundreds of thousands of rows, and one unbounded DELETE holds a lock
     * long enough for a sync cron to time out behind it.
     *
     * @return array<string, int> table => rows removed (only non-zero)
     */
    public static function purge(?int $syncDays = null, ?int $apiDays = null): array
    {
        $removed = [];

        foreach ([
            [self::SYNC_TABLES, $syncDays ?? self::syncDays()],
            [self::API_TABLES, $apiDays ?? self::apiDays()],
        ] as [$tables, $days]) {
            $cutoff = now()->subDays($days);

            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    continue;   // that extension is not installed here
                }

                $count = 0;
                do {
                    $batch = DB::table($table)->where('created_at', '<', $cutoff)->limit(5000)->delete();
                    $count += $batch;
                } while ($batch > 0);

                if ($count > 0) {
                    $removed[$table] = $count;
                }
            }
        }

        return $removed;
    }

    private static function setting(): Setting
    {
        return Setting::singleton();
    }
}
