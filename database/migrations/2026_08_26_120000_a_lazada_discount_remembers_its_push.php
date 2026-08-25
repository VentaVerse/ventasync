<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lazada holds a sale on each SKU and reports no promotion id back, so
 * the listing record could not say whether its rule had ever reached
 * Lazada, and the Discount card offered "End discount now" on a rule
 * that was only saved (critique 2026-08-25). The push now stamps when
 * it succeeded; End clears it with the rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            $table->timestamp('sale_pushed_at')->nullable()->after('sale_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            $table->dropColumn('sale_pushed_at');
        });
    }
};
