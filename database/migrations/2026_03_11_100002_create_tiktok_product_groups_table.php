<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_product_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tiktok_category_id');
            $table->json('catalog_category_ids')->nullable();
            $table->json('manufacturer_ids')->nullable();
            $table->decimal('markup_percent', 10, 2)->nullable();
            $table->decimal('markup_fixed', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_product_groups');
    }
};
