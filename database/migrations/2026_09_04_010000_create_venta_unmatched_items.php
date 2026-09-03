<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Venta import queue (cleanup point 11, the owner 2026-09-04): items the
 * store holds that match no catalog SKU, found by Scan on the Import
 * page and resolved by Import / Link / Dismiss - the same queue the Big
 * 3 have carried since #196, store-scoped like everything Venta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_unmatched_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('venta_setting_id');
            $table->unsignedBigInteger('venta_product_id')->nullable();
            $table->string('sku', 191);
            $table->string('name', 255)->nullable();
            $table->decimal('price', 15, 4)->nullable();
            $table->integer('quantity')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->unsignedInteger('variant_count')->default(0);
            $table->string('status', 20)->default('unmatched'); // unmatched | linked | dismissed
            $table->unsignedBigInteger('linked_product_id')->nullable();
            $table->timestamps();

            $table->unique(['venta_setting_id', 'sku']);
            $table->index(['venta_setting_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_unmatched_items');
    }
};
