<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A variation a store does not carry is no longer an error, so the
 * mismatch errors already written stop showing on the listings.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['shopee_product_links', 'lazada_products'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'last_sync_error_code')) {
                continue;
            }
            DB::table($table)->where('last_sync_error_code', 'VARIATIONS_MISMATCH')->update([
                'last_sync_ok' => null,
                'last_sync_error_code' => null,
                'last_sync_error_message' => null,
            ]);
        }
    }

    public function down(): void
    {
        // The errors described a rule that no longer holds; nothing to restore.
    }
};
