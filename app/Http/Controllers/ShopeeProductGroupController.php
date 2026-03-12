<?php

namespace App\Http\Controllers;

use App\Models\ShopeeCategory;
use App\Models\ShopeeCategoryTemplate;
use App\Models\ShopeeLogistic;
use App\Models\ShopeeProductGroup;
use App\Models\ShopeeProductGroupAttribute;
use App\Models\ShopeeProductGroupProduct;
use App\Models\ShopeeSetting;
use App\Services\ActivityLogger;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopeeProductGroupController extends Controller
{
    private function productsRedirect(int $id)
    {
        $return = request()->input('_return');
        if ($return && str_starts_with($return, '/')) {
            return redirect($return);
        }
        return redirect()->route('shopee.product-groups.products', $id);
    }

    public function index()
    {
        $groups = ShopeeProductGroup::query()->orderByDesc('id')->get();

        // Shopee category names
        $shopeeCatIds = $groups->pluck('shopee_category_id')->filter()->unique()->values()->all();
        $shopeeCategoryNames = collect();
        if (!empty($shopeeCatIds)) {
            $shopeeCategoryNames = ShopeeCategory::query()
                ->whereIn('category_id', $shopeeCatIds)
                ->pluck('name', 'category_id');
        }

        // Product counts per group (via pivot)
        $productCounts = DB::table('shopee_product_group_products')
            ->selectRaw('shopee_product_group_id, COUNT(*) as cnt')
            ->groupBy('shopee_product_group_id')
            ->pluck('cnt', 'shopee_product_group_id');

        return view('shopee.product-groups.index', [
            'groups' => $groups,
            'shopeeCategoryNames' => $shopeeCategoryNames,
            'productCounts' => $productCounts,
        ]);
    }

    public function create()
    {
        return $this->form(new ShopeeProductGroup(), 'create');
    }

    public function edit(int $id)
    {
        $group = ShopeeProductGroup::query()->findOrFail($id);
        return $this->form($group, 'edit');
    }

    private function form(ShopeeProductGroup $group, string $mode)
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

        $shopeeCategories = ShopeeCategory::query()->orderBy('name')->limit(5000)->get();

        // Fetch logistics channels
        $logistics = $this->fetchLogistics();

        // Template & attributes
        $template = null;
        $attributes = [];
        $saved = [];
        if ($group->shopee_category_id) {
            $template = ShopeeCategoryTemplate::query()
                ->where('category_id', (int) $group->shopee_category_id)
                ->first();

            if ($template && $template->attributes) {
                $attributes = $this->extractAttributes($template->attributes);
            }

            if ($group->exists) {
                $saved = ShopeeProductGroupAttribute::query()
                    ->where('shopee_product_group_id', $group->id)
                    ->pluck('value', 'attribute_key')
                    ->toArray();
            }
        }

        // Load group products for dual-listbox
        // On validation failure, restore from old('product_ids') so selections aren't lost
        $oldProductIds = old('product_ids');
        $groupProducts = collect();

        if (!empty($oldProductIds)) {
            $productIds = array_filter(array_map('intval', (array) $oldProductIds));
        } elseif ($group->exists) {
            $productIds = $group->groupProducts()->pluck('product_id')->toArray();
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

        return view('shopee.product-groups.form', [
            'group' => $group,
            'mode' => $mode,
            'catalogCategories' => $catalogCategories,
            'manufacturers' => $manufacturers,
            'shopeeCategories' => $shopeeCategories,
            'logistics' => $logistics,
            'template' => $template,
            'attributes' => $attributes,
            'saved' => $saved,
            'groupProducts' => $groupProducts,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'shopee_category_id' => 'required|integer|min:1',
            'logistic_ids' => 'required|array',
            'logistic_ids.*' => 'integer',
            'markup_fixed' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|min:1',
        ], [
            'shopee_category_id.required' => 'Shopee Category is required.',
            'logistic_ids.required' => 'At least one Logistics Channel is required.',
        ]);

        $logIds = array_filter($data['logistic_ids'] ?? []);

        $group = ShopeeProductGroup::create([
            'name' => $data['name'],
            'catalog_category_ids' => null,
            'manufacturer_ids' => null,
            'shopee_category_id' => $data['shopee_category_id'] ?? null,
            'logistic_ids' => !empty($logIds) ? array_values(array_map('intval', $logIds)) : null,
            'markup_fixed' => $data['markup_fixed'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null,
        ]);

        ActivityLogger::log('created', 'Shopee Product Group', $group->id, $group->name);

        $this->saveGroupAttributes($group, (array) $request->input('attributes', []));

        // Add products from dual-listbox
        $productIds = array_map('intval', array_filter($data['product_ids'] ?? []));
        foreach ($productIds as $pid) {
            ShopeeProductGroupProduct::create([
                'shopee_product_group_id' => $group->id,
                'product_id' => $pid,
            ]);
        }

        return redirect()->route('shopee.product-groups.edit', $group->id)
            ->with('status', 'Product group created. ' . count($productIds) . ' product(s) assigned.');
    }

    public function update(Request $request, int $id)
    {
        $group = ShopeeProductGroup::query()->findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'shopee_category_id' => 'required|integer|min:1',
            'logistic_ids' => 'required|array',
            'logistic_ids.*' => 'integer',
            'markup_fixed' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|min:1',
        ], [
            'shopee_category_id.required' => 'Shopee Category is required.',
            'logistic_ids.required' => 'At least one Logistics Channel is required.',
        ]);

        $logIds = array_filter($data['logistic_ids'] ?? []);

        $group->update([
            'name' => $data['name'],
            'catalog_category_ids' => null,
            'manufacturer_ids' => null,
            'shopee_category_id' => $data['shopee_category_id'] ?? null,
            'logistic_ids' => !empty($logIds) ? array_values(array_map('intval', $logIds)) : null,
            'markup_fixed' => $data['markup_fixed'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null,
        ]);

        ActivityLogger::log('updated', 'Shopee Product Group', $group->id, $group->name);

        $this->saveGroupAttributes($group, (array) $request->input('attributes', []));

        // Sync product assignments
        $newProductIds = array_map('intval', array_filter($data['product_ids'] ?? []));
        $existingProductIds = $group->groupProducts()->pluck('product_id')->toArray();

        $toAdd = array_diff($newProductIds, $existingProductIds);
        $toRemove = array_diff($existingProductIds, $newProductIds);

        foreach ($toAdd as $pid) {
            ShopeeProductGroupProduct::create([
                'shopee_product_group_id' => $group->id,
                'product_id' => $pid,
            ]);
        }

        if (!empty($toRemove)) {
            $group->groupProducts()->whereIn('product_id', $toRemove)->delete();
        }

        return redirect()->route('shopee.product-groups.edit', $group->id)
            ->with('status', 'Product group saved.');
    }

    public function destroy(int $id)
    {
        $group = ShopeeProductGroup::query()->findOrFail($id);
        $name = $group->name;
        $group->delete();

        ActivityLogger::log('deleted', 'Shopee Product Group', (int) $id, $name);

        return redirect()->route('shopee.product-groups.index')
            ->with('status', 'Product group "' . e($name) . '" deleted.');
    }

    /**
     * Products page for a specific product group.
     */
    public function products(Request $request, int $id)
    {
        $group = ShopeeProductGroup::query()->findOrFail($id);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $q = trim((string) $request->input('q'));
        $syncStatus = (string) $request->input('sync_status', 'all');
        $erpStatus = (string) $request->input('erp_status', 'all');

        $pivotTable = 'shopee_product_group_products';

        $query = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->join($pivotTable . ' as gp', function ($j) use ($group) {
                $j->on('p.product_id', '=', 'gp.product_id')
                    ->where('gp.shopee_product_group_id', '=', $group->id);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->select(
                'p.product_id', 'pd.name', 'p.sku', 'p.model', 'p.price',
                'p.quantity', 'p.image', 'p.status',
                'm.name as manufacturer_name',
                'gp.shopee_item_id', 'gp.sync_status', 'gp.last_pushed_at', 'gp.push_error'
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
            $query->whereNotNull('gp.shopee_item_id')->where('gp.shopee_item_id', '!=', '');
        } elseif ($syncStatus === 'pending') {
            $query->where(function ($w) {
                $w->whereNull('gp.shopee_item_id')->orWhere('gp.shopee_item_id', '');
            })->where('gp.sync_status', '!=', 'error');
        } elseif ($syncStatus === 'error') {
            $query->where('gp.sync_status', 'error');
        }

        $products = $query->orderBy('p.product_id', 'desc')->paginate(50)->appends($request->except('page'));

        // Pivot map for templates
        $pivotProductIds = $products->pluck('product_id')->toArray();
        $pivotMap = ShopeeProductGroupProduct::query()
            ->where('shopee_product_group_id', $group->id)
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

        // All product IDs in this group (for "Remove from Group" action)
        $manualIds = $group->groupProducts()->pluck('product_id')->toArray();

        return view('shopee.product-groups.products', [
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
        $group = ShopeeProductGroup::query()->findOrFail($id);

        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));
        if (empty($ids)) {
            return $this->productsRedirect($group->id)
                ->with('error', 'No products selected.');
        }

        $existing = $group->groupProducts()->whereIn('product_id', $ids)->pluck('product_id')->toArray();
        $added = 0;
        foreach ($ids as $pid) {
            if (in_array($pid, $existing)) continue;
            ShopeeProductGroupProduct::create([
                'shopee_product_group_id' => $group->id,
                'product_id' => $pid,
            ]);
            $added++;
        }

        return $this->productsRedirect($group->id)
            ->with('status', "{$added} product(s) added to group.");
    }

    /**
     * Remove a single product from the group.
     */
    public function removeProduct(int $groupId, int $productId)
    {
        $group = ShopeeProductGroup::query()->findOrFail($groupId);
        $group->groupProducts()->where('product_id', $productId)->delete();

        return $this->productsRedirect($group->id)
            ->with('status', 'Product removed from group.');
    }

    /**
     * Bulk remove products from the group.
     */
    public function massRemove(Request $request, int $id)
    {
        $group = ShopeeProductGroup::query()->findOrFail($id);
        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));

        if (!empty($ids)) {
            $group->groupProducts()->whereIn('product_id', $ids)->delete();
        }

        return $this->productsRedirect($group->id)
            ->with('status', count($ids) . ' product(s) removed from group.');
    }

    public function push(Request $request, int $id)
    {
        return $this->productsRedirect($id)->with('error', 'Push to Shopee is not yet implemented.');
    }

    public function pushPrices(Request $request, int $id)
    {
        return $this->productsRedirect($id)->with('error', 'Sync Price for Shopee is not yet implemented.');
    }

    public function pushStock(Request $request, int $id)
    {
        return $this->productsRedirect($id)->with('error', 'Sync Qty for Shopee is not yet implemented.');
    }

    public function deleteFromShopee(Request $request, int $id)
    {
        return $this->productsRedirect($id)->with('error', 'Delete from Shopee is not yet implemented.');
    }

    public function syncTemplate(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::query()->findOrFail($id);

        $request->validate(['shopee_category_id' => 'required|integer|min:1']);
        $shopeeCategoryId = (int) $request->input('shopee_category_id');
        $group->update(['shopee_category_id' => $shopeeCategoryId]);

        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return redirect()->route('shopee.product-groups.edit', $group->id)
                ->with('error', 'Missing Shopee settings.');
        }

        $path = '/api/v2/product/get_attribute_tree';

        $result = $client->shopGet(
            $setting->mode ?? 'sandbox',
            (int)$setting->partner_id,
            (string)$setting->partner_key,
            (string)$setting->access_token,
            (int)$setting->shop_id,
            $path,
            ['category_id' => $shopeeCategoryId, 'language' => $setting->region ?: 'en']
        );

        $body = $result['body'] ?? null;

        if (!($result['ok'] ?? false)) {
            $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'API call failed')) : 'API call failed';
            return redirect()->route('shopee.product-groups.edit', $group->id)
                ->with('error', 'Sync failed: ' . $msg);
        }

        // Try multiple response keys — Shopee may use attribute_list or attribute_tree
        $attrList = null;
        if (is_array($body)) {
            $response = $body['response'] ?? $body;
            $attrList = $response['attribute_list']
                ?? $response['attribute_tree']
                ?? $response['attributes']
                ?? null;
        }

        ShopeeCategoryTemplate::query()->updateOrCreate(
            ['category_id' => $shopeeCategoryId],
            [
                'region' => (string)($setting->region ?? ''),
                'attributes' => $attrList ?? ($response ?? $body),
                'fetched_at' => now(),
            ]
        );

        $count = is_array($attrList) ? count($attrList) : 0;
        $debugKeys = is_array($response ?? null) ? implode(', ', array_keys($response)) : 'n/a';

        return redirect()->route('shopee.product-groups.edit', $group->id)
            ->with('status', "Category template synced. Attributes found: {$count}. Response keys: {$debugKeys}");
    }

    // -- Helpers --

    private function saveGroupAttributes(ShopeeProductGroup $group, array $attrs): void
    {
        foreach ($attrs as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') continue;

            if ($value === null || $value === '') {
                ShopeeProductGroupAttribute::query()
                    ->where('shopee_product_group_id', $group->id)
                    ->where('attribute_key', $key)
                    ->delete();
            } else {
                ShopeeProductGroupAttribute::query()->updateOrCreate(
                    ['shopee_product_group_id' => $group->id, 'attribute_key' => $key],
                    ['value' => (string) $value]
                );
            }
        }
    }

    private function extractAttributes($data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $list = $data;
        if (isset($data['attribute_list']) && is_array($data['attribute_list'])) {
            $list = $data['attribute_list'];
        }

        if (!is_array($list) || empty($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $a) {
            if (!is_array($a)) continue;

            $name = (string)($a['original_attribute_name'] ?? $a['display_attribute_name'] ?? $a['attribute_name'] ?? '');
            $id = $a['attribute_id'] ?? null;
            $required = (bool)($a['is_mandatory'] ?? $a['mandatory'] ?? false);
            $inputType = (string)($a['input_type'] ?? $a['input_validation_type'] ?? 'text');
            $options = $a['attribute_value_list'] ?? $a['options'] ?? $a['values'] ?? null;

            $key = is_scalar($id) ? (string)$id : ($name !== '' ? $name : '');
            if ($key === '') continue;

            $out[] = [
                'key' => $key,
                'name' => $name !== '' ? $name : $key,
                'required' => $required,
                'input_type' => $inputType,
                'options' => is_array($options) ? $options : [],
            ];
        }
        return $out;
    }

    /**
     * AJAX: Refresh categories from Shopee API and return updated list.
     */
    public function refreshCategories(ShopeeClient $client)
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return response()->json(['ok' => false, 'message' => 'Missing Shopee settings.']);
        }

        $result = $client->shopGet(
            $setting->mode ?? 'sandbox',
            (int) $setting->partner_id,
            (string) $setting->partner_key,
            (string) $setting->access_token,
            (int) $setting->shop_id,
            '/api/v2/product/get_category',
            ['language' => $setting->region ?: 'en']
        );

        $body = $result['body'] ?? null;
        $nodes = [];
        if (is_array($body)) {
            $response = $body['response'] ?? $body;
            if (isset($response['category_list']) && is_array($response['category_list'])) {
                $nodes = $response['category_list'];
            }
        }

        if (!($result['ok'] ?? false) || empty($nodes)) {
            $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'Failed')) : 'Failed';
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
            DB::table('shopee_categories')->delete();
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('shopee_categories')->insert($chunk);
            }
        });

        $categories = ShopeeCategory::query()->orderBy('name')->limit(5000)->get(['category_id', 'name']);
        return response()->json(['ok' => true, 'count' => count($rows), 'categories' => $categories]);
    }

    private function flattenCategoryTree(array $nodes, array &$rows, ?int $parentId, int $level): void
    {
        foreach ($nodes as $n) {
            if (!is_array($n)) continue;
            $categoryId = isset($n['category_id']) ? (int) $n['category_id'] : null;
            $name = (string) ($n['original_category_name'] ?? $n['display_category_name'] ?? $n['category_name'] ?? '');
            $hasChildren = (bool) ($n['has_children'] ?? false);
            if (!$categoryId || $name === '') continue;
            $rows[] = [
                'category_id' => $categoryId,
                'parent_id' => $n['parent_category_id'] ?? $parentId,
                'name' => $name,
                'level' => $level,
                'leaf' => !$hasChildren,
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
    public function fetchAttributesAjax(Request $request, ShopeeClient $client)
    {
        $categoryId = (int) $request->input('shopee_category_id', 0);
        if ($categoryId <= 0) {
            return response()->json(['ok' => false, 'html' => '']);
        }

        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            return response()->json(['ok' => false, 'html' => '<div class="text-muted">Missing Shopee settings.</div>']);
        }

        $result = $client->shopGet(
            $setting->mode ?? 'sandbox',
            (int) $setting->partner_id,
            (string) $setting->partner_key,
            (string) $setting->access_token,
            (int) $setting->shop_id,
            '/api/v2/product/get_attribute_tree',
            ['category_id' => $categoryId, 'language' => $setting->region ?: 'en']
        );

        $body = $result['body'] ?? null;
        $attrList = null;
        if (is_array($body)) {
            $response = $body['response'] ?? $body;
            $attrList = $response['attribute_list'] ?? $response['attribute_tree'] ?? $response['attributes'] ?? null;
        }

        $template = ShopeeCategoryTemplate::query()->updateOrCreate(
            ['category_id' => $categoryId],
            [
                'region' => (string) ($setting->region ?? ''),
                'attributes' => $attrList ?? ($response ?? $body),
                'fetched_at' => now(),
            ]
        );

        $attributes = $this->extractAttributes($template->attributes);

        $html = view('shopee.product-groups._attributes', [
            'attributes' => $attributes,
            'saved' => [],
            'template' => $template,
        ])->render();

        return response()->json(['ok' => true, 'html' => $html, 'count' => count($attributes)]);
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

}
