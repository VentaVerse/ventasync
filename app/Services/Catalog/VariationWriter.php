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
                $errors[] = "Duplicate SKU \"{$sku}\" within this product's variations.";
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
            throw ValidationException::withMessages(['variation_sku' => $errors]);
        }
    }

    public function save(int $productId, array $input, array &$skuChanges = []): void
    {
        $variationTypeName = trim($input['variation_type_name'] ?? '');
        $type1Name = trim($input['type1_name'] ?? '');

        if ($variationTypeName === '' && $type1Name === '') {
            $this->clearProductVariationTypes($productId);
            return;
        }

        $this->validateSkus($productId, $input);

        if ($type1Name !== '') {
            $this->saveTwoTypes($productId, $input, $skuChanges);
        } else {
            $this->saveOneType($productId, $input, $skuChanges);
        }
    }

    private function findOrCreateVariationType(string $name): int
    {
        $langId = (int) config('catalog.default_language_id');

        $existing = DB::table('variation_type_descriptions')
            ->where('language_id', $langId)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        if ($existing) return (int) $existing->variation_type_id;

        $variationTypeId = DB::table('variation_types')->insertGetId([
            'type' => 'select',
            'sort_order' => 0,
        ]);

        DB::table('variation_type_descriptions')->insert([
            'variation_type_id' => $variationTypeId,
            'language_id' => $langId,
            'name' => $name,
        ]);

        return (int) $variationTypeId;
    }

    private function findOrCreateVariationValue(int $variationTypeId, string $name): int
    {
        $langId = (int) config('catalog.default_language_id');

        $existing = DB::table('variation_value_descriptions')
            ->where('variation_type_id', $variationTypeId)
            ->where('language_id', $langId)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        if ($existing) return (int) $existing->variation_value_id;

        $variationValueId = DB::table('variation_values')->insertGetId([
            'variation_type_id' => $variationTypeId,
            'image' => '',
            'sort_order' => 0,
        ]);

        DB::table('variation_value_descriptions')->insert([
            'variation_value_id' => $variationValueId,
            'variation_type_id' => $variationTypeId,
            'language_id' => $langId,
            'name' => $name,
        ]);

        return (int) $variationValueId;
    }

    private function ensureProductVariationType(int $productId, int $variationTypeId): int
    {

        $existing = DB::table('product_variation_types')
            ->where('product_id', $productId)
            ->where('variation_type_id', $variationTypeId)
            ->first();

        if ($existing) return (int) $existing->product_variation_type_id;

        return DB::table('product_variation_types')->insertGetId([
            'product_id' => $productId,
            'variation_type_id' => $variationTypeId,
            'value' => '',
            'required' => 1,
        ]);
    }

    private function clearProductVariationTypes(int $productId): void
    {

        DB::table('product_variation_parts')
            ->whereIn('product_variation_id', function ($q) use ($productId) {
                $q->select('id')->from('product_variations')->where('product_id', $productId);
            })->delete();
        DB::table('product_variations')->where('product_id', $productId)->delete();

        $deletedPovIds = DB::table('product_variation_values')
            ->where('product_id', $productId)
            ->pluck('product_variation_value_id')
            ->map(fn($v) => (int) $v)
            ->all();

        DB::table('product_variation_values')->where('product_id', $productId)->delete();
        DB::table('product_variation_types')->where('product_id', $productId)->delete();

        if (!empty($deletedPovIds)) {
            DB::table('ch_lazada_listing_variations')
                ->whereIn('product_variation_value_id', $deletedPovIds)
                ->update(['product_variation_value_id' => null]);
        }
    }

    private function parentCostFormula(int $productId, array $input): array
    {
        $pct = array_key_exists('cost_percentage', $input) ? (float) ($input['cost_percentage'] ?? 0) : null;
        $add = array_key_exists('cost_additional', $input) ? (float) ($input['cost_additional'] ?? 0) : null;

        if ($pct === null || $add === null) {
            $row = DB::table('products')
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
        $variationTypeName = trim($input['variation_type_name'] ?? '');
        $values = $input['values'] ?? [];
        if (!is_array($values)) $values = [];

        $variationTypeId = $this->findOrCreateVariationType($variationTypeName);

        $stalePos = DB::table('product_variation_types')
            ->where('product_id', $productId)
            ->where('variation_type_id', '!=', $variationTypeId)
            ->pluck('product_variation_type_id');

        if ($stalePos->isNotEmpty()) {
            $stalePovIds = DB::table('product_variation_values')
                ->whereIn('product_variation_type_id', $stalePos)
                ->pluck('product_variation_value_id')
                ->map(fn($v) => (int) $v)->all();

            DB::table('product_variation_values')->whereIn('product_variation_type_id', $stalePos)->delete();
            DB::table('product_variation_types')->whereIn('product_variation_type_id', $stalePos)->delete();

            if (!empty($stalePovIds)) {
                DB::table('ch_lazada_listing_variations')
                    ->whereIn('product_variation_value_id', $stalePovIds)
                    ->update(['product_variation_value_id' => null]);
            }
        }

        $productVariationTypeId = $this->ensureProductVariationType($productId, $variationTypeId);

        $existingPovs = DB::table('product_variation_values')
            ->where('product_variation_type_id', $productVariationTypeId)
            ->get()
            ->keyBy('variation_value_id');

        $seenVariationValueIds = [];

        $imageByVariationValueId = [];
        $statusByVariationValueId = [];
        $costAmountByVariationValueId = [];
        $costAddByVariationValueId = [];

        [$parentPct, $parentAdd] = $this->parentCostFormula($productId, $input);

        foreach ($values as $v) {
            if (!is_array($v)) continue;
            $valueName = trim($v['name'] ?? '');
            if ($valueName === '') continue;

            $variationValueId = !empty($v['variation_value_id'])
                ? (int) $v['variation_value_id']
                : $this->findOrCreateVariationValue($variationTypeId, $valueName);

            $seenVariationValueIds[] = $variationValueId;
            $imageByVariationValueId[$variationValueId] = trim((string) ($v['image'] ?? ''));
            // An absent status means enabled; do not switch a variation off when the caller says nothing.
            $statusByVariationValueId[$variationValueId] = array_key_exists('status', $v)
                ? (int) (bool) $v['status']
                : 1;

            $costAmount = (float) ($v['cost_amount'] ?? $v['absolute_cost'] ?? 0);
            $costAmountByVariationValueId[$variationValueId] = $costAmount;
            $costAdd = $this->rowCostAdditional($v, $parentAdd);
            $costAddByVariationValueId[$variationValueId] = $costAdd;

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

            if (isset($existingPovs[$variationValueId])) {
                $old = $existingPovs[$variationValueId];

                $oldSku = trim((string) ($old->sku ?? ''));
                if ($oldSku !== '' && $data['sku'] !== $oldSku) {
                    $skuChanges['variation_skus'][(int) $old->product_variation_value_id] = [
                        'old' => $oldSku, 'new' => $data['sku']
                    ];
                }

                if ((int) ($old->quantity ?? 0) !== $data['quantity']) {
                    StockHistoryLogger::log(
                        productId: $productId,
                        variationValueId: (int) $old->product_variation_value_id,
                        orderId: null,
                        type: 'set',
                        qtyBefore: (int) $old->quantity,
                        qtyAfter: $data['quantity'],
                        source: 'manual',
                        note: "Manual edit (variation), qty {$old->quantity} to {$data['quantity']}",
                    );
                }

                DB::table('product_variation_values')
                    ->where('product_variation_value_id', $old->product_variation_value_id)
                    ->update(array_merge($data, ['product_variation_type_id' => $productVariationTypeId]));
            } else {
                DB::table('product_variation_values')->insert(array_merge($data, [
                    'product_variation_type_id' => $productVariationTypeId,
                    'product_id' => $productId,
                    'variation_type_id' => $variationTypeId,
                    'variation_value_id' => $variationValueId,
                    'points' => 0,
                    'points_prefix' => '+',
                ]));
            }
        }

        $deletedPovIds = [];
        foreach ($existingPovs as $ovId => $row) {
            if (!in_array((int) $ovId, $seenVariationValueIds, true)) {
                $deletedPovIds[] = (int) $row->product_variation_value_id;
                DB::table('product_variation_values')
                    ->where('product_variation_value_id', $row->product_variation_value_id)
                    ->delete();
            }
        }
        if (!empty($deletedPovIds)) {
            DB::table('ch_lazada_listing_variations')
                ->whereIn('product_variation_value_id', $deletedPovIds)
                ->update(['product_variation_value_id' => null]);
        }

        DB::table('product_variation_parts')
            ->whereIn('product_variation_id', function ($q) use ($productId) {
                $q->select('id')->from('product_variations')->where('product_id', $productId);
            })->delete();
        DB::table('product_variations')->where('product_id', $productId)->delete();

        $now = now();
        $sort = 0;
        $povRows = DB::table('product_variation_values')
            ->where('product_variation_type_id', $productVariationTypeId)
            ->get();

        foreach ($povRows as $pov) {
            $image = $imageByVariationValueId[(int) $pov->variation_value_id] ?? '';
            $status = $statusByVariationValueId[(int) $pov->variation_value_id] ?? 1;
            $costAmount = $costAmountByVariationValueId[(int) $pov->variation_value_id]
                ?? (float) ($pov->cost_amount ?? 0);
            $costAdd = $costAddByVariationValueId[(int) $pov->variation_value_id]
                ?? (float) ($pov->cost_additional ?? 0);

            $comboId = DB::table('product_variations')->insertGetId([
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

            DB::table('product_variation_parts')->insert([
                'product_variation_id' => $comboId,
                'product_variation_value_id' => (int) $pov->product_variation_value_id,
            ]);
        }
    }

    private function saveTwoTypes(int $productId, array $input, array &$skuChanges = []): void
    {
        $opt1Name = trim($input['type1_name'] ?? '');
        $opt2Name = trim($input['type2_name'] ?? '');
        $opt1ValueNames = $input['type1_values'] ?? [];
        $opt2ValueNames = $input['type2_values'] ?? [];
        $combinations = $input['combinations'] ?? [];

        if (!is_array($opt1ValueNames)) $opt1ValueNames = [];
        if (!is_array($opt2ValueNames)) $opt2ValueNames = [];
        if (!is_array($combinations)) $combinations = [];

        $variationTypeId1 = $this->findOrCreateVariationType($opt1Name);
        $variationTypeId2 = $this->findOrCreateVariationType($opt2Name);

        $keepVariationTypeIds = [$variationTypeId1, $variationTypeId2];
        $stalePos = DB::table('product_variation_types')
            ->where('product_id', $productId)
            ->whereNotIn('variation_type_id', $keepVariationTypeIds)
            ->pluck('product_variation_type_id');

        if ($stalePos->isNotEmpty()) {
            $stalePovIds = DB::table('product_variation_values')
                ->whereIn('product_variation_type_id', $stalePos)
                ->pluck('product_variation_value_id')
                ->map(fn($v) => (int) $v)->all();

            DB::table('product_variation_values')->whereIn('product_variation_type_id', $stalePos)->delete();
            DB::table('product_variation_types')->whereIn('product_variation_type_id', $stalePos)->delete();

            if (!empty($stalePovIds)) {
                DB::table('ch_lazada_listing_variations')
                    ->whereIn('product_variation_value_id', $stalePovIds)
                    ->update(['product_variation_value_id' => null]);
            }
        }

        $poId1 = $this->ensureProductVariationType($productId, $variationTypeId1);
        $poId2 = $this->ensureProductVariationType($productId, $variationTypeId2);

        [$parentPct, $parentAdd] = $this->parentCostFormula($productId, $input);

        $opt1PovMap = $this->syncVariationValues($productId, $variationTypeId1, $poId1, $opt1ValueNames);
        $opt2PovMap = $this->syncVariationValues($productId, $variationTypeId2, $poId2, $opt2ValueNames);

        DB::table('product_variation_parts')
            ->whereIn('product_variation_id', function ($q) use ($productId) {
                $q->select('id')->from('product_variations')->where('product_id', $productId);
            })->delete();
        DB::table('product_variations')->where('product_id', $productId)->delete();

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

            $comboId = DB::table('product_variations')->insertGetId([
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

            DB::table('product_variation_parts')->insert([
                ['product_variation_id' => $comboId, 'product_variation_value_id' => $pov1Id],
                ['product_variation_id' => $comboId, 'product_variation_value_id' => $pov2Id],
            ]);
        }
    }

    private function syncVariationValues(int $productId, int $variationTypeId, int $productVariationTypeId, array $valueNames): array
    {
        $map = [];

        $existingPovs = DB::table('product_variation_values')
            ->where('product_variation_type_id', $productVariationTypeId)
            ->get()
            ->keyBy('variation_value_id');

        $seenVariationValueIds = [];

        foreach ($valueNames as $name) {
            $name = trim($name);
            if ($name === '') continue;

            $variationValueId = $this->findOrCreateVariationValue($variationTypeId, $name);
            $seenVariationValueIds[] = $variationValueId;

            if (!isset($existingPovs[$variationValueId])) {
                $povId = DB::table('product_variation_values')->insertGetId([
                    'product_variation_type_id' => $productVariationTypeId,
                    'product_id' => $productId,
                    'variation_type_id' => $variationTypeId,
                    'variation_value_id' => $variationValueId,
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
                $map[strtolower($name)] = (int) $existingPovs[$variationValueId]->product_variation_value_id;
            }
        }

        foreach ($existingPovs as $ovId => $row) {
            if (!in_array((int) $ovId, $seenVariationValueIds, true)) {
                DB::table('product_variation_values')
                    ->where('product_variation_value_id', $row->product_variation_value_id)
                    ->delete();
            }
        }

        return $map;
    }
}
