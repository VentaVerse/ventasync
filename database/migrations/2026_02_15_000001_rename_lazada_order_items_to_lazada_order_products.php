<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rename table to align terminology with the ERP domain ("products").
        if (Schema::hasTable('lazada_order_items') && !Schema::hasTable('lazada_order_products')) {
            Schema::rename('lazada_order_items', 'lazada_order_products');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('lazada_order_products') && !Schema::hasTable('lazada_order_items')) {
            Schema::rename('lazada_order_products', 'lazada_order_items');
        }
    }
};
