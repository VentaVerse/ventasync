<?php

namespace App\Services\Catalog;

use App\Rules\UniqueSku;
use App\Services\StockHistoryLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes a product's variations, in the shape the product form posts them.
 *
 * One variation type: `option_name` and `values[]`, one row per value
 * (name, sku, quantity, absolute_price, cost_amount, and optionally
 * cost_additional, image and status). Two types: `option1_name`,
 * `option2_name`, `option1_values[]`, `option2_values[]` and
 * `combinations[]`, one row per pair (opt1, opt2 and the same fields).
 * No type name at all removes the product's variations.
 *
 * The product form, the API and the imports all save variations here, so a
 * variation is written one way whoever makes it. Option and option value
 * records are shared across products and found by name before one is made.
 */
class VariationWriter
{
    /** The product's stock when it has variations: the sum of theirs. Null when there are no rows. */
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

    /**
     * Every typed variation SKU is checked before anything writes: not the
     * parent's SKU, not twice in the same product, and not held by another
     * product or its variations. A create passes 0, which excludes nothing.
     *
     * @throws ValidationException under `option_sku`, one message per refused SKU
     */
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

    /**
     * Save the variations in $input onto the product. $skuChanges collects
     * each existing variation whose SKU changed, for the channel SKU sync
     * the caller runs after its transaction commits.
     */
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

    /**
     * The parent's cost formula, in the two parts a variation inherits.
     *
     * A product composes its unit cost from a fixed amount, a share of the
     * selling price, and an additional. Only the amount is a property of the
     * particular variation, so the percentage and the additional stay on the
     * parent and reach every row.
     *
     * Read from the input first because the parent is saved in the same
     * submission. The fallback is for callers that touch variations without
     * sending the parent's pricing fields.
     *
     * @return array{0: float, 1: float} [percentage, additional]
     */
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

    /**
     * What one variation costs, against its own selling price: the
     * percentage is a share of this row's price, not the parent's. Never
     * taken from the client; the stored figure is worked out here.
     */
    private function variationUnitCost(float $amount, float $price, float $pct, float $add): float
    {
        return round($amount + ($pct / 100 * $price) + $add, 4);
    }

    /**
     * The additional cost a row carries: its own when the row names one, the
     * parent's when it does not. An empty string is "not named", a typed 0
     * is a real 0.
     */
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

        // Remove product_options for other option_ids (the type was switched)
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

        // Index existing POVs
        $existingPovs = DB::table($pfx . 'product_option_value')
            ->where('product_option_id', $productOptionId)
            ->get()
            ->keyBy('option_value_id');

        $seenOptionValueIds = [];

        /* The image, status and cost parts belong to the COMBINATION, the
         * sellable unit, not to product_option_value. The combination rebuild
         * at the end reads its rows back out of product_option_value, so these
         * maps carry each row's values across to it; without them every save
         * would write defaults over what was there. */
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
            // Absent means enabled: a caller that says nothing about status
            // must not switch a variation off.
            $statusByOptionValueId[$optionValueId] = array_key_exists('status', $v)
                ? (int) (bool) $v['status']
                : 1;

            // absolute_cost is read as the amount when cost_amount is absent,
            // for older payloads whose one cost number was the amount.
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

        // Remove old values not in new list
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

        // Rebuild combinations (1 combo per value)
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

        // Remove product_options for other option_ids
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

        // Sync POV rows for each type; they are structural, pricing lives in the combinations.
        $opt1PovMap = $this->syncOptionValues($productId, $optionId1, $poId1, $opt1ValueNames);
        $opt2PovMap = $this->syncOptionValues($productId, $optionId2, $poId2, $opt2ValueNames);

        // Rebuild combinations
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

            // Straight off the row here: a combination in this path IS the
            // input row, unlike the one-type path, which rebuilds from
            // product_option_value and needs the image mapped across.
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

    /**
     * Sync product_option_value rows for one type. Returns map: lowercase_name => pov_id
     */
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

        // Remove old values not in new list
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
