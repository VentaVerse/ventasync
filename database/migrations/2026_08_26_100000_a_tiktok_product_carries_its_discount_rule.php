<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The TikTok half of #146 (quest 2). Shopee and Lazada keep their
 * discount rule on the channel's per-product record; TikTok never had
 * one - whether a product is ON TikTok is DERIVED across the group
 * pivots, and that stays true. This table is NOT that truth. It holds
 * only per-product facts with no other home, starting with the
 * discount rule: a percent off and a window, plus the id of the TikTok
 * promotion activity the product joined. TikTok's DIRECT_DISCOUNT
 * applies the percent to the live price server-side, so no price is
 * ever stored or computed here.
 *
 * One activity per window, shared: like the Shopee half, every listing
 * pushed for an identical window joins the same activity object.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id')->unique();
            $table->decimal('sale_percent', 5, 2)->nullable();
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();
            $table->string('tiktok_activity_id', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_listings');
    }
};
