<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pedallion_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('catalog_category_ids')->nullable();
            $table->json('manufacturer_ids')->nullable();
            $table->unsignedInteger('pedallion_category_id')->nullable();
            $table->string('condition', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('pedallion_profile_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pedallion_profile_id');
            $table->unsignedInteger('product_id');
            $table->timestamps();

            $table->unique(['pedallion_profile_id', 'product_id'], 'ped_profile_product_unique');
            $table->foreign('pedallion_profile_id')
                ->references('id')->on('pedallion_profiles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedallion_profile_products');
        Schema::dropIfExists('pedallion_profiles');
    }
};
