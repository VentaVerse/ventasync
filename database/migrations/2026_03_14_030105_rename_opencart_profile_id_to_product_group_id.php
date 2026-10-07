<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opencart_product_group_products', function (Blueprint $table) {
            $table->renameColumn('opencart_profile_id', 'opencart_product_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('opencart_product_group_products', function (Blueprint $table) {
            $table->renameColumn('opencart_product_group_id', 'opencart_profile_id');
        });
    }
};
