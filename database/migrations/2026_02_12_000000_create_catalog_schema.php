<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $p = config('catalog.prefix');

        // CATEGORY
        Schema::create($p.'category', function (Blueprint $table) {
            $table->integer('category_id')->autoIncrement();
            $table->string('image',255)->nullable();
            $table->integer('parent_id')->default(0);
            $table->tinyInteger('top')->default(0);
            $table->integer('column')->default(0);
            $table->integer('sort_order')->default(0);
            $table->tinyInteger('status')->default(0);
            $table->dateTime('date_added');
            $table->dateTime('date_modified');
            $table->index('parent_id');
        });

        Schema::create($p.'category_description', function (Blueprint $table) {
            $table->integer('category_id');
            $table->integer('language_id');
            $table->string('name',255);
            $table->text('description');
            $table->string('meta_title',255);
            $table->string('meta_description',255);
            $table->string('meta_keyword',255);
            $table->string('seo_keyword',255);
            $table->string('seo_h1',255);
            $table->string('seo_h2',255);
            $table->string('seo_h3',255);
            $table->primary(['category_id','language_id']);
            $table->index('name');
        });

        Schema::create($p.'category_path', function (Blueprint $table) {
            $table->integer('category_id');
            $table->integer('path_id');
            $table->integer('level');
            $table->primary(['category_id','path_id']);
        });

        // MANUFACTURER
        Schema::create($p.'manufacturer', function (Blueprint $table) {
            $table->integer('manufacturer_id')->autoIncrement();
            $table->string('name',64);
            $table->string('image',255)->nullable();
            $table->integer('sort_order')->default(0);
        });

        // OPTION
        Schema::create($p.'option', function (Blueprint $table) {
            $table->integer('option_id')->autoIncrement();
            $table->string('type',32);
            $table->integer('sort_order')->default(0);
            $table->index('sort_order');
        });

        Schema::create($p.'option_description', function (Blueprint $table) {
            $table->integer('option_id');
            $table->integer('language_id');
            $table->string('name',128);
            $table->primary(['option_id','language_id']);
            $table->index('name');
        });

        Schema::create($p.'option_value', function (Blueprint $table) {
            $table->integer('option_value_id')->autoIncrement();
            $table->integer('option_id');
            $table->string('image',255)->default('');
            $table->integer('sort_order')->default(0);
            $table->index('option_id');
        });

        Schema::create($p.'option_value_description', function (Blueprint $table) {
            $table->integer('option_value_id');
            $table->integer('language_id');
            $table->integer('option_id');
            $table->string('name',128);
            $table->primary(['option_value_id','language_id']);
            $table->index('option_id');
        });

        // PRODUCT
        Schema::create($p.'product', function (Blueprint $table) {
            $table->integer('product_id')->autoIncrement();

            $table->string('model',64)->default('');
            $table->string('sku',64)->default('');
            $table->string('upc',12)->default('');
            $table->string('ean',14)->default('');
            $table->string('jan',13)->default('');
            $table->string('isbn',17)->default('');
            $table->string('mpn',64)->default('');
            $table->string('location',128)->default('');

            $table->integer('quantity')->default(0);
            $table->integer('stock_status_id')->default(0);
            $table->string('image',255)->nullable();
            $table->integer('manufacturer_id')->default(0);
$table->tinyInteger('shipping')->default(1);

            $table->decimal('price',15,4)->default(0);
$table->integer('points')->default(0);
            $table->integer('tax_class_id')->default(0);

            // FIX: strict MariaDB/MySQL rejects '0000-00-00' default
            // We keep it compatible by allowing NULL (your app can treat NULL as "not set")
            $table->date('date_available')->nullable();

            $table->decimal('weight',15,8)->default(0);
            $table->integer('weight_class_id')->default(0);
            $table->decimal('length',15,8)->default(0);
            $table->decimal('width',15,8)->default(0);
            $table->decimal('height',15,8)->default(0);
            $table->integer('length_class_id')->default(0);

            $table->tinyInteger('subtract')->default(1);
            $table->integer('minimum')->default(1);
            $table->integer('sort_order')->default(0);
            $table->tinyInteger('status')->default(0);
            $table->integer('viewed')->default(0);

            $table->string('config_background_image_position',40)->default('');
            $table->string('config_background_image',255)->default('');

            $table->dateTime('date_added');
            $table->dateTime('date_modified');

            $table->tinyInteger('add_cart')->default(1);
            $table->text('product_additionals')->nullable();
            $table->string('meta_robots',40)->default('');
            $table->string('seo_canonical',32)->default('');
        });

        Schema::create($p.'product_description', function (Blueprint $table) {
            $table->integer('product_id');
            $table->integer('language_id');
            $table->string('name',255);
            $table->text('description');
            $table->string('meta_title',255);
            $table->string('meta_description',255);
            $table->string('meta_keyword',255);
            $table->text('tag')->nullable();
            $table->primary(['product_id','language_id']);
            $table->index('name');
        });

        // Fulltext index (works on MyISAM/InnoDB depending on config; MariaDB supports it)
        DB::statement("ALTER TABLE `{$p}product_description` ADD FULLTEXT KEY `related_generator` (`name`,`description`)");

        Schema::create($p.'product_to_category', function (Blueprint $table) {
            $table->integer('product_id');
            $table->integer('category_id');
            $table->primary(['product_id','category_id']);
        });

        Schema::create($p.'product_option', function (Blueprint $table) {
            $table->integer('product_option_id')->autoIncrement();
            $table->integer('product_id');
            $table->integer('option_id');
            $table->text('value');
            $table->tinyInteger('required')->default(0);
        });

        Schema::create($p.'product_option_value', function (Blueprint $table) {
            $table->integer('product_option_value_id')->autoIncrement();
            $table->integer('product_option_id');
            $table->integer('product_id');
            $table->integer('option_id');
            $table->integer('option_value_id');

            $table->string('sku',64)->default('');
            $table->integer('quantity')->default(0);
            $table->tinyInteger('subtract')->default(1);
            $table->decimal('price',15,4)->default(0);
            $table->string('price_prefix',1)->default('+');
            $table->integer('points')->default(0);
            $table->string('points_prefix',1)->default('+');
            $table->decimal('weight',15,8)->default(0);
            $table->string('weight_prefix',1)->default('+');
        });
    }

    public function down(): void
    {
        $p = config('catalog.prefix');

        Schema::dropIfExists($p.'product_option_value');
        Schema::dropIfExists($p.'product_option');
        Schema::dropIfExists($p.'product_to_category');
        Schema::dropIfExists($p.'product_description');
        Schema::dropIfExists($p.'product');
        Schema::dropIfExists($p.'option_value_description');
        Schema::dropIfExists($p.'option_value');
        Schema::dropIfExists($p.'option_description');
        Schema::dropIfExists($p.'option');
        Schema::dropIfExists($p.'manufacturer');
        Schema::dropIfExists($p.'category_path');
        Schema::dropIfExists($p.'category_description');
        Schema::dropIfExists($p.'category');
    }
};
