<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class CatalogImages
{
    private static array $memo = [];

    public static function forSkus(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map(fn ($s) => trim((string) $s), $skus), fn ($s) => $s !== '')));
        $wanted = array_values(array_filter($skus, fn ($s) => !array_key_exists($s, self::$memo)));
        if ($wanted) {
            $pfx = (string) config('catalog.prefix');
            $found = [];

            foreach (DB::table($pfx . 'product')->where(function ($w) use ($wanted) {
                $w->whereIn('sku', $wanted)->orWhereIn('model', $wanted);
            })->get(['sku', 'model', 'image']) as $p) {
                if (trim((string) $p->image) === '') {
                    continue;
                }
                foreach ([$p->sku, $p->model] as $k) {
                    $k = trim((string) $k);
                    if ($k !== '' && in_array($k, $wanted, true)) {
                        $found[$k] ??= trim((string) $p->image);
                    }
                }
            }

            foreach (DB::table('product_option_combinations as c')
                ->join($pfx . 'product as p', 'p.product_id', '=', 'c.product_id')
                ->whereIn('c.sku', $wanted)
                ->get(['c.sku', 'c.image as own', 'p.image as parent']) as $r) {
                $k = trim((string) $r->sku);
                $img = trim((string) $r->own) !== '' ? trim((string) $r->own) : trim((string) $r->parent);
                if ($img !== '') {
                    $found[$k] ??= $img;
                }
            }

            foreach (DB::table($pfx . 'product_option_value as pov')
                ->join($pfx . 'product as p', 'p.product_id', '=', 'pov.product_id')
                ->whereIn('pov.sku', $wanted)
                ->get(['pov.sku', 'p.image']) as $r) {
                $k = trim((string) $r->sku);
                if (trim((string) $r->image) !== '') {
                    $found[$k] ??= trim((string) $r->image);
                }
            }

            foreach ($wanted as $s) {
                self::$memo[$s] = $found[$s] ?? null;
            }
        }

        $out = [];
        foreach ($skus as $s) {
            if (self::$memo[$s] !== null) {
                $out[$s] = self::$memo[$s];
            }
        }

        return $out;
    }

    public static function urlFor(?string $sku): ?string
    {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return null;
        }
        $path = self::forSkus([$sku])[$sku] ?? null;

        return $path ? \App\Services\Media\ImageCache::url($path) : null;
    }
}
