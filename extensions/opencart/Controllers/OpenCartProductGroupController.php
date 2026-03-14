<?php

namespace Extensions\opencart\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\opencart\Models\OpenCartProductLink;
use Extensions\opencart\Models\OpenCartProductGroup;
use Extensions\opencart\Models\OpenCartProductGroupProduct;
use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Services\OpenCart\OpenCartClient;
use Extensions\opencart\Services\OpenCart\OpenCartProductSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OpenCartProductGroupController extends Controller
{
    private function store_(int $storeId): OpenCartSetting
    {
        return OpenCartSetting::where('id', $storeId)->where('enabled', true)->firstOrFail();
    }

    public function index(int $store)
    {
        $setting = $this->store_($store);
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $groups = OpenCartProductGroup::where('opencart_setting_id', $store)->orderByDesc('id')->get();

        // Category names
        $catIds = $groups->pluck('catalog_category_ids')->filter()->flatten()->unique()->values()->all();
        $categoryNames = collect();
        if (!empty($catIds)) {
            $categoryNames = DB::table($pfx . 'category_description')
                ->whereIn('category_id', $catIds)
                ->where('language_id', $langId)
                ->pluck('name', 'category_id');
        }

        // Manufacturer names
        $mfgIds = $groups->pluck('manufacturer_ids')->filter()->flatten()->unique()->values()->all();
        $manufacturerNames = collect();
        if (!empty($mfgIds)) {
            $manufacturerNames = DB::table($pfx . 'manufacturer')
                ->whereIn('manufacturer_id', $mfgIds)
                ->pluck('name', 'manufacturer_id');
        }

        // Product counts per group (filter-matched + manual)
        $productCounts = [];
        foreach ($groups as $g) {
            $productCounts[$g->id] = count($this->getMatchingProductIds($g));
        }

        return view('ext-opencart::product-groups.index', [
            'setting' => $setting,
            'groups' => $groups,
            'categoryNames' => $categoryNames,
            'manufacturerNames' => $manufacturerNames,
            'productCounts' => $productCounts,
        ]);
    }

    public function create(int $store)
    {
        $setting = $this->store_($store);
        return $this->form($setting, new OpenCartProductGroup(['opencart_setting_id' => $store]), 'create');
    }

    public function edit(int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = OpenCartProductGroup::where('opencart_setting_id', $store)->findOrFail($group);
        return $this->form($setting, $g, 'edit');
    }

    private function form(OpenCartSetting $setting, OpenCartProductGroup $group, string $mode)
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

        $productCount = count($this->getMatchingProductIds($group));

        return view('ext-opencart::product-groups.form', [
            'setting' => $setting,
            'group' => $group,
            'mode' => $mode,
            'catalogCategories' => $catalogCategories,
            'manufacturers' => $manufacturers,
            'productCount' => $productCount,
        ]);
    }

    public function store(Request $request, int $store)
    {
        $setting = $this->store_($store);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'catalog_category_ids' => 'nullable|array',
            'catalog_category_ids.*' => 'integer|min:1',
            'manufacturer_ids' => 'nullable|array',
            'manufacturer_ids.*' => 'integer|min:1',
        ]);

        $catIds = array_filter($data['catalog_category_ids'] ?? []);
        $mfgIds = array_filter($data['manufacturer_ids'] ?? []);

        $group = OpenCartProductGroup::create([
            'opencart_setting_id' => $store,
            'name' => $data['name'],
            'catalog_category_ids' => !empty($catIds) ? array_values(array_map('intval', $catIds)) : null,
            'manufacturer_ids' => !empty($mfgIds) ? array_values(array_map('intval', $mfgIds)) : null,
        ]);

        ActivityLogger::log('created', 'OpenCart Product Group', $group->id, $group->name);

        return redirect()->route('ext.opencart.product-groups.edit', [$store, $group->id])
            ->with('status', 'Product group created.');
    }

    public function update(Request $request, int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = OpenCartProductGroup::where('opencart_setting_id', $store)->findOrFail($group);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'catalog_category_ids' => 'nullable|array',
            'catalog_category_ids.*' => 'integer|min:1',
            'manufacturer_ids' => 'nullable|array',
            'manufacturer_ids.*' => 'integer|min:1',
        ]);

        $catIds = array_filter($data['catalog_category_ids'] ?? []);
        $mfgIds = array_filter($data['manufacturer_ids'] ?? []);

        $g->update([
            'name' => $data['name'],
            'catalog_category_ids' => !empty($catIds) ? array_values(array_map('intval', $catIds)) : null,
            'manufacturer_ids' => !empty($mfgIds) ? array_values(array_map('intval', $mfgIds)) : null,
        ]);

        ActivityLogger::log('updated', 'OpenCart Product Group', $g->id, $g->name);

        return redirect()->route('ext.opencart.product-groups.edit', [$store, $g->id])
            ->with('status', 'Product group saved.');
    }

    public function destroy(int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = OpenCartProductGroup::where('opencart_setting_id', $store)->findOrFail($group);
        $name = $g->name;
        $g->delete();

        ActivityLogger::log('deleted', 'OpenCart Product Group', (int) $group, $name);

        return redirect()->route('ext.opencart.product-groups.index', $store)
            ->with('status', 'Product group "' . e($name) . '" deleted.');
    }

    public function products(Request $request, int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = OpenCartProductGroup::where('opencart_setting_id', $store)->findOrFail($group);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productIds = $this->getMatchingProductIds($g);

        if (empty($productIds)) {
            return view('ext-opencart::product-groups.products', [
                'setting' => $setting,
                'group' => $g,
                'products' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, 50),
                'links' => collect(),
                'manualIds' => [],
            ]);
        }

        $q = trim((string) $request->input('q'));

        $query = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->whereIn('p.product_id', $productIds)
            ->select('p.product_id', 'pd.name', 'p.model', 'p.sku', 'p.price', 'p.quantity', 'p.status', 'p.image');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%")
                    ->orWhere('p.model', 'like', "%{$q}%");
            });
        }

        $products = $query->orderBy('p.product_id', 'desc')->paginate(50)->withQueryString();

        // OC product links for push status
        $links = OpenCartProductLink::where('opencart_setting_id', $store)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        // Other OC store links
        $otherOcLinks = OpenCartProductLink::where('opencart_setting_id', '!=', $store)
            ->whereIn('product_id', $productIds)
            ->get()
            ->groupBy('product_id');

        $otherStoreNames = DB::table('opencart_settings')
            ->where('id', '!=', $store)
            ->where('enabled', true)
            ->pluck('store_name', 'id');

        // Marketplace sync status (fast local lookups)
        $shopeeLinked = DB::table('shopee_product_links')
            ->whereIn('product_id', $productIds)
            ->pluck('shopee_item_id', 'product_id');

        $lazadaLinked = DB::table('lazada_products')
            ->whereIn('product_id', $productIds)
            ->where('product_id', '>', 0)
            ->pluck('lazada_item_id', 'product_id');

        $tiktokLinked = DB::table('tiktok_product_group_products')
            ->whereIn('product_id', $productIds)
            ->whereNotNull('tiktok_product_id')
            ->pluck('tiktok_product_id', 'product_id');

        $manualIds = $g->groupProducts()->pluck('product_id')->toArray();

        return view('ext-opencart::product-groups.products', [
            'setting' => $setting,
            'group' => $g,
            'products' => $products,
            'links' => $links,
            'otherOcLinks' => $otherOcLinks,
            'otherStoreNames' => $otherStoreNames,
            'shopeeLinked' => $shopeeLinked,
            'lazadaLinked' => $lazadaLinked,
            'tiktokLinked' => $tiktokLinked,
            'manualIds' => $manualIds,
            'q' => $q,
        ]);
    }

    public function push(Request $request, int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = OpenCartProductGroup::where('opencart_setting_id', $store)->findOrFail($group);

        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return redirect()->route('ext.opencart.product-groups.products', [$store, $group])
                ->with('status', 'No products selected.');
        }

        $ids = array_map('intval', (array) $ids);

        $client = new OpenCartClient($setting);

        // Ping test
        $ping = $client->ping();
        if (!$ping['ok']) {
            return redirect()->route('ext.opencart.product-groups.products', [$store, $group])
                ->with('status', 'Cannot connect to OpenCart: ' . ($ping['body']['error'] ?? 'unknown'));
        }

        $sync = new OpenCartProductSync($client, $setting);
        $result = $sync->push($ids);

        $msg = "Updated: {$result['updated']}, Created: {$result['created']}, Failed: {$result['failed']}";

        return redirect()->route('ext.opencart.product-groups.products', [$store, $group])
            ->with('status', $msg);
    }

    public function addProducts(Request $request, int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = OpenCartProductGroup::where('opencart_setting_id', $store)->findOrFail($group);

        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return redirect()->route('ext.opencart.product-groups.products', [$store, $group])
                ->with('status', 'No products selected.');
        }

        $ids = array_map('intval', (array) $ids);
        $existing = $g->groupProducts()->pluck('product_id')->toArray();
        $added = 0;

        foreach ($ids as $pid) {
            if (in_array($pid, $existing)) continue;
            OpenCartProductGroupProduct::create([
                'opencart_product_group_id' => $g->id,
                'product_id' => $pid,
            ]);
            $added++;
        }

        return redirect()->route('ext.opencart.product-groups.products', [$store, $group])
            ->with('status', "{$added} product(s) added to product group.");
    }

    public function removeProduct(int $store, int $group, int $product)
    {
        $setting = $this->store_($store);
        $g = OpenCartProductGroup::where('opencart_setting_id', $store)->findOrFail($group);

        OpenCartProductGroupProduct::where('opencart_product_group_id', $g->id)
            ->where('product_id', $product)
            ->delete();

        return redirect()->route('ext.opencart.product-groups.products', [$store, $group])
            ->with('status', 'Product removed from product group.');
    }

    public function searchProducts(Request $request, int $store)
    {
        $this->store_($store);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->input('q'));

        if (strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        $rows = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%")
                    ->orWhere('p.model', 'like', "%{$q}%");
            })
            ->select('p.product_id', 'pd.name', 'p.sku', 'p.price')
            ->orderBy('p.product_id', 'desc')
            ->limit(50)
            ->get();

        return response()->json(['items' => $rows]);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function getMatchingProductIds(OpenCartProductGroup $group): array
    {
        $pfx = (string) config('catalog.prefix');
        $catIds = $group->catalog_category_ids ?? [];
        $mfgIds = $group->manufacturer_ids ?? [];

        $filterIds = [];

        if (!empty($catIds) || !empty($mfgIds)) {
            $query = DB::table($pfx . 'product as p')->select('p.product_id');

            if (!empty($catIds)) {
                $query->join($pfx . 'product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                    ->whereIn('ptc.category_id', $catIds);
            }

            if (!empty($mfgIds)) {
                $query->whereIn('p.manufacturer_id', $mfgIds);
            }

            $filterIds = $query->distinct()->pluck('p.product_id')->toArray();
        }

        // Manual products
        $manualIds = [];
        if ($group->exists) {
            $manualIds = $group->groupProducts()->pluck('product_id')->toArray();
        }

        return array_values(array_unique(array_merge($filterIds, $manualIds)));
    }
}
