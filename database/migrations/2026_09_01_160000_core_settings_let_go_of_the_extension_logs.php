<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core settings let go of the sync and API log retentions added this morning.
 *
 * Syncing is not a core feature: every sync log and per-marketplace API log
 * belongs to an extension, so a core settings page holding their retention,
 * and a core Purge now button deleting from their tables, was core reaching
 * into modules (the owner, 2026-09-01). Each extension declares its own log
 * tables in extension.json now, and App\Support\LogRetention collects them
 * the way ApiScopeRegistry collects api_scopes. The numbers that are left
 * are housekeeping defaults and live in config/logs.php.
 *
 * activity_log_retention_days stays: the activity log is core's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach (['sync_log_retention_days', 'sync_log_level', 'api_log_retention_days'] as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('sync_log_retention_days')->default(30)->after('activity_log_retention_days');
            $table->string('sync_log_level', 16)->default('all')->after('sync_log_retention_days');
            $table->unsignedSmallInteger('api_log_retention_days')->default(30)->after('sync_log_level');
        });
    }
};
