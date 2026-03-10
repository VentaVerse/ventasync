<?php

namespace App\Http\Controllers;

use App\Models\Catalog\Product;
use App\Rules\UniqueSku;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductOptionController extends Controller
{
    public function edit(int $id)
    {
        return redirect()->route('products.edit', ['id' => $id, 'tab' => 'options']);
    }

    public function update(Request $request, int $id)
    {
        Product::query()->where('product_id', $id)->firstOrFail();

        $p = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        // Detect mode: 1-option (legacy) vs 2-option (combinations)
        $hasCombinations = $request->has('combinations') && $request->has('option2_name');

        if ($hasCombinations) {
            return $this->updateTwoOption($request, $id, $p, $langId);
        }

        return $this->updateOneOption($request, $id, $p, $langId);
    }

    /**
     * Save 1-option product (same as before + combination sync).
     */
    private function updateOneOption(Request $request, int $id, string $p, int $langId)
    {
        $validated = $request->validate([
            'option_name' => 'nullable|string|max:128',
            'values' => 'nullable|array',
            'values.*.name' => 'required|string|max:128',
            'values.*.sku' => 'nullable|string|max:64',
            'values.*.quantity' => 'nullable|integer',
            'values.*.absolute_price' => 'nullable|numeric',
            'values.*.absolute_cost' => 'nullable|numeric',
            'values.*.option_value_id' => 'nullable|integer',
        ]);

        $optionName = trim($validated['option_name'] ?? '');
        $values = $validated['values'] ?? [];

        if ($optionName === '' || empty($values)) {
            return $this->removeAllOptions($p, $id);
        }

        // Validate SKU uniqueness (cross-product + intra-form + vs parent SKU)
        $parentProduct = DB::table($p . 'product')->where('product_id', $id)->first(['price', 'sku']);
        $basePrice = (float) $parentProduct->price;

        $skuRule = new UniqueSku($id);
        $skuErrors = [];
        $seenSkus = [];
        $parentSku = strtolower(trim((string) ($parentProduct->sku ?? '')));
        if ($parentSku !== '') {
            $seenSkus[$parentSku] = true;
        }
        foreach ($values as $vi => $v) {
            $sku = trim((string) ($v['sku'] ?? ''));
            if ($sku === '') continue;
            $skuLower = strtolower($sku);
            if ($skuLower === $parentSku) {
                $skuErrors[] = "The SKU \"{$sku}\" is already used as this product's parent SKU.";
                continue;
            }
            if (isset($seenSkus[$skuLower])) {
                $skuErrors[] = "Duplicate SKU \"{$sku}\" within this product's options.";
                continue;
            }
            $seenSkus[$skuLower] = true;
            $skuRule->validate("values.{$vi}.sku", $sku, function (string $msg) use (&$skuErrors) {
                $skuErrors[] = $msg;
            });
        }
        if (!empty($skuErrors)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['option_sku' => $skuErrors]);
        }
        $skuChanges = ['product_sku' => null, 'option_skus' => []];

        DB::transaction(function () use ($p, $id, $langId, $optionName, $values, $basePrice, &$skuChanges) {
            $optionId = $this->resolveOptionId($p, $langId, $optionName);

            // Upsert product_option
            $existingPo = DB::table($p . 'product_option')
                ->where('product_id', $id)
                ->first();

            if ($existingPo && (int) $existingPo->option_id === $optionId) {
                $productOptionId = (int) $existingPo->product_option_id;
            } elseif ($existingPo) {
                $this->deleteProductOptionValues($p, $id, (int) $existingPo->product_option_id);
                DB::table($p . 'product_option')->where('product_option_id', $existingPo->product_option_id)->delete();
                $productOptionId = DB::table($p . 'product_option')->insertGetId([
                    'product_id' => $id, 'option_id' => $optionId, 'value' => '', 'required' => 1,
                ]);
            } else {
                $productOptionId = DB::table($p . 'product_option')->insertGetId([
                    'product_id' => $id, 'option_id' => $optionId, 'value' => '', 'required' => 1,
                ]);
            }

            // Index existing POV rows
            $existingPov = DB::table($p . 'product_option_value')
                ->where('product_id', $id)
                ->where('product_option_id', $productOptionId)
                ->get()
                ->keyBy('option_value_id');

            $seenOptionValueIds = [];
            $povIdMap = []; // option_value_id → product_option_value_id (for combo sync)

            foreach ($values as $v) {
                $valueName = trim($v['name']);
                if ($valueName === '') continue;

                $optionValueId = (int) ($v['option_value_id'] ?? 0);
                if ($optionValueId === 0) {
                    $optionValueId = $this->resolveOptionValueId($p, $langId, $optionId, $valueName);
                } else {
                    $this->ensureOptionValueName($p, $langId, $optionValueId, $valueName);
                }

                $seenOptionValueIds[] = $optionValueId;

                $absolutePrice = (float) ($v['absolute_price'] ?? 0);
                $absoluteCost = (float) ($v['absolute_cost'] ?? 0);

                $priceDelta = $absolutePrice - $basePrice;
                $pricePrefix = $priceDelta >= 0 ? '+' : '-';
                $priceValue = abs($priceDelta);

                $data = [
                    'sku' => (string) ($v['sku'] ?? ''),
                    'quantity' => (int) ($v['quantity'] ?? 0),
                    'subtract' => 1,
                    'price' => $priceValue,
                    'price_prefix' => $pricePrefix,
                    'absolute_price' => $absolutePrice,
                    'cost' => $absoluteCost,
                    'cost_prefix' => '+',
                    'cost_amount' => $absoluteCost,
                    'cost_percentage' => 0,
                    'cost_additional' => 0,
                    'absolute_cost' => $absoluteCost,
                ];

                if (isset($existingPov[$optionValueId])) {
                    $existing = $existingPov[$optionValueId];

                    $oldSku = trim((string) ($existing->sku ?? ''));
                    $newSku = $data['sku'];
                    if ($oldSku !== '' && $newSku !== $oldSku) {
                        DB::table('shopee_product_links')
                            ->where('product_id', $id)->where('sku', $oldSku)
                            ->update(['sku' => $newSku]);
                        DB::table('lazada_product_variants')
                            ->whereIn('lazada_product_id', fn($q) => $q->select('id')->from('lazada_products')->where('product_id', $id))
                            ->where('seller_sku', $oldSku)
                            ->update(['seller_sku' => $newSku]);
                        $skuChanges['option_skus'][(int) $existing->product_option_value_id] = ['old' => $oldSku, 'new' => $newSku];
                    }

                    DB::table($p . 'product_option_value')
                        ->where('product_option_value_id', $existing->product_option_value_id)
                        ->update(array_merge($data, ['product_option_id' => $productOptionId]));

                    $povIdMap[$optionValueId] = (int) $existing->product_option_value_id;
                } else {
                    $newPovId = DB::table($p . 'product_option_value')->insertGetId(array_merge($data, [
                        'product_option_id' => $productOptionId,
                        'product_id' => $id,
                        'option_id' => $optionId,
                        'option_value_id' => $optionValueId,
                        'points' => 0, 'points_prefix' => '+',
                        'weight' => 0, 'weight_prefix' => '+',
                    ]));
                    $povIdMap[$optionValueId] = $newPovId;
                }
            }

            // Remove option values no longer in the list
            $deletedPovIds = [];
            foreach ($existingPov as $ovId => $row) {
                if (!in_array((int) $ovId, $seenOptionValueIds, true)) {
                    $deletedPovIds[] = (int) $row->product_option_value_id;
                    DB::table($p . 'product_option_value')
                        ->where('product_option_value_id', $row->product_option_value_id)
                        ->delete();
                }
            }

            // Remove extra product_option groups (enforce max 1 for 1-option mode)
            $otherPo = DB::table($p . 'product_option')
                ->where('product_id', $id)
                ->where('product_option_id', '!=', $productOptionId)
                ->get();

            foreach ($otherPo as $po) {
                $otherPovIds = DB::table($p . 'product_option_value')
                    ->where('product_option_id', $po->product_option_id)
                    ->pluck('product_option_value_id')->map(fn($v) => (int) $v)->all();
                $deletedPovIds = array_merge($deletedPovIds, $otherPovIds);
                DB::table($p . 'product_option_value')->where('product_option_id', $po->product_option_id)->delete();
                DB::table($p . 'product_option')->where('product_option_id', $po->product_option_id)->delete();
            }

            // Clean stale downstream references
            if (!empty($deletedPovIds)) {
                DB::table('lazada_product_variants')
                    ->whereIn('product_option_value_id', $deletedPovIds)
                    ->update(['product_option_value_id' => null]);
                // Delete combinations for deleted POVs
                $this->deleteCombinationsForPovIds($deletedPovIds);
            }

            // Sync combinations: 1 combo per POV (1-option mode)
            // Build resolved list with actual pov_ids (no re-matching needed)
            $resolvedValues = [];
            foreach ($values as $v) {
                $valueName = trim($v['name'] ?? '');
                if ($valueName === '') continue;
                $ovId = (int) ($v['option_value_id'] ?? 0);
                if ($ovId === 0) {
                    $ovId = $this->resolveOptionValueId($p, $langId, $optionId, $valueName);
                }
                $povId = $povIdMap[$ovId] ?? null;
                if (!$povId) continue;
                $resolvedValues[] = [
                    'pov_id' => $povId,
                    'sku' => (string) ($v['sku'] ?? ''),
                    'quantity' => (int) ($v['quantity'] ?? 0),
                    'absolute_price' => (float) ($v['absolute_price'] ?? 0),
                    'absolute_cost' => (float) ($v['absolute_cost'] ?? 0),
                ];
            }
            $this->syncOneOptionCombinations($id, $resolvedValues);
        });

        $status = 'Options saved';
        if (!empty($skuChanges['option_skus'])) {
            $syncMessages = (new \App\Services\SkuSyncService())->syncSkuChanges($id, $skuChanges);
            if (!empty($syncMessages)) {
                $status .= ' | SKU synced: ' . implode(' | ', $syncMessages);
            }
        }

        ActivityLogger::log('updated', 'Product Options', $id, 'Product #' . $id);

        return redirect()->route('products.options.edit', $id)->with('status', $status);
    }

    /**
     * Save 2-option product with explicit combinations.
     */
    private function updateTwoOption(Request $request, int $id, string $p, int $langId)
    {
        $validated = $request->validate([
            'option1_name' => 'required|string|max:128',
            'option1_values' => 'required|array|min:1',
            'option1_values.*' => 'required|string|max:128',
            'option2_name' => 'required|string|max:128',
            'option2_values' => 'required|array|min:1',
            'option2_values.*' => 'required|string|max:128',
            'combinations' => 'required|array|min:1',
            'combinations.*.opt1' => 'required|string|max:128',
            'combinations.*.opt2' => 'required|string|max:128',
            'combinations.*.sku' => 'nullable|string|max:64',
            'combinations.*.quantity' => 'nullable|integer',
            'combinations.*.absolute_price' => 'nullable|numeric',
            'combinations.*.absolute_cost' => 'nullable|numeric',
        ]);

        // Validate SKU uniqueness (cross-product + intra-form + vs parent SKU)
        $parentProduct = DB::table($p . 'product')->where('product_id', $id)->first(['price', 'sku']);
        $basePrice = (float) $parentProduct->price;

        $skuRule = new UniqueSku($id);
        $skuErrors = [];
        $seenSkus = [];
        $parentSku = strtolower(trim((string) ($parentProduct->sku ?? '')));
        if ($parentSku !== '') {
            $seenSkus[$parentSku] = true;
        }
        foreach ($validated['combinations'] as $ci => $c) {
            $sku = trim((string) ($c['sku'] ?? ''));
            if ($sku === '') continue;
            $skuLower = strtolower($sku);
            if ($skuLower === $parentSku) {
                $skuErrors[] = "The SKU \"{$sku}\" is already used as this product's parent SKU.";
                continue;
            }
            if (isset($seenSkus[$skuLower])) {
                $skuErrors[] = "Duplicate SKU \"{$sku}\" within this product's combinations.";
                continue;
            }
            $seenSkus[$skuLower] = true;
            $skuRule->validate("combinations.{$ci}.sku", $sku, function (string $msg) use (&$skuErrors) {
                $skuErrors[] = $msg;
            });
        }
        if (!empty($skuErrors)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['option_sku' => $skuErrors]);
        }

        DB::transaction(function () use ($p, $id, $langId, $validated, $basePrice) {
            $opt1Name = trim($validated['option1_name']);
            $opt2Name = trim($validated['option2_name']);
            $opt1Values = array_map('trim', $validated['option1_values']);
            $opt2Values = array_map('trim', $validated['option2_values']);

            // Resolve both option groups
            $optionId1 = $this->resolveOptionId($p, $langId, $opt1Name);
            $optionId2 = $this->resolveOptionId($p, $langId, $opt2Name);

            // Clean existing product_option entries for this product
            $existingPos = DB::table($p . 'product_option')->where('product_id', $id)->get();
            $oldPovIds = [];
            foreach ($existingPos as $po) {
                $ids = DB::table($p . 'product_option_value')
                    ->where('product_option_id', $po->product_option_id)
                    ->pluck('product_option_value_id')->map(fn($v) => (int) $v)->all();
                $oldPovIds = array_merge($oldPovIds, $ids);
            }
            DB::table($p . 'product_option_value')->where('product_id', $id)->delete();
            DB::table($p . 'product_option')->where('product_id', $id)->delete();

            // Clean stale downstream refs
            if (!empty($oldPovIds)) {
                DB::table('lazada_product_variants')
                    ->whereIn('product_option_value_id', $oldPovIds)
                    ->update(['product_option_value_id' => null]);
                $this->deleteCombinationsForPovIds($oldPovIds);
            }

            // Delete existing combinations for this product
            DB::table('product_option_combination_values')
                ->whereIn('combination_id', function ($q) use ($id) {
                    $q->select('id')->from('product_option_combinations')->where('product_id', $id);
                })
                ->delete();
            DB::table('product_option_combinations')->where('product_id', $id)->delete();

            // Create product_option rows
            $poId1 = DB::table($p . 'product_option')->insertGetId([
                'product_id' => $id, 'option_id' => $optionId1, 'value' => '', 'required' => 1,
            ]);
            $poId2 = DB::table($p . 'product_option')->insertGetId([
                'product_id' => $id, 'option_id' => $optionId2, 'value' => '', 'required' => 1,
            ]);

            // Resolve all option values and create POV rows
            // Map: value_name → { option_value_id, product_option_value_id }
            $pov1Map = []; // opt1 value name → pov_id
            $pov2Map = []; // opt2 value name → pov_id

            foreach ($opt1Values as $vName) {
                $ovId = $this->resolveOptionValueId($p, $langId, $optionId1, $vName);
                $povId = DB::table($p . 'product_option_value')->insertGetId([
                    'product_option_id' => $poId1, 'product_id' => $id,
                    'option_id' => $optionId1, 'option_value_id' => $ovId,
                    'sku' => '', 'quantity' => 0, 'subtract' => 1,
                    'price' => 0, 'price_prefix' => '+', 'absolute_price' => 0,
                    'cost' => 0, 'cost_prefix' => '+', 'cost_amount' => 0,
                    'cost_percentage' => 0, 'cost_additional' => 0, 'absolute_cost' => 0,
                    'points' => 0, 'points_prefix' => '+', 'weight' => 0, 'weight_prefix' => '+',
                ]);
                $pov1Map[strtolower($vName)] = $povId;
            }

            foreach ($opt2Values as $vName) {
                $ovId = $this->resolveOptionValueId($p, $langId, $optionId2, $vName);
                $povId = DB::table($p . 'product_option_value')->insertGetId([
                    'product_option_id' => $poId2, 'product_id' => $id,
                    'option_id' => $optionId2, 'option_value_id' => $ovId,
                    'sku' => '', 'quantity' => 0, 'subtract' => 1,
                    'price' => 0, 'price_prefix' => '+', 'absolute_price' => 0,
                    'cost' => 0, 'cost_prefix' => '+', 'cost_amount' => 0,
                    'cost_percentage' => 0, 'cost_additional' => 0, 'absolute_cost' => 0,
                    'points' => 0, 'points_prefix' => '+', 'weight' => 0, 'weight_prefix' => '+',
                ]);
                $pov2Map[strtolower($vName)] = $povId;
            }

            // Create combination rows
            $now = now();
            $sortOrder = 0;

            // Aggregate data for POV back-sync: pov_id → [prices[], quantities[]]
            $pov1Agg = [];
            $pov2Agg = [];

            foreach ($validated['combinations'] as $c) {
                $opt1Val = strtolower(trim($c['opt1']));
                $opt2Val = strtolower(trim($c['opt2']));
                $povId1 = $pov1Map[$opt1Val] ?? null;
                $povId2 = $pov2Map[$opt2Val] ?? null;
                if (!$povId1 || !$povId2) continue;

                $absPrice = (float) ($c['absolute_price'] ?? 0);
                $absCost = (float) ($c['absolute_cost'] ?? 0);
                $qty = (int) ($c['quantity'] ?? 0);
                $sku = (string) ($c['sku'] ?? '');

                $comboId = DB::table('product_option_combinations')->insertGetId([
                    'product_id' => $id,
                    'sku' => $sku,
                    'quantity' => $qty,
                    'absolute_price' => $absPrice,
                    'absolute_cost' => $absCost,
                    'subtract' => 1,
                    'sort_order' => $sortOrder++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('product_option_combination_values')->insert([
                    ['combination_id' => $comboId, 'product_option_value_id' => $povId1],
                    ['combination_id' => $comboId, 'product_option_value_id' => $povId2],
                ]);

                // Aggregate for POV back-sync
                $pov1Agg[$povId1]['prices'][] = $absPrice;
                $pov1Agg[$povId1]['costs'][] = $absCost;
                $pov1Agg[$povId1]['qty'] = ($pov1Agg[$povId1]['qty'] ?? 0) + $qty;

                $pov2Agg[$povId2]['prices'][] = $absPrice;
                $pov2Agg[$povId2]['costs'][] = $absCost;
                $pov2Agg[$povId2]['qty'] = ($pov2Agg[$povId2]['qty'] ?? 0) + $qty;
            }

            // Back-sync POV rows with aggregated data (for OC compatibility)
            $this->backSyncPov($p, $pov1Agg, $basePrice);
            $this->backSyncPov($p, $pov2Agg, $basePrice);
        });

        ActivityLogger::log('updated', 'Product Options', $id, 'Product #' . $id . ' — 2-option');

        return redirect()->route('products.options.edit', $id)->with('status', 'Options saved (2-option combination).');
    }

    /**
     * Back-sync POV rows from aggregated combination data.
     * Sets POV quantity = sum of combo quantities, price = average combo price.
     */
    private function backSyncPov(string $p, array $povAgg, float $basePrice): void
    {
        foreach ($povAgg as $povId => $agg) {
            $avgPrice = array_sum($agg['prices']) / count($agg['prices']);
            $avgCost = array_sum($agg['costs']) / count($agg['costs']);
            $totalQty = $agg['qty'];

            $priceDelta = $avgPrice - $basePrice;

            DB::table($p . 'product_option_value')
                ->where('product_option_value_id', $povId)
                ->update([
                    'quantity' => $totalQty,
                    'absolute_price' => $avgPrice,
                    'absolute_cost' => $avgCost,
                    'price' => abs($priceDelta),
                    'price_prefix' => $priceDelta >= 0 ? '+' : '-',
                    'cost' => $avgCost,
                    'cost_amount' => $avgCost,
                    'cost_percentage' => 0,
                    'cost_additional' => 0,
                ]);
        }
    }

    /**
     * Sync combination rows for 1-option products.
     * Each POV maps 1:1 to a combination.
     *
     * @param array $resolvedValues [{pov_id, sku, quantity, absolute_price, absolute_cost}, ...]
     */
    private function syncOneOptionCombinations(int $productId, array $resolvedValues): void
    {
        $now = now();

        // Build a lookup: pov_id → existing combination_id
        $allPovIds = array_column($resolvedValues, 'pov_id');
        $existingCombos = [];
        if (!empty($allPovIds)) {
            $rows = DB::table('product_option_combination_values as cv')
                ->join('product_option_combinations as c', 'cv.combination_id', '=', 'c.id')
                ->whereIn('cv.product_option_value_id', $allPovIds)
                ->where('c.product_id', $productId)
                ->get(['cv.product_option_value_id', 'c.id as combination_id']);

            foreach ($rows as $r) {
                $existingCombos[(int) $r->product_option_value_id] = (int) $r->combination_id;
            }
        }

        $sortOrder = 0;
        $seenComboIds = [];

        foreach ($resolvedValues as $rv) {
            $povId = $rv['pov_id'];

            if (isset($existingCombos[$povId])) {
                $comboId = $existingCombos[$povId];
                DB::table('product_option_combinations')
                    ->where('id', $comboId)
                    ->update([
                        'sku' => $rv['sku'],
                        'quantity' => $rv['quantity'],
                        'absolute_price' => $rv['absolute_price'],
                        'absolute_cost' => $rv['absolute_cost'],
                        'sort_order' => $sortOrder++,
                        'updated_at' => $now,
                    ]);
                $seenComboIds[] = $comboId;
            } else {
                $comboId = DB::table('product_option_combinations')->insertGetId([
                    'product_id' => $productId,
                    'sku' => $rv['sku'],
                    'quantity' => $rv['quantity'],
                    'absolute_price' => $rv['absolute_price'],
                    'absolute_cost' => $rv['absolute_cost'],
                    'subtract' => 1,
                    'sort_order' => $sortOrder++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('product_option_combination_values')->insert([
                    'combination_id' => $comboId,
                    'product_option_value_id' => $povId,
                ]);
                $seenComboIds[] = $comboId;
            }
        }

        // Delete orphan combinations for this product that weren't seen
        $orphanCombos = DB::table('product_option_combinations')
            ->where('product_id', $productId)
            ->when(!empty($seenComboIds), fn($q) => $q->whereNotIn('id', $seenComboIds))
            ->pluck('id')->all();

        if (!empty($orphanCombos)) {
            DB::table('product_option_combination_values')->whereIn('combination_id', $orphanCombos)->delete();
            DB::table('product_option_combinations')->whereIn('id', $orphanCombos)->delete();
        }
    }

    /**
     * Delete combinations that reference given POV IDs.
     */
    private function deleteCombinationsForPovIds(array $povIds): void
    {
        if (empty($povIds)) return;

        $comboIds = DB::table('product_option_combination_values')
            ->whereIn('product_option_value_id', $povIds)
            ->pluck('combination_id')
            ->unique()
            ->all();

        if (!empty($comboIds)) {
            DB::table('product_option_combination_values')->whereIn('combination_id', $comboIds)->delete();
            DB::table('product_option_combinations')->whereIn('id', $comboIds)->delete();
        }
    }

    // --- Shared helpers ---

    private function resolveOptionId(string $p, int $langId, string $name): int
    {
        $existing = DB::table($p . 'option as o')
            ->join($p . 'option_description as od', function ($j) use ($langId) {
                $j->on('o.option_id', '=', 'od.option_id')
                    ->where('od.language_id', '=', $langId);
            })
            ->whereRaw('LOWER(od.name) = ?', [strtolower($name)])
            ->value('o.option_id');

        if ($existing) return (int) $existing;

        $optionId = DB::table($p . 'option')->insertGetId([
            'type' => 'select', 'sort_order' => 0,
        ]);

        DB::table($p . 'option_description')->insert([
            'option_id' => $optionId, 'language_id' => $langId, 'name' => $name,
        ]);

        return $optionId;
    }

    private function resolveOptionValueId(string $p, int $langId, int $optionId, string $name): int
    {
        $existing = DB::table($p . 'option_value as ov')
            ->join($p . 'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('ov.option_value_id', '=', 'ovd.option_value_id')
                    ->where('ovd.language_id', '=', $langId);
            })
            ->where('ov.option_id', $optionId)
            ->whereRaw('LOWER(ovd.name) = ?', [strtolower($name)])
            ->value('ov.option_value_id');

        if ($existing) return (int) $existing;

        $optionValueId = DB::table($p . 'option_value')->insertGetId([
            'option_id' => $optionId, 'image' => '', 'sort_order' => 0,
        ]);

        DB::table($p . 'option_value_description')->insert([
            'option_value_id' => $optionValueId, 'language_id' => $langId, 'name' => $name,
        ]);

        return $optionValueId;
    }

    private function ensureOptionValueName(string $p, int $langId, int $optionValueId, string $name): void
    {
        DB::table($p . 'option_value_description')
            ->where('option_value_id', $optionValueId)
            ->where('language_id', $langId)
            ->update(['name' => $name]);
    }

    private function deleteProductOptionValues(string $p, int $productId, int $productOptionId): void
    {
        $povIds = DB::table($p . 'product_option_value')
            ->where('product_option_id', $productOptionId)
            ->pluck('product_option_value_id')->map(fn($v) => (int) $v)->all();

        DB::table($p . 'product_option_value')->where('product_option_id', $productOptionId)->delete();

        if (!empty($povIds)) {
            DB::table('lazada_product_variants')
                ->whereIn('product_option_value_id', $povIds)
                ->update(['product_option_value_id' => null]);
            $this->deleteCombinationsForPovIds($povIds);
        }
    }

    private function removeAllOptions(string $p, int $id): \Illuminate\Http\RedirectResponse
    {
        DB::transaction(function () use ($p, $id) {
            $poIds = DB::table($p . 'product_option')
                ->where('product_id', $id)->pluck('product_option_id')->all();

            if (!empty($poIds)) {
                $povIds = DB::table($p . 'product_option_value')
                    ->whereIn('product_option_id', $poIds)
                    ->pluck('product_option_value_id')->map(fn($v) => (int) $v)->all();

                DB::table($p . 'product_option_value')->whereIn('product_option_id', $poIds)->delete();
                DB::table($p . 'product_option')->whereIn('product_option_id', $poIds)->delete();

                if (!empty($povIds)) {
                    DB::table('lazada_product_variants')
                        ->whereIn('product_option_value_id', $povIds)
                        ->update(['product_option_value_id' => null]);
                    $this->deleteCombinationsForPovIds($povIds);
                }
            }

            // Delete all combinations for this product
            DB::table('product_option_combination_values')
                ->whereIn('combination_id', function ($q) use ($id) {
                    $q->select('id')->from('product_option_combinations')->where('product_id', $id);
                })
                ->delete();
            DB::table('product_option_combinations')->where('product_id', $id)->delete();
        });

        ActivityLogger::log('updated', 'Product Options', $id, 'Product #' . $id . ' — options removed');

        return redirect()->route('products.options.edit', $id)->with('status', 'All options removed.');
    }
}
