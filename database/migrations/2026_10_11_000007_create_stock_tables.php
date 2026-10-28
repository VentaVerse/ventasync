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
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('product_variation_value_id')->nullable();
            $table->unsignedInteger('order_id')->nullable();
            $table->string('type', 20);
            $table->integer('quantity_before');
            $table->integer('quantity_after');
            $table->integer('quantity_change');
            $table->string('source', 30);
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('user_name', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['created_at'], 'stock_history_created_at_index');
            $table->index(['order_id'], 'stock_history_order_id_index');
            $table->index(['product_id'], 'stock_history_product_id_index');
            $table->index(['product_variation_value_id'], 'stock_history_product_variation_value_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_history');
    }
};
