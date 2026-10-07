<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_product_group_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopee_product_group_id');
            $table->unsignedInteger('product_id');
            $table->string('shopee_item_id', 64)->nullable();
            $table->string('sync_status', 20)->default('pending');
            $table->timestamp('last_pushed_at')->nullable();
            $table->text('push_error')->nullable();

            $table->foreign('shopee_product_group_id')
                ->references('id')->on('shopee_product_groups')
                ->onDelete('cascade');

            $table->unique(['shopee_product_group_id', 'product_id'], 'spgp_group_product_unique');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_product_group_products');
    }
};
