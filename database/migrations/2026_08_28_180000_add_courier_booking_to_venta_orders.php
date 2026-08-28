<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The courier booking on a storefront order, mirrored from the storefront
 * (#168). The storefront books a parcel with QuadX, SPX or LBC through its
 * courier-fulfillment extension and keeps four columns for it; the ERP now
 * books through the same API and needs the same four, so a row can say who
 * is collecting and under which tracking number without opening the
 * storefront. Written from the booking response the moment the ERP books,
 * and from the order payload on every pull once the storefront carries the
 * booking in it (#169). All nullable: an order with no API booking, or one
 * fetched before this shipped, simply has none.
 */
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
