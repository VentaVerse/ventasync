<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lazada_image_links', function (Blueprint $table) {
            $table->id();
            $table->string('region', 8)->index();
            $table->text('original_url');
            $table->string('original_hash', 64)->index();
            $table->text('lazada_url');
            $table->timestamps();

            $table->unique(['region', 'original_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lazada_image_links');
    }
};
