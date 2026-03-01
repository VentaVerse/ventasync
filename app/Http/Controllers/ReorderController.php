<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderLog;
use App\Models\ProductVendor;
use App\Models\Vendor;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReorderController extends Controller
{
    public function index(Request $request)
    {
        $pfx = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $vendorId = (int) $request->get('vendor_id', 0);
        $categoryId = (int) $request->get('category_id', 0);

        $query = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                  ->where('pd.language_id', '=', $langId);
            })
            ->where('p.status', 1)
            ->where('p.reorder_level', '>', 0)
            ->whereColumn('p.quantity', '<=', 'p.reorder_level');

        if ($categoryId > 0) {
            $query->join($pfx . 'product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                  ->where('ptc.category_id', $categoryId);
        }

        if ($vendorId > 0) {
            $query->whereExists(function ($sub) use ($vendorId) {
                $sub->select(DB::raw(1))
                    ->from('product_vendors')
                    ->whereColumn('product_vendors.product_id', 'p.product_id')
                    ->where('product_vendors.vendor_id', $vendorId);
            });
        }

        $products = $query->select([
            'p.product_id', 'pd.name', 'p.sku', 'p.model',
            'p.quantity', 'p.reorder_level', 'p.price',
        ])->orderBy('pd.name')->get();

        // Enrich with preferred vendor and last PO price
        foreach ($products as $product) {
            $product->name = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $product->deficit = $product->reorder_level - $product->quantity;

            // Preferred vendor = cheapest from product_vendors
            $pv = ProductVendor::where('product_id', $product->product_id)
                ->orderBy('unit_price')
                ->first();
            $product->preferred_vendor_id = $pv->vendor_id ?? null;
            $product->preferred_vendor_name = $pv ? (Vendor::find($pv->vendor_id)->name ?? '—') : '—';
            $product->vendor_price = $pv->unit_price ?? null;
            $product->vendor_currency = $pv->currency_code ?? null;

            // Last PO price
            $lastPoItem = PurchaseOrderItem::where('product_id', $product->product_id)
                ->orderByDesc('id')
                ->first(['unit_price']);
            $product->last_po_price = $lastPoItem->unit_price ?? null;
        }

        $vendors = Vendor::where('status', 1)->orderBy('name')->get(['id', 'name']);

        // Categories for filter
        $categories = DB::table($pfx . 'category_description')
            ->where('language_id', $langId)
            ->orderBy('name')
            ->get(['category_id as id', 'name']);
        foreach ($categories as $cat) {
            $cat->name = html_entity_decode($cat->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return view('purchase_orders.reorder', compact('products', 'vendors', 'categories', 'vendorId', 'categoryId'));
    }

    public function createFromReorder(Request $request)
    {
        $request->validate([
            'selected' => 'required|array|min:1',
            'selected.*' => 'integer',
            'qty' => 'required|array',
            'qty.*' => 'integer|min:1',
        ]);

        $pfx = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $selectedIds = $request->input('selected', []);
        $quantities = $request->input('qty', []);

        // Group by preferred vendor
        $groups = [];
        foreach ($selectedIds as $productId) {
            $productId = (int) $productId;
            $qty = (int) ($quantities[$productId] ?? 0);
            if ($qty <= 0) continue;

            $pv = ProductVendor::where('product_id', $productId)->orderBy('unit_price')->first();
            $vendorId = $pv->vendor_id ?? 0;

            if (!$vendorId) continue;

            if (!isset($groups[$vendorId])) {
                $groups[$vendorId] = [];
            }
            $groups[$vendorId][] = [
                'product_id' => $productId,
                'qty' => $qty,
                'unit_price' => $pv->unit_price ?? 0,
                'vendor_sku' => $pv->vendor_sku ?? null,
                'currency_code' => $pv->currency_code ?? 'PHP',
            ];
        }

        $createdPOs = [];

        foreach ($groups as $vendorId => $items) {
            $vendor = Vendor::find($vendorId);
            if (!$vendor) continue;

            $currencyCode = $items[0]['currency_code'] ?? $vendor->currency_code ?? 'PHP';
            $currency = \App\Models\Currency::where('code', $currencyCode)->first();
            $exchangeRate = $currency->exchange_rate ?? 1;

            $po = PurchaseOrder::create([
                'po_number' => PurchaseOrder::generatePoNumber(),
                'vendor_id' => $vendorId,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'currency_code' => $currencyCode,
                'exchange_rate' => $exchangeRate,
                'notes' => 'Auto-generated from reorder suggestions',
                'created_by' => auth()->id(),
            ]);

            foreach ($items as $item) {
                $productName = DB::table($pfx . 'product_description')
                    ->where('product_id', $item['product_id'])
                    ->where('language_id', $langId)
                    ->value('name');
                $productName = html_entity_decode($productName ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

                $productSku = DB::table($pfx . 'product')
                    ->where('product_id', $item['product_id'])
                    ->value('sku');

                $lineTotal = $item['qty'] * $item['unit_price'];

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $item['product_id'],
                    'description' => $productName,
                    'sku' => $item['vendor_sku'] ?: $productSku,
                    'quantity' => $item['qty'],
                    'qty_received' => 0,
                    'unit_price' => $item['unit_price'],
                    'discount_percent' => 0,
                    'total' => $lineTotal,
                ]);
            }

            $po->recalculateTotals();
            ActivityLogger::log('created', 'PurchaseOrder', $po->id, $po->po_number, 'Created from reorder suggestions');
            PurchaseOrderLog::record($po->id, 'created', null, 'draft', 'Created from reorder suggestions');

            $createdPOs[] = $po->po_number;
        }

        if (empty($createdPOs)) {
            return redirect()->route('reorder.index')->with('error', 'No valid items selected. Ensure products have vendor links.');
        }

        $poList = implode(', ', $createdPOs);
        return redirect()->route('purchase_orders.index')
            ->with('status', "Created " . count($createdPOs) . " draft PO(s): {$poList}");
    }
}
