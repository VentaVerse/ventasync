<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_vendors', function (Blueprint $table) {
            $table->integer('product_option_value_id')->nullable()->after('product_id');

            $table->dropUnique(['product_id', 'vendor_id']);
            $table->unique(['product_id', 'vendor_id', 'product_option_value_id'], 'pv_product_vendor_option_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_vendors', function (Blueprint $table) {
            $table->dropUnique('pv_product_vendor_option_unique');
            $table->unique(['product_id', 'vendor_id']);
            $table->dropColumn('product_option_value_id');
        });
    }
};
