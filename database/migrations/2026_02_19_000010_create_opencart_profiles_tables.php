<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opencart_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('opencart_setting_id');
            $table->string('name');
            $table->json('catalog_category_ids')->nullable();
            $table->json('manufacturer_ids')->nullable();
            $table->timestamps();

            $table->foreign('opencart_setting_id')
                ->references('id')
                ->on('opencart_settings')
                ->cascadeOnDelete();
        });

        Schema::create('opencart_profile_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('opencart_profile_id');
            $table->unsignedInteger('product_id');

            $table->unique(['opencart_profile_id', 'product_id']);

            $table->foreign('opencart_profile_id')
                ->references('id')
                ->on('opencart_profiles')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opencart_profile_products');
        Schema::dropIfExists('opencart_profiles');
    }
};
