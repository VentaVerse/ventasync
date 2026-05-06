<?php

namespace Extensions\lazada\Controllers;

use App\Http\Controllers\Controller;

use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaCategoryTemplate;
use Extensions\lazada\Models\LazadaCategory;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductAttribute;
use Extensions\lazada\Models\LazadaProductGroup;
use Extensions\lazada\Models\LazadaProductGroupAttribute;
use Extensions\lazada\Models\LazadaProductGroupProduct;
use Extensions\lazada\Models\LazadaProductVariant;
use Extensions\lazada\Models\LazadaSetting;
use App\Services\ActivityLogger;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LazadaProductGroupController extends Controller
{
    private function productsRedirect(int $id)
    {
        $return = request()->input('_return');
        if ($return && str_starts_with($return, '/')) {
            return redirect($return);
        }
        return redirect()->route('ext.lazada.product-groups.products', $id);
    }

    public function index()
    {
        $groups = LazadaProductGroup::query()->orderByDesc('id')->get();

        // Lazada category names
        $lazCatIds = $groups->pluck('lazada_category_id')->filter()->unique()->values()->all();
        $lazadaCategoryNames = collect();
        if (!empty($lazCatIds)) {
            $lazadaCategoryNames = LazadaCategory::query()
                ->whereIn('category_id', $lazCatIds)
                ->pluck('name', 'category_id');
        }

        // Product counts per group (via pivot)
        $productCounts = DB::table('lazada_product_group_products')
            ->selectRaw('lazada_product_group_id, COUNT(*) as cnt')
            ->groupBy('lazada_product_group_id')
            ->pluck('cnt', 'lazada_product_group_id');

        return view('ext-lazada::product-groups.index', [
            'groups' => $groups,
            'lazadaCategoryNames' => $lazadaCategoryNames,
            'productCounts' => $productCounts,
        ]);
    }

    public function create()
    {
        return $this->form(new LazadaProductGroup(), 'create');
    }

    public function edit(int $id)
    {
        $group = LazadaProductGroup::query()->findOrFail($id);
        return $this->form($group, 'edit');
    }

    private function form(LazadaProductGroup $group, string $mode)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $catalogCategories = DB::table($pfx . 'category_description')
            ->where('language_id', $langId)
            ->orderBy('name')
            ->get(['category_id', 'name']);

        $manufacturers = DB::table($pfx . 'manufacturer')
            ->orderBy('name')
            ->get(['manufacturer_id', 'name']);

        $lazadaCategories = LazadaCategory::query()->orderBy('name')->limit(5000)->get();

        // Template & attributes
        $template = null;
        $attributes = [];
        $saved = [];
        if ($group->lazada_category_id) {
            $template = LazadaCategoryTemplate::query()
                ->where('region', $this->region())
                ->where('primary_category_id', (int) $group->lazada_category_id)
                ->first();

            if ($template && $template->template_body) {
                $attributes = $this->extractAttributes($template->template_body);
            }

            if ($group->exists) {
                $saved = LazadaProductGroupAttribute::query()
                    ->where('lazada_product_group_id', $group->id)
                    ->pluck('value', 'attribute_key')
                    ->toArray();
            }
        }

        $selectedBrandName = '';
        if ($group->brand_id) {
            $brand = \Extensions\lazada\Models\LazadaBrand::query()->where('brand_id', $group->brand_id)->first();
            if ($brand) $selectedBrandName = $brand->name;
        }

        // Load group products for dual-listbox
        // On validation failure, restore from old('product_ids') so selections aren't lost
        $oldProductIds = old('product_ids');
        $groupProducts = collect();

        if (!empty($oldProductIds)) {
            $productIds = array_filter(array_map('intval', (array) $oldProductIds));
        } elseif ($group->exists) {
            $productIds = $group->groupProducts()->whereNotNull('product_id')->pluck('product_id')->toArray();
        } else {
            $productIds = [];
        }

        if (!empty($productIds)) {
            $groupProducts = DB::table($pfx . 'product as p')
                ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                    $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
                })
                ->whereIn('p.product_id', $productIds)
                ->select('p.product_id', 'pd.name', 'p.sku', 'p.model', 'p.quantity')
                ->orderBy('pd.name')
                ->get();
        }

        return view('ext-lazada::product-groups.form', [
            'group' => $group,
            'mode' => $mode,
            'catalogCategories' => $catalogCategories,
            'manufacturers' => $manufacturers,
            'lazadaCategories' => $lazadaCategories,
            'template' => $template,
            'attributes' => $attributes,
            'saved' => $saved,
            'selectedBrandName' => $selectedBrandName,
            'erpSourceFields' => LazadaProductController::ERP_SOURCE_FIELDS,
            'groupProducts' => $groupProducts,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'lazada_category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'no_brand' => 'nullable',
            'markup_fixed' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|min:1',
        ]);

        $group = LazadaProductGroup::create([
            'name' => $data['name'],
            'catalog_category_ids' => null,
            'manufacturer_ids' => null,
            'lazada_category_id' => $data['lazada_category_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'brand_name_override' => !empty($request->input('no_brand')) ? 'No Brand' : null,
            'markup_fixed' => $data['markup_fixed'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null,
        ]);

        $this->saveGroupAttributes($group, (array) $request->input('attributes', []));

        ActivityLogger::log('created', 'Lazada Product Group', $group->id, $group->name);

        // Add products from dual-listbox
        $productIds = array_map('intval', array_filter($data['product_ids'] ?? []));
        $this->addProductsToGroup($group, $productIds);

        return redirect()->route('ext.lazada.product-groups.edit', $group->id)
            ->with('status', 'Product group created. ' . count($productIds) . ' product(s) assigned.');
    }

    public function update(Request $request, int $id)
    {
        $group = LazadaProductGroup::query()->findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'lazada_category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'no_brand' => 'nullable',
            'markup_fixed' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|min:1',
        ]);

        $group->update([
            'name' => $data['name'],
            'catalog_category_ids' => null,
            'manufacturer_ids' => null,
            'lazada_category_id' => $data['lazada_category_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'brand_name_override' => !empty($request->input('no_brand')) ? 'No Brand' : null,
            'markup_fixed' => $data['markup_fixed'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null,
        ]);

        $this->saveGroupAttributes($group, (array) $request->input('attributes', []));

        ActivityLogger::log('updated', 'Lazada Product Group', $group->id, $group->name);

        // Sync product assignments
        $newProductIds = array_map('intval', array_filter($data['product_ids'] ?? []));
        $existingProductIds = $group->groupProducts()->whereNotNull('product_id')->pluck('product_id')->toArray();

        $toAdd = array_diff($newProductIds, $existingProductIds);
        $toRemove = array_diff($existingProductIds, $newProductIds);

        if (!empty($toAdd)) {
            $this->addProductsToGroup($group, $toAdd);
        }

        if (!empty($toRemove)) {
            $group->groupProducts()->whereIn('product_id', $toRemove)->delete();
        }

        return redirect()->route('ext.lazada.product-groups.edit', $group->id)
            ->with('status', 'Product group saved.');
    }

    public function destroy(int $id)
    {
        $group = LazadaProductGroup::query()->findOrFail($id);

        // Get lazada_product_ids linked to this group before deletion
        $linkedLpIds = $group->groupProducts()->pluck('lazada_product_id')->filter()->toArray();

        $group->delete();

        ActivityLogger::log('deleted', 'Lazada Product Group', (int) $id, $group->name);

        // Check which lazada products no longer have any group links
        $removed = 0;
        $kept = 0;
        if (!empty($linkedLpIds)) {
            $stillLinked = DB::table('lazada_product_group_products')
                ->whereIn('lazada_product_id', $linkedLpIds)
                ->pluck('lazada_product_id')
                ->unique()
                ->toArray();

            $orphanIds = array_diff($linkedLpIds, $stillLinked);

            if (!empty($orphanIds)) {
                $removed = LazadaProduct::query()
                    ->whereIn('id', $orphanIds)
                    ->whereNull('lazada_item_id')
                    ->delete();
            }
            $kept = count($stillLinked);
        }

        $parts = ['Product group "' . e($group->name) . '" deleted.'];
        if ($removed > 0) $parts[] = "{$removed} product(s) removed.";
        if ($kept > 0) $parts[] = "{$kept} product(s) still linked to other groups.";

        return redirect()->route('ext.lazada.product-groups.index')
            ->with('status', implode(' ', $parts));
    }

    /**
     * Products page for a specific product group.
     */
    public function products(Request $request, int $id)
    {
        $group = LazadaProductGroup::query()->findOrFail($id);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $q = trim((string) $request->input('q'));
        $syncStatus = (string) $request->input('sync_status', 'all');
        $erpStatus = (string) $request->input('erp_status', 'all');

        $query = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->join('lazada_product_group_products as gp', function ($j) use ($group) {
                $j->on('p.product_id', '=', 'gp.product_id')
                    ->where('gp.lazada_product_group_id', '=', $group->id);
            })
            ->leftJoin('lazada_products as lp', 'gp.lazada_product_id', '=', 'lp.id')
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->select(
                'p.product_id', 'pd.name', 'p.sku', 'p.model', 'p.price',
                'p.quantity', 'p.image', 'p.status',
                'm.name as manufacturer_name',
                'lp.lazada_item_id',
                'gp.sync_status', 'gp.last_pushed_at', 'gp.push_error'
            );

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%")
                    ->orWhere('p.model', 'like', "%{$q}%");
            });
        }

        if ($erpStatus === 'enabled') {
            $query->where('p.status', 1);
        } elseif ($erpStatus === 'disabled') {
            $query->where('p.status', 0);
        }

        if ($syncStatus === 'pushed') {
            $query->whereNotNull('lp.lazada_item_id')->where('lp.lazada_item_id', '!=', '')
                ->where('gp.sync_status', '!=', 'unlinked');
        } elseif ($syncStatus === 'pending') {
            $query->where(function ($w) {
                $w->whereNull('lp.lazada_item_id')->orWhere('lp.lazada_item_id', '');
            })->whereNotIn('gp.sync_status', ['error', 'unlinked']);
        } elseif ($syncStatus === 'error') {
            $query->where('gp.sync_status', 'error');
        }

        $products = $query->orderBy('p.product_id', 'desc')->paginate(50)->appends($request->except('page'));

        // Pivot map for templates
        $pivotProductIds = $products->pluck('product_id')->toArray();
        $pivotMap = LazadaProductGroupProduct::query()
            ->where('lazada_product_group_id', $group->id)
            ->whereIn('product_id', $pivotProductIds)
            ->get()
            ->keyBy('product_id');

        // Option rows
        $optionRowsByProductId = collect();
        if (!empty($pivotProductIds)) {
            $optionRowsByProductId = DB::table($pfx . 'product_option_value as pov')
                ->join($pfx . 'option_description as od', function ($j) use ($langId) {
                    $j->on('pov.option_id', '=', 'od.option_id')->where('od.language_id', '=', $langId);
                })
                ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                    $j->on('pov.option_value_id', '=', 'ovd.option_value_id')->where('ovd.language_id', '=', $langId);
                })
                ->whereIn('pov.product_id', $pivotProductIds)
                ->select(
                    'pov.product_id', 'pov.product_option_value_id',
                    'od.name as option_name', 'ovd.name as option_value_name',
                    'pov.quantity as option_quantity', 'pov.sku as option_sku',
                    'pov.absolute_price as option_absolute_price'
                )
                ->orderBy('pov.product_id')
                ->orderBy('pov.product_option_value_id')
                ->get()
                ->groupBy('product_id');
        }

        // All product IDs in this group
        $manualIds = $group->groupProducts()->whereNotNull('product_id')->pluck('product_id')->toArray();

        return view('ext-lazada::product-groups.products', [
            'group' => $group,
            'products' => $products,
            'pivotMap' => $pivotMap,
            'optionRowsByProductId' => $optionRowsByProductId,
            'manualIds' => $manualIds,
            'q' => $q,
            'syncStatus' => $syncStatus,
            'erpStatus' => $erpStatus,
        ]);
    }

    /**
     * AJAX search for dual-listbox product picker.
     */
    public function searchProducts(Request $request)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $q = trim((string) $request->input('q'));
        $categoryId = (int) $request->input('category_id', 0);
        $manufacturerId = (int) $request->input('manufacturer_id', 0);

        if (strlen($q) < 2 && !$categoryId && !$manufacturerId) {
            return response()->json(['items' => []]);
        }

        $query = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->select('p.product_id', 'pd.name', 'p.sku', 'p.model', 'p.price', 'p.quantity');

        if ($categoryId > 0) {
            $query->join($pfx . 'product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                ->where('ptc.category_id', $categoryId);
        }

        if ($manufacturerId > 0) {
            $query->where('p.manufacturer_id', $manufacturerId);
        }

        if (strlen($q) >= 2) {
            $query->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%")
                    ->orWhere('p.model', 'like', "%{$q}%");
            });
        }

        $rows = $query->distinct()->orderBy('p.product_id', 'desc')->limit(100)->get();

        return response()->json(['items' => $rows]);
    }

    /**
     * Add products manually to a group.
     */
    public function addProducts(Request $request, int $id)
    {
        $group = LazadaProductGroup::query()->findOrFail($id);

        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));
        if (empty($ids)) {
            return $this->productsRedirect($group->id)
                ->with('error', 'No products selected.');
        }

        $added = $this->addProductsToGroup($group, $ids);

        return $this->productsRedirect($group->id)
            ->with('status', "{$added} product(s) added to group.");
    }

    /**
     * Unlink a product (keep in group but mark as unlinked).
     */
    public function unlinkProduct(int $groupId, int $productId)
    {
        $group = LazadaProductGroup::query()->findOrFail($groupId);

        $group->groupProducts()
            ->where('product_id', $productId)
            ->update(['sync_status' => 'unlinked']);

        return $this->productsRedirect($group->id)
            ->with('status', 'Product unlinked from Lazada.');
    }

    /**
     * Re-link a previously unlinked product.
     */
    /**
     * Sync Lazada item ID for a single product by matching seller_sku via /products/get API.
     */
    public function syncId(int $groupId, int $productId)
    {
        $group = LazadaProductGroup::query()->findOrFail($groupId);
        $pivot = $group->groupProducts()->where('product_id', $productId)->first();
        if (!$pivot) {
            return redirect()->back()->with('error', 'Product not in this group.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return redirect()->back()->with('error', 'Missing Lazada credentials.');
        }

        $pfx = (string) config('catalog.prefix');
        $client = app(\Extensions\lazada\Services\Lazada\LazadaClient::class);

        // Collect all SKUs for this product (parent + option values)
        $product = DB::table($pfx . 'product')->where('product_id', $productId)->first(['sku', 'model']);
        $skus = [];
        if ($product->sku && trim($product->sku) !== '') $skus[] = trim($product->sku);
        if ($product->model && trim($product->model) !== '' && !in_array(trim($product->model), $skus)) $skus[] = trim($product->model);

        $optSkus = DB::table($pfx . 'product_option_value')
            ->where('product_id', $productId)
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->pluck('sku')->map(fn($s) => trim($s))->unique()->toArray();
        $skus = array_unique(array_merge($skus, $optSkus));

        if (empty($skus)) {
            return redirect()->back()->with('error', 'Product has no SKU to match against Lazada.');
        }

        // If already has a lazada_item_id, just refresh variant cache (no need to search)
        $lazadaProduct = LazadaProduct::find($pivot->lazada_product_id);
        if ($lazadaProduct && $lazadaProduct->lazada_item_id && !$lazadaProduct->unlinked_at) {
            $this->refreshLazadaVariants($lazadaProduct, $setting, $client);
            $pivot->update(['sync_status' => 'synced']);
            return redirect()->back()->with('status', "Lazada item ID {$lazadaProduct->lazada_item_id} — cache refreshed.");
        }

        // Search Lazada by each SKU using /products/get with search param
        $matchedItemId = null;
        foreach ($skus as $sku) {
            $apiPath = '/products/get';
            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $setting->app_key,
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $setting->access_token,
                'filter' => 'all',
                'search' => $sku,
                'limit' => '50',
                'offset' => '0',
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);
            $result = $client->get((string) $setting->region, $apiPath, $params);

            if (!($result['ok'] ?? false)) continue;

            $products = $result['body']['data']['products'] ?? [];
            foreach ($products as $p) {
                $itemId = $p['item_id'] ?? ($p['itemId'] ?? null);
                if (!$itemId) continue;

                foreach ($p['skus'] ?? [] as $s) {
                    $sellerSku = $s['SellerSku'] ?? ($s['seller_sku'] ?? ($s['SellerSKU'] ?? null));
                    if ($sellerSku !== null && strcasecmp(trim((string) $sellerSku), $sku) === 0) {
                        $matchedItemId = (string) $itemId;
                        break 3;
                    }
                }
            }
        }

        if (!$matchedItemId) {
            return redirect()->back()->with('error', 'No matching product found on Lazada for SKU(s): ' . implode(', ', $skus));
        }

        // Update lazada_products with the matched item ID
        if (!$lazadaProduct) {
            $lazadaProduct = LazadaProduct::find($pivot->lazada_product_id);
        }
        if ($lazadaProduct) {
            $lazadaProduct->update([
                'lazada_item_id' => $matchedItemId,
                'lazada_deleted_at' => null,
                'unlinked_at' => null,
            ]);

            // Refresh variant cache + product status from API
            $this->refreshLazadaVariants($lazadaProduct, $setting, $client);
        }

        // Update sync status
        $pivot->update(['sync_status' => 'pushed']);

        return redirect()->back()->with('status', "Lazada item ID {$matchedItemId} synced for this product.");
    }

    /**
     * Refresh variant cache and product status from Lazada API.
     */
    private function refreshLazadaVariants(LazadaProduct $listing, object $setting, LazadaClient $client): void
    {
        $itemId = (string) ($listing->lazada_item_id ?? '');
        if ($itemId === '') return;

        $apiPath = '/product/item/get';
        $params = [
            'app_key'      => (string) $setting->app_key,
            'sign_method'  => 'sha256',
            'timestamp'    => (string) round(microtime(true) * 1000),
            'access_token' => (string) $setting->access_token,
            'item_id'      => $itemId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);

        $res = $client->get((string) $setting->region, $apiPath, $params);
        $body = $res['body'] ?? [];
        $data = $body['data'] ?? $body;

        if (empty($data) || !is_array($data)) return;

        // Update product status
        $listing->status = $data['status'] ?? $listing->status;
        $listing->last_sync_ok = true;
        $listing->last_sync_error_code = null;
        $listing->last_sync_error_message = null;
        $listing->last_synced_at = now();
        $listing->save();

        // Refresh variants
        $pfx = (string) config('catalog.prefix');
        $skuToPovId = [];
        $erpOvs = DB::table($pfx . 'product_option_value')
            ->where('product_id', (int) $listing->product_id)
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->get(['product_option_value_id', 'sku']);
        foreach ($erpOvs as $ov) {
            $skuToPovId[trim((string) $ov->sku)] = (int) $ov->product_option_value_id;
        }

        $skus = data_get($data, 'skus') ?? data_get($data, 'Skus.Sku') ?? [];
        $activeSellerSkus = [];

        foreach ($skus as $s) {
            $sellerSku = trim((string) ($s['SellerSku'] ?? $s['seller_sku'] ?? ''));
            $skuId = $s['SkuId'] ?? $s['sku_id'] ?? $s['skuId'] ?? null;
            $shopSku = $s['ShopSku'] ?? $s['shop_sku'] ?? null;

            if ($skuId === null || $skuId === '') continue;

            $activeSellerSkus[] = $sellerSku;

            try {
                LazadaProductVariant::updateOrCreate(
                    ['lazada_product_id' => $listing->id, 'seller_sku' => $sellerSku],
                    [
                        'sku_id' => (int) $skuId,
                        'shop_sku' => $shopSku ? (string) $shopSku : null,
                        'product_option_value_id' => $skuToPovId[$sellerSku] ?? null,
                    ]
                );
            } catch (\Throwable $ex) {}
        }

        // Remove stale variants
        if (!empty($activeSellerSkus)) {
            LazadaProductVariant::where('lazada_product_id', $listing->id)
                ->whereNotIn('seller_sku', $activeSellerSkus)
                ->delete();
        }

        // Update group pivot sync status
        DB::table('lazada_product_group_products')
            ->where('lazada_product_id', $listing->id)
            ->update(['sync_status' => 'synced', 'push_error' => null]);
    }

    public function linkProduct(int $groupId, int $productId)
    {
        $group = LazadaProductGroup::query()->findOrFail($groupId);
        $pivot = $group->groupProducts()->where('product_id', $productId)->first();

        if ($pivot) {
            // Check if the linked lazada_product has a lazada_item_id
            $hasItemId = false;
            if ($pivot->lazada_product_id) {
                $hasItemId = LazadaProduct::query()
                    ->where('id', $pivot->lazada_product_id)
                    ->whereNotNull('lazada_item_id')
                    ->where('lazada_item_id', '!=', '')
                    ->exists();
            }

            $pivot->update(['sync_status' => $hasItemId ? 'synced' : 'pending']);
        }

        return $this->productsRedirect($group->id)
            ->with('status', 'Product re-linked.');
    }

    /**
     * Remove a single product from the group.
     */
    public function removeProduct(int $groupId, int $productId)
    {
        $group = LazadaProductGroup::query()->findOrFail($groupId);
        $group->groupProducts()->where('product_id', $productId)->delete();

        return $this->productsRedirect($group->id)
            ->with('status', 'Product removed from group.');
    }

    /**
     * Bulk remove products from the group.
     */
    public function massRemove(Request $request, int $id)
    {
        $group = LazadaProductGroup::query()->findOrFail($id);
        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));

        if (!empty($ids)) {
            $group->groupProducts()->whereIn('product_id', $ids)->delete();
        }

        return $this->productsRedirect($group->id)
            ->with('status', count($ids) . ' product(s) removed from group.');
    }

    public function push(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        if (!$group->lazada_category_id) {
            return $this->productsRedirect($id)->with('error', 'Product Group has no Lazada category configured. Set a category first.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }

        $lazCtrl = app(LazadaProductController::class);
        $pfx = (string) config('catalog.prefix');

        $okCount = 0;
        $skipCount = 0;
        $errCount = 0;
        $errors = [];

        foreach ($productIds as $productId) {
            // Get or create LazadaProduct record
            $pivot = LazadaProductGroupProduct::query()
                ->where('lazada_product_group_id', $group->id)
                ->where('product_id', $productId)
                ->first();

            $listing = null;
            if ($pivot && $pivot->lazada_product_id) {
                $listing = LazadaProduct::find($pivot->lazada_product_id);
            }

            // Skip already-linked products — use "Update on Lazada" for those
            $isAlreadyLinked = $listing && $listing->lazada_item_id && !$listing->lazada_deleted_at;
            if ($isAlreadyLinked) {
                $skipCount++;
                continue;
            }

            // Create listing record if needed
            if (!$listing) {
                $listing = LazadaProduct::create([
                    'product_id' => $productId,
                    'primary_category_id' => $group->lazada_category_id,
                    'brand_name_override' => 'No Brand',
                    'markup_fixed' => $group->markup_fixed,
                    'markup_percent' => $group->markup_percent,
                ]);

                // Copy group attributes to product attributes
                $groupAttrs = LazadaProductGroupAttribute::where('lazada_product_group_id', $group->id)
                    ->get(['attribute_key', 'value']);
                foreach ($groupAttrs as $ga) {
                    \Extensions\lazada\Models\LazadaProductAttribute::updateOrCreate(
                        ['lazada_product_id' => $listing->id, 'attribute_key' => $ga->attribute_key],
                        ['value' => $ga->value]
                    );
                }

                // Link pivot
                if ($pivot) {
                    $pivot->update(['lazada_product_id' => $listing->id]);
                } else {
                    LazadaProductGroupProduct::create([
                        'lazada_product_group_id' => $group->id,
                        'product_id' => $productId,
                        'lazada_product_id' => $listing->id,
                        'sync_status' => 'pending',
                    ]);
                    $pivot = LazadaProductGroupProduct::query()
                        ->where('lazada_product_group_id', $group->id)
                        ->where('product_id', $productId)
                        ->first();
                }
            } else {
                // Sync group settings to existing listing
                $listing->update([
                    'primary_category_id' => $group->lazada_category_id,
                    'markup_fixed'        => $group->markup_fixed,
                    'markup_percent'      => $group->markup_percent,
                ]);

                // Sync group attributes
                $groupAttrs = LazadaProductGroupAttribute::where('lazada_product_group_id', $group->id)
                    ->get(['attribute_key', 'value']);
                foreach ($groupAttrs as $ga) {
                    \Extensions\lazada\Models\LazadaProductAttribute::updateOrCreate(
                        ['lazada_product_id' => $listing->id, 'attribute_key' => $ga->attribute_key],
                        ['value' => $ga->value]
                    );
                }
            }

            // Build payload
            try {
                [$productPayload, $preview] = $lazCtrl->buildLazadaProductCreatePayload($listing, $setting, $client);
                $productPayload = $lazCtrl->ensureLazadaInlinkImages($productPayload, $setting, $client);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $msg = implode('; ', array_map(fn($m) => implode(', ', $m), $e->errors()));
                $errors[] = "#{$productId}: {$msg}";
                if ($pivot) $pivot->update(['sync_status' => 'error']);
                $errCount++;
                continue;
            } catch (\Throwable $e) {
                $errors[] = "#{$productId}: " . $e->getMessage();
                if ($pivot) $pivot->update(['sync_status' => 'error']);
                $errCount++;
                continue;
            }

            $apiPath = '/product/create';
            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $setting->app_key,
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $setting->access_token,
                'payload' => json_encode($productPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);

            $result = $client->post((string) $setting->region, $apiPath, $params);

            LazadaApiLog::safeCreate([
                'pack' => 'lazada.product.create.group',
                'method' => 'POST', 'api_path' => $apiPath,
                'auth_required' => true, 'request_params' => $params,
                'response_status' => (int) ($result['status'] ?? 0),
                'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
            ]);

            $e = $this->extractLazadaError($result);
            if ($e['ok']) {
                // Extract item_id from create response
                $body = $result['body'] ?? [];
                $data = $body['data'] ?? $body;
                $itemId = $data['item_id'] ?? ($data['ItemId'] ?? null);

                if ($itemId) {
                    $listing->lazada_item_id = (string) $itemId;
                    $listing->lazada_deleted_at = null;
                    try { $listing->save(); } catch (\Throwable $ex) {}
                }

                // Refresh variant cache after push
                $this->refreshLazadaVariants($listing, $setting, $client);

                $this->persistSyncStatus($listing, 'push', true);
                if ($pivot) $pivot->update(['sync_status' => 'pushed']);

                ActivityLogger::log('created', 'Lazada Product', $listing->id,
                    'Pushed ERP #' . $productId . ' to Lazada via product group');

                $okCount++;
            } else {
                $errors[] = "#{$productId}: " . ($e['message'] ?? 'Unknown error');
                if ($pivot) $pivot->update(['sync_status' => 'error']);
                $errCount++;
            }

            usleep(300000); // 300ms rate limit
        }

        $summary = "Push: {$okCount} pushed";
        if ($skipCount > 0) $summary .= ", {$skipCount} skipped (already linked or disabled)";
        if ($errCount > 0) $summary .= ", {$errCount} failed";
        if (!empty($errors)) $summary .= '. Errors: ' . implode('; ', array_slice($errors, 0, 5));

        return $this->productsRedirect($id)->with($okCount > 0 ? 'status' : 'error', $summary);
    }

    /**
     * Update existing Lazada listings with latest ERP data.
     * Only processes products that are already linked to Lazada.
     */
    public function updateProduct(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        if (!$group->lazada_category_id) {
            return $this->productsRedirect($id)->with('error', 'Product Group has no Lazada category configured.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }

        $lazCtrl = app(LazadaProductController::class);

        $okCount = 0;
        $skipCount = 0;
        $errCount = 0;
        $errors = [];

        foreach ($productIds as $productId) {
            $pivot = LazadaProductGroupProduct::query()
                ->where('lazada_product_group_id', $group->id)
                ->where('product_id', $productId)
                ->first();

            $listing = null;
            if ($pivot && $pivot->lazada_product_id) {
                $listing = LazadaProduct::find($pivot->lazada_product_id);
            }

            // Only process products that are already linked
            if (!$listing || !$listing->lazada_item_id || $listing->lazada_deleted_at) {
                $skipCount++;
                continue;
            }

            // Sync group settings to listing
            $listing->update([
                'primary_category_id' => $group->lazada_category_id,
                'markup_fixed'        => $group->markup_fixed,
                'markup_percent'      => $group->markup_percent,
            ]);

            $groupAttrs = LazadaProductGroupAttribute::where('lazada_product_group_id', $group->id)
                ->get(['attribute_key', 'value']);
            foreach ($groupAttrs as $ga) {
                \Extensions\lazada\Models\LazadaProductAttribute::updateOrCreate(
                    ['lazada_product_id' => $listing->id, 'attribute_key' => $ga->attribute_key],
                    ['value' => $ga->value]
                );
            }

            // Build payload
            try {
                [$productPayload, $preview] = $lazCtrl->buildLazadaProductCreatePayload($listing, $setting, $client);
                $productPayload = $lazCtrl->ensureLazadaInlinkImages($productPayload, $setting, $client);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $msg = implode('; ', array_map(fn($m) => implode(', ', $m), $e->errors()));
                $errors[] = "#{$productId}: {$msg}";
                if ($pivot) $pivot->update(['sync_status' => 'error']);
                $errCount++;
                continue;
            } catch (\Throwable $e) {
                $errors[] = "#{$productId}: " . $e->getMessage();
                if ($pivot) $pivot->update(['sync_status' => 'error']);
                $errCount++;
                continue;
            }

            // Inject ItemId and SkuId for update
            $productPayload['Request']['Product']['ItemId'] = (int) $listing->lazada_item_id;

            $variantMap = LazadaProductVariant::where('lazada_product_id', $listing->id)
                ->whereNotNull('sku_id')
                ->pluck('sku_id', 'seller_sku')
                ->toArray();

            foreach ($productPayload['Request']['Product']['Skus']['Sku'] as $idx => $sku) {
                $sellerSku = $sku['SellerSku'] ?? '';
                if (isset($variantMap[$sellerSku])) {
                    $productPayload['Request']['Product']['Skus']['Sku'][$idx]['SkuId'] = (int) $variantMap[$sellerSku];
                }
            }

            $apiPath = '/product/update';
            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $setting->app_key,
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $setting->access_token,
                'payload' => json_encode($productPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);

            $result = $client->post((string) $setting->region, $apiPath, $params);

            LazadaApiLog::safeCreate([
                'pack' => 'lazada.product.update.group',
                'method' => 'POST', 'api_path' => $apiPath,
                'auth_required' => true, 'request_params' => $params,
                'response_status' => (int) ($result['status'] ?? 0),
                'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
            ]);

            $e = $this->extractLazadaError($result);
            if ($e['ok']) {
                $this->refreshLazadaVariants($listing, $setting, $client);
                $this->persistSyncStatus($listing, 'push', true);
                if ($pivot) $pivot->update(['sync_status' => 'pushed']);

                ActivityLogger::log('updated', 'Lazada Product', $listing->id,
                    'Updated ERP #' . $productId . ' on Lazada via product group');

                $okCount++;
            } else {
                $errors[] = "#{$productId}: " . ($e['message'] ?? 'Unknown error');
                if ($pivot) $pivot->update(['sync_status' => 'error']);
                $errCount++;
            }

            usleep(300000);
        }

        $summary = "Update: {$okCount} updated";
        if ($skipCount > 0) $summary .= ", {$skipCount} skipped (not linked)";
        if ($errCount > 0) $summary .= ", {$errCount} failed";
        if (!empty($errors)) $summary .= '. Errors: ' . implode('; ', array_slice($errors, 0, 5));

        return $this->productsRedirect($id)->with($okCount > 0 ? 'status' : 'error', $summary);
    }

    public function pushPrices(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }

        $pfx = (string) config('catalog.prefix');

        // Get lazada_product_ids from the pivot table for these products
        $pivotRows = LazadaProductGroupProduct::query()
            ->where('lazada_product_group_id', $group->id)
            ->whereIn('product_id', $productIds)
            ->whereNotNull('lazada_product_id')
            ->get(['product_id', 'lazada_product_id']);

        $listingIds = $pivotRows->pluck('lazada_product_id')->filter()->unique()->values()->all();
        $skip = count($productIds) - $pivotRows->pluck('product_id')->unique()->count();

        if (empty($listingIds)) {
            return $this->productsRedirect($id)->with('error', 'No linked Lazada products found for the selected items.');
        }

        $listings = LazadaProduct::query()->whereIn('id', $listingIds)->get();
        $erpProductIds = $listings->pluck('product_id')->filter()->unique()->values()->all();

        $products = collect();
        if (!empty($erpProductIds)) {
            $products = DB::table($pfx . 'product as p')
                ->whereIn('p.product_id', $erpProductIds)
                ->get(['p.product_id', 'p.sku', 'p.price'])
                ->keyBy('product_id');
        }

        $variantPriceByProductId = collect();
        if (!empty($erpProductIds)) {
            $vrows = DB::table($pfx . 'product_option_value as pov')
                ->whereIn('pov.product_id', $erpProductIds)
                ->whereNotNull('pov.sku')
                ->where('pov.sku', '!=', '')
                ->orderBy('pov.product_option_value_id')
                ->get(['pov.product_id', 'pov.sku', 'pov.absolute_price']);
            $variantPriceByProductId = $vrows->groupBy(fn($r) => (int) $r->product_id);
        }

        $okCount = 0;
        $errCount = 0;

        foreach ($listings as $listing) {
            $product = $products->get((int) $listing->product_id);
            if (!$product) { $errCount++; continue; }

            if (empty($listing->lazada_item_id)) {
                $this->persistSyncStatus($listing, 'sync_price', false, 'NO_ITEM_ID', 'No Lazada Item ID.');
                $errCount++;
                continue;
            }

            $skuIdMap = $this->resolveLazadaSkuIds($listing, $setting, $client);
            if (empty($skuIdMap)) {
                $this->persistSyncStatus($listing, 'sync_price', false, 'NO_SKU_IDS', 'Could not resolve Lazada SkuIds.');
                $errCount++;
                continue;
            }

            $pid = (int) $product->product_id;
            $basePrice = max(0, (float) ($product->price ?? 0));

            $variants = $variantPriceByProductId->get($pid);
            $variantSkus = [];
            if ($variants && count($variants) > 0) {
                foreach ($variants as $vr) {
                    $vSku = trim((string) ($vr->sku ?? ''));
                    if ($vSku === '') continue;
                    $vPrice = (float) ($vr->absolute_price ?? $basePrice);
                    if ($vPrice < 0) $vPrice = 0;
                    $variantSkus[] = ['seller_sku' => $vSku, 'price' => $vPrice];
                }
            }

            if (empty($variantSkus)) {
                $sku = trim((string) ($product->sku ?? ''));
                if ($sku === '') {
                    $this->persistSyncStatus($listing, 'sync_price', false, 'EMPTY_SKU', 'ERP product SKU is empty.');
                    $errCount++;
                    continue;
                }
                $variantSkus = [['seller_sku' => $sku, 'price' => $basePrice]];
            }

            // Apply group markup
            $fixedMarkup = $listing->markup_fixed;
            $percentMarkup = $listing->markup_percent;
            if ($fixedMarkup === null && $percentMarkup === null) {
                $fixedMarkup = $group->markup_fixed;
                $percentMarkup = $group->markup_percent;
            }
            foreach ($variantSkus as &$vs) {
                $vs['price'] = LazadaProductController::computeFinalPrice((float) $vs['price'], $fixedMarkup, $percentMarkup);
            }
            unset($vs);

            $ok = 0;
            $err = 0;
            $apiPath = '/product/price_quantity/update';
            foreach ($variantSkus as $v) {
                $sku = trim((string) ($v['seller_sku'] ?? ''));
                if ($sku === '') continue;
                $skuId = $skuIdMap[$sku] ?? null;
                if ($skuId === null) { $err++; continue; }
                $price = max(0, (float) ($v['price'] ?? 0));
                $priceStr = number_format($price, 2, '.', '');
                $payloadXml = $this->buildPriceUpdateXml($skuId, $priceStr);

                $timestamp = (string) round(microtime(true) * 1000);
                $params = [
                    'app_key' => (string) $setting->app_key,
                    'sign_method' => 'sha256',
                    'timestamp' => $timestamp,
                    'access_token' => (string) $setting->access_token,
                    'payload' => $payloadXml,
                ];
                $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);
                $result = $client->post((string) $setting->region, $apiPath, $params);

                LazadaApiLog::safeCreate([
                    'pack' => 'lazada.product.price_quantity.update.group',
                    'method' => 'POST', 'api_path' => $apiPath,
                    'auth_required' => true, 'request_params' => $params,
                    'response_status' => (int) ($result['status'] ?? 0),
                    'ok' => (bool) ($result['ok'] ?? false),
                    'response_body' => $result['body'] ?? $result,
                    'user_id' => auth()->id(),
                ]);

                $e = $this->extractLazadaError($result);
                if ($e['ok']) { $ok++; } else { $err++; }
            }

            $this->persistSyncStatus($listing, 'sync_price', $err === 0);
            if ($err === 0) { $okCount++; } else { $errCount++; }
        }

        $msg = "Sync Price: {$okCount} success, {$errCount} error";
        if ($skip > 0) $msg .= ", {$skip} skipped (not linked)";
        return $this->productsRedirect($id)->with('status', $msg . '.');
    }

    public function pushStock(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }

        $pfx = (string) config('catalog.prefix');

        // Get lazada_product_ids from the pivot table for these products
        $pivotRows = LazadaProductGroupProduct::query()
            ->where('lazada_product_group_id', $group->id)
            ->whereIn('product_id', $productIds)
            ->whereNotNull('lazada_product_id')
            ->get(['product_id', 'lazada_product_id']);

        $listingIds = $pivotRows->pluck('lazada_product_id')->filter()->unique()->values()->all();
        $skip = count($productIds) - $pivotRows->pluck('product_id')->unique()->count();

        if (empty($listingIds)) {
            return $this->productsRedirect($id)->with('error', 'No linked Lazada products found for the selected items.');
        }

        $listings = LazadaProduct::query()->whereIn('id', $listingIds)->get();
        $erpProductIds = $listings->pluck('product_id')->filter()->unique()->values()->all();

        $products = collect();
        if (!empty($erpProductIds)) {
            $products = DB::table($pfx . 'product as p')
                ->whereIn('p.product_id', $erpProductIds)
                ->get(['p.product_id', 'p.sku', 'p.quantity', 'p.status'])
                ->keyBy('product_id');
        }

        $erpOptionsByProduct = collect();
        if (!empty($erpProductIds)) {
            $erpOvRows = DB::table($pfx . 'product_option_value as pov')
                ->whereIn('pov.product_id', $erpProductIds)
                ->get(['pov.product_id', 'pov.sku', 'pov.quantity']);
            $erpOptionsByProduct = $erpOvRows->groupBy(fn($r) => (int) $r->product_id);
        }

        $okCount = 0;
        $errCount = 0;
        $disabledCount = 0;

        foreach ($listings as $listing) {
            $product = $products->get((int) $listing->product_id);
            if (!$product) { $errCount++; continue; }

            if ((int) $product->status === 0) {
                $disabledCount++;
                continue;
            }

            if (empty($listing->lazada_item_id)) {
                $this->persistSyncStatus($listing, 'sync_qty', false, 'NO_ITEM_ID', 'No Lazada Item ID.');
                $errCount++;
                continue;
            }

            $skuIdMap = $this->resolveLazadaSkuIds($listing, $setting, $client);
            if (empty($skuIdMap)) {
                $this->persistSyncStatus($listing, 'sync_qty', false, 'NO_SKU_IDS', 'Could not resolve Lazada SkuIds.');
                $errCount++;
                continue;
            }

            $pid = (int) $product->product_id;
            $erpOvs = $erpOptionsByProduct->get($pid);
            $variantSkus = [];

            if ($erpOvs && count($erpOvs) > 0) {
                foreach ($erpOvs as $ov) {
                    $sku = trim((string) ($ov->sku ?? ''));
                    if ($sku === '') continue;
                    $variantSkus[] = ['seller_sku' => $sku, 'quantity' => max(0, (int) ($ov->quantity ?? 0))];
                }
            }

            if (empty($variantSkus)) {
                $sku = trim((string) ($product->sku ?? ''));
                if ($sku !== '') {
                    $variantSkus[] = ['seller_sku' => $sku, 'quantity' => max(0, (int) ($product->quantity ?? 0))];
                }
            }

            if (empty($variantSkus)) {
                $this->persistSyncStatus($listing, 'sync_qty', false, 'EMPTY_SKU', 'No SKU found.');
                $errCount++;
                continue;
            }

            $ok = 0;
            $err = 0;
            $apiPath = '/product/price_quantity/update';
            foreach ($variantSkus as $v) {
                $sku = trim((string) ($v['seller_sku'] ?? ''));
                if ($sku === '') continue;
                $skuId = $skuIdMap[$sku] ?? null;
                if ($skuId === null) { $err++; continue; }
                $qty = max(0, (int) ($v['quantity'] ?? 0));
                $payloadXml = $this->buildQuantityUpdateXml($skuId, $qty);

                $timestamp = (string) round(microtime(true) * 1000);
                $params = [
                    'app_key' => (string) $setting->app_key,
                    'sign_method' => 'sha256',
                    'timestamp' => $timestamp,
                    'access_token' => (string) $setting->access_token,
                    'payload' => $payloadXml,
                ];
                $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);
                $result = $client->post((string) $setting->region, $apiPath, $params);

                LazadaApiLog::safeCreate([
                    'pack' => 'lazada.product.price_quantity.update.group',
                    'method' => 'POST', 'api_path' => $apiPath,
                    'auth_required' => true, 'request_params' => $params,
                    'response_status' => (int) ($result['status'] ?? 0),
                    'ok' => (bool) ($result['ok'] ?? false),
                    'response_body' => $result['body'] ?? $result,
                    'user_id' => auth()->id(),
                ]);

                $e = $this->extractLazadaError($result);
                if ($e['ok']) { $ok++; } else { $err++; }
            }

            $this->persistSyncStatus($listing, 'sync_qty', $err === 0);
            if ($err === 0) { $okCount++; } else { $errCount++; }
        }

        $msg = "Sync Qty: {$okCount} success, {$errCount} error";
        if ($skip > 0) $msg .= ", {$skip} skipped (not linked)";
        if ($disabledCount > 0) $msg .= ", {$disabledCount} skipped (disabled)";
        return $this->productsRedirect($id)->with('status', $msg . '.');
    }

    public function deleteFromLazada(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }

        $pfx = (string) config('catalog.prefix');
        $lazCtrl = app(LazadaProductController::class);

        // Get lazada_product_ids from pivot
        $pivotRows = LazadaProductGroupProduct::query()
            ->where('lazada_product_group_id', $group->id)
            ->whereIn('product_id', $productIds)
            ->whereNotNull('lazada_product_id')
            ->get(['product_id', 'lazada_product_id']);

        if ($pivotRows->isEmpty()) {
            return $this->productsRedirect($id)->with('error', 'No linked Lazada products found for the selected items.');
        }

        $okCount = 0;
        $errCount = 0;

        foreach ($pivotRows as $pivotRow) {
            $listing = LazadaProduct::find($pivotRow->lazada_product_id);
            if (!$listing || !$listing->lazada_item_id) {
                $errCount++;
                continue;
            }

            $product = DB::table($pfx . 'product as p')
                ->where('p.product_id', (int) $listing->product_id)
                ->first(['p.product_id', 'p.sku']);

            $mainSku = trim((string) ($product->sku ?? ''));

            // Get variant SKUs
            $variantSkus = $lazCtrl->getErpVariantStockByProductId((int) $listing->product_id);
            $sellerSkuList = [];
            foreach ($variantSkus as $v) {
                $s = trim((string) ($v['seller_sku'] ?? ''));
                if ($s !== '') $sellerSkuList[] = $s;
            }
            $sellerSkuList = array_values(array_unique($sellerSkuList));

            if (empty($sellerSkuList)) {
                if ($mainSku === '') { $errCount++; continue; }
                $sellerSkuList = [$mainSku];
            } elseif ($mainSku !== '' && !in_array($mainSku, $sellerSkuList, true)) {
                $sellerSkuList[] = $mainSku;
            }

            // Try to get sku_id_list via API
            $skuIdList = [];
            $itemId = trim((string) ($listing->lazada_item_id ?? ''));
            if ($itemId !== '') {
                try {
                    $skuIdList = $lazCtrl->fetchSkuIdListByItemId(
                        $client,
                        (string) $setting->region,
                        (string) $setting->app_key,
                        (string) $setting->app_secret,
                        (string) $setting->access_token,
                        $itemId
                    );
                } catch (\Throwable $ex) {}
            }

            $apiPath = '/product/remove';
            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $setting->app_key,
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $setting->access_token,
            ];

            if (!empty($skuIdList)) {
                $params['sku_id_list'] = json_encode($skuIdList, JSON_UNESCAPED_SLASHES);
            } else {
                $params['seller_sku_list'] = json_encode($sellerSkuList, JSON_UNESCAPED_SLASHES);
            }
            $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);

            $result = $client->post((string) $setting->region, $apiPath, $params);

            // Retry on E006 (internal error)
            $e = $this->extractLazadaError($result);
            if (!empty($e['code']) && (string) $e['code'] === '6') {
                $params['timestamp'] = (string) round(microtime(true) * 1000);
                $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);
                $result = $client->post((string) $setting->region, $apiPath, $params);
                $e = $this->extractLazadaError($result);
            }

            LazadaApiLog::safeCreate([
                'pack' => 'lazada.product.remove.group',
                'method' => 'POST', 'api_path' => $apiPath,
                'auth_required' => true, 'request_params' => $params,
                'response_status' => (int) ($result['status'] ?? 0),
                'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
            ]);

            if (!empty($e['ok'])) {
                try {
                    $listing->lazada_item_id = null;
                    $listing->lazada_deleted_at = now();
                    $listing->save();
                } catch (\Throwable $ex) {}

                // Update pivot
                LazadaProductGroupProduct::where('lazada_product_group_id', $group->id)
                    ->where('product_id', $pivotRow->product_id)
                    ->update(['sync_status' => 'pending']);

                ActivityLogger::log('deleted', 'Lazada Product', $listing->id,
                    'Deleted from Lazada via product group, ERP #' . (int) $listing->product_id);

                $okCount++;
            } else {
                $errCount++;
            }
        }

        return $this->productsRedirect($id)
            ->with($okCount > 0 ? 'status' : 'error', "Delete from Lazada: {$okCount} deleted, {$errCount} failed.");
    }

    // ── Sync Helpers ─────────────────────────────────────────────────

    private function parseIds(Request $request): array
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids)) $ids = [];
        return array_values(array_unique(array_filter(array_map(fn($v) => (int) $v, $ids), fn($v) => $v > 0)));
    }

    private function extractLazadaError(array $result): array
    {
        $body = $result['body'] ?? null;
        $code = null;
        $message = null;

        if (is_array($body)) {
            $code = $body['code'] ?? ($body['error_code'] ?? null);
            $message = $body['message'] ?? ($body['error_message'] ?? null);
        }

        if ($code === null && is_string($body)) {
            $message = trim($body);
        }

        $codeStr = $code !== null ? trim((string) $code) : null;
        $msgStr = $message !== null ? trim((string) $message) : null;

        $okHttp = (bool) ($result['ok'] ?? false);
        $okCode = ($codeStr === null) || ($codeStr === '0');
        $ok = $okHttp && $okCode;

        return [
            'ok' => $ok,
            'code' => $ok ? null : ($codeStr ?: 'UNKNOWN'),
            'message' => $ok ? null : ($msgStr ?: 'Unknown error'),
        ];
    }

    private function persistSyncStatus(LazadaProduct $listing, string $action, bool $ok, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        $listing->last_synced_at = now();
        $listing->last_sync_action = $action;
        $listing->last_sync_ok = $ok;
        $listing->last_sync_error_code = $ok ? null : $errorCode;
        $listing->last_sync_error_message = $ok ? null : $errorMessage;
        try { $listing->save(); } catch (\Throwable $ex) {}
    }

    private function resolveLazadaSkuIds(LazadaProduct $listing, object $setting, LazadaClient $client): array
    {
        $map = [];

        $cached = LazadaProductVariant::where('lazada_product_id', $listing->id)
            ->whereNotNull('sku_id')
            ->get(['seller_sku', 'sku_id']);

        if ($cached->isNotEmpty()) {
            foreach ($cached as $v) {
                $map[trim((string) $v->seller_sku)] = (int) $v->sku_id;
            }

            $pfx = (string) config('catalog.prefix');
            $erpSkus = DB::table($pfx . 'product_option_value')
                ->where('product_id', (int) $listing->product_id)
                ->whereNotNull('sku')
                ->where('sku', '!=', '')
                ->pluck('sku')
                ->map(fn($s) => trim((string) $s))
                ->filter(fn($s) => $s !== '')
                ->toArray();

            if (empty(array_diff($erpSkus, array_keys($map)))) {
                return $map;
            }
        }

        $variants = $this->fetchAndCacheLazadaVariants($listing, $setting, $client);
        $map = [];
        foreach ($variants as $v) {
            $map[trim((string) $v['seller_sku'])] = (int) $v['sku_id'];
        }
        return $map;
    }

    private function fetchAndCacheLazadaVariants(LazadaProduct $listing, object $setting, LazadaClient $client): array
    {
        $itemId = (string) ($listing->lazada_item_id ?? '');
        if ($itemId === '') return [];

        $apiPath = '/product/item/get';
        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => (string) $setting->access_token,
            'item_id' => $itemId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);

        $res = $client->get((string) $setting->region, $apiPath, $params);
        $body = $res['body'] ?? [];
        $data = $body['data'] ?? $body;

        $skus = data_get($data, 'skus') ?? data_get($data, 'Skus.Sku') ?? [];
        if (!is_array($skus)) return [];

        $pfx = (string) config('catalog.prefix');
        $skuToPovId = [];
        $erpOvs = DB::table($pfx . 'product_option_value')
            ->where('product_id', (int) $listing->product_id)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->get(['product_option_value_id', 'sku']);
        foreach ($erpOvs as $ov) {
            $skuToPovId[trim((string) $ov->sku)] = (int) $ov->product_option_value_id;
        }

        $variants = [];
        foreach ($skus as $s) {
            $sellerSku = trim((string) ($s['SellerSku'] ?? $s['seller_sku'] ?? ''));
            $skuId = $s['SkuId'] ?? $s['sku_id'] ?? $s['skuId'] ?? null;
            $shopSku = $s['ShopSku'] ?? $s['shop_sku'] ?? null;

            if ($skuId === null || $skuId === '') continue;

            $povId = $skuToPovId[$sellerSku] ?? null;

            $variants[] = [
                'seller_sku' => $sellerSku,
                'sku_id' => (int) $skuId,
                'shop_sku' => $shopSku ? (string) $shopSku : null,
            ];

            try {
                LazadaProductVariant::updateOrCreate(
                    ['lazada_product_id' => $listing->id, 'seller_sku' => $sellerSku],
                    [
                        'sku_id' => (int) $skuId,
                        'shop_sku' => $shopSku ? (string) $shopSku : null,
                        'product_option_value_id' => $povId,
                    ]
                );
            } catch (\Throwable $ex) {}
        }

        return $variants;
    }

    private function buildQuantityUpdateXml(int $skuId, int $quantity): string
    {
        $quantity = max(0, $quantity);
        return '<Request>'
            . '<Product><Skus><Sku>'
            . '<SkuId>' . $skuId . '</SkuId>'
            . '<Quantity>' . $quantity . '</Quantity>'
            . '</Sku></Skus></Product>'
            . '</Request>';
    }

    private function buildPriceUpdateXml(int $skuId, string $price): string
    {
        $price = htmlspecialchars($price, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<Request>'
            . '<Product><Skus><Sku>'
            . '<SkuId>' . $skuId . '</SkuId>'
            . '<Price>' . $price . '</Price>'
            . '</Sku></Skus></Product>'
            . '</Request>';
    }

    public function syncTemplate(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::query()->findOrFail($id);

        $request->validate(['lazada_category_id' => 'required|integer|min:1']);
        $lazadaCategoryId = (int) $request->input('lazada_category_id');
        $group->update(['lazada_category_id' => $lazadaCategoryId]);

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('ext.lazada.product-groups.edit', $group->id)
                ->with('status', 'Missing Lazada settings.');
        }

        $apiPath = '/category/attributes/get';
        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'primary_category_id' => (string) $lazadaCategoryId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);
        $result = $client->get((string) $setting->region, $apiPath, $params);

        LazadaCategoryTemplate::query()->updateOrCreate(
            ['region' => (string) $setting->region, 'primary_category_id' => $lazadaCategoryId],
            ['template_body' => $result['body'] ?? null, 'fetched_at' => now()]
        );

        return redirect()->route('ext.lazada.product-groups.edit', $group->id)
            ->with('status', 'Category template synced.');
    }

    // -- Helpers ----------------------------------------------------------

    /**
     * Add catalog products to a group, creating LazadaProduct records as needed.
     */
    private function addProductsToGroup(LazadaProductGroup $group, array $productIds): int
    {
        $existing = $group->groupProducts()->whereIn('product_id', $productIds)->pluck('product_id')->toArray();
        $added = 0;

        $groupAttrs = LazadaProductGroupAttribute::query()
            ->where('lazada_product_group_id', $group->id)
            ->get();

        // Batch-fetch existing LazadaProduct records
        $existingLpMap = LazadaProduct::query()
            ->whereIn('product_id', $productIds)
            ->pluck('id', 'product_id');

        // Batch-fetch ERP prices for new products
        $pfx = (string) config('catalog.prefix');
        $newProductIds = array_values(array_diff($productIds, $existingLpMap->keys()->toArray()));
        $erpPrices = [];
        if (!empty($newProductIds)) {
            $erpPrices = DB::table($pfx . 'product')
                ->whereIn('product_id', $newProductIds)
                ->pluck('price', 'product_id')
                ->toArray();
        }

        foreach ($productIds as $pid) {
            if (in_array($pid, $existing)) continue;

            $lpId = $existingLpMap->get($pid);

            if (!$lpId) {
                // Create LazadaProduct record
                $basePrice = (float)($erpPrices[$pid] ?? 0);
                $finalPrice = LazadaProductController::computeFinalPrice(
                    $basePrice, $group->markup_fixed, $group->markup_percent
                );

                $lp = LazadaProduct::create([
                    'product_id' => $pid,
                    'primary_category_id' => $group->lazada_category_id,
                    'brand_id' => $group->brand_id,
                    'brand_name_override' => $group->brand_name_override,
                    'total_price' => $finalPrice,
                ]);
                $lpId = $lp->id;

                foreach ($groupAttrs as $pa) {
                    LazadaProductAttribute::create([
                        'lazada_product_id' => $lpId,
                        'attribute_key' => $pa->attribute_key,
                        'value' => $pa->value,
                    ]);
                }
            }

            LazadaProductGroupProduct::create([
                'lazada_product_group_id' => $group->id,
                'lazada_product_id' => $lpId,
                'product_id' => $pid,
            ]);

            $added++;
        }

        return $added;
    }

    private function saveGroupAttributes(LazadaProductGroup $group, array $attrs): void
    {
        foreach ($attrs as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || strtolower($key) === 'brand') continue;

            if ($value === null || $value === '') {
                LazadaProductGroupAttribute::query()
                    ->where('lazada_product_group_id', $group->id)
                    ->where('attribute_key', $key)
                    ->delete();
            } else {
                LazadaProductGroupAttribute::query()->updateOrCreate(
                    ['lazada_product_group_id' => $group->id, 'attribute_key' => $key],
                    ['value' => (string) $value]
                );
            }
        }
    }

    /**
     * AJAX: Refresh categories from Lazada API and return updated list.
     */
    public function refreshCategories(LazadaClient $client)
    {
        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return response()->json(['ok' => false, 'message' => 'Missing Lazada settings.']);
        }

        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
        ];
        $apiPath = '/category/tree/get';
        $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);
        $result = $client->get((string) $setting->region, $apiPath, $params);
        $body = $result['body'] ?? null;

        $nodes = [];
        if (is_array($body)) {
            if (isset($body['data']) && is_array($body['data'])) {
                $nodes = $body['data'];
            } elseif (isset($body['data']['data']) && is_array($body['data']['data'])) {
                $nodes = $body['data']['data'];
            }
        }

        if (!$result['ok'] || empty($nodes)) {
            $msg = is_array($body) ? ($body['message'] ?? ($body['code'] ?? 'Failed')) : 'Failed';
            return response()->json(['ok' => false, 'message' => 'Fetch failed: ' . $msg]);
        }

        $rows = [];
        $this->flattenCategoryTree($nodes, $rows, null, 0);
        if (empty($rows)) {
            return response()->json(['ok' => false, 'message' => 'No categories returned.']);
        }

        $i = 1;
        foreach ($rows as &$r) { $r['id'] = $i++; }
        unset($r);

        DB::transaction(function () use ($rows) {
            DB::table('lazada_categories')->delete();
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('lazada_categories')->insert($chunk);
            }
        });

        $categories = LazadaCategory::query()->orderBy('name')->limit(5000)->get(['category_id', 'name']);
        return response()->json(['ok' => true, 'count' => count($rows), 'categories' => $categories]);
    }

    private function flattenCategoryTree(array $nodes, array &$rows, ?int $parentId, int $level): void
    {
        foreach ($nodes as $n) {
            if (!is_array($n)) continue;
            $categoryId = isset($n['category_id']) ? (int) $n['category_id'] : null;
            $name = isset($n['name']) ? (string) $n['name'] : '';
            if (!$categoryId || $name === '') continue;
            $rows[] = [
                'category_id' => $categoryId,
                'name' => $name,
                'leaf' => (bool) ($n['leaf'] ?? false),
                'var' => array_key_exists('var', $n) ? (bool) $n['var'] : null,
                'parent_id' => $parentId,
                'level' => $level,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (!empty($n['children']) && is_array($n['children'])) {
                $this->flattenCategoryTree($n['children'], $rows, $categoryId, $level + 1);
            }
        }
    }

    /**
     * AJAX: Sync template for a category and return rendered attribute fields.
     */
    public function fetchAttributesAjax(Request $request, LazadaClient $client)
    {
        $categoryId = (int) $request->input('lazada_category_id', 0);
        if ($categoryId <= 0) {
            return response()->json(['ok' => false, 'html' => '']);
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return response()->json(['ok' => false, 'html' => '<div class="text-muted">Missing Lazada settings.</div>']);
        }

        $apiPath = '/category/attributes/get';
        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'primary_category_id' => (string) $categoryId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);
        $result = $client->get((string) $setting->region, $apiPath, $params);

        $template = LazadaCategoryTemplate::query()->updateOrCreate(
            ['region' => (string) $setting->region, 'primary_category_id' => $categoryId],
            ['template_body' => $result['body'] ?? null, 'fetched_at' => now()]
        );

        $attributes = $this->extractAttributes($template->template_body);
        $erpSourceFields = \App\Http\Controllers\LazadaProductController::ERP_SOURCE_FIELDS;

        $html = view('ext-lazada::product-groups._attributes', [
            'attributes' => $attributes,
            'saved' => [],
            'erpSourceFields' => $erpSourceFields,
            'template' => $template,
        ])->render();

        return response()->json(['ok' => true, 'html' => $html, 'count' => count($attributes)]);
    }

    private function region(): string
    {
        $setting = LazadaSetting::query()->first();
        return (string) ($setting->region ?? '');
    }

    private function extractAttributes($body): array
    {
        if (!is_array($body)) return [];
        $data = $body['data'] ?? $body;
        if (is_array($data) && isset($data['attributes']) && is_array($data['attributes'])) {
            return $this->normalizeAttributes($data['attributes']);
        }
        if (is_array($data) && array_is_list($data)) {
            return $this->normalizeAttributes($data);
        }
        return [];
    }

    private function normalizeAttributes(array $raw): array
    {
        $out = [];
        foreach ($raw as $a) {
            if (!is_array($a)) continue;
            $name = (string) ($a['name'] ?? $a['attribute_name'] ?? '');
            $id = $a['id'] ?? $a['attribute_id'] ?? null;
            $required = (bool) ($a['is_mandatory'] ?? $a['isMandatory'] ?? $a['mandatory'] ?? $a['is_required'] ?? $a['required'] ?? false);
            $inputType = (string) ($a['input_type'] ?? $a['type'] ?? $a['inputType'] ?? 'text');
            $options = $a['options'] ?? $a['option_values'] ?? $a['values'] ?? null;
            $key = $name !== '' ? $name : (is_scalar($id) ? (string) $id : '');
            if ($key === '') continue;
            $out[] = [
                'key' => $key, 'name' => $name !== '' ? $name : $key,
                'required' => $required, 'input_type' => $inputType,
                'options' => is_array($options) ? $options : [],
            ];
        }
        return $out;
    }
}
