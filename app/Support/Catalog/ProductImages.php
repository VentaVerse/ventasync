<?php

namespace App\Support\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The images that go up, main image first, with nothing repeated.
 *
 * Two answers live here, and they are the same answer:
 *   - the catalog product's own images, in the gallery's order. This is
 *     what every channel push sends when the listing has said nothing;
 *   - the listing's own list, when it has one. Arranged on the listing
 *     page, it holds the same storage paths and may hold library images
 *     the catalog product does not carry.
 *
 * An empty own-list means "follows the catalog", the same rule the Content
 * fields keep (App\Integrations\Listings\ListingContent).
 */
class ProductImages
{
    /**
     * The storage paths that go up, in order.
     *
     * @param  ?array<int, mixed>  $own  the listing's own list, or null to follow the catalog
     * @return list<string>
     */
    public static function paths(int $productId, ?array $own = null): array
    {
        $chosen = self::clean($own ?? []);
        if ($chosen !== []) {
            return $chosen;
        }

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

        return array_values(array_unique($paths));
    }

    /**
     * The same list as public URLs, which is what a store that fetches the
     * images over the web is given.
     *
     * @param  ?array<int, mixed>  $own
     * @return list<string>
     */
    public static function urls(int $productId, ?array $own = null): array
    {
        return array_map([self::class, 'url'], self::paths($productId, $own));
    }

    /**
     * Path and URL together, which is what the "Images that go up" card
     * needs: it shows the URL and submits the path.
     *
     * @param  ?array<int, mixed>  $own
     * @return list<array{path: string, url: string}>
     */
    public static function tiles(int $productId, ?array $own = null): array
    {
        return array_map(
            fn (string $path) => ['path' => $path, 'url' => self::url($path)],
            self::paths($productId, $own)
        );
    }

    /**
     * What a submitted list is worth: strings only, trimmed, de-duplicated,
     * no traversal, and every path a file the image library actually holds.
     * A list that comes back the same as the catalog's own is not a list at
     * all - the caller stores null for it, so the listing keeps following
     * the catalog.
     *
     * @param  array<int, mixed>  $submitted
     * @return list<string>
     */
    public static function acceptable(array $submitted): array
    {
        $disk = Storage::disk('public');

        return array_values(array_filter(
            self::clean($submitted),
            fn (string $path) => $disk->exists($path)
        ));
    }

    /**
     * Whether a submitted list says nothing the catalog does not already
     * say, order included.
     *
     * @param  list<string>  $submitted
     */
    public static function followsCatalog(int $productId, array $submitted): bool
    {
        return $submitted === self::paths($productId);
    }

    /** The public URL of one stored image path. */
    public static function url(string $path): string
    {
        $segments = explode('/', ltrim($path, '/'));

        return asset('storage/' . implode('/', array_map('rawurlencode', $segments)));
    }

    /**
     * @param  array<int, mixed>  $paths
     * @return list<string>
     */
    private static function clean(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }
            $path = trim(str_replace('\\', '/', $path), '/');
            /* The library lives under catalog/ on the public disk. Anything
               else, anything climbing out of it, and anything carrying a
               control character is not an image this ERP offered, and never
               reaches a marketplace.

               No allowlist of characters beyond that: the real library holds
               parentheses, spaces, ampersands and apostrophes in its file
               names, and a pattern narrow enough to feel safe was silently
               dropping half the catalog. What makes a path acceptable is
               that the disk actually holds it - see acceptable(). */
            if ($path === '' || ! str_starts_with($path, 'catalog/')) {
                continue;
            }
            if (in_array('..', explode('/', $path), true) || preg_match('/[\x00-\x1f]/', $path)) {
                continue;
            }
            $out[$path] = true;
        }

        return array_keys($out);
    }
}
