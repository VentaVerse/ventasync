<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A picture for the thing that is actually sold.
 *
 * ON THE COMBINATION, NOT ON THE OPTION VALUE, and that is the whole decision
 * in this migration. A combination is the sellable unit: it carries the sku,
 * the quantity and the price, and it maps one-to-one onto a marketplace
 * variant. An option value is one axis of one - "Small", or "Red".
 *
 * Measured on this database at the time of writing: 264 combinations, of
 * which 258 join a single option value and 6 join two. Put the image on
 * product_option_value and a Size x Colour product can be told what "Small"
 * looks like and what "Red" looks like, but never what "Small / Red" looks
 * like - and Small/Red is what the marketplace sells and what the packer
 * pulls off the shelf. Apparel is exactly the case that wants a picture per
 * variation, and it is the case that storage cannot serve.
 *
 * For single-axis products a combination is 1:1 with its option value, so
 * nothing is lost by putting it here for the simple case either.
 *
 * Nullable, and null MEANS SOMETHING: inherit the product's primary image.
 * It is deliberately not backfilled with a copy of the parent's picture,
 * because a copy silently stops tracking the parent the day somebody
 * replaces it. See the fulfilment side, which already resolves an order line
 * to the product image and will prefer this when it is set.
 *
 * A relative path under storage/app/public/catalog/..., the same convention
 * the rest of the catalogue uses (config/catalog.php), served through the
 * storage:link symlink and rendered with asset('storage/' . $path).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_combinations', function (Blueprint $table) {
            $table->string('image', 255)->nullable()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('product_option_combinations', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
