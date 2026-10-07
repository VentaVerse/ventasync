<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tiktok_settings', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 16)->default('live');

            $table->string('app_key', 64)->nullable();
            $table->text('app_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->string('shop_id', 64)->nullable();
            $table->string('shop_cipher', 255)->nullable();
            $table->string('shop_name', 255)->nullable();
            $table->string('redirect_uri', 255)->nullable();
            $table->string('region', 16)->nullable();

            $table->unsignedSmallInteger('sync_last_days')->default(15);
            $table->boolean('api_logging')->default(true);
            $table->timestamp('last_order_sync_at')->nullable();
            $table->timestamp('last_stock_push_at')->nullable();

            $table->string('sandbox_app_key', 64)->nullable();
            $table->text('sandbox_app_secret')->nullable();
            $table->text('sandbox_access_token')->nullable();
            $table->text('sandbox_refresh_token')->nullable();
            $table->timestamp('sandbox_expires_at')->nullable();
            $table->timestamp('sandbox_refresh_expires_at')->nullable();
            $table->string('sandbox_shop_id', 64)->nullable();
            $table->string('sandbox_shop_cipher', 255)->nullable();
            $table->string('sandbox_shop_name', 255)->nullable();
            $table->string('sandbox_redirect_uri', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_settings');
    }
};
