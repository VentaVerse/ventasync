<?php

namespace App\Integrations\Listings;

use App\Support\Catalog\ProductImages;

/**
 * THE ARRANGEABLE "IMAGES THAT GO UP" CARD, READ AND WRITTEN IN ONE PLACE.
 *
 * The card itself has been shared since 2026-09-08
 * (partials/channel-images-card). What was not shared was the handful of
 * lines on either side of it: what a controller must hand the card, and
 * what it may believe when the card is submitted back. Shopee grew both
 * first, and copying them into four more controllers is how five channels
 * end up arranging pictures four different ways.
 *
 * So both halves live here, and every channel calls the same two methods.
 *
 * The rule they enforce is the Content fields' rule, because it is the
 * same idea: an empty arrangement means THIS LISTING FOLLOWS THE CATALOG.
 * A list that says exactly what the catalog already says is empty in that
 * sense, and is stored as null, so agreeing with the catalog never freezes
 * a listing into a copy of it.
 */
final class ListingImages
{
    /**
     * What the card needs, ready to spread into a view's data.
     *
     * A refused save hands back what was ARRANGED rather than what is
     * stored, the same way a refused save hands back typed words; the
     * hidden input comes back as a JSON string, which is why old() is
     * decoded here rather than trusted as an array.
     *
     * @param  ?array<int, mixed>  $stored  the listing's own arrangement, or null to follow the catalog
     * @return array{tiles: list<array{path: string, url: string}>, catalogTiles: list<array{path: string, url: string}>, browseUrl: string}
     */
    public static function cardData(int $productId, ?array $stored): array
    {
        $submitted = null;
        $old = old('image_order');
        if ($old !== null && trim((string) $old) !== '') {
            $decoded = json_decode((string) $old, true);
            $submitted = is_array($decoded) ? $decoded : null;
        }

        return [
            'tiles' => ProductImages::tiles($productId, $submitted ?? $stored),
            'catalogTiles' => ProductImages::tiles($productId),
            'browseUrl' => route('products.images.browse'),
        ];
    }

    /**
     * What a submitted arrangement is worth.
     *
     * Every path is checked against the library on disk before it is
     * stored: a hidden input is client input, and this one ends up in a
     * file read at push time. Null means follow the catalog - which is
     * what an empty list, an unparseable one, and one that matches the
     * catalog exactly all mean.
     *
     * @param  ?string  $json  the hidden input's value, verbatim
     * @return ?array<int, string>
     */
    public static function submitted(int $productId, ?string $json): ?array
    {
        $decoded = json_decode((string) $json, true);
        if (! is_array($decoded)) {
            return null;
        }

        $accepted = ProductImages::acceptable($decoded);

        return ($accepted !== [] && ! ProductImages::followsCatalog($productId, $accepted)) ? $accepted : null;
    }
}
