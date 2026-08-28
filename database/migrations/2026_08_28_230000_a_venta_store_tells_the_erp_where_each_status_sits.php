<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The storefront's own placement of each of its order statuses (#167).
 *
 * A Venta store places every status in one of its queue tabs (unpaid,
 * to_pack, to_handover, shipping, failed, lost_damaged, delivered,
 * cancelled) from its own settings, and reports that placement on
 * GET /order-statuses. Until now the ERP guessed the step from the
 * status NAME through a list copied from one store, and a store whose
 * names or placements differ filed its orders in the wrong step: a
 * booked order that the storefront had moved to To Handover stayed in
 * To Pack here. This table is the store's answer, refreshed on every
 * pull and every single-order re-read, one row per status per store.
 *
 * Separate from venta_order_status_map on purpose: that table is the
 * operator's mapping of storefront statuses onto ERP order statuses and
 * is edited by hand; this one is the store's own fact and is overwritten
 * by the store.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('venta_status_placements')) {
            return;
        }

        Schema::create('venta_status_placements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('venta_setting_id');
            $t->unsignedInteger('venta_status_id');
            $t->string('venta_status_name', 64)->default('');
            $t->string('placement', 32)->nullable();
            $t->timestamp('fetched_at')->nullable();
            $t->timestamps();

            $t->unique(['venta_setting_id', 'venta_status_id'], 'venta_status_placement_unique');
            $t->foreign('venta_setting_id')->references('id')->on('venta_settings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_status_placements');
    }
};
