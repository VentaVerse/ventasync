<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id')->index();
            $table->unsignedInteger('product_option_value_id')->nullable()->index();
            $table->unsignedInteger('order_id')->nullable()->index();
            $table->string('type', 20);           // set, deduct, restore
            $table->integer('quantity_before');
            $table->integer('quantity_after');
            $table->integer('quantity_change');     // positive = added, negative = deducted
            $table->string('source', 30);           // manual, order, lazada_sync, shopee_sync, opencart_sync
            $table->string('note', 255)->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('user_name', 100)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_history');
    }
};
