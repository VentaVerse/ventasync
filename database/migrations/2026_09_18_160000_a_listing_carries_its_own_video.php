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
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'video_path')) {
                    $t->string('video_path', 512)->nullable();
                }
                if (! Schema::hasColumn($table, 'video_off')) {
                    $t->boolean('video_off')->default(false);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::LISTINGS as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (['video_path', 'video_off'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $t->dropColumn($column);
                    }
                }
            });
        }
    }
};
