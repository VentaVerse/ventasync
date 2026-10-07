<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['shopee_listings', 'lazada_products', 'tiktok_listings', 'venta_listings', 'woocommerce_listings'];

    private const DIMENSIONS = ['package_length', 'package_width', 'package_height'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (self::DIMENSIONS as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $t->decimal($column, 10, 2)->nullable()->change();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (self::DIMENSIONS as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $t->unsignedInteger($column)->nullable()->change();
                    }
                }
            });
        }
    }
};
