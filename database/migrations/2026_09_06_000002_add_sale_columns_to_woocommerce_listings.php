<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woocommerce_listings', function (Blueprint $t) {
            if (! Schema::hasColumn('woocommerce_listings', 'sale_percent')) {
                $t->decimal('sale_percent', 8, 2)->nullable()->after('markup_fixed');
            }
            if (! Schema::hasColumn('woocommerce_listings', 'sale_starts_at')) {
                $t->timestamp('sale_starts_at')->nullable()->after('sale_percent');
            }
            if (! Schema::hasColumn('woocommerce_listings', 'sale_ends_at')) {
                $t->timestamp('sale_ends_at')->nullable()->after('sale_starts_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('woocommerce_listings', function (Blueprint $t) {
            $t->dropColumn(['sale_percent', 'sale_starts_at', 'sale_ends_at']);
        });
    }
};
