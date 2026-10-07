<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopee_product_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id')->index();
            $table->string('shopee_item_id', 64)->index();
            $table->string('shopee_model_id', 64)->nullable();
            $table->string('sku', 128)->nullable()->index();
            $table->timestamps();

            $table->unique(['product_id', 'shopee_item_id', 'shopee_model_id'], 'spl_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_product_links');
    }
};
