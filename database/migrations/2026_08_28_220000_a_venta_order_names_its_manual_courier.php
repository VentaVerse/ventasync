<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The third way a parcel leaves the storefront (#168 follow-up): a courier
 * the store does not integrate with, recorded by hand with a name and a
 * tracking number. courier_provider carries 'manual' for it, and this is
 * where the courier's name goes, because 'manual' is not a name a packer
 * can read on the row. Integrated bookings leave it null: their provider
 * key names them.
 */
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
