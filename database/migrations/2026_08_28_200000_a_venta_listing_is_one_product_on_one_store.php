<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('venta_listings')) {
            return;
        }

        Schema::create('venta_listings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('venta_setting_id');
            $t->unsignedInteger('product_id');

            $t->decimal('markup_percent', 8, 2)->nullable();
            $t->decimal('markup_fixed', 12, 2)->nullable();

            $t->string('name', 255)->nullable();
            $t->text('description')->nullable();
            $t->string('meta_title', 255)->nullable();
            $t->string('meta_description', 500)->nullable();

            $t->string('live_status', 16)->nullable()->index();
            $t->timestamp('live_checked_at')->nullable();
            $t->decimal('live_price', 12, 2)->nullable();
            $t->integer('live_quantity')->nullable();

            $t->timestamp('last_pushed_at')->nullable();
            $t->string('last_push_source', 64)->nullable();
            $t->json('last_push_settings')->nullable();

            $t->timestamps();

            $t->unique(['venta_setting_id', 'product_id'], 'venta_listing_store_product_unique');
            $t->foreign('venta_setting_id')->references('id')->on('venta_settings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_listings');
    }
};
