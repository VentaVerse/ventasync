<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One video per catalog product, beside its pictures.
 *
 * Its own table rather than a column on the catalog product: the file needs
 * what a picture never did (how long it runs, how heavy it is, and whether
 * the seller says it was made with AI, which Shopee now asks and penalises
 * a wrong answer to), and the catalog tables stay as OpenCart left them.
 */
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
            $table->string('path', 512);            // on the public disk, under video/
            $table->string('original_name', 255);
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('duration_ms')->nullable(); // null when the file does not say
            $table->boolean('ai_generated')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_videos');
    }
};
