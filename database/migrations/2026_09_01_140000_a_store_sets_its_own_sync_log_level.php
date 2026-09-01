<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sync log's controls belong where the log is read (the owner, 2026-09-01):
 * the store's own Sync log tab, beside its list, exactly where the API log
 * already keeps its recording switch and its Delete all. One global setting
 * is the wrong granularity once several channels are busy: a store that is
 * noisy by nature should not force every other store quiet.
 *
 * NULL means "follow the default in Settings, Website", so nothing changes
 * for a store nobody has an opinion about.
 *
 * The created_at index arrives with it. Every reader of these tables orders
 * by created_at and takes the newest few, and not one of the three had an
 * index for it: on this box that was a 310,120-row scan and filesort each
 * time the Venta settings page opened. Pagination would have made it worse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_settings', function (Blueprint $table) {
            $table->string('sync_log_level', 16)->nullable()->after('api_logging');
        });

        foreach (['venta_sync_logs', 'opencart_sync_log', 'shopify_sync_logs'] as $table) {
            if (Schema::hasTable($table) && ! self::hasIndex($table, $table . '_created_at_index')) {
                Schema::table($table, fn (Blueprint $t) => $t->index('created_at'));
            }
        }
    }

    public function down(): void
    {
        Schema::table('venta_settings', function (Blueprint $table) {
            $table->dropColumn('sync_log_level');
        });

        foreach (['venta_sync_logs', 'opencart_sync_log', 'shopify_sync_logs'] as $table) {
            if (Schema::hasTable($table) && self::hasIndex($table, $table . '_created_at_index')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($table . '_created_at_index'));
            }
        }
    }

    private static function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($i) => ($i['name'] ?? '') === $index);
    }
};
