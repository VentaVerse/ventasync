<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One word per state.
 *
 * shopee_product_group_products.sync_status carried five values for four
 * states: 'synced' and 'pushed' both meant "this product is on Shopee". They
 * stayed interchangeable only because nothing read either of them, so the
 * first query written as where('sync_status', 'pushed') would have silently
 * missed every row the Match-ID path and the read-time back-fill had touched.
 *
 * The writers now all say 'pushed'. This brings the rows already stored into
 * line so the ambiguity cannot be rediscovered later.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('shopee_product_group_products')
            ->where('sync_status', 'synced')
            ->update(['sync_status' => 'pushed']);
    }

    public function down(): void
    {
        // Deliberately not reversed. 'synced' and 'pushed' meant the same
        // thing, so restoring the split would recreate a defect rather than a
        // previous state, and nothing can tell which rows were originally
        // which.
    }
};
