<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;

final class VariationSnapshot
{
    public function wire(int $productId): ?array
    {
        $pfx = (string) config('catalog.prefix');
        $lang = (int) config('catalog.default_language_id');

        $types = DB::table($pfx . 'product_option as po')
            ->leftJoin($pfx . 'option_description as od', fn ($j) => $j->on('od.option_id', '=', 'po.option_id')->where('od.language_id', '=', $lang))
            ->where('po.product_id', $productId)
            ->orderBy('po.product_option_id')
            ->get(['po.product_option_id', 'po.option_id', 'od.name']);

        if ($types->isEmpty()) {
            return null;
        }

        $values = DB::table($pfx . 'product_option_value as pov')
            ->leftJoin($pfx . 'option_value_description as ovd', fn ($j) => $j->on('ovd.option_value_id', '=', 'pov.option_value_id')->where('ovd.language_id', '=', $lang))
            ->where('pov.product_id', $productId)
            ->orderBy('pov.product_option_value_id')
            ->get(['pov.product_option_value_id', 'pov.product_option_id', 'pov.option_value_id', 'pov.sku', 'pov.quantity', 'pov.absolute_price', 'ovd.name']);

        $combos = DB::table('product_option_combinations')->where('product_id', $productId)->orderBy('sort_order')->orderBy('id')->get();
        $pivot = DB::table('product_option_combination_values')
            ->whereIn('combination_id', $combos->pluck('id')->all())
            ->get()
            ->groupBy('combination_id');

        $name = fn ($v) => html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($types->count() === 1) {
            $comboByPov = [];
            foreach ($combos as $combo) {
                foreach ($pivot[$combo->id] ?? [] as $link) {
                    $comboByPov[(int) $link->product_option_value_id] ??= $combo;
                }
            }

            return [
                'option_name' => $name($types[0]->name),
                'values' => $values->map(function ($v) use ($comboByPov, $name) {
                    $combo = $comboByPov[(int) $v->product_option_value_id] ?? null;

                    return [
                        'option_value_id' => (int) $v->option_value_id,
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

        [$first, $second] = [(int) $types[0]->product_option_id, (int) $types[1]->product_option_id];
        $valueByPov = $values->keyBy('product_option_value_id');

        return [
            'option1_name' => $name($types[0]->name),
            'option2_name' => $name($types[1]->name),
            'option1_values' => $values->where('product_option_id', $first)->map(fn ($v) => $name($v->name))->values()->all(),
            'option2_values' => $values->where('product_option_id', $second)->map(fn ($v) => $name($v->name))->values()->all(),
            'combinations' => $combos->map(function ($combo) use ($pivot, $valueByPov, $first, $name) {
                $opt1 = $opt2 = '';
                foreach ($pivot[$combo->id] ?? [] as $link) {
                    $v = $valueByPov[(int) $link->product_option_value_id] ?? null;
                    if ($v === null) {
                        continue;
                    }
                    if ((int) $v->product_option_id === $first) {
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
