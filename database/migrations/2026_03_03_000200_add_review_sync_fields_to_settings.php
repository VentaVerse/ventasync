<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->timestamp('last_review_sync_at')->nullable()->after('last_return_sync_at');
        });

        Schema::table('lazada_settings', function (Blueprint $table) {
            $table->timestamp('last_review_sync_at')->nullable()->after('last_return_sync_at');
        });

        Schema::table('opencart_settings', function (Blueprint $table) {
            $table->boolean('review_auto_approve')->default(true)->after('sync_log');
            $table->timestamp('last_review_push_at')->nullable()->after('review_auto_approve');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->dropColumn('last_review_sync_at');
        });

        Schema::table('lazada_settings', function (Blueprint $table) {
            $table->dropColumn('last_review_sync_at');
        });

        Schema::table('opencart_settings', function (Blueprint $table) {
            $table->dropColumn(['review_auto_approve', 'last_review_push_at']);
        });
    }
};
