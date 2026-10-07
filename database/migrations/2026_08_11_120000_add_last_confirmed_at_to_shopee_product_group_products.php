<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_product_group_products', function (Blueprint $table) {
            $table->timestamp('last_confirmed_at')->nullable()->after('last_pushed_at');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_product_group_products', function (Blueprint $table) {
            $table->dropColumn('last_confirmed_at');
        });
    }
};
