<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The storefront's listing record (#171), on the shared contract (#97):
 * one row per product per STORE, because Venta is multi-store and each
 * store is a separate live shop. It holds what the marketplaces' records
 * hold, minus what the storefront has no notion of (no category attribute
 * tree, no courier list): the price rule, the content overrides, the
 * status mirror, and the provenance of the last push from this page.
 *
 * What is deliberately NOT here, per the contract: the storefront's
 * product id, which venta_product_links already holds and which every
 * push and reconcile writes; price and quantity as pushed values, which
 * stay core price through the rule and live stock; and sku or model,
 * which are never overridable because reconcile matches by SKU.
 *
 * Content columns are nullable and NULL means "never set by the
 * operator", so an empty field follows the catalogue and Accept (#100)
 * can tell an operator's wording from an untouched one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('venta_listings')) {
            return;
        }

        Schema::create('venta_listings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('venta_setting_id');
            $t->unsignedInteger('product_id');

            // The price rule. Core price through this at every push.
            $t->decimal('markup_percent', 8, 2)->nullable();
            $t->decimal('markup_fixed', 12, 2)->nullable();

            // Content overrides. NULL follows the catalogue.
            $t->string('name', 255)->nullable();
            $t->text('description')->nullable();
            $t->string('meta_title', 255)->nullable();
            $t->string('meta_description', 500)->nullable();

            // The status mirror (#166): active, inactive, missing; null
            // until first checked. Refreshed by this page's own read on
            // load and written through by every push and toggle.
            $t->string('live_status', 16)->nullable()->index();
            $t->timestamp('live_checked_at')->nullable();
            $t->decimal('live_price', 12, 2)->nullable();
            $t->integer('live_quantity')->nullable();

            // Provenance of the last push made FROM THIS PAGE. Group pushes
            // keep their own per-group history on the pivots.
            $t->timestamp('last_pushed_at')->nullable();
            $t->string('last_push_source', 64)->nullable();
            $t->json('last_push_settings')->nullable();

            $t->timestamps();

            $t->unique(['venta_setting_id', 'product_id'], 'venta_listing_store_product_unique');
            $t->foreign('venta_setting_id')->references('id')->on('venta_settings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_listings');
    }
};
