<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materialised listing readiness (VENT2 perf): the Listings page used to
 * compute every product's readiness from scratch on each load, which scaled
 * with catalogue size. This table holds the answer per channel/store/product
 * so the page reads it, and it is recomputed only for products whose inputs
 * changed (a per-product `stamp`). Purely a cache, safe to truncate; it is
 * rebuilt lazily on read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_readiness', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 32);
            $table->unsignedInteger('store_id');
            $table->unsignedInteger('product_id');
            $table->boolean('ready')->default(false);
            $table->json('gaps')->nullable();          // [{code,label,keys?}]
            $table->string('stamp', 64)->nullable();    // hash of the inputs readiness depends on
            $table->timestamp('computed_at')->nullable();

            $table->unique(['channel', 'store_id', 'product_id'], 'listing_readiness_unique');
            $table->index(['channel', 'store_id', 'ready'], 'listing_readiness_ready_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_readiness');
    }
};
