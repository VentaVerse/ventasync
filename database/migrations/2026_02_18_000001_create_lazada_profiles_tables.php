<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lazada_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->unsignedInteger('catalog_category_id');
            $table->unsignedInteger('manufacturer_id')->nullable();
            $table->unsignedBigInteger('lazada_category_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('brand_name_override', 255)->nullable();
            $table->timestamps();

            $table->index(['catalog_category_id']);
        });

        Schema::create('lazada_profile_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lazada_profile_id');
            $table->string('attribute_key', 191);
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['lazada_profile_id', 'attribute_key'], 'lazada_profile_attr_unique');
            $table->foreign('lazada_profile_id')
                ->references('id')
                ->on('lazada_profiles')
                ->onDelete('cascade');
        });

        Schema::table('lazada_products', function (Blueprint $table) {
            $table->unsignedBigInteger('lazada_profile_id')->nullable()->after('brand_name_override');
            $table->foreign('lazada_profile_id')
                ->references('id')
                ->on('lazada_profiles')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            $table->dropForeign(['lazada_profile_id']);
            $table->dropColumn('lazada_profile_id');
        });

        Schema::dropIfExists('lazada_profile_attributes');
        Schema::dropIfExists('lazada_profiles');
    }
};
