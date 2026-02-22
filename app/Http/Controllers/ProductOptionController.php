<?php

namespace App\Http\Controllers;

use App\Models\Catalog\Product;
use App\Rules\UniqueSku;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductOptionController extends Controller
{
    public function edit(int $id)
    {
        // Options are managed in the Product Add/Edit screen under the Options tab.
        return redirect()->route('products.edit', ['id' => $id, 'tab' => 'options']);
    }


    public function update(Request $request, int $id)
    {
        // Product exists
        Product::query()->where('product_id', $id)->firstOrFail();

        $p = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $validated = $request->validate([
            'options' => 'array',
            'options.*.option_id' => 'required|integer|min:1',
            'options.*.required' => 'nullable|in:0,1',
            'options.*.values' => 'array',
            'options.*.values.*.option_value_id' => 'required|integer|min:1',
            'options.*.values.*.sku' => 'nullable|string|max:64',
            'options.*.values.*.quantity' => 'nullable|integer|min:0',
            'options.*.values.*.price' => 'nullable|numeric',
            'options.*.values.*.price_prefix' => 'nullable|in:+,-',
            'options.*.values.*.cost' => 'nullable|numeric',
        ]);

        $options = $validated['options'] ?? [];

        // Validate SKU uniqueness across product.sku and product_option_value.sku
        $skuRule = new UniqueSku($id);
        $skuErrors = [];
        foreach ($options as $oi => $opt) {
            foreach (($opt['values'] ?? []) as $vi => $v) {
                $sku = trim((string) ($v['sku'] ?? ''));
                if ($sku === '') continue;
                $skuRule->validate("options.{$oi}.values.{$vi}.sku", $sku, function (string $msg) use (&$skuErrors) {
                    $skuErrors[] = $msg;
                });
            }
        }
        if (!empty($skuErrors)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['sku' => $skuErrors]);
        }

        // Hard guard: only allow supported option types for now
        $supportedTypes = ['select', 'radio', 'checkbox', 'image'];

        // Resolve option types and ensure they exist
        $optionIds = collect($options)->pluck('option_id')->map(fn($v)=>(int)$v)->unique()->values()->all();

        $types = DB::table($p.'option')
            ->whereIn('option_id', $optionIds)
            ->pluck('type', 'option_id');

        foreach ($optionIds as $oid) {
            if (!isset($types[$oid])) {
                abort(422, 'Invalid option_id: '.$oid);
            }
            if (!in_array((string)$types[$oid], $supportedTypes, true)) {
                abort(422, 'Unsupported option type for option_id '.$oid);
            }
        }

        $skuChanges = ['product_sku' => null, 'option_skus' => []];

        DB::transaction(function () use ($p, $id, $options, &$skuChanges) {
            // Upsert strategy: update existing rows in place to preserve product_option_value_id
            // (downstream systems like Lazada/Shopee variant mappings reference these IDs)

            // Index existing product_option rows by option_id
            $existingPo = DB::table($p.'product_option')
                ->where('product_id', $id)
                ->get()
                ->keyBy('option_id');

            // Index existing product_option_value rows by "option_id:option_value_id"
            $existingPov = DB::table($p.'product_option_value')
                ->where('product_id', $id)
                ->get()
                ->keyBy(fn ($r) => $r->option_id . ':' . $r->option_value_id);

            $seenPoOptionIds = [];
            $seenPovKeys = [];

            foreach ($options as $opt) {
                $optionId = (int) $opt['option_id'];
                $required = (int) ($opt['required'] ?? 0);
                $seenPoOptionIds[] = $optionId;

                if (isset($existingPo[$optionId])) {
                    $productOptionId = (int) $existingPo[$optionId]->product_option_id;
                    DB::table($p.'product_option')
                        ->where('product_option_id', $productOptionId)
                        ->update(['required' => $required]);
                } else {
                    $productOptionId = DB::table($p.'product_option')->insertGetId([
                        'product_id' => $id,
                        'option_id' => $optionId,
                        'value' => '',
                        'required' => $required,
                    ]);
                }

                $vals = $opt['values'] ?? [];
                foreach ($vals as $v) {
                    $ovId = (int) $v['option_value_id'];
                    $key = $optionId . ':' . $ovId;
                    $seenPovKeys[] = $key;

                    $data = [
                        'sku' => (string)($v['sku'] ?? ''),
                        'quantity' => (int)($v['quantity'] ?? 0),
                        'subtract' => 1,
                        'price' => (float)($v['price'] ?? 0),
                        'price_prefix' => (string)($v['price_prefix'] ?? '+'),
                    ];

                    if (isset($existingPov[$key])) {
                        // Track SKU changes for marketplace link updates
                        $oldSku = trim((string) ($existingPov[$key]->sku ?? ''));
                        $newSku = $data['sku'];
                        if ($oldSku !== '' && $newSku !== $oldSku) {
                            DB::table('shopee_product_links')
                                ->where('product_id', $id)
                                ->where('sku', $oldSku)
                                ->update(['sku' => $newSku]);

                            DB::table('lazada_product_variants')
                                ->whereIn('lazada_product_id', function ($q) use ($id) {
                                    $q->select('id')->from('lazada_products')->where('product_id', $id);
                                })
                                ->where('seller_sku', $oldSku)
                                ->update(['seller_sku' => $newSku]);

                            // Record for platform API sync after transaction
                            $povId = (int) $existingPov[$key]->product_option_value_id;
                            $skuChanges['option_skus'][$povId] = ['old' => $oldSku, 'new' => $newSku];
                        }

                        DB::table($p.'product_option_value')
                            ->where('product_option_value_id', $existingPov[$key]->product_option_value_id)
                            ->update(array_merge($data, ['product_option_id' => $productOptionId]));
                    } else {
                        DB::table($p.'product_option_value')->insert(array_merge($data, [
                            'product_option_id' => $productOptionId,
                            'product_id' => $id,
                            'option_id' => $optionId,
                            'option_value_id' => $ovId,
                            'points' => 0,
                            'points_prefix' => '+',
                            'weight' => 0,
                            'weight_prefix' => '+',
                        ]));
                    }
                }
            }

            // Remove option values that are no longer in the incoming list
            $deletedPovIds = [];
            foreach ($existingPov as $key => $row) {
                if (!in_array($key, $seenPovKeys, true)) {
                    $deletedPovIds[] = (int) $row->product_option_value_id;
                    DB::table($p.'product_option_value')->where('product_option_value_id', $row->product_option_value_id)->delete();
                }
            }

            // Remove product options that are no longer in the incoming list
            foreach ($existingPo as $optId => $row) {
                if (!in_array((int) $optId, $seenPoOptionIds, true)) {
                    $groupPovIds = DB::table($p.'product_option_value')
                        ->where('product_option_id', $row->product_option_id)
                        ->pluck('product_option_value_id')
                        ->map(fn ($v) => (int) $v)
                        ->all();
                    $deletedPovIds = array_merge($deletedPovIds, $groupPovIds);

                    DB::table($p.'product_option_value')->where('product_option_id', $row->product_option_id)->delete();
                    DB::table($p.'product_option')->where('product_option_id', $row->product_option_id)->delete();
                }
            }

            // Clean stale downstream references for deleted option values
            if (!empty($deletedPovIds)) {
                DB::table('lazada_product_variants')
                    ->whereIn('product_option_value_id', $deletedPovIds)
                    ->update(['product_option_value_id' => null]);
            }
        });

        // Sync SKU changes to connected platforms (after transaction commits)
        $status = 'Options saved';
        if (!empty($skuChanges['product_sku']) || !empty($skuChanges['option_skus'])) {
            $syncMessages = (new \App\Services\SkuSyncService())->syncSkuChanges($id, $skuChanges);
            if (!empty($syncMessages)) {
                $status .= ' | SKU synced: ' . implode(' | ', $syncMessages);
            }
        }

        return redirect()->route('products.options.edit', $id)->with('status', $status);
    }
}
