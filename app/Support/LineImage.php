<?php

namespace App\Support;

use App\Services\Media\ImageCache;
use Illuminate\Support\Facades\DB;

final class LineImage
{
    public static function url(?string $sku, ?string $storeImage = null, ?int $productId = null): ?string
    {
        $sku = trim((string) $sku);

        if ($sku !== '') {
            $own = DB::table('product_variations')->where('sku', $sku)
                ->whereNotNull('image')->where('image', '!=', '')->value('image');
            if ($own) {
                return self::address($own);
            }
        }

        $storeImage = trim((string) $storeImage);
        if ($storeImage !== '') {
            return $storeImage;
        }

        $path = $sku !== '' ? (CatalogImages::forSkus([$sku])[$sku] ?? null) : null;
        if (! $path && $productId) {
            $path = DB::table('products')->where('product_id', $productId)->value('image');
        }

        return $path ? self::address($path) : null;
    }

    private static function address(string $path): ?string
    {
        return ImageCache::appUrl($path, ImageCache::PREVIEW) ?? ImageCache::url($path, ImageCache::PREVIEW);
    }
}
