<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $order = config('catalog.prefix') . 'order';
        if (Schema::hasTable($order) && !Schema::hasColumn($order, 'customer_group')) {
            Schema::table($order, function (Blueprint $t) {
                $t->string('customer_group', 64)->nullable()->after('customer_group_id');
            });
        }
        if (Schema::hasTable('venta_orders') && !Schema::hasColumn('venta_orders', 'customer_group')) {
            Schema::table('venta_orders', function (Blueprint $t) {
                $t->string('customer_group', 64)->nullable()->after('customer_email');
            });
        }
    }

    public function down(): void
    {
        $order = config('catalog.prefix') . 'order';
        if (Schema::hasTable($order) && Schema::hasColumn($order, 'customer_group')) {
            Schema::table($order, fn (Blueprint $t) => $t->dropColumn('customer_group'));
        }
        if (Schema::hasTable('venta_orders') && Schema::hasColumn('venta_orders', 'customer_group')) {
            Schema::table('venta_orders', fn (Blueprint $t) => $t->dropColumn('customer_group'));
        }
    }
};
