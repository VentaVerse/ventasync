<?php

namespace App\Support\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * The catalog product's images as public URLs, main image first, in the
 * gallery's own order, with nothing repeated. This is the list every
 * channel push sends (each trims it to its own limit), so the "Images
 * that go up" card on every listing page reads the same list.
 */
class ProductImages
{
    /** @return list<string> */
    public static function urls(int $productId): array
    {
        $pfx = (string) config('catalog.prefix');
        $paths = [];

        $main = DB::table($pfx . 'product')->where('product_id', $productId)->value('image');
        if ($main !== null && trim((string) $main) !== '') {
            $paths[] = trim((string) $main);
        }
        foreach (DB::table($pfx . 'product_image')->where('product_id', $productId)->orderBy('sort_order')->pluck('image') as $img) {
            if ($img !== null && trim((string) $img) !== '') {
                $paths[] = trim((string) $img);
            }
        }

        return array_values(array_map([self::class, 'url'], array_values(array_unique($paths))));
    }

    /** The public URL of one stored image path. */
    public static function url(string $path): string
    {
        $segments = explode('/', ltrim($path, '/'));

        return asset('storage/' . implode('/', array_map('rawurlencode', $segments)));
    }
}
