<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LISTINGS = [
        'shopee_listings',
        'lazada_products',
        'tiktok_listings',
        'venta_listings',
        'woocommerce_listings',
    ];

    public function up(): void
    {
        foreach (self::LISTINGS as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'image_off')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->json('image_off')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::LISTINGS as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'image_off')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('image_off');
            });
        }
    }
};
