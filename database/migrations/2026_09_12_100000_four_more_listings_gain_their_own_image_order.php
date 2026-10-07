<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'lazada_products' => 'description',
        'tiktok_listings' => 'attribute_values',
        'venta_listings' => 'description',
        'woocommerce_listings' => 'description',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $after) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table, $after) {
                if (! Schema::hasColumn($table, 'image_order')) {
                    $t->json('image_order')->nullable()->after($after);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'image_order')) {
                    $t->dropColumn('image_order');
                }
            });
        }
    }
};
