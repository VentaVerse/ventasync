<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            if (! Schema::hasColumn('lazada_products', 'item_name')) {
                $table->string('item_name')->nullable()->after('product_id');
            }
            if (! Schema::hasColumn('lazada_products', 'description')) {
                $table->text('description')->nullable()->after('item_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            foreach (['item_name', 'description'] as $column) {
                if (Schema::hasColumn('lazada_products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
