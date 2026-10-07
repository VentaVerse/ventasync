<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_product_group_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tiktok_product_group_id');
            $table->unsignedBigInteger('product_id');
            $table->string('tiktok_product_id')->nullable();
            $table->string('sync_status')->default('pending');
            $table->timestamp('last_pushed_at')->nullable();
            $table->text('push_error')->nullable();

            $table->foreign('tiktok_product_group_id')
                ->references('id')->on('tiktok_product_groups')
                ->onDelete('cascade');

            $table->unique(['tiktok_product_group_id', 'product_id'], 'tiktok_pgp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_product_group_products');
    }
};
