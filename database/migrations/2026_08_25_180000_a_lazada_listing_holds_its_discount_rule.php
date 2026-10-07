<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
