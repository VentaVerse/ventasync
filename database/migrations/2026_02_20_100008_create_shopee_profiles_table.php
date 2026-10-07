<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('catalog_category_ids')->nullable();
            $table->json('manufacturer_ids')->nullable();
            $table->unsignedBigInteger('shopee_category_id')->nullable();
            $table->decimal('markup_fixed', 12, 2)->nullable();
            $table->decimal('markup_percent', 8, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('shopee_profile_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopee_profile_id')->index();
            $table->string('attribute_key');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->foreign('shopee_profile_id')
                ->references('id')->on('shopee_profiles')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_profile_attributes');
        Schema::dropIfExists('shopee_profiles');
    }
};
