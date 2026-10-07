<?php

namespace App\Integrations\Listings;

// Round to each marketplace's own places only when sending (Shopee 1/2, Lazada 2/3, TikTok 2/3); never when storing.
final class ParcelPrecision
{
    public const SHOPEE = ['dimension' => 1, 'weight' => 2];

    public const LAZADA = ['dimension' => 2, 'weight' => 3];

    public const TIKTOK = ['dimension' => 2, 'weight' => 3];

    public static function at(mixed $value, int $places, float $fallback = 0.0): float
    {
        $number = (float) ($value ?? 0);
        if ($number <= 0) {
            $number = $fallback;
        }

        return round($number, $places);
    }

    public static function of(mixed $own, mixed $catalog, int $places): float
    {
        $number = (float) ($own ?? 0);
        if ($number <= 0) {
            $number = (float) ($catalog ?? 0);
        }

        return round(max(0.0, $number), $places);
    }
}
