<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-store Lazada, stage 1 (the owner, 2026-09-05, following C): the same
 * conversion Shopee had in S4 (#194). lazada_settings stops being a
 * singleton and becomes one row per store - the shape venta_settings has
 * had all along. The existing row becomes store #1 exactly as it is:
 * credentials, tokens and cadence stamps untouched, so nothing
 * re-authorizes.
 *
 * Only the two columns a store list needs land here - a name and an
 * enabled flag. The per-store scoping of the routes, controllers and
 * sync services is the later stages; keeping this one pure means a
 * rollback stands alone. Every existing LazadaSetting::first() call keeps
 * returning this now-named, enabled row until those stages land, so the
 * channel does not break between them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('lazada_settings', 'store_name')) {
            return;
        }

        Schema::table('lazada_settings', function (Blueprint $table) {
            $table->string('store_name')->nullable()->after('id');
            $table->boolean('enabled')->default(true)->after('store_name');
        });

        // The row that was "the" Lazada shop keeps that role, named after
        // the account it authorized under so the operator recognises it.
        DB::table('lazada_settings')->whereNull('store_name')->orderBy('id')->get()
            ->each(function ($row) {
                DB::table('lazada_settings')->where('id', $row->id)->update([
                    'store_name' => $row->account ?: ($row->region ? 'Lazada ' . strtoupper($row->region) : 'Main store'),
                    'enabled' => true,
                ]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('lazada_settings', 'store_name')) {
            return;
        }
        Schema::table('lazada_settings', function (Blueprint $table) {
            $table->dropColumn(['store_name', 'enabled']);
        });
    }
};
