<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_settings', function (Blueprint $table) {
            $table->timestamp('last_order_sync_at')->nullable();
            $table->timestamp('last_stock_push_at')->nullable();
        });

        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->timestamp('last_order_sync_at')->nullable();
            $table->timestamp('last_stock_push_at')->nullable();
            $table->timestamp('last_return_sync_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lazada_settings', function (Blueprint $table) {
            $table->dropColumn(['last_order_sync_at', 'last_stock_push_at']);
        });

        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->dropColumn(['last_order_sync_at', 'last_stock_push_at', 'last_return_sync_at']);
        });
    }
};
