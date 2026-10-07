<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['shopee_listings', 'lazada_products', 'tiktok_listings', 'venta_listings', 'woocommerce_listings'];

    private const PARCEL = ['weight', 'package_length', 'package_width', 'package_height'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'price')) {
                    $t->decimal('price', 15, 4)->nullable();
                }
                if (! Schema::hasColumn($table, 'weight')) {
                    $t->decimal('weight', 8, 3)->nullable();
                }
                foreach (['package_length', 'package_width', 'package_height'] as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        $t->unsignedInteger($column)->nullable();
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
            $columns = $table === 'shopee_listings' ? ['price'] : array_merge(['price'], self::PARCEL);
            $columns = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
            if ($columns !== []) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($columns));
            }
        }
    }
};
