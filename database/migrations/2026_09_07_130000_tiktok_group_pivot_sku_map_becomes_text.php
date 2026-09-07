<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tiktok_product_group_products.tiktok_sku_id was a varchar(255) from the
 * day it held one id. Since 2026-08-26 it holds the seller_sku => id map
 * the stock and price pushes read (a TikTok SKU id is nineteen digits, so
 * seven variations already pass 255), and the map repair writes the same
 * map to the listing row, which has been TEXT since then. Live, 2026-09-07:
 * "Data too long for column 'tiktok_sku_id'" stopped every TikTok stock
 * push. The pivot column now matches the listing's.
 */
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
