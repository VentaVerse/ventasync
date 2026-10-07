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
            $pfx = (string) config('catalog.prefix');
            $langId = (int) config('catalog.default_language_id');
            $found = [];

            $combos = DB::table('product_option_combinations as c')
                ->whereIn('c.sku', $wanted)
                ->get(['c.id', 'c.sku']);
            if ($combos->isNotEmpty()) {
                $skuByCombo = [];
                foreach ($combos as $c) {
                    $skuByCombo[(int) $c->id] = trim((string) $c->sku);
                }
                $pivots = DB::table('product_option_combination_values as cv')
                    ->join($pfx . 'product_option_value as pov', 'cv.product_option_value_id', '=', 'pov.product_option_value_id')
                    ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                        $j->on('pov.option_value_id', '=', 'ovd.option_value_id')->where('ovd.language_id', '=', $langId);
                    })
                    ->join($pfx . 'option_description as od', function ($j) use ($langId) {
                        $j->on('pov.option_id', '=', 'od.option_id')->where('od.language_id', '=', $langId);
                    })
                    ->join($pfx . 'product_option as po', 'pov.product_option_id', '=', 'po.product_option_id')
                    ->whereIn('cv.combination_id', array_keys($skuByCombo))
                    ->orderBy('po.product_option_id')
                    ->get(['cv.combination_id', 'ovd.name as value_name', 'od.name as option_name']);
                foreach ($pivots as $pv) {
                    $sku = $skuByCombo[(int) $pv->combination_id] ?? '';
                    if ($sku === '') {
                        continue;
                    }
                    $found[$sku][] = [
                        'name' => html_entity_decode((string) $pv->option_name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        'value' => html_entity_decode((string) $pv->value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    ];
                }
            }

            $rest = array_values(array_diff($wanted, array_keys($found)));
            if ($rest) {
                $povRows = DB::table($pfx . 'product_option_value as pov')
                    ->join($pfx . 'option_description as od', function ($j) use ($langId) {
                        $j->on('pov.option_id', '=', 'od.option_id')->where('od.language_id', '=', $langId);
                    })
                    ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                        $j->on('pov.option_value_id', '=', 'ovd.option_value_id')->where('ovd.language_id', '=', $langId);
                    })
                    ->whereIn('pov.sku', $rest)
                    ->get(['pov.sku', 'od.name as option_name', 'ovd.name as value_name']);
                foreach ($povRows as $r) {
                    $sku = trim((string) $r->sku);
                    $found[$sku] ??= [[
                        'name' => html_entity_decode((string) $r->option_name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
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
