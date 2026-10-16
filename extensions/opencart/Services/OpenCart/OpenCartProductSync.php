<?php

namespace Extensions\OpenCart\Services\OpenCart;

use Extensions\OpenCart\Models\OpenCartListingLink;
use Extensions\OpenCart\Models\OpenCartStore;
use Extensions\OpenCart\Models\OpenCartSyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OpenCartProductSync
{
    private OpenCartClient $client;
    private OpenCartStore $store;

    private array $categoryMap = [];

    private array $brandMap = [];

    private array $variationTypeMap = [];

    private array $variationValueMap = [];

    public function __construct(OpenCartClient $client, OpenCartStore $store)
    {
        $this->client = $client;
        $this->store = $store;
    }

    public function setCategoryMap(array $map): void
    {
        $this->categoryMap = $map;
    }

    public function setBrandMap(array $map): void
    {
        $this->brandMap = $map;
    }

    public function setVariationTypeMap(array $map): void
    {
        $this->variationTypeMap = $map;
    }

    public function setVariationValueMap(array $map): void
    {
        $this->variationValueMap = $map;
    }

    private function autoPopulateMaps(): void
    {
        $langId = (int) config('catalog.default_language_id');

        if (empty($this->categoryMap)) {
            $result = $this->client->getCategories();
            if ($result['ok']) {
                $openCartCategories = $result['body']['data'] ?? [];
                foreach ($openCartCategories as $cat) {
                    $openCartId = (int) ($cat['category_id'] ?? 0);
                    $name = trim($cat['name'] ?? '');
                    if ($openCartId <= 0 || $name === '') continue;

                    $local = DB::table('category_descriptions')
                        ->where('language_id', $langId)
                        ->where('name', $name)
                        ->value('category_id');

                    if ($local) {
                        $this->categoryMap[$openCartId] = (int) $local;
                    }
                }
            }
        }

        if (empty($this->brandMap)) {
            $result = $this->client->getBrands();
            if ($result['ok']) {
                $openCartMfgs = $result['body']['data'] ?? [];
                foreach ($openCartMfgs as $mfg) {
                    $openCartId = (int) ($mfg['manufacturer_id'] ?? 0);
                    $name = trim($mfg['name'] ?? '');
                    if ($openCartId <= 0 || $name === '') continue;

                    $local = DB::table('brands')
                        ->where('name', $name)
                        ->value('brand_id');

                    if ($local) {
                        $this->brandMap[$openCartId] = (int) $local;
                    }
                }
            }
        }

        if (empty($this->variationTypeMap)) {
            $result = $this->client->getOptions();
            if ($result['ok']) {
                $openCartOptions = $result['body']['data'] ?? [];
                foreach ($openCartOptions as $opt) {
                    $openCartOptionId = (int) ($opt['option_id'] ?? 0);
                    $name = trim($opt['name'] ?? '');
                    if ($openCartOptionId <= 0 || $name === '') continue;

                    $local = DB::table('variation_types AS o')
                        ->join('variation_type_descriptions AS od', function ($join) use ($langId) {
                            $join->on('o.variation_type_id', '=', 'od.variation_type_id')
                                ->where('od.language_id', '=', $langId);
                        })
                        ->where('od.name', $name)
                        ->value('o.variation_type_id');

                    if ($local) {
                        $this->variationTypeMap[$openCartOptionId] = (int) $local;

                        $values = $opt['values'] ?? [];
                        foreach ($values as $val) {
                            $openCartValueId = (int) ($val['option_value_id'] ?? 0);
                            $valName = trim($val['name'] ?? '');
                            if ($openCartValueId <= 0 || $valName === '') continue;

                            $localVal = DB::table('variation_values AS ov')
                                ->join('variation_value_descriptions AS ovd', function ($join) use ($langId) {
                                    $join->on('ov.variation_value_id', '=', 'ovd.variation_value_id')
                                        ->where('ovd.language_id', '=', $langId);
                                })
                                ->where('ov.variation_type_id', (int) $local)
                                ->where('ovd.name', $valName)
                                ->value('ov.variation_value_id');

                            if ($localVal) {
                                $this->variationValueMap[$openCartValueId] = (int) $localVal;
                            }
                        }
                    }
                }
            }
        }

        Log::info('OpenCart product sync auto-populated maps', [
            'categories'    => count($this->categoryMap),
            'brands' => count($this->brandMap),
            'variation_types' => count($this->variationTypeMap),
            'variation_values' => count($this->variationValueMap),
        ]);
    }

    public function pull(?string $modifiedSince = null, bool $full = false, ?int $productId = null): OpenCartSyncLog
    {
        $this->autoPopulateMaps();
        $log = OpenCartSyncLog::create([
            'store_id' => $this->store->id,
            'entity_type' => 'product',
            'direction'   => 'pull',
            'status'      => 'started',
            'started_at'  => now(),
        ]);

        try {
            $since = ($full || $productId) ? null : ($modifiedSince ?? $this->store->last_product_sync_at?->toIso8601String());
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

            if (!$productId) {
                $this->store->update(['last_product_sync_at' => now()]);
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

    public function push(array $productIds): array
    {
        $langId = (int) config('catalog.default_language_id');
        $updated = 0;
        $created = 0;
        $failed = 0;
        $errors = [];

        $links = OpenCartListingLink::where('store_id', $this->store->id)
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

        $staleLinks = [];

        foreach (array_chunk($toUpdate, 50) as $chunk) {
            $products = [];
            $chunkMap = [];

            foreach ($chunk as $pid) {
                $product = DB::table('products')
                    ->where('product_id', (int) $pid)
                    ->first();

                if (!$product) continue;

                if ((int) $product->status === 0) {
                    $failed++;
                    $errors[] = ['product_id' => $pid, 'error' => 'Product is disabled (status=0), skipped.'];
                    continue;
                }

                $desc = DB::table('product_descriptions')
                    ->where('product_id', (int) $pid)
                    ->where('language_id', $langId)
                    ->first();

                $link = $links->get($pid);
                $openCartPid = (int) $link->opencart_product_id;

                $mfgName = '';
                if ((int) $product->brand_id > 0) {
                    $mfg = DB::table('brands')
                        ->where('brand_id', (int) $product->brand_id)
                        ->first();
                    if ($mfg) $mfgName = $mfg->name;
                }

                $productData = [
                    'product_id'        => $openCartPid,
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
                    'model'             => $product->sku ?? '',
                    'weight'            => (string) $product->weight,
                    'image'             => $product->image ?? '',
                    'manufacturer_name' => $mfgName,
                ];

                if (!empty($product->image)) {
                    $encoded = $this->encodeImage($product->image);
                    if ($encoded) {
                        $productData['image_data'] = $encoded;
                        $productData['image_filename'] = basename($product->image);
                    }
                }

                $addlImages = DB::table('product_images')
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

                $variations = $this->buildVariationsPayload((int) $pid, $langId);
                if (!empty($variations)) {
                    $productData['options'] = $variations;
                }

                $products[] = $productData;
                $chunkMap[$openCartPid] = $pid;
            }

            if (!empty($products)) {
                $resp = $this->client->bulkUpdateProducts($products);

                if (empty($resp['ok'])) {
                    Log::warning('OpenCart bulk update failed, queueing for re-create', [
                        'store' => $this->store->id,
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
                                $openCartPid = (int) ($r['product_id'] ?? 0);
                                if ($openCartPid > 0 && isset($chunkMap[$openCartPid])) {
                                    $staleLinks[] = $chunkMap[$openCartPid];
                                } else {
                                    $failed++;
                                }
                            }
                        }
                    } else {
                        $staleLinks = array_merge($staleLinks, array_values($chunkMap));
                    }
                }
            }
        }

        if (!empty($staleLinks)) {
            OpenCartListingLink::where('store_id', $this->store->id)
                ->whereIn('product_id', $staleLinks)
                ->delete();
            $toCreate = array_merge($toCreate, $staleLinks);
        }

        foreach ($toCreate as $pid) {
            $createStatus = DB::table('products')->where('product_id', (int) $pid)->value('status');
            if ((int) $createStatus === 0) {
                $failed++;
                $errors[] = ['product_id' => $pid, 'error' => 'Product is disabled (status=0), skipped create.'];
                continue;
            }

            try {
                $payload = $this->buildCreatePayload((int) $pid, $langId);
                if ($payload === null) {
                    $failed++;
                    $errors[] = ['product_id' => $pid, 'error' => 'Product not found'];
                    continue;
                }

                $resp = $this->client->createProduct($payload);

                if (!empty($resp['ok']) && !empty($resp['body']['success'])) {
                    $openCartProductId = (int) ($resp['body']['data']['product_id'] ?? 0);

                    if ($openCartProductId > 0) {
                        OpenCartListingLink::updateOrCreate(
                            [
                                'store_id' => $this->store->id,
                                'opencart_product_id'       => $openCartProductId,
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

    private function buildCreatePayload(int $productId, int $langId): ?array
    {
        $product = DB::table('products')
            ->where('product_id', $productId)
            ->first();

        if (!$product) return null;

        $desc = DB::table('product_descriptions')
            ->where('product_id', $productId)
            ->where('language_id', $langId)
            ->first();

        $payload = [
            'sku'              => $product->sku ?? '',
            'model'            => $product->sku ?? '',
            'price'            => (float) $product->price,
            'cost'             => (float) ($product->cost ?? 0),
            'cost_amount'      => (float) ($product->cost_amount ?? 0),
            'cost_percentage'  => (float) ($product->cost_percentage ?? 0),
            'cost_additional'  => (float) ($product->cost_additional ?? 0),
            'quantity'         => (int) $product->quantity,
            'status'           => (int) $product->status,
            'weight'           => (float) $product->weight,
            'length'           => (float) $product->length,
            'width'            => (float) $product->width,
            'height'           => (float) $product->height,
            'image'            => $product->image ?? '',
            'subtract'         => (int) $product->subtract_stock,
            'name'             => $desc->name ?? '',
            'description'      => $desc->description ?? '',
            'meta_title'       => $desc->meta_title ?? '',
            'meta_description' => $desc->meta_description ?? '',
            'tag'              => $desc->tag ?? '',
        ];

        if ((int) $product->brand_id > 0) {
            $mfg = DB::table('brands')
                ->where('brand_id', (int) $product->brand_id)
                ->first();
            if ($mfg) {
                $payload['manufacturer_name'] = $mfg->name;
            }
        }

        $catIds = DB::table('product_categories')
            ->where('product_id', $productId)
            ->pluck('category_id');

        $categoryNames = [];
        foreach ($catIds as $catId) {
            $path = $this->buildCategoryPath((int) $catId, $langId);
            if ($path !== '') {
                $categoryNames[] = $path;
            }
        }
        if (!empty($categoryNames)) {
            $payload['category_names'] = $categoryNames;
        }

        if (!empty($product->image)) {
            $encoded = $this->encodeImage($product->image);
            if ($encoded) {
                $payload['image_data'] = $encoded;
                $payload['image_filename'] = basename($product->image);
            }
        }

        $images = DB::table('product_images')
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

        $specials = DB::table('product_specials')
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

        $variationsPayload = $this->buildVariationsPayload($productId, $langId);
        if (!empty($variationsPayload)) {
            $payload['options'] = $variationsPayload;
        }

        return $payload;
    }

    private function buildVariationsPayload(int $productId, int $langId): array
    {
        $basePrice = (float) DB::table('products')->where('product_id', $productId)->value('price');

        $productVariationTypes = DB::table('product_variation_types AS po')
            ->join('variation_type_descriptions AS od', function ($join) use ($langId) {
                $join->on('po.variation_type_id', '=', 'od.variation_type_id')
                    ->where('od.language_id', '=', $langId);
            })
            ->join('variation_types AS o', 'po.variation_type_id', '=', 'o.variation_type_id')
            ->where('po.product_id', $productId)
            ->select('po.product_variation_type_id', 'po.variation_type_id', 'po.required', 'od.name AS variation_type_name', 'o.type')
            ->get();

        if ($productVariationTypes->isEmpty()) {
            return [];
        }

        $variationsPayload = [];
        foreach ($productVariationTypes as $po) {
            $values = DB::table('product_variation_values AS pov')
                ->join('variation_value_descriptions AS ovd', function ($join) use ($langId) {
                    $join->on('pov.variation_value_id', '=', 'ovd.variation_value_id')
                        ->where('ovd.language_id', '=', $langId);
                })
                ->where('pov.product_variation_type_id', (int) $po->product_variation_type_id)
                ->select(
                    'ovd.name', 'pov.sku', 'pov.quantity', 'pov.subtract_stock',
                    'pov.absolute_price', 'pov.absolute_cost',
                    'pov.weight', 'pov.weight_prefix',
                    'pov.points', 'pov.points_prefix'
                )
                ->get();

            $variationsPayload[] = [
                'option_name' => $po->variation_type_name,
                'type'        => $po->type,
                'required'    => (int) $po->required,
                'values'      => $values->map(function ($v) use ($basePrice) {
                    $absPrice = (float) ($v->absolute_price ?? $basePrice);
                    $priceDelta = $absPrice - $basePrice;

                    $absCost = (float) ($v->absolute_cost ?? 0);

                    return [
                        'name'            => $v->name,
                        'sku'             => $v->sku ?? '',
                        'quantity'        => (int) $v->quantity,
                        'subtract'        => (int) $v->subtract_stock,
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

        return $variationsPayload;
    }

    private function encodeImage(string $path): ?string
    {
        if ($path === '') return null;

        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        if (!$disk->exists($path)) return null;

        $contents = $disk->get($path);
        if ($contents === false || $contents === '') return null;

        return base64_encode($contents);
    }

    private function buildCategoryPath(int $categoryId, int $langId): string
    {
        $rows = DB::table('category_paths AS cp')
            ->join('category_descriptions AS cd', function ($join) use ($langId) {
                $join->on('cp.path_id', '=', 'cd.category_id')
                    ->where('cd.language_id', '=', $langId);
            })
            ->where('cp.category_id', $categoryId)
            ->orderBy('cp.level')
            ->pluck('cd.name');

        return $rows->implode(' > ');
    }

    private function upsertProduct(array $raw): bool
    {
        $langId = (int) config('catalog.default_language_id');
        $openCartProductId = (int) ($raw['product_id'] ?? 0);
        $sku = trim($raw['sku'] ?? '');
        $created = false;
        $coreProductId = null;
        $linkedToOtherStore = false;

        $openCartMfgId = (int) ($raw['manufacturer_id'] ?? 0);
        $coreMfgId = ($openCartMfgId > 0 && isset($this->brandMap[$openCartMfgId]))
            ? $this->brandMap[$openCartMfgId]
            : 0;

        DB::transaction(function () use ($raw, $langId, $sku, $openCartProductId, $coreMfgId, &$created, &$coreProductId, &$linkedToOtherStore) {
            $data = [
                'sku'             => $sku,
                'upc'             => $raw['upc'] ?? '',
                'ean'             => $raw['ean'] ?? '',
                'jan'             => $raw['jan'] ?? '',
                'isbn'            => $raw['isbn'] ?? '',
                'mpn'             => $raw['mpn'] ?? '',
                'quantity'        => (int) ($raw['quantity'] ?? 0),
                'image'           => $raw['image'] ?? '',
                'brand_id' => $coreMfgId,
                'price'           => $raw['price'] ?? 0,
                'weight'          => $raw['weight'] ?? 0,
                'length'          => $raw['length'] ?? 0,
                'width'           => $raw['width'] ?? 0,
                'height'          => $raw['height'] ?? 0,
                'status'          => (int) ($raw['status'] ?? 0),
                'subtract_stock'  => (int) ($raw['subtract'] ?? 1),
                'date_modified'   => (isset($raw['date_modified']) && $raw['date_modified'] !== '0000-00-00 00:00:00') ? $raw['date_modified'] : now()->toDateTimeString(),
            ];

            $existing = null;
            if ($sku !== '') {
                $existing = DB::table('products')
                    ->where('sku', $sku)
                    ->select('product_id')
                    ->first();
            }
            if (!$existing) {
                $link = OpenCartListingLink::where('store_id', $this->store->id)
                    ->where('opencart_product_id', $openCartProductId)
                    ->first();
                if ($link) {
                    $existing = DB::table('products')
                        ->where('product_id', $link->product_id)
                        ->select('product_id')
                        ->first();
                }
            }

            if ($existing) {
                $coreProductId = (int) $existing->product_id;

                $existingLink = OpenCartListingLink::where('product_id', $coreProductId)
                    ->where('store_id', '!=', $this->store->id)
                    ->exists();

                if ($existingLink) {
                    $linkedToOtherStore = true;
                } else {
                    DB::table('products')
                        ->where('product_id', $coreProductId)
                        ->update($data);
                }
            } else {
                if ($full = \App\Plans\Quota::refusal('products')) {
                    throw new \App\Plans\PlanLimitReached('Not imported. ' . $full);
                }
                $data['date_added'] = (isset($raw['date_added']) && $raw['date_added'] !== '0000-00-00 00:00:00') ? $raw['date_added'] : now()->toDateTimeString();
                $coreProductId = DB::table('products')->insertGetId($data, 'product_id');
                $created = true;
            }
        });

        OpenCartListingLink::updateOrCreate(
            [
                'store_id' => $this->store->id,
                'opencart_product_id'       => $openCartProductId,
            ],
            [
                'product_id' => $coreProductId,
                'sku'        => $sku,
            ]
        );

        if ($linkedToOtherStore) {
            return false;
        }

        $this->syncSubTable('product_description', $coreProductId, function () use ( $langId, $coreProductId, $raw) {
            DB::table('product_descriptions')->updateOrInsert(
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

        $this->syncSubTable('product_to_category', $coreProductId, function () use ( $coreProductId, $raw) {
            if (!isset($raw['categories']) || !is_array($raw['categories'])) return;

            DB::table('product_categories')
                ->where('product_id', $coreProductId)
                ->delete();

            foreach ($raw['categories'] as $openCartCatId) {
                $coreCatId = $this->categoryMap[(int) $openCartCatId] ?? null;
                if ($coreCatId === null) continue;

                DB::table('product_categories')->insert([
                    'product_id'  => $coreProductId,
                    'category_id' => $coreCatId,
                ]);
            }
        });

        $this->syncSubTable('product_variation_types', $coreProductId, function () use ( $coreProductId, $raw) {
            if (!isset($raw['options']) || !is_array($raw['options'])) return;

            DB::table('product_variation_types')
                ->where('product_id', $coreProductId)
                ->delete();

            foreach ($raw['options'] as $po) {
                $openCartOptionId = (int) ($po['option_id'] ?? 0);
                $coreVariationTypeId = $this->variationTypeMap[$openCartOptionId] ?? null;
                if ($coreVariationTypeId === null) continue;

                DB::table('product_variation_types')->insert([
                    'product_id' => $coreProductId,
                    'variation_type_id'  => $coreVariationTypeId,
                    'value'      => $po['value'] ?? '',
                    'required'   => (int) ($po['required'] ?? 0),
                ]);
            }
        });

        $this->syncSubTable('product_variation_values', $coreProductId, function () use ( $coreProductId, $raw) {
            if (!isset($raw['option_values']) || !is_array($raw['option_values'])) return;

            DB::table('product_variation_values')
                ->where('product_id', $coreProductId)
                ->delete();

            $poMap = [];
            $corePos = DB::table('product_variation_types')
                ->where('product_id', $coreProductId)
                ->get();

            foreach ($corePos as $cpo) {
                $poMap[(int) $cpo->variation_type_id] = (int) $cpo->product_variation_type_id;
            }

            foreach ($raw['option_values'] as $ov) {
                $openCartOptionId = (int) ($ov['option_id'] ?? 0);
                $openCartValueId = (int) ($ov['option_value_id'] ?? 0);

                $coreVariationTypeId = $this->variationTypeMap[$openCartOptionId] ?? null;
                $coreValueId = $this->variationValueMap[$openCartValueId] ?? null;

                if ($coreVariationTypeId === null || $coreValueId === null) continue;

                $corePoId = $poMap[$coreVariationTypeId] ?? 0;

                $basePrice    = (float) ($raw['price'] ?? 0);
                $ovPrice      = (float) ($ov['price'] ?? 0);
                $ovPrefix     = $ov['price_prefix'] ?? '+';
                $absolutePrice = $basePrice + ($ovPrefix === '+' ? $ovPrice : -$ovPrice);

                DB::table('product_variation_values')->insert([
                    'product_variation_type_id' => $corePoId,
                    'product_id'        => $coreProductId,
                    'variation_type_id'         => $coreVariationTypeId,
                    'variation_value_id'   => $coreValueId,
                    'sku'               => $ov['sku'] ?? '',
                    'quantity'          => (int) ($ov['quantity'] ?? 0),
                    'subtract_stock'    => (int) ($ov['subtract'] ?? 1),
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

        $this->syncCombinationsFromPov($coreProductId);

        $this->syncSubTable('product_special', $coreProductId, function () use ( $coreProductId, $raw) {
            if (!isset($raw['specials']) || !is_array($raw['specials'])) return;

            DB::table('product_specials')
                ->where('product_id', $coreProductId)
                ->delete();

            foreach ($raw['specials'] as $sp) {
                DB::table('product_specials')->insert([
                    'product_id'        => $coreProductId,
                    'customer_group_id' => (int) ($sp['customer_group_id'] ?? 0),
                    'priority'          => (int) ($sp['priority'] ?? 0),
                    'price'             => (float) ($sp['price'] ?? 0),
                    'date_start'        => $sp['date_start'] ?? '0000-00-00',
                    'date_end'          => $sp['date_end'] ?? '0000-00-00',
                ]);
            }
        });

        $this->syncSubTable('product_image', $coreProductId, function () use ( $coreProductId, $raw) {
            if (!isset($raw['images']) || !is_array($raw['images'])) return;

            DB::table('product_images')
                ->where('product_id', $coreProductId)
                ->delete();

            foreach ($raw['images'] as $img) {
                DB::table('product_images')->insert([
                    'product_id' => $coreProductId,
                    'image'      => $img['image'] ?? '',
                    'sort_order' => (int) ($img['sort_order'] ?? 0),
                ]);
            }
        });

        return $created;
    }

    public function pushQuantities(array $onlyProductIds = []): OpenCartSyncLog
    {
        $log = OpenCartSyncLog::create([
            'store_id' => $this->store->id,
            'entity_type' => 'product_qty',
            'direction'   => 'push',
            'status'      => 'started',
            'started_at'  => now(),
        ]);

        try {
            $updated = 0;
            $skipped = 0;
            $failed = 0;
            $errors = [];

            $links = OpenCartListingLink::where('store_id', $this->store->id)
                ->when($onlyProductIds !== [], fn ($q) => $q->whereIn('product_id', $onlyProductIds))
                ->get();

            if ($links->isEmpty()) {
                $log->update([
                    'status'            => 'completed',
                    'records_processed' => 0,
                    'completed_at'      => now(),
                ]);
                return $log;
            }

            $localProductIds = $links->pluck('product_id')->unique()->toArray();
            $localQty = DB::table('products')
                ->whereIn('product_id', $localProductIds)
                ->where('status', 1)
                ->pluck('quantity', 'product_id')
                ->toArray();

            $langId = (int) config('catalog.default_language_id');
            $variationValues = DB::table('product_variation_values as pov')
                ->leftJoin('variation_value_descriptions as ovd', function ($j) use ($langId) {
                    $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')
                        ->where('ovd.language_id', '=', $langId);
                })
                ->whereIn('pov.product_id', $localProductIds)
                ->get(['pov.product_id', 'pov.product_variation_value_id', 'pov.sku', 'pov.quantity', 'ovd.name as variation_value_name'])
                ->groupBy('product_id');

            foreach ($links->chunk(50) as $chunk) {
                $products = [];

                foreach ($chunk as $link) {
                    $pid = (int) $link->product_id;
                    if (!isset($localQty[$pid])) {
                        $skipped++;
                        continue;
                    }

                    $payload = [
                        'product_id' => (int) $link->opencart_product_id,
                        'quantity'   => (int) $localQty[$pid],
                    ];

                    $ovRows = $variationValues->get($pid);
                    if ($ovRows && $ovRows->count() > 0) {
                        $ovPayload = [];
                        foreach ($ovRows as $ov) {
                            $ovName = trim((string) ($ov->variation_value_name ?? ''));
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

    public function pushPrices(array $onlyProductIds = []): OpenCartSyncLog
    {
        $log = OpenCartSyncLog::create([
            'store_id' => $this->store->id,
            'entity_type' => 'product_price',
            'direction'   => 'push',
            'status'      => 'started',
            'started_at'  => now(),
        ]);

        try {
            $updated = 0;
            $skipped = 0;
            $failed = 0;
            $errors = [];

            $links = OpenCartListingLink::where('store_id', $this->store->id)
                ->when($onlyProductIds !== [], fn ($q) => $q->whereIn('product_id', $onlyProductIds))
                ->get();
            if ($links->isEmpty()) {
                $log->update(['status' => 'completed', 'records_processed' => 0, 'completed_at' => now()]);

                return $log;
            }

            $localProductIds = $links->pluck('product_id')->unique()->toArray();
            $localPrice = DB::table('products')
                ->whereIn('product_id', $localProductIds)
                ->where('status', 1)
                ->pluck('price', 'product_id')
                ->toArray();

            $langId = (int) config('catalog.default_language_id');
            $variationValues = DB::table('product_variation_values as pov')
                ->leftJoin('variation_value_descriptions as ovd', function ($j) use ($langId) {
                    $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')
                        ->where('ovd.language_id', '=', $langId);
                })
                ->whereIn('pov.product_id', $localProductIds)
                ->get(['pov.product_id', 'pov.sku', 'pov.price', 'pov.price_prefix', 'pov.absolute_price', 'ovd.name as variation_value_name'])
                ->groupBy('product_id');

            foreach ($links->chunk(50) as $chunk) {
                $products = [];
                foreach ($chunk as $link) {
                    $pid = (int) $link->product_id;
                    if (!isset($localPrice[$pid])) {
                        $skipped++;
                        continue;
                    }
                    $basePrice = (float) $localPrice[$pid];
                    $payload = [
                        'product_id' => (int) $link->opencart_product_id,
                        'price'      => number_format($basePrice, 4, '.', ''),
                    ];

                    $ovRows = $variationValues->get($pid);
                    if ($ovRows && $ovRows->count() > 0) {
                        $ovPayload = [];
                        foreach ($ovRows as $ov) {
                            $ovName = trim((string) ($ov->variation_value_name ?? ''));
                            $ovSku = trim((string) $ov->sku);
                            if ($ovName === '' && $ovSku === '') continue;

                            if ((float) ($ov->absolute_price ?? 0) > 0) {
                                $delta = (float) $ov->absolute_price - $basePrice;
                                $entry = ['price' => number_format(abs($delta), 4, '.', ''), 'price_prefix' => $delta >= 0 ? '+' : '-'];
                            } else {
                                $entry = ['price' => number_format((float) ($ov->price ?? 0), 4, '.', ''), 'price_prefix' => ($ov->price_prefix ?? '+') === '-' ? '-' : '+'];
                            }
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
                        foreach (($resp['body']['data'] ?? []) as $r) {
                            !empty($r['success']) ? $updated++ : $failed++;
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
            $log->update(['status' => 'failed', 'error_message' => $e->getMessage(), 'completed_at' => now()]);
            Log::error('OpenCart price push failed', ['error' => $e->getMessage()]);
        }

        return $log;
    }

    public function pullQuantities(): OpenCartSyncLog
    {
        $log = OpenCartSyncLog::create([
            'store_id' => $this->store->id,
            'entity_type' => 'product_qty',
            'direction'   => 'pull',
            'status'      => 'started',
            'started_at'  => now(),
        ]);

        try {
            $page = 1;
            $limit = 100;
            $updated = 0;
            $skipped = 0;
            $failed = 0;
            $errors = [];

            $linkMap = OpenCartListingLink::where('store_id', $this->store->id)
                ->pluck('product_id', 'opencart_product_id')
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
                        $openCartProductId = (int) ($raw['product_id'] ?? 0);
                        $coreProductId = $linkMap[$openCartProductId] ?? null;

                        if (!$coreProductId) {
                            $sku = trim($raw['sku'] ?? '');
                            if ($sku !== '') {
                                $found = DB::table('products')->where('sku', $sku)->value('product_id');
                                if ($found) $coreProductId = (int) $found;
                            }
                        }

                        if (!$coreProductId) {
                            $skipped++;
                            continue;
                        }

                        DB::table('products')
                            ->where('product_id', $coreProductId)
                            ->update(['quantity' => (int) ($raw['quantity'] ?? 0)]);

                        if (isset($raw['option_values']) && is_array($raw['option_values'])) {
                            foreach ($raw['option_values'] as $ov) {
                                $ovSku = trim($ov['sku'] ?? '');
                                if ($ovSku === '') continue;

                                DB::table('product_variation_values')
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

    private function syncCombinationsFromPov(int $productId): void
    {
        try {

            DB::table('product_variation_parts')
                ->whereIn('product_variation_id', function ($q) use ($productId) {
                    $q->select('id')->from('product_variations')->where('product_id', $productId);
                })
                ->delete();
            DB::table('product_variations')->where('product_id', $productId)->delete();

            $povRows = DB::table('product_variation_values')
                ->where('product_id', $productId)
                ->orderBy('product_variation_type_id')
                ->get();

            if ($povRows->isEmpty()) return;

            $grouped = $povRows->groupBy('product_variation_type_id');
            $groupCount = $grouped->count();
            $now = now();

            if ($groupCount === 1) {
                $sortOrder = 0;
                foreach ($povRows as $pov) {
                    $comboId = DB::table('product_variations')->insertGetId([
                        'product_id' => $productId,
                        'sku' => $pov->sku ?? '',
                        'quantity' => (int) $pov->quantity,
                        'absolute_price' => (float) ($pov->absolute_price ?? 0),
                        'absolute_cost' => (float) ($pov->absolute_cost ?? 0),
                        'subtract_stock' => (int) ($pov->subtract_stock ?? 1),
                        'sort_order' => $sortOrder++,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    DB::table('product_variation_parts')->insert([
                        'product_variation_id' => $comboId,
                        'product_variation_value_id' => (int) $pov->product_variation_value_id,
                    ]);
                }
            } elseif ($groupCount >= 2) {
                $groups = $grouped->values();
                $group1 = $groups[0];
                $group2 = $groups[1];
                $basePrice = (float) DB::table('products')->where('product_id', $productId)->value('price');
                $sortOrder = 0;

                foreach ($group1 as $pov1) {
                    foreach ($group2 as $pov2) {
                        $delta1 = ($pov1->price_prefix ?? '+') === '+' ? (float) $pov1->price : -(float) $pov1->price;
                        $delta2 = ($pov2->price_prefix ?? '+') === '+' ? (float) $pov2->price : -(float) $pov2->price;
                        $comboPrice = $basePrice + $delta1 + $delta2;

                        $comboSku = ($pov1->sku ?? '') . ($pov2->sku ? '-' . $pov2->sku : '');

                        $comboId = DB::table('product_variations')->insertGetId([
                            'product_id' => $productId,
                            'sku' => $comboSku,
                            'quantity' => (int) $pov1->quantity,
                            'absolute_price' => $comboPrice,
                            'absolute_cost' => 0,
                            'subtract_stock' => 1,
                            'sort_order' => $sortOrder++,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        DB::table('product_variation_parts')->insert([
                            ['product_variation_id' => $comboId, 'product_variation_value_id' => (int) $pov1->product_variation_value_id],
                            ['product_variation_id' => $comboId, 'product_variation_value_id' => (int) $pov2->product_variation_value_id],
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
