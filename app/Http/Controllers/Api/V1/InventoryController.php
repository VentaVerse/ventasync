<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only inventory access for external API clients (scope: inventory:read).
 *
 * Surfaces stock levels against each product's reorder level so an external
 * tool can flag low stock and reason about restocking (combine with the
 * reports endpoint for sales velocity and cost/price for margin).
 */
class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'low_stock' => ['nullable', 'boolean'],
            'threshold' => ['nullable', 'integer', 'min:0'],
            'search'    => ['nullable', 'string', 'max:255'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $perPage = max(1, min((int) $request->integer('per_page', 50), 200));
        $lowStock = $request->boolean('low_stock');
        $threshold = $request->filled('threshold') ? (int) $request->integer('threshold') : null;

        $query = Product::query()->with('description');

        // "Low stock" = at or below the product's own reorder level.
        if ($lowStock) {
            $query->where('reorder_level', '>', 0)
                ->whereColumn('quantity', '<=', 'reorder_level');
        }

        // Absolute threshold override (e.g. everything with <= 5 on hand).
        if ($threshold !== null) {
            $query->where('quantity', '<=', $threshold);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('model', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhereHas('description', fn ($dq) => $dq->where('name', 'like', "%{$search}%"));
            });
        }

        // Lowest stock first when filtering for restock needs; else stable id order.
        $query->orderBy(($lowStock || $threshold !== null) ? 'quantity' : 'product_id',
            ($lowStock || $threshold !== null) ? 'asc' : 'asc');

        $items = $query->paginate($perPage);
        $items->getCollection()->transform(fn (Product $p) => $this->present($p));

        return response()->json($items);
    }

    private function present(Product $product): array
    {
        $reorder = (int) $product->reorder_level;
        $qty = (int) $product->quantity;

        return [
            'product_id'    => $product->product_id,
            'name'          => $product->description?->name,
            'sku'           => $product->sku,
            'model'         => $product->model,
            'quantity'      => $qty,
            'reorder_level' => $reorder,
            'is_low'        => $reorder > 0 && $qty <= $reorder,
            'below_by'      => $reorder > 0 ? max(0, $reorder - $qty) : 0,
            'cost'          => (float) $product->cost,
            'price'         => (float) $product->price,
            'status'        => (bool) $product->status,
        ];
    }
}
