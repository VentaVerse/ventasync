<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A NAMED MARK, KEPT IN THE CATALOG AND CHOSEN WHEREVER IT APPLIES.
 *
 * the owner, 2026-09-10: "This is part of Catalog. so inside catalog. Make it
 * a template CRUD. Then for every listing, and every group, they can
 * choose the template."
 *
 * This replaces the per-store settings block decided on 2026-09-07. A
 * store-level block meant one mark per store, so marking a clearance range
 * differently from everything else was impossible; a template makes that
 * ordinary, and one template can serve five stores instead of the same
 * picture being uploaded five times.
 *
 * The picture is a PATH INTO THE IMAGE LIBRARY, not a copy: the logo is
 * uploaded once and every template that wants it points at the same file,
 * so replacing it updates them all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watermark_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // The library path, the same string a product image column holds.
            $table->string('image_path');
            $table->string('position', 20)->default('bottom-right');
            // Percent of the picture's SHORTER edge, so a mark looks the
            // same size on a square photo and a tall one. 100 covers the
            // frame, which is how a full-frame template is expressed.
            $table->decimal('size_percent', 5, 2)->default(18);
            $table->decimal('margin_percent', 5, 2)->default(3);
            // 0 to 1, multiplied into the mark's own alpha.
            $table->decimal('opacity', 4, 3)->default(1);
            /* The cover image is the one a marketplace polices. Shopee
               permits a corner logo at 10% or less and refuses a busy
               watermark; the gallery carries text and graphics freely on
               both Shopee and Lazada. So this is off unless the seller
               says otherwise, and the form says why. */
            $table->boolean('stamp_main')->default(false);
            $table->timestamps();

            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watermark_templates');
    }
};
