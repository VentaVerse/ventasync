<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['lazada_products', 'tiktok_listings'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'last_push_error')) {
                    $t->string('last_push_error', 480)->nullable()->after('last_push_source');
                }
                if (! Schema::hasColumn($table, 'last_push_failed_at')) {
                    $t->timestamp('last_push_failed_at')->nullable()->after('last_push_error');
                }
            });
        }

        if (Schema::hasTable('lazada_product_group_products') && Schema::hasColumn('lazada_product_group_products', 'push_error')) {
            $failed = DB::table('lazada_product_group_products')
                ->where('sync_status', 'error')->whereNotNull('push_error')->whereNotNull('lazada_product_id')
                ->orderBy('last_pushed_at')
                ->get(['lazada_product_id', 'push_error', 'last_pushed_at']);
            foreach ($failed as $row) {
                DB::table('lazada_products')->where('id', $row->lazada_product_id)
                    ->update(['last_push_error' => mb_substr((string) $row->push_error, 0, 480), 'last_push_failed_at' => $row->last_pushed_at]);
            }
        }

        if (Schema::hasTable('tiktok_product_group_products') && Schema::hasColumn('tiktok_product_group_products', 'push_error')) {
            $failed = DB::table('tiktok_product_group_products as pv')
                ->join('tiktok_product_groups as g', 'g.id', '=', 'pv.tiktok_product_group_id')
                ->where('pv.sync_status', 'error')->whereNotNull('pv.push_error')
                ->orderBy('pv.last_pushed_at')
                ->get(['pv.product_id', 'pv.push_error', 'pv.last_pushed_at', 'g.tiktok_setting_id']);
            foreach ($failed as $row) {
                DB::table('tiktok_listings')
                    ->where('tiktok_setting_id', $row->tiktok_setting_id)->where('product_id', $row->product_id)
                    ->update(['last_push_error' => mb_substr((string) $row->push_error, 0, 480), 'last_push_failed_at' => $row->last_pushed_at]);
            }
        }
    }

    public function down(): void
    {
        foreach (['lazada_products', 'tiktok_listings'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (['last_push_error', 'last_push_failed_at'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $t->dropColumn($column);
                    }
                }
            });
        }
    }
};
