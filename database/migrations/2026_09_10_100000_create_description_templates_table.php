<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Description templates (the owner, 2026-09-07): a named block of text a store
 * puts above or below a product's description, so a returns policy or a
 * warranty note lives in one place instead of on two thousand products.
 *
 * One table for every channel rather than five, because the shape is the
 * same everywhere and a template is not marketplace-specific: it is the
 * store's own words. The store is named by (integration, store_id), the
 * same pair the rest of the core uses for a store.
 *
 * A listing points at one for its prefix and one for its suffix; neither is
 * required, and None is the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('description_templates')) {
            Schema::create('description_templates', function (Blueprint $table) {
                $table->id();
                $table->string('integration', 32);
                $table->unsignedBigInteger('store_id');
                $table->string('name', 128);
                $table->text('body')->nullable();
                $table->timestamps();
                $table->index(['integration', 'store_id']);
            });
        }

        // One prefix and one suffix per listing, on every channel that has
        // a listing table. Guarded so a tier that already has them is left
        // alone, and so a channel added later can join by re-running this.
        foreach ([
            'shopee_listings', 'lazada_products', 'tiktok_listings',
            'venta_listings', 'woocommerce_listings',
        ] as $listings) {
            if (! Schema::hasTable($listings)) {
                continue;
            }
            Schema::table($listings, function (Blueprint $table) use ($listings) {
                if (! Schema::hasColumn($listings, 'description_prefix_id')) {
                    $table->unsignedBigInteger('description_prefix_id')->nullable();
                }
                if (! Schema::hasColumn($listings, 'description_suffix_id')) {
                    $table->unsignedBigInteger('description_suffix_id')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'shopee_listings', 'lazada_products', 'tiktok_listings',
            'venta_listings', 'woocommerce_listings',
        ] as $listings) {
            if (! Schema::hasTable($listings)) {
                continue;
            }
            Schema::table($listings, function (Blueprint $table) use ($listings) {
                foreach (['description_prefix_id', 'description_suffix_id'] as $column) {
                    if (Schema::hasColumn($listings, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
        Schema::dropIfExists('description_templates');
    }
};
