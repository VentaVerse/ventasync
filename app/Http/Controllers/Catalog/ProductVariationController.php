<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Rules\UniqueSku;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductVariationController extends Controller
{
    public function edit(int $id)
    {
        return redirect()->route('products.edit', ['id' => $id, 'tab' => 'variations']);
    }

    public function update(Request $request, int $id)
    {
        Product::query()->where('product_id', $id)->firstOrFail();
        $langId = (int) config('catalog.default_language_id');

        $hasCombinations = $request->has('combinations') && $request->has('type2_name');

        if ($hasCombinations) {
            return $this->updateTwoTypes($request, $id, $langId);
        }

        return $this->updateOneType($request, $id, $langId);
    }

    private function updateOneType(Request $request, int $id, int $langId)
    {
        $validated = $request->validate([
            'variation_type_name' => 'nullable|string|max:128',
            'values' => 'nullable|array',
            'values.*.name' => 'required|string|max:128',
            'values.*.sku' => 'nullable|string|max:64',
            'values.*.image' => 'nullable|string|max:255',
            'values.*.status' => 'nullable|boolean',
            'values.*.quantity' => 'nullable|integer',
            'values.*.absolute_price' => 'nullable|numeric',
            'values.*.absolute_cost' => 'nullable|numeric',
            'values.*.variation_value_id' => 'nullable|integer',
        ]);

        $variationTypeName = trim($validated['variation_type_name'] ?? '');
        $values = $validated['values'] ?? [];

        if ($variationTypeName === '' || empty($values)) {
            return $this->removeAllVariations( $id);
        }

        $parentProduct = DB::table('products')->where('product_id', $id)->first(['price', 'sku']);
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
                $skuErrors[] = "Duplicate SKU \"{$sku}\" within this product's variations.";
                continue;
            }
            $seenSkus[$skuLower] = true;
            $skuRule->validate("values.{$vi}.sku", $sku, function (string $msg) use (&$skuErrors) {
                $skuErrors[] = $msg;
            });
        }
        if (!empty($skuErrors)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['variation_sku' => $skuErrors]);
        }
        $skuChanges = ['product_sku' => null, 'variation_skus' => []];
        $variationsBefore = \App\Services\VariationForgetService::snapshot($id);

        DB::transaction(function () use ( $id, $langId, $variationTypeName, $values, $basePrice, &$skuChanges) {
            $variationTypeId = $this->resolveOptionId( $langId, $variationTypeName);

            $existingPo = DB::table('product_variation_types')
                ->where('product_id', $id)
                ->first();

            if ($existingPo && (int) $existingPo->variation_type_id === $variationTypeId) {
                $productVariationTypeId = (int) $existingPo->product_variation_type_id;
            } elseif ($existingPo) {
                $this->deleteProductVariationValues( $id, (int) $existingPo->product_variation_type_id);
                DB::table('product_variation_types')->where('product_variation_type_id', $existingPo->product_variation_type_id)->delete();
                $productVariationTypeId = DB::table('product_variation_types')->insertGetId([
                    'product_id' => $id, 'variation_type_id' => $variationTypeId, 'value' => '', 'required' => 1,
                ]);
            } else {
                $productVariationTypeId = DB::table('product_variation_types')->insertGetId([
                    'product_id' => $id, 'variation_type_id' => $variationTypeId, 'value' => '', 'required' => 1,
                ]);
            }

            $existingPov = DB::table('product_variation_values')
                ->where('product_id', $id)
                ->where('product_variation_type_id', $productVariationTypeId)
                ->get()
                ->keyBy('variation_value_id');

            $seenVariationValueIds = [];
            $povIdMap = [];

            foreach ($values as $v) {
                $valueName = trim($v['name']);
                if ($valueName === '') continue;

                $variationValueId = (int) ($v['variation_value_id'] ?? 0);
                if ($variationValueId === 0) {
                    $variationValueId = $this->resolveVariationValueId( $langId, $variationTypeId, $valueName);
                } else {
                    $this->ensureVariationValueName( $langId, $variationValueId, $valueName);
                }

                $seenVariationValueIds[] = $variationValueId;

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

                if (isset($existingPov[$variationValueId])) {
                    $existing = $existingPov[$variationValueId];

                    $oldSku = trim((string) ($existing->sku ?? ''));
                    $newSku = $data['sku'];
                    if ($oldSku !== '' && $newSku !== $oldSku) {
                        DB::table('ch_shopee_listing_links')
                            ->where('product_id', $id)->where('sku', $oldSku)
                            ->update(['sku' => $newSku]);
                        DB::table('ch_lazada_listing_variations')
                            ->whereIn('lazada_listing_id', fn($q) => $q->select('id')->from('ch_lazada_listings')->where('product_id', $id))
                            ->where('seller_sku', $oldSku)
                            ->update(['seller_sku' => $newSku]);
                        $skuChanges['variation_skus'][(int) $existing->product_variation_value_id] = ['old' => $oldSku, 'new' => $newSku];
                    }

                    DB::table('product_variation_values')
                        ->where('product_variation_value_id', $existing->product_variation_value_id)
                        ->update(array_merge($data, ['product_variation_type_id' => $productVariationTypeId]));

                    $povIdMap[$variationValueId] = (int) $existing->product_variation_value_id;
                } else {
                    $newPovId = DB::table('product_variation_values')->insertGetId(array_merge($data, [
                        'product_variation_type_id' => $productVariationTypeId,
                        'product_id' => $id,
                        'variation_type_id' => $variationTypeId,
                        'variation_value_id' => $variationValueId,
                        'points' => 0, 'points_prefix' => '+',
                        'weight' => 0, 'weight_prefix' => '+',
                    ]));
                    $povIdMap[$variationValueId] = $newPovId;
                }
            }

            $deletedPovIds = [];
            foreach ($existingPov as $ovId => $row) {
                if (!in_array((int) $ovId, $seenVariationValueIds, true)) {
                    $deletedPovIds[] = (int) $row->product_variation_value_id;
                    DB::table('product_variation_values')
                        ->where('product_variation_value_id', $row->product_variation_value_id)
                        ->delete();
                }
            }

            $otherPo = DB::table('product_variation_types')
                ->where('product_id', $id)
                ->where('product_variation_type_id', '!=', $productVariationTypeId)
                ->get();

            foreach ($otherPo as $po) {
                $otherPovIds = DB::table('product_variation_values')
                    ->where('product_variation_type_id', $po->product_variation_type_id)
                    ->pluck('product_variation_value_id')->map(fn($v) => (int) $v)->all();
                $deletedPovIds = array_merge($deletedPovIds, $otherPovIds);
                DB::table('product_variation_values')->where('product_variation_type_id', $po->product_variation_type_id)->delete();
                DB::table('product_variation_types')->where('product_variation_type_id', $po->product_variation_type_id)->delete();
            }

            if (!empty($deletedPovIds)) {
                DB::table('ch_lazada_listing_variations')
                    ->whereIn('product_variation_value_id', $deletedPovIds)
                    ->update(['product_variation_value_id' => null]);
                $this->deleteCombinationsForPovIds($deletedPovIds);
            }

            $resolvedValues = [];
            foreach ($values as $v) {
                $valueName = trim($v['name'] ?? '');
                if ($valueName === '') continue;
                $ovId = (int) ($v['variation_value_id'] ?? 0);
                if ($ovId === 0) {
                    $ovId = $this->resolveVariationValueId( $langId, $variationTypeId, $valueName);
                }
                $povId = $povIdMap[$ovId] ?? null;
                if (!$povId) continue;
                $resolvedValues[] = [
                    'pov_id' => $povId,
                    'sku' => (string) ($v['sku'] ?? ''),
                    'image' => trim((string) ($v['image'] ?? '')),
                    'status' => array_key_exists('status', $v) ? (int) (bool) $v['status'] : 1,
                    'quantity' => (int) ($v['quantity'] ?? 0),
                    'absolute_price' => (float) ($v['absolute_price'] ?? 0),
                    'absolute_cost' => (float) ($v['absolute_cost'] ?? 0),
                ];
            }
            $this->syncOneTypeCombinations($id, $resolvedValues);
        });

        $status = 'Variations saved';
        if (!empty($skuChanges['variation_skus'])) {
            $syncMessages = (new \App\Services\SkuSyncService())->syncSkuChanges($id, $skuChanges);
            if (!empty($syncMessages)) {
                $status .= ' | SKU synced: ' . implode(' | ', $syncMessages);
            }
        }
        $forgotten = (new \App\Services\VariationForgetService())->reconcile($id, $variationsBefore);
        if ($forgotten !== []) {
            $status .= ' | Forgotten on every channel: ' . implode(', ', $forgotten);
        }
        ActivityLogger::log('updated', 'Product Variations', $id, 'Product #' . $id);

        return redirect()->route('products.variations.edit', $id)->with('status', $status);
    }

    private function updateTwoTypes(Request $request, int $id, int $langId)
    {
        $validated = $request->validate([
            'type1_name' => 'required|string|max:128',
            'type1_values' => 'required|array|min:1',
            'type1_values.*' => 'required|string|max:128',
            'type2_name' => 'required|string|max:128',
            'type2_values' => 'required|array|min:1',
            'type2_values.*' => 'required|string|max:128',
            'combinations' => 'required|array|min:1',
            'combinations.*.opt1' => 'required|string|max:128',
            'combinations.*.opt2' => 'required|string|max:128',
            'combinations.*.sku' => 'nullable|string|max:64',
            'combinations.*.image' => 'nullable|string|max:255',
            'combinations.*.status' => 'nullable|boolean',
            'combinations.*.quantity' => 'nullable|integer',
            'combinations.*.absolute_price' => 'nullable|numeric',
            'combinations.*.absolute_cost' => 'nullable|numeric',
        ]);

        $parentProduct = DB::table('products')->where('product_id', $id)->first(['price', 'sku']);
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
            throw \Illuminate\Validation\ValidationException::withMessages(['variation_sku' => $skuErrors]);
        }

        DB::transaction(function () use ( $id, $langId, $validated, $basePrice) {
            $opt1Name = trim($validated['type1_name']);
            $opt2Name = trim($validated['type2_name']);
            $opt1Values = array_map('trim', $validated['type1_values']);
            $opt2Values = array_map('trim', $validated['type2_values']);

            $variationTypeId1 = $this->resolveOptionId( $langId, $opt1Name);
            $variationTypeId2 = $this->resolveOptionId( $langId, $opt2Name);

            $existingPos = DB::table('product_variation_types')->where('product_id', $id)->get();
            $oldPovIds = [];
            foreach ($existingPos as $po) {
                $ids = DB::table('product_variation_values')
                    ->where('product_variation_type_id', $po->product_variation_type_id)
                    ->pluck('product_variation_value_id')->map(fn($v) => (int) $v)->all();
                $oldPovIds = array_merge($oldPovIds, $ids);
            }
            DB::table('product_variation_values')->where('product_id', $id)->delete();
            DB::table('product_variation_types')->where('product_id', $id)->delete();

            if (!empty($oldPovIds)) {
                DB::table('ch_lazada_listing_variations')
                    ->whereIn('product_variation_value_id', $oldPovIds)
                    ->update(['product_variation_value_id' => null]);
                $this->deleteCombinationsForPovIds($oldPovIds);
            }

            DB::table('product_variation_parts')
                ->whereIn('product_variation_id', function ($q) use ($id) {
                    $q->select('id')->from('product_variations')->where('product_id', $id);
                })
                ->delete();
            DB::table('product_variations')->where('product_id', $id)->delete();

            $poId1 = DB::table('product_variation_types')->insertGetId([
                'product_id' => $id, 'variation_type_id' => $variationTypeId1, 'value' => '', 'required' => 1,
            ]);
            $poId2 = DB::table('product_variation_types')->insertGetId([
                'product_id' => $id, 'variation_type_id' => $variationTypeId2, 'value' => '', 'required' => 1,
            ]);

            $pov1Map = [];
            $pov2Map = [];

            foreach ($opt1Values as $vName) {
                $ovId = $this->resolveVariationValueId( $langId, $variationTypeId1, $vName);
                $povId = DB::table('product_variation_values')->insertGetId([
                    'product_variation_type_id' => $poId1, 'product_id' => $id,
                    'variation_type_id' => $variationTypeId1, 'variation_value_id' => $ovId,
                    'sku' => '', 'quantity' => 0, 'subtract' => 1,
                    'price' => 0, 'price_prefix' => '+', 'absolute_price' => 0,
                    'cost' => 0, 'cost_prefix' => '+', 'cost_amount' => 0,
                    'cost_percentage' => 0, 'cost_additional' => 0, 'absolute_cost' => 0,
                    'points' => 0, 'points_prefix' => '+', 'weight' => 0, 'weight_prefix' => '+',
                ]);
                $pov1Map[strtolower($vName)] = $povId;
            }

            foreach ($opt2Values as $vName) {
                $ovId = $this->resolveVariationValueId( $langId, $variationTypeId2, $vName);
                $povId = DB::table('product_variation_values')->insertGetId([
                    'product_variation_type_id' => $poId2, 'product_id' => $id,
                    'variation_type_id' => $variationTypeId2, 'variation_value_id' => $ovId,
                    'sku' => '', 'quantity' => 0, 'subtract' => 1,
                    'price' => 0, 'price_prefix' => '+', 'absolute_price' => 0,
                    'cost' => 0, 'cost_prefix' => '+', 'cost_amount' => 0,
                    'cost_percentage' => 0, 'cost_additional' => 0, 'absolute_cost' => 0,
                    'points' => 0, 'points_prefix' => '+', 'weight' => 0, 'weight_prefix' => '+',
                ]);
                $pov2Map[strtolower($vName)] = $povId;
            }

            $now = now();
            $sortOrder = 0;

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
                $image = trim((string) ($c['image'] ?? ''));
                $status = array_key_exists('status', $c) ? (int) (bool) $c['status'] : 1;

                $comboId = DB::table('product_variations')->insertGetId([
                    'product_id' => $id,
                    'sku' => $sku,
                    'status' => $status,
                    'image' => $image !== '' ? $image : null,
                    'quantity' => $qty,
                    'absolute_price' => $absPrice,
                    'absolute_cost' => $absCost,
                    'subtract' => 1,
                    'sort_order' => $sortOrder++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('product_variation_parts')->insert([
                    ['product_variation_id' => $comboId, 'product_variation_value_id' => $povId1],
                    ['product_variation_id' => $comboId, 'product_variation_value_id' => $povId2],
                ]);

                $pov1Agg[$povId1]['prices'][] = $absPrice;
                $pov1Agg[$povId1]['costs'][] = $absCost;
                $pov1Agg[$povId1]['qty'] = ($pov1Agg[$povId1]['qty'] ?? 0) + $qty;

                $pov2Agg[$povId2]['prices'][] = $absPrice;
                $pov2Agg[$povId2]['costs'][] = $absCost;
                $pov2Agg[$povId2]['qty'] = ($pov2Agg[$povId2]['qty'] ?? 0) + $qty;
            }

            $this->backSyncPov( $pov1Agg, $basePrice);
            $this->backSyncPov( $pov2Agg, $basePrice);
        });

        ActivityLogger::log('updated', 'Product Variations', $id, 'Product #' . $id . ': two variation types');

        return redirect()->route('products.variations.edit', $id)->with('status', 'Variations saved.');
    }

    private function backSyncPov( array $povAgg, float $basePrice): void
    {
        foreach ($povAgg as $povId => $agg) {
            $avgPrice = array_sum($agg['prices']) / count($agg['prices']);
            $avgCost = array_sum($agg['costs']) / count($agg['costs']);
            $totalQty = $agg['qty'];

            $priceDelta = $avgPrice - $basePrice;

            DB::table('product_variation_values')
                ->where('product_variation_value_id', $povId)
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

    private function syncOneTypeCombinations(int $productId, array $resolvedValues): void
    {
        $now = now();

        $allPovIds = array_column($resolvedValues, 'pov_id');
        $existingCombos = [];
        if (!empty($allPovIds)) {
            $rows = DB::table('product_variation_parts as cv')
                ->join('product_variations as c', 'cv.product_variation_id', '=', 'c.id')
                ->whereIn('cv.product_variation_value_id', $allPovIds)
                ->where('c.product_id', $productId)
                ->get(['cv.product_variation_value_id', 'c.id as product_variation_id']);

            foreach ($rows as $r) {
                $existingCombos[(int) $r->product_variation_value_id] = (int) $r->product_variation_id;
            }
        }

        $sortOrder = 0;
        $seenComboIds = [];

        foreach ($resolvedValues as $rv) {
            $povId = $rv['pov_id'];

            if (isset($existingCombos[$povId])) {
                $comboId = $existingCombos[$povId];
                DB::table('product_variations')
                    ->where('id', $comboId)
                    ->update([
                        'sku' => $rv['sku'],
                        'status' => $rv['status'] ?? 1,
                        'image' => $rv['image'] !== '' ? $rv['image'] : null,
                        'quantity' => $rv['quantity'],
                        'absolute_price' => $rv['absolute_price'],
                        'absolute_cost' => $rv['absolute_cost'],
                        'sort_order' => $sortOrder++,
                        'updated_at' => $now,
                    ]);
                $seenComboIds[] = $comboId;
            } else {
                $comboId = DB::table('product_variations')->insertGetId([
                    'product_id' => $productId,
                    'sku' => $rv['sku'],
                    'status' => $rv['status'] ?? 1,
                    'image' => $rv['image'] !== '' ? $rv['image'] : null,
                    'quantity' => $rv['quantity'],
                    'absolute_price' => $rv['absolute_price'],
                    'absolute_cost' => $rv['absolute_cost'],
                    'subtract' => 1,
                    'sort_order' => $sortOrder++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('product_variation_parts')->insert([
                    'product_variation_id' => $comboId,
                    'product_variation_value_id' => $povId,
                ]);
                $seenComboIds[] = $comboId;
            }
        }

        $orphanCombos = DB::table('product_variations')
            ->where('product_id', $productId)
            ->when(!empty($seenComboIds), fn($q) => $q->whereNotIn('id', $seenComboIds))
            ->pluck('id')->all();

        if (!empty($orphanCombos)) {
            DB::table('product_variation_parts')->whereIn('product_variation_id', $orphanCombos)->delete();
            DB::table('product_variations')->whereIn('id', $orphanCombos)->delete();
        }
    }

    private function deleteCombinationsForPovIds(array $povIds): void
    {
        if (empty($povIds)) return;

        $comboIds = DB::table('product_variation_parts')
            ->whereIn('product_variation_value_id', $povIds)
            ->pluck('product_variation_id')
            ->unique()
            ->all();

        if (!empty($comboIds)) {
            DB::table('product_variation_parts')->whereIn('product_variation_id', $comboIds)->delete();
            DB::table('product_variations')->whereIn('id', $comboIds)->delete();
        }
    }

    private function resolveOptionId( int $langId, string $name): int
    {
        $existing = DB::table('variation_types as o')
            ->join('variation_type_descriptions as od', function ($j) use ($langId) {
                $j->on('o.variation_type_id', '=', 'od.variation_type_id')
                    ->where('od.language_id', '=', $langId);
            })
            ->whereRaw('LOWER(od.name) = ?', [strtolower($name)])
            ->value('o.variation_type_id');

        if ($existing) return (int) $existing;

        $variationTypeId = DB::table('variation_types')->insertGetId([
            'type' => 'select', 'sort_order' => 0,
        ]);

        DB::table('variation_type_descriptions')->insert([
            'variation_type_id' => $variationTypeId, 'language_id' => $langId, 'name' => $name,
        ]);

        return $variationTypeId;
    }

    private function resolveVariationValueId( int $langId, int $variationTypeId, string $name): int
    {
        $existing = DB::table('variation_values as ov')
            ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                $j->on('ov.variation_value_id', '=', 'ovd.variation_value_id')
                    ->where('ovd.language_id', '=', $langId);
            })
            ->where('ov.variation_type_id', $variationTypeId)
            ->whereRaw('LOWER(ovd.name) = ?', [strtolower($name)])
            ->value('ov.variation_value_id');

        if ($existing) return (int) $existing;

        $variationValueId = DB::table('variation_values')->insertGetId([
            'variation_type_id' => $variationTypeId, 'image' => '', 'sort_order' => 0,
        ]);

        DB::table('variation_value_descriptions')->insert([
            'variation_value_id' => $variationValueId,
            'language_id' => $langId,
            'variation_type_id' => $variationTypeId,
            'name' => $name,
        ]);

        return $variationValueId;
    }

    private function ensureVariationValueName( int $langId, int $variationValueId, string $name): void
    {
        DB::table('variation_value_descriptions')
            ->where('variation_value_id', $variationValueId)
            ->where('language_id', $langId)
            ->update(['name' => $name]);
    }

    private function deleteProductVariationValues( int $productId, int $productVariationTypeId): void
    {
        $povIds = DB::table('product_variation_values')
            ->where('product_variation_type_id', $productVariationTypeId)
            ->pluck('product_variation_value_id')->map(fn($v) => (int) $v)->all();

        DB::table('product_variation_values')->where('product_variation_type_id', $productVariationTypeId)->delete();

        if (!empty($povIds)) {
            DB::table('ch_lazada_listing_variations')
                ->whereIn('product_variation_value_id', $povIds)
                ->update(['product_variation_value_id' => null]);
            $this->deleteCombinationsForPovIds($povIds);
        }
    }

    private function removeAllVariations( int $id): \Illuminate\Http\RedirectResponse
    {
        DB::transaction(function () use ( $id) {
            $poIds = DB::table('product_variation_types')
                ->where('product_id', $id)->pluck('product_variation_type_id')->all();

            if (!empty($poIds)) {
                $povIds = DB::table('product_variation_values')
                    ->whereIn('product_variation_type_id', $poIds)
                    ->pluck('product_variation_value_id')->map(fn($v) => (int) $v)->all();

                DB::table('product_variation_values')->whereIn('product_variation_type_id', $poIds)->delete();
                DB::table('product_variation_types')->whereIn('product_variation_type_id', $poIds)->delete();

                if (!empty($povIds)) {
                    DB::table('ch_lazada_listing_variations')
                        ->whereIn('product_variation_value_id', $povIds)
                        ->update(['product_variation_value_id' => null]);
                    $this->deleteCombinationsForPovIds($povIds);
                }
            }

            DB::table('product_variation_parts')
                ->whereIn('product_variation_id', function ($q) use ($id) {
                    $q->select('id')->from('product_variations')->where('product_id', $id);
                })
                ->delete();
            DB::table('product_variations')->where('product_id', $id)->delete();
        });

        ActivityLogger::log('updated', 'Product Variations', $id, 'Product #' . $id . ': variations removed');

        return redirect()->route('products.variations.edit', $id)->with('status', 'All variations removed.');
    }
}
