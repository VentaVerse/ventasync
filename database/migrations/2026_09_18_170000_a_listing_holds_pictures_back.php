<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PICTURES THIS LISTING HOLDS BACK.
 *
 * A store refuses a set of pictures it thinks is too long, and only the store
 * knows that number. So every picture the listing has goes up, the store
 * answers, and the seller turns some off and sends again.
 *
 * Off is not gone. Removing a picture with the X takes it out of the
 * arrangement and the seller has to find it again to put it back; turning it
 * off leaves it where it is, ready to come back with one press. The paths
 * named here are the ones held back, in the same shape `image_order` already
 * uses.
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
