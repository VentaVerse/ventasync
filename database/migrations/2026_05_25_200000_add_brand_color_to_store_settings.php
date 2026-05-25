<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('venta_settings') && !Schema::hasColumn('venta_settings', 'brand_color')) {
            Schema::table('venta_settings', function (Blueprint $table) {
                $table->string('brand_color', 7)->nullable()->after('store_name');
            });
        }

        if (Schema::hasTable('opencart_settings') && !Schema::hasColumn('opencart_settings', 'brand_color')) {
            Schema::table('opencart_settings', function (Blueprint $table) {
                $table->string('brand_color', 7)->nullable()->after('store_name');
            });
        }
    }

    public function down(): void
    {
        Schema::table('venta_settings', function (Blueprint $table) {
            $table->dropColumn('brand_color');
        });
        Schema::table('opencart_settings', function (Blueprint $table) {
            $table->dropColumn('brand_color');
        });
    }
};
