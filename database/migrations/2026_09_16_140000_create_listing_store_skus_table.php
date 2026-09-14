<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The variation SKUs a store's own item holds, per channel, store and
 * product, as last read from the store. It lets a listing name a variation
 * the store sells but its item does not have, without asking the store on
 * every page load.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('listing_store_skus')) {
            return;
        }

        Schema::create('listing_store_skus', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 32);
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('product_id');
            $table->json('skus');
            $table->timestamp('checked_at')->nullable();
            $table->unique(['channel', 'store_id', 'product_id'], 'listing_store_skus_one_row');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_store_skus');
    }
};
