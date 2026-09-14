<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Rules\UniqueSku;
use App\Services\Catalog\ProductCreator;
use App\Services\Catalog\VariationWriter;
use App\Services\Media\CatalogImageImporter;
use App\Support\LineCost;
use App\Support\VariationRows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Product access for external API clients: reading (products:read), and
 * setting a price or creating a product (products:write). Exposes the
 * catalog and pricing fields an external tool needs: cost, price,
 * quantity, status. Nothing customer-facing is returned here.
 */
class ProductController extends Controller
{
    /** The most variations a product is created with here. */
    public const MAX_VARIATIONS = 50;

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
     * The same product the Add product form makes: ProductCreator makes it
     * and VariationWriter saves its variations, the code the form runs.
     * Every field and every SKU is checked first; then the photos are
     * fetched or decoded, and a photo that cannot be used creates nothing
     * and names itself. A product with no photo is saved switched off,
     * because a product needs a photo to be sold.
     */
    public function store(Request $request): JsonResponse
    {
        $maxImages = ProductCreator::MAX_IMAGES;
        $maxVariations = self::MAX_VARIATIONS;

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => ['required', 'string', 'max:64', new UniqueSku()],
            'price' => 'required|numeric|gt:0|max:99999999',
            'weight' => 'required|numeric|gt:0',
            'length' => 'required|numeric|gt:0',
            'width' => 'required|numeric|gt:0',
            'height' => 'required|numeric|gt:0',
            'description' => 'nullable|string|max:65000',
            'quantity' => 'nullable|integer|min:0',
            'cost_amount' => 'nullable|numeric|min:0',
            'cost_percentage' => 'nullable|numeric|min:0|max:100',
            'cost_additional' => 'nullable|numeric|min:0|max:99999999',
            'manufacturer_name' => 'nullable|string|max:255',
            'category_names' => 'nullable|array|max:10',
            'category_names.*' => 'string|max:255',
            'image_urls' => 'nullable|array|max:' . $maxImages,
            'image_urls.*' => 'string|max:2048',
            'image_files' => 'nullable|array|max:' . $maxImages,
            'image_files.*' => 'array',
            'image_files.*.filename' => 'required|string|max:255',
            // Room for line breaks and a data: prefix; the importer judges the decoded size.
            'image_files.*.data' => 'required|string|max:7700000',
            'variation_types' => 'nullable|array|max:2',
            'variation_types.*' => 'array',
            'variation_types.*.name' => 'nullable|string|max:128',
            'variation_types.*.values' => 'nullable|array|max:' . $maxVariations,
            'variation_types.*.values.*' => 'nullable|string|max:128',
            'variations' => 'nullable|array|max:' . $maxVariations,
            'variations.*' => 'array',
            'variations.*.values' => 'required|array|min:1|max:2',
            'variations.*.values.*' => 'nullable|string|max:128',
            'variations.*.sku' => 'nullable|string|max:64',
            'variations.*.price' => 'required|numeric|gt:0|max:99999999',
            'variations.*.quantity' => 'required|integer|min:0|max:9999999',
            'variations.*.cost_amount' => 'nullable|numeric|min:0|max:99999999',
            'variations.*.image_url' => 'nullable|string|max:2048',
            'enabled' => 'nullable|boolean',
            'reason' => 'required|string|min:3|max:500',
        ], [
            'image_urls.max' => "At most {$maxImages} photos.",
            'image_files.max' => "At most {$maxImages} photos.",
            'image_files.*.data.max' => 'Each photo must be 5 MB or smaller.',
            'variation_types.max' => 'At most two variation types.',
            'variations.max' => "At most {$maxVariations} variations.",
            'variations.*.values.required' => 'Each variation names its value for each variation type.',
            'variations.*.price.required' => 'Each variation needs a price above zero.',
            'variations.*.price.gt' => "Each variation's price must be more than zero.",
            'variations.*.quantity.required' => 'Each variation needs its stock, 0 or more.',
        ]);

        $urls = array_values((array) ($data['image_urls'] ?? []));
        $files = array_values((array) ($data['image_files'] ?? []));
        if (count($urls) + count($files) > $maxImages) {
            throw ValidationException::withMessages(['image_files' => ["At most {$maxImages} photos in all, links and files together."]]);
        }

        $creator = app(ProductCreator::class);
        $writer = app(VariationWriter::class);
        $notes = [];

        // The variations as the form sends them, and the form's SKU checks, before anything is fetched.
        $wire = $this->variationInput($data);
        if ($wire !== null) {
            $writer->validateSkus(0, $wire);
        }

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

        // The photos next: one that cannot be used creates nothing.
        $disk = Storage::disk('public');
        $importer = app(CatalogImageImporter::class);
        $folder = $creator->imageFolder($manufacturerId);
        $stored = [];
        $paths = [];
        $where = '';
        try {
            foreach ($urls as $i => $url) {
                $where = 'Photo ' . ($i + 1);
                $paths[] = $stored[] = $importer->fromUrl((string) $url, $folder);
            }
            foreach ($files as $i => $file) {
                $where = 'Photo file ' . ($i + 1) . ' (' . (string) $file['filename'] . ')';
                $paths[] = $stored[] = $importer->fromBase64((string) $file['data'], (string) $file['filename'], $folder);
            }
            if ($wire !== null) {
                $variationImages = [];
                foreach ($this->variationImageUrls($wire) as $url => $sku) {
                    $where = 'The picture for variation ' . $sku;
                    $variationImages[$url] = $stored[] = $importer->fromUrl($url, $folder);
                }
                $wire = $this->withVariationImages($wire, $variationImages);
            }
        } catch (\RuntimeException $e) {
            $disk->delete($stored);

            return response()->json(['message' => $where . ' could not be used: ' . $e->getMessage() . ' Nothing was created.'], 422);
        }

        if ($paths === []) {
            $notes[] = 'Saved switched off: a product needs at least one photo before it can be switched on and sold.';
        }

        // A product with variations holds the sum of their stock, as on the form.
        $quantity = (int) ($data['quantity'] ?? 0);
        if ($wire !== null) {
            $sum = (int) $writer->totalQuantity($wire);
            if (isset($data['quantity']) && $quantity !== $sum) {
                $notes[] = "Stock is the sum of the variations' stock, {$sum}; the quantity sent was not used.";
            }
            $quantity = $sum;
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
                'quantity' => $quantity,
                'cost_amount' => (float) ($data['cost_amount'] ?? 0),
                'cost_percentage' => (float) ($data['cost_percentage'] ?? 0),
                'cost_additional' => (float) ($data['cost_additional'] ?? 0),
                'manufacturer_id' => $manufacturerId,
                'category_ids' => array_values(array_unique($categoryIds)),
                'description' => (string) ($data['description'] ?? ''),
                'image_paths' => $paths,
            ], variations: $wire === null ? null : function (int $id) use ($writer, $wire): void {
                $writer->save($id, $wire);
            }, by: [
                'source' => 'api',
                'actor' => (string) ($request->user()?->name ?? 'An API application'),
                'reason' => trim((string) $data['reason']),
            ]);
        } catch (\Throwable $e) {
            $disk->delete($stored);
            throw $e;
        }

        $notes[] = "It is in the catalog only. Add it to a store's listings to sell it there.";

        $product = Product::with('description')->findOrFail($productId);

        return response()->json($this->present($product) + [
            'photos' => count($paths),
            'variations' => $this->variations($product),
            'notes' => $notes,
        ], 201);
    }

    /**
     * The API's variations in the shape the product form posts: one type as
     * `option_name` and `values[]`, two as `option1_name`, `option2_name`,
     * their value lists and `combinations[]`. The API names the types once
     * in `variation_types` and gives one row in `variations` per value, or
     * per pair of values, so every combination must be there exactly once.
     * Refused in the form's own words where the form has them.
     *
     * @return array<string, mixed>|null null when the product has no variations
     *
     * @throws ValidationException
     */
    private function variationInput(array $data): ?array
    {
        $types = array_values((array) ($data['variation_types'] ?? []));
        $rows = array_values((array) ($data['variations'] ?? []));
        if ($types === [] && $rows === []) {
            return null;
        }
        if ($types === []) {
            throw ValidationException::withMessages(['variation_types' => ['Variation 1 name is required.']]);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['variations' => ['Each variation needs its own row, with a SKU, a price and its stock.']]);
        }

        $lower = fn (string $s): string => mb_strtolower($s, 'UTF-8');
        $errors = [];
        $names = [];
        $values = [];
        foreach ($types as $t => $type) {
            $label = 'Variation ' . ($t + 1);
            $names[$t] = trim((string) ($type['name'] ?? ''));
            if ($names[$t] === '') {
                $errors[] = $label . ' name is required.';
            }
            $values[$t] = [];
            $seen = [];
            foreach ((array) ($type['values'] ?? []) as $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    $errors[] = 'All variation values must have a name.';
                    continue;
                }
                if (isset($seen[$lower($value)])) {
                    $errors[] = 'Duplicate value name "' . $value . '" in ' . $label . '.';
                    continue;
                }
                $seen[$lower($value)] = true;
                $values[$t][] = $value;
            }
            if ($values[$t] === []) {
                $errors[] = $label . ' must have at least one value.';
            }
        }
        if (count($names) === 2 && $names[0] !== '' && $lower($names[0]) === $lower($names[1])) {
            $errors[] = 'Variation 1 and Variation 2 cannot have the same name.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['variation_types' => array_values(array_unique($errors))]);
        }

        // Every value, or every pair of values: the grid the form's builder draws.
        $grid = [[]];
        foreach ($values as $list) {
            $next = [];
            foreach ($grid as $prefix) {
                foreach ($list as $value) {
                    $next[] = [...$prefix, $value];
                }
            }
            $grid = $next;
        }
        if (count($grid) > self::MAX_VARIATIONS) {
            throw ValidationException::withMessages(['variations' => [
                'At most ' . self::MAX_VARIATIONS . ' variations: these values make ' . count($grid) . '.',
            ]]);
        }

        $keyOf = fn (array $combo): string => $lower(implode("\u{1F}", $combo));
        $byKey = [];
        $missingSku = 0;
        foreach ($rows as $i => $row) {
            $n = $i + 1;
            $given = array_values((array) ($row['values'] ?? []));
            if (count($given) !== count($types)) {
                $errors[] = "Variation row {$n} must name one value for each variation type, in order: " . implode(', ', $names) . '.';
                continue;
            }
            $combo = [];
            foreach ($given as $t => $value) {
                $value = trim((string) $value);
                $match = null;
                foreach ($values[$t] as $known) {
                    if ($lower($known) === $lower($value)) {
                        $match = $known;
                        break;
                    }
                }
                if ($match === null) {
                    $errors[] = "Variation row {$n}: \"{$value}\" is not one of the values of {$names[$t]}.";
                    continue 2;
                }
                $combo[] = $match;
            }
            if (isset($byKey[$keyOf($combo)])) {
                $errors[] = "Variation row {$n} repeats " . implode(' / ', $combo) . '.';
                continue;
            }
            if (trim((string) ($row['sku'] ?? '')) === '') {
                $missingSku++;
            }
            $byKey[$keyOf($combo)] = $row;
        }
        if ($errors === []) {
            foreach ($grid as $combo) {
                if (! isset($byKey[$keyOf($combo)])) {
                    $errors[] = implode(' / ', $combo) . ' has no variation row. Every value, or every pair of values, needs one.';
                }
            }
        }
        if ($missingSku > 0) {
            $errors[] = $missingSku === 1 ? 'One new variation needs an SKU.' : $missingSku . ' new variations need an SKU.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['variations' => $errors]);
        }

        $line = fn (array $row): array => [
            'sku' => trim((string) $row['sku']),
            'quantity' => (int) $row['quantity'],
            'absolute_price' => (float) $row['price'],
            'cost_amount' => (float) ($row['cost_amount'] ?? 0),
            'image_url' => trim((string) ($row['image_url'] ?? '')),
        ];

        $wire = [
            'sku' => trim((string) $data['sku']),
            'cost_percentage' => (float) ($data['cost_percentage'] ?? 0),
            'cost_additional' => (float) ($data['cost_additional'] ?? 0),
        ];
        if (count($types) === 1) {
            $wire['option_name'] = $names[0];
            $wire['values'] = array_map(fn (array $combo): array => ['name' => $combo[0]] + $line($byKey[$keyOf($combo)]), $grid);
        } else {
            $wire['option1_name'] = $names[0];
            $wire['option2_name'] = $names[1];
            $wire['option1_values'] = $values[0];
            $wire['option2_values'] = $values[1];
            $wire['combinations'] = array_map(
                fn (array $combo): array => ['opt1' => $combo[0], 'opt2' => $combo[1]] + $line($byKey[$keyOf($combo)]),
                $grid
            );
        }

        return $wire;
    }

    /** @return array<string, string> each distinct variation picture link, with the first SKU that uses it */
    private function variationImageUrls(array $wire): array
    {
        $urls = [];
        foreach ([...($wire['values'] ?? []), ...($wire['combinations'] ?? [])] as $row) {
            if ($row['image_url'] !== '' && ! isset($urls[$row['image_url']])) {
                $urls[$row['image_url']] = $row['sku'];
            }
        }

        return $urls;
    }

    /** The rows with each link replaced by the library path it was stored at. */
    private function withVariationImages(array $wire, array $paths): array
    {
        foreach (['values', 'combinations'] as $list) {
            foreach ($wire[$list] ?? [] as $k => $row) {
                $wire[$list][$k]['image'] = $row['image_url'] !== '' ? $paths[$row['image_url']] : '';
                unset($wire[$list][$k]['image_url']);
            }
        }

        return $wire;
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
