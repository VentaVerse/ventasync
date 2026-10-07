<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('venta_orders') || Schema::hasColumn('venta_orders', 'courier_provider')) {
            return;
        }

        Schema::table('venta_orders', function (Blueprint $t) {
            $t->string('courier_provider', 32)->nullable()->after('tracking_number');
            $t->string('courier_tracking_number', 100)->nullable()->after('courier_provider');
            $t->string('courier_status', 50)->nullable()->after('courier_tracking_number');
            $t->timestamp('courier_booked_at')->nullable()->after('courier_status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('venta_orders') || ! Schema::hasColumn('venta_orders', 'courier_provider')) {
            return;
        }

        Schema::table('venta_orders', fn (Blueprint $t) => $t->dropColumn([
            'courier_provider', 'courier_tracking_number', 'courier_status', 'courier_booked_at',
        ]));
    }
};
