<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->boolean('api_logging')->default(true)->after('sync_last_days');
        });

        Schema::table('lazada_settings', function (Blueprint $table) {
            $table->boolean('api_logging')->default(true)->after('sync_last_days');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->dropColumn('api_logging');
        });

        Schema::table('lazada_settings', function (Blueprint $table) {
            $table->dropColumn('api_logging');
        });
    }
};
