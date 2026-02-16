<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lazada_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lazada_order_id')->index();
            $table->string('order_item_id', 64)->index();
            $table->string('sku', 128)->nullable();
            $table->string('name', 255)->nullable();
            $table->integer('quantity')->nullable();
            $table->string('status', 64)->nullable()->index();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['lazada_order_id', 'order_item_id']);

            $table->foreign('lazada_order_id')->references('id')->on('lazada_orders')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lazada_order_items');
    }
};
