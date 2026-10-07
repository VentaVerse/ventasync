<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shopee_listings')) {
            return;
        }
        Schema::table('shopee_listings', function (Blueprint $table) {
            if (! Schema::hasColumn('shopee_listings', 'last_push_error')) {
                $table->string('last_push_error', 480)->nullable()->after('last_push_settings');
            }
            if (! Schema::hasColumn('shopee_listings', 'last_push_failed_at')) {
                $table->timestamp('last_push_failed_at')->nullable()->after('last_push_error');
            }
        });

        if (! Schema::hasTable('shopee_product_group_products') || ! Schema::hasColumn('shopee_product_group_products', 'push_error')) {
            return;
        }

        $failed = DB::table('shopee_product_group_products as pv')
            ->join('shopee_product_groups as g', 'g.id', '=', 'pv.shopee_product_group_id')
            ->where('pv.sync_status', 'error')
            ->whereNotNull('pv.push_error')
            ->orderBy('pv.last_pushed_at')
            ->get(['pv.product_id', 'pv.push_error', 'pv.last_pushed_at', 'g.shopee_setting_id']);

        foreach ($failed as $row) {
            $exists = DB::table('shopee_listings')
                ->where('shopee_setting_id', $row->shopee_setting_id)
                ->where('product_id', $row->product_id)
                ->exists();
            if (! $exists) {
                continue;
            }
            DB::table('shopee_listings')
                ->where('shopee_setting_id', $row->shopee_setting_id)
                ->where('product_id', $row->product_id)
                ->update(['last_push_error' => mb_substr((string) $row->push_error, 0, 480), 'last_push_failed_at' => $row->last_pushed_at]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('shopee_listings')) {
            return;
        }
        Schema::table('shopee_listings', function (Blueprint $table) {
            foreach (['last_push_error', 'last_push_failed_at'] as $column) {
                if (Schema::hasColumn('shopee_listings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
