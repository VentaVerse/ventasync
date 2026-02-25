<?php

namespace App\Http\Controllers;

use App\Models\ShopeeApiLog;
use App\Models\ShopeeCategory;
use App\Models\ShopeeItemCache;
use App\Models\ShopeeLogistic;
use App\Models\ShopeeProductLink;
use App\Models\ShopeeProfile;
use App\Models\ShopeeSetting;
use App\Models\ShopeeUnmatchedItem;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopeeProductController extends Controller
{
    public function index(Request $request)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->get('q', ''));
        $syncStatus = (string) $request->get('sync_status', 'all');
        $manufacturerFilter = (string) $request->get('manufacturer', 'all');
        $profileFilter = (string) $request->get('profile', 'all');
        $erpStatus = (string) $request->get('erp_status', 'all');

        // Build profile → product mapping
        $profileMapping = $this->getProfileProductMapping();

        $query = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                  ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->select(
                'p.product_id',
                'pd.name',
                'p.image',
                'p.model',
                'p.sku',
                'p.price',
                'p.quantity',
                'p.status',
                'm.name as manufacturer_name'
            );

        // Profile filter
        if ($profileFilter === 'none') {
            // Show products NOT matching any profile
            $allMatchedIds = $profileMapping['ids'];
            if ($allMatchedIds === null || empty($allMatchedIds)) {
                // No profiles or no matches — show all products
            } else {
                $query->whereNotIn('p.product_id', $allMatchedIds);
            }
        } elseif ($profileFilter !== 'all') {
            // Show products matching a specific profile
            $specificIds = $this->getProductIdsByProfile((int) $profileFilter);
            if (empty($specificIds)) {
                $query->whereRaw('1=0');
            } else {
                $query->whereIn('p.product_id', $specificIds);
            }
        } else {
            // Default: only show products matching at least one profile
            $allMatchedIds = $profileMapping['ids'];
            if ($allMatchedIds === null) {
                $query->whereRaw('1=0');
            } else {
                $query->whereIn('p.product_id', $allMatchedIds);
            }
        }

        // Manufacturer filter
        if ($manufacturerFilter !== 'all') {
            $query->where('p.manufacturer_id', (int) $manufacturerFilter);
        }

        // ERP Status filter
        if ($erpStatus === 'enabled') {
            $query->where('p.status', 1);
        } elseif ($erpStatus === 'disabled') {
            $query->where('p.status', 0);
        }

        // Text search
        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.model', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%');
            });
        }

        // Sync status filter (listed/not listed on Shopee)
        if ($syncStatus === 'listed') {
            $listedIds = ShopeeProductLink::query()->pluck('product_id')->unique()->toArray();
            if (empty($listedIds)) {
                $query->whereRaw('1=0');
            } else {
                $query->whereIn('p.product_id', $listedIds);
            }
        } elseif ($syncStatus === 'not_listed') {
            $listedIds = ShopeeProductLink::query()->pluck('product_id')->unique()->toArray();
            if (!empty($listedIds)) {
                $query->whereNotIn('p.product_id', $listedIds);
            }
        }

        $query->orderBy('p.product_id', 'asc');

        $products = $query->paginate(50)->withQueryString();

        foreach ($products as $row) {
            if (isset($row->name)) {
                $row->name = html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        // Load Shopee links for products on this page
        $pageIds = $products->pluck('product_id')->map(fn($v) => (int) $v)->all();
        $shopeeLinks = collect();
        if (!empty($pageIds)) {
            $shopeeLinks = ShopeeProductLink::query()
                ->whereIn('product_id', $pageIds)
                ->get()
                ->groupBy(fn($l) => (int) $l->product_id);
        }

        // Preload option values for products on this page
        $optionRowsByProductId = collect();
        if (!empty($pageIds)) {
            $optionRows = DB::table($pfx . 'product_option_value as pov')
                ->join($pfx . 'product_option as po', 'pov.product_option_id', '=', 'po.product_option_id')
                ->leftJoin($pfx . 'option_description as od', function ($j) use ($langId) {
                    $j->on('po.option_id', '=', 'od.option_id')
                      ->where('od.language_id', '=', $langId);
                })
                ->leftJoin($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                    $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                      ->where('ovd.language_id', '=', $langId);
                })
                ->whereIn('pov.product_id', $pageIds)
                ->orderBy('pov.product_id')
                ->orderBy('pov.product_option_id')
                ->orderBy('pov.product_option_value_id')
                ->get([
                    'pov.product_id',
                    'od.name as option_name',
                    'ovd.name as option_value_name',
                    'pov.sku as option_sku',
                    'pov.quantity as option_quantity',
                    'pov.price as option_price',
                    'pov.price_prefix as option_price_prefix',
                ]);

            $optionRowsByProductId = $optionRows->groupBy(fn($r) => (int) $r->product_id);
        }

        // Load unmatched items
        $unmatchedItems = ShopeeUnmatchedItem::query()
            ->where('status', 'unmatched')
            ->orderBy('item_name')
            ->get();

        // Manufacturer list for filter dropdown
        $allManufacturers = DB::table($pfx . 'manufacturer')
            ->orderBy('name')
            ->pluck('name', 'manufacturer_id');

        // Profile list for filter dropdown
        $allProfiles = ShopeeProfile::query()->orderBy('name')->pluck('name', 'id');

        // Compute profile names for products on this page
        $profileMap = $profileMapping['map'];
        $pageProfileMap = collect();
        foreach ($products as $p) {
            $pid = (int) $p->product_id;
            $pageProfileMap[$pid] = $profileMap[$pid] ?? [];
        }

        return view('shopee.products.index', [
            'products' => $products,
            'q' => $q,
            'shopeeLinks' => $shopeeLinks,
            'syncStatus' => $syncStatus,
            'manufacturerFilter' => $manufacturerFilter,
            'profileFilter' => $profileFilter,
            'erpStatus' => $erpStatus,
            'allManufacturers' => $allManufacturers,
            'allProfiles' => $allProfiles,
            'profilesByProductId' => $pageProfileMap,
            'unmatchedItems' => $unmatchedItems,
            'optionRowsByProductId' => $optionRowsByProductId,
        ]);
    }

    public function pushForm(int $productId, ShopeeClient $client)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                  ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->where('p.product_id', $productId)
            ->first([
                'p.product_id', 'pd.name', 'pd.description', 'p.image',
                'p.model', 'p.sku', 'p.price', 'p.quantity',
                'p.weight', 'p.length', 'p.width', 'p.height',
                'p.manufacturer_id',
                'm.name as manufacturer_name',
            ]);

        if (!$product) {
            abort(404, 'Product not found.');
        }

        $product->name = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $existingLink = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if ($existingLink) {
            return redirect()->route('shopee.products.index')
                ->with('error', 'Product is already listed on Shopee (Item ID: ' . $existingLink->shopee_item_id . ').');
        }

        $images = [];
        if (!empty($product->image)) {
            $images[] = $product->image;
        }
        $additionalImages = DB::table($pfx . 'product_image')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->pluck('image')
            ->toArray();
        $images = array_merge($images, $additionalImages);

        $shopeeCategories = ShopeeCategory::query()
            ->where('leaf', true)
            ->orderBy('name')
            ->limit(5000)
            ->get(['category_id', 'name']);

        $logistics = $this->fetchLogistics();

        // Find matching profile for this product to pre-select logistics & category
        $matchingProfile = $this->findProfileForProduct($productId, $product->manufacturer_id);
        $profileLogisticIds = $matchingProfile ? ($matchingProfile->logistic_ids ?? []) : [];
        $profileCategoryId = $matchingProfile ? ($matchingProfile->shopee_category_id ?? null) : null;

        return view('shopee.products.push', [
            'product' => $product,
            'images' => $images,
            'shopeeCategories' => $shopeeCategories,
            'logistics' => $logistics,
            'profileLogisticIds' => $profileLogisticIds,
            'profileCategoryId' => $profileCategoryId,
            'profileName' => $matchingProfile ? $matchingProfile->name : null,
        ]);
    }

    public function push(Request $request, int $productId, ShopeeClient $client)
    {
        $request->validate([
            'item_name' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'original_price' => 'required|numeric|min:0.01',
            'normal_stock' => 'required|integer|min:0',
            'item_sku' => 'nullable|string|max:100',
            'weight' => 'required|numeric|min:0.01',
            'package_length' => 'required|integer|min:1',
            'package_width' => 'required|integer|min:1',
            'package_height' => 'required|integer|min:1',
            'category_id' => 'required|integer|min:1',
            'logistic_ids' => 'required|array|min:1',
            'logistic_ids.*' => 'integer',
            'selected_images' => 'required|array|min:1',
            'selected_images.*' => 'string',
        ]);

        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->back()
                ->with('error', 'Missing Shopee settings.');
        }

        $existingLink = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if ($existingLink) {
            return redirect()->back()
                ->with('error', 'Product already listed on Shopee (Item ID: ' . $existingLink->shopee_item_id . ').');
        }

        // Step 1: Upload images
        $imageIds = [];
        foreach ($request->input('selected_images', []) as $imgPath) {
            $localPath = $this->resolveLocalImagePath($imgPath);
            if (!$localPath || !file_exists($localPath)) {
                continue;
            }

            $uploadResult = $this->uploadImage($client, $setting, $localPath);

            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.products.upload_image',
                'method' => 'POST',
                'api_path' => '/api/v2/media_space/upload_image',
                'auth_required' => true,
                'request_params' => ['image' => $imgPath],
                'response_status' => $uploadResult['status'] ?? null,
                'ok' => (bool) ($uploadResult['ok'] ?? false),
                'response_body' => $uploadResult['body'] ?? null,
                'user_id' => auth()->id(),
            ]);

            if (!($uploadResult['ok'] ?? false)) {
                $msg = is_array($uploadResult['body'] ?? null)
                    ? ($uploadResult['body']['message'] ?? ($uploadResult['body']['error'] ?? 'Upload failed'))
                    : 'Upload failed';
                return redirect()->route('shopee.products.push', $productId)
                    ->with('error', 'Image upload failed for "' . basename($imgPath) . '": ' . $msg)
                    ->withInput();
            }

            $imageId = $uploadResult['body']['response']['image_info']['image_id'] ?? null;
            if ($imageId) {
                $imageIds[] = (string) $imageId;
            }
        }

        if (empty($imageIds)) {
            return redirect()->route('shopee.products.push', $productId)
                ->with('error', 'No images were uploaded successfully.')
                ->withInput();
        }

        // Step 2: Build add_item payload
        $logisticInfo = [];
        foreach ($request->input('logistic_ids', []) as $lid) {
            $logisticInfo[] = ['logistic_id' => (int) $lid, 'enabled' => true];
        }

        $payload = [
            'original_price' => (float) $request->input('original_price'),
            'description' => (string) $request->input('description'),
            'item_name' => (string) $request->input('item_name'),
            'normal_stock' => (int) $request->input('normal_stock'),
            'item_sku' => (string) ($request->input('item_sku') ?? ''),
            'weight' => (float) $request->input('weight'),
            'dimension' => [
                'package_length' => (int) $request->input('package_length'),
                'package_width' => (int) $request->input('package_width'),
                'package_height' => (int) $request->input('package_height'),
            ],
            'category_id' => (int) $request->input('category_id'),
            'image' => ['image_id_list' => $imageIds],
            'logistic_info' => $logisticInfo,
            'brand' => ['brand_id' => 0, 'original_brand_name' => 'No Brand'],
        ];

        $path = '/api/v2/product/add_item';
        $result = $client->shopPost(
            $setting->mode ?? 'sandbox',
            (int) $setting->partner_id, (string) $setting->partner_key,
            (string) $setting->access_token, (int) $setting->shop_id,
            $path, [], $payload
        );

        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.products.add_item', 'method' => 'POST', 'api_path' => $path,
            'auth_required' => true, 'request_params' => $payload,
            'response_status' => $result['status'] ?? null, 'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        $body = $result['body'] ?? null;
        if (!($result['ok'] ?? false)) {
            $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'API call failed')) : 'API call failed';
            return redirect()->route('shopee.products.push', $productId)
                ->with('error', 'Push failed: ' . $msg)->withInput();
        }

        $itemId = $body['response']['item_id'] ?? null;
        if (!$itemId) {
            return redirect()->route('shopee.products.push', $productId)
                ->with('error', 'Push succeeded but no item_id returned. Check API logs.')->withInput();
        }

        ShopeeProductLink::create([
            'product_id' => $productId,
            'shopee_item_id' => (int) $itemId,
            'shopee_model_id' => null,
            'sku' => $request->input('item_sku') ?? '',
        ]);

        return redirect()->route('shopee.products.index')
            ->with('status', 'Product pushed to Shopee. Item ID: ' . $itemId);
    }

    // ── Sync Product IDs (with model support + local cache) ────────

    public function syncProductIds(Request $request, ShopeeClient $client)
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->back()->with('error', 'Missing Shopee settings.');
        }

        $selectedIds = array_map('intval', array_filter($request->input('product_ids', [])));
        if (empty($selectedIds)) {
            return redirect()->back()->with('error', 'No products selected.');
        }

        $pfx = (string) config('catalog.prefix');

        // Build SKU map from selected products (lowercase ERP SKU → product_id)
        // All keys are lowercased for case-insensitive matching
        $targetSkus = [];
        $originalSkuCase = []; // lowercase → original case (for display)
        foreach ($selectedIds as $pid) {
            $row = DB::table($pfx . 'product')->where('product_id', $pid)->first(['model', 'sku']);
            if ($row) {
                if ($row->model && trim($row->model) !== '') {
                    $key = strtolower(trim($row->model));
                    $targetSkus[$key] = $pid;
                    $originalSkuCase[$key] = trim($row->model);
                }
                if ($row->sku && trim($row->sku) !== '') {
                    $key = strtolower(trim($row->sku));
                    $targetSkus[$key] = $pid;
                    $originalSkuCase[$key] = trim($row->sku);
                }
            }
            $optSkus = DB::table($pfx . 'product_option_value')
                ->where('product_id', $pid)->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku');
            foreach ($optSkus as $os) {
                $key = strtolower(trim($os));
                $targetSkus[$key] = $pid;
                $originalSkuCase[$key] = trim($os);
            }
        }

        if (empty($targetSkus)) {
            return redirect()->back()->with('error', 'Selected products have no SKUs to match.');
        }

        $linked = 0;
        $skipped = 0;
        $remaining = $targetSkus; // lowercase SKUs still not linked

        // ── Step 1: Check cache for quick matches ──────────────────────
        $cacheHits = ShopeeItemCache::query()
            ->whereIn(DB::raw('LOWER(sku)'), array_keys($remaining))
            ->get();

        foreach ($cacheHits as $hit) {
            $lcSku = strtolower($hit->sku);
            $productId = $remaining[$lcSku] ?? 0;
            if ($productId === 0) continue;

            $isParent = ($hit->shopee_model_id === null);

            if ($isParent) {
                $erpHasOptions = DB::table($pfx . 'product_option_value')
                    ->where('product_id', $productId)
                    ->whereNotNull('sku')->where('sku', '!=', '')
                    ->exists();

                if ($erpHasOptions) {
                    $hasModelEntries = ShopeeItemCache::query()
                        ->where('shopee_item_id', $hit->shopee_item_id)
                        ->whereNotNull('shopee_model_id')
                        ->exists();
                    if ($hasModelEntries) {
                        continue; // Skip parent — model-level cache entries will match option SKUs
                    }
                }
            }

            $alreadyLinked = ShopeeProductLink::query()
                ->where('shopee_item_id', $hit->shopee_item_id)
                ->where('product_id', $productId)
                ->exists();

            if ($alreadyLinked) {
                $skipped++;
            } else {
                ShopeeProductLink::create([
                    'product_id' => $productId,
                    'shopee_item_id' => (int) $hit->shopee_item_id,
                    'shopee_model_id' => $isParent ? null : (int) $hit->shopee_model_id,
                    'sku' => $hit->sku,
                ]);
                $linked++;
            }
            $remaining = array_filter($remaining, fn($pid) => $pid !== $productId);
        }

        if (empty($remaining)) {
            $selectedCount = count($selectedIds);
            $msg = "Sync complete for {$selectedCount} product(s). Linked: {$linked}. Already linked: {$skipped}. [from cache]";
            return redirect()->back()->with($linked > 0 || $skipped > 0 ? 'status' : 'error', $msg);
        }

        // ── Step 2: Cache miss — fetch remaining from Shopee API ──────
        $allItemIds = $this->fetchAllShopeeItemIds($client, $setting, 730, 'shopee.products.sync_ids');

        if (empty($allItemIds)) {
            return redirect()->back()->with('error', 'No items found on Shopee.');
        }

        // ── Step 3: Fetch base info, cache simple items, collect model items ──
        $needModelFetch = [];

        foreach (array_chunk($allItemIds, 50) as $chunk) {
            if (empty($remaining)) break; // Early exit

            $infoResult = $client->shopGet(
                $setting->mode ?? 'sandbox',
                (int) $setting->partner_id, (string) $setting->partner_key,
                (string) $setting->access_token, (int) $setting->shop_id,
                '/api/v2/product/get_item_base_info',
                ['item_id_list' => implode(',', $chunk)]
            );

            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.products.sync_ids', 'method' => 'GET',
                'api_path' => '/api/v2/product/get_item_base_info', 'auth_required' => true,
                'request_params' => ['item_id_list' => implode(',', $chunk)],
                'response_status' => $infoResult['status'] ?? null,
                'ok' => (bool) ($infoResult['ok'] ?? false),
                'response_body' => $infoResult['body'] ?? null, 'user_id' => auth()->id(),
            ]);

            if (!($infoResult['ok'] ?? false)) continue;

            $itemList = (($infoResult['body'] ?? [])['response'] ?? ($infoResult['body'] ?? []))['item_list'] ?? [];

            foreach ($itemList as $item) {
                $itemId = (int) ($item['item_id'] ?? 0);
                $itemSku = trim((string) ($item['item_sku'] ?? ''));
                $hasModel = (bool) ($item['has_model'] ?? false);
                $itemName = trim((string) ($item['item_name'] ?? ''));
                $imageUrl = $item['image']['image_url_list'][0] ?? null;

                if (!$itemId) continue;

                // Always try item_sku first, regardless of has_model
                if ($itemSku !== '') {
                    ShopeeItemCache::query()->updateOrCreate(
                        ['shopee_item_id' => $itemId, 'shopee_model_id' => null],
                        ['sku' => $itemSku, 'item_name' => $itemName, 'image_url' => $imageUrl]
                    );

                    $productId = $remaining[strtolower($itemSku)] ?? 0;
                    if ($productId > 0) {
                        // If the Shopee item has models AND the ERP product has option SKUs,
                        // skip parent-level link — let Step 4 create model-level links instead.
                        $erpHasOptions = $hasModel && DB::table($pfx . 'product_option_value')
                            ->where('product_id', $productId)
                            ->whereNotNull('sku')->where('sku', '!=', '')
                            ->exists();

                        if ($erpHasOptions) {
                            // Don't create parent link; don't remove from $remaining.
                            // Step 4 will match model SKUs to option SKUs.
                        } else {
                            $alreadyLinked = ShopeeProductLink::query()
                                ->where('shopee_item_id', $itemId)->where('product_id', $productId)->exists();
                            if ($alreadyLinked) {
                                $skipped++;
                            } else {
                                ShopeeProductLink::create([
                                    'product_id' => $productId, 'shopee_item_id' => $itemId,
                                    'shopee_model_id' => null, 'sku' => $itemSku,
                                ]);
                                $linked++;
                            }
                            $remaining = array_filter($remaining, fn($pid) => $pid !== $productId);
                        }
                    }
                }

                // Additionally queue model fetch if has_model (models may have different SKUs)
                if ($hasModel) {
                    $needModelFetch[$itemId] = [
                        'item_name' => $itemName, 'item_sku' => $itemSku, 'image_url' => $imageUrl,
                    ];
                }
            }
        }

        // ── Step 4: Fetch models one-by-one (avoids batch failure from deleted items) ──
        if (!empty($needModelFetch) && !empty($remaining)) {
            foreach ($needModelFetch as $itemId => $info) {
                if (empty($remaining)) break; // Early exit

                $modelResult = $client->shopGet(
                    $setting->mode ?? 'sandbox',
                    (int) $setting->partner_id, (string) $setting->partner_key,
                    (string) $setting->access_token, (int) $setting->shop_id,
                    '/api/v2/product/get_model_list',
                    ['item_id' => (int) $itemId]
                );

                if (!($modelResult['ok'] ?? false)) continue; // Skip invalid/deleted items

                $sr = ($modelResult['body'] ?? [])['response'] ?? ($modelResult['body'] ?? []);
                $models = $sr['model'] ?? [];

                if (empty($models)) continue; // item_sku already checked in Step 3

                // Cache and match each model
                foreach ($models as $model) {
                    $modelId = (int) ($model['model_id'] ?? 0);
                    $modelSku = trim((string) ($model['model_sku'] ?? ''));
                    if ($modelSku === '') continue;

                    ShopeeItemCache::query()->updateOrCreate(
                        ['shopee_item_id' => $itemId, 'shopee_model_id' => $modelId],
                        ['sku' => $modelSku, 'item_name' => $info['item_name'], 'image_url' => $info['image_url']]
                    );

                    $productId = $remaining[strtolower($modelSku)] ?? 0;
                    if ($productId === 0) continue;

                    $exists = ShopeeProductLink::query()
                        ->where('shopee_item_id', $itemId)->where('shopee_model_id', $modelId)->exists();
                    if ($exists) {
                        $skipped++;
                    } else {
                        ShopeeProductLink::create([
                            'product_id' => $productId, 'shopee_item_id' => $itemId,
                            'shopee_model_id' => $modelId, 'sku' => $modelSku,
                        ]);
                        $linked++;
                    }
                    $remaining = array_filter($remaining, fn($pid) => $pid !== $productId);
                }
            }
        }

        $selectedCount = count($selectedIds);
        // Collect unique unmatched product IDs and their original-case SKUs
        $unmatchedPids = array_unique(array_values($remaining));
        $notFound = count($unmatchedPids);
        $msg = "Sync complete for {$selectedCount} product(s). Linked: {$linked}. Already linked: {$skipped}.";
        if ($notFound > 0) {
            $unmatchedSkuNames = [];
            foreach ($remaining as $lcSku => $pid) {
                $unmatchedSkuNames[] = $originalSkuCase[$lcSku] ?? $lcSku;
            }
            $skuList = implode(', ', array_unique($unmatchedSkuNames));
            $msg .= " No matching Shopee product found for SKUs: {$skuList}";
        }
        return redirect()->back()->with($linked > 0 || $skipped > 0 ? 'status' : 'error', $msg);
    }

    // ── Sync Qty (single, model-aware) ──────────────────────────────

    public function syncQuantity(int $productId, ShopeeClient $client)
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->back()->with('error', 'Missing Shopee settings.');
        }

        $links = ShopeeProductLink::query()->where('product_id', $productId)->get();
        if ($links->isEmpty()) {
            return redirect()->back()->with('error', 'Product not linked to Shopee. Sync Product IDs first.');
        }

        $pfx = (string) config('catalog.prefix');
        $results = $this->pushStockForLinks($client, $setting, $pfx, $links);

        $msg = $results['ok'] > 0
            ? "Stock synced to Shopee ({$results['ok']} model(s))."
            : 'Sync qty failed: ' . ($results['last_error'] ?? 'Unknown error');

        return redirect()->back()->with($results['ok'] > 0 ? 'status' : 'error', $msg);
    }

    // ── Sync Price (single, model-aware) ────────────────────────────

    public function syncPrice(int $productId, ShopeeClient $client)
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->back()->with('error', 'Missing Shopee settings.');
        }

        $links = ShopeeProductLink::query()->where('product_id', $productId)->get();
        if ($links->isEmpty()) {
            return redirect()->back()->with('error', 'Product not linked to Shopee. Sync Product IDs first.');
        }

        $pfx = (string) config('catalog.prefix');
        $results = $this->pushPriceForLinks($client, $setting, $pfx, $links);

        $msg = $results['ok'] > 0
            ? "Price synced to Shopee ({$results['ok']} model(s))."
            : 'Sync price failed: ' . ($results['last_error'] ?? 'Unknown error');

        return redirect()->back()->with($results['ok'] > 0 ? 'status' : 'error', $msg);
    }

    // ── Unlink Product from Shopee ──────────────────────────────────

    public function unlink(int $productId)
    {
        // Get linked item IDs before deleting, so we can clear stale cache entries
        $links = ShopeeProductLink::query()->where('product_id', $productId)->get();

        if ($links->isEmpty()) {
            return redirect()->back()->with('error', 'Product was not linked to Shopee.');
        }

        // Clear cache entries for the linked SKUs to prevent stale re-matching
        foreach ($links as $link) {
            ShopeeItemCache::query()
                ->where('shopee_item_id', $link->shopee_item_id)
                ->where('sku', $link->sku)
                ->delete();
        }

        $deleted = ShopeeProductLink::query()->where('product_id', $productId)->delete();

        return redirect()->back()->with('status', "Unlinked product from Shopee ($deleted link(s) removed, cache cleared). You can now re-sync.");
    }

    // ── Rebuild Shopee Item Cache ──────────────────────────────────

    public function rebuildCache(ShopeeClient $client)
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->back()->with('error', 'Missing Shopee settings.');
        }

        $allItemIds = $this->fetchAllShopeeItemIds($client, $setting, 730, 'shopee.products.rebuild_cache');

        if (empty($allItemIds)) {
            return redirect()->back()->with('error', 'No items found on Shopee.');
        }

        // Clear existing cache
        DB::table('shopee_item_cache')->delete();

        $cached = 0;
        $modelsCached = 0;

        foreach (array_chunk($allItemIds, 50) as $chunk) {
            $infoResult = $client->shopGet(
                $setting->mode ?? 'sandbox',
                (int) $setting->partner_id, (string) $setting->partner_key,
                (string) $setting->access_token, (int) $setting->shop_id,
                '/api/v2/product/get_item_base_info',
                ['item_id_list' => implode(',', $chunk)]
            );

            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.products.rebuild_cache', 'method' => 'GET',
                'api_path' => '/api/v2/product/get_item_base_info', 'auth_required' => true,
                'request_params' => ['item_id_list' => implode(',', $chunk)],
                'response_status' => $infoResult['status'] ?? null,
                'ok' => (bool) ($infoResult['ok'] ?? false),
                'response_body' => $infoResult['body'] ?? null, 'user_id' => auth()->id(),
            ]);

            if (!($infoResult['ok'] ?? false)) continue;

            $itemList = (($infoResult['body'] ?? [])['response'] ?? ($infoResult['body'] ?? []))['item_list'] ?? [];

            foreach ($itemList as $item) {
                $itemId = (int) ($item['item_id'] ?? 0);
                $itemSku = trim((string) ($item['item_sku'] ?? ''));
                $hasModel = (bool) ($item['has_model'] ?? false);
                $itemName = trim((string) ($item['item_name'] ?? ''));
                $imageUrl = $item['image']['image_url_list'][0] ?? null;

                if (!$itemId) continue;

                if ($itemSku !== '') {
                    ShopeeItemCache::query()->create([
                        'shopee_item_id' => $itemId,
                        'shopee_model_id' => null,
                        'sku' => $itemSku,
                        'item_name' => $itemName,
                        'image_url' => $imageUrl,
                    ]);
                    $cached++;
                }

                if ($hasModel) {
                    $modelResult = $client->shopGet(
                        $setting->mode ?? 'sandbox',
                        (int) $setting->partner_id, (string) $setting->partner_key,
                        (string) $setting->access_token, (int) $setting->shop_id,
                        '/api/v2/product/get_model_list',
                        ['item_id' => $itemId]
                    );

                    if (!($modelResult['ok'] ?? false)) continue;

                    $models = (($modelResult['body'] ?? [])['response'] ?? ($modelResult['body'] ?? []))['model'] ?? [];

                    foreach ($models as $model) {
                        $modelId = (int) ($model['model_id'] ?? 0);
                        $modelSku = trim((string) ($model['model_sku'] ?? ''));
                        if ($modelSku === '' || !$modelId) continue;

                        ShopeeItemCache::query()->create([
                            'shopee_item_id' => $itemId,
                            'shopee_model_id' => $modelId,
                            'sku' => $modelSku,
                            'item_name' => $itemName,
                            'image_url' => $imageUrl,
                        ]);
                        $modelsCached++;
                    }
                }
            }
        }

        $total = $cached + $modelsCached;
        return redirect()->back()
            ->with('status', "Cache rebuilt: {$total} entries ({$cached} items + {$modelsCached} models) from " . count($allItemIds) . " Shopee products.");
    }

    // ── Bulk Sync Qty (model-aware) ─────────────────────────────────

    public function bulkSyncQuantity(Request $request, ShopeeClient $client)
    {
        $ids = $this->parseProductIds($request);
        if (empty($ids)) {
            return redirect()->back()->with('error', 'No products selected.');
        }

        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->back()->with('error', 'Missing Shopee settings.');
        }

        $pfx = (string) config('catalog.prefix');
        $links = ShopeeProductLink::query()->whereIn('product_id', $ids)->get();

        $ok = 0;
        $err = 0;
        $skip = count($ids) - $links->pluck('product_id')->unique()->count();

        $results = $this->pushStockForLinks($client, $setting, $pfx, $links);
        $ok = $results['ok'];
        $err = $results['err'];

        return redirect()->back()
            ->with('status', "Bulk Sync Qty: {$ok} success, {$err} error, {$skip} skipped (not linked).");
    }

    // ── Bulk Sync Price (model-aware) ───────────────────────────────

    public function bulkSyncPrice(Request $request, ShopeeClient $client)
    {
        $ids = $this->parseProductIds($request);
        if (empty($ids)) {
            return redirect()->back()->with('error', 'No products selected.');
        }

        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->back()->with('error', 'Missing Shopee settings.');
        }

        $pfx = (string) config('catalog.prefix');
        $links = ShopeeProductLink::query()->whereIn('product_id', $ids)->get();

        $skip = count($ids) - $links->pluck('product_id')->unique()->count();

        $results = $this->pushPriceForLinks($client, $setting, $pfx, $links);

        return redirect()->back()
            ->with('status', "Bulk Sync Price: {$results['ok']} success, {$results['err']} error, {$skip} skipped (not linked).");
    }

    // ── Sync Single Product ID ────────────────────────────────────

    public function syncSingleProductId(int $productId, ShopeeClient $client)
    {
        $tag = '[ShopeeSync:single:' . $productId . ']';

        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->back()->with('error', 'Missing Shopee settings.');
        }

        // Already linked?
        $existingLink = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if ($existingLink) {
            return redirect()->back()->with('status', 'Product already linked to Shopee item ' . $existingLink->shopee_item_id . ' (SKU: ' . $existingLink->sku . '). Use Unlink to re-sync.');
        }

        $pfx = (string) config('catalog.prefix');

        // Collect all SKUs for this product: option value SKUs + main model + sku column
        $ovSkus = DB::table($pfx . 'product_option_value')
            ->where('product_id', $productId)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->pluck('sku')
            ->map(fn($s) => trim((string) $s))
            ->filter(fn($s) => $s !== '')
            ->unique()
            ->values()
            ->toArray();

        $product = DB::table($pfx . 'product')->where('product_id', $productId)->first(['model', 'sku']);
        $mainModel = trim((string) ($product->model ?? ''));
        $mainSku = trim((string) ($product->sku ?? ''));

        $allSkus = $ovSkus;
        if ($mainModel !== '' && !in_array($mainModel, $allSkus)) $allSkus[] = $mainModel;
        if ($mainSku !== '' && !in_array($mainSku, $allSkus)) $allSkus[] = $mainSku;

        Log::info("$tag ERP product fields — model: '$mainModel', sku: '$mainSku', option_skus: " . json_encode($ovSkus));
        Log::info("$tag Looking for these SKUs: " . json_encode($allSkus));

        if (empty($allSkus)) {
            return redirect()->back()->with('error', 'Product has no SKU (main or option values).');
        }

        // Build lowercase lookup for case-insensitive matching
        $lcSkus = array_map('strtolower', $allSkus);

        // ── Try cache first ──────────────────────────────────────────
        $cacheHits = ShopeeItemCache::query()
            ->whereIn(DB::raw('LOWER(sku)'), $lcSkus)
            ->get();

        if ($cacheHits->isNotEmpty()) {
            $hasOptions = !empty($ovSkus);
            $linkedAny = false;
            $cacheItemId = null;
            $cacheItemName = null;

            foreach ($cacheHits as $hit) {
                $isParent = ($hit->shopee_model_id === null);

                // Skip parent-level hit when ERP product has option SKUs and Shopee item has models
                if ($isParent && $hasOptions) {
                    $hasModelEntries = ShopeeItemCache::query()
                        ->where('shopee_item_id', $hit->shopee_item_id)
                        ->whereNotNull('shopee_model_id')
                        ->exists();
                    if ($hasModelEntries) {
                        Log::info("$tag Cache: skipping parent hit (item {$hit->shopee_item_id}, sku: {$hit->sku}) — ERP has option SKUs");
                        continue;
                    }
                }

                if ($isParent) {
                    ShopeeProductLink::create([
                        'product_id' => $productId,
                        'shopee_item_id' => (int) $hit->shopee_item_id,
                        'shopee_model_id' => null,
                        'sku' => $hit->sku,
                    ]);
                    Log::info("$tag MATCHED via cache (parent) — shopee_item_id: {$hit->shopee_item_id}, sku: {$hit->sku}");
                    return redirect()->back()
                        ->with('status', "Linked to Shopee item {$hit->shopee_item_id} \"{$hit->item_name}\" (SKU: {$hit->sku}) [from cache].");
                }

                // Model-level match
                $exists = ShopeeProductLink::query()
                    ->where('shopee_item_id', $hit->shopee_item_id)
                    ->where('shopee_model_id', $hit->shopee_model_id)
                    ->exists();
                if (!$exists) {
                    ShopeeProductLink::create([
                        'product_id' => $productId,
                        'shopee_item_id' => (int) $hit->shopee_item_id,
                        'shopee_model_id' => (int) $hit->shopee_model_id,
                        'sku' => $hit->sku,
                    ]);
                }
                $linkedAny = true;
                $cacheItemId = $hit->shopee_item_id;
                $cacheItemName = $hit->item_name;
                Log::info("$tag MATCHED via cache (model) — shopee_item_id: {$hit->shopee_item_id}, model_id: {$hit->shopee_model_id}, sku: {$hit->sku}");
            }

            if ($linkedAny) {
                return redirect()->back()
                    ->with('status', "Linked to Shopee item {$cacheItemId} \"{$cacheItemName}\" (models matched by SKU) [from cache].");
            }
        }

        Log::info("$tag No cache match, falling back to full API scan");

        // ── Cache miss — fallback to full API scan ──────────────────
        $allItemIds = $this->fetchAllShopeeItemIds($client, $setting, 730, 'shopee.products.sync_single');
        Log::info("$tag Total unique Shopee items: " . count($allItemIds));

        if (empty($allItemIds)) {
            return redirect()->back()->with('error', 'No items found on Shopee.');
        }

        // Fetch base info and try to match
        $simpleCount = 0;
        $modelItemCount = 0;
        $allShopeeSkus = []; // collect for debugging

        foreach (array_chunk($allItemIds, 50) as $chunkIdx => $chunk) {
            $infoResult = $client->shopGet(
                $setting->mode ?? 'sandbox',
                (int) $setting->partner_id, (string) $setting->partner_key,
                (string) $setting->access_token, (int) $setting->shop_id,
                '/api/v2/product/get_item_base_info',
                ['item_id_list' => implode(',', $chunk)]
            );

            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.products.sync_single', 'method' => 'GET',
                'api_path' => '/api/v2/product/get_item_base_info', 'auth_required' => true,
                'request_params' => ['item_id_list' => implode(',', $chunk), 'product_id' => $productId],
                'response_status' => $infoResult['status'] ?? null,
                'ok' => (bool) ($infoResult['ok'] ?? false),
                'response_body' => $infoResult['body'] ?? null, 'user_id' => auth()->id(),
            ]);

            if (!($infoResult['ok'] ?? false)) {
                Log::warning("$tag get_item_base_info FAILED for chunk $chunkIdx — " . json_encode($infoResult['body'] ?? null));
                continue;
            }

            $itemList = (($infoResult['body'] ?? [])['response'] ?? ($infoResult['body'] ?? []))['item_list'] ?? [];

            foreach ($itemList as $item) {
                $itemId = (int) ($item['item_id'] ?? 0);
                $itemSku = trim((string) ($item['item_sku'] ?? ''));
                $hasModel = (bool) ($item['has_model'] ?? false);
                $itemName = trim((string) ($item['item_name'] ?? ''));
                $imageUrl = $item['image']['image_url_list'][0] ?? null;
                if (!$itemId) continue;

                // Always check item_sku first, regardless of has_model
                if ($itemSku !== '') {
                    $simpleCount++;
                    $allShopeeSkus[] = $itemSku;

                    ShopeeItemCache::query()->updateOrCreate(
                        ['shopee_item_id' => $itemId, 'shopee_model_id' => null],
                        ['sku' => $itemSku, 'item_name' => $itemName, 'image_url' => $imageUrl]
                    );

                    if (in_array(strtolower($itemSku), $lcSkus)) {
                        // If ERP product has option SKUs and Shopee item has models,
                        // skip parent link — let model matching below handle it.
                        $erpHasOptions = $hasModel && !empty($ovSkus);
                        if (!$erpHasOptions) {
                            ShopeeProductLink::create([
                                'product_id' => $productId, 'shopee_item_id' => $itemId,
                                'shopee_model_id' => null, 'sku' => $itemSku,
                            ]);
                            Log::info("$tag MATCHED via item_sku — shopee_item_id: $itemId, item_sku: $itemSku, item_name: '$itemName', has_model: " . ($hasModel ? 'true' : 'false'));
                            return redirect()->back()
                                ->with('status', "Linked to Shopee item {$itemId} \"{$itemName}\" (SKU: {$itemSku}).");
                        }
                        Log::info("$tag item_sku '$itemSku' matches but ERP has option SKUs — deferring to model-level matching");
                    }
                }

                // Additionally check model SKUs if has_model
                if ($hasModel) {
                    $modelItemCount++;
                    $modelResult = $client->shopGet(
                        $setting->mode ?? 'sandbox',
                        (int) $setting->partner_id, (string) $setting->partner_key,
                        (string) $setting->access_token, (int) $setting->shop_id,
                        '/api/v2/product/get_model_list',
                        ['item_id' => $itemId]
                    );

                    ShopeeApiLog::safeCreate([
                        'pack' => 'shopee.products.sync_single', 'method' => 'GET',
                        'api_path' => '/api/v2/product/get_model_list', 'auth_required' => true,
                        'request_params' => ['item_id' => $itemId, 'product_id' => $productId],
                        'response_status' => $modelResult['status'] ?? null,
                        'ok' => (bool) ($modelResult['ok'] ?? false),
                        'response_body' => $modelResult['body'] ?? null, 'user_id' => auth()->id(),
                    ]);

                    if (!($modelResult['ok'] ?? false)) {
                        Log::warning("$tag get_model_list FAILED for item_id=$itemId — " . json_encode($modelResult['body'] ?? null));
                        continue;
                    }

                    $models = (($modelResult['body'] ?? [])['response'] ?? ($modelResult['body'] ?? []))['model'] ?? [];
                    $linkedAny = false;
                    foreach ($models as $model) {
                        $modelSku = trim((string) ($model['model_sku'] ?? ''));
                        $mId = (int) ($model['model_id'] ?? 0);

                        if ($modelSku !== '') {
                            $allShopeeSkus[] = $modelSku;
                            ShopeeItemCache::query()->updateOrCreate(
                                ['shopee_item_id' => $itemId, 'shopee_model_id' => $mId],
                                ['sku' => $modelSku, 'item_name' => $itemName, 'image_url' => $imageUrl]
                            );
                        }

                        if ($modelSku !== '' && in_array(strtolower($modelSku), $lcSkus)) {
                            $exists = ShopeeProductLink::query()
                                ->where('shopee_item_id', $itemId)
                                ->where('shopee_model_id', $mId)
                                ->exists();
                            if (!$exists) {
                                ShopeeProductLink::create([
                                    'product_id' => $productId, 'shopee_item_id' => $itemId,
                                    'shopee_model_id' => $mId, 'sku' => $modelSku,
                                ]);
                            }
                            $linkedAny = true;
                            Log::info("$tag MATCHED via model_sku — shopee_item_id: $itemId, model_id: $mId, model_sku: $modelSku");
                        }
                    }
                    if ($linkedAny) {
                        return redirect()->back()
                            ->with('status', "Linked to Shopee item {$itemId} \"{$itemName}\" (models matched by SKU).");
                    }
                }
            }
        }

        Log::warning("$tag NO MATCH — simple items scanned: $simpleCount, model items scanned: $modelItemCount, total Shopee SKUs seen: " . count($allShopeeSkus));
        Log::warning("$tag ERP SKUs wanted: " . json_encode($allSkus));
        Log::warning("$tag Shopee SKUs sample (first 50): " . json_encode(array_slice($allShopeeSkus, 0, 50)));

        return redirect()->back()
            ->with('error', 'No matching Shopee product found for SKUs: ' . implode(', ', $allSkus)
                . ' (Scanned ' . count($allItemIds) . ' Shopee items: ' . $simpleCount . ' simple, ' . $modelItemCount . ' with models)');
    }

    // ── Manual Link / Dismiss Unmatched ─────────────────────────────

    public function linkUnmatched(Request $request, int $unmatchedId)
    {
        $request->validate(['product_id' => 'required|integer|min:1']);

        $item = ShopeeUnmatchedItem::findOrFail($unmatchedId);
        $productId = (int) $request->input('product_id');

        $pfx = (string) config('catalog.prefix');
        $product = DB::table($pfx . 'product')->where('product_id', $productId)->first(['product_id']);
        if (!$product) {
            return redirect()->back()->with('error', 'Catalog product not found.');
        }

        // Create the link
        $exists = ShopeeProductLink::query()
            ->where('shopee_item_id', $item->shopee_item_id)
            ->where('shopee_model_id', $item->shopee_model_id)
            ->exists();

        if (!$exists) {
            ShopeeProductLink::create([
                'product_id' => $productId,
                'shopee_item_id' => $item->shopee_item_id,
                'shopee_model_id' => $item->shopee_model_id,
                'sku' => $item->sku ?? '',
            ]);
        }

        $item->update(['status' => 'linked', 'linked_product_id' => $productId]);

        return redirect()->back()
            ->with('status', 'Linked "' . ($item->item_name ?? 'Unknown') . '" to product #' . $productId . '.');
    }

    public function dismissUnmatched(int $unmatchedId)
    {
        $item = ShopeeUnmatchedItem::findOrFail($unmatchedId);
        $item->update(['status' => 'dismissed']);

        return redirect()->back()
            ->with('status', 'Dismissed "' . ($item->item_name ?? 'Unknown') . '".');
    }

    public function searchCatalogProducts(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if ($q === '' || strlen($q) < 2) {
            return response()->json([]);
        }

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        // Find product IDs matching by option value SKU
        $optionMatchIds = DB::table($pfx . 'product_option_value')
            ->where('sku', 'like', '%' . $q . '%')
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->pluck('product_id')
            ->unique()
            ->toArray();

        $results = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                  ->where('pd.language_id', '=', $langId);
            })
            ->where(function ($sub) use ($q, $optionMatchIds) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.model', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%');
                if (!empty($optionMatchIds)) {
                    $sub->orWhereIn('p.product_id', $optionMatchIds);
                }
            })
            ->limit(20)
            ->get(['p.product_id', 'pd.name', 'p.model', 'p.sku', 'p.image']);

        // Fetch option values for these products
        $productIds = $results->pluck('product_id')->map(fn($v) => (int) $v)->all();
        $optionValues = collect();
        if (!empty($productIds)) {
            $optionValues = DB::table($pfx . 'product_option_value as pov')
                ->leftJoin($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                    $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                      ->where('ovd.language_id', '=', $langId);
                })
                ->whereIn('pov.product_id', $productIds)
                ->whereNotNull('pov.sku')
                ->where('pov.sku', '!=', '')
                ->get(['pov.product_id', 'pov.sku', 'pov.quantity', 'ovd.name as value_name'])
                ->groupBy('product_id');
        }

        return response()->json($results->map(function ($r) use ($optionValues) {
            $opts = $optionValues->get((int) $r->product_id, collect());
            $optionList = $opts->map(function ($ov) {
                return [
                    'sku' => $ov->sku,
                    'name' => $ov->value_name ?? '',
                    'qty' => (int) $ov->quantity,
                ];
            })->values()->toArray();

            return [
                'product_id' => (int) $r->product_id,
                'name' => html_entity_decode($r->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'model' => $r->model ?? '',
                'sku' => $r->sku ?? '',
                'image' => $r->image ? asset('storage/' . ltrim($r->image, '/')) : null,
                'options' => $optionList,
            ];
        }));
    }

    // ── Model-Aware Stock Push Helper ───────────────────────────────

    private function pushStockForLinks(ShopeeClient $client, object $setting, string $pfx, $links): array
    {
        $ok = 0;
        $err = 0;
        $lastError = '';

        // Group links by shopee_item_id
        $grouped = [];
        foreach ($links as $link) {
            $grouped[$link->shopee_item_id][] = $link;
        }

        foreach ($grouped as $itemId => $itemLinks) {
            $stockList = [];

            foreach ($itemLinks as $link) {
                $productId = (int) $link->product_id;
                $sku = trim((string) ($link->sku ?? ''));
                $modelId = (int) ($link->shopee_model_id ?? 0);
                $qty = 0;

                if ($sku !== '') {
                    // Try option value SKU for quantity
                    $povQty = DB::table($pfx . 'product_option_value')
                        ->where('product_id', $productId)
                        ->where('sku', $sku)
                        ->value('quantity');

                    if ($povQty !== null) {
                        $qty = max(0, (int) $povQty);
                    } else {
                        $qty = max(0, (int) (DB::table($pfx . 'product')->where('product_id', $productId)->value('quantity') ?? 0));
                    }
                } else {
                    $qty = max(0, (int) (DB::table($pfx . 'product')->where('product_id', $productId)->value('quantity') ?? 0));
                }

                $stockList[] = ['model_id' => $modelId, 'seller_stock' => [['stock' => $qty]]];

            }

            if (empty($stockList)) continue;

            $path = '/api/v2/product/update_stock';
            $payload = ['item_id' => (int) $itemId, 'stock_list' => $stockList];

            $result = $client->shopPost(
                $setting->mode ?? 'sandbox',
                (int) $setting->partner_id, (string) $setting->partner_key,
                (string) $setting->access_token, (int) $setting->shop_id,
                $path, [], $payload
            );

            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.products.update_stock', 'method' => 'POST', 'api_path' => $path,
                'auth_required' => true, 'request_params' => $payload,
                'response_status' => $result['status'] ?? null, 'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
            ]);

            $isOk = ($result['ok'] ?? false) && (($result['body']['error'] ?? '') === '' || ($result['body']['error'] ?? null) === null);
            $syncData = [
                'last_synced_at' => now(),
                'last_sync_action' => 'sync_qty',
                'last_sync_ok' => $isOk,
                'last_sync_error_code' => $isOk ? null : (string) ($result['body']['error'] ?? ''),
                'last_sync_error_message' => $isOk ? null : (string) ($result['body']['message'] ?? ''),
            ];
            foreach ($itemLinks as $link) {
                $link->update($syncData);
            }

            if ($isOk) {
                $ok += count($stockList);
            } else {
                $err += count($stockList);
                $lastError = $result['body']['message'] ?? ($result['body']['error'] ?? 'Unknown error');
            }
        }

        return ['ok' => $ok, 'err' => $err, 'last_error' => $lastError];
    }

    // ── Model-Aware Price Push Helper ───────────────────────────────

    private function pushPriceForLinks(ShopeeClient $client, object $setting, string $pfx, $links): array
    {
        $ok = 0;
        $err = 0;
        $lastError = '';

        // Group links by shopee_item_id
        $grouped = [];
        foreach ($links as $link) {
            $grouped[$link->shopee_item_id][] = $link;
        }

        foreach ($grouped as $itemId => $itemLinks) {
            $priceList = [];

            foreach ($itemLinks as $link) {
                $productId = (int) $link->product_id;
                $sku = trim((string) ($link->sku ?? ''));
                $modelId = (int) ($link->shopee_model_id ?? 0);

                // Base product price
                $basePrice = max(0, (float) (DB::table($pfx . 'product')->where('product_id', $productId)->value('price') ?? 0));
                $price = $basePrice;

                // If linked via option SKU, apply option price modifier (+ or -)
                if ($sku !== '') {
                    $pov = DB::table($pfx . 'product_option_value')
                        ->where('product_id', $productId)
                        ->where('sku', $sku)
                        ->first(['price', 'price_prefix']);

                    if ($pov && (float) $pov->price > 0) {
                        $modifier = (float) $pov->price;
                        $price = ($pov->price_prefix === '-')
                            ? max(0, $basePrice - $modifier)
                            : $basePrice + $modifier;
                    }
                }

                $priceList[] = ['model_id' => $modelId, 'original_price' => round($price, 2)];
            }

            if (empty($priceList)) continue;

            $path = '/api/v2/product/update_price';
            $payload = ['item_id' => (int) $itemId, 'price_list' => $priceList];

            $result = $client->shopPost(
                $setting->mode ?? 'sandbox',
                (int) $setting->partner_id, (string) $setting->partner_key,
                (string) $setting->access_token, (int) $setting->shop_id,
                $path, [], $payload
            );

            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.products.update_price', 'method' => 'POST', 'api_path' => $path,
                'auth_required' => true, 'request_params' => $payload,
                'response_status' => $result['status'] ?? null, 'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
            ]);

            $isOk = ($result['ok'] ?? false) && (($result['body']['error'] ?? '') === '' || ($result['body']['error'] ?? null) === null);
            $syncData = [
                'last_synced_at' => now(),
                'last_sync_action' => 'sync_price',
                'last_sync_ok' => $isOk,
                'last_sync_error_code' => $isOk ? null : (string) ($result['body']['error'] ?? ''),
                'last_sync_error_message' => $isOk ? null : (string) ($result['body']['message'] ?? ''),
            ];
            foreach ($itemLinks as $link) {
                $link->update($syncData);
            }

            if ($isOk) {
                $ok += count($priceList);
            } else {
                $err += count($priceList);
                $lastError = $result['body']['message'] ?? ($result['body']['error'] ?? 'Unknown error');
            }
        }

        return ['ok' => $ok, 'err' => $err, 'last_error' => $lastError];
    }

    // ── General Helpers ──────────────────────────────────────────────

    private function parseProductIds(Request $request): array
    {
        $ids = $request->input('product_ids', []);
        if (!is_array($ids)) $ids = [];
        return array_values(array_unique(array_filter(array_map(fn($v) => (int) $v, $ids), fn($v) => $v > 0)));
    }

    private function matchProductBySku(string $pfx, string $sku): int
    {
        if ($sku === '') return 0;

        // Try option value SKU
        $pov = DB::table($pfx . 'product_option_value')->where('sku', $sku)->first(['product_id']);
        if ($pov) return (int) $pov->product_id;

        // Try product model (main SKU)
        $prod = DB::table($pfx . 'product')->where('model', $sku)->first(['product_id']);
        if ($prod) return (int) $prod->product_id;

        // Try product sku column
        $prod = DB::table($pfx . 'product')->where('sku', $sku)->first(['product_id']);
        if ($prod) return (int) $prod->product_id;

        return 0;
    }

    private function findProfileForProduct(int $productId, ?int $manufacturerId): ?ShopeeProfile
    {
        $pfx = (string) config('catalog.prefix');
        $profiles = ShopeeProfile::all();

        // Get category IDs for this product
        $productCatIds = DB::table($pfx . 'product_to_category')
            ->where('product_id', $productId)
            ->pluck('category_id')
            ->toArray();

        foreach ($profiles as $profile) {
            $catIds = $profile->catalog_category_ids ?? [];
            $mfgIds = $profile->manufacturer_ids ?? [];

            // If profile has no filters, it matches all products
            if (empty($catIds) && empty($mfgIds)) {
                return $profile;
            }

            $catMatch = empty($catIds) || !empty(array_intersect($catIds, $productCatIds));
            $mfgMatch = empty($mfgIds) || in_array($manufacturerId, $mfgIds);

            if ($catMatch && $mfgMatch) {
                return $profile;
            }
        }

        return null;
    }

    private function getProfileProductMapping(): array
    {
        $profiles = ShopeeProfile::all();
        if ($profiles->isEmpty()) return ['ids' => null, 'map' => []];

        $pfx = (string) config('catalog.prefix');
        $allIds = collect();
        $map = [];

        foreach ($profiles as $profile) {
            $catIds = $profile->catalog_category_ids ?? [];
            $mfgIds = $profile->manufacturer_ids ?? [];

            $q = DB::table($pfx . 'product as p')->select('p.product_id');

            if (!empty($catIds)) {
                $q->join($pfx . 'product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                    ->whereIn('ptc.category_id', $catIds);
            }
            if (!empty($mfgIds)) {
                $q->whereIn('p.manufacturer_id', $mfgIds);
            }

            $pids = $q->distinct()->pluck('p.product_id');
            $allIds = $allIds->merge($pids);

            foreach ($pids as $pid) {
                $map[(int) $pid][] = $profile->name;
            }
        }

        return ['ids' => $allIds->unique()->values()->all(), 'map' => $map];
    }

    private function getProductIdsByProfile(int $profileId): array
    {
        $profile = ShopeeProfile::find($profileId);
        if (!$profile) return [];

        $pfx = (string) config('catalog.prefix');
        $catIds = $profile->catalog_category_ids ?? [];
        $mfgIds = $profile->manufacturer_ids ?? [];

        $q = DB::table($pfx . 'product as p')->select('p.product_id');

        if (!empty($catIds)) {
            $q->join($pfx . 'product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                ->whereIn('ptc.category_id', $catIds);
        }
        if (!empty($mfgIds)) {
            $q->whereIn('p.manufacturer_id', $mfgIds);
        }

        return $q->distinct()->pluck('p.product_id')->all();
    }

    private function resolveLocalImagePath(string $path): ?string
    {
        $try = [
            storage_path('app/public/' . $path),
            public_path('image/' . $path),
            public_path($path),
        ];
        foreach ($try as $p) {
            if (file_exists($p)) return $p;
        }
        return null;
    }

    private function uploadImage(ShopeeClient $client, object $setting, string $localPath): array
    {
        $path = '/api/v2/media_space/upload_image';
        $timestamp = time();
        $sign = $client->signShop(
            (int) $setting->partner_id, (string) $setting->partner_key,
            $path, $timestamp, (string) $setting->access_token, (int) $setting->shop_id
        );

        $query = http_build_query([
            'partner_id' => (int) $setting->partner_id, 'timestamp' => $timestamp,
            'sign' => $sign, 'access_token' => (string) $setting->access_token,
            'shop_id' => (int) $setting->shop_id,
        ]);

        $url = $client->baseUrl($setting->mode ?? 'sandbox') . $path . '?' . $query;

        $response = Http::timeout(30)
            ->attach('image', file_get_contents($localPath), basename($localPath))
            ->post($url);

        return ['status' => $response->status(), 'ok' => $response->ok(), 'body' => $response->json() ?? $response->body()];
    }

    private function fetchLogistics(): array
    {
        return ShopeeLogistic::query()
            ->orderByDesc('enabled')
            ->orderBy('logistics_channel_name')
            ->get()
            ->map(fn($ch) => [
                'logistic_id' => $ch->logistics_channel_id,
                'logistic_name' => $ch->logistics_channel_name,
                'enabled' => $ch->enabled,
            ])
            ->toArray();
    }

    /**
     * Fetch all Shopee item IDs across 15-day time windows (API limit).
     * Scans NORMAL + UNLIST statuses, going back $days days.
     */
    private function fetchAllShopeeItemIds(ShopeeClient $client, object $setting, int $days = 730, ?string $logPack = null): array
    {
        $now = time();
        $allItemIds = [];
        $windowSize = 15 * 86400; // 15 days in seconds
        $startFrom = $now - ($days * 86400);

        // Scan by both update_time AND create_time to catch all items.
        // Items created long ago that were never updated via API would be missed
        // by update_time-only scanning.
        $timeFields = [
            ['update_time_from', 'update_time_to'],
            ['create_time_from', 'create_time_to'],
        ];

        foreach ($timeFields as [$fromKey, $toKey]) {
            foreach (['NORMAL', 'UNLIST'] as $itemStatus) {
                $windowStart = $startFrom;

                while ($windowStart < $now) {
                    $windowEnd = min($windowStart + $windowSize, $now);
                    $offset = 0;

                    while (true) {
                        $result = $client->shopGet(
                            $setting->mode ?? 'sandbox',
                            (int) $setting->partner_id, (string) $setting->partner_key,
                            (string) $setting->access_token, (int) $setting->shop_id,
                            '/api/v2/product/get_item_list',
                            ['offset' => $offset, 'page_size' => 100, $fromKey => $windowStart, $toKey => $windowEnd, 'item_status' => $itemStatus]
                        );

                        if ($logPack) {
                            ShopeeApiLog::safeCreate([
                                'pack' => $logPack, 'method' => 'GET',
                                'api_path' => '/api/v2/product/get_item_list', 'auth_required' => true,
                                'request_params' => ['offset' => $offset, 'item_status' => $itemStatus, 'time_field' => $fromKey, 'from' => date('Y-m-d', $windowStart), 'to' => date('Y-m-d', $windowEnd)],
                                'response_status' => $result['status'] ?? null,
                                'ok' => (bool) ($result['ok'] ?? false), 'response_body' => $result['body'] ?? null,
                                'user_id' => auth()->id(),
                            ]);
                        }

                        if (!($result['ok'] ?? false)) break;

                        $response = ($result['body'] ?? [])['response'] ?? ($result['body'] ?? []);
                        $items = $response['item'] ?? [];
                        foreach ($items as $item) {
                            if ($id = $item['item_id'] ?? null) $allItemIds[] = (int) $id;
                        }

                        if (!($response['has_next_page'] ?? false) || empty($items)) break;
                        $offset = (int) ($response['next_offset'] ?? $offset + 100);
                    }

                    $windowStart = $windowEnd;
                }
            }
        }

        return array_values(array_unique($allItemIds));
    }

}
