<?php

namespace Extensions\opencart\Services\OpenCart;

use Extensions\opencart\Models\OpenCartProductLink;
use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Models\OpenCartSyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OpenCartProductSync
{
    private OpenCartClient $client;
    private OpenCartSetting $setting;

    /** @var array<int,int> oc_category_id → core category_id */
    private array $categoryMap = [];

    /** @var array<int,int> oc_manufacturer_id → core manufacturer_id */
    private array $manufacturerMap = [];

    /** @var array<int,int> oc_option_id → core option_id */
    private array $optionMap = [];

    /** @var array<int,int> oc_option_value_id → core option_value_id */
    private array $optionValueMap = [];

    public function __construct(OpenCartClient $client, OpenCartSetting $setting)
    {
        $this->client = $client;
        $this->setting = $setting;
    }

    public function setCategoryMap(array $map): void
    {
        $this->categoryMap = $map;
    }

    public function setManufacturerMap(array $map): void
    {
        $this->manufacturerMap = $map;
    }

    public function setOptionMap(array $map): void
    {
        $this->optionMap = $map;
    }

    public function setOptionValueMap(array $map): void
    {
        $this->optionValueMap = $map;
    }

    /**
     * Auto-build category, manufacturer, and option maps from OC API + local DB.
     * Called automatically when maps are empty (e.g. individual sync from UI).
     */
    private function autoPopulateMaps(): void
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        // Categories: OC API → match by name in local DB
        if (empty($this->categoryMap)) {
            $result = $this->client->getCategories();
            if ($result['ok']) {
                $ocCategories = $result['body']['data'] ?? [];
                foreach ($ocCategories as $cat) {
                    $ocId = (int) ($cat['category_id'] ?? 0);
                    $name = trim($cat['name'] ?? '');
                    if ($ocId <= 0 || $name === '') continue;

                    $local = DB::table($pfx . 'category_description')
                        ->where('language_id', $langId)
                        ->where('name', $name)
                        ->value('category_id');

                    if ($local) {
                        $this->categoryMap[$ocId] = (int) $local;
                    }
                }
            }
        }

        // Manufacturers: OC API → match by name in local DB
        if (empty($this->manufacturerMap)) {
            $result = $this->client->getManufacturers();
            if ($result['ok']) {
                $ocMfgs = $result['body']['data'] ?? [];
                foreach ($ocMfgs as $mfg) {
                    $ocId = (int) ($mfg['manufacturer_id'] ?? 0);
                    $name = trim($mfg['name'] ?? '');
                    if ($ocId <= 0 || $name === '') continue;

                    $local = DB::table($pfx . 'manufacturer')
                        ->where('name', $name)
                        ->value('manufacturer_id');

                    if ($local) {
                        $this->manufacturerMap[$ocId] = (int) $local;
                    }
                }
            }
        }

        // Options + Option Values: OC API → match by name in local DB
        if (empty($this->optionMap)) {
            $result = $this->client->getOptions();
            if ($result['ok']) {
                $ocOptions = $result['body']['data'] ?? [];
                foreach ($ocOptions as $opt) {
                    $ocOptionId = (int) ($opt['option_id'] ?? 0);
                    $name = trim($opt['name'] ?? '');
                    if ($ocOptionId <= 0 || $name === '') continue;

                    $local = DB::table($pfx . 'option AS o')
                        ->join($pfx . 'option_description AS od', function ($join) use ($langId) {
                            $join->on('o.option_id', '=', 'od.option_id')
                                ->where('od.language_id', '=', $langId);
                        })
                        ->where('od.name', $name)
                        ->value('o.option_id');

                    if ($local) {
                        $this->optionMap[$ocOptionId] = (int) $local;

                        // Map option values by name within this option
                        $values = $opt['values'] ?? [];
                        foreach ($values as $val) {
                            $ocValueId = (int) ($val['option_value_id'] ?? 0);
                            $valName = trim($val['name'] ?? '');
                            if ($ocValueId <= 0 || $valName === '') continue;

                            $localVal = DB::table($pfx . 'option_value AS ov')
                                ->join($pfx . 'option_value_description AS ovd', function ($join) use ($langId) {
                                    $join->on('ov.option_value_id', '=', 'ovd.option_value_id')
                                        ->where('ovd.language_id', '=', $langId);
                                })
                                ->where('ov.option_id', (int) $local)
                                ->where('ovd.name', $valName)
                                ->value('ov.option_value_id');

                            if ($localVal) {
                                $this->optionValueMap[$ocValueId] = (int) $localVal;
                            }
                        }
                    }
                }
            }
        }

        Log::info('OpenCart product sync auto-populated maps', [
            'categories'    => count($this->categoryMap),
            'manufacturers' => count($this->manufacturerMap),
            'options'       => count($this->optionMap),
            'optionValues'  => count($this->optionValueMap),
        ]);
    }

    /**
     * Pull products from OpenCart into the local catalog tables.
     * Uses SKU-based matching — auto-increments IDs.
     */
    public function pull(?string $modifiedSince = null, bool $full = false, ?int $productId = null): OpenCartSyncLog
    {
        // Auto-build maps if not provided (e.g. individual sync from UI)
        $this->autoPopulateMaps();
        $log = OpenCartSyncLog::create([
            'opencart_setting_id' => $this->setting->id,
            'entity_type' => 'product',
            'direction'   => 'pull',
            'status'      => 'started',
            'started_at'  => now(),
        ]);

        try {
            $since = ($full || $productId) ? null : ($modifiedSince ?? $this->setting->last_product_sync_at?->toIso8601String());
            $page = 1;
            $limit = 100;
            $created = 0;
            $updated = 0;
            $failed = 0;
            $errors = [];

            do {
                $result = $this->client->getProducts($page, $limit, $since, $productId);

                if (!$result['ok']) {
                    throw new \RuntimeException('API error: ' . json_encode($result['body']));
                }

                $products = $result['body']['data'] ?? [];
                $pagination = $result['body']['pagination'] ?? [];

                foreach ($products as $raw) {
                    try {
                        $wasCreated = $this->upsertProduct($raw);
                        if ($wasCreated) {
                            $created++;
                        } else {
                            $updated++;
                        }
                    } catch (\Throwable $e) {
                        $failed++;
                        $errors[] = [
                            'product_id' => $raw['product_id'] ?? '?',
                            'sku'        => $raw['sku'] ?? '',
                            'error'      => $e->getMessage(),
                        ];
                    }
                }

                $page++;
                $hasMore = $page <= ($pagination['total_pages'] ?? 0);
            } while ($hasMore);

            // Don't update last sync timestamp when importing a single product
            if (!$productId) {
                $this->setting->update(['last_product_sync_at' => now()]);
            }

            $log->update([
                'status'            => 'completed',
                'records_processed' => $created + $updated + $failed,
                'records_created'   => $created,
                'records_updated'   => $updated,
                'records_failed'    => $failed,
                'details'           => $errors ?: null,
                'completed_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
            Log::error('OpenCart product sync failed', ['error' => $e->getMessage()]);
        }

        return $log;
    }

    /**
     * Push product changes from Laravel to OpenCart.
     * Products WITH a link → bulk update.
     * Products WITHOUT a link → create individually, save link.
     *
     * @return array{updated: int, created: int, failed: int, errors: array}
     */
    public function push(array $productIds): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $updated = 0;
        $created = 0;
        $failed = 0;
        $errors = [];

        // Get existing links for this store
        $links = OpenCartProductLink::where('opencart_setting_id', $this->setting->id)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        $toUpdate = [];
        $toCreate = [];

        foreach ($productIds as $pid) {
            if ($links->has($pid)) {
                $toUpdate[] = $pid;
            } else {
                $toCreate[] = $pid;
            }
        }

        // -- Bulk update existing products --
        // Track products that failed update (deleted from OC) so we can re-create them
        $staleLinks = [];

        foreach (array_chunk($toUpdate, 50) as $chunk) {
            $products = [];
            $chunkMap = []; // oc_product_id => laravel product_id

            foreach ($chunk as $pid) {
                $product = DB::table($pfx . 'product')
                    ->where('product_id', (int) $pid)
                    ->first();

                if (!$product) continue;

                if ((int) $product->status === 0) {
                    $failed++;
                    $errors[] = ['product_id' => $pid, 'error' => 'Product is disabled (status=0), skipped.'];
                    continue;
                }

                $desc = DB::table($pfx . 'product_description')
                    ->where('product_id', (int) $pid)
                    ->where('language_id', $langId)
                    ->first();

                $link = $links->get($pid);
                $ocPid = (int) $link->oc_product_id;

                // Resolve manufacturer name for image folder
                $mfgName = '';
                if ((int) $product->manufacturer_id > 0) {
                    $mfg = DB::table($pfx . 'manufacturer')
                        ->where('manufacturer_id', (int) $product->manufacturer_id)
                        ->first();
                    if ($mfg) $mfgName = $mfg->name;
                }

                $productData = [
                    'product_id'        => $ocPid,
                    'quantity'          => (int) $product->quantity,
                    'price'             => (string) $product->price,
                    'cost'              => (string) ($product->cost ?? 0),
                    'cost_amount'       => (string) ($product->cost_amount ?? 0),
                    'cost_percentage'   => (string) ($product->cost_percentage ?? 0),
                    'cost_additional'   => (string) ($product->cost_additional ?? 0),
                    'status'            => (int) $product->status,
                    'name'              => $desc->name ?? '',
                    'description'       => $desc->description ?? '',
                    'sku'               => $product->sku ?? '',
                    'model'             => $product->model ?? '',
                    'weight'            => (string) $product->weight,
                    'image'             => $product->image ?? '',
                    'manufacturer_name' => $mfgName,
                ];

                // Main image — base64-encode
                if (!empty($product->image)) {
                    $encoded = $this->encodeImage($product->image);
                    if ($encoded) {
                        $productData['image_data'] = $encoded;
                        $productData['image_filename'] = basename($product->image);
                    }
                }

                // Additional images
                $addlImages = DB::table($pfx . 'product_image')
                    ->where('product_id', (int) $pid)
                    ->orderBy('sort_order')
                    ->get();

                if ($addlImages->isNotEmpty()) {
                    $imagesPayload = [];
                    foreach ($addlImages as $img) {
                        $entry = [
                            'image'      => $img->image,
                            'sort_order' => (int) $img->sort_order,
                        ];
                        $encoded = $this->encodeImage($img->image);
                        if ($encoded) {
                            $entry['image_data'] = $encoded;
                            $entry['image_filename'] = basename($img->image);
                        }
                        $imagesPayload[] = $entry;
                    }
                    $productData['images'] = $imagesPayload;
                }

                // Include options so new/updated variants are synced
                $options = $this->buildOptionsPayload((int) $pid, $pfx, $langId);
                if (!empty($options)) {
                    $productData['options'] = $options;
                }

                $products[] = $productData;
                $chunkMap[$ocPid] = $pid;
            }

            if (!empty($products)) {
                $resp = $this->client->bulkUpdateProducts($products);

                if (empty($resp['ok'])) {
                    // API call failed entirely — treat all as stale
                    Log::warning('OpenCart bulk update failed, queueing for re-create', [
                        'store' => $this->setting->id,
                        'status' => $resp['status'] ?? 0,
                    ]);
                    $staleLinks = array_merge($staleLinks, array_values($chunkMap));
                } else {
                    $data = $resp['body']['data'] ?? [];
                    if (is_array($data) && !empty($data)) {
                        foreach ($data as $r) {
                            if (!empty($r['success'])) {
                                $updated++;
                            } else {
                                // Product not found in OC — stale link, queue for re-create
                                $ocPid = (int) ($r['product_id'] ?? 0);
                                if ($ocPid > 0 && isset($chunkMap[$ocPid])) {
                                    $staleLinks[] = $chunkMap[$ocPid];
                                } else {
                                    $failed++;
                                }
                            }
                        }
                    } else {
                        // Response OK but no data — treat all as stale
                        $staleLinks = array_merge($staleLinks, array_values($chunkMap));
                    }
                }
            }
        }

        // Remove stale links and add to create queue
        if (!empty($staleLinks)) {
            OpenCartProductLink::where('opencart_setting_id', $this->setting->id)
                ->whereIn('product_id', $staleLinks)
                ->delete();
            $toCreate = array_merge($toCreate, $staleLinks);
        }

        // -- Create new products one by one --
        foreach ($toCreate as $pid) {
            // Skip disabled products
            $createStatus = DB::table($pfx . 'product')->where('product_id', (int) $pid)->value('status');
            if ((int) $createStatus === 0) {
                $failed++;
                $errors[] = ['product_id' => $pid, 'error' => 'Product is disabled (status=0), skipped create.'];
                continue;
            }

            try {
                $payload = $this->buildCreatePayload((int) $pid, $pfx, $langId);
                if ($payload === null) {
                    $failed++;
                    $errors[] = ['product_id' => $pid, 'error' => 'Product not found'];
                    continue;
                }

                $resp = $this->client->createProduct($payload);

                if (!empty($resp['ok']) && !empty($resp['body']['success'])) {
                    $ocProductId = (int) ($resp['body']['data']['product_id'] ?? 0);

                    if ($ocProductId > 0) {
                        OpenCartProductLink::updateOrCreate(
                            [
                                'opencart_setting_id' => $this->setting->id,
                                'oc_product_id'       => $ocProductId,
                            ],
                            [
                                'product_id' => $pid,
                                'sku'        => $payload['sku'] ?? '',
                            ]
                        );
                        $created++;
                    } else {
                        $failed++;
                        $errors[] = ['product_id' => $pid, 'error' => 'No product_id returned'];
                    }
                } else {
                    $failed++;
                    $errors[] = ['product_id' => $pid, 'error' => $resp['body']['error'] ?? 'Unknown error'];
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['product_id' => $pid, 'error' => $e->getMessage()];
            }
        }

        return compact('updated', 'created', 'failed', 'errors');
    }

    /**
     * Build the full payload for creating a product in OC.
     * Resolves manufacturer name, category name paths, option names, etc.
     */
    private function buildCreatePayload(int $productId, string $pfx, int $langId): ?array
    {
        $product = DB::table($pfx . 'product')
            ->where('product_id', $productId)
            ->first();

        if (!$product) return null;

        $desc = DB::table($pfx . 'product_description')
            ->where('product_id', $productId)
            ->where('language_id', $langId)
            ->first();

        $payload = [
            'sku'              => $product->sku ?? '',
            'model'            => $product->model ?? '',
            'price'            => (float) $product->price,
            'cost'             => (float) ($product->cost ?? 0),
            'cost_amount'      => (float) ($product->cost_amount ?? 0),
            'cost_percentage'  => (float) ($product->cost_percentage ?? 0),
            'cost_additional'  => (float) ($product->cost_additional ?? 0),
            'quantity'         => (int) $product->quantity,
            'status'           => (int) $product->status,
            'weight'           => (float) $product->weight,
            'weight_class_id'  => (int) $product->weight_class_id,
            'length'           => (float) $product->length,
            'width'            => (float) $product->width,
            'height'           => (float) $product->height,
            'length_class_id'  => (int) $product->length_class_id,
            'image'            => $product->image ?? '',
            'shipping'         => (int) $product->shipping,
            'subtract'         => (int) $product->subtract,
            'minimum'          => (int) $product->minimum,
            'sort_order'       => (int) $product->sort_order,
            'stock_status_id'  => (int) $product->stock_status_id,
            'tax_class_id'     => (int) $product->tax_class_id,
            'date_available'   => $product->date_available ?? date('Y-m-d'),
            'name'             => $desc->name ?? '',
            'description'      => $desc->description ?? '',
            'meta_title'       => $desc->meta_title ?? '',
            'meta_description' => $desc->meta_description ?? '',
            'tag'              => $desc->tag ?? '',
        ];

        // Manufacturer name
        if ((int) $product->manufacturer_id > 0) {
            $mfg = DB::table($pfx . 'manufacturer')
                ->where('manufacturer_id', (int) $product->manufacturer_id)
                ->first();
            if ($mfg) {
                $payload['manufacturer_name'] = $mfg->name;
            }
        }

        // Category name paths (e.g. "Guitars > Electric Guitars")
        $catIds = DB::table($pfx . 'product_to_category')
            ->where('product_id', $productId)
            ->pluck('category_id');

        $categoryNames = [];
        foreach ($catIds as $catId) {
            $path = $this->buildCategoryPath((int) $catId, $pfx, $langId);
            if ($path !== '') {
                $categoryNames[] = $path;
            }
        }
        if (!empty($categoryNames)) {
            $payload['category_names'] = $categoryNames;
        }

        // Main image — base64-encode if file exists
        if (!empty($product->image)) {
            $encoded = $this->encodeImage($product->image);
            if ($encoded) {
                $payload['image_data'] = $encoded;
                $payload['image_filename'] = basename($product->image);
            }
        }

        // Additional images
        $images = DB::table($pfx . 'product_image')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->get();

        if ($images->isNotEmpty()) {
            $imagesPayload = [];
            foreach ($images as $img) {
                $entry = [
                    'image'      => $img->image,
                    'sort_order' => (int) $img->sort_order,
                ];
                $encoded = $this->encodeImage($img->image);
                if ($encoded) {
                    $entry['image_data'] = $encoded;
                    $entry['image_filename'] = basename($img->image);
                }
                $imagesPayload[] = $entry;
            }
            $payload['images'] = $imagesPayload;
        }

        // Specials
        $specials = DB::table($pfx . 'product_special')
            ->where('product_id', $productId)
            ->get();

        if ($specials->isNotEmpty()) {
            $payload['specials'] = $specials->map(fn($sp) => [
                'customer_group_id' => (int) $sp->customer_group_id,
                'priority'          => (int) $sp->priority,
                'price'             => (float) $sp->price,
                'date_start'        => $sp->date_start,
                'date_end'          => $sp->date_end,
            ])->toArray();
        }

        // Options with names
        $optionsPayload = $this->buildOptionsPayload($productId, $pfx, $langId);
        if (!empty($optionsPayload)) {
            $payload['options'] = $optionsPayload;
        }

        return $payload;
    }

    /**
     * Build the options payload for a product (used by both create and update).
     */
    private function buildOptionsPayload(int $productId, string $pfx, int $langId): array
    {
        // Get base product price for back-calculating +/- from absolute
        $basePrice = (float) DB::table($pfx . 'product')->where('product_id', $productId)->value('price');

        $productOptions = DB::table($pfx . 'product_option AS po')
            ->join($pfx . 'option_description AS od', function ($join) use ($langId) {
                $join->on('po.option_id', '=', 'od.option_id')
                    ->where('od.language_id', '=', $langId);
            })
            ->join($pfx . 'option AS o', 'po.option_id', '=', 'o.option_id')
            ->where('po.product_id', $productId)
            ->select('po.product_option_id', 'po.option_id', 'po.required', 'od.name AS option_name', 'o.type')
            ->get();

        if ($productOptions->isEmpty()) {
            return [];
        }

        $optionsPayload = [];
        foreach ($productOptions as $po) {
            $values = DB::table($pfx . 'product_option_value AS pov')
                ->join($pfx . 'option_value_description AS ovd', function ($join) use ($langId) {
                    $join->on('pov.option_value_id', '=', 'ovd.option_value_id')
                        ->where('ovd.language_id', '=', $langId);
                })
                ->where('pov.product_option_id', (int) $po->product_option_id)
                ->select(
                    'ovd.name', 'pov.sku', 'pov.quantity', 'pov.subtract',
                    'pov.absolute_price', 'pov.absolute_cost',
                    'pov.weight', 'pov.weight_prefix',
                    'pov.points', 'pov.points_prefix'
                )
                ->get();

            $optionsPayload[] = [
                'option_name' => $po->option_name,
                'type'        => $po->type,
                'required'    => (int) $po->required,
                'values'      => $values->map(function ($v) use ($basePrice) {
                    // Translate absolute price/cost to +/- for OpenCart
                    $absPrice = (float) ($v->absolute_price ?? $basePrice);
                    $priceDelta = $absPrice - $basePrice;

                    $absCost = (float) ($v->absolute_cost ?? 0);

                    return [
                        'name'            => $v->name,
                        'sku'             => $v->sku ?? '',
                        'quantity'        => (int) $v->quantity,
                        'subtract'        => (int) $v->subtract,
                        'price'           => abs($priceDelta),
                        'price_prefix'    => $priceDelta >= 0 ? '+' : '-',
                        'weight'          => (float) $v->weight,
                        'weight_prefix'   => $v->weight_prefix ?? '+',
                        'points'          => (int) $v->points,
                        'points_prefix'   => $v->points_prefix ?? '+',
                        'cost'            => $absCost,
                        'cost_amount'     => $absCost,
                        'cost_percentage' => 0,
                        'cost_additional' => 0,
                        'cost_prefix'     => '+',
                    ];
                })->toArray(),
            ];
        }

        return $optionsPayload;
    }

    /**
     * Base64-encode an image from Laravel's public storage disk.
     */
    private function encodeImage(string $path): ?string
    {
        if ($path === '') return null;

        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        if (!$disk->exists($path)) return null;

        $contents = $disk->get($path);
        if ($contents === false || $contents === '') return null;

        return base64_encode($contents);
    }

    /**
     * Build a full category path string like "Guitars > Electric Guitars"
     * by walking the category_path table from root to leaf.
     */
    private function buildCategoryPath(int $categoryId, string $pfx, int $langId): string
    {
        $rows = DB::table($pfx . 'category_path AS cp')
            ->join($pfx . 'category_description AS cd', function ($join) use ($langId) {
                $join->on('cp.path_id', '=', 'cd.category_id')
                    ->where('cd.language_id', '=', $langId);
            })
            ->where('cp.category_id', $categoryId)
            ->orderBy('cp.level')
            ->pluck('cd.name');

        return $rows->implode(' > ');
    }

    /**
     * Upsert a single product from OC API response.
     * Matches by SKU. Auto-increments IDs.
     *
     * @return bool True if created, false if updated.
     */
    private function upsertProduct(array $raw): bool
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $ocProductId = (int) ($raw['product_id'] ?? 0);
        $sku = trim($raw['sku'] ?? '');
        $created = false;
        $coreProductId = null;
        $linkedToOtherStore = false;

        // Remap manufacturer_id
        $ocMfgId = (int) ($raw['manufacturer_id'] ?? 0);
        $coreMfgId = ($ocMfgId > 0 && isset($this->manufacturerMap[$ocMfgId]))
            ? $this->manufacturerMap[$ocMfgId]
            : 0;

        DB::transaction(function () use ($raw, $pfx, $langId, $sku, $ocProductId, $coreMfgId, &$created, &$coreProductId, &$linkedToOtherStore) {
            $data = [
                'model'           => $raw['model'] ?? '',
                'sku'             => $sku,
                'upc'             => $raw['upc'] ?? '',
                'ean'             => $raw['ean'] ?? '',
                'jan'             => $raw['jan'] ?? '',
                'isbn'            => $raw['isbn'] ?? '',
                'mpn'             => $raw['mpn'] ?? '',
                'location'        => $raw['location'] ?? '',
                'quantity'        => (int) ($raw['quantity'] ?? 0),
                'stock_status_id' => (int) ($raw['stock_status_id'] ?? 0),
                'image'           => $raw['image'] ?? '',
                'manufacturer_id' => $coreMfgId,
                'shipping'        => (int) ($raw['shipping'] ?? 1),
                'price'           => $raw['price'] ?? 0,
                'points'          => (int) ($raw['points'] ?? 0),
                'tax_class_id'    => (int) ($raw['tax_class_id'] ?? 0),
                'date_available'  => (isset($raw['date_available']) && $raw['date_available'] !== '0000-00-00') ? $raw['date_available'] : '1970-01-01',
                'weight'          => $raw['weight'] ?? 0,
                'weight_class_id' => (int) ($raw['weight_class_id'] ?? 0),
                'length'          => $raw['length'] ?? 0,
                'width'           => $raw['width'] ?? 0,
                'height'          => $raw['height'] ?? 0,
                'length_class_id' => (int) ($raw['length_class_id'] ?? 0),
                'status'          => (int) ($raw['status'] ?? 0),
                'subtract'        => (int) ($raw['subtract'] ?? 1),
                'minimum'         => (int) ($raw['minimum'] ?? 1),
                'sort_order'      => (int) ($raw['sort_order'] ?? 0),
                'viewed'          => (int) ($raw['viewed'] ?? 0),
                'date_modified'   => (isset($raw['date_modified']) && $raw['date_modified'] !== '0000-00-00 00:00:00') ? $raw['date_modified'] : now()->toDateTimeString(),
            ];

            // SKU-based matching, then fallback to opencart_product_links
            $existing = null;
            if ($sku !== '') {
                $existing = DB::table($pfx . 'product')
                    ->where('sku', $sku)
                    ->select('product_id')
                    ->first();
            }
            if (!$existing) {
                $link = OpenCartProductLink::where('opencart_setting_id', $this->setting->id)
                    ->where('oc_product_id', $ocProductId)
                    ->first();
                if ($link) {
                    $existing = DB::table($pfx . 'product')
                        ->where('product_id', $link->product_id)
                        ->select('product_id')
                        ->first();
                }
            }

            if ($existing) {
                $coreProductId = (int) $existing->product_id;

                // Check if this product is already linked to a DIFFERENT store.
                // If so, only create the link — don't overwrite product data,
                // as quantities/prices may differ between stores.
                $existingLink = OpenCartProductLink::where('product_id', $coreProductId)
                    ->where('opencart_setting_id', '!=', $this->setting->id)
                    ->exists();

                if ($existingLink) {
                    $linkedToOtherStore = true;
                } else {
                    DB::table($pfx . 'product')
                        ->where('product_id', $coreProductId)
                        ->update($data);
                }
            } else {
                $data['date_added'] = (isset($raw['date_added']) && $raw['date_added'] !== '0000-00-00 00:00:00') ? $raw['date_added'] : now()->toDateTimeString();
                $coreProductId = DB::table($pfx . 'product')->insertGetId($data, 'product_id');
                $created = true;
            }
        });

        // Save product link for this store
        OpenCartProductLink::updateOrCreate(
            [
                'opencart_setting_id' => $this->setting->id,
                'oc_product_id'       => $ocProductId,
            ],
            [
                'product_id' => $coreProductId,
                'sku'        => $sku,
            ]
        );

        // If product is linked to another store, skip sub-table updates to avoid overwriting
        if ($linkedToOtherStore) {
            return false;
        }

        // Sub-tables
        $this->syncSubTable('product_description', $coreProductId, function () use ($pfx, $langId, $coreProductId, $raw) {
            DB::table($pfx . 'product_description')->updateOrInsert(
                ['product_id' => $coreProductId, 'language_id' => $langId],
                [
                    'name'             => $raw['name'] ?? '',
                    'description'      => $raw['description'] ?? '',
                    'meta_title'       => $raw['meta_title'] ?? '',
                    'meta_description' => $raw['meta_description'] ?? '',
                    'meta_keyword'     => '',
                    'tag'              => $raw['tag'] ?? '',
                ]
            );
        });

        $this->syncSubTable('product_to_category', $coreProductId, function () use ($pfx, $coreProductId, $raw) {
            if (!isset($raw['categories']) || !is_array($raw['categories'])) return;

            DB::table($pfx . 'product_to_category')
                ->where('product_id', $coreProductId)
                ->delete();

            foreach ($raw['categories'] as $ocCatId) {
                $coreCatId = $this->categoryMap[(int) $ocCatId] ?? null;
                if ($coreCatId === null) continue; // skip unmapped categories

                DB::table($pfx . 'product_to_category')->insert([
                    'product_id'  => $coreProductId,
                    'category_id' => $coreCatId,
                ]);
            }
        });

        $this->syncSubTable('product_option', $coreProductId, function () use ($pfx, $coreProductId, $raw) {
            if (!isset($raw['options']) || !is_array($raw['options'])) return;

            DB::table($pfx . 'product_option')
                ->where('product_id', $coreProductId)
                ->delete();

            foreach ($raw['options'] as $po) {
                $ocOptionId = (int) ($po['option_id'] ?? 0);
                $coreOptionId = $this->optionMap[$ocOptionId] ?? null;
                if ($coreOptionId === null) continue;

                DB::table($pfx . 'product_option')->insert([
                    'product_id' => $coreProductId,
                    'option_id'  => $coreOptionId,
                    'value'      => $po['value'] ?? '',
                    'required'   => (int) ($po['required'] ?? 0),
                ]);
            }
        });

        $this->syncSubTable('product_option_value', $coreProductId, function () use ($pfx, $coreProductId, $raw) {
            if (!isset($raw['option_values']) || !is_array($raw['option_values'])) return;

            DB::table($pfx . 'product_option_value')
                ->where('product_id', $coreProductId)
                ->delete();

            // Build a map from OC product_option_id to new core product_option_id
            // We need to look up the freshly-inserted product_option rows
            $poMap = [];
            $corePos = DB::table($pfx . 'product_option')
                ->where('product_id', $coreProductId)
                ->get();

            // Match by option_id to build a lookup
            foreach ($corePos as $cpo) {
                $poMap[(int) $cpo->option_id] = (int) $cpo->product_option_id;
            }

            foreach ($raw['option_values'] as $ov) {
                $ocOptionId = (int) ($ov['option_id'] ?? 0);
                $ocValueId = (int) ($ov['option_value_id'] ?? 0);

                $coreOptionId = $this->optionMap[$ocOptionId] ?? null;
                $coreValueId = $this->optionValueMap[$ocValueId] ?? null;

                if ($coreOptionId === null || $coreValueId === null) continue;

                $corePoId = $poMap[$coreOptionId] ?? 0;

                // Compute absolute price from base + modifier
                $basePrice    = (float) ($raw['price'] ?? 0);
                $ovPrice      = (float) ($ov['price'] ?? 0);
                $ovPrefix     = $ov['price_prefix'] ?? '+';
                $absolutePrice = $basePrice + ($ovPrefix === '+' ? $ovPrice : -$ovPrice);

                DB::table($pfx . 'product_option_value')->insert([
                    'product_option_id' => $corePoId,
                    'product_id'        => $coreProductId,
                    'option_id'         => $coreOptionId,
                    'option_value_id'   => $coreValueId,
                    'sku'               => $ov['sku'] ?? '',
                    'quantity'          => (int) ($ov['quantity'] ?? 0),
                    'subtract'          => (int) ($ov['subtract'] ?? 1),
                    'price'             => $ov['price'] ?? 0,
                    'price_prefix'      => $ov['price_prefix'] ?? '+',
                    'absolute_price'    => $absolutePrice,
                    'weight'            => $ov['weight'] ?? 0,
                    'weight_prefix'     => $ov['weight_prefix'] ?? '+',
                    'points'            => (int) ($ov['points'] ?? 0),
                    'points_prefix'     => $ov['points_prefix'] ?? '+',
                ]);
            }
        });

        // Sync product_option_combinations from freshly-inserted POV rows
        $this->syncCombinationsFromPov($coreProductId);

        $this->syncSubTable('product_special', $coreProductId, function () use ($pfx, $coreProductId, $raw) {
            if (!isset($raw['specials']) || !is_array($raw['specials'])) return;

            DB::table($pfx . 'product_special')
                ->where('product_id', $coreProductId)
                ->delete();

            foreach ($raw['specials'] as $sp) {
                DB::table($pfx . 'product_special')->insert([
                    'product_id'        => $coreProductId,
                    'customer_group_id' => (int) ($sp['customer_group_id'] ?? 0),
                    'priority'          => (int) ($sp['priority'] ?? 0),
                    'price'             => (float) ($sp['price'] ?? 0),
                    'date_start'        => $sp['date_start'] ?? '0000-00-00',
                    'date_end'          => $sp['date_end'] ?? '0000-00-00',
                ]);
            }
        });

        $this->syncSubTable('product_image', $coreProductId, function () use ($pfx, $coreProductId, $raw) {
            if (!isset($raw['images']) || !is_array($raw['images'])) return;

            DB::table($pfx . 'product_image')
                ->where('product_id', $coreProductId)
                ->delete();

            foreach ($raw['images'] as $img) {
                DB::table($pfx . 'product_image')->insert([
                    'product_id' => $coreProductId,
                    'image'      => $img['image'] ?? '',
                    'sort_order' => (int) ($img['sort_order'] ?? 0),
                ]);
            }
        });

        return $created;
    }

    /**
     * Push only product quantities from the ERP to OpenCart.
     * Sends quantity + option_value quantities via bulk_update without touching other fields.
     */
    public function pushQuantities(): OpenCartSyncLog
    {
        $log = OpenCartSyncLog::create([
            'opencart_setting_id' => $this->setting->id,
            'entity_type' => 'product_qty',
            'direction'   => 'push',
            'status'      => 'started',
            'started_at'  => now(),
        ]);

        try {
            $pfx = (string) config('catalog.prefix');
            $updated = 0;
            $skipped = 0;
            $failed = 0;
            $errors = [];

            // Get all product links for this store
            $links = OpenCartProductLink::where('opencart_setting_id', $this->setting->id)
                ->get();

            if ($links->isEmpty()) {
                $log->update([
                    'status'            => 'completed',
                    'records_processed' => 0,
                    'completed_at'      => now(),
                ]);
                return $log;
            }

            // Batch-fetch local product quantities (only enabled products)
            $localProductIds = $links->pluck('product_id')->unique()->toArray();
            $localQty = DB::table($pfx . 'product')
                ->whereIn('product_id', $localProductIds)
                ->where('status', 1)
                ->pluck('quantity', 'product_id')
                ->toArray();

            // Batch-fetch option value quantities with names for matching
            $langId = (int) config('catalog.default_language_id');
            $optionValues = DB::table($pfx . 'product_option_value as pov')
                ->leftJoin($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                    $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                        ->where('ovd.language_id', '=', $langId);
                })
                ->whereIn('pov.product_id', $localProductIds)
                ->get(['pov.product_id', 'pov.product_option_value_id', 'pov.sku', 'pov.quantity', 'ovd.name as option_value_name'])
                ->groupBy('product_id');

            // Build bulk update payloads in chunks of 50
            foreach ($links->chunk(50) as $chunk) {
                $products = [];

                foreach ($chunk as $link) {
                    $pid = (int) $link->product_id;
                    if (!isset($localQty[$pid])) {
                        $skipped++;
                        continue;
                    }

                    $payload = [
                        'product_id' => (int) $link->oc_product_id,
                        'quantity'   => (int) $localQty[$pid],
                    ];

                    // Include option value quantities (matched by name or SKU in OC)
                    $ovRows = $optionValues->get($pid);
                    if ($ovRows && $ovRows->count() > 0) {
                        $ovPayload = [];
                        foreach ($ovRows as $ov) {
                            $ovName = trim((string) ($ov->option_value_name ?? ''));
                            $ovSku = trim((string) $ov->sku);
                            if ($ovName === '' && $ovSku === '') continue;

                            $entry = ['quantity' => (int) $ov->quantity];
                            if ($ovName !== '') $entry['name'] = $ovName;
                            if ($ovSku !== '') $entry['sku'] = $ovSku;
                            $ovPayload[] = $entry;
                        }
                        if (!empty($ovPayload)) {
                            $payload['option_values'] = $ovPayload;
                        }
                    }

                    $products[] = $payload;
                }

                if (empty($products)) continue;

                try {
                    $resp = $this->client->bulkUpdateProducts($products);

                    if (empty($resp['ok'])) {
                        $failed += count($products);
                        if (count($errors) < 50) {
                            $errors[] = ['error' => 'Bulk update failed: ' . json_encode($resp['body'] ?? '')];
                        }
                    } else {
                        $data = $resp['body']['data'] ?? [];
                        foreach ($data as $r) {
                            if (!empty($r['success'])) {
                                $updated++;
                            } else {
                                $failed++;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $failed += count($products);
                    if (count($errors) < 50) {
                        $errors[] = ['error' => $e->getMessage()];
                    }
                }
            }

            $log->update([
                'status'            => 'completed',
                'records_processed' => $updated + $skipped + $failed,
                'records_updated'   => $updated,
                'records_failed'    => $failed,
                'details'           => $errors ?: null,
                'completed_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
            Log::error('OpenCart quantity push failed', ['error' => $e->getMessage()]);
        }

        return $log;
    }

    /**
     * Pull only product quantities from OpenCart into the ERP.
     * Updates product.quantity and product_option_value.quantity without touching other fields.
     */
    public function pullQuantities(): OpenCartSyncLog
    {
        $log = OpenCartSyncLog::create([
            'opencart_setting_id' => $this->setting->id,
            'entity_type' => 'product_qty',
            'direction'   => 'pull',
            'status'      => 'started',
            'started_at'  => now(),
        ]);

        try {
            $pfx = (string) config('catalog.prefix');
            $page = 1;
            $limit = 100;
            $updated = 0;
            $skipped = 0;
            $failed = 0;
            $errors = [];

            // Build OC product_id → local product_id map from product links
            $linkMap = OpenCartProductLink::where('opencart_setting_id', $this->setting->id)
                ->pluck('product_id', 'oc_product_id')
                ->toArray();

            do {
                $result = $this->client->getProducts($page, $limit);

                if (!$result['ok']) {
                    throw new \RuntimeException('API error: ' . json_encode($result['body']));
                }

                $products = $result['body']['data'] ?? [];
                $pagination = $result['body']['pagination'] ?? [];

                foreach ($products as $raw) {
                    try {
                        $ocProductId = (int) ($raw['product_id'] ?? 0);
                        $coreProductId = $linkMap[$ocProductId] ?? null;

                        // Fallback: match by SKU if no link exists
                        if (!$coreProductId) {
                            $sku = trim($raw['sku'] ?? '');
                            if ($sku !== '') {
                                $found = DB::table($pfx . 'product')->where('sku', $sku)->value('product_id');
                                if ($found) $coreProductId = (int) $found;
                            }
                        }

                        if (!$coreProductId) {
                            $skipped++;
                            continue;
                        }

                        // Update main product quantity
                        DB::table($pfx . 'product')
                            ->where('product_id', $coreProductId)
                            ->update(['quantity' => (int) ($raw['quantity'] ?? 0)]);

                        // Update option value quantities (match by SKU)
                        if (isset($raw['option_values']) && is_array($raw['option_values'])) {
                            foreach ($raw['option_values'] as $ov) {
                                $ovSku = trim($ov['sku'] ?? '');
                                if ($ovSku === '') continue;

                                DB::table($pfx . 'product_option_value')
                                    ->where('product_id', $coreProductId)
                                    ->where('sku', $ovSku)
                                    ->update(['quantity' => (int) ($ov['quantity'] ?? 0)]);
                            }
                        }

                        $updated++;
                    } catch (\Throwable $e) {
                        $failed++;
                        if (count($errors) < 50) {
                            $errors[] = [
                                'product_id' => $raw['product_id'] ?? '?',
                                'error'      => $e->getMessage(),
                            ];
                        }
                    }
                }

                $page++;
                $hasMore = $page <= ($pagination['total_pages'] ?? 0);
            } while ($hasMore);

            $log->update([
                'status'            => 'completed',
                'records_processed' => $updated + $skipped + $failed,
                'records_updated'   => $updated,
                'records_failed'    => $failed,
                'details'           => $errors ?: null,
                'completed_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
            Log::error('OpenCart quantity pull failed', ['error' => $e->getMessage()]);
        }

        return $log;
    }

    private function syncSubTable(string $table, int $productId, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning("OpenCart product sync: {$table} failed for product {$productId}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * After pulling POV rows from OpenCart, create/update combination rows.
     * For 1-option products: 1 combo per POV. For 2-option: Cartesian product.
     */
    private function syncCombinationsFromPov(int $productId): void
    {
        try {
            $pfx = config('catalog.prefix');

            // Delete existing combinations for this product
            DB::table('product_option_combination_values')
                ->whereIn('combination_id', function ($q) use ($productId) {
                    $q->select('id')->from('product_option_combinations')->where('product_id', $productId);
                })
                ->delete();
            DB::table('product_option_combinations')->where('product_id', $productId)->delete();

            // Get all POV rows grouped by product_option_id
            $povRows = DB::table($pfx . 'product_option_value')
                ->where('product_id', $productId)
                ->orderBy('product_option_id')
                ->get();

            if ($povRows->isEmpty()) return;

            $grouped = $povRows->groupBy('product_option_id');
            $groupCount = $grouped->count();
            $now = now();

            if ($groupCount === 1) {
                // 1-option: 1 combo per POV
                $sortOrder = 0;
                foreach ($povRows as $pov) {
                    $comboId = DB::table('product_option_combinations')->insertGetId([
                        'product_id' => $productId,
                        'sku' => $pov->sku ?? '',
                        'quantity' => (int) $pov->quantity,
                        'absolute_price' => (float) ($pov->absolute_price ?? 0),
                        'absolute_cost' => (float) ($pov->absolute_cost ?? 0),
                        'subtract' => (int) ($pov->subtract ?? 1),
                        'sort_order' => $sortOrder++,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    DB::table('product_option_combination_values')->insert([
                        'combination_id' => $comboId,
                        'product_option_value_id' => (int) $pov->product_option_value_id,
                    ]);
                }
            } elseif ($groupCount >= 2) {
                // 2-option: Cartesian product of first two groups
                $groups = $grouped->values();
                $group1 = $groups[0];
                $group2 = $groups[1];
                $basePrice = (float) DB::table($pfx . 'product')->where('product_id', $productId)->value('price');
                $sortOrder = 0;

                foreach ($group1 as $pov1) {
                    foreach ($group2 as $pov2) {
                        // Combination price = base + delta1 + delta2
                        $delta1 = ($pov1->price_prefix ?? '+') === '+' ? (float) $pov1->price : -(float) $pov1->price;
                        $delta2 = ($pov2->price_prefix ?? '+') === '+' ? (float) $pov2->price : -(float) $pov2->price;
                        $comboPrice = $basePrice + $delta1 + $delta2;

                        $comboSku = ($pov1->sku ?? '') . ($pov2->sku ? '-' . $pov2->sku : '');

                        $comboId = DB::table('product_option_combinations')->insertGetId([
                            'product_id' => $productId,
                            'sku' => $comboSku,
                            'quantity' => (int) $pov1->quantity,
                            'absolute_price' => $comboPrice,
                            'absolute_cost' => 0,
                            'subtract' => 1,
                            'sort_order' => $sortOrder++,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        DB::table('product_option_combination_values')->insert([
                            ['combination_id' => $comboId, 'product_option_value_id' => (int) $pov1->product_option_value_id],
                            ['combination_id' => $comboId, 'product_option_value_id' => (int) $pov2->product_option_value_id],
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("OpenCart product sync: combinations failed for product {$productId}", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
