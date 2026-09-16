<?php

namespace App\Integrations\Listings;

/**
 * WHAT EACH MARKETPLACE WILL HOLD, at the boundary and nowhere else.
 *
 * The ERP stores one shape: kilograms to three places, centimetres to two,
 * in the catalog and in every store table. That is the seller's figure and
 * it is never rounded on the way in.
 *
 * Each marketplace then keeps a different number of places, checked against
 * their own seller centres rather than their docs, which understate it:
 *
 *   Shopee    dimensions 1 place,  weight 2 places, kilograms only
 *   Lazada    dimensions 2 places, weight 3 places, kilograms by default
 *   TikTok    dimensions 2 places, weight 3 places, or whole grams
 *
 * Rounding here, once, is the difference between a figure a seller typed
 * being kept and being quietly truncated: the previous code cast dimensions
 * with (int), so 10.5 cm reached Shopee as 10 cm and the seller was never
 * told. A marketplace's limit is the marketplace's business; throwing away a
 * value it would have accepted is ours.
 *
 * Nothing here converts units. Kilograms and centimetres go up as kilograms
 * and centimetres, which is what all three take, and a unit the seller could
 * choose would be a different feature on all three at once.
 */
final class ParcelPrecision
{
    /** Shopee keeps one place on a dimension and two on a weight. */
    public const SHOPEE = ['dimension' => 1, 'weight' => 2];

    /** Lazada keeps two places on a dimension and three on a weight. */
    public const LAZADA = ['dimension' => 2, 'weight' => 3];

    /** TikTok keeps two places on a dimension; its weight travels as whole grams. */
    public const TIKTOK = ['dimension' => 2, 'weight' => 3];

    /**
     * A measurement as that marketplace will hold it.
     *
     * A value at or below zero answers with the fallback, because a parcel of
     * zero is not a measurement and every push refuses one anyway; the
     * readiness check names it long before this is reached.
     */
    public static function at(mixed $value, int $places, float $fallback = 0.0): float
    {
        $number = (float) ($value ?? 0);
        if ($number <= 0) {
            $number = $fallback;
        }

        return round($number, $places);
    }

    /**
     * The first measurement that is actually set: the listing's own, else the
     * catalog's, rounded to what the marketplace keeps.
     */
    public static function of(mixed $own, mixed $catalog, int $places): float
    {
        $number = (float) ($own ?? 0);
        if ($number <= 0) {
            $number = (float) ($catalog ?? 0);
        }

        return round(max(0.0, $number), $places);
    }
}
