<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The Shopify and OpenCart import queues (cleanup point 11, 2026-09-04). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shopify_unmatched_items')) {
        Schema::create('shopify_unmatched_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_setting_id');
            $table->unsignedBigInteger('shopify_product_id');
            $table->string('sku', 191)->nullable();
            $table->string('name', 255)->nullable();
            $table->decimal('price', 15, 4)->nullable();
            $table->integer('quantity')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->unsignedInteger('variant_count')->default(0);
            $table->string('status', 20)->default('unmatched');
            $table->unsignedBigInteger('linked_product_id')->nullable();
            $table->timestamps();

            // Explicit names: the generated ones blow MySQL's 64-char cap.
            $table->unique(['shopify_setting_id', 'shopify_product_id'], 'shopify_unmatched_store_product_unique');
            $table->index(['shopify_setting_id', 'status'], 'shopify_unmatched_store_status_index');
        });
        }

        if (! Schema::hasTable('opencart_unmatched_items')) {
        Schema::create('opencart_unmatched_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('opencart_setting_id');
            $table->unsignedBigInteger('oc_product_id');
            $table->string('sku', 191)->nullable();
            $table->string('name', 255)->nullable();
            $table->decimal('price', 15, 4)->nullable();
            $table->integer('quantity')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->unsignedInteger('variant_count')->default(0);
            $table->string('status', 20)->default('unmatched');
            $table->unsignedBigInteger('linked_product_id')->nullable();
            $table->timestamps();

            $table->unique(['opencart_setting_id', 'oc_product_id'], 'opencart_unmatched_store_product_unique');
            $table->index(['opencart_setting_id', 'status'], 'opencart_unmatched_store_status_index');
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_unmatched_items');
        Schema::dropIfExists('opencart_unmatched_items');
    }
};
