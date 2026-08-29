<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Support\LineCost;
use App\Support\VariationRows;
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

        $payload = $this->present($product);
        $payload['variations'] = $this->variations($product);

        return response()->json($payload);
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

    /**
     * The product's variations, each with its own SKU, stock, price and
     * cost. On show() only: the list stays one row per product, and a
     * reader that wants the breakdown asks for the product. Until
     * 2026-08-29 no API answer carried variations at all, while the tool
     * description promised them, so "how many Blue are left" could not be
     * answered.
     *
     * Cost is what an order line of this variation would be costed at
     * (LineCost: the variation's own amount when one was entered, else the
     * parent's), the same figure the profitability reports use, so the API
     * and the reports cannot disagree about a variation's margin.
     *
     * @return list<array<string, mixed>>
     */
    private function variations(Product $product): array
    {
        $rows = VariationRows::forProducts([(int) $product->product_id])->get((int) $product->product_id, collect());

        return $rows->map(function (object $v) use ($product): array {
            $ids = array_values(array_filter((array) ($v->option_value_ids ?? []), fn ($id) => (int) $id > 0));

            return [
                'variation' => (string) $v->option_value_name,
                'option'    => (string) $v->option_name,
                'sku'       => (string) ($v->sku ?? ''),
                'quantity'  => (int) $v->quantity,
                'price'     => (float) ($v->absolute_price ?? 0) > 0 ? (float) $v->absolute_price : (float) $product->price,
                'cost'      => LineCost::resolve((int) $product->product_id, (int) ($ids[0] ?? 0)),
                'status'    => (bool) ($v->status ?? 1),
            ];
        })->values()->all();
    }
}
