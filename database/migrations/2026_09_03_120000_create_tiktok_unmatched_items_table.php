<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TikTok's import queue (#196): products the shop holds that the
 * catalog does not - the same queue Shopee and Lazada carry, in
 * TikTok's anatomy (a string product id, seller SKUs per row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_unmatched_items', function (Blueprint $table) {
            $table->id();
            $table->string('tiktok_product_id', 64);
            $table->string('item_name', 255)->nullable();
            $table->string('sku', 128)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->string('status', 16)->default('unmatched');
            $table->unsignedBigInteger('linked_product_id')->nullable();
            $table->timestamps();

            $table->unique(['tiktok_product_id', 'sku'], 'tui_product_sku_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_unmatched_items');
    }
};
