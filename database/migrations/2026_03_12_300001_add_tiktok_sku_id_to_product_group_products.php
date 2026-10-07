<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_product_group_products', function (Blueprint $table) {
            $table->string('tiktok_sku_id')->nullable()->after('tiktok_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_product_group_products', function (Blueprint $table) {
            $table->dropColumn('tiktok_sku_id');
        });
    }
};
