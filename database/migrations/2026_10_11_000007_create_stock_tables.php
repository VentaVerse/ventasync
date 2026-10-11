<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('code', 32);
            $table->boolean('is_default')->default(0);
            $table->boolean('is_sellable')->default(1);
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['code'], 'warehouses_code_unique');
        });

        Schema::create('warehouse_inventory', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('product_variation_value_id')->default(0);
            $table->integer('quantity')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['product_id'], 'warehouse_inventory_product_id_index');
            $table->unique(['warehouse_id', 'product_id', 'product_variation_value_id'], 'warehouse_inventory_unique');
        });

        Schema::create('warehouse_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32);
            $table->unsignedBigInteger('from_warehouse_id');
            $table->unsignedBigInteger('to_warehouse_id');
            $table->string('status', 16)->default('completed');
            $table->text('note')->nullable();
            $table->integer('user_id')->nullable();
            $table->string('user_name', 128)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['reference'], 'warehouse_transfers_reference_unique');
        });

        Schema::create('warehouse_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_transfer_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('product_variation_value_id')->default(0);
            $table->integer('quantity');
            $table->timestamp('created_at')->useCurrent();
        });

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

        Schema::table('warehouse_inventory', function (Blueprint $table) {
            $table->foreign('warehouse_id', 'warehouse_inventory_warehouse_id_foreign')->references('id')->on('warehouses');
        });

        Schema::table('warehouse_transfers', function (Blueprint $table) {
            $table->foreign('from_warehouse_id', 'warehouse_transfers_from_warehouse_id_foreign')->references('id')->on('warehouses');
            $table->foreign('to_warehouse_id', 'warehouse_transfers_to_warehouse_id_foreign')->references('id')->on('warehouses');
        });

        Schema::table('warehouse_transfer_items', function (Blueprint $table) {
            $table->foreign('warehouse_transfer_id', 'warehouse_transfer_items_warehouse_transfer_id_foreign')->references('id')->on('warehouse_transfers')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('stock_history');
        Schema::dropIfExists('warehouse_transfer_items');
        Schema::dropIfExists('warehouse_transfers');
        Schema::dropIfExists('warehouse_inventory');
        Schema::dropIfExists('warehouses');
        Schema::enableForeignKeyConstraints();
    }
};
