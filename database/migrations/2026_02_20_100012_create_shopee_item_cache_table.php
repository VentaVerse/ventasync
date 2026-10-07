<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_item_cache', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopee_item_id')->index();
            $table->unsignedBigInteger('shopee_model_id')->nullable();
            $table->string('sku', 128)->index();
            $table->string('item_name', 500)->nullable();
            $table->string('image_url', 1000)->nullable();
            $table->timestamps();

            $table->unique(['shopee_item_id', 'shopee_model_id'], 'sic_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_item_cache');
    }
};
