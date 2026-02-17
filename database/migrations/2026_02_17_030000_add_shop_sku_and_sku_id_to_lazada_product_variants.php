<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_product_variants', function (Blueprint $table) {
            if (!Schema::hasColumn('lazada_product_variants', 'shop_sku')) {
                $table->string('shop_sku', 191)->nullable()->after('seller_sku');
            }
            if (!Schema::hasColumn('lazada_product_variants', 'sku_id')) {
                // Lazada sku_id is numeric; store as unsigned big int.
                $table->unsignedBigInteger('sku_id')->nullable()->after('shop_sku');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lazada_product_variants', function (Blueprint $table) {
            if (Schema::hasColumn('lazada_product_variants', 'sku_id')) {
                $table->dropColumn('sku_id');
            }
            if (Schema::hasColumn('lazada_product_variants', 'shop_sku')) {
                $table->dropColumn('shop_sku');
            }
        });
    }
};
