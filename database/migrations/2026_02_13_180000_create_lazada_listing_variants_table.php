<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('lazada_listing_variants')) {
            return;
        }

        Schema::create('lazada_listing_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lazada_listing_id');
            $table->unsignedInteger('product_option_value_id')->nullable();

            // Mapping fields (Phase 2)
            $table->string('seller_sku', 64)->nullable();
            $table->decimal('price', 15, 4)->nullable();
            $table->integer('quantity')->nullable();

            $table->timestamps();

            $table->unique(['lazada_listing_id', 'product_option_value_id'], 'lazada_listing_variant_unique');
            $table->foreign('lazada_listing_id')->references('id')->on('lazada_listings')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lazada_listing_variants');
    }
};
