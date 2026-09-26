<?php

namespace App\Actions\Catalog;

use App\Actions\Catalog\ProductWrites;
use App\Models\Catalog\Product;
use App\Support\Api\Input;
use App\Support\Api\Refused;

/**
 * Products as an application reads them: the search and one product in full.
 * The partner API and the assistant tools both run these.
 */
final class ProductReads
{
    public function index(Input $request): array
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(1, min($perPage, 100));
        $page = max(1, (int) $request->integer('page', 1));

        $query = Product::query()->with('description');

        \App\Support\Catalog\ProductSearch::apply($query, trim((string) $request->query('search', '')));

        $products = $query->orderBy('product_id')->paginate($perPage, ['*'], 'page', $page)->withPath(url('/api/v1/products'));

        $writes = app(ProductWrites::class);
        $products->getCollection()->transform(fn (Product $product) => $writes->present($product));

        return $this->json($products);
    }

    public function show(Input $request, int $id): array
    {
        $product = Product::with('description')->find($id);

        if (! $product) {
            return $this->json(['message' => 'Product not found.'], 404);
        }

        $writes = app(ProductWrites::class);
        $payload = $writes->present($product) + $writes->details($product);
        $payload['variations'] = $writes->variations($product);

        return $this->json($payload);
    }

    /**
     * An answer, or a refusal when the status says the request was turned
     * down: the message and status reach every door unchanged.
     *
     * @return array<string, mixed>
     */
    private function json(mixed $data, int $status = 200): array
    {
        if ($status >= 400) {
            $data = (array) json_decode((string) json_encode($data), true);

            throw new Refused((string) ($data['message'] ?? 'The request was refused.'), $status);
        }

        return is_array($data) ? $data : (array) json_decode((string) json_encode($data), true);
    }
}
