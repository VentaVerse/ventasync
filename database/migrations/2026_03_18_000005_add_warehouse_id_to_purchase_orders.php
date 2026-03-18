<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id')->nullable();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('warehouse_id');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('warehouse_id');
        });
    }
};
