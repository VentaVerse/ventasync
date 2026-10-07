<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $pfx = (string) config('catalog.prefix');
        $product = $pfx . 'product';
        if (Schema::hasTable($product)) {
            Schema::table($product, function (Blueprint $t) use ($product) {
                if (Schema::hasColumn($product, 'weight')) {
                    $t->decimal('weight', 8, 3)->default(0)->change();
                }
                foreach (['length', 'width', 'height'] as $column) {
                    if (Schema::hasColumn($product, $column)) {
                        $t->decimal($column, 10, 2)->default(0)->change();
                    }
                }
            });
        }

        $optionValue = $pfx . 'product_option_value';
        if (Schema::hasTable($optionValue) && Schema::hasColumn($optionValue, 'weight')) {
            Schema::table($optionValue, fn (Blueprint $t) => $t->decimal('weight', 8, 3)->default(0)->change());
        }
    }

    public function down(): void
    {
        $pfx = (string) config('catalog.prefix');
        $product = $pfx . 'product';
        if (Schema::hasTable($product)) {
            Schema::table($product, function (Blueprint $t) use ($product) {
                foreach (['weight', 'length', 'width', 'height'] as $column) {
                    if (Schema::hasColumn($product, $column)) {
                        $t->decimal($column, 15, 8)->default(0)->change();
                    }
                }
            });
        }

        $optionValue = $pfx . 'product_option_value';
        if (Schema::hasTable($optionValue) && Schema::hasColumn($optionValue, 'weight')) {
            Schema::table($optionValue, fn (Blueprint $t) => $t->decimal('weight', 15, 8)->default(0)->change());
        }
    }
};
