<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tiktok_product_group_products') || ! Schema::hasColumn('tiktok_product_group_products', 'tiktok_sku_id')) {
            return;
        }
        Schema::table('tiktok_product_group_products', function (Blueprint $table) {
            $table->text('tiktok_sku_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tiktok_product_group_products') || ! Schema::hasColumn('tiktok_product_group_products', 'tiktok_sku_id')) {
            return;
        }
        Schema::table('tiktok_product_group_products', function (Blueprint $table) {
            $table->string('tiktok_sku_id')->nullable()->change();
        });
    }
};
