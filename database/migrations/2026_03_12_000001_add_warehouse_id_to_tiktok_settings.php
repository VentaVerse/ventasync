<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_settings', function (Blueprint $table) {
            $table->string('warehouse_id', 64)->nullable()->after('shop_name');
            $table->string('sandbox_warehouse_id', 64)->nullable()->after('sandbox_shop_name');
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_settings', function (Blueprint $table) {
            $table->dropColumn(['warehouse_id', 'sandbox_warehouse_id']);
        });
    }
};
