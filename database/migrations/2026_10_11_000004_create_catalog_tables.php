<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->integer('brand_id', true);
            $table->string('name', 64);
            $table->string('image', 255)->nullable();
            $table->integer('sort_order')->default(0);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->integer('category_id', true);
            $table->string('image', 255)->nullable();
            $table->integer('parent_id')->default(0);
            $table->tinyInteger('top')->default(0);
            $table->integer('column')->default(0);
            $table->integer('sort_order')->default(0);
            $table->tinyInteger('status')->default(0);
            $table->dateTime('date_added');
            $table->dateTime('date_modified');
            $table->index(['parent_id'], 'categories_parent_id_index');
        });

        Schema::create('category_descriptions', function (Blueprint $table) {
            $table->integer('category_id');
            $table->integer('language_id');
            $table->string('name', 255);
            $table->text('description');
            $table->string('meta_title', 255);
            $table->string('meta_description', 255);
            $table->string('meta_keyword', 255);
            $table->string('seo_keyword', 255);
            $table->string('seo_h1', 255);
            $table->string('seo_h2', 255);
            $table->string('seo_h3', 255);
            $table->primary(['category_id', 'language_id']);
            $table->index(['name'], 'category_descriptions_name_index');
        });

        Schema::create('category_paths', function (Blueprint $table) {
            $table->integer('category_id');
            $table->integer('path_id');
            $table->integer('level');
            $table->primary(['category_id', 'path_id']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->integer('product_id', true);
            $table->string('model', 64)->default('');
            $table->string('sku', 64)->default('');
            $table->string('upc', 12)->default('');
            $table->string('ean', 14)->default('');
            $table->string('jan', 13)->default('');
            $table->string('isbn', 17)->default('');
            $table->string('mpn', 64)->default('');
            $table->string('location', 128)->default('');
            $table->integer('quantity')->default(0);
            $table->integer('reorder_level')->default(0);
            $table->integer('stock_status_id')->default(0);
            $table->string('image', 255)->nullable();
            $table->integer('brand_id')->default(0);
            $table->tinyInteger('shipping')->default(1);
            $table->decimal('price', 15, 4)->default(0);
            $table->decimal('cost', 15, 4)->default(0);
            $table->decimal('cost_amount', 15, 4)->default(0);
            $table->decimal('cost_percentage', 15, 2)->default(0);
            $table->decimal('cost_additional', 15, 4)->default(0);
            $table->integer('points')->default(0);
            $table->integer('tax_class_id')->default(0);
            $table->date('date_available')->nullable();
            $table->decimal('weight', 8, 3)->default(0);
            $table->integer('weight_class_id')->default(0);
            $table->decimal('length', 10, 2)->default(0);
            $table->decimal('width', 10, 2)->default(0);
            $table->decimal('height', 10, 2)->default(0);
            $table->integer('length_class_id')->default(0);
            $table->tinyInteger('subtract')->default(1);
            $table->integer('minimum')->default(1);
            $table->integer('sort_order')->default(0);
            $table->tinyInteger('status')->default(0);
            $table->integer('viewed')->default(0);
            $table->string('config_background_image_position', 40)->default('');
            $table->string('config_background_image', 255)->default('');
            $table->dateTime('date_added');
            $table->dateTime('date_modified');
            $table->tinyInteger('add_cart')->default(1);
            $table->text('product_additionals')->nullable();
            $table->string('meta_robots', 40)->default('');
            $table->string('seo_canonical', 32)->default('');
        });

        Schema::create('product_descriptions', function (Blueprint $table) {
            $table->integer('product_id');
            $table->integer('language_id');
            $table->string('name', 255);
            $table->text('description');
            $table->string('meta_title', 255);
            $table->string('meta_description', 255);
            $table->string('meta_keyword', 255);
            $table->text('tag')->nullable();
            $table->primary(['product_id', 'language_id']);
            $table->fullText(['name', 'description'], 'product_descriptions_name_description_fulltext');
            $table->index(['name'], 'product_descriptions_name_index');
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->integer('product_image_id', true);
            $table->integer('product_id');
            $table->string('image', 255)->default('');
            $table->integer('sort_order')->default(0);
            $table->index(['product_id'], 'product_images_product_id_index');
        });

        Schema::create('product_specials', function (Blueprint $table) {
            $table->integer('product_special_id', true);
            $table->integer('product_id');
            $table->integer('customer_group_id')->default(0);
            $table->integer('priority')->default(0);
            $table->decimal('price', 15, 4)->default(0);
            $table->date('date_start')->nullable();
            $table->date('date_end')->nullable();
            $table->index(['product_id'], 'product_specials_product_id_index');
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->integer('product_id');
            $table->integer('category_id');
            $table->primary(['product_id', 'category_id']);
        });

        Schema::create('product_videos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('path', 512);
            $table->string('original_name', 255);
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('ai_generated')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['product_id'], 'product_videos_product_id_unique');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('product_videos');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('product_specials');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_descriptions');
        Schema::dropIfExists('products');
        Schema::dropIfExists('category_paths');
        Schema::dropIfExists('category_descriptions');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brands');
        Schema::enableForeignKeyConstraints();
    }
};
