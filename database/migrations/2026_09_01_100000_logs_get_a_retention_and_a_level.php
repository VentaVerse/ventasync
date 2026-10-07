<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
