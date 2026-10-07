<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopee_order_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopee_order_id')->index();
            $table->string('item_id', 64)->nullable();
            $table->string('model_id', 64)->nullable();
            $table->string('sku', 128)->nullable();
            $table->string('name', 512)->nullable();
            $table->string('variation', 255)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('price', 12, 2)->default(0);
            $table->string('image', 512)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->foreign('shopee_order_id')
                ->references('id')
                ->on('shopee_orders')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_order_products');
    }
};
