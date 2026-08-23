<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card #97 phase 1 (Shopee) + #96 + #104: the listing record.
 *
 * A LISTING is one product on one channel. This row is what lets an
 * individual push work OUTSIDE the product groups: it holds the settings
 * the push needs (category, brand, couriers, markup) and the content the
 * operator explicitly wrote for Shopee, plus the provenance of the last
 * push - which settings went, from where, when.
 *
 * The defect this kills (#96, measured): an individual push had no
 * settings of its own, so it borrowed the FIRST product group whose
 * filter matched - and with every group's filters empty, that meant
 * primary-key order decided the price. All nine live groups are
 * catch-alls today, so every individual push was priced by group #1's
 * 50% markup, silently.
 *
 * Card rules honoured in the schema:
 * - NEVER STORED: price (stays core price + markup, so markup keeps
 *   meaning) and quantity (stays live; push-stock runs on cron).
 * - NEVER OVERRIDABLE: sku and model - reconcile matches BY SKU, so they
 *   have no columns here. The link table keeps the sku it was pushed with.
 * - Content columns are NULLABLE, and null means "never set by the
 *   operator", which is what lets Accept (#100) tell your work from
 *   untouched fields later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id')->unique();

            // Settings: what an individual push needs, owned by the listing.
            $table->unsignedBigInteger('shopee_category_id')->nullable();
            $table->unsignedBigInteger('shopee_brand_id')->nullable();
            $table->json('logistic_ids')->nullable();
            $table->decimal('markup_percent', 8, 2)->nullable();
            $table->decimal('markup_fixed', 12, 2)->nullable();

            // Content: only what the operator explicitly wrote for Shopee.
            $table->string('item_name', 255)->nullable();
            $table->text('description')->nullable();
            $table->decimal('weight', 8, 3)->nullable();
            $table->unsignedInteger('package_length')->nullable();
            $table->unsignedInteger('package_width')->nullable();
            $table->unsignedInteger('package_height')->nullable();

            // Provenance (#104): what the last push actually sent and where
            // its settings came from ("form", "listing", "group:Cables").
            $table->timestamp('last_pushed_at')->nullable();
            $table->string('last_push_source', 64)->nullable();
            $table->json('last_push_settings')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_listings');
    }
};
