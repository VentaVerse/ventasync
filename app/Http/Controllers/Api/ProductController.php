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
        $p = (string) config('catalog.prefix');
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

        $optQtySub = DB::table($p . 'product_option_value as pov')
            ->select('pov.product_id', DB::raw('SUM(pov.quantity) as options_quantity'))
            ->groupBy('pov.product_id');

        $query = DB::table($p . 'product as p')
            ->leftJoin($p . 'product_description as pd', function ($j) use ($langId) {
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
                DB::raw('COALESCE(povsum.options_quantity, 0) as options_quantity'),
                'p.price',
                'p.status'
            );

        if ($q !== '') {
            $ids = DB::table($p . 'product_description as pd')
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
                'options_quantity' => (int) $row->options_quantity,
                'price'            => (float) $row->price,
                'status'           => (int) $row->status,
            ];
        });

        return response()->json($products);
    }

    public function showQuantity(int $id)
    {
        $p = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table($p . 'product')->where('product_id', $id)->first();
        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $name = DB::table($p . 'product_description')
            ->where('product_id', $id)
            ->where('language_id', $langId)
            ->value('name') ?? '';

        // Get option values with their names
        $optionValues = DB::table($p . 'product_option_value as pov')
            ->join($p . 'option_description as od', function ($j) use ($langId) {
                $j->on('pov.option_id', '=', 'od.option_id')
                    ->where('od.language_id', '=', $langId);
            })
            ->join($p . 'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                    ->where('ovd.language_id', '=', $langId);
            })
            ->where('pov.product_id', $id)
            ->select(
                'pov.product_option_value_id',
                'od.name as option_name',
                'ovd.name as option_value_name',
                'pov.quantity'
            )
            ->orderBy('od.name')
            ->orderBy('ovd.name')
            ->get();

        $hasOptions = $optionValues->isNotEmpty();

        return response()->json([
            'product_id'  => (int) $product->product_id,
            'name'        => html_entity_decode((string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'has_options'  => $hasOptions,
            'quantity'     => (int) $product->quantity,
            'options'      => $hasOptions ? $optionValues->map(fn ($row) => [
                'product_option_value_id' => (int) $row->product_option_value_id,
                'option_name'             => (string) $row->option_name,
                'option_value_name'       => (string) $row->option_value_name,
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
        $p = (string) config('catalog.prefix');

        $product = DB::table($p . 'product')->where('product_id', $id)->first();
        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        // Check if product has option values
        $hasOptions = DB::table($p . 'product_option_value')
            ->where('product_id', $id)
            ->exists();

        $oldProductQty = (int) $product->quantity;

        if ($hasOptions) {
            $request->validate([
                'options'            => 'required|array|min:1',
                'options.*.product_option_value_id' => 'required|integer',
                'options.*.quantity'  => 'required|integer|min:0',
            ]);

            $totalQty = 0;
            foreach ($request->input('options') as $opt) {
                $povId = (int) $opt['product_option_value_id'];
                $qty = (int) $opt['quantity'];

                $oldOptQty = (int) DB::table($p . 'product_option_value')
                    ->where('product_option_value_id', $povId)
                    ->where('product_id', $id)
                    ->value('quantity');

                DB::table($p . 'product_option_value')
                    ->where('product_option_value_id', $povId)
                    ->where('product_id', $id)
                    ->update(['quantity' => $qty]);

                if ($oldOptQty !== $qty) {
                    StockHistoryLogger::log(
                        productId: $id,
                        optionValueId: $povId,
                        orderId: null,
                        type: 'set',
                        qtyBefore: $oldOptQty,
                        qtyAfter: $qty,
                        source: 'mobile',
                        note: "Mobile edit (option) — qty {$oldOptQty} → {$qty}",
                    );
                }

                $totalQty += $qty;

                // Sync warehouse inventory for this option value
                if (class_exists(\Extensions\warehousing\Services\WarehouseStockService::class)) {
                    $defaultWh = \Extensions\warehousing\Services\WarehouseStockService::getDefaultWarehouse();
                    if ($defaultWh) {
                        $inv = \Extensions\warehousing\Services\WarehouseStockService::getOrCreateInventory($defaultWh->id, $id, $povId);
                        $inv->update(['quantity' => $qty]);
                    }
                }
            }

            // Auto-sum: set product quantity to total of option values
            DB::table($p . 'product')
                ->where('product_id', $id)
                ->update([
                    'quantity'      => $totalQty,
                    'date_modified' => now(),
                ]);

            if ($oldProductQty !== $totalQty) {
                StockHistoryLogger::log(
                    productId: $id,
                    optionValueId: null,
                    orderId: null,
                    type: 'set',
                    qtyBefore: $oldProductQty,
                    qtyAfter: $totalQty,
                    source: 'mobile',
                    note: "Mobile edit — qty {$oldProductQty} → {$totalQty}",
                );
            }
        } else {
            $request->validate([
                'quantity' => 'required|integer|min:0',
            ]);

            $newQty = (int) $request->input('quantity');

            DB::table($p . 'product')
                ->where('product_id', $id)
                ->update([
                    'quantity'      => $newQty,
                    'date_modified' => now(),
                ]);

            if ($oldProductQty !== $newQty) {
                StockHistoryLogger::log(
                    productId: $id,
                    optionValueId: null,
                    orderId: null,
                    type: 'set',
                    qtyBefore: $oldProductQty,
                    qtyAfter: $newQty,
                    source: 'mobile',
                    note: "Mobile edit — qty {$oldProductQty} → {$newQty}",
                );
            }

            // Sync warehouse inventory for product without options
            if (class_exists(\Extensions\warehousing\Services\WarehouseStockService::class)) {
                $defaultWh = \Extensions\warehousing\Services\WarehouseStockService::getDefaultWarehouse();
                if ($defaultWh) {
                    $inv = \Extensions\warehousing\Services\WarehouseStockService::getOrCreateInventory($defaultWh->id, $id, 0);
                    $inv->update(['quantity' => $newQty]);
                }
            }
        }

        return $this->showQuantity($id);
    }
}
