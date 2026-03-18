<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->unsignedBigInteger('from_warehouse_id');
            $table->unsignedBigInteger('to_warehouse_id');
            $table->string('status', 16)->default('completed');
            $table->text('note')->nullable();
            $table->integer('user_id')->nullable();
            $table->string('user_name', 128)->nullable();
            $table->timestamps();

            $table->foreign('from_warehouse_id')->references('id')->on('warehouses');
            $table->foreign('to_warehouse_id')->references('id')->on('warehouses');
        });

        Schema::create('warehouse_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_transfer_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('product_option_value_id')->default(0);
            $table->integer('quantity');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('warehouse_transfer_id')
                  ->references('id')
                  ->on('warehouse_transfers')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_transfer_items');
        Schema::dropIfExists('warehouse_transfers');
    }
};
