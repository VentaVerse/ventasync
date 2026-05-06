<?php

namespace Extensions\shopee\Controllers;

use App\Http\Controllers\Controller;

use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeCategory;
use Extensions\shopee\Models\ShopeeItemCache;
use Extensions\shopee\Models\ShopeeLogistic;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeProductGroup;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Models\ShopeeUnmatchedItem;
use App\Services\ActivityLogger;
use Extensions\shopee\Services\Shopee\ShopeeClient;
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
        $groupFilter = (string) $request->get('group', 'all');
        $erpStatus = (string) $request->get('erp_status', 'all');

        // Build product group → product mapping
        $groupMapping = $this->getGroupProductMapping();

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

        // Product group filter
        if ($groupFilter === 'none') {
            // Show products NOT matching any product group
            $allMatchedIds = $groupMapping['ids'];
            if ($allMatchedIds === null || empty($allMatchedIds)) {
                // No product groups or no matches — show all products
            } else {
                $query->whereNotIn('p.product_id', $allMatchedIds);
            }
        } elseif ($groupFilter !== 'all') {
            // Show products matching a specific product group
            $specificIds = $this->getProductIdsByGroup((int) $groupFilter);
            if (empty($specificIds)) {
                $query->whereRaw('1=0');
            } else {
                $query->whereIn('p.product_id', $specificIds);
            }
        } else {
            // Default: show products matching at least one product group + products with existing Shopee links
            $allMatchedIds = $groupMapping['ids'] ?? [];
            $linkedIds = ShopeeProductLink::query()->pluck('product_id')->unique()->toArray();
            $allVisibleIds = array_values(array_unique(array_merge($allMatchedIds, $linkedIds)));
            if (empty($allVisibleIds)) {
                $query->whereRaw('1=0');
            } else {
                $query->whereIn('p.product_id', $allVisibleIds);
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
                    'pov.absolute_price as option_absolute_price',
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

        // Product group list for filter dropdown
        $allGroups = ShopeeProductGroup::query()->orderBy('name')->pluck('name', 'id');

        // Compute product group names for products on this page
        $groupMap = $groupMapping['map'];
        $pageGroupMap = collect();
        foreach ($products as $p) {
            $pid = (int) $p->product_id;
            $pageGroupMap[$pid] = $groupMap[$pid] ?? [];
        }

        return view('ext-shopee::products.index', [
            'products' => $products,
            'q' => $q,
            'shopeeLinks' => $shopeeLinks,
            'syncStatus' => $syncStatus,
            'manufacturerFilter' => $manufacturerFilter,
            'groupFilter' => $groupFilter,
            'erpStatus' => $erpStatus,
            'allManufacturers' => $allManufacturers,
            'allGroups' => $allGroups,
            'groupsByProductId' => $pageGroupMap,
            'unmatchedItems' => $unmatchedItems,
            'optionRowsByProductId' => $optionRowsByProductId,
        ]);
    }

    public function addProduct(Request $request)
    {
        $shopeeCategories = ShopeeCategory::query()
            ->where('leaf', true)
            ->orderBy('name')
            ->limit(5000)
            ->get(['category_id', 'name']);

        return view('ext-shopee::products.add', [
            'shopeeCategories' => $shopeeCategories,
        ]);
    }

    public function pushForm(Request $request, int $productId, ShopeeClient $client)
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

        // Check product status by fetching it separately (not in the join query)
        $erpStatus = DB::table($pfx . 'product')->where('product_id', $productId)->value('status');
        if ((int) $erpStatus === 0) {
            return redirect()->route('ext.shopee.products.index')
                ->with('error', 'Cannot push a disabled product to Shopee. Enable it first.');
        }

        $product->name = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $existingLink = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if ($existingLink) {
            return redirect()->route('ext.shopee.products.index')
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

        // Find matching product group for this product to pre-select logistics & category + markup
        $matchingGroup = $this->findGroupForProduct($productId, $product->manufacturer_id);
        $groupLogisticIds = $matchingGroup ? ($matchingGroup->logistic_ids ?? []) : [];
        $groupCategoryId = $matchingGroup ? ($matchingGroup->shopee_category_id ?? null) : null;

        // Apply product group price markup (query params from Add Product page override group)
        $basePrice = (float) $product->price;
        $qMarkupPct = $request->query('markup_percent');
        $qMarkupFixed = $request->query('markup_fixed');
        $qCategoryId = $request->query('category_id');

        if ($qMarkupPct !== null || $qMarkupFixed !== null) {
            $markupPct = $qMarkupPct !== null ? (float) $qMarkupPct : 0;
            $markupFixed = $qMarkupFixed !== null ? (float) $qMarkupFixed : 0;
        } else {
            $markupPct = $matchingGroup ? (float) ($matchingGroup->markup_percent ?? 0) : 0;
            $markupFixed = $matchingGroup ? (float) ($matchingGroup->markup_fixed ?? 0) : 0;
        }
        $suggestedPrice = round($basePrice + ($basePrice * $markupPct / 100) + $markupFixed, 2);

        if ($qCategoryId !== null) {
            $groupCategoryId = (int) $qCategoryId ?: null;
        }

        return view('ext-shopee::products.push', [
            'product' => $product,
            'images' => $images,
            'shopeeCategories' => $shopeeCategories,
            'logistics' => $logistics,
            'groupLogisticIds' => $groupLogisticIds,
            'groupCategoryId' => $groupCategoryId,
            'groupName' => $matchingGroup ? $matchingGroup->name : null,
            'suggestedPrice' => $suggestedPrice,
        ]);
    }

    public function push(Request $request, int $productId, ShopeeClient $client)
    {
        $request->validate([
            'item_name' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'original_price' => 'required|numeric|min:0.01',
            'normal_stock' => 'required|integer|min:0', // mapped to seller_stock in payload
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

        // Skip disabled products
        $pfx = (string) config('catalog.prefix');
        $erpStatus = DB::table($pfx . 'product')->where('product_id', $productId)->value('status');
        if ((int) $erpStatus === 0) {
            return redirect()->back()
                ->with('error', 'Cannot push a disabled product to Shopee. Enable it first.');
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
                return redirect()->route('ext.shopee.products.push', $productId)
                    ->with('error', 'Image upload failed for "' . basename($imgPath) . '": ' . $msg)
                    ->withInput();
            }

            $imageId = $uploadResult['body']['response']['image_info']['image_id'] ?? null;
            if ($imageId) {
                $imageIds[] = (string) $imageId;
            }
        }

        if (empty($imageIds)) {
            return redirect()->route('ext.shopee.products.push', $productId)
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
            'seller_stock' => [['stock' => (int) $request->input('normal_stock')]],
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
            return redirect()->route('ext.shopee.products.push', $productId)
                ->with('error', 'Push failed: ' . $msg)->withInput();
        }

        $itemId = $body['response']['item_id'] ?? null;
        if (!$itemId) {
            return redirect()->route('ext.shopee.products.push', $productId)
                ->with('error', 'Push succeeded but no item_id returned. Check API logs.')->withInput();
        }

        ShopeeProductLink::create([
            'product_id' => $productId,
            'shopee_item_id' => (int) $itemId,
            'shopee_model_id' => null,
            'sku' => $request->input('item_sku') ?? '',
        ]);

        return redirect()->route('ext.shopee.products.index')
            ->with('status', 'Product pushed to Shopee. Item ID: ' . $itemId);
    }

    // ── Push Direct (auto-populate from product + product group) ─────────

    public function pushDirect(int $productId, ShopeeClient $client)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->back()->with('error', 'Push failed: Missing Shopee settings.');
        }

        $existingLink = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if ($existingLink) {
            return redirect()->back()->with('error', 'Push failed: Product already listed on Shopee (Item ID: ' . $existingLink->shopee_item_id . ').');
        }

        $product = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                  ->where('pd.language_id', '=', $langId);
            })
            ->where('p.product_id', $productId)
            ->first([
                'p.product_id', 'pd.name', 'pd.description', 'p.image',
                'p.sku', 'p.price', 'p.quantity', 'p.status',
                'p.weight', 'p.length', 'p.width', 'p.height',
                'p.manufacturer_id',
            ]);

        if (!$product) {
            return redirect()->back()->with('error', 'Push failed: Product not found.');
        }

        if ((int) $product->status === 0) {
            return redirect()->back()->with('error', 'Push failed: Product is disabled. Enable it first.');
        }

        // Resolve product group for category + logistics
        $group = $this->findGroupForProduct($productId, $product->manufacturer_id);
        if (!$group || !$group->shopee_category_id) {
            return redirect()->back()->with('error', 'Push failed: No Shopee Product Group with a category found for this product. Assign a product group first or use the push form.');
        }

        $logisticIds = $group->logistic_ids ?? [];
        if (empty($logisticIds)) {
            return redirect()->back()->with('error', 'Push failed: Product Group "' . $group->name . '" has no logistics channels configured.');
        }

        // Validate required fields
        $itemName = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $description = trim(strip_tags(html_entity_decode($product->description ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (trim($itemName) === '' || trim($description) === '') {
            return redirect()->back()->with('error', 'Push failed: Product name or description is empty.');
        }
        if ((float) $product->price <= 0) {
            return redirect()->back()->with('error', 'Push failed: Product price must be greater than 0.');
        }
        if ((float) $product->weight <= 0) {
            return redirect()->back()->with('error', 'Push failed: Product weight must be greater than 0.');
        }
        $pkgLength = max(1, (int) $product->length);
        $pkgWidth = max(1, (int) $product->width);
        $pkgHeight = max(1, (int) $product->height);

        // Step 1: Upload images
        $imagePaths = [];
        if (!empty($product->image)) {
            $imagePaths[] = $product->image;
        }
        $additionalImages = DB::table($pfx . 'product_image')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->pluck('image')
            ->toArray();
        $imagePaths = array_merge($imagePaths, $additionalImages);

        if (empty($imagePaths)) {
            return redirect()->back()->with('error', 'Push failed: Product has no images.');
        }

        $imageIds = [];
        foreach ($imagePaths as $imgPath) {
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
                return redirect()->back()->with('error', 'Push failed: Image upload failed for "' . basename($imgPath) . '": ' . $msg);
            }

            $imageId = $uploadResult['body']['response']['image_info']['image_id'] ?? null;
            if ($imageId) {
                $imageIds[] = (string) $imageId;
            }
        }

        if (empty($imageIds)) {
            return redirect()->back()->with('error', 'Push failed: No images were uploaded successfully.');
        }

        // Step 2: Build add_item payload
        $logisticInfo = [];
        foreach ($logisticIds as $lid) {
            $logisticInfo[] = ['logistic_id' => (int) $lid, 'enabled' => true];
        }

        // Apply product group price markup
        $basePrice = (float) $product->price;
        $markupPct = (float) ($group->markup_percent ?? 0);
        $markupFixed = (float) ($group->markup_fixed ?? 0);
        $sellingPrice = round($basePrice + ($basePrice * $markupPct / 100) + $markupFixed, 2);

        $payload = [
            'original_price' => $sellingPrice,
            'description' => mb_substr($description, 0, 5000),
            'item_name' => mb_substr($itemName, 0, 255),
            'seller_stock' => [['stock' => max(0, (int) $product->quantity)]],
            'item_sku' => (string) ($product->sku ?? ''),
            'weight' => (float) $product->weight,
            'dimension' => [
                'package_length' => $pkgLength,
                'package_width' => $pkgWidth,
                'package_height' => $pkgHeight,
            ],
            'category_id' => (int) $group->shopee_category_id,
            'image' => ['image_id_list' => $imageIds],
            'logistic_info' => $logisticInfo,
            'brand' => ['brand_id' => 0, 'original_brand_name' => 'No Brand'],
        ];

        $path = '/api/v2/product/add_item';
        $result = $client->shopPost(
            $setting->mode ?? 'live',
            (int) $setting->partner_id, (string) $setting->partner_key,
            (string) $setting->access_token, (int) $setting->shop_id,
            $path, [], $payload
        );

        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.products.add_item.direct', 'method' => 'POST', 'api_path' => $path,
            'auth_required' => true, 'request_params' => $payload,
            'response_status' => $result['status'] ?? null, 'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        $body = $result['body'] ?? null;
        if (!($result['ok'] ?? false)) {
            $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'API call failed')) : 'API call failed';
            return redirect()->back()->with('error', 'Push failed: ' . $msg);
        }

        $itemId = $body['response']['item_id'] ?? null;
        if (!$itemId) {
            return redirect()->back()->with('error', 'Push succeeded but no item_id returned. Check API logs.');
        }

        ShopeeProductLink::create([
            'product_id' => $productId,
            'shopee_item_id' => (int) $itemId,
            'shopee_model_id' => null,
            'sku' => $product->sku ?? '',
        ]);

        return redirect()->back()->with('status', 'Product pushed to Shopee. Item ID: ' . $itemId);
    }

    // ── Bulk Push to Shopee ───────────────────────────────────────

    public function bulkPushToShopee(Request $request, ShopeeClient $client)
    {
        $productIds = array_map('intval', array_filter($request->input('product_ids', [])));
        if (empty($productIds)) {
            return redirect()->back()->with('error', 'No products selected.');
        }

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->back()->with('error', 'Missing Shopee credentials. Configure Shopee settings first.');
        }

        $okCount = 0;
        $skipCount = 0;
        $errCount = 0;
        $errors = [];

        foreach ($productIds as $productId) {
            // Skip already linked
            if (ShopeeProductLink::query()->where('product_id', $productId)->exists()) {
                $skipCount++;
                continue;
            }

            $product = DB::table($pfx . 'product as p')
                ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                    $j->on('p.product_id', '=', 'pd.product_id')
                      ->where('pd.language_id', '=', $langId);
                })
                ->where('p.product_id', $productId)
                ->first([
                    'p.product_id', 'pd.name', 'pd.description', 'p.image',
                    'p.sku', 'p.price', 'p.quantity', 'p.status',
                    'p.weight', 'p.length', 'p.width', 'p.height',
                    'p.manufacturer_id',
                ]);

            if (!$product || (int) $product->status === 0) {
                $skipCount++;
                continue;
            }

            $group = $this->findGroupForProduct($productId, $product->manufacturer_id);
            if (!$group || !$group->shopee_category_id || empty($group->logistic_ids ?? [])) {
                $errors[] = "#{$productId}: No product group/category/logistics";
                $errCount++;
                continue;
            }

            $itemName = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $description = trim(strip_tags(html_entity_decode($product->description ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if (trim($itemName) === '' || trim($description) === '' || (float) $product->price <= 0 || (float) $product->weight <= 0) {
                $errors[] = "#{$productId}: Missing name/description/price/weight";
                $errCount++;
                continue;
            }

            // Upload images
            $imagePaths = [];
            if (!empty($product->image)) $imagePaths[] = $product->image;
            $additionalImages = DB::table($pfx . 'product_image')
                ->where('product_id', $productId)
                ->orderBy('sort_order')
                ->pluck('image')
                ->toArray();
            $imagePaths = array_merge($imagePaths, $additionalImages);

            if (empty($imagePaths)) {
                $errors[] = "#{$productId}: No images";
                $errCount++;
                continue;
            }

            $imageIds = [];
            $imgFailed = false;
            foreach ($imagePaths as $imgPath) {
                $localPath = $this->resolveLocalImagePath($imgPath);
                if (!$localPath || !file_exists($localPath)) continue;

                $uploadResult = $this->uploadImage($client, $setting, $localPath);
                if ($uploadResult['ok'] ?? false) {
                    $imgId = $uploadResult['body']['response']['image_info']['image_id'] ?? null;
                    if ($imgId) $imageIds[] = (string) $imgId;
                } else {
                    $imgFailed = true;
                    break;
                }
            }

            if (empty($imageIds)) {
                $errors[] = "#{$productId}: Image upload failed";
                $errCount++;
                continue;
            }

            // Build payload
            $logisticInfo = [];
            foreach ($group->logistic_ids as $lid) {
                $logisticInfo[] = ['logistic_id' => (int) $lid, 'enabled' => true];
            }

            $basePrice = (float) $product->price;
            $sellingPrice = round($basePrice + ($basePrice * (float) ($group->markup_percent ?? 0) / 100) + (float) ($group->markup_fixed ?? 0), 2);

            $payload = [
                'original_price' => $sellingPrice,
                'description' => mb_substr($description, 0, 5000),
                'item_name' => mb_substr($itemName, 0, 255),
                'seller_stock' => [['stock' => max(0, (int) $product->quantity)]],
                'item_sku' => (string) ($product->sku ?? ''),
                'weight' => (float) $product->weight,
                'dimension' => [
                    'package_length' => max(1, (int) $product->length),
                    'package_width' => max(1, (int) $product->width),
                    'package_height' => max(1, (int) $product->height),
                ],
                'category_id' => (int) $group->shopee_category_id,
                'image' => ['image_id_list' => $imageIds],
                'logistic_info' => $logisticInfo,
                'brand' => ['brand_id' => 0, 'original_brand_name' => 'No Brand'],
            ];

            $path = '/api/v2/product/add_item';
            $result = $client->shopPost(
                $setting->mode ?? 'live',
                (int) $setting->partner_id, (string) $setting->partner_key,
                (string) $setting->access_token, (int) $setting->shop_id,
                $path, [], $payload
            );

            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.products.add_item.bulk', 'method' => 'POST', 'api_path' => $path,
                'auth_required' => true, 'request_params' => $payload,
                'response_status' => $result['status'] ?? null, 'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
            ]);

            $body = $result['body'] ?? [];
            $itemId = ($result['ok'] ?? false) ? ($body['response']['item_id'] ?? null) : null;

            if ($itemId) {
                ShopeeProductLink::create([
                    'product_id' => $productId,
                    'shopee_item_id' => (int) $itemId,
                    'shopee_model_id' => null,
                    'sku' => $product->sku ?? '',
                ]);
                $okCount++;
            } else {
                $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'API error')) : 'API error';
                $errors[] = "#{$productId}: {$msg}";
                $errCount++;
            }

            usleep(300000); // 300ms rate limit between pushes
        }

        $summary = "Bulk Push: {$okCount} pushed";
        if ($skipCount > 0) $summary .= ", {$skipCount} skipped (already linked or disabled)";
        if ($errCount > 0) $summary .= ", {$errCount} failed";
        if (!empty($errors)) $summary .= '. Errors: ' . implode('; ', array_slice($errors, 0, 5));

        return redirect()->back()->with($okCount > 0 ? 'status' : 'error', $summary);
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
                    // Product has options — skip parent-level link entirely.
                    // If model cache entries exist, they'll match option SKUs above.
                    // If not, leave SKUs in $remaining so Steps 3-4 fetch models from API.
                    continue;
                }
            }

            $targetModelId = $isParent ? null : (int) $hit->shopee_model_id;

            $alreadyLinked = ShopeeProductLink::query()
                ->where('shopee_item_id', $hit->shopee_item_id)
                ->where('product_id', $productId)
                ->where('shopee_model_id', $targetModelId)
                ->exists();

            if ($alreadyLinked) {
                $skipped++;
            } else {
                ShopeeProductLink::create([
                    'product_id' => $productId,
                    'shopee_item_id' => (int) $hit->shopee_item_id,
                    'shopee_model_id' => $targetModelId,
                    'sku' => $hit->sku,
                ]);
                $linked++;
            }
            unset($remaining[$lcSku]);
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

        // Skip disabled products
        $erpStatus = DB::table($pfx . 'product')->where('product_id', $productId)->value('status');
        if ((int) $erpStatus === 0) {
            return redirect()->back()->with('error', 'Cannot sync quantity for a disabled product. Enable it first.');
        }

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

    // ── Delete Product from Shopee ────────────────────────────────

    public function deleteFromShopee(int $productId, ShopeeClient $client)
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->back()->with('error', 'Missing Shopee credentials. Configure Shopee settings first.');
        }

        $link = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if (!$link || !$link->shopee_item_id) {
            return redirect()->back()->with('error', 'Product is not linked to a Shopee item.');
        }

        $itemId = (int) $link->shopee_item_id;
        $path = '/api/v2/product/delete_item';

        $result = $client->shopPost(
            $setting->mode ?? 'live',
            (int) $setting->partner_id,
            (string) $setting->partner_key,
            (string) $setting->access_token,
            (int) $setting->shop_id,
            $path,
            [],
            ['item_id' => $itemId]
        );

        ShopeeApiLog::safeCreate([
            'pack'            => 'shopee.product.delete_item',
            'method'          => 'POST',
            'api_path'        => $path,
            'auth_required'   => true,
            'request_params'  => ['item_id' => $itemId, 'product_id' => $productId],
            'response_status' => (int) ($result['status'] ?? 0),
            'ok'              => (bool) ($result['ok'] ?? false),
            'response_body'   => $result['body'] ?? null,
        ]);

        $body = $result['body'] ?? [];
        $respError = is_array($body) ? ($body['error'] ?? '') : '';
        $respMsg = is_array($body) ? ($body['message'] ?? '') : '';

        if (($result['ok'] ?? false) && $respError === '') {
            // Remove links and cache entries for this item
            ShopeeItemCache::query()->where('shopee_item_id', $link->shopee_item_id)->delete();
            ShopeeProductLink::query()->where('product_id', $productId)->delete();

            ActivityLogger::log('deleted', 'Shopee Product', $productId, 'Deleted item ' . $itemId . ' from Shopee');

            return redirect()->back()->with('status', 'Product deleted from Shopee (item ' . $itemId . ').');
        }

        $errorMsg = $respError ?: $respMsg ?: 'Unknown error';
        return redirect()->back()->with('error', 'Delete failed: ' . $errorMsg);
    }

    public function bulkDeleteFromShopee(Request $request, ShopeeClient $client)
    {
        $productIds = $request->input('product_ids', []);
        if (!is_array($productIds) || empty($productIds)) {
            return redirect()->back()->with('error', 'No products selected.');
        }

        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->back()->with('error', 'Missing Shopee credentials. Configure Shopee settings first.');
        }

        $okCount = 0;
        $errCount = 0;
        $path = '/api/v2/product/delete_item';

        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            $link = ShopeeProductLink::query()->where('product_id', $productId)->first();
            if (!$link || !$link->shopee_item_id) {
                $errCount++;
                continue;
            }

            $itemId = (int) $link->shopee_item_id;

            $result = $client->shopPost(
                $setting->mode ?? 'live',
                (int) $setting->partner_id,
                (string) $setting->partner_key,
                (string) $setting->access_token,
                (int) $setting->shop_id,
                $path,
                [],
                ['item_id' => $itemId]
            );

            ShopeeApiLog::safeCreate([
                'pack'            => 'shopee.product.delete_item.bulk',
                'method'          => 'POST',
                'api_path'        => $path,
                'auth_required'   => true,
                'request_params'  => ['item_id' => $itemId, 'product_id' => $productId],
                'response_status' => (int) ($result['status'] ?? 0),
                'ok'              => (bool) ($result['ok'] ?? false),
                'response_body'   => $result['body'] ?? null,
            ]);

            $body = $result['body'] ?? [];
            $respError = is_array($body) ? ($body['error'] ?? '') : '';

            if (($result['ok'] ?? false) && $respError === '') {
                ShopeeItemCache::query()->where('shopee_item_id', $link->shopee_item_id)->delete();
                ShopeeProductLink::query()->where('product_id', $productId)->delete();
                $okCount++;
            } else {
                $errCount++;
            }

            usleep(200000); // 200ms rate limit
        }

        ActivityLogger::log('deleted', 'Shopee Product', null, 'Bulk deleted ' . $okCount . ' product(s) from Shopee');

        return redirect()->back()->with('status', "Bulk Delete: {$okCount} deleted, {$errCount} failed out of " . count($productIds) . " selected.");
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

    // ── Scan for Unmatched Shopee Items ────────────────────────────

    public function syncUnmatchedItems(ShopeeClient $client)
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->route('ext.shopee.products.index')->with('error', 'Missing Shopee settings.');
        }

        $pfx = (string) config('catalog.prefix');

        // 1. Fetch all Shopee item IDs
        $allItemIds = $this->fetchAllShopeeItemIds($client, $setting, 730, 'shopee.products.sync_unmatched');
        if (empty($allItemIds)) {
            return redirect()->route('ext.shopee.products.index')->with('error', 'No items found on Shopee.');
        }

        // 2. Get all linked item+model combos from ShopeeProductLink
        $linkedCombos = ShopeeProductLink::query()->get(['shopee_item_id', 'shopee_model_id']);
        $linkedSet = [];
        foreach ($linkedCombos as $lc) {
            $key = (string) $lc->shopee_item_id . ':' . (string) ($lc->shopee_model_id ?? '');
            $linkedSet[$key] = true;
        }

        // 3. Build ERP SKU set for matching (case-insensitive)
        $erpSkus = [];
        // Product model + sku fields
        $erpProducts = DB::table($pfx . 'product')->whereNotNull('model')->where('model', '!=', '')->get(['model', 'sku']);
        foreach ($erpProducts as $ep) {
            if ($ep->model !== null && $ep->model !== '') $erpSkus[strtolower(trim($ep->model))] = true;
            if ($ep->sku !== null && $ep->sku !== '') $erpSkus[strtolower(trim($ep->sku))] = true;
        }
        // Option value SKUs
        $optSkus = DB::table($pfx . 'product_option_value')
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->pluck('sku');
        foreach ($optSkus as $s) {
            $erpSkus[strtolower(trim($s))] = true;
        }

        // 4. Clear old unmatched entries
        ShopeeUnmatchedItem::query()->where('status', 'unmatched')->delete();

        $unmatchedCount = 0;
        $matchedCount = 0;
        $totalScanned = 0;

        // 5. Fetch item info in chunks and check matches
        foreach (array_chunk($allItemIds, 50) as $chunk) {
            $infoResult = $client->shopGet(
                $setting->mode ?? 'sandbox',
                (int) $setting->partner_id, (string) $setting->partner_key,
                (string) $setting->access_token, (int) $setting->shop_id,
                '/api/v2/product/get_item_base_info',
                ['item_id_list' => implode(',', $chunk)]
            );

            if (!($infoResult['ok'] ?? false)) continue;

            $itemList = (($infoResult['body'] ?? [])['response'] ?? ($infoResult['body'] ?? []))['item_list'] ?? [];

            foreach ($itemList as $item) {
                $itemId = (int) ($item['item_id'] ?? 0);
                $itemSku = trim((string) ($item['item_sku'] ?? ''));
                $hasModel = (bool) ($item['has_model'] ?? false);
                $itemName = trim((string) ($item['item_name'] ?? ''));
                $imageUrl = $item['image']['image_url_list'][0] ?? null;

                if (!$itemId) continue;
                $totalScanned++;

                if ($hasModel) {
                    // Fetch models for this item
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
                        if (!$modelId) continue;

                        $linkKey = $itemId . ':' . $modelId;

                        // Already linked?
                        if (isset($linkedSet[$linkKey])) {
                            $matchedCount++;
                            continue;
                        }

                        // SKU matches ERP?
                        if ($modelSku !== '' && isset($erpSkus[strtolower($modelSku)])) {
                            $matchedCount++;
                            continue;
                        }

                        // Unmatched model
                        $unmatchedCount++;
                        ShopeeUnmatchedItem::updateOrCreate(
                            ['shopee_item_id' => (string) $itemId, 'shopee_model_id' => (string) $modelId],
                            [
                                'item_name' => $itemName . ($modelSku ? ' [' . $modelSku . ']' : ''),
                                'sku' => $modelSku,
                                'image_url' => $imageUrl,
                                'status' => 'unmatched',
                                'linked_product_id' => null,
                            ]
                        );
                    }
                } else {
                    // Simple item (no models)
                    $linkKey = $itemId . ':';

                    if (isset($linkedSet[$linkKey])) {
                        $matchedCount++;
                        continue;
                    }

                    // SKU matches ERP?
                    if ($itemSku !== '' && isset($erpSkus[strtolower($itemSku)])) {
                        $matchedCount++;
                        continue;
                    }

                    // Unmatched item
                    $unmatchedCount++;
                    ShopeeUnmatchedItem::updateOrCreate(
                        ['shopee_item_id' => (string) $itemId, 'shopee_model_id' => null],
                        [
                            'item_name' => $itemName,
                            'sku' => $itemSku,
                            'image_url' => $imageUrl,
                            'status' => 'unmatched',
                            'linked_product_id' => null,
                        ]
                    );
                }
            }
        }

        return redirect()->route('ext.shopee.products.index')
            ->with('status', "Unmatched scan complete. Shopee items scanned: {$totalScanned}. Already matched: {$matchedCount}. Unmatched: {$unmatchedCount}.");
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

        // Filter out disabled products
        $enabledIds = DB::table($pfx . 'product')
            ->whereIn('product_id', $ids)
            ->where('status', 1)
            ->pluck('product_id')
            ->toArray();
        $disabledCount = count($ids) - count($enabledIds);

        $links = ShopeeProductLink::query()->whereIn('product_id', $enabledIds)->get();

        $ok = 0;
        $err = 0;
        $skip = count($enabledIds) - $links->pluck('product_id')->unique()->count();

        $results = $this->pushStockForLinks($client, $setting, $pfx, $links);
        $ok = $results['ok'];
        $err = $results['err'];

        $msg = "Bulk Sync Qty: {$ok} success, {$err} error, {$skip} skipped (not linked)";
        if ($disabledCount > 0) {
            $msg .= ", {$disabledCount} skipped (disabled)";
        }

        return redirect()->back()->with('status', $msg . '.');
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
                $productRow = DB::table($pfx . 'product')->where('product_id', $productId)->first(['price', 'manufacturer_id']);
                $basePrice = max(0, (float) ($productRow->price ?? 0));
                $price = $basePrice;

                // If linked via option SKU, use absolute_price
                if ($sku !== '') {
                    $pov = DB::table($pfx . 'product_option_value')
                        ->where('product_id', $productId)
                        ->where('sku', $sku)
                        ->first(['absolute_price']);

                    if ($pov && $pov->absolute_price !== null) {
                        $price = max(0, (float) $pov->absolute_price);
                    }
                }

                // Apply product group price markup
                $group = $this->findGroupForProduct($productId, $productRow->manufacturer_id ?? null);
                if ($group) {
                    $markupPct = (float) ($group->markup_percent ?? 0);
                    $markupFixed = (float) ($group->markup_fixed ?? 0);
                    $price = $price + ($price * $markupPct / 100) + $markupFixed;
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

    private function findGroupForProduct(int $productId, ?int $manufacturerId): ?ShopeeProductGroup
    {
        $pfx = (string) config('catalog.prefix');
        $groups = ShopeeProductGroup::all();

        // Get category IDs for this product
        $productCatIds = DB::table($pfx . 'product_to_category')
            ->where('product_id', $productId)
            ->pluck('category_id')
            ->toArray();

        foreach ($groups as $group) {
            $catIds = $group->catalog_category_ids ?? [];
            $mfgIds = $group->manufacturer_ids ?? [];

            // If product group has no filters, it matches all products
            if (empty($catIds) && empty($mfgIds)) {
                return $group;
            }

            $catMatch = empty($catIds) || !empty(array_intersect($catIds, $productCatIds));
            $mfgMatch = empty($mfgIds) || in_array($manufacturerId, $mfgIds);

            if ($catMatch && $mfgMatch) {
                return $group;
            }
        }

        return null;
    }

    private function getGroupProductMapping(): array
    {
        $groups = ShopeeProductGroup::all();
        if ($groups->isEmpty()) return ['ids' => null, 'map' => []];

        $pfx = (string) config('catalog.prefix');
        $allIds = collect();
        $map = [];

        foreach ($groups as $group) {
            $catIds = $group->catalog_category_ids ?? [];
            $mfgIds = $group->manufacturer_ids ?? [];

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
                $map[(int) $pid][] = $group->name;
            }
        }

        return ['ids' => $allIds->unique()->values()->all(), 'map' => $map];
    }

    private function getProductIdsByGroup(int $groupId): array
    {
        $group = ShopeeProductGroup::find($groupId);
        if (!$group) return [];

        $pfx = (string) config('catalog.prefix');
        $catIds = $group->catalog_category_ids ?? [];
        $mfgIds = $group->manufacturer_ids ?? [];

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

        $url = $client->baseUrl($setting->mode ?? 'live') . $path . '?' . $query;

        $contents = file_get_contents($localPath);
        if ($contents === false) {
            return ['status' => 0, 'ok' => false, 'body' => ['error' => 'file_read_failed', 'message' => 'Could not read image file: ' . basename($localPath)]];
        }

        $response = Http::timeout(30)
            ->attach('image', $contents, basename($localPath))
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
