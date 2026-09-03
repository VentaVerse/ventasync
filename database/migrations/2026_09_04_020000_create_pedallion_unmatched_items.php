<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The Pedallion import queue (cleanup point 11, the owner 2026-09-04). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedallion_unmatched_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 191)->unique();
            $table->string('name', 255)->nullable();
            $table->decimal('price', 15, 4)->nullable();
            $table->integer('quantity')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->unsignedInteger('variant_count')->default(0);
            $table->string('status', 20)->default('unmatched'); // unmatched | linked | dismissed
            $table->unsignedBigInteger('linked_product_id')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedallion_unmatched_items');
    }
};
