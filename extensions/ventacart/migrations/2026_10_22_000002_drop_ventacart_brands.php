<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ch_ventacart_brands');
    }

    public function down(): void
    {
        Schema::create('ch_ventacart_brands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('ventacart_brand_id');
            $table->string('name');
            $table->string('slug')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'ventacart_brand_id'], 'ch_ventacart_brands_store_id_ventacart_brand_id_unique');
            $table->foreign('store_id', 'ch_ventacart_brands_store_id_foreign')->references('id')->on('ch_ventacart_stores')->cascadeOnDelete();
        });
    }
};
