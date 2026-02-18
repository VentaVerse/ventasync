<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lazada_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('primary_category_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();

            // Remote identifiers (filled in Phase 3)
            $table->string('lazada_item_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();
            $table->index(['product_id']);
            $table->index(['primary_category_id']);
        });

        Schema::create('lazada_category_templates', function (Blueprint $table) {
            $table->id();
            $table->string('region', 10)->nullable();
            $table->unsignedBigInteger('primary_category_id');
            $table->json('template_body')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['region', 'primary_category_id'], 'lazada_cat_tpl_region_category_unique');
        });

        Schema::create('lazada_product_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lazada_product_id');
            $table->string('attribute_key', 191);
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['lazada_product_id', 'attribute_key'], 'lazada_product_attr_unique');
            $table->foreign('lazada_product_id')->references('id')->on('lazada_products')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lazada_product_attributes');
        Schema::dropIfExists('lazada_category_templates');
        Schema::dropIfExists('lazada_products');
    }
};
