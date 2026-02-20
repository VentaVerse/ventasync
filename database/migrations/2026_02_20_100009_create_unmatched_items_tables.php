<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopee_unmatched_items', function (Blueprint $table) {
            $table->id();
            $table->string('shopee_item_id', 64)->index();
            $table->string('shopee_model_id', 64)->nullable();
            $table->string('item_name', 500)->nullable();
            $table->string('sku', 128)->nullable()->index();
            $table->string('image_url', 1000)->nullable();
            $table->json('raw_data')->nullable();
            $table->enum('status', ['unmatched', 'linked', 'dismissed'])->default('unmatched')->index();
            $table->unsignedInteger('linked_product_id')->nullable();
            $table->timestamps();

            $table->unique(['shopee_item_id', 'shopee_model_id'], 'sui_unique');
        });

        Schema::create('lazada_unmatched_items', function (Blueprint $table) {
            $table->id();
            $table->string('lazada_item_id', 64)->index();
            $table->string('lazada_sku_id', 64)->nullable();
            $table->string('item_name', 500)->nullable();
            $table->string('sku', 128)->nullable()->index();
            $table->string('image_url', 1000)->nullable();
            $table->json('raw_data')->nullable();
            $table->enum('status', ['unmatched', 'linked', 'dismissed'])->default('unmatched')->index();
            $table->unsignedInteger('linked_product_id')->nullable();
            $table->timestamps();

            $table->unique(['lazada_item_id', 'sku'], 'lui_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_unmatched_items');
        Schema::dropIfExists('lazada_unmatched_items');
    }
};
