<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * API logging grows a middle setting (#200, the owner): "errors only".
 *
 * He wants logging ON permanently, but the every-minute order fetch
 * eats the database and drowns the interesting rows. A boolean cannot
 * hold that want - the answer is a mode: all / errors / off. The old
 * booleans (api_logging, and pedallion's differently-named
 * logging_enabled) backfill into it and then leave, so there is one
 * source of truth.
 */
return new class extends Migration
{
    /** [table, old boolean column] per channel with an API log. */
    private const TABLES = [
        ['shopee_settings', 'api_logging'],
        ['lazada_settings', 'api_logging'],
        ['tiktok_settings', 'api_logging'],
        ['venta_settings', 'api_logging'],
        ['shopify_settings', 'api_logging'],
        ['pedallion_settings', 'logging_enabled'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as [$table, $old]) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'api_log_mode')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->string('api_log_mode', 10)->default('all');
            });
            if (Schema::hasColumn($table, $old)) {
                DB::table($table)->where($old, false)->update(['api_log_mode' => 'off']);
                DB::table($table)->where($old, true)->update(['api_log_mode' => 'all']);
                Schema::table($table, function (Blueprint $t) use ($old) {
                    $t->dropColumn($old);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as [$table, $old]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'api_log_mode')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($old) {
                $t->boolean($old)->default(true);
            });
            DB::table($table)->where('api_log_mode', 'off')->update([$old => false]);
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('api_log_mode');
            });
        }
    }
};
