<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('venta_listings') && ! Schema::hasColumn('venta_listings', 'venta_category_id')) {
            Schema::table('venta_listings', function (Blueprint $table) {
                $table->unsignedBigInteger('venta_category_id')->nullable()->after('product_id');
            });
        }
        if (Schema::hasTable('woocommerce_listings') && ! Schema::hasColumn('woocommerce_listings', 'woo_category_id')) {
            Schema::table('woocommerce_listings', function (Blueprint $table) {
                $table->unsignedBigInteger('woo_category_id')->nullable()->after('product_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('venta_listings', 'venta_category_id')) {
            Schema::table('venta_listings', fn (Blueprint $table) => $table->dropColumn('venta_category_id'));
        }
        if (Schema::hasColumn('woocommerce_listings', 'woo_category_id')) {
            Schema::table('woocommerce_listings', fn (Blueprint $table) => $table->dropColumn('woo_category_id'));
        }
    }
};
