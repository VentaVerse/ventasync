<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_order_products', function (Blueprint $table) {
            $table->string('image_url', 500)->nullable()->after('variant_title');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_order_products', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
