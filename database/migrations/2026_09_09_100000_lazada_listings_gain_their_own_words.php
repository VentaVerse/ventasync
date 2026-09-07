<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Lazada listing gains its own title and description (the owner,
 * 2026-09-07, the one listing shape): every other channel's listing page
 * carries a Content card whose empty field follows the catalog product
 * and whose filled field follows nobody. Lazada had no columns to hold
 * one, so the catalog's words always went up. Empty on every existing
 * row, which is exactly "follows the catalog", so nothing changes for a
 * listing nobody edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            if (! Schema::hasColumn('lazada_products', 'item_name')) {
                $table->string('item_name')->nullable()->after('product_id');
            }
            if (! Schema::hasColumn('lazada_products', 'description')) {
                $table->text('description')->nullable()->after('item_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            foreach (['item_name', 'description'] as $column) {
                if (Schema::hasColumn('lazada_products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
