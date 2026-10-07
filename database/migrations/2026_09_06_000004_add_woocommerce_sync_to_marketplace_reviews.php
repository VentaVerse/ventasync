<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_reviews', function (Blueprint $t) {
            if (! Schema::hasColumn('marketplace_reviews', 'woocommerce_sync_status')) {
                $t->string('woocommerce_sync_status', 20)->default('pending')->index()->after('venta_push_error');
            }
            if (! Schema::hasColumn('marketplace_reviews', 'woocommerce_setting_id')) {
                $t->unsignedBigInteger('woocommerce_setting_id')->nullable()->after('woocommerce_sync_status');
            }
            if (! Schema::hasColumn('marketplace_reviews', 'woocommerce_review_id')) {
                $t->unsignedBigInteger('woocommerce_review_id')->nullable()->after('woocommerce_setting_id');
            }
            if (! Schema::hasColumn('marketplace_reviews', 'woocommerce_pushed_at')) {
                $t->timestamp('woocommerce_pushed_at')->nullable()->after('woocommerce_review_id');
            }
            if (! Schema::hasColumn('marketplace_reviews', 'woocommerce_push_error')) {
                $t->string('woocommerce_push_error', 500)->nullable()->after('woocommerce_pushed_at');
            }
        });
        Schema::table('woocommerce_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('woocommerce_settings', 'last_review_push_at')) {
                $t->timestamp('last_review_push_at')->nullable()->after('last_stock_push_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_reviews', function (Blueprint $t) {
            $t->dropIndex(['woocommerce_sync_status']);
            $t->dropColumn(['woocommerce_sync_status', 'woocommerce_setting_id', 'woocommerce_review_id', 'woocommerce_pushed_at', 'woocommerce_push_error']);
        });
        Schema::table('woocommerce_settings', function (Blueprint $t) {
            $t->dropColumn('last_review_push_at');
        });
    }
};
