<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Support\VariationRows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inventory for external API clients: reading on inventory:read, and one
 * write on inventory:write (#177).
 *
 * The read surfaces stock levels against each product's reorder level so an
 * external tool can flag low stock and reason about restocking (combine with
 * the reports endpoint for sales velocity and cost/price for margin).
 */
class InventoryController extends Controller
{
    /**
     * POST /api/v1/inventory/adjust - one quantity, with a stated reason.
     *
     * A variation is named by its SKU, the same code every list prints and
     * every channel keys on. The product's own quantity can be set only
     * when it has no variations; where it has them it is their sum, and
     * the answer returns the recomputed total so the caller can report it
     * without asking again.
     */
    public function adjust(Request $request): JsonResponse
    {
        try {
            return response()->json(app(\App\Actions\Stock\AdjustStock::class)->handle($request->all()));
        } catch (\App\Support\Api\Refused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

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

        // Stock is asked about per variation ("how many Blue are left"),
        // and quantity here is the total across them, so every row carries
        // its variations' own quantities. One query for the page, not one
        // per row.
        $variations = VariationRows::forProducts($items->getCollection()->pluck('product_id')->map('intval')->all());
        $items->getCollection()->transform(fn (Product $p) => $this->present($p, $variations->get((int) $p->product_id, collect())));

        return response()->json($items);
    }

    /** @param \Illuminate\Support\Collection<int, object> $variations */
    private function present(Product $product, $variations): array
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
            'variations'    => $variations->map(fn (object $v) => [
                'variation' => (string) $v->option_value_name,
                'sku'       => (string) ($v->sku ?? ''),
                'quantity'  => (int) $v->quantity,
                'status'    => (bool) ($v->status ?? 1),
            ])->values()->all(),
        ];
    }
}
