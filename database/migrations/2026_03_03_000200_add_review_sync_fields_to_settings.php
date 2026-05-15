<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cross-cutting migration — touches shopee_settings (Community),
 * lazada_settings (Community), and opencart_settings (Plus).
 * Each ALTER is guarded with Schema::hasTable() so the migration
 * works regardless of which extensions are installed. A Community-
 * only install (without opencart) skips the third block silently;
 * a full Plus install runs all three.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shopee_settings')) {
            Schema::table('shopee_settings', function (Blueprint $table) {
                $table->timestamp('last_review_sync_at')->nullable()->after('last_return_sync_at');
            });
        }

        if (Schema::hasTable('lazada_settings')) {
            Schema::table('lazada_settings', function (Blueprint $table) {
                $table->timestamp('last_review_sync_at')->nullable()->after('last_return_sync_at');
            });
        }

        if (Schema::hasTable('opencart_settings')) {
            Schema::table('opencart_settings', function (Blueprint $table) {
                $table->boolean('review_auto_approve')->default(true)->after('sync_log');
                $table->timestamp('last_review_push_at')->nullable()->after('review_auto_approve');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('shopee_settings')) {
            Schema::table('shopee_settings', function (Blueprint $table) {
                $table->dropColumn('last_review_sync_at');
            });
        }

        if (Schema::hasTable('lazada_settings')) {
            Schema::table('lazada_settings', function (Blueprint $table) {
                $table->dropColumn('last_review_sync_at');
            });
        }

        if (Schema::hasTable('opencart_settings')) {
            Schema::table('opencart_settings', function (Blueprint $table) {
                $table->dropColumn(['review_auto_approve', 'last_review_push_at']);
            });
        }
    }
};
