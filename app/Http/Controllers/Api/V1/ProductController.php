<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\ProductWrites;
use App\Http\Controllers\Controller;
use App\Support\Api\Refused;
use App\Models\Catalog\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product access for external API clients: reading (products:read), and
 * setting a price or creating a product (products:write). Exposes the
 * catalog and pricing fields an external tool needs: cost, price,
 * quantity, status. Nothing customer-facing is returned here.
 */
class ProductController extends Controller
{
    /** The most variations a product is created with here. */
    public const MAX_VARIATIONS = ProductWrites::MAX_VARIATIONS;

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(1, min($perPage, 100));
        $page = max(1, (int) $request->integer('page', 1));

        $query = Product::query()->with('description');

        \App\Support\Catalog\ProductSearch::apply($query, trim((string) $request->query('search', '')));

        $products = $query->orderBy('product_id')->paginate($perPage, ['*'], 'page', $page);

        $writes = app(ProductWrites::class);
        $products->getCollection()->transform(fn (Product $product) => $writes->present($product));

        return response()->json($products);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $product = Product::with('description')->find($id);

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $writes = app(ProductWrites::class);
        $payload = $writes->present($product) + $writes->details($product);
        $payload['variations'] = $writes->variations($product);

        return response()->json($payload);
    }

    /** The answer's before and after carry only what changed. */

    /** @return array<string, string> each distinct variation picture link, with the first SKU that uses it */

    /** The rows with each link replaced by the library path it was stored at. */


    /** PATCH /api/v1/products/{id}: some of a product's fields, with a reason. */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->run(fn () => app(ProductWrites::class)->update($request->all(), $id));
    }

    /** PATCH /api/v1/products/{id}/variations: change or add variations. */
    public function updateVariations(Request $request, int $id): JsonResponse
    {
        return $this->run(fn () => app(ProductWrites::class)->updateVariations($request->all(), $id));
    }

    /** PUT /api/v1/products/{id}/price: a product's or a variation's price. */
    public function updatePrice(Request $request, int $id): JsonResponse
    {
        return $this->run(fn () => app(ProductWrites::class)->updatePrice($request->all(), $id));
    }

    /** POST /api/v1/products: a new product. */
    public function store(Request $request): JsonResponse
    {
        return $this->run(fn () => app(ProductWrites::class)->store($request->all()), 201);
    }

    /** A shared rule's answer as JSON, or its refusal with its status. */
    private function run(\Closure $rule, int $status = 200): JsonResponse
    {
        try {
            return response()->json($rule(), $status);
        } catch (Refused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
