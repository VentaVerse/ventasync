<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only product access for external API clients (scope: products:read).
 * Exposes catalog + pricing fields an external pricing/reporting tool needs:
 * cost, price, quantity, status — nothing customer-facing is returned here.
 */
class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        $query = Product::query()->with('description');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('model', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhereHas('description', function ($dq) use ($search) {
                        $dq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $products = $query->orderBy('product_id')->paginate($perPage);

        $products->getCollection()->transform(fn (Product $product) => $this->present($product));

        return response()->json($products);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $product = Product::with('description')->find($id);

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json($this->present($product));
    }

    private function present(Product $product): array
    {
        return [
            'product_id' => $product->product_id,
            'name'       => $product->description?->name,
            'model'      => $product->model,
            'sku'        => $product->sku,
            'price'      => (float) $product->price,
            'cost'       => (float) $product->cost,
            'quantity'   => (int) $product->quantity,
            'status'     => (bool) $product->status,
        ];
    }
}
