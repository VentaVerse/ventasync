<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_variation_offs', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 32);
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('product_id');
            $table->string('sku', 100);
            $table->timestamps();

            $table->unique(['channel', 'store_id', 'product_id', 'sku'], 'listing_variation_offs_unique');
            $table->index(['product_id'], 'listing_variation_offs_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_variation_offs');
    }
};
