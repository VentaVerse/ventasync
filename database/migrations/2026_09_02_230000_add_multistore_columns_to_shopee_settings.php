<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-store Shopee, stage S1 (#194, umbrella #180): shopee_settings
 * stops being a singleton and becomes one row per store, the shape
 * venta_settings has had all along. The existing row becomes store #1
 * exactly as it is - credentials, tokens and cadence stamps untouched,
 * so nothing re-authorizes.
 *
 * Only the two columns a store list needs land here. The setting_id
 * columns on the data tables are S2's migration; keeping S1 pure means
 * a rollback of either stage stands alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->string('store_name')->nullable()->after('id');
            $table->boolean('enabled')->default(true)->after('store_name');
        });

        // The row that was "the" Shopee shop keeps that role by name.
        DB::table('shopee_settings')->whereNull('store_name')
            ->orderBy('id')->limit(1)->update(['store_name' => 'Main store']);
    }

    public function down(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->dropColumn(['store_name', 'enabled']);
        });
    }
};
