<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StockHistoryLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $langId = (int) config('catalog.default_language_id');

        $q = trim((string) $request->get('q', ''));
        $sort = (string) $request->get('sort', 'product_id');
        $dir = strtolower((string) $request->get('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $allowedSort = [
            'product_id' => 'p.product_id',
            'name'       => 'pd.name',
            'quantity'   => 'p.quantity',
            'price'      => 'p.price',
            'status'     => 'p.status',
        ];

        if (! array_key_exists($sort, $allowedSort)) {
            $sort = 'product_id';
        }

        $optQtySub = DB::table('product_variation_values as pov')
            ->select('pov.product_id', DB::raw('SUM(pov.quantity) as variations_quantity'))
            ->groupBy('pov.product_id');

        $query = DB::table('products as p')
            ->leftJoin('product_descriptions as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoinSub($optQtySub, 'povsum', function ($j) {
                $j->on('p.product_id', '=', 'povsum.product_id');
            })
            ->select(
                'p.product_id',
                'pd.name as name',
                'p.image',
                'p.model',
                'p.sku',
                'p.quantity',
                DB::raw('COALESCE(povsum.variations_quantity, 0) as variations_quantity'),
                'p.price',
                'p.status'
            );

        if ($q !== '') {
            $ids = DB::table('product_descriptions as pd')
                ->where('pd.language_id', '=', $langId)
                ->where('pd.name', 'like', "%{$q}%")
                ->limit(800)
                ->pluck('pd.product_id');

            if ($ids->isEmpty()) {
                $query->whereRaw('1=0');
            } else {
                $query->whereIn('p.product_id', $ids);
            }
        }

        $query->orderBy($allowedSort[$sort], $dir);

        $products = $query->paginate(30);

        $products->getCollection()->transform(function ($row) {
            return [
                'product_id'       => (int) $row->product_id,
                'name'             => html_entity_decode((string) ($row->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'image'            => $row->image ?? '',
                'image_url'        => $this->productImageUrl($row->image),
                'model'            => $row->model ?? '',
                'sku'              => $row->sku ?? '',
                'quantity'         => (int) $row->quantity,
                'variations_quantity' => (int) $row->variations_quantity,
                'price'            => (float) $row->price,
                'status'           => (int) $row->status,
            ];
        });

        return response()->json($products);
    }

    public function showQuantity(int $id)
    {
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table('products')->where('product_id', $id)->first();
        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $name = DB::table('product_descriptions')
            ->where('product_id', $id)
            ->where('language_id', $langId)
            ->value('name') ?? '';

        $variationValues = DB::table('product_variation_values as pov')
            ->join('variation_type_descriptions as od', function ($j) use ($langId) {
                $j->on('pov.variation_type_id', '=', 'od.variation_type_id')
                    ->where('od.language_id', '=', $langId);
            })
            ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')
                    ->where('ovd.language_id', '=', $langId);
            })
            ->where('pov.product_id', $id)
            ->select(
                'pov.product_variation_value_id',
                'od.name as variation_type_name',
                'ovd.name as variation_value_name',
                'pov.quantity'
            )
            ->orderBy('od.name')
            ->orderBy('ovd.name')
            ->get();

        $hasVariations = $variationValues->isNotEmpty();

        return response()->json([
            'product_id'  => (int) $product->product_id,
            'name'        => html_entity_decode((string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'has_variations'  => $hasVariations,
            'quantity'     => (int) $product->quantity,
            'variations'     => $hasVariations ? $variationValues->map(fn ($row) => [
                'product_variation_value_id' => (int) $row->product_variation_value_id,
                'variation_type_name'             => (string) $row->variation_type_name,
                'variation_value_name'       => (string) $row->variation_value_name,
                'quantity'                => (int) $row->quantity,
            ])->values()->all() : [],
        ]);
    }

    private function productImageUrl(?string $path): ?string
    {
        $path = trim((string) ($path ?? ''));
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }
        $base = rtrim((string) config('catalog.public_url', config('app.url')), '/');
        $prefix = config('catalog.image_prefix', 'image');

        return $base . '/' . $prefix . '/' . ltrim($path, '/');
    }

    public function updateQuantity(Request $request, int $id)
    {

        $product = DB::table('products')->where('product_id', $id)->first();
        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $hasVariations = DB::table('product_variation_values')
            ->where('product_id', $id)
            ->exists();

        $oldProductQty = (int) $product->quantity;

        if ($hasVariations) {
            $request->validate([
                'variations'            => 'required|array|min:1',
                'variations.*.product_variation_value_id' => 'required|integer',
                'variations.*.quantity'  => 'required|integer|min:0',
            ]);

            $totalQty = 0;
            foreach ($request->input('variations') as $variation) {
                $povId = (int) $variation['product_variation_value_id'];
                $qty = (int) $variation['quantity'];

                $oldVariationQty = (int) DB::table('product_variation_values')
                    ->where('product_variation_value_id', $povId)
                    ->where('product_id', $id)
                    ->value('quantity');

                DB::table('product_variation_values')
                    ->where('product_variation_value_id', $povId)
                    ->where('product_id', $id)
                    ->update(['quantity' => $qty]);

                if ($oldVariationQty !== $qty) {
                    StockHistoryLogger::log(
                        productId: $id,
                        variationValueId: $povId,
                        orderId: null,
                        type: 'set',
                        qtyBefore: $oldVariationQty,
                        qtyAfter: $qty,
                        source: 'mobile',
                        note: "Mobile edit (variation): qty {$oldVariationQty} → {$qty}",
                    );
                }

                $totalQty += $qty;

                if (class_exists(\Extensions\Warehousing\Services\WarehouseStockService::class)) {
                    $defaultWh = \Extensions\Warehousing\Services\WarehouseStockService::getDefaultWarehouse();
                    if ($defaultWh) {
                        $inv = \Extensions\Warehousing\Services\WarehouseStockService::getOrCreateInventory($defaultWh->id, $id, $povId);
                        $inv->update(['quantity' => $qty]);
                    }
                }

                $linkedComboIds = DB::table('product_variation_parts')
                    ->where('product_variation_value_id', $povId)
                    ->pluck('product_variation_id');

                if ($linkedComboIds->isNotEmpty()) {
                    $singleValueComboIds = DB::table('product_variation_parts')
                        ->whereIn('product_variation_id', $linkedComboIds)
                        ->groupBy('product_variation_id')
                        ->havingRaw('COUNT(*) = 1')
                        ->pluck('product_variation_id');

                    if ($singleValueComboIds->isNotEmpty()) {
                        DB::table('product_variations')
                            ->whereIn('id', $singleValueComboIds)
                            ->where('product_id', $id)
                            ->update(['quantity' => $qty, 'updated_at' => now()]);
                    }
                }
            }

            DB::table('products')
                ->where('product_id', $id)
                ->update([
                    'quantity'      => $totalQty,
                    'date_modified' => now(),
                ]);

            if ($oldProductQty !== $totalQty) {
                StockHistoryLogger::log(
                    productId: $id,
                    variationValueId: null,
                    orderId: null,
                    type: 'set',
                    qtyBefore: $oldProductQty,
                    qtyAfter: $totalQty,
                    source: 'mobile',
                    note: "Mobile edit: qty {$oldProductQty} → {$totalQty}",
                );
            }
        } else {
            $request->validate([
                'quantity' => 'required|integer|min:0',
            ]);

            $newQty = (int) $request->input('quantity');

            DB::table('products')
                ->where('product_id', $id)
                ->update([
                    'quantity'      => $newQty,
                    'date_modified' => now(),
                ]);

            if ($oldProductQty !== $newQty) {
                StockHistoryLogger::log(
                    productId: $id,
                    variationValueId: null,
                    orderId: null,
                    type: 'set',
                    qtyBefore: $oldProductQty,
                    qtyAfter: $newQty,
                    source: 'mobile',
                    note: "Mobile edit: qty {$oldProductQty} → {$newQty}",
                );
            }

            if (class_exists(\Extensions\Warehousing\Services\WarehouseStockService::class)) {
                $defaultWh = \Extensions\Warehousing\Services\WarehouseStockService::getDefaultWarehouse();
                if ($defaultWh) {
                    $inv = \Extensions\Warehousing\Services\WarehouseStockService::getOrCreateInventory($defaultWh->id, $id, 0);
                    $inv->update(['quantity' => $newQty]);
                }
            }
        }

        return $this->showQuantity($id);
    }
}
