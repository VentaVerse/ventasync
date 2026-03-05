<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pedallion_settings', function (Blueprint $table) {
            $table->id();
            $table->string('base_url', 255)->default('https://api.pedallion.com/api/v1');
            $table->text('api_key')->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('logging_enabled')->default(false);
            $table->unsignedInteger('sync_last_days')->default(14);
            $table->timestamp('last_category_sync_at')->nullable();
            $table->timestamp('last_manufacturer_sync_at')->nullable();
            $table->timestamp('last_product_sync_at')->nullable();
            $table->timestamp('last_order_sync_at')->nullable();
            $table->timestamp('last_stock_push_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedallion_settings');
    }
};
