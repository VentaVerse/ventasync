<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = Schema::hasTable('lazada_order_products') ? 'lazada_order_products' : 'lazada_order_items';

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (!Schema::hasColumn($tableName, 'variation')) {
                $table->string('variation', 255)->nullable()->after('name');
            }
            if (!Schema::hasColumn($tableName, 'image')) {
                $table->string('image', 512)->nullable()->after('quantity');
            }
        });
    }

    public function down(): void
    {
        $tableName = Schema::hasTable('lazada_order_products') ? 'lazada_order_products' : 'lazada_order_items';
        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (Schema::hasColumn($tableName, 'variation')) {
                $table->dropColumn('variation');
            }
            if (Schema::hasColumn($tableName, 'image')) {
                $table->dropColumn('image');
            }
        });
    }
};
