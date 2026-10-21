<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ch_listing_variation_prices')) {
            Schema::create('ch_listing_variation_prices', function (Blueprint $table) {
                $table->id();
                $table->string('channel', 32);
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('product_id');
                $table->string('sku', 100);
                $table->decimal('price', 15, 4);
                $table->timestamps();
                $table->unique(['channel', 'store_id', 'product_id', 'sku'], 'ch_listing_variation_prices_unique');
                $table->index(['product_id'], 'ch_listing_variation_prices_product_id_index');
            });
        }

        if (! Schema::hasColumn('ch_lazada_listing_variations', 'price')) {
            return;
        }

        $now = now();
        $rows = [];
        foreach (DB::table('ch_lazada_listing_variations as lv')
            ->join('ch_lazada_listings as l', 'l.id', '=', 'lv.lazada_listing_id')
            ->join('product_variation_values as pov', 'pov.product_variation_value_id', '=', 'lv.product_variation_value_id')
            ->whereNotNull('lv.price')->whereNotNull('l.store_id')->whereNotNull('l.product_id')
            ->where('pov.sku', '!=', '')
            ->get(['l.store_id', 'l.product_id', 'pov.sku', 'lv.price']) as $r) {
            $key = 'lazada|' . $r->store_id . '|' . $r->product_id . '|' . strtolower(trim((string) $r->sku));
            $rows[$key] = [
                'channel' => 'lazada', 'store_id' => (int) $r->store_id, 'product_id' => (int) $r->product_id,
                'sku' => strtolower(trim((string) $r->sku)), 'price' => $r->price, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('ch_listing_variation_prices')->insertOrIgnore($chunk);
        }

        Schema::table('ch_lazada_listing_variations', fn (Blueprint $t) => $t->dropColumn('price'));
    }

    public function down(): void
    {
        if (Schema::hasTable('ch_lazada_listing_variations') && ! Schema::hasColumn('ch_lazada_listing_variations', 'price')) {
            Schema::table('ch_lazada_listing_variations', fn (Blueprint $t) => $t->decimal('price', 15, 4)->nullable()->after('sku_id'));
        }
        if (! Schema::hasTable('ch_listing_variation_prices')) {
            return;
        }

        foreach (DB::table('ch_listing_variation_prices')->where('channel', 'lazada')->get() as $p) {
            $listingId = DB::table('ch_lazada_listings')->where('store_id', $p->store_id)->where('product_id', $p->product_id)->value('id');
            $povId = DB::table('product_variation_values')->where('product_id', $p->product_id)->whereRaw('LOWER(TRIM(sku)) = ?', [$p->sku])->value('product_variation_value_id');
            if ($listingId && $povId) {
                DB::table('ch_lazada_listing_variations')->updateOrInsert(
                    ['lazada_listing_id' => $listingId, 'product_variation_value_id' => $povId],
                    ['price' => $p->price]
                );
            }
        }

        Schema::dropIfExists('ch_listing_variation_prices');
    }
};
