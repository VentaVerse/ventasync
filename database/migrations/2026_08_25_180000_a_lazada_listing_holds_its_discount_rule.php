<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quest 2, first landing (#146): a time-bound discount as a RULE on the
 * listing - percent off with a window - never a stored price. The sale
 * price Lazada receives is computed from the same final price a push
 * would send, at the moment the discount is pushed, so a catalog price
 * change moves the discount with it. Lazada carries discounts as
 * SalePrice + SaleStartDate/SaleEndDate on the price update itself,
 * which is exactly the seller center's "Edit Special Price".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            $table->decimal('sale_percent', 6, 2)->nullable()->after('markup_percent');
            $table->timestamp('sale_starts_at')->nullable()->after('sale_percent');
            $table->timestamp('sale_ends_at')->nullable()->after('sale_starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            $table->dropColumn(['sale_percent', 'sale_starts_at', 'sale_ends_at']);
        });
    }
};
