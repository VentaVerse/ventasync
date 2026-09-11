<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE LATEST PUSH ERROR, ON VENTA TOO.
 *
 * Shopee, Lazada, TikTok and WooCommerce listings each carry the last push
 * error and when it happened (2026-09-05, 2026-09-06). Venta's listing table
 * never got the pair, but the Push stock and Push price buttons write it
 * whenever the storefront refuses a product, so a refused push answered
 * with a server error instead of the refusal (live, 2026-09-11: "Unknown
 * column 'last_push_error'" on /channels/venta/3/listings/2115/push-stock).
 *
 * Same shape as the other four, guarded so a tier that already has either
 * column is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_listings', function (Blueprint $t) {
            if (! Schema::hasColumn('venta_listings', 'last_push_error')) {
                $t->string('last_push_error', 480)->nullable();
            }
            if (! Schema::hasColumn('venta_listings', 'last_push_failed_at')) {
                $t->timestamp('last_push_failed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('venta_listings', function (Blueprint $t) {
            foreach (['last_push_error', 'last_push_failed_at'] as $column) {
                if (Schema::hasColumn('venta_listings', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
