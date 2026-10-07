<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id')->unique();

            $table->unsignedBigInteger('shopee_category_id')->nullable();
            $table->unsignedBigInteger('shopee_brand_id')->nullable();
            $table->json('logistic_ids')->nullable();
            $table->decimal('markup_percent', 8, 2)->nullable();
            $table->decimal('markup_fixed', 12, 2)->nullable();

            $table->string('item_name', 255)->nullable();
            $table->text('description')->nullable();
            $table->decimal('weight', 8, 3)->nullable();
            $table->unsignedInteger('package_length')->nullable();
            $table->unsignedInteger('package_width')->nullable();
            $table->unsignedInteger('package_height')->nullable();

            $table->timestamp('last_pushed_at')->nullable();
            $table->string('last_push_source', 64)->nullable();
            $table->json('last_push_settings')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_listings');
    }
};
