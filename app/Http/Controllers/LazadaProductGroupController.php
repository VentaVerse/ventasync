<?php

namespace App\Http\Controllers;

use App\Models\LazadaCategoryTemplate;
use App\Models\LazadaCategory;
use App\Models\LazadaProduct;
use App\Models\LazadaProductAttribute;
use App\Models\LazadaProductGroup;
use App\Models\LazadaProductGroupAttribute;
use App\Models\LazadaProductGroupProduct;
use App\Models\LazadaSetting;
use App\Services\ActivityLogger;
use App\Services\Lazada\LazadaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LazadaProductGroupController extends Controller
{
    private function productsRedirect(int $id)
    {
        $return = request()->input('_return');
        if ($return && str_starts_with($return, '/')) {
            return redirect($return);
        }
        return redirect()->route('lazada.product-groups.products', $id);
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

        return view('lazada.product-groups.index', [
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
            $brand = \App\Models\LazadaBrand::query()->where('brand_id', $group->brand_id)->first();
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

        return view('lazada.product-groups.form', [
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

        return redirect()->route('lazada.product-groups.edit', $group->id)
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

        return redirect()->route('lazada.product-groups.edit', $group->id)
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

        return redirect()->route('lazada.product-groups.index')
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
            $query->whereNotNull('lp.lazada_item_id')->where('lp.lazada_item_id', '!=', '');
        } elseif ($syncStatus === 'pending') {
            $query->where(function ($w) {
                $w->whereNull('lp.lazada_item_id')->orWhere('lp.lazada_item_id', '');
            })->where('gp.sync_status', '!=', 'error');
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

        return view('lazada.product-groups.products', [
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

    public function push(Request $request, int $id)
    {
        return $this->productsRedirect($id)->with('error', 'Push to Lazada is not yet implemented.');
    }

    public function pushPrices(Request $request, int $id)
    {
        return $this->productsRedirect($id)->with('error', 'Sync Price for Lazada is not yet implemented.');
    }

    public function pushStock(Request $request, int $id)
    {
        return $this->productsRedirect($id)->with('error', 'Sync Qty for Lazada is not yet implemented.');
    }

    public function deleteFromLazada(Request $request, int $id)
    {
        return $this->productsRedirect($id)->with('error', 'Delete from Lazada is not yet implemented.');
    }

    public function syncTemplate(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::query()->findOrFail($id);

        $request->validate(['lazada_category_id' => 'required|integer|min:1']);
        $lazadaCategoryId = (int) $request->input('lazada_category_id');
        $group->update(['lazada_category_id' => $lazadaCategoryId]);

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('lazada.product-groups.edit', $group->id)
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

        return redirect()->route('lazada.product-groups.edit', $group->id)
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

        $html = view('lazada.product-groups._attributes', [
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
