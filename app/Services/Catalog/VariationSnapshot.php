<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;

final class VariationSnapshot
{
    public function wire(int $productId): ?array
    {
        $lang = (int) config('catalog.default_language_id');

        $types = DB::table('product_variation_types as po')
            ->leftJoin('variation_type_descriptions as od', fn ($j) => $j->on('od.variation_type_id', '=', 'po.variation_type_id')->where('od.language_id', '=', $lang))
            ->where('po.product_id', $productId)
            ->orderBy('po.product_variation_type_id')
            ->get(['po.product_variation_type_id', 'po.variation_type_id', 'od.name']);

        if ($types->isEmpty()) {
            return null;
        }

        $values = DB::table('product_variation_values as pov')
            ->leftJoin('variation_value_descriptions as ovd', fn ($j) => $j->on('ovd.variation_value_id', '=', 'pov.variation_value_id')->where('ovd.language_id', '=', $lang))
            ->where('pov.product_id', $productId)
            ->orderBy('pov.product_variation_value_id')
            ->get(['pov.product_variation_value_id', 'pov.product_variation_type_id', 'pov.variation_value_id', 'pov.sku', 'pov.quantity', 'pov.absolute_price', 'ovd.name']);

        $combos = DB::table('product_variations')->where('product_id', $productId)->orderBy('sort_order')->orderBy('id')->get();
        $pivot = DB::table('product_variation_parts')
            ->whereIn('product_variation_id', $combos->pluck('id')->all())
            ->get()
            ->groupBy('product_variation_id');

        $name = fn ($v) => html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($types->count() === 1) {
            $comboByPov = [];
            foreach ($combos as $combo) {
                foreach ($pivot[$combo->id] ?? [] as $link) {
                    $comboByPov[(int) $link->product_variation_value_id] ??= $combo;
                }
            }

            return [
                'variation_type_name' => $name($types[0]->name),
                'values' => $values->map(function ($v) use ($comboByPov, $name) {
                    $combo = $comboByPov[(int) $v->product_variation_value_id] ?? null;

                    return [
                        'variation_value_id' => (int) $v->variation_value_id,
                        'name' => $name($v->name),
                        'sku' => (string) ($v->sku ?? ''),
                        'quantity' => (int) $v->quantity,
                        'absolute_price' => (float) $v->absolute_price,
                        'cost_amount' => (float) ($combo->cost_amount ?? 0),
                        'cost_additional' => (float) ($combo->cost_additional ?? 0),
                        'image' => (string) ($combo->image ?? ''),
                        'status' => (int) ($combo->status ?? 1),
                    ];
                })->values()->all(),
            ];
        }

        [$first, $second] = [(int) $types[0]->product_variation_type_id, (int) $types[1]->product_variation_type_id];
        $valueByPov = $values->keyBy('product_variation_value_id');

        return [
            'type1_name' => $name($types[0]->name),
            'type2_name' => $name($types[1]->name),
            'type1_values' => $values->where('product_variation_type_id', $first)->map(fn ($v) => $name($v->name))->values()->all(),
            'type2_values' => $values->where('product_variation_type_id', $second)->map(fn ($v) => $name($v->name))->values()->all(),
            'combinations' => $combos->map(function ($combo) use ($pivot, $valueByPov, $first, $name) {
                $opt1 = $opt2 = '';
                foreach ($pivot[$combo->id] ?? [] as $link) {
                    $v = $valueByPov[(int) $link->product_variation_value_id] ?? null;
                    if ($v === null) {
                        continue;
                    }
                    if ((int) $v->product_variation_type_id === $first) {
                        $opt1 = $name($v->name);
                    } else {
                        $opt2 = $name($v->name);
                    }
                }

                return [
                    'opt1' => $opt1,
                    'opt2' => $opt2,
                    'sku' => (string) ($combo->sku ?? ''),
                    'quantity' => (int) $combo->quantity,
                    'absolute_price' => (float) $combo->absolute_price,
                    'cost_amount' => (float) $combo->cost_amount,
                    'cost_additional' => (float) $combo->cost_additional,
                    'image' => (string) ($combo->image ?? ''),
                    'status' => (int) $combo->status,
                ];
            })->filter(fn ($c) => $c['opt1'] !== '' && $c['opt2'] !== '')->values()->all(),
        ];
    }
}
