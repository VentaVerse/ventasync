<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A LISTING MAY CARRY ITS OWN VIDEO.
 *
 * The catalog's video is the source, as the picture library is, and a listing
 * sends it unless it says otherwise. What a listing says is here.
 *
 * It exists because the channels disagree and that is not an error in the
 * video. Ninety seconds is fine on TikTok and Lazada and refused by Shopee, so
 * the seller keeps their video on the two that take it and gives Shopee its
 * own shorter cut, leaving the catalog and the other channels alone.
 *
 *   video_path   null and video_off false  the catalog's video goes up
 *                a path                    this store sends that one instead
 *   video_off    true                      this store sends no video at all
 *
 * The two are separate because "follow the catalog" and "send nothing" are
 * different answers, and a single nullable path cannot tell them apart.
 */
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
