<?php

namespace App\Http\Controllers;

use App\Models\OpenCartProductLink;
use App\Models\OpenCartProfile;
use App\Models\OpenCartProfileProduct;
use App\Models\OpenCartSetting;
use App\Services\OpenCart\OpenCartClient;
use App\Services\OpenCart\OpenCartProductSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OpenCartProfileController extends Controller
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

        $profiles = OpenCartProfile::where('opencart_setting_id', $store)->orderByDesc('id')->get();

        // Category names
        $catIds = $profiles->pluck('catalog_category_ids')->filter()->flatten()->unique()->values()->all();
        $categoryNames = collect();
        if (!empty($catIds)) {
            $categoryNames = DB::table($pfx . 'category_description')
                ->whereIn('category_id', $catIds)
                ->where('language_id', $langId)
                ->pluck('name', 'category_id');
        }

        // Manufacturer names
        $mfgIds = $profiles->pluck('manufacturer_ids')->filter()->flatten()->unique()->values()->all();
        $manufacturerNames = collect();
        if (!empty($mfgIds)) {
            $manufacturerNames = DB::table($pfx . 'manufacturer')
                ->whereIn('manufacturer_id', $mfgIds)
                ->pluck('name', 'manufacturer_id');
        }

        // Product counts per profile (filter-matched + manual)
        $productCounts = [];
        foreach ($profiles as $p) {
            $productCounts[$p->id] = count($this->getMatchingProductIds($p));
        }

        return view('opencart.profiles.index', [
            'setting' => $setting,
            'profiles' => $profiles,
            'categoryNames' => $categoryNames,
            'manufacturerNames' => $manufacturerNames,
            'productCounts' => $productCounts,
        ]);
    }

    public function create(int $store)
    {
        $setting = $this->store_($store);
        return $this->form($setting, new OpenCartProfile(['opencart_setting_id' => $store]), 'create');
    }

    public function edit(int $store, int $profile)
    {
        $setting = $this->store_($store);
        $p = OpenCartProfile::where('opencart_setting_id', $store)->findOrFail($profile);
        return $this->form($setting, $p, 'edit');
    }

    private function form(OpenCartSetting $setting, OpenCartProfile $profile, string $mode)
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

        $productCount = count($this->getMatchingProductIds($profile));

        return view('opencart.profiles.form', [
            'setting' => $setting,
            'profile' => $profile,
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

        $profile = OpenCartProfile::create([
            'opencart_setting_id' => $store,
            'name' => $data['name'],
            'catalog_category_ids' => !empty($catIds) ? array_values(array_map('intval', $catIds)) : null,
            'manufacturer_ids' => !empty($mfgIds) ? array_values(array_map('intval', $mfgIds)) : null,
        ]);

        return redirect()->route('opencart.profiles.edit', [$store, $profile->id])
            ->with('status', 'Profile created.');
    }

    public function update(Request $request, int $store, int $profile)
    {
        $setting = $this->store_($store);
        $p = OpenCartProfile::where('opencart_setting_id', $store)->findOrFail($profile);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'catalog_category_ids' => 'nullable|array',
            'catalog_category_ids.*' => 'integer|min:1',
            'manufacturer_ids' => 'nullable|array',
            'manufacturer_ids.*' => 'integer|min:1',
        ]);

        $catIds = array_filter($data['catalog_category_ids'] ?? []);
        $mfgIds = array_filter($data['manufacturer_ids'] ?? []);

        $p->update([
            'name' => $data['name'],
            'catalog_category_ids' => !empty($catIds) ? array_values(array_map('intval', $catIds)) : null,
            'manufacturer_ids' => !empty($mfgIds) ? array_values(array_map('intval', $mfgIds)) : null,
        ]);

        return redirect()->route('opencart.profiles.edit', [$store, $p->id])
            ->with('status', 'Profile saved.');
    }

    public function destroy(int $store, int $profile)
    {
        $setting = $this->store_($store);
        $p = OpenCartProfile::where('opencart_setting_id', $store)->findOrFail($profile);
        $name = $p->name;
        $p->delete();

        return redirect()->route('opencart.profiles.index', $store)
            ->with('status', 'Profile "' . e($name) . '" deleted.');
    }

    public function products(Request $request, int $store, int $profile)
    {
        $setting = $this->store_($store);
        $p = OpenCartProfile::where('opencart_setting_id', $store)->findOrFail($profile);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productIds = $this->getMatchingProductIds($p);

        if (empty($productIds)) {
            return view('opencart.profiles.products', [
                'setting' => $setting,
                'profile' => $p,
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

        $manualIds = $p->profileProducts()->pluck('product_id')->toArray();

        return view('opencart.profiles.products', [
            'setting' => $setting,
            'profile' => $p,
            'products' => $products,
            'links' => $links,
            'manualIds' => $manualIds,
            'q' => $q,
        ]);
    }

    public function push(Request $request, int $store, int $profile)
    {
        $setting = $this->store_($store);
        $p = OpenCartProfile::where('opencart_setting_id', $store)->findOrFail($profile);

        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return redirect()->route('opencart.profiles.products', [$store, $profile])
                ->with('status', 'No products selected.');
        }

        $ids = array_map('intval', (array) $ids);

        $client = new OpenCartClient($setting);

        // Ping test
        $ping = $client->ping();
        if (!$ping['ok']) {
            return redirect()->route('opencart.profiles.products', [$store, $profile])
                ->with('status', 'Cannot connect to OpenCart: ' . ($ping['body']['error'] ?? 'unknown'));
        }

        $sync = new OpenCartProductSync($client, $setting);
        $result = $sync->push($ids);

        $msg = "Updated: {$result['updated']}, Created: {$result['created']}, Failed: {$result['failed']}";

        return redirect()->route('opencart.profiles.products', [$store, $profile])
            ->with('status', $msg);
    }

    public function addProducts(Request $request, int $store, int $profile)
    {
        $setting = $this->store_($store);
        $p = OpenCartProfile::where('opencart_setting_id', $store)->findOrFail($profile);

        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return redirect()->route('opencart.profiles.products', [$store, $profile])
                ->with('status', 'No products selected.');
        }

        $ids = array_map('intval', (array) $ids);
        $existing = $p->profileProducts()->pluck('product_id')->toArray();
        $added = 0;

        foreach ($ids as $pid) {
            if (in_array($pid, $existing)) continue;
            OpenCartProfileProduct::create([
                'opencart_profile_id' => $p->id,
                'product_id' => $pid,
            ]);
            $added++;
        }

        return redirect()->route('opencart.profiles.products', [$store, $profile])
            ->with('status', "{$added} product(s) added to profile.");
    }

    public function removeProduct(int $store, int $profile, int $product)
    {
        $setting = $this->store_($store);
        $p = OpenCartProfile::where('opencart_setting_id', $store)->findOrFail($profile);

        OpenCartProfileProduct::where('opencart_profile_id', $p->id)
            ->where('product_id', $product)
            ->delete();

        return redirect()->route('opencart.profiles.products', [$store, $profile])
            ->with('status', 'Product removed from profile.');
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

    private function getMatchingProductIds(OpenCartProfile $profile): array
    {
        $pfx = (string) config('catalog.prefix');
        $catIds = $profile->catalog_category_ids ?? [];
        $mfgIds = $profile->manufacturer_ids ?? [];

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
        if ($profile->exists) {
            $manualIds = $profile->profileProducts()->pluck('product_id')->toArray();
        }

        return array_values(array_unique(array_merge($filterIds, $manualIds)));
    }
}
