<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Shopee actually credited to the wallet for an order (the owner,
 * 2026-10-01): the ESCROW_VERIFIED_ADD amount Sync Payouts matches. Set
 * beside the escrow Shopee promised, it is how a store's Payouts page
 * tells "Paid" from "Paid short". Shopee only: Lazada and TikTok report an
 * order's payout and its fees from the same finance lines, so there is no
 * second figure there to compare.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shopee_orders', 'payout_amount')) {
            return;
        }

        Schema::table('shopee_orders', function (Blueprint $table) {
            $table->decimal('payout_amount', 12, 2)->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('shopee_orders', 'payout_amount')) {
            Schema::table('shopee_orders', function (Blueprint $table) {
                $table->dropColumn('payout_amount');
            });
        }
    }
};
