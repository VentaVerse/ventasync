<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One group per product PER STORE on Lazada and TikTok, as on Shopee and
 * Venta. Their groups carry the store and the pivot does not, so a unique
 * key on the pivot's product_id made a product grouped on one store
 * impossible to group on any other store of the same channel. The rule
 * is kept per store by OneGroupRule. A plain index keeps lookups by
 * product fast once the unique one is gone.
 */
return new class extends Migration
{
    private const PIVOTS = ['lazada_product_group_products', 'tiktok_product_group_products'];

    public function up(): void
    {
        foreach (self::PIVOTS as $pivot) {
            if (! Schema::hasTable($pivot)) {
                continue;
            }
            if (! Schema::hasIndex($pivot, $pivot . '_product_id_index')) {
                Schema::table($pivot, fn (Blueprint $table) => $table->index('product_id', $pivot . '_product_id_index'));
            }
            if (Schema::hasIndex($pivot, $pivot . '_one_group')) {
                Schema::table($pivot, fn (Blueprint $table) => $table->dropUnique($pivot . '_one_group'));
            }
        }
    }

    public function down(): void
    {
        // Restored only where no product has since joined a group on a second store.
        foreach (self::PIVOTS as $pivot) {
            if (! Schema::hasTable($pivot) || Schema::hasIndex($pivot, $pivot . '_one_group')) {
                continue;
            }
            $shared = DB::table($pivot)->select('product_id')->groupBy('product_id')->havingRaw('COUNT(*) > 1')->exists();
            if (! $shared) {
                Schema::table($pivot, fn (Blueprint $table) => $table->unique('product_id', $pivot . '_one_group'));
            }
        }
    }
};
