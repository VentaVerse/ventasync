<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opencart_settings', function (Blueprint $table) {
            $table->id();
            $table->string('store_name', 128)->default('');
            $table->string('base_url', 255);
            $table->text('api_key');
            $table->string('admin_path', 64)->default('admin');
            $table->string('admin_secret', 255)->default('');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_product_sync_at')->nullable();
            $table->timestamp('last_order_sync_at')->nullable();
            $table->timestamp('last_category_sync_at')->nullable();
            $table->timestamp('last_manufacturer_sync_at')->nullable();
            $table->unsignedInteger('last_order_page')->default(0);
            $table->json('sync_log')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opencart_settings');
    }
};
