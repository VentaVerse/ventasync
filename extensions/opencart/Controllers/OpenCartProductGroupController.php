<?php

namespace Extensions\OpenCart\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\OpenCart\Models\OpenCartListingLink;
use Extensions\OpenCart\Models\OpenCartProductGroup;
use Extensions\OpenCart\Models\OpenCartProductGroupProduct;
use Extensions\OpenCart\Models\OpenCartStore;
use Extensions\OpenCart\Services\OpenCart\OpenCartClient;
use Extensions\OpenCart\Services\OpenCart\OpenCartProductSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OpenCartProductGroupController extends Controller
{
    private function store_(int $storeId): OpenCartStore
    {
        return OpenCartStore::findOrFail($storeId);
    }

    public function index(int $store)
    {
        $setting = $this->store_($store);
        $langId = (int) config('catalog.default_language_id');

        $groups = OpenCartProductGroup::where('store_id', $store)->orderByDesc('id')->get();

        $productCounts = [];
        foreach ($groups as $g) {
            $productCounts[$g->id] = count($this->getMatchingProductIds($g));
        }

        return view('ext-opencart::product-groups.index', [
            'setting' => $setting,
            'groups' => $groups,
            'productCounts' => $productCounts,
        ]);
    }

    public function create(int $store)
    {
        $setting = $this->store_($store);
        return $this->form($setting, new OpenCartProductGroup(['store_id' => $store]), 'create');
    }

    public function edit(int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = OpenCartProductGroup::where('store_id', $store)->findOrFail($group);
        return $this->form($setting, $g, 'edit');
    }

    private function form(OpenCartStore $store, OpenCartProductGroup $group, string $mode)
    {
        $langId = (int) config('catalog.default_language_id');

        $catalogCategories = DB::table('category_descriptions')
            ->where('language_id', $langId)
            ->orderBy('name')
            ->get(['category_id', 'name']);

        $brands = DB::table('brands')
            ->orderBy('name')
            ->get(['brand_id', 'name']);

        $productCount = count($this->getMatchingProductIds($group));

        return view('ext-opencart::product-groups.form', [
            'setting' => $store,
            'group' => $group,
            'mode' => $mode,
            'catalogCategories' => $catalogCategories,
            'brands' => $brands,
            'productCount' => $productCount,
        ]);
    }

    public function store(Request $request, int $store)
    {
        $setting = $this->store_($store);

        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);


        $group = OpenCartProductGroup::create([
            'store_id' => $store,
            'name' => $data['name'],
        ]);

        ActivityLogger::log('created', 'OpenCart Product Group', $group->id, $group->name);

        return redirect()->route('ext.opencart.product-groups.edit', [$store, $group->id])
            ->with('status', 'Product group created.');
    }

    public function update(Request $request, int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = OpenCartProductGroup::where('store_id', $store)->findOrFail($group);

        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);


        $g->update([
            'name' => $data['name'],
        ]);

        ActivityLogger::log('updated', 'OpenCart Product Group', $g->id, $g->name);

        return redirect()->route('ext.opencart.product-groups.edit', [$store, $g->id])
            ->with('status', 'Product group saved.');
    }

    public function destroy(int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = OpenCartProductGroup::where('store_id', $store)->findOrFail($group);
        $name = $g->name;
        $g->delete();

        ActivityLogger::log('deleted', 'OpenCart Product Group', (int) $group, $name);

        return redirect()->route('ext.opencart.product-groups.index', $store)
            ->with('status', 'Product group "' . e($name) . '" deleted.');
    }

    public function products(Request $request, int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = OpenCartProductGroup::where('store_id', $store)->findOrFail($group);
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

        $query = DB::table('products as p')
            ->join('product_descriptions as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->whereIn('p.product_id', $productIds)
            ->select('p.product_id', 'pd.name', 'p.sku', 'p.price', 'p.quantity', 'p.status', 'p.image');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%");
            });
        }

        $products = $query->orderBy('p.product_id', 'desc')->paginate(50)->withQueryString();

        $links = OpenCartListingLink::where('store_id', $store)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        $manualIds = $g->groupProducts()->pluck('product_id')->toArray();

        return view('ext-opencart::product-groups.products', [
            'setting' => $setting,
            'group' => $g,
            'products' => $products,
            'links' => $links,
            'manualIds' => $manualIds,
            'q' => $q,
        ]);
    }

    public function push(Request $request, int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = OpenCartProductGroup::where('store_id', $store)->findOrFail($group);

        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return redirect()->route('ext.opencart.product-groups.products', [$store, $group])
                ->with('status', 'No products selected.');
        }

        $ids = array_map('intval', (array) $ids);

        $client = new OpenCartClient($setting);

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
        $g = OpenCartProductGroup::where('store_id', $store)->findOrFail($group);

        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return redirect()->route('ext.opencart.product-groups.products', [$store, $group])
                ->with('status', 'No products selected.');
        }

        $ids = array_map('intval', (array) $ids);
        $existing = $g->groupProducts()->pluck('product_id')->toArray();
        $ids = array_values(array_diff($ids, $existing));

        $claim = \App\Channels\OneGroupRule::claim('ch_opencart_product_group_products', 'opencart_product_group_id', 'ch_opencart_product_groups', 'store_id', $g->id, $ids, array_map('intval', (array) $request->input('move_ids', [])));

        $added = 0;
        foreach (array_merge($claim['free'], array_keys($claim['moved'])) as $pid) {
            OpenCartProductGroupProduct::create([
                'opencart_product_group_id' => $g->id,
                'product_id' => $pid,
            ]);
            $added++;
        }

        return redirect()->route('ext.opencart.product-groups.products', [$store, $group])
            ->with($added > 0 ? 'status' : 'warning', "{$added} product(s) added to product group."
                . \App\Channels\OneGroupRule::heldClause($claim['held']));
    }

    public function removeProduct(int $store, int $group, int $product)
    {
        $setting = $this->store_($store);
        $g = OpenCartProductGroup::where('store_id', $store)->findOrFail($group);

        OpenCartProductGroupProduct::where('opencart_product_group_id', $g->id)
            ->where('product_id', $product)
            ->delete();

        return redirect()->route('ext.opencart.product-groups.products', [$store, $group])
            ->with('status', 'Product removed from product group.');
    }

    public function searchProducts(Request $request, int $store)
    {
        $this->store_($store);
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->input('q'));

        if (strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        $rows = DB::table('products as p')
            ->join('product_descriptions as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%");
            })
            ->select('p.product_id', 'pd.name', 'p.sku', 'p.price')
            ->orderBy('p.product_id', 'desc')
            ->limit(50)
            ->get();

        $owners = DB::table('ch_opencart_product_group_products as pv')
            ->join('ch_opencart_product_groups as g', 'g.id', '=', 'pv.opencart_product_group_id')
            ->whereIn('pv.product_id', $rows->pluck('product_id')->all() ?: [0])
            ->where('g.store_id', $store)
            ->get(['pv.product_id', 'g.id as group_id', 'g.name'])
            ->keyBy('product_id');
        $rows = $rows->map(function ($r) use ($owners) {
            $own = $owners->get($r->product_id);
            $r->group_id = $own->group_id ?? null;
            $r->group_name = $own->name ?? null;

            return $r;
        });

        return response()->json(['items' => $rows->values()]);
    }

    private function getMatchingProductIds(OpenCartProductGroup $group): array
    {
        return $group->exists
            ? $group->groupProducts()->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all()
            : [];
    }
}
