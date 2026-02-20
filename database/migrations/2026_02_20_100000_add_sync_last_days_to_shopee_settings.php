<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('shopee_settings', 'sync_last_days')) {
            return;
        }

        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('sync_last_days')->nullable()->after('region');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('shopee_settings', 'sync_last_days')) {
            Schema::table('shopee_settings', function (Blueprint $table) {
                $table->dropColumn('sync_last_days');
            });
        }
    }
};
