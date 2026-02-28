<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lazada_order_products', function (Blueprint $table) {
            if (!Schema::hasColumn('lazada_order_products', 'item_price')) {
                $table->decimal('item_price', 12, 2)->nullable()->after('quantity');
            }
            if (!Schema::hasColumn('lazada_order_products', 'paid_price')) {
                $table->decimal('paid_price', 12, 2)->nullable()->after('item_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lazada_order_products', function (Blueprint $table) {
            if (Schema::hasColumn('lazada_order_products', 'item_price')) {
                $table->dropColumn('item_price');
            }
            if (Schema::hasColumn('lazada_order_products', 'paid_price')) {
                $table->dropColumn('paid_price');
            }
        });
    }
};
