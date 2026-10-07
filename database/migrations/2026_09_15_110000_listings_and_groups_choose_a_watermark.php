<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LISTINGS = ['shopee_listings', 'lazada_products', 'tiktok_listings', 'venta_listings', 'woocommerce_listings'];

    private const GROUPS = ['shopee_product_groups', 'lazada_product_groups', 'tiktok_product_groups', 'venta_product_groups', 'woocommerce_product_groups'];

    public function up(): void
    {
        foreach (self::LISTINGS as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'watermark_template_id')) {
                    $t->unsignedBigInteger('watermark_template_id')->nullable();
                }
                if (! Schema::hasColumn($table, 'watermark_all_images')) {
                    $t->boolean('watermark_all_images')->default(false);
                }
            });
        }
        foreach (self::GROUPS as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'watermark_template_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('watermark_template_id')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::LISTINGS as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (['watermark_template_id', 'watermark_all_images'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $t->dropColumn($column);
                    }
                }
            });
        }
        foreach (self::GROUPS as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'watermark_template_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('watermark_template_id');
                });
            }
        }
    }
};
