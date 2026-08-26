<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer group an order was placed under, as a NAME (#129, decided
 * 2026-08-26). No customer-group entity in the ERP and no mapping: the
 * storefront sends the group's name with the order and the sync writes
 * it onto the order. One text column on the core order row, so the
 * margin report reads one place for every storefront; marketplace orders
 * leave it empty. customer_group_id stays as the channel-native id it
 * always held (#132).
 */
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
