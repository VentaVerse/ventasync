<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shopee_settings')) {
            return;
        }

        Schema::create('shopee_settings', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 16)->default('sandbox');
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->text('partner_key')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('redirect_uri', 255)->nullable();
            $table->string('region', 16)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_settings');
    }
};
