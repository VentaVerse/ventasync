<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
