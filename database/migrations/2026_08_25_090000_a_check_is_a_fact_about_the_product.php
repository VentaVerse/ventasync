<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2 of the one-truth repointing: when "Check against Shopee" last
 * confirmed a product is a fact about the PRODUCT, not about the group
 * page the operator happened to run the check from. It moves onto the
 * listing record - the product-per-channel row every page reads - so a
 * check run anywhere is visible everywhere. The pivot's per-group
 * last_confirmed_at stops being written and is pruned after the soak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_listings', function (Blueprint $table) {
            $table->timestamp('last_checked_at')->nullable()->after('last_push_settings');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_listings', function (Blueprint $table) {
            $table->dropColumn('last_checked_at');
        });
    }
};
