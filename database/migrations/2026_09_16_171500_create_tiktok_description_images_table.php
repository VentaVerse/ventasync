<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pictures inside a product description that TikTok Shop already holds,
 * one row per store and picture. TikTok only shows description pictures it
 * hosts itself, so each one is uploaded once and its TikTok URL reused on
 * every later push to the same store. The picture is keyed by the hash of
 * the bytes that were sent, so a changed picture is uploaded again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tiktok_description_images')) {
            return;
        }

        Schema::create('tiktok_description_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tiktok_setting_id')->default(0);
            $table->char('content_hash', 64);
            $table->string('url', 1024);
            $table->string('uri', 512)->nullable();
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();
            $table->unique(['tiktok_setting_id', 'content_hash'], 'tiktok_description_images_one_row');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_description_images');
    }
};
