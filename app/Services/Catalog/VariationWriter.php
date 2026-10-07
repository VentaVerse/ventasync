<?php

namespace App\Services\Catalog;

use App\Rules\UniqueSku;
use App\Services\StockHistoryLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VariationWriter
{
    public function totalQuantity(array $input): ?int
    {
        $values = $input['values'] ?? [];
        if (is_array($values) && ! empty($values)) {
            return array_sum(array_map(fn ($v) => (int) ($v['quantity'] ?? 0), $values));
        }

        $combos = $input['combinations'] ?? [];
        if (is_array($combos) && ! empty($combos)) {
            return array_sum(array_map(fn ($c) => (int) ($c['quantity'] ?? 0), $combos));
        }

        return null;
    }

    public function validateSkus(int $productId, array $input): void
    {
        $skuRule = new UniqueSku($productId);
        $errors = [];
        $seenSkus = [];
        $parentSku = strtolower(trim((string) ($input['sku'] ?? '')));
        if ($parentSku !== '') {
            $seenSkus[$parentSku] = true;
        }

        $valueSources = $input['values'] ?? [];
        $comboSources = $input['combinations'] ?? [];

        foreach ((is_array($valueSources) ? $valueSources : []) as $v) {
            $sku = trim((string) ($v['sku'] ?? ''));
            if ($sku === '') continue;
            $skuLower = strtolower($sku);
            if ($skuLower === $parentSku) {
                $errors[] = "The SKU \"{$sku}\" is already used as this product's parent SKU.";
                continue;
            }
            if (isset($seenSkus[$skuLower])) {
                $errors[] = "Duplicate SKU \"{$sku}\" within this product's options.";
                continue;
            }
            $seenSkus[$skuLower] = true;
            $skuRule->validate('sku', $sku, function (string $msg) use (&$errors) {
                $errors[] = $msg;
            });
        }
        foreach ((is_array($comboSources) ? $comboSources : []) as $c) {
            $sku = trim((string) ($c['sku'] ?? ''));
            if ($sku === '') continue;
            $skuLower = strtolower($sku);
            if ($skuLower === $parentSku) {
                $errors[] = "The SKU \"{$sku}\" is already used as this product's parent SKU.";
                continue;
            }
            if (isset($seenSkus[$skuLower])) {
                $errors[] = "Duplicate SKU \"{$sku}\" within this product's combinations.";
                continue;
            }
            $seenSkus[$skuLower] = true;
            $skuRule->validate('sku', $sku, function (string $msg) use (&$errors) {
                $errors[] = $msg;
            });
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages(['option_sku' => $errors]);
        }
    }

    public function save(int $productId, array $input, array &$skuChanges = []): void
    {
        $optionName = trim($input['option_name'] ?? '');
        $option1Name = trim($input['option1_name'] ?? '');

        if ($optionName === '' && $option1Name === '') {
            $this->clearProductOptions($productId);
            return;
        }

        $this->validateSkus($productId, $input);

        if ($option1Name !== '') {
            $this->saveTwoTypes($productId, $input, $skuChanges);
        } else {
            $this->saveOneType($productId, $input, $skuChanges);
        }
    }

    private function findOrCreateOption(string $name): int
    {
        $pfx = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $existing = DB::table($pfx . 'option_description')
            ->where('language_id', $langId)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        if ($existing) return (int) $existing->option_id;

        $optionId = DB::table($pfx . 'option')->insertGetId([
            'type' => 'select',
            'sort_order' => 0,
        ]);

        DB::table($pfx . 'option_description')->insert([
            'option_id' => $optionId,
            'language_id' => $langId,
            'name' => $name,
        ]);

        return (int) $optionId;
    }

    private function findOrCreateOptionValue(int $optionId, string $name): int
    {
        $pfx = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $existing = DB::table($pfx . 'option_value_description')
            ->where('option_id', $optionId)
            ->where('language_id', $langId)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        if ($existing) return (int) $existing->option_value_id;

        $optionValueId = DB::table($pfx . 'option_value')->insertGetId([
            'option_id' => $optionId,
            'image' => '',
            'sort_order' => 0,
        ]);

        DB::table($pfx . 'option_value_description')->insert([
            'option_value_id' => $optionValueId,
            'option_id' => $optionId,
            'language_id' => $langId,
            'name' => $name,
        ]);

        return (int) $optionValueId;
    }

    private function ensureProductOption(int $productId, int $optionId): int
    {
        $pfx = config('catalog.prefix');

        $existing = DB::table($pfx . 'product_option')
            ->where('product_id', $productId)
            ->where('option_id', $optionId)
            ->first();

        if ($existing) return (int) $existing->product_option_id;

        return DB::table($pfx . 'product_option')->insertGetId([
            'product_id' => $productId,
            'option_id' => $optionId,
            'value' => '',
            'required' => 1,
        ]);
    }

    private function clearProductOptions(int $productId): void
    {
        $pfx = config('catalog.prefix');

        DB::table('product_option_combination_values')
            ->whereIn('combination_id', function ($q) use ($productId) {
                $q->select('id')->from('product_option_combinations')->where('product_id', $productId);
            })->delete();
        DB::table('product_option_combinations')->where('product_id', $productId)->delete();

        $deletedPovIds = DB::table($pfx . 'product_option_value')
            ->where('product_id', $productId)
            ->pluck('product_option_value_id')
            ->map(fn($v) => (int) $v)
            ->all();

        DB::table($pfx . 'product_option_value')->where('product_id', $productId)->delete();
        DB::table($pfx . 'product_option')->where('product_id', $productId)->delete();

        if (!empty($deletedPovIds)) {
            DB::table('lazada_product_variants')
                ->whereIn('product_option_value_id', $deletedPovIds)
                ->update(['product_option_value_id' => null]);
        }
    }

    private function parentCostFormula(int $productId, array $input): array
    {
        $pct = array_key_exists('cost_percentage', $input) ? (float) ($input['cost_percentage'] ?? 0) : null;
        $add = array_key_exists('cost_additional', $input) ? (float) ($input['cost_additional'] ?? 0) : null;

        if ($pct === null || $add === null) {
            $row = DB::table(config('catalog.prefix') . 'product')
                ->where('product_id', $productId)
                ->first(['cost_percentage', 'cost_additional']);

            $pct ??= (float) ($row->cost_percentage ?? 0);
            $add ??= (float) ($row->cost_additional ?? 0);
        }

        return [$pct, $add];
    }

    private function variationUnitCost(float $amount, float $price, float $pct, float $add): float
    {
        return round($amount + ($pct / 100 * $price) + $add, 4);
    }

    private function rowCostAdditional(array $row, float $parentAdd): float
    {
        $raw = $row['cost_additional'] ?? null;
        if ($raw === null || $raw === '') {
            return $parentAdd;
        }

        return (float) $raw;
    }

    private function saveOneType(int $productId, array $input, array &$skuChanges = []): void
    {
        $pfx = config('catalog.prefix');
        $optionName = trim($input['option_name'] ?? '');
        $values = $input['values'] ?? [];
        if (!is_array($values)) $values = [];

        $optionId = $this->findOrCreateOption($optionName);

        $stalePos = DB::table($pfx . 'product_option')
            ->where('product_id', $productId)
            ->where('option_id', '!=', $optionId)
            ->pluck('product_option_id');

        if ($stalePos->isNotEmpty()) {
            $stalePovIds = DB::table($pfx . 'product_option_value')
                ->whereIn('product_option_id', $stalePos)
                ->pluck('product_option_value_id')
                ->map(fn($v) => (int) $v)->all();

            DB::table($pfx . 'product_option_value')->whereIn('product_option_id', $stalePos)->delete();
            DB::table($pfx . 'product_option')->whereIn('product_option_id', $stalePos)->delete();

            if (!empty($stalePovIds)) {
                DB::table('lazada_product_variants')
                    ->whereIn('product_option_value_id', $stalePovIds)
                    ->update(['product_option_value_id' => null]);
            }
        }

        $productOptionId = $this->ensureProductOption($productId, $optionId);

        $existingPovs = DB::table($pfx . 'product_option_value')
            ->where('product_option_id', $productOptionId)
            ->get()
            ->keyBy('option_value_id');

        $seenOptionValueIds = [];

        $imageByOptionValueId = [];
        $statusByOptionValueId = [];
        $costAmountByOptionValueId = [];
        $costAddByOptionValueId = [];

        [$parentPct, $parentAdd] = $this->parentCostFormula($productId, $input);

        foreach ($values as $v) {
            if (!is_array($v)) continue;
            $valueName = trim($v['name'] ?? '');
            if ($valueName === '') continue;

            $optionValueId = !empty($v['option_value_id'])
                ? (int) $v['option_value_id']
                : $this->findOrCreateOptionValue($optionId, $valueName);

            $seenOptionValueIds[] = $optionValueId;
            $imageByOptionValueId[$optionValueId] = trim((string) ($v['image'] ?? ''));
            // An absent status means enabled; do not switch a variation off when the caller says nothing.
            $statusByOptionValueId[$optionValueId] = array_key_exists('status', $v)
                ? (int) (bool) $v['status']
                : 1;

            $costAmount = (float) ($v['cost_amount'] ?? $v['absolute_cost'] ?? 0);
            $costAmountByOptionValueId[$optionValueId] = $costAmount;
            $costAdd = $this->rowCostAdditional($v, $parentAdd);
            $costAddByOptionValueId[$optionValueId] = $costAdd;

            $data = [
                'sku' => trim((string) ($v['sku'] ?? '')),
                'quantity' => (int) ($v['quantity'] ?? 0),
                'subtract' => 1,
                'price' => 0,
                'price_prefix' => '+',
                'absolute_price' => (float) ($v['absolute_price'] ?? 0),
                'weight' => 0,
                'weight_prefix' => '+',
                'cost' => 0,
                'cost_prefix' => '+',
                'cost_amount' => $costAmount,
                'cost_percentage' => $parentPct,
                'cost_additional' => $costAdd,
                'absolute_cost' => $this->variationUnitCost(
                    $costAmount,
                    (float) ($v['absolute_price'] ?? 0),
                    $parentPct,
                    $costAdd
                ),
            ];

            if (isset($existingPovs[$optionValueId])) {
                $old = $existingPovs[$optionValueId];

                $oldSku = trim((string) ($old->sku ?? ''));
                if ($oldSku !== '' && $data['sku'] !== $oldSku) {
                    $skuChanges['option_skus'][(int) $old->product_option_value_id] = [
                        'old' => $oldSku, 'new' => $data['sku']
                    ];
                }

                if ((int) ($old->quantity ?? 0) !== $data['quantity']) {
                    StockHistoryLogger::log(
                        productId: $productId,
                        optionValueId: (int) $old->product_option_value_id,
                        orderId: null,
                        type: 'set',
                        qtyBefore: (int) $old->quantity,
                        qtyAfter: $data['quantity'],
                        source: 'manual',
                        note: "Manual edit (variation), qty {$old->quantity} to {$data['quantity']}",
                    );
                }

                DB::table($pfx . 'product_option_value')
                    ->where('product_option_value_id', $old->product_option_value_id)
                    ->update(array_merge($data, ['product_option_id' => $productOptionId]));
            } else {
                DB::table($pfx . 'product_option_value')->insert(array_merge($data, [
                    'product_option_id' => $productOptionId,
                    'product_id' => $productId,
                    'option_id' => $optionId,
                    'option_value_id' => $optionValueId,
                    'points' => 0,
                    'points_prefix' => '+',
                ]));
            }
        }

        $deletedPovIds = [];
        foreach ($existingPovs as $ovId => $row) {
            if (!in_array((int) $ovId, $seenOptionValueIds, true)) {
                $deletedPovIds[] = (int) $row->product_option_value_id;
                DB::table($pfx . 'product_option_value')
                    ->where('product_option_value_id', $row->product_option_value_id)
                    ->delete();
            }
        }
        if (!empty($deletedPovIds)) {
            DB::table('lazada_product_variants')
                ->whereIn('product_option_value_id', $deletedPovIds)
                ->update(['product_option_value_id' => null]);
        }

        DB::table('product_option_combination_values')
            ->whereIn('combination_id', function ($q) use ($productId) {
                $q->select('id')->from('product_option_combinations')->where('product_id', $productId);
            })->delete();
        DB::table('product_option_combinations')->where('product_id', $productId)->delete();

        $now = now();
        $sort = 0;
        $povRows = DB::table($pfx . 'product_option_value')
            ->where('product_option_id', $productOptionId)
            ->get();

        foreach ($povRows as $pov) {
            $image = $imageByOptionValueId[(int) $pov->option_value_id] ?? '';
            $status = $statusByOptionValueId[(int) $pov->option_value_id] ?? 1;
            $costAmount = $costAmountByOptionValueId[(int) $pov->option_value_id]
                ?? (float) ($pov->cost_amount ?? 0);
            $costAdd = $costAddByOptionValueId[(int) $pov->option_value_id]
                ?? (float) ($pov->cost_additional ?? 0);

            $comboId = DB::table('product_option_combinations')->insertGetId([
                'product_id' => $productId,
                'sku' => $pov->sku ?? '',
                'status' => $status,
                'image' => $image !== '' ? $image : null,
                'quantity' => (int) $pov->quantity,
                'absolute_price' => (float) ($pov->absolute_price ?? 0),
                'cost_amount' => $costAmount,
                'cost_additional' => $costAdd,
                'absolute_cost' => (float) ($pov->absolute_cost ?? 0),
                'subtract' => 1,
                'sort_order' => $sort++,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('product_option_combination_values')->insert([
                'combination_id' => $comboId,
                'product_option_value_id' => (int) $pov->product_option_value_id,
            ]);
        }
    }

    private function saveTwoTypes(int $productId, array $input, array &$skuChanges = []): void
    {
        $pfx = config('catalog.prefix');
        $opt1Name = trim($input['option1_name'] ?? '');
        $opt2Name = trim($input['option2_name'] ?? '');
        $opt1ValueNames = $input['option1_values'] ?? [];
        $opt2ValueNames = $input['option2_values'] ?? [];
        $combinations = $input['combinations'] ?? [];

        if (!is_array($opt1ValueNames)) $opt1ValueNames = [];
        if (!is_array($opt2ValueNames)) $opt2ValueNames = [];
        if (!is_array($combinations)) $combinations = [];

        $optionId1 = $this->findOrCreateOption($opt1Name);
        $optionId2 = $this->findOrCreateOption($opt2Name);

        $keepOptionIds = [$optionId1, $optionId2];
        $stalePos = DB::table($pfx . 'product_option')
            ->where('product_id', $productId)
            ->whereNotIn('option_id', $keepOptionIds)
            ->pluck('product_option_id');

        if ($stalePos->isNotEmpty()) {
            $stalePovIds = DB::table($pfx . 'product_option_value')
                ->whereIn('product_option_id', $stalePos)
                ->pluck('product_option_value_id')
                ->map(fn($v) => (int) $v)->all();

            DB::table($pfx . 'product_option_value')->whereIn('product_option_id', $stalePos)->delete();
            DB::table($pfx . 'product_option')->whereIn('product_option_id', $stalePos)->delete();

            if (!empty($stalePovIds)) {
                DB::table('lazada_product_variants')
                    ->whereIn('product_option_value_id', $stalePovIds)
                    ->update(['product_option_value_id' => null]);
            }
        }

        $poId1 = $this->ensureProductOption($productId, $optionId1);
        $poId2 = $this->ensureProductOption($productId, $optionId2);

        [$parentPct, $parentAdd] = $this->parentCostFormula($productId, $input);

        $opt1PovMap = $this->syncOptionValues($productId, $optionId1, $poId1, $opt1ValueNames);
        $opt2PovMap = $this->syncOptionValues($productId, $optionId2, $poId2, $opt2ValueNames);

        DB::table('product_option_combination_values')
            ->whereIn('combination_id', function ($q) use ($productId) {
                $q->select('id')->from('product_option_combinations')->where('product_id', $productId);
            })->delete();
        DB::table('product_option_combinations')->where('product_id', $productId)->delete();

        $now = now();
        $sort = 0;

        foreach ($combinations as $combo) {
            if (!is_array($combo)) continue;
            $v1Name = trim($combo['opt1'] ?? '');
            $v2Name = trim($combo['opt2'] ?? '');
            if ($v1Name === '' || $v2Name === '') continue;

            $pov1Id = $opt1PovMap[strtolower($v1Name)] ?? null;
            $pov2Id = $opt2PovMap[strtolower($v2Name)] ?? null;
            if (!$pov1Id || !$pov2Id) continue;

            $image = trim((string) ($combo['image'] ?? ''));

            $comboPrice = (float) ($combo['absolute_price'] ?? 0);
            $comboCostAmount = (float) ($combo['cost_amount'] ?? $combo['absolute_cost'] ?? 0);
            $comboCostAdd = $this->rowCostAdditional($combo, $parentAdd);

            $comboId = DB::table('product_option_combinations')->insertGetId([
                'product_id' => $productId,
                'sku' => trim((string) ($combo['sku'] ?? '')),
                'status' => array_key_exists('status', $combo) ? (int) (bool) $combo['status'] : 1,
                'image' => $image !== '' ? $image : null,
                'quantity' => (int) ($combo['quantity'] ?? 0),
                'absolute_price' => $comboPrice,
                'cost_amount' => $comboCostAmount,
                'cost_additional' => $comboCostAdd,
                'absolute_cost' => $this->variationUnitCost(
                    $comboCostAmount,
                    $comboPrice,
                    $parentPct,
                    $comboCostAdd
                ),
                'subtract' => 1,
                'sort_order' => $sort++,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('product_option_combination_values')->insert([
                ['combination_id' => $comboId, 'product_option_value_id' => $pov1Id],
                ['combination_id' => $comboId, 'product_option_value_id' => $pov2Id],
            ]);
        }
    }

    private function syncOptionValues(int $productId, int $optionId, int $productOptionId, array $valueNames): array
    {
        $pfx = config('catalog.prefix');
        $map = [];

        $existingPovs = DB::table($pfx . 'product_option_value')
            ->where('product_option_id', $productOptionId)
            ->get()
            ->keyBy('option_value_id');

        $seenOptionValueIds = [];

        foreach ($valueNames as $name) {
            $name = trim($name);
            if ($name === '') continue;

            $optionValueId = $this->findOrCreateOptionValue($optionId, $name);
            $seenOptionValueIds[] = $optionValueId;

            if (!isset($existingPovs[$optionValueId])) {
                $povId = DB::table($pfx . 'product_option_value')->insertGetId([
                    'product_option_id' => $productOptionId,
                    'product_id' => $productId,
                    'option_id' => $optionId,
                    'option_value_id' => $optionValueId,
                    'sku' => '',
                    'quantity' => 0,
                    'subtract' => 1,
                    'price' => 0,
                    'price_prefix' => '+',
                    'absolute_price' => 0,
                    'weight' => 0,
                    'weight_prefix' => '+',
                    'cost' => 0,
                    'cost_prefix' => '+',
                    'cost_amount' => 0,
                    'cost_percentage' => 0,
                    'cost_additional' => 0,
                    'absolute_cost' => 0,
                    'points' => 0,
                    'points_prefix' => '+',
                ]);
                $map[strtolower($name)] = $povId;
            } else {
                $map[strtolower($name)] = (int) $existingPovs[$optionValueId]->product_option_value_id;
            }
        }

        foreach ($existingPovs as $ovId => $row) {
            if (!in_array((int) $ovId, $seenOptionValueIds, true)) {
                DB::table($pfx . 'product_option_value')
                    ->where('product_option_value_id', $row->product_option_value_id)
                    ->delete();
            }
        }

        return $map;
    }
}
