<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quest 2, Shopee half (#146). The same rule Lazada carries - percent
 * off with a window, never a stored price - plus one Shopee-specific
 * fact: which of Shopee's NAMED discount objects currently carries this
 * listing. Shopee wants a promotion object with a server-side window;
 * VentaSync manages one object per window and listings join it, so ten
 * listings on one sale share one object instead of spawning ten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_listings', function (Blueprint $table) {
            $table->decimal('sale_percent', 6, 2)->nullable()->after('markup_fixed');
            $table->timestamp('sale_starts_at')->nullable()->after('sale_percent');
            $table->timestamp('sale_ends_at')->nullable()->after('sale_starts_at');
            $table->unsignedBigInteger('shopee_discount_id')->nullable()->after('sale_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_listings', function (Blueprint $table) {
            $table->dropColumn(['sale_percent', 'sale_starts_at', 'sale_ends_at', 'shopee_discount_id']);
        });
    }
};
