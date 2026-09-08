<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Shopee listing gains its own list of images (the owner, 2026-09-08:
 * "make this draggable to arrange, and can open up media library").
 *
 * Empty means what an empty Content field means: this listing follows the
 * catalog product, main image first, then the gallery in its own order. A
 * filled column is the listing's own list, in the order it goes up, and
 * follows nobody after that. It holds storage paths, the same strings the
 * catalog product's image columns hold, so the push resolves them exactly
 * as it always did.
 *
 * Shopee first (the owner's own order); the other four channels take the same
 * column when they take the card. See extensions/CHANNEL-CONTRACT.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_listings', function (Blueprint $table) {
            if (! Schema::hasColumn('shopee_listings', 'image_order')) {
                $table->json('image_order')->nullable()->after('attribute_values');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shopee_listings', function (Blueprint $table) {
            if (Schema::hasColumn('shopee_listings', 'image_order')) {
                $table->dropColumn('image_order');
            }
        });
    }
};
