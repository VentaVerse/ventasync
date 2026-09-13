<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Support\LineCost;
use App\Support\VariationRows;
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

    /**
     * PUT /api/v1/products/{id}/price - one price, with a stated reason.
     *
     * The write half of #177. The body names the product's own price or
     * one variation's, by the SKU the reader already saw on show(); the
     * answer carries before and after and says plainly that nothing has
     * reached a marketplace yet, because a channel price is this price run
     * through the listing's rule at the next push.
     */
    public function updatePrice(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'price' => 'required|numeric|min:0|max:99999999',
            'variation_sku' => 'nullable|string|max:64',
            // The reason is the audit trail's whole value, so it is not
            // optional and it is not allowed to be a shrug.
            'reason' => 'required|string|min:3|max:500',
        ]);

        try {
            $result = app(\App\Services\Catalog\ProductPriceWriter::class)->set(
                $id,
                $data['variation_sku'] ?? null,
                (float) $data['price'],
                trim((string) $data['reason']),
                (string) ($request->user()?->name ?? 'An API application')
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result + [
            'currency' => \App\Support\Money::defaultCode(),
            'note' => 'Saved on the catalog product. Marketplace prices are this price run through each '
                . "listing's own rule, so they change on the next push or price sync, not now.",
        ]);
    }

    /**
     * POST /api/v1/products - one new catalog product, with a stated reason.
     *
     * The same product the Add product form makes, through ProductCreator.
     * Every field is checked first; then the photos are fetched, and a link
     * that fails creates nothing and names itself. A product with no photo
     * is saved switched off, because a product needs a photo to be sold.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => ['required', 'string', 'max:64', new \App\Rules\UniqueSku()],
            'price' => 'required|numeric|gt:0|max:99999999',
            'weight' => 'required|numeric|gt:0',
            'length' => 'required|numeric|gt:0',
            'width' => 'required|numeric|gt:0',
            'height' => 'required|numeric|gt:0',
            'description' => 'nullable|string|max:65000',
            'quantity' => 'nullable|integer|min:0',
            'cost_amount' => 'nullable|numeric|min:0',
            'manufacturer_name' => 'nullable|string|max:255',
            'category_names' => 'nullable|array|max:10',
            'category_names.*' => 'string|max:255',
            'image_urls' => 'nullable|array|max:9',
            'image_urls.*' => 'string|max:2048',
            'enabled' => 'nullable|boolean',
            'reason' => 'required|string|min:3|max:500',
        ]);

        $creator = app(\App\Services\Catalog\ProductCreator::class);
        $notes = [];

        $brand = trim((string) ($data['manufacturer_name'] ?? ''));
        $manufacturerId = $creator->resolveManufacturerId(0, $brand);
        if ($brand !== '' && $manufacturerId === 0) {
            $notes[] = 'No brand named "' . $brand . '" exists, so the product was saved without one.';
        }

        $categoryIds = [];
        foreach ((array) ($data['category_names'] ?? []) as $categoryName) {
            $categoryId = $creator->resolveCategoryId(0, (string) $categoryName);
            if ($categoryId > 0) {
                $categoryIds[] = $categoryId;
            } else {
                $notes[] = 'No category named "' . $categoryName . '" exists, so it was left out.';
            }
        }

        // The photos first: a link that fails creates nothing.
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        $importer = app(\App\Services\Media\CatalogImageImporter::class);
        $folder = $creator->imageFolder($manufacturerId);
        $paths = [];
        foreach (array_values((array) ($data['image_urls'] ?? [])) as $i => $url) {
            try {
                $paths[] = $importer->fromUrl((string) $url, $folder);
            } catch (\RuntimeException $e) {
                $disk->delete($paths);

                return response()->json(['message' => 'Photo ' . ($i + 1) . ' could not be used: ' . $e->getMessage() . ' Nothing was created.'], 422);
            }
        }

        if ($paths === []) {
            $notes[] = 'Saved switched off: a product needs at least one photo before it can be switched on and sold.';
        }

        try {
            $productId = $creator->create([
                'name' => trim((string) $data['name']),
                'sku' => trim((string) $data['sku']),
                'price' => (float) $data['price'],
                'weight' => (float) $data['weight'],
                'length' => (float) $data['length'],
                'width' => (float) $data['width'],
                'height' => (float) $data['height'],
                'status' => (($data['enabled'] ?? true) && $paths !== []) ? 1 : 0,
                'quantity' => (int) ($data['quantity'] ?? 0),
                'cost_amount' => (float) ($data['cost_amount'] ?? 0),
                'manufacturer_id' => $manufacturerId,
                'category_ids' => array_values(array_unique($categoryIds)),
                'description' => (string) ($data['description'] ?? ''),
                'image_paths' => $paths,
            ], by: [
                'source' => 'api',
                'actor' => (string) ($request->user()?->name ?? 'An API application'),
                'reason' => trim((string) $data['reason']),
            ]);
        } catch (\Throwable $e) {
            $disk->delete($paths);
            throw $e;
        }

        $notes[] = "It is in the catalog only. Add it to a store's listings to sell it there.";

        return response()->json($this->present(Product::with('description')->findOrFail($productId)) + [
            'photos' => count($paths),
            'notes' => $notes,
        ], 201);
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
