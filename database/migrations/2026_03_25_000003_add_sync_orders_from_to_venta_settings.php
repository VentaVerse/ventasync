<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_settings', function (Blueprint $table) {
            $table->date('sync_orders_from')->nullable()->after('sync_last_days');
        });
    }

    public function down(): void
    {
        Schema::table('venta_settings', function (Blueprint $table) {
            $table->dropColumn('sync_orders_from');
        });
    }
};
