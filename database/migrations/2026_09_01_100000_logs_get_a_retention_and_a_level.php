<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sync logs and API logs grow forever (the owner, 2026-09-01: the live disk is
 * being eaten). On this box venta_sync_logs alone had 310,120 rows / 38 MB,
 * the largest table in the database, ahead of `order` itself, and
 * opencart_sync_log another 129,301 / 17.5 MB. Between them, 76% of every
 * log byte stored. Nothing purged them and nothing could stop them being
 * written.
 *
 * Activity logs already had both (activity_log_retention_days plus the
 * scheduled activity-log:purge). This gives the other two families the same
 * treatment: a retention in days, and for sync runs a level, because most of
 * those rows record a cron that found nothing and are worth nothing the day
 * after.
 *
 * Defaults keep today's behaviour visible rather than silently discarding:
 * level 'all' writes every run exactly as now; the 30-day retentions start
 * trimming, which is the point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('sync_log_retention_days')->default(30)->after('activity_log_retention_days');
            $table->string('sync_log_level', 16)->default('all')->after('sync_log_retention_days');
            $table->unsignedSmallInteger('api_log_retention_days')->default(30)->after('sync_log_level');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['sync_log_retention_days', 'sync_log_level', 'api_log_retention_days']);
        });
    }
};
