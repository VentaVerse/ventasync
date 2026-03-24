<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_reviews', function (Blueprint $table) {
            $table->string('venta_sync_status', 20)->default('pending')->index()->after('oc_push_error');
            $table->unsignedBigInteger('venta_setting_id')->nullable()->after('venta_sync_status');
            $table->unsignedBigInteger('venta_review_id')->nullable()->after('venta_setting_id');
            $table->timestamp('venta_pushed_at')->nullable()->after('venta_review_id');
            $table->string('venta_push_error', 500)->nullable()->after('venta_pushed_at');
        });

        Schema::table('venta_settings', function (Blueprint $table) {
            $table->timestamp('last_review_push_at')->nullable()->after('last_stock_push_at');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_reviews', function (Blueprint $table) {
            $table->dropIndex(['venta_sync_status']);
            $table->dropColumn([
                'venta_sync_status',
                'venta_setting_id',
                'venta_review_id',
                'venta_pushed_at',
                'venta_push_error',
            ]);
        });

        Schema::table('venta_settings', function (Blueprint $table) {
            $table->dropColumn('last_review_push_at');
        });
    }
};
