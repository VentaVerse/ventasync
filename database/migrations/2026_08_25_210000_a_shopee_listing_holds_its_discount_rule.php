<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
