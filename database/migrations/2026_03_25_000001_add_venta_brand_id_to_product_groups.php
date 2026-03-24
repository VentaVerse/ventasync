<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_product_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('venta_brand_id')->nullable()->after('venta_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('venta_product_groups', function (Blueprint $table) {
            $table->dropColumn('venta_brand_id');
        });
    }
};
