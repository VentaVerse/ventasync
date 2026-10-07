<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('venta_orders') || Schema::hasColumn('venta_orders', 'courier_name')) {
            return;
        }

        Schema::table('venta_orders', function (Blueprint $t) {
            $t->string('courier_name', 120)->nullable()->after('courier_provider');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('venta_orders') && Schema::hasColumn('venta_orders', 'courier_name')) {
            Schema::table('venta_orders', fn (Blueprint $t) => $t->dropColumn('courier_name'));
        }
    }
};
