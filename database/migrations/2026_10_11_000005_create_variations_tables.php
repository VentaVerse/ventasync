<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variation_types', function (Blueprint $table) {
            $table->integer('variation_type_id', true);
            $table->string('type', 32);
            $table->integer('sort_order')->default(0);
            $table->index(['sort_order'], 'variation_types_sort_order_index');
        });

        Schema::create('variation_type_descriptions', function (Blueprint $table) {
            $table->integer('variation_type_id');
            $table->integer('language_id');
            $table->string('name', 128);
            $table->primary(['variation_type_id', 'language_id']);
            $table->index(['language_id'], 'variation_type_descriptions_language_id_index');
            $table->index(['name'], 'variation_type_descriptions_name_index');
            $table->index(['variation_type_id'], 'variation_type_descriptions_variation_type_id_index');
        });

        Schema::create('variation_values', function (Blueprint $table) {
            $table->integer('variation_value_id', true);
            $table->integer('variation_type_id');
            $table->string('image', 255)->default('');
            $table->integer('sort_order')->default(0);
            $table->index(['variation_type_id'], 'variation_values_variation_type_id_index');
        });

        Schema::create('variation_value_descriptions', function (Blueprint $table) {
            $table->integer('variation_value_id');
            $table->integer('language_id');
            $table->integer('variation_type_id');
            $table->string('name', 128);
            $table->primary(['variation_value_id', 'language_id']);
            $table->index(['language_id'], 'variation_value_descriptions_language_id_index');
            $table->index(['variation_type_id'], 'variation_value_descriptions_variation_type_id_index');
            $table->index(['variation_value_id'], 'variation_value_descriptions_variation_value_id_index');
        });

        Schema::create('product_variation_types', function (Blueprint $table) {
            $table->integer('product_variation_type_id', true);
            $table->integer('product_id');
            $table->integer('variation_type_id');
            $table->text('value');
            $table->tinyInteger('required')->default(0);
            $table->index(['product_id'], 'product_variation_types_product_id_index');
            $table->index(['variation_type_id'], 'product_variation_types_variation_type_id_index');
        });

        Schema::create('product_variation_values', function (Blueprint $table) {
            $table->integer('product_variation_value_id', true);
            $table->integer('product_variation_type_id');
            $table->integer('product_id');
            $table->integer('variation_type_id');
            $table->integer('variation_value_id');
            $table->string('sku', 64)->default('');
            $table->integer('quantity')->default(0);
            $table->tinyInteger('subtract')->default(1);
            $table->decimal('price', 15, 4)->default(0);
            $table->decimal('cost', 15, 4)->default(0);
            $table->decimal('cost_amount', 15, 4)->default(0);
            $table->decimal('cost_percentage', 15, 2)->default(0);
            $table->decimal('cost_additional', 15, 4)->default(0);
            $table->decimal('absolute_cost', 15, 4)->nullable();
            $table->string('cost_prefix', 1)->default('+');
            $table->string('price_prefix', 1)->default('+');
            $table->decimal('absolute_price', 15, 4)->nullable();
            $table->integer('points')->default(0);
            $table->string('points_prefix', 1)->default('+');
            $table->decimal('weight', 8, 3)->default(0);
            $table->string('weight_prefix', 1)->default('+');
            $table->index(['product_id'], 'product_variation_values_product_id_index');
            $table->index(['product_variation_type_id'], 'product_variation_values_product_variation_type_id_index');
            $table->index(['variation_type_id'], 'product_variation_values_variation_type_id_index');
            $table->index(['variation_value_id'], 'product_variation_values_variation_value_id_index');
        });

        Schema::create('product_variations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id');
            $table->string('sku', 64)->default('');
            $table->boolean('status')->default(1);
            $table->string('image', 255)->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('absolute_price', 15, 4)->default(0);
            $table->decimal('absolute_cost', 15, 4)->default(0);
            $table->decimal('cost_amount', 15, 4)->default(0);
            $table->decimal('cost_additional', 15, 4)->default(0);
            $table->tinyInteger('subtract')->default(1);
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['product_id'], 'product_variations_product_id_index');
            $table->index(['sku'], 'product_variations_sku_index');
        });

        Schema::create('product_variation_parts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_variation_id');
            $table->unsignedInteger('product_variation_value_id');
            $table->index(['product_variation_id'], 'product_variation_parts_product_variation_id_index');
            $table->index(['product_variation_value_id'], 'product_variation_parts_product_variation_value_id_index');
            $table->unique(['product_variation_id', 'product_variation_value_id'], 'product_variation_parts_unique');
        });

        Schema::table('product_variation_parts', function (Blueprint $table) {
            $table->foreign('product_variation_id', 'product_variation_parts_product_variation_id_foreign')->references('id')->on('product_variations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('product_variation_parts');
        Schema::dropIfExists('product_variations');
        Schema::dropIfExists('product_variation_values');
        Schema::dropIfExists('product_variation_types');
        Schema::dropIfExists('variation_value_descriptions');
        Schema::dropIfExists('variation_values');
        Schema::dropIfExists('variation_type_descriptions');
        Schema::dropIfExists('variation_types');
        Schema::enableForeignKeyConstraints();
    }
};
