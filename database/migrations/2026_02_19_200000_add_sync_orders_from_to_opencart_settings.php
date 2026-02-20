<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opencart_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('opencart_settings', 'sync_orders_from')) {
                $table->date('sync_orders_from')->nullable()->after('last_order_sync_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('opencart_settings', function (Blueprint $table) {
            $table->dropColumn('sync_orders_from');
        });
    }
};
