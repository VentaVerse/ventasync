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
use Extensions\lazada\Services\Lazada\LazadaAttributes;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Extensions\lazada\Services\Lazada\LazadaItemCache;
use Extensions\lazada\Services\Lazada\LazadaPushPayload;
use Extensions\lazada\Services\LazadaStockPushService;
use Extensions\lazada\Services\Lazada\LazadaLiveListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LazadaProductGroupController extends Controller
{

    /** The one attribute vocabulary (Phase C, #184): this controller carried
     * its own pre-#175 fork of the extractor, which still showed sale props
     * and __wire__ keys as questions on the group sheet. */
    private function attrs(): LazadaAttributes
    {
        return app(LazadaAttributes::class);
    }

    /** The wire payloads and the reading of Lazada's answers (Phase C, #184). */
    private function payload(): LazadaPushPayload
    {
        return app(LazadaPushPayload::class);
    }
    /**
     * Write a product's status to EVERY group that holds it.
     *
     * The status was stored once per group, so one product could read
     * differently on two pages: a stale error sends the operator chasing a
     * problem that is fixed, and a stale success hides a real failure behind a
     * green badge somewhere else. Lazada is single-store, so every group
     * refers to the same shop and the same remote product.
     */
    private function writeProductStatus(int $productId, array $attributes): void
    {
        \App\Support\ChannelProductStatus::write(
            'lazada_product_group_products',
            'lazada_product_group_id',
            null,
            $productId,
            $attributes
        );
    }

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
                $attributes = $this->attrs()->extractAttributes($template->template_body);
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
            'erpSourceFields' => LazadaAttributes::ERP_SOURCE_FIELDS,
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
            'lazada_category_id' => $data['lazada_category_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'brand_name_override' => !empty($request->input('no_brand')) ? 'No Brand' : null,
            'markup_fixed' => $data['markup_fixed'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null,
        ]);

        $this->saveGroupAttributes($group, (array) $request->input('attributes', []));

        ActivityLogger::log('created', 'Lazada Product group', $group->id, $group->name);

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
            'lazada_category_id' => $data['lazada_category_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'brand_name_override' => !empty($request->input('no_brand')) ? 'No Brand' : null,
            'markup_fixed' => $data['markup_fixed'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null,
        ]);

        $this->saveGroupAttributes($group, (array) $request->input('attributes', []));

        ActivityLogger::log('updated', 'Lazada Product group', $group->id, $group->name);

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

        ActivityLogger::log('deleted', 'Lazada Product group', (int) $id, $group->name);

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
                'lp.lazada_item_id', 'lp.lazada_deleted_at',
                'lp.last_pushed_at as listing_last_pushed_at', 'lp.last_push_source',
                'p.date_modified',
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

        /* The filter answers what the column shows (one-truth port from
         * Shopee, 2026-08-25): Listed/Not listed are the LISTING record's
         * facts - lazada_products, the same row the Listings page reads.
         * 'error' stays per-group: this group's own last attempt. */
        if ($syncStatus === 'pushed') {
            $query->whereNotNull('lp.lazada_item_id')->where('lp.lazada_item_id', '!=', '');
        } elseif ($syncStatus === 'pending') {
            $query->where(function ($w) {
                $w->whereNull('lp.lazada_item_id')->orWhere('lp.lazada_item_id', '');
            });
        } elseif ($syncStatus === 'error') {
            $query->where('gp.sync_status', 'error');
        } elseif ($syncStatus === 'unlinked') {
            // Unlinked was a state the columns could show and the filter
            // could not find (parity #156, from Shopee's group page).
            $query->where('gp.sync_status', 'unlinked');
        }

        $products = $query->orderBy('p.product_id', 'desc')->paginate(50)->appends($request->except('page'));

        // Pivot map for templates
        $pivotProductIds = $products->pluck('product_id')->toArray();
        $pivotMap = LazadaProductGroupProduct::query()
            ->where('lazada_product_group_id', $group->id)
            ->whereIn('product_id', $pivotProductIds)
            ->get()
            ->keyBy('product_id');

        // Variation rows: the shared combinations-first query the core
        // product list uses, image and proper option names included
        // (App\Support\VariationRows). The pov fallback for never-resaved
        // products lives inside it.
        $optionRowsByProductId = \App\Support\VariationRows::forProducts($pivotProductIds);

        // All product IDs in this group
        $manualIds = $group->groupProducts()->whereNotNull('product_id')->pluck('product_id')->toArray();

        return view('ext-lazada::product-groups.products', [
            'group' => $group,
            'products' => $products,
            'pivotMap' => $pivotMap,
            /* ONE state per row (#188): the same engine the Listings page
             * renders, so this page's badges and menus can never disagree
             * with it. */
            'listingStates' => app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class)
                ->forProducts(array_map('intval', $pivotProductIds)),
            'optionRowsByProductId' => $optionRowsByProductId,
            // The row menu reaches the product's Lazada listing page, like
            // the Shopee and TikTok group pages do (critique 2026-08-25).
            'lazadaIdByProductId' => LazadaProduct::query()
                ->whereIn('product_id', $products->pluck('product_id')->all() ?: [0])
                ->pluck('id', 'product_id'),
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

        // Empty filters return the latest 100 products (the LIMIT below) so
        // operators can browse everything without first picking a category
        // or typing a search term.

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

        $added = $this->addProductsToGroup($group, $ids, array_map('intval', (array) $request->input('move_ids', [])));

        return $this->productsRedirect($group->id)
            ->with($added > 0 ? 'status' : 'warning', "{$added} product(s) added to the product group."
                . \App\Integrations\OneGroupRule::heldClause($this->lastHeld));
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
            return redirect()->back()->with('error', 'Product not in this product group.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
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
            app(LazadaItemCache::class)->refreshListing($lazadaProduct, $setting, $client, 'lazada.product.item.get.cache.group');
            $pivot->update(['sync_status' => 'synced']);
            return redirect()->back()->with('status', "Lazada item ID {$lazadaProduct->lazada_item_id} — cache refreshed.");
        }

        // Search Lazada by each SKU using /products/get with search param
        $matchedItemId = null;
        foreach ($skus as $sku) {
            $apiPath = '/products/get';
            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
                'filter' => 'all',
                'search' => $sku,
                'limit' => '50',
                'offset' => '0',
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
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
            app(LazadaItemCache::class)->refreshListing($lazadaProduct, $setting, $client, 'lazada.product.item.get.cache.group');
        }

        // Update sync status
        $pivot->update(['sync_status' => 'pushed']);

        return redirect()->back()->with('status', "Lazada item ID {$matchedItemId} synced for this product.");
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
            ->with('status', 'Product removed from the product group.');
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
            ->with('status', count($ids) . ' product(s) removed from the product group.');
    }

    public function push(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        if (!$group->lazada_category_id) {
            return $this->productsRedirect($id)->with('error', 'This product group has no Lazada category configured. Set a category first.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }

        $pfx = (string) config('catalog.prefix');

        $okCount = 0;
        $updatedCount = 0;
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
            $groupOverrides = [];
            if ($pivot && $pivot->lazada_product_id) {
                $listing = LazadaProduct::find($pivot->lazada_product_id);
            }

            /* SEND SEMANTICS (parity #156, item 4): an already-listed
             * product is updated in place instead of skipped, the verb
             * Shopee's group page already speaks. */
            $isAlreadyLinked = $listing && $listing->lazada_item_id && !$listing->lazada_deleted_at;
            if ($isAlreadyLinked) {
                $outcome = $this->updateLinked($group, $pivot, $listing, (int) $productId, $setting, $creds, $client);
                if ($outcome['ok']) {
                    $updatedCount++;
                } else {
                    $errors[] = "#{$productId}: " . $outcome['message'];
                    $errCount++;
                }
                usleep(300000);
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
                /* THE GROUP'S SETTINGS MAKE THIS PUSH - WITHOUT BECOMING THE
                 * LISTING'S OWN (#104's rule, ported from Shopee). The
                 * builder applies these in memory; the listing's rows are
                 * never written. This replaces the overwrite-then-restore
                 * dance, whose crash window left listings permanently
                 * wearing the group's values. */
                $groupOverrides = [
                    'settings' => [
                        'primary_category_id' => $group->lazada_category_id,
                        'markup_fixed'        => $group->markup_fixed,
                        'markup_percent'      => $group->markup_percent,
                    ],
                    'attributes' => LazadaProductGroupAttribute::where('lazada_product_group_id', $group->id)
                        ->pluck('value', 'attribute_key')->toArray(),
                ];
            }

            // Build payload
            try {
                [$productPayload, $preview] = $this->payload()->buildLazadaProductCreatePayload($listing, $setting, $client, $groupOverrides);
                $productPayload = app(\Extensions\lazada\Services\Lazada\LazadaImages::class)->ensureLazadaInlinkImages($productPayload, $setting, $client);
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
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
                'payload' => json_encode($productPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

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

            $e = $this->payload()->extractLazadaError($result);
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
                app(LazadaItemCache::class)->refreshListing($listing, $setting, $client, 'lazada.product.item.get.cache.group');

                $this->persistSyncStatus($listing, 'push', true);

                /* Provenance (#104): the GROUP's settings made this push -
                 * auditable on the listing, same vocabulary as Shopee. */
                $listing->forceFill([
                    'last_pushed_at' => now(),
                    'last_push_source' => 'group:' . $group->name,
                    'last_push_settings' => [
                        'category_id' => (int) ($group->lazada_category_id ?? 0),
                        'markup_percent' => $group->markup_percent,
                        'markup_fixed' => $group->markup_fixed,
                    ],
                ])->save();
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

        $summary = "Send: {$okCount} created";
        if ($updatedCount > 0) $summary .= ", {$updatedCount} already listed and updated in place";
        if ($skipCount > 0) $summary .= ", {$skipCount} skipped (disabled)";
        if ($errCount > 0) $summary .= ", {$errCount} failed";
        if (!empty($errors)) $summary .= '. Errors: ' . implode('; ', array_slice($errors, 0, 5));

        return $this->productsRedirect($id)->with(($okCount + $updatedCount) > 0 ? 'status' : 'error', $summary);
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
            return $this->productsRedirect($id)->with('error', 'This product group has no Lazada category configured.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }


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

            $outcome = $this->updateLinked($group, $pivot, $listing, (int) $productId, $setting, $creds, $client);
            if ($outcome['ok']) {
                $okCount++;
            } else {
                $errors[] = "#{$productId}: " . $outcome['message'];
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

    /**
     * Update one already-listed product on Lazada with the GROUP's settings,
     * without those becoming the listing's own (#104): applied for the
     * build, restored right after. Shared by Update and by Send, where an
     * already-listed selection is updated in place instead of skipped.
     */
    private function updateLinked(LazadaProductGroup $group, ?LazadaProductGroupProduct $pivot, LazadaProduct $listing, int $productId, object $setting, array $creds, LazadaClient $client): array
    {
            /* The GROUP's settings make this update - without becoming the
             * listing's own (#104's rule). The builder applies them in
             * memory; the listing's rows are never written (the old
             * overwrite-then-restore dance had a crash window that left
             * listings permanently wearing the group's values). */
            $groupOverrides = [
                'settings' => [
                    'primary_category_id' => $group->lazada_category_id,
                    'markup_fixed'        => $group->markup_fixed,
                    'markup_percent'      => $group->markup_percent,
                ],
                'attributes' => LazadaProductGroupAttribute::where('lazada_product_group_id', $group->id)
                    ->pluck('value', 'attribute_key')->toArray(),
            ];

            // Build payload
            try {
                [$productPayload, $preview] = $this->payload()->buildLazadaProductCreatePayload($listing, $setting, $client, $groupOverrides);
                $productPayload = app(\Extensions\lazada\Services\Lazada\LazadaImages::class)->ensureLazadaInlinkImages($productPayload, $setting, $client);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $msg = implode('; ', array_map(fn($m) => implode(', ', $m), $e->errors()));
                if ($pivot) $pivot->update(['sync_status' => 'error']);
                return ['ok' => false, 'message' => $msg];
            } catch (\Throwable $e) {
                if ($pivot) $pivot->update(['sync_status' => 'error']);
                return ['ok' => false, 'message' => $e->getMessage()];
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
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
                'payload' => json_encode($productPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

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

            $e = $this->payload()->extractLazadaError($result);
            if ($e['ok']) {
                app(LazadaItemCache::class)->refreshListing($listing, $setting, $client, 'lazada.product.item.get.cache.group');
                $this->persistSyncStatus($listing, 'push', true);

                /* Provenance (#104): the GROUP's settings made this push -
                 * auditable on the listing, same vocabulary as Shopee. */
                $listing->forceFill([
                    'last_pushed_at' => now(),
                    'last_push_source' => 'group:' . $group->name,
                    'last_push_settings' => [
                        'category_id' => (int) ($group->lazada_category_id ?? 0),
                        'markup_percent' => $group->markup_percent,
                        'markup_fixed' => $group->markup_fixed,
                    ],
                ])->save();
                if ($pivot) $pivot->update(['sync_status' => 'pushed']);

                ActivityLogger::log('updated', 'Lazada Product', $listing->id,
                    'Updated ERP #' . $productId . ' on Lazada via product group');

                return ['ok' => true, 'message' => 'updated'];
            }

            if ($pivot) $pivot->update(['sync_status' => 'error']);

            return ['ok' => false, 'message' => (string) ($e['message'] ?? 'Unknown error')];

    }

    /**
     * Check every product in this group against Lazada (parity #156, the
     * Shopee group page's tool). Nothing is sent; the ERP corrects its
     * own records to what it finds: a listed product whose item is gone
     * is marked so, an unlisted product whose SKU already sits on the
     * shop is linked. One batched lookup by seller SKU, fifty a call.
     */
    /**
     * Ask Lazada about this group's products and make the ERP agree.
     * The SKU walk lives in LazadaLinkCheck, shared with the workbench
     * (Phase F, #184); this entry point supplies the group's members as
     * the default scope and the group itself for adoption defaults.
     */
    public function checkAgainstLazada(Request $request, int $id, \Extensions\lazada\Services\Lazada\LazadaLinkCheck $check)
    {
        // One Lazada call per fifty SKUs: a whole group of two hundred
        // products is eight or more calls of a few seconds each, past the
        // default execution limit, and the request died with the page still
        // waiting (2026-08-26). The same allowance every other long action
        // on this controller takes.
        try { @set_time_limit(0); } catch (\Throwable $e) {}

        $group = LazadaProductGroup::findOrFail($id);
        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['complete']) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }

        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            $productIds = $group->groupProducts()->pluck('product_id')->map('intval')->all();
        }
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'This product group has no products to check.');
        }

        $r = $check->run($setting, $creds, $productIds, $group);

        return $this->productsRedirect($id)->with($r['tone'], $r['summary']);
    }

    /**
     * What Lazada holds that no catalog product is linked to (parity
     * #156, the Shopee group page's Unlinked view). Read-only: every row
     * is a judgement call for the operator.
     */
    public function orphans(Request $request, int $id, LazadaLiveListing $live)
    {
        $group = LazadaProductGroup::findOrFail($id);
        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['complete']) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }

        $index = $live->skuIndex($setting, $creds);
        $known = LazadaProduct::query()->whereNotNull('lazada_item_id')->where('lazada_item_id', '!=', '')
            ->pluck('lazada_item_id')->map(fn ($v) => (string) $v)->flip();

        $orphans = [];
        foreach ($index['items'] as $itemId => $skus) {
            if ($known->has((string) $itemId)) {
                continue;
            }
            sort($skus);
            $orphans[] = ['id' => (string) $itemId, 'skus' => $skus];
        }

        return view('ext-lazada::product-groups.orphans', [
            'group' => $group,
            'orphans' => $orphans,
            'scanned' => count($index['items']),
            'complete' => $index['complete'],
        ]);
    }

    /** Every SKU a product carries: its own, its model, its option and combination SKUs. */

    public function pushPrices(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::findOrFail($id);
        $ids = $this->parseIds($request);
        if (empty($ids)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['complete']) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }

        $pfx = (string) config('catalog.prefix');
        $listings = LazadaProduct::query()->whereNotNull('product_id')->whereIn('product_id', $ids)->get();

        /* The shared sender (Phase F, #184), priced by the GROUP's rule. */
        $gFixed = (float) ($group->markup_fixed ?? 0);
        $gPct = (float) ($group->markup_percent ?? 0);
        $results = app(\Extensions\lazada\Services\Lazada\LazadaStockPricePush::class)
            ->push('price', $setting, $creds, $listings, $pfx,
                fn (int $pid, float $base) => \Extensions\lazada\Services\Lazada\LazadaPushPayload::computeFinalPrice($base, $gFixed, $gPct));

        /* The verdicts land on THIS group's pivots - a stock or price
         * failure used to leave no durable trace on the page at all. */
        foreach ($results['outcomes'] as $pid => $outcome) {
            LazadaProductGroupProduct::query()
                ->where('lazada_product_group_id', $group->id)->where('product_id', (int) $pid)
                ->update($outcome['ok']
                    ? ['sync_status' => 'pushed', 'push_error' => null]
                    : ['sync_status' => 'error', 'push_error' => mb_substr('Price push: ' . $outcome['error'], 0, 255)]);
        }

        return $this->productsRedirect($id)->with(
            \App\Integrations\Push\PushLedger::batchTone($results['ok'], $results['err']),
            "Sync Price: {$results['ok']} ok, {$results['err']} failed"
                . ($results['skipped'] > 0 ? ", {$results['skipped']} skipped (disabled)" : '') . '.'
                . \Extensions\lazada\Services\Lazada\LazadaStockPricePush::ledgerClause($results)
        );
    }

    public function pushStock(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::findOrFail($id);
        $ids = $this->parseIds($request);
        if (empty($ids)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['complete']) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }

        $pfx = (string) config('catalog.prefix');
        $listings = LazadaProduct::query()->whereNotNull('product_id')->whereIn('product_id', $ids)->get();

        /* The shared sender (Phase F, #184): the orphan variant that used
         * to be silently zeroed is named and skipped instead. */
        $results = app(\Extensions\lazada\Services\Lazada\LazadaStockPricePush::class)
            ->push('stock', $setting, $creds, $listings, $pfx);

        foreach ($results['outcomes'] as $pid => $outcome) {
            LazadaProductGroupProduct::query()
                ->where('lazada_product_group_id', $group->id)->where('product_id', (int) $pid)
                ->update($outcome['ok']
                    ? ['sync_status' => 'pushed', 'push_error' => null]
                    : ['sync_status' => 'error', 'push_error' => mb_substr('Stock push: ' . $outcome['error'], 0, 255)]);
        }

        return $this->productsRedirect($id)->with(
            \App\Integrations\Push\PushLedger::batchTone($results['ok'], $results['err']),
            "Sync Qty: {$results['ok']} ok, {$results['err']} failed"
                . ($results['skipped'] > 0 ? ", {$results['skipped']} skipped (disabled)" : '') . '.'
                . \Extensions\lazada\Services\Lazada\LazadaStockPricePush::ledgerClause($results)
        );
    }

    public function deleteFromLazada(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }

        $pfx = (string) config('catalog.prefix');

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
            $variantSkus = $this->payload()->getErpVariantStockByProductId((int) $listing->product_id);
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
                    $skuIdList = app(LazadaItemCache::class)->fetchSkuIdListByItemId(
                        $client,
                        (string) $setting->region,
                        (string) $creds['app_key'],
                        (string) $creds['app_secret'],
                        (string) $creds['access_token'],
                        $itemId
                    );
                } catch (\Throwable $ex) {}
            }

            $apiPath = '/product/remove';
            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
            ];

            if (!empty($skuIdList)) {
                $params['sku_id_list'] = json_encode($skuIdList, JSON_UNESCAPED_SLASHES);
            } else {
                $params['seller_sku_list'] = json_encode($sellerSkuList, JSON_UNESCAPED_SLASHES);
            }
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

            $result = $client->post((string) $setting->region, $apiPath, $params);

            // Retry on E006 (internal error)
            $e = $this->payload()->extractLazadaError($result);
            if (!empty($e['code']) && (string) $e['code'] === '6') {
                $params['timestamp'] = (string) round(microtime(true) * 1000);
                $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
                $result = $client->post((string) $setting->region, $apiPath, $params);
                $e = $this->payload()->extractLazadaError($result);
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
                $this->writeProductStatus($pivotRow->product_id, ['sync_status' => 'pending']);

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


    private function persistSyncStatus(LazadaProduct $listing, string $action, bool $ok, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        $listing->last_synced_at = now();
        $listing->last_sync_action = $action;
        $listing->last_sync_ok = $ok;
        $listing->last_sync_error_code = $ok ? null : $errorCode;
        $listing->last_sync_error_message = $ok ? null : $errorMessage;
        try { $listing->save(); } catch (\Throwable $ex) {}
    }


    public function syncTemplate(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::query()->findOrFail($id);

        $request->validate(['lazada_category_id' => 'required|integer|min:1']);
        $lazadaCategoryId = (int) $request->input('lazada_category_id');
        $group->update(['lazada_category_id' => $lazadaCategoryId]);

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret']) {
            return redirect()->route('ext.lazada.product-groups.edit', $group->id)
                ->with('status', 'Missing Lazada settings.');
        }

        $apiPath = '/category/attributes/get';
        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'primary_category_id' => (string) $lazadaCategoryId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
        $result = $client->get((string) $setting->region, $apiPath, $params);

        LazadaCategoryTemplate::query()->updateOrCreate(
            ['region' => (string) $setting->region, 'primary_category_id' => $lazadaCategoryId],
            ['template_body' => $result['body'] ?? null, 'fetched_at' => now()]
        );

        return redirect()->route('ext.lazada.product-groups.edit', $group->id)
            ->with('status', 'Category template synced.');
    }

    // -- Helpers ----------------------------------------------------------

    /** Products the one-group rule held back on the last add, for flashes. */
    private array $lastHeld = [];

    /**
     * Add catalog products to a group, creating LazadaProduct records as needed.
     */
    private function addProductsToGroup(LazadaProductGroup $group, array $productIds, array $moveIds = []): int
    {
        $existing = $group->groupProducts()->whereIn('product_id', $productIds)->pluck('product_id')->toArray();

        /* One product, one group (cleanup point 2): products owned by
         * another group are dropped here and named by the caller's flash
         * via lastHeld. */
        $claim = \App\Integrations\OneGroupRule::claim('lazada_product_group_products', 'lazada_product_group_id', 'lazada_product_groups', null, $group->id, array_values(array_diff(array_map('intval', $productIds), $existing)), $moveIds);
        $this->lastHeld = $claim['held'];
        $productIds = array_merge($claim['free'], array_keys($claim['moved']), array_map('intval', $existing));
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
                $finalPrice = LazadaPushPayload::computeFinalPrice(
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
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret']) {
            return response()->json(['ok' => false, 'message' => 'Missing Lazada settings.']);
        }

        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
        ];
        $apiPath = '/category/tree/get';
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
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
            $msg = \App\Support\MarketplaceAnswer::plain('Lazada', ['ok' => false, 'body' => is_array($body) ? $body : []]);
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
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret']) {
            return response()->json(['ok' => false, 'html' => '<div class="text-muted">Missing Lazada settings.</div>']);
        }

        $apiPath = '/category/attributes/get';
        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'primary_category_id' => (string) $categoryId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
        $result = $client->get((string) $setting->region, $apiPath, $params);

        $template = LazadaCategoryTemplate::query()->updateOrCreate(
            ['region' => (string) $setting->region, 'primary_category_id' => $categoryId],
            ['template_body' => $result['body'] ?? null, 'fetched_at' => now()]
        );

        $attributes = $this->attrs()->extractAttributes($template->template_body);
        $erpSourceFields = \Extensions\lazada\Controllers\LazadaAttributes::ERP_SOURCE_FIELDS;

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


}
