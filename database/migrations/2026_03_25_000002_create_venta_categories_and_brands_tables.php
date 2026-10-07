<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('venta_setting_id');
            $table->unsignedBigInteger('venta_category_id');
            $table->string('name', 255);
            $table->string('slug', 255)->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['venta_setting_id', 'venta_category_id'], 'venta_cat_store_unique');
            $table->foreign('venta_setting_id')->references('id')->on('venta_settings')->cascadeOnDelete();
        });

        Schema::create('venta_brands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('venta_setting_id');
            $table->unsignedBigInteger('venta_brand_id');
            $table->string('name', 255);
            $table->string('slug', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['venta_setting_id', 'venta_brand_id'], 'venta_brand_store_unique');
            $table->foreign('venta_setting_id')->references('id')->on('venta_settings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_brands');
        Schema::dropIfExists('venta_categories');
    }
};
