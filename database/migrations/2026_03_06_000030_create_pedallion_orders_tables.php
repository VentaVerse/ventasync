<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pedallion_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 64)->unique();
            $table->string('status', 64)->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 3)->default('PHP');
            $table->string('buyer_name', 128)->nullable();
            $table->text('shipping_address')->nullable();
            $table->timestamp('order_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->unsignedInteger('erp_order_id')->nullable();
            $table->timestamps();
        });

        Schema::create('pedallion_order_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pedallion_order_id');
            $table->string('pedallion_sku', 128);
            $table->string('product_name', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->unsignedInteger('product_id')->nullable();
            $table->timestamps();

            $table->foreign('pedallion_order_id')
                ->references('id')->on('pedallion_orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedallion_order_products');
        Schema::dropIfExists('pedallion_orders');
    }
};
