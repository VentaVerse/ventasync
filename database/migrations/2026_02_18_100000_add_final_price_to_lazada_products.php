<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            $table->decimal('total_price', 12, 2)->nullable()->after('markup_percent');
        });
    }

    public function down(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            $table->dropColumn('total_price');
        });
    }
};
