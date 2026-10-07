<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_brands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->index();
            $table->unsignedBigInteger('brand_id');
            $table->string('name');
            $table->tinyInteger('status')->default(1);
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'brand_id'], 'shopee_brands_cat_brand_unique');
        });

        Schema::table('shopee_product_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('shopee_brand_id')->nullable()->after('shopee_category_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('shopee_product_groups', 'shopee_brand_id')) {
            Schema::table('shopee_product_groups', function (Blueprint $table) {
                $table->dropColumn('shopee_brand_id');
            });
        }
        Schema::dropIfExists('shopee_brands');
    }
};
