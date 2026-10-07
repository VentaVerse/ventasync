<?php

namespace App\Actions\Catalog;

use App\Models\Catalog\Product;
use App\Rules\UniqueSku;
use App\Services\Catalog\ProductCreator;
use App\Services\Catalog\VariationWriter;
use App\Services\Media\CatalogImageImporter;
use App\Support\Actor;
use App\Support\Api\Refused;
use App\Support\LineCost;
use App\Support\VariationRows;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ProductWrites
{
    public const MAX_VARIATIONS = 50;

    public function update(array $input, int $id): array
    {
        $product = Product::with('description')->find($id);
        if (! $product) {
            return $this->json(['message' => 'Product not found.'], 404);
        }

        $maxImages = ProductCreator::MAX_IMAGES;
        $data = Validator::make($input, [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string|max:65000',
            'sku' => ['sometimes', 'required', 'string', 'max:64', new UniqueSku($id)],
            'enabled' => 'sometimes|boolean',
            'weight' => 'sometimes|numeric|gt:0',
            'length' => 'sometimes|numeric|gt:0',
            'width' => 'sometimes|numeric|gt:0',
            'height' => 'sometimes|numeric|gt:0',
            'manufacturer_name' => 'sometimes|nullable|string|max:255',
            'category_names' => 'sometimes|array|max:10',
            'category_names.*' => 'string|max:255',
            'photos' => 'sometimes|array|max:' . $maxImages,
            'photos.*' => 'string|max:512',
            'image_urls' => 'nullable|array|max:' . $maxImages,
            'image_urls.*' => 'string|max:2048',
            'image_files' => 'nullable|array|max:' . $maxImages,
            'image_files.*' => 'array',
            'image_files.*.filename' => 'required|string|max:255',
            'image_files.*.data' => 'required|string|max:7700000',
            'reason' => 'required|string|min:3|max:500',
        ], [
            'image_files.*.data.max' => 'Each photo must be 5 MB or smaller.',
        ])->validate();

        $creator = app(ProductCreator::class);
        $notes = [];
        $fields = [];

        if (array_key_exists('name', $data)) {
            $fields['title'] = trim((string) $data['name']);
        }
        if (array_key_exists('description', $data)) {
            $fields['description'] = (string) ($data['description'] ?? '');
        }
        if (array_key_exists('sku', $data)) {
            $fields['sku'] = trim((string) $data['sku']);
        }
        foreach (['weight', 'length', 'width', 'height'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = (float) $data[$key];
            }
        }

        if (array_key_exists('manufacturer_name', $data)) {
            $brand = trim((string) ($data['manufacturer_name'] ?? ''));
            $manufacturerId = $creator->resolveManufacturerId(0, $brand);
            if ($brand !== '' && $manufacturerId === 0) {
                $notes[] = 'No brand named "' . $brand . '" exists, so the brand was left as it was.';
            } else {
                $fields['manufacturer_id'] = $manufacturerId;
            }
        }

        if (array_key_exists('category_names', $data)) {
            $categoryIds = [];
            $unknown = [];
            foreach ((array) $data['category_names'] as $categoryName) {
                $categoryId = $creator->resolveCategoryId(0, (string) $categoryName);
                if ($categoryId > 0) {
                    $categoryIds[] = $categoryId;
                } else {
                    $unknown[] = (string) $categoryName;
                }
            }
            if ($unknown !== []) {
                $notes[] = 'No category named "' . implode('", "', $unknown) . '" exists, so the categories were left as they were.';
            } else {
                $fields['category_ids'] = $categoryIds;
            }
        }

        $urls = array_values((array) ($data['image_urls'] ?? []));
        $files = array_values((array) ($data['image_files'] ?? []));
        $current = \App\Support\Catalog\ProductImages::paths($id);
        $stored = [];
        if (array_key_exists('photos', $data) || $urls !== [] || $files !== []) {
            $kept = array_key_exists('photos', $data) ? array_values((array) $data['photos']) : $current;
            $strangers = array_values(array_diff($kept, $current));
            if ($strangers !== []) {
                return $this->json(['message' => 'These are not photos of this product: ' . implode(', ', $strangers)
                    . '. Keep photos by the paths products_get gives, and add new ones by link or data. Nothing was changed.'], 422);
            }
            if (count($kept) + count($urls) + count($files) > $maxImages) {
                return $this->json(['message' => "At most {$maxImages} photos in all. Nothing was changed."], 422);
            }

            $importer = app(CatalogImageImporter::class);
            $folder = $creator->imageFolder((int) ($fields['manufacturer_id'] ?? $product->manufacturer_id));
            $where = '';
            try {
                foreach ($urls as $i => $url) {
                    $where = 'Photo link ' . ($i + 1);
                    $stored[] = $importer->fromUrl((string) $url, $folder);
                }
                foreach ($files as $i => $file) {
                    $where = 'Photo file ' . ($i + 1) . ' (' . (string) $file['filename'] . ')';
                    $stored[] = $importer->fromBase64((string) $file['data'], (string) $file['filename'], $folder);
                }
            } catch (\RuntimeException $e) {
                Storage::disk('public')->delete($stored);

                return $this->json(['message' => $where . ' could not be used: ' . $e->getMessage() . ' Nothing was changed.'], 422);
            }
            $fields['photos'] = array_merge($kept, $stored);
        }

        $photosAfter = $fields['photos'] ?? $current;
        $enabledAfter = array_key_exists('enabled', $data) ? (bool) $data['enabled'] : (bool) $product->status;
        if ($enabledAfter && $photosAfter === []) {
            Storage::disk('public')->delete($stored);

            return $this->json(['message' => 'A product needs at least one photo to be switched on. Nothing was changed.'], 422);
        }
        if (array_key_exists('enabled', $data)) {
            $fields['status'] = (bool) $data['enabled'];
        }

        $before = $this->present($product) + $this->details($product);
        $changes = app(\App\Services\Catalog\CatalogBasicsWriter::class)->edit($id, $fields, [
            'actor' => (string) (Actor::current()->name ?? 'An API application'),
            'reason' => trim((string) $data['reason']),
        ]);

        if (isset($changes['sku'])) {
            $synced = (new \App\Services\SkuSyncService())->syncSkuChanges($id, [
                'product_sku' => ['old' => $changes['sku']['old'], 'new' => $changes['sku']['new']],
                'option_skus' => [],
            ]);
            foreach ($synced as $line) {
                $notes[] = 'SKU synced: ' . $line;
            }
        }

        $product = Product::with('description')->findOrFail($id);
        $after = $this->present($product) + $this->details($product);

        $changed = array_keys($changes);
        if ($changed === []) {
            $notes[] = 'Nothing was different from what the product already holds, so nothing was written.';
        } else {
            $notes[] = "It is changed in the catalog only. Each store's listing keeps its own copy and shows the change for you to take or leave.";
        }

        return $this->json([
            'product_id' => $id,
            'changed' => array_values(array_map(fn ($k) => match ($k) {
                'title' => 'name', 'manufacturer_id' => 'brand', 'category_ids' => 'categories', 'images' => 'photos', 'status' => 'enabled', default => $k,
            }, $changed)),
            'before' => array_intersect_key($before, $this->shown($changed)),
            'after' => array_intersect_key($after, $this->shown($changed)),
            'notes' => $notes,
        ]);
    }

    public function updateVariations(array $input, int $id): array
    {
        if (! Product::whereKey($id)->exists()) {
            return $this->json(['message' => 'Product not found.'], 404);
        }

        $data = Validator::make($input, [
            'changes' => 'nullable|array|max:' . self::MAX_VARIATIONS,
            'changes.*.sku' => 'required|string|max:64',
            'changes.*.new_sku' => 'nullable|string|max:64',
            'changes.*.enabled' => 'nullable|boolean',
            'changes.*.image_url' => 'nullable|string|max:2048',
            'add' => 'nullable|array|max:' . self::MAX_VARIATIONS,
            'add.*.values' => 'required|array|min:1|max:2',
            'add.*.values.*' => 'required|string|max:128',
            'add.*.sku' => 'required|string|max:64',
            'add.*.price' => 'required|numeric|gt:0|max:99999999',
            'add.*.cost_amount' => 'nullable|numeric|min:0|max:99999999',
            'add.*.image_url' => 'nullable|string|max:2048',
            'reason' => 'required|string|min:3|max:500',
        ])->validate();

        $changes = array_values((array) ($data['changes'] ?? []));
        $additions = array_values((array) ($data['add'] ?? []));
        if ($changes === [] && $additions === []) {
            return $this->json(['message' => 'Name at least one variation to change or add.'], 422);
        }

        $creator = app(ProductCreator::class);
        $importer = app(CatalogImageImporter::class);
        $folder = $creator->imageFolder((int) Product::whereKey($id)->value('manufacturer_id'));
        $stored = [];
        $where = '';
        try {
            foreach ($changes as $i => $change) {
                if (! empty($change['image_url'])) {
                    $where = 'The picture for ' . $change['sku'];
                    $changes[$i]['image'] = $stored[] = $importer->fromUrl((string) $change['image_url'], $folder);
                }
            }
            foreach ($additions as $i => $add) {
                if (! empty($add['image_url'])) {
                    $where = 'The picture for ' . $add['sku'];
                    $additions[$i]['image'] = $stored[] = $importer->fromUrl((string) $add['image_url'], $folder);
                }
            }
        } catch (\RuntimeException $e) {
            Storage::disk('public')->delete($stored);

            return $this->json(['message' => $where . ' could not be used: ' . $e->getMessage() . ' Nothing was changed.'], 422);
        }

        try {
            $report = app(\App\Services\Catalog\VariationEditor::class)->edit($id, $changes, $additions, [
                'actor' => (string) (Actor::current()->name ?? 'An API application'),
                'reason' => trim((string) $data['reason']),
            ]);
        } catch (\RuntimeException $e) {
            Storage::disk('public')->delete($stored);

            return $this->json(['message' => $e->getMessage()], 422);
        } catch (ValidationException $e) {
            Storage::disk('public')->delete($stored);
            throw $e;
        }

        $report['notes'][] = "It is changed in the catalog only. Each store's listing picks it up on its next push.";

        return $this->json(['product_id' => $id] + $report + [
            'variations' => $this->variations(Product::with('description')->findOrFail($id)),
        ]);
    }

    private function shown(array $changed): array
    {
        $keys = ['name' => 'name', 'title' => 'name', 'description' => 'description', 'sku' => 'sku', 'status' => 'status',
            'manufacturer_id' => 'brand', 'category_ids' => 'categories', 'images' => 'photos',
            'weight' => 'weight', 'length' => 'length', 'width' => 'width', 'height' => 'height'];

        return array_flip(array_values(array_intersect_key($keys, array_flip($changed))));
    }

    public function details(Product $product): array
    {
        $pfx = (string) config('catalog.prefix');
        $lang = (int) config('catalog.default_language_id');
        $id = (int) $product->product_id;

        $brand = (int) $product->manufacturer_id > 0
            ? \Illuminate\Support\Facades\DB::table($pfx . 'manufacturer')->where('manufacturer_id', $product->manufacturer_id)->value('name')
            : null;
        $categories = \Illuminate\Support\Facades\DB::table($pfx . 'product_to_category as ptc')
            ->leftJoin($pfx . 'category_description as cd', fn ($j) => $j->on('cd.category_id', '=', 'ptc.category_id')->where('cd.language_id', '=', $lang))
            ->where('ptc.product_id', $id)
            ->orderBy('ptc.category_id')
            ->pluck('cd.name')
            ->map(fn ($n) => html_entity_decode((string) $n, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            ->all();

        return [
            'description' => (string) ($product->description?->description ?? ''),
            'brand' => $brand !== null ? html_entity_decode((string) $brand, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
            'categories' => $categories,
            'weight' => (float) $product->weight,
            'length' => (float) $product->length,
            'width' => (float) $product->width,
            'height' => (float) $product->height,
            'photos' => array_map(fn (string $path) => [
                'path' => $path,
                'url' => \App\Support\Catalog\ProductImages::url($path),
            ], \App\Support\Catalog\ProductImages::paths($id)),
        ];
    }

    public function updatePrice(array $input, int $id): array
    {
        $data = Validator::make($input, [
            'price' => 'required|numeric|min:0|max:99999999',
            'variation_sku' => 'nullable|string|max:64',
            'reason' => 'required|string|min:3|max:500',
        ])->validate();

        try {
            $result = app(\App\Services\Catalog\ProductPriceWriter::class)->set(
                $id,
                $data['variation_sku'] ?? null,
                (float) $data['price'],
                trim((string) $data['reason']),
                (string) (Actor::current()->name ?? 'An API application')
            );
        } catch (\RuntimeException $e) {
            return $this->json(['message' => $e->getMessage()], 422);
        }

        return $this->json($result + [
            'currency' => \App\Support\Money::defaultCode(),
            'note' => 'Saved on the catalog product. Marketplace prices are this price run through each '
                . "listing's own rule, so they change on the next push or price sync, not now.",
        ]);
    }

    public function store(array $input): array
    {
        if ($full = \App\Plans\Quota::refusal('products')) {
            throw ValidationException::withMessages(['product' => [$full]]);
        }

        $maxImages = ProductCreator::MAX_IMAGES;
        $maxVariations = self::MAX_VARIATIONS;

        $data = Validator::make($input, [
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
        ])->validate();

        $urls = array_values((array) ($data['image_urls'] ?? []));
        $files = array_values((array) ($data['image_files'] ?? []));
        if (count($urls) + count($files) > $maxImages) {
            throw ValidationException::withMessages(['image_files' => ["At most {$maxImages} photos in all, links and files together."]]);
        }

        $creator = app(ProductCreator::class);
        $writer = app(VariationWriter::class);
        $notes = [];

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

            return $this->json(['message' => $where . ' could not be used: ' . $e->getMessage() . ' Nothing was created.'], 422);
        }

        if ($paths === []) {
            $notes[] = 'Saved switched off: a product needs at least one photo before it can be switched on and sold.';
        }

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
                'actor' => (string) (Actor::current()->name ?? 'An API application'),
                'reason' => trim((string) $data['reason']),
            ]);
        } catch (\Throwable $e) {
            $disk->delete($stored);
            throw $e;
        }

        $notes[] = "It is in the catalog only. Add it to a store's listings to sell it there.";

        $product = Product::with('description')->findOrFail($productId);

        return $this->json($this->present($product) + [
            'photos' => count($paths),
            'variations' => $this->variations($product),
            'notes' => $notes,
        ], 201);
    }

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

    public function present(Product $product): array
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

    public function variations(Product $product): array
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

    private function json(array $data, int $status = 200): array
    {
        if ($status >= 400) {
            throw new Refused((string) ($data['message'] ?? 'The change was refused.'), $status);
        }

        return $data;
    }
}
