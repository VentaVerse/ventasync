<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->json('gaps')->nullable();
            $table->string('stamp', 64)->nullable();
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
