<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_logistics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('logistics_channel_id')->unique();
            $table->string('logistics_channel_name', 255);
            $table->boolean('cod_enabled')->default(false);
            $table->boolean('enabled')->default(false);
            $table->boolean('force_enable')->default(false);
            $table->string('fee_type', 50)->nullable();
            $table->json('weight_limit')->nullable();
            $table->json('item_max_dimension')->nullable();
            $table->json('volume_limit')->nullable();
            $table->unsignedBigInteger('mask_channel_id')->default(0);
            $table->text('logistics_description')->nullable();
            $table->boolean('support_pre_order')->default(false);
            $table->boolean('support_cross_border')->default(false);
            $table->json('raw_data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_logistics');
    }
};
