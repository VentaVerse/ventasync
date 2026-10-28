<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ch_listing_variation_prices')) {
            Schema::create('ch_listing_variation_prices', function (Blueprint $table) {
                $table->id();
                $table->string('channel', 32);
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('product_id');
                $table->string('sku', 100);
                $table->decimal('price', 15, 4);
                $table->timestamps();
                $table->unique(['channel', 'store_id', 'product_id', 'sku'], 'ch_listing_variation_prices_unique');
                $table->index(['product_id'], 'ch_listing_variation_prices_product_id_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ch_listing_variation_prices');
    }
};
