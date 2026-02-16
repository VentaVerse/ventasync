<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lazada_brands', function (Blueprint $table) {
            $table->id();
            $table->string('region', 8)->index();
            $table->unsignedBigInteger('brand_id')->index();
            $table->string('name', 255)->index();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['region', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lazada_brands');
    }
};
