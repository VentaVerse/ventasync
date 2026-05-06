<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_order_products', function (Blueprint $table) {
            $table->unsignedBigInteger('erp_order_product_id')->nullable()->after('shopify_order_id_local')->index();
        });
    }

    public function down(): void
    {
        Schema::table('shopify_order_products', function (Blueprint $table) {
            $table->dropColumn('erp_order_product_id');
        });
    }
};
