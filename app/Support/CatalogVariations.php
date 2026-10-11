<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class CatalogVariations
{
    private static array $memo = [];

    public static function forSkus(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map(fn ($s) => trim((string) $s), $skus), fn ($s) => $s !== '')));
        $wanted = array_values(array_filter($skus, fn ($s) => !array_key_exists($s, self::$memo)));
        if ($wanted) {
            $langId = (int) config('catalog.default_language_id');
            $found = [];

            $combos = DB::table('product_variations as c')
                ->whereIn('c.sku', $wanted)
                ->get(['c.id', 'c.sku']);
            if ($combos->isNotEmpty()) {
                $skuByCombo = [];
                foreach ($combos as $c) {
                    $skuByCombo[(int) $c->id] = trim((string) $c->sku);
                }
                $pivots = DB::table('product_variation_parts as cv')
                    ->join('product_variation_values as pov', 'cv.product_variation_value_id', '=', 'pov.product_variation_value_id')
                    ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                        $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')->where('ovd.language_id', '=', $langId);
                    })
                    ->join('variation_type_descriptions as od', function ($j) use ($langId) {
                        $j->on('pov.variation_type_id', '=', 'od.variation_type_id')->where('od.language_id', '=', $langId);
                    })
                    ->join('product_variation_types as po', 'pov.product_variation_type_id', '=', 'po.product_variation_type_id')
                    ->whereIn('cv.product_variation_id', array_keys($skuByCombo))
                    ->orderBy('po.product_variation_type_id')
                    ->get(['cv.product_variation_id', 'ovd.name as value_name', 'od.name as variation_type_name']);
                foreach ($pivots as $pv) {
                    $sku = $skuByCombo[(int) $pv->product_variation_id] ?? '';
                    if ($sku === '') {
                        continue;
                    }
                    $found[$sku][] = [
                        'name' => html_entity_decode((string) $pv->variation_type_name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        'value' => html_entity_decode((string) $pv->value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    ];
                }
            }

            $rest = array_values(array_diff($wanted, array_keys($found)));
            if ($rest) {
                $povRows = DB::table('product_variation_values as pov')
                    ->join('variation_type_descriptions as od', function ($j) use ($langId) {
                        $j->on('pov.variation_type_id', '=', 'od.variation_type_id')->where('od.language_id', '=', $langId);
                    })
                    ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                        $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')->where('ovd.language_id', '=', $langId);
                    })
                    ->whereIn('pov.sku', $rest)
                    ->get(['pov.sku', 'od.name as variation_type_name', 'ovd.name as value_name']);
                foreach ($povRows as $r) {
                    $sku = trim((string) $r->sku);
                    $found[$sku] ??= [[
                        'name' => html_entity_decode((string) $r->variation_type_name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        'value' => html_entity_decode((string) $r->value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    ]];
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

    public static function pairsFor(?string $sku, ?string $fallback = null): array
    {
        $sku = trim((string) $sku);
        if ($sku !== '') {
            $pairs = self::forSkus([$sku])[$sku] ?? null;
            if ($pairs) {
                return $pairs;
            }
        }

        $text = trim((string) $fallback);
        if ($text === '') {
            return [];
        }

        if (!str_contains($text, ':')) {
            return [['name' => '', 'value' => $text]];
        }

        $pairs = [];
        foreach (preg_split('/\s*[,;|]\s*/u', $text) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^([^:]{1,40}):\s*(.+)$/u', $part, $m)) {
                $pairs[] = ['name' => trim($m[1]), 'value' => trim($m[2])];
            } else {
                $pairs[] = ['name' => '', 'value' => $part];
            }
        }

        return $pairs;
    }
}
