<?php

namespace App\Http\Controllers;

use App\Models\Catalog\Product;
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

        DB::transaction(function () use ($p, $id, $options) {
            // Replace strategy (idempotent + avoids partial updates)
            $existingPoIds = DB::table($p.'product_option')
                ->where('product_id', $id)
                ->pluck('product_option_id')
                ->map(fn ($v) => (int)$v)
                ->all();

            if (!empty($existingPoIds)) {
                DB::table($p.'product_option_value')->whereIn('product_option_id', $existingPoIds)->delete();
            }
            DB::table($p.'product_option')->where('product_id', $id)->delete();

            foreach ($options as $opt) {
                $optionId = (int) $opt['option_id'];
                $required = (int) ($opt['required'] ?? 0);

                $productOptionId = DB::table($p.'product_option')->insertGetId([
                    'product_id' => $id,
                    'option_id' => $optionId,
                    'value' => '', // not used for select/radio/checkbox/image
                    'required' => $required,
                ]);

                $vals = $opt['values'] ?? [];
                foreach ($vals as $v) {
                    DB::table($p.'product_option_value')->insert([
                        'product_option_id' => (int) $productOptionId,
                        'product_id' => $id,
                        'option_id' => $optionId,
                        'option_value_id' => (int) $v['option_value_id'],
                        'sku' => (string)($v['sku'] ?? ''),
                        'quantity' => (int)($v['quantity'] ?? 0),
                        'subtract' => 1,
                        'price' => (float)($v['price'] ?? 0),
                        'price_prefix' => (string)($v['price_prefix'] ?? '+'),
                        'points' => 0,
                        'points_prefix' => '+',
                        'weight' => 0,
                        'weight_prefix' => '+',
                    ]);
                }
            }
        });

        return redirect()->route('products.options.edit', $id)->with('status', 'Options saved');
    }
}
