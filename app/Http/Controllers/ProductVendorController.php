<?php

namespace App\Http\Controllers;

use App\Models\ProductVendor;
use Illuminate\Http\Request;

class ProductVendorController extends Controller
{
    public function index($productId)
    {
        $rows = ProductVendor::with('vendor:id,name')
            ->where('product_id', (int) $productId)
            ->orderBy('id')
            ->get();

        return response()->json($rows);
    }

    public function store(Request $request, $productId)
    {
        $request->validate([
            'vendor_id' => 'required|integer|exists:vendors,id',
            'vendor_sku' => 'nullable|string|max:128',
            'product_option_value_id' => 'nullable|integer',
            'min_qty' => 'nullable|integer|min:1',
            'currency_code' => 'required|string|max:3',
            'unit_price' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $povId = $request->product_option_value_id;

        $existing = ProductVendor::where('product_id', (int) $productId)
            ->where('vendor_id', $request->vendor_id)
            ->where('product_option_value_id', $povId)
            ->exists();

        if ($existing) {
            $label = $povId ? 'This vendor+option combination' : 'This vendor';
            return response()->json(['error' => "{$label} is already linked to this product."], 422);
        }

        $pv = ProductVendor::create([
            'product_id' => (int) $productId,
            'vendor_id' => $request->vendor_id,
            'product_option_value_id' => $povId,
            'vendor_sku' => $request->vendor_sku,
            'min_qty' => (int) ($request->min_qty ?? 1),
            'currency_code' => $request->currency_code,
            'unit_price' => $request->unit_price,
            'notes' => $request->notes,
        ]);

        $pv->load('vendor:id,name');

        return response()->json($pv, 201);
    }

    /**
     * Bulk store — per-option pricing, each option value can have its own vendor/currency/min_qty.
     */
    public function storeBulk(Request $request, $productId)
    {
        $request->validate([
            'options' => 'required|array|min:1',
            'options.*.product_option_value_id' => 'required|integer',
            'options.*.vendor_id' => 'required|integer|exists:vendors,id',
            'options.*.currency_code' => 'required|string|max:3',
            'options.*.min_qty' => 'nullable|integer|min:1',
            'options.*.vendor_sku' => 'nullable|string|max:128',
            'options.*.unit_price' => 'required|numeric|min:0',
        ]);

        $created = [];

        foreach ($request->options as $v) {
            $povId = (int) $v['product_option_value_id'];
            $vendorId = (int) $v['vendor_id'];

            $exists = ProductVendor::where('product_id', (int) $productId)
                ->where('vendor_id', $vendorId)
                ->where('product_option_value_id', $povId)
                ->exists();

            if ($exists) continue;

            $pv = ProductVendor::create([
                'product_id' => (int) $productId,
                'vendor_id' => $vendorId,
                'product_option_value_id' => $povId,
                'vendor_sku' => $v['vendor_sku'] ?? null,
                'min_qty' => (int) ($v['min_qty'] ?? 1),
                'currency_code' => $v['currency_code'],
                'unit_price' => $v['unit_price'],
            ]);

            $created[] = $pv;
        }

        // Return newly created rows with vendor loaded
        $createdIds = array_map(fn($pv) => $pv->id, $created);
        $all = ProductVendor::with('vendor:id,name')
            ->whereIn('id', $createdIds)
            ->orderBy('id')
            ->get();

        return response()->json($all, 201);
    }

    public function update(Request $request, $productId, $id)
    {
        $pv = ProductVendor::where('product_id', (int) $productId)->findOrFail($id);

        $request->validate([
            'vendor_sku' => 'nullable|string|max:128',
            'min_qty' => 'nullable|integer|min:1',
            'currency_code' => 'required|string|max:3',
            'unit_price' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $pv->update([
            'vendor_sku' => $request->vendor_sku,
            'min_qty' => (int) ($request->min_qty ?? 1),
            'currency_code' => $request->currency_code,
            'unit_price' => $request->unit_price,
            'notes' => $request->notes,
        ]);

        $pv->load('vendor:id,name');

        return response()->json($pv);
    }

    public function destroy($productId, $id)
    {
        $pv = ProductVendor::where('product_id', (int) $productId)->findOrFail($id);
        $pv->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Bulk delete — remove by vendor or remove all for a product.
     */
    public function destroyBulk(Request $request, $productId)
    {
        $vendorId = $request->input('vendor_id');

        if (!$vendorId) {
            return response()->json(['error' => 'vendor_id is required.'], 422);
        }

        $count = ProductVendor::where('product_id', (int) $productId)
            ->where('vendor_id', (int) $vendorId)
            ->delete();

        return response()->json(['ok' => true, 'deleted' => $count]);
    }
}
