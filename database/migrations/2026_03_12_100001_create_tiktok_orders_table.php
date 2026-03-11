<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_orders', function (Blueprint $t) {
            $t->id();
            $t->string('region', 10)->default('PH')->index();
            $t->string('order_id', 64)->index();
            $t->string('status', 50)->nullable()->index();
            $t->timestamp('order_created_at')->nullable()->index();
            $t->timestamp('order_updated_at')->nullable();
            $t->json('raw')->nullable();
            $t->json('fees')->nullable();
            $t->string('buyer_name', 200)->nullable();
            $t->string('payout_status', 30)->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->unsignedBigInteger('catalog_order_id')->nullable()->index();
            $t->timestamps();
            $t->unique(['region', 'order_id']);
        });

        Schema::create('tiktok_order_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tiktok_order_id')->index();
            $t->string('order_line_item_id', 64)->nullable();
            $t->string('sku', 100)->nullable();
            $t->string('name', 500)->nullable();
            $t->string('variation', 255)->nullable();
            $t->unsignedInteger('quantity')->default(1);
            $t->decimal('item_price', 12, 2)->default(0);
            $t->decimal('sale_price', 12, 2)->default(0);
            $t->string('status', 50)->nullable();
            $t->string('image', 1000)->nullable();
            $t->json('raw')->nullable();
            $t->timestamps();
            $t->foreign('tiktok_order_id')->references('id')->on('tiktok_orders')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_order_products');
        Schema::dropIfExists('tiktok_orders');
    }
};
