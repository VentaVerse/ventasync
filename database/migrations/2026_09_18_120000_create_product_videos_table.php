<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_videos')) {
            return;
        }

        Schema::create('product_videos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->unique();
            $table->string('path', 512);
            $table->string('original_name', 255);
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('ai_generated')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_videos');
    }
};
