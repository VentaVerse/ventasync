<?php

namespace App\Support\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductImages
{
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

    public static function urls(int $productId, ?array $own = null): array
    {
        return array_map([self::class, 'url'], self::paths($productId, $own));
    }

    public static function tiles(int $productId, ?array $own = null): array
    {
        return array_map(
            fn (string $path) => ['path' => $path, 'url' => self::url($path)],
            self::paths($productId, $own)
        );
    }

    public static function acceptable(array $submitted): array
    {
        $disk = Storage::disk('public');

        return array_values(array_filter(
            self::clean($submitted),
            fn (string $path) => $disk->exists($path)
        ));
    }

    public static function url(string $path): string
    {
        $segments = explode('/', ltrim($path, '/'));

        return asset('storage/' . implode('/', array_map('rawurlencode', $segments)));
    }

    private static function clean(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }
            $path = trim(str_replace('\\', '/', $path), '/');
            // Only paths under catalog/ that the disk holds are images; do not narrow the character set, real file names are varied.
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
