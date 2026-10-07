<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_product_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('venta_category_id')->nullable()->after('name');
            $table->decimal('markup_percent', 8, 2)->default(0)->after('manufacturer_ids');
            $table->decimal('markup_fixed', 12, 2)->default(0)->after('markup_percent');
        });
    }

    public function down(): void
    {
        Schema::table('venta_product_groups', function (Blueprint $table) {
            $table->dropColumn(['venta_category_id', 'markup_percent', 'markup_fixed']);
        });
    }
};
