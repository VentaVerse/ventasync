<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_settings', function (Blueprint $table) {
            $table->json('order_tab_map')->nullable()->after('sync_last_days');
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_settings', function (Blueprint $table) {
            $table->dropColumn('order_tab_map');
        });
    }
};
