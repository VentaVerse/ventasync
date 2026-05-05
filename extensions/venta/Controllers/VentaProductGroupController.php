<?php

namespace Extensions\venta\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\venta\Models\VentaBrand;
use Extensions\venta\Models\VentaCategory;
use Extensions\venta\Models\VentaProductGroup;
use Extensions\venta\Models\VentaProductGroupProduct;
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VentaProductGroupController extends Controller
{
    public function navRedirect()
    {
        $store = VentaSetting::where('enabled', true)->first();
        if (!$store) {
            return redirect()->route('ext.venta.index')->with('error', 'No enabled Venta store. Add one first.');
        }
        return redirect()->route('ext.venta.product-groups.index', $store->id);
    }

    private function store_(int $storeId): VentaSetting
    {
        return VentaSetting::where('id', $storeId)->where('enabled', true)->firstOrFail();
    }

    private function productsRedirect(int $store, int $group)
    {
        $return = request()->input('_return');
        if ($return && str_starts_with($return, '/')) {
            return redirect($return);
        }
        return redirect()->route('ext.venta.product-groups.products', [$store, $group]);
    }

    // ── Group CRUD ──────────────────────────────────────────

    public function index(int $store)
    {
        $setting = $this->store_($store);

        $groups = VentaProductGroup::where('venta_setting_id', $store)->orderByDesc('id')->get();

        $productCounts = [];
        foreach ($groups as $g) {
            $productCounts[$g->id] = $g->products()->count();
        }

        return view('ext-venta::product-groups.index', [
            'setting'       => $setting,
            'groups'        => $groups,
            'productCounts' => $productCounts,
        ]);
    }

    public function create(int $store)
    {
        $setting = $this->store_($store);
        return $this->form($setting, new VentaProductGroup(['venta_setting_id' => $store]), 'create');
    }

    public function edit(int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);
        return $this->form($setting, $g, 'edit');
    }

    private function form(VentaSetting $setting, VentaProductGroup $group, string $mode)
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

        // Load current group products for the dual-listbox
        // On validation failure, restore from old('product_ids') so selections aren't lost
        $oldProductIds = old('product_ids');
        $groupProducts = collect();

        if (!empty($oldProductIds)) {
            $productIds = array_filter(array_map('intval', (array) $oldProductIds));
        } elseif ($group->exists) {
            $productIds = $group->products()->pluck('product_id')->toArray();
        } else {
            $productIds = [];
        }

        if (!empty($productIds)) {
            $groupProducts = DB::table($pfx . 'product as p')
                ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                    $j->on('p.product_id', '=', 'pd.product_id')
                        ->where('pd.language_id', '=', $langId);
                })
                ->whereIn('p.product_id', $productIds)
                ->select('p.product_id', 'pd.name', 'p.sku', 'p.model', 'p.quantity')
                ->orderBy('pd.name')
                ->get();
        }

        return view('ext-venta::product-groups.form', [
            'setting'           => $setting,
            'group'             => $group,
            'mode'              => $mode,
            'catalogCategories' => $catalogCategories,
            'manufacturers'     => $manufacturers,
            'groupProducts'     => $groupProducts,
        ]);
    }

    public function store(Request $request, int $store)
    {
        $this->store_($store);

        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'venta_category_id' => 'nullable|integer',
            'venta_brand_id'    => 'nullable|integer',
            'markup_percent'    => 'nullable|numeric|min:0',
            'markup_fixed'      => 'nullable|numeric|min:0',
        ]);

        $group = VentaProductGroup::create([
            'venta_setting_id'     => $store,
            'name'                 => $data['name'],
            'venta_category_id'    => $data['venta_category_id'] ?? null,
            'venta_brand_id'       => $data['venta_brand_id'] ?? null,
            'catalog_category_ids' => null,
            'manufacturer_ids'     => null,
            'markup_percent'       => $data['markup_percent'] ?? 0,
            'markup_fixed'         => $data['markup_fixed'] ?? 0,
        ]);

        // Add products from dual-listbox
        $productIds = array_filter(array_map('intval', (array) $request->input('product_ids', [])));
        foreach ($productIds as $pid) {
            VentaProductGroupProduct::create([
                'venta_product_group_id' => $group->id,
                'product_id'             => $pid,
            ]);
        }

        ActivityLogger::log('created', 'Venta Product Group', $group->id, $group->name);

        return redirect()->route('ext.venta.product-groups.edit', [$store, $group->id])
            ->with('status', 'Product group created with ' . count($productIds) . ' product(s).');
    }

    public function update(Request $request, int $store, int $group)
    {
        $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'venta_category_id' => 'nullable|integer',
            'venta_brand_id'    => 'nullable|integer',
            'markup_percent'    => 'nullable|numeric|min:0',
            'markup_fixed'      => 'nullable|numeric|min:0',
        ]);

        $g->update([
            'name'                 => $data['name'],
            'venta_category_id'    => $data['venta_category_id'] ?? $g->venta_category_id,
            'venta_brand_id'       => $data['venta_brand_id'] ?? $g->venta_brand_id,
            'catalog_category_ids' => null,
            'manufacturer_ids'     => null,
            'markup_percent'       => $data['markup_percent'] ?? $g->markup_percent,
            'markup_fixed'         => $data['markup_fixed'] ?? $g->markup_fixed,
        ]);

        // Sync products from dual-listbox
        $submittedIds = array_filter(array_map('intval', (array) $request->input('product_ids', [])));
        $existingPivots = $g->products()->get()->keyBy('product_id');
        $existingIds = $existingPivots->keys()->map(fn($k) => (int) $k)->toArray();

        // Add new products
        foreach (array_diff($submittedIds, $existingIds) as $pid) {
            VentaProductGroupProduct::create([
                'venta_product_group_id' => $g->id,
                'product_id'             => $pid,
            ]);
        }

        // Remove products no longer in the list
        $toRemove = array_diff($existingIds, $submittedIds);
        if (!empty($toRemove)) {
            $g->products()->whereIn('product_id', $toRemove)->delete();
        }

        ActivityLogger::log('updated', 'Venta Product Group', $g->id, $g->name);

        return redirect()->route('ext.venta.product-groups.edit', [$store, $g->id])
            ->with('status', 'Product group saved.');
    }

    public function destroy(int $store, int $group)
    {
        $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);
        $name = $g->name;
        $g->delete();

        ActivityLogger::log('deleted', 'Venta Product Group', (int) $group, $name);

        return redirect()->route('ext.venta.product-groups.index', $store)
            ->with('status', 'Product group "' . e($name) . '" deleted.');
    }

    // ── Products listing ────────────────────────────────────

    public function products(Request $request, int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productIds = $this->getMatchingProductIds($g);

        if (empty($productIds)) {
            return view('ext-venta::product-groups.products', [
                'setting'              => $setting,
                'group'                => $g,
                'products'             => new \Illuminate\Pagination\LengthAwarePaginator([], 0, 50),
                'optionRowsByProductId' => collect(),
                'manualIds'            => [],
            ]);
        }

        $q = trim((string) $request->input('q'));
        $syncStatus = (string) $request->input('sync_status', 'all');
        $erpStatus = (string) $request->input('erp_status', 'all');

        $query = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->leftJoin('venta_product_links as vpl', function ($j) use ($store) {
                $j->on('p.product_id', '=', 'vpl.product_id')
                    ->where('vpl.venta_setting_id', '=', $store);
            })
            ->leftJoin('venta_product_group_products as vpgp', function ($j) use ($g) {
                $j->on('p.product_id', '=', 'vpgp.product_id')
                    ->where('vpgp.venta_product_group_id', '=', $g->id);
            })
            ->whereIn('p.product_id', $productIds)
            ->select(
                'p.product_id',
                'pd.name',
                'p.model',
                'p.sku',
                'p.price',
                'p.quantity',
                'p.status',
                'p.image',
                'm.name as manufacturer_name',
                'vpl.venta_product_id',
                'vpl.sku as venta_sku',
                'vpgp.sync_status',
                'vpgp.last_pushed_at',
                'vpgp.push_error'
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
            $query->whereNotNull('vpl.venta_product_id')
                ->where('vpgp.sync_status', '!=', 'unlinked');
        } elseif ($syncStatus === 'pending') {
            $query->whereNull('vpl.venta_product_id')
                ->where(function ($w) {
                    $w->whereNotIn('vpgp.sync_status', ['error', 'failed', 'unlinked'])
                        ->orWhereNull('vpgp.sync_status');
                });
        } elseif ($syncStatus === 'error') {
            $query->whereIn('vpgp.sync_status', ['error', 'failed']);
        }

        $products = $query->orderBy('p.product_id', 'desc')->paginate(50)->withQueryString();

        // Option rows (variations) for display — combinations first, fallback to option values
        $paginatedIds = $products->pluck('product_id')->toArray();
        $optionRowsByProductId = collect();
        if (!empty($paginatedIds)) {
            // Check combinations first
            $combos = DB::table('product_option_combinations as poc')
                ->whereIn('poc.product_id', $paginatedIds)
                ->orderBy('poc.product_id')
                ->orderBy('poc.sort_order')
                ->get();

            $comboProductIds = $combos->pluck('product_id')->unique()->toArray();

            if ($combos->isNotEmpty()) {
                $comboIds = $combos->pluck('id')->toArray();
                $comboValues = DB::table('product_option_combination_values as pocv')
                    ->join($pfx . 'product_option_value as pov', 'pocv.product_option_value_id', '=', 'pov.product_option_value_id')
                    ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                        $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                            ->where('ovd.language_id', '=', $langId);
                    })
                    ->whereIn('pocv.combination_id', $comboIds)
                    ->select('pocv.combination_id', 'ovd.name as value_name')
                    ->get()
                    ->groupBy('combination_id');

                foreach ($combos as $c) {
                    $valueNames = ($comboValues->get($c->id) ?? collect())->pluck('value_name')->implode(' / ');
                    $row = (object) [
                        'product_id'             => $c->product_id,
                        'product_option_value_id' => $c->id,
                        'option_name'            => 'Option',
                        'option_value_name'      => $valueNames,
                        'option_quantity'         => (int) $c->quantity,
                        'option_sku'             => $c->sku,
                        'option_absolute_price'  => (float) $c->absolute_price,
                    ];
                    if (!$optionRowsByProductId->has($c->product_id)) {
                        $optionRowsByProductId[$c->product_id] = collect();
                    }
                    $optionRowsByProductId[$c->product_id]->push($row);
                }
            }

            // Fallback: option values for products without combinations
            $nonComboIds = array_diff($paginatedIds, $comboProductIds);
            if (!empty($nonComboIds)) {
                $optValues = DB::table($pfx . 'product_option_value as pov')
                    ->join($pfx . 'option_description as od', function ($j) use ($langId) {
                        $j->on('pov.option_id', '=', 'od.option_id')->where('od.language_id', '=', $langId);
                    })
                    ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                        $j->on('pov.option_value_id', '=', 'ovd.option_value_id')->where('ovd.language_id', '=', $langId);
                    })
                    ->whereIn('pov.product_id', $nonComboIds)
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

                $optionRowsByProductId = $optionRowsByProductId->union($optValues);
            }
        }

        $manualIds = $g->products()->pluck('product_id')->toArray();

        return view('ext-venta::product-groups.products', [
            'setting'              => $setting,
            'group'                => $g,
            'products'             => $products,
            'optionRowsByProductId' => $optionRowsByProductId,
            'manualIds'            => $manualIds,
            'q'                    => $q,
            'syncStatus'           => $syncStatus,
            'erpStatus'            => $erpStatus,
        ]);
    }

    // ── Sync ID (match ERP SKU to Venta product) ────────────

    public function syncId(int $store, int $group, int $product)
    {
        $setting = $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        // Verify the product is in this group
        $productIds = $this->getMatchingProductIds($g);
        if (!in_array($product, $productIds)) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'Product not in this group.');
        }

        $pfx = (string) config('catalog.prefix');
        $client = new VentaClient($setting);

        // Collect all SKUs for this product (parent SKU + model + option value SKUs)
        $prod = DB::table($pfx . 'product')->where('product_id', $product)->first(['sku', 'model']);
        if (!$prod) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'Product not found in catalog.');
        }

        $skus = [];
        if ($prod->sku && trim($prod->sku) !== '') {
            $skus[] = trim($prod->sku);
        }
        if ($prod->model && trim($prod->model) !== '' && !in_array(trim($prod->model), $skus)) {
            $skus[] = trim($prod->model);
        }

        $optSkus = DB::table($pfx . 'product_option_value')
            ->where('product_id', $product)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->pluck('sku')
            ->map(fn($s) => trim($s))
            ->unique()
            ->toArray();
        $skus = array_unique(array_merge($skus, $optSkus));

        if (empty($skus)) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'Product has no SKU to match against Venta.');
        }

        // Search Venta API by each SKU
        $matchedVentaId = null;
        $matchedSku = null;

        foreach ($skus as $sku) {
            $result = $client->getProducts(perPage: 50, sku: $sku);

            if (!($result['ok'] ?? false)) {
                continue;
            }

            $ventaProducts = $result['body']['data'] ?? $result['body'] ?? [];
            foreach ($ventaProducts as $vp) {
                $vpId = $vp['id'] ?? null;
                $vpSku = $vp['sku'] ?? null;

                if ($vpId && $vpSku !== null && strcasecmp(trim((string) $vpSku), $sku) === 0) {
                    $matchedVentaId = (int) $vpId;
                    $matchedSku = $sku;
                    break 2;
                }

                // Also check variants if present
                foreach ($vp['variants'] ?? [] as $variant) {
                    $varSku = $variant['sku'] ?? null;
                    if ($varSku !== null && strcasecmp(trim((string) $varSku), $sku) === 0) {
                        $matchedVentaId = (int) $vpId;
                        $matchedSku = $sku;
                        break 3;
                    }
                }
            }
        }

        if (!$matchedVentaId) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'No matching product found on Venta for SKU(s): ' . implode(', ', $skus));
        }

        // Save or update the link
        VentaProductLink::updateOrCreate(
            [
                'venta_setting_id' => $store,
                'product_id'       => $product,
            ],
            [
                'venta_product_id' => $matchedVentaId,
                'sku'              => $matchedSku,
            ]
        );

        // Update sync status on group product pivot
        VentaProductGroupProduct::where('venta_product_group_id', $g->id)
            ->where('product_id', $product)
            ->update([
                'sync_status' => 'synced',
                'venta_sku'   => $matchedSku,
                'push_error'  => null,
            ]);

        return $this->productsRedirect($store, $group)
            ->with('status', "Venta product ID {$matchedVentaId} synced for SKU: {$matchedSku}");
    }

    /**
     * Mass sync Venta IDs for selected products by SKU matching.
     */
    public function syncIds(Request $request, int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));
        if (empty($ids)) {
            return $this->productsRedirect($store, $group)->with('error', 'No products selected.');
        }

        $pfx = (string) config('catalog.prefix');
        $client = new VentaClient($setting);

        $synced = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($ids as $pid) {
            $prod = DB::table($pfx . 'product')->where('product_id', $pid)->first(['sku', 'model']);
            if (!$prod) { $failed++; continue; }

            $skus = [];
            if ($prod->sku && trim($prod->sku) !== '') $skus[] = trim($prod->sku);
            if ($prod->model && trim($prod->model) !== '' && !in_array(trim($prod->model), $skus)) $skus[] = trim($prod->model);

            $optSkus = DB::table($pfx . 'product_option_value')
                ->where('product_id', $pid)
                ->whereNotNull('sku')->where('sku', '!=', '')
                ->pluck('sku')->map(fn($s) => trim($s))->unique()->toArray();
            $skus = array_unique(array_merge($skus, $optSkus));

            if (empty($skus)) { $skipped++; continue; }

            // Check if already linked
            $existingLink = VentaProductLink::where('venta_setting_id', $store)
                ->where('product_id', $pid)
                ->whereNotNull('venta_product_id')
                ->first();
            if ($existingLink) { $skipped++; continue; }

            $matchedVentaId = null;
            $matchedSku = null;

            foreach ($skus as $sku) {
                $result = $client->getProducts(perPage: 50, sku: $sku);
                if (!($result['ok'] ?? false)) continue;

                $ventaProducts = $result['body']['data'] ?? $result['body'] ?? [];
                foreach ($ventaProducts as $vp) {
                    $vpId = $vp['id'] ?? null;
                    $vpSku = $vp['sku'] ?? null;

                    if ($vpId && $vpSku !== null && strcasecmp(trim((string) $vpSku), $sku) === 0) {
                        $matchedVentaId = (int) $vpId;
                        $matchedSku = $sku;
                        break 2;
                    }

                    foreach ($vp['variants'] ?? [] as $variant) {
                        $varSku = $variant['sku'] ?? null;
                        if ($varSku !== null && strcasecmp(trim((string) $varSku), $sku) === 0) {
                            $matchedVentaId = (int) $vpId;
                            $matchedSku = $sku;
                            break 3;
                        }
                    }
                }
            }

            if (!$matchedVentaId) { $failed++; continue; }

            VentaProductLink::updateOrCreate(
                ['venta_setting_id' => $store, 'product_id' => $pid],
                ['venta_product_id' => $matchedVentaId, 'sku' => $matchedSku]
            );

            VentaProductGroupProduct::where('venta_product_group_id', $g->id)
                ->where('product_id', $pid)
                ->update(['sync_status' => 'synced', 'venta_sku' => $matchedSku, 'push_error' => null]);

            $synced++;
            usleep(200000); // rate limit
        }

        $msg = "Sync complete. Linked: {$synced}";
        if ($skipped > 0) $msg .= ", Skipped (already linked/no SKU): {$skipped}";
        if ($failed > 0) $msg .= ", Not found on Venta: {$failed}";

        return $this->productsRedirect($store, $group)->with('status', $msg);
    }

    // ── Push Products (create/update on Venta) ──────────────

    public function pushProducts(Request $request, int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));
        if (empty($ids)) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'No products selected.');
        }

        $client = new VentaClient($setting);
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];

        foreach ($ids as $pid) {
            $prod = DB::table($pfx . 'product as p')
                ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                    $j->on('p.product_id', '=', 'pd.product_id')
                        ->where('pd.language_id', '=', $langId);
                })
                ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
                ->where('p.product_id', $pid)
                ->first([
                    'p.product_id', 'pd.name', 'pd.description', 'p.model', 'p.sku',
                    'p.price', 'p.cost', 'p.quantity', 'p.status', 'p.image',
                    'p.weight', 'p.length', 'p.width', 'p.height',
                    'm.name as brand_name',
                ]);

            if (!$prod) {
                $failed++;
                continue;
            }

            $sku = $prod->sku ?: $prod->model;
            if (!$sku || trim($sku) === '') {
                $failed++;
                $errors[] = "Product #{$pid}: No SKU";
                continue;
            }

            // Build product data payload
            $data = [
                'name'        => $prod->name,
                'sku'         => $sku,
                'price'       => (float) $prod->price,
                'cost'        => (float) ($prod->cost ?? 0),
                'quantity'    => (int) $prod->quantity,
                'weight'      => (float) ($prod->weight ?? 0),
                'length'      => (float) ($prod->length ?? 0),
                'width'       => (float) ($prod->width ?? 0),
                'height'      => (float) ($prod->height ?? 0),
                'status'      => (bool) $prod->status,
                'description' => $prod->description ?? '',
            ];

            // Send brand as name — Venta API will find-or-create
            if ($prod->brand_name) {
                $data['brand_name'] = $prod->brand_name;
            }

            // Send categories as names — Venta API will find-or-create
            $catNames = DB::table($pfx . 'product_to_category as ptc')
                ->join($pfx . 'category_description as cd', function ($j) use ($langId) {
                    $j->on('ptc.category_id', '=', 'cd.category_id')
                        ->where('cd.language_id', '=', $langId);
                })
                ->where('ptc.product_id', $pid)
                ->pluck('cd.name')
                ->filter()
                ->values()
                ->toArray();
            if (!empty($catNames)) {
                $data['category_names'] = $catNames;
            }

            // Add images
            $images = $this->getProductImages($pfx, $pid);
            if (!empty($images)) {
                $data['images'] = $images;
            }

            // Get variants — check combinations first, fall back to option values
            $combos = DB::table('product_option_combinations as poc')
                ->where('poc.product_id', $pid)
                ->orderBy('poc.sort_order')
                ->get();

            if ($combos->isNotEmpty()) {
                // Combination-based variants (multi-option products)
                $comboIds = $combos->pluck('id')->toArray();
                $comboValues = DB::table('product_option_combination_values as pocv')
                    ->join($pfx . 'product_option_value as pov', 'pocv.product_option_value_id', '=', 'pov.product_option_value_id')
                    ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                        $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                            ->where('ovd.language_id', '=', $langId);
                    })
                    ->whereIn('pocv.combination_id', $comboIds)
                    ->select('pocv.combination_id', 'ovd.name as value_name')
                    ->get()
                    ->groupBy('combination_id');

                $variants = [];
                foreach ($combos as $c) {
                    $valueNames = ($comboValues->get($c->id) ?? collect())->pluck('value_name')->implode(' / ');
                    $variants[] = [
                        'sku'         => $c->sku ?: ($sku . '-' . $c->id),
                        'name'        => $valueNames,
                        'option_name' => 'Option',
                        'price'       => (float) $c->absolute_price,
                        'cost'        => (float) $c->absolute_cost,
                        'quantity'    => (int) $c->quantity,
                        'weight'      => (float) ($prod->weight ?? 0),
                    ];
                }
                $data['variants'] = $variants;
            } else {
                // Single-option variants
                $optionValues = DB::table($pfx . 'product_option_value as pov')
                    ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                        $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                            ->where('ovd.language_id', '=', $langId);
                    })
                    ->join($pfx . 'option_description as od', function ($j) use ($langId) {
                        $j->on('pov.option_id', '=', 'od.option_id')
                            ->where('od.language_id', '=', $langId);
                    })
                    ->where('pov.product_id', $pid)
                    ->get([
                        'pov.product_option_value_id', 'pov.sku as variant_sku',
                        'pov.quantity as variant_qty', 'pov.price as variant_price',
                        'pov.price_prefix', 'pov.absolute_price',
                        'pov.cost as variant_cost', 'pov.cost_prefix', 'pov.absolute_cost',
                        'ovd.name as option_value_name', 'od.name as option_name',
                    ]);

                if ($optionValues->isNotEmpty()) {
                    $variants = [];
                    foreach ($optionValues as $ov) {
                        $variantSku = $ov->variant_sku ?: ($sku . '-' . $ov->product_option_value_id);

                        // Price: absolute_price takes priority, then modifier
                        if ((float) ($ov->absolute_price ?? 0) > 0) {
                            $variantPrice = (float) $ov->absolute_price;
                        } else {
                            $variantPrice = (float) $prod->price;
                            if ($ov->variant_price) {
                                $variantPrice = $ov->price_prefix === '-'
                                    ? $variantPrice - (float) $ov->variant_price
                                    : $variantPrice + (float) $ov->variant_price;
                            }
                        }

                        // Cost: absolute_cost takes priority, then modifier
                        if ((float) ($ov->absolute_cost ?? 0) > 0) {
                            $variantCost = (float) $ov->absolute_cost;
                        } else {
                            $variantCost = (float) ($prod->cost ?? 0);
                            if ($ov->variant_cost) {
                                $variantCost = $ov->cost_prefix === '-'
                                    ? $variantCost - (float) $ov->variant_cost
                                    : $variantCost + (float) $ov->variant_cost;
                            }
                        }

                        $variants[] = [
                            'sku'         => $variantSku,
                            'name'        => $ov->option_value_name,
                            'option_name' => $ov->option_name,
                            'price'       => $variantPrice,
                            'cost'        => $variantCost,
                            'quantity'    => (int) $ov->variant_qty,
                            'weight'      => (float) ($prod->weight ?? 0),
                        ];
                    }
                    $data['variants'] = $variants;
                }
            }

            // Check for existing link
            $existingLink = VentaProductLink::where('venta_setting_id', $store)
                ->where('product_id', $pid)
                ->first();

            if ($existingLink && $existingLink->venta_product_id) {
                // Update existing product on Venta
                $result = $client->updateProduct($sku, $data);
                if ($result['ok']) {
                    $updated++;
                    VentaProductGroupProduct::where('venta_product_group_id', $g->id)
                        ->where('product_id', $pid)
                        ->update([
                            'sync_status'  => 'synced',
                            'last_pushed_at' => now(),
                            'push_error'   => null,
                            'venta_sku'    => $sku,
                        ]);
                } else {
                    $failed++;
                    $errMsg = json_encode($result['body']);
                    $errors[] = "Product #{$pid}: {$errMsg}";
                    VentaProductGroupProduct::where('venta_product_group_id', $g->id)
                        ->where('product_id', $pid)
                        ->update(['sync_status' => 'failed', 'push_error' => $errMsg]);
                }
            } else {
                // Create new product on Venta
                $result = $client->createProduct($data);
                if ($result['ok']) {
                    $created++;
                    $ventaProductId = $result['body']['id'] ?? $result['body']['data']['id'] ?? 0;

                    VentaProductLink::updateOrCreate(
                        [
                            'venta_setting_id' => $store,
                            'product_id'       => $pid,
                        ],
                        [
                            'venta_product_id' => $ventaProductId,
                            'sku'              => $sku,
                        ]
                    );

                    VentaProductGroupProduct::where('venta_product_group_id', $g->id)
                        ->where('product_id', $pid)
                        ->update([
                            'sync_status'  => 'synced',
                            'last_pushed_at' => now(),
                            'push_error'   => null,
                            'venta_sku'    => $sku,
                        ]);
                } else {
                    $failed++;
                    $errMsg = json_encode($result['body']);
                    $errors[] = "Product #{$pid}: {$errMsg}";
                    VentaProductGroupProduct::where('venta_product_group_id', $g->id)
                        ->where('product_id', $pid)
                        ->update(['sync_status' => 'failed', 'push_error' => $errMsg]);
                }
            }
        }

        $msg = "Push complete — Created: {$created}, Updated: {$updated}, Failed: {$failed}";
        if (!empty($errors)) {
            Log::warning('Venta pushProducts errors', ['store' => $store, 'group' => $group, 'errors' => $errors]);
        }

        return $this->productsRedirect($store, $group)->with('status', $msg);
    }

    // ── Push Stock ──────────────────────────────────────────

    public function pushStock(Request $request, int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));
        if (empty($ids)) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'No products selected.');
        }

        $client = new VentaClient($setting);
        $pfx = (string) config('catalog.prefix');

        // Get linked products for the selected IDs
        $links = VentaProductLink::where('venta_setting_id', $store)
            ->whereIn('product_id', $ids)
            ->get()
            ->keyBy('product_id');

        if ($links->isEmpty()) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'No linked Venta products found for the selected items.');
        }

        // Fetch ERP quantities
        $erpProducts = DB::table($pfx . 'product')
            ->whereIn('product_id', $ids)
            ->get(['product_id', 'sku', 'quantity', 'status'])
            ->keyBy('product_id');

        // Fetch combinations first, then option values as fallback
        $erpCombos = DB::table('product_option_combinations')
            ->whereIn('product_id', $ids)
            ->get(['product_id', 'sku', 'quantity'])
            ->groupBy(fn($r) => (int) $r->product_id);

        $erpOptions = DB::table($pfx . 'product_option_value')
            ->whereIn('product_id', $ids)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->get(['product_id', 'sku', 'quantity'])
            ->groupBy(fn($r) => (int) $r->product_id);

        $okCount = 0;
        $errCount = 0;
        $skipCount = 0;

        foreach ($ids as $pid) {
            $link = $links->get($pid);
            $product = $erpProducts->get($pid);

            if (!$link || !$product) {
                $skipCount++;
                continue;
            }

            if ((int) $product->status === 0) {
                $skipCount++;
                continue;
            }

            $sku = $link->sku ?: $product->sku;

            // Check combinations first, then option values
            $variants = $erpCombos->get($pid) ?? $erpOptions->get($pid);
            if ($variants && $variants->count() > 0) {
                $allOk = true;
                foreach ($variants as $ov) {
                    if (!$ov->sku || trim($ov->sku) === '') continue;
                    $result = $client->pushVariantStock(trim($ov->sku), max(0, (int) $ov->quantity));
                    if (!$result['ok']) {
                        $allOk = false;
                    }
                }
                // Also push parent stock
                $client->pushStock($sku, max(0, (int) $product->quantity));
                $allOk ? $okCount++ : $errCount++;
            } else {
                $result = $client->pushStock($sku, max(0, (int) $product->quantity));
                $result['ok'] ? $okCount++ : $errCount++;
            }
        }

        $msg = "Stock push complete — {$okCount} updated";
        if ($errCount > 0) $msg .= ", {$errCount} failed";
        if ($skipCount > 0) $msg .= ", {$skipCount} skipped";

        return $this->productsRedirect($store, $group)->with('status', $msg);
    }

    // ── Push Prices ─────────────────────────────────────────

    public function pushPrices(Request $request, int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));
        if (empty($ids)) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'No products selected.');
        }

        $client = new VentaClient($setting);
        $pfx = (string) config('catalog.prefix');

        // Get linked products
        $links = VentaProductLink::where('venta_setting_id', $store)
            ->whereIn('product_id', $ids)
            ->get()
            ->keyBy('product_id');

        if ($links->isEmpty()) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'No linked Venta products found for the selected items.');
        }

        // Fetch ERP prices
        $erpProducts = DB::table($pfx . 'product')
            ->whereIn('product_id', $ids)
            ->get(['product_id', 'sku', 'price', 'status'])
            ->keyBy('product_id');

        // Fetch combinations first, then option values as fallback
        $erpCombos = DB::table('product_option_combinations')
            ->whereIn('product_id', $ids)
            ->get(['product_id', 'sku', 'absolute_price', 'absolute_cost'])
            ->groupBy(fn($r) => (int) $r->product_id);

        $erpOptions = DB::table($pfx . 'product_option_value')
            ->whereIn('product_id', $ids)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->get(['product_id', 'sku', 'price', 'price_prefix', 'absolute_price'])
            ->groupBy(fn($r) => (int) $r->product_id);

        $okCount = 0;
        $errCount = 0;
        $skipCount = 0;

        foreach ($ids as $pid) {
            $link = $links->get($pid);
            $product = $erpProducts->get($pid);

            if (!$link || !$product) {
                $skipCount++;
                continue;
            }

            if ((int) $product->status === 0) {
                $skipCount++;
                continue;
            }

            $sku = $link->sku ?: $product->sku;
            $basePrice = (float) $product->price;

            // Push parent price
            $result = $client->updateProduct($sku, ['price' => $basePrice]);
            if (!$result['ok']) {
                $errCount++;
                continue;
            }

            // Push variant prices — combinations first, then option values
            $combos = $erpCombos->get($pid);
            if ($combos && $combos->count() > 0) {
                foreach ($combos as $c) {
                    if (!$c->sku || trim($c->sku) === '') continue;
                    $client->updateVariant(trim($c->sku), ['price' => (float) $c->absolute_price]);
                }
            } else {
                $variants = $erpOptions->get($pid);
                if ($variants && $variants->count() > 0) {
                    foreach ($variants as $ov) {
                        if (!$ov->sku || trim($ov->sku) === '') continue;
                        if ((float) ($ov->absolute_price ?? 0) > 0) {
                            $variantPrice = (float) $ov->absolute_price;
                        } else {
                            $variantPrice = $basePrice;
                            if ($ov->price) {
                                $variantPrice = $ov->price_prefix === '-'
                                    ? $basePrice - (float) $ov->price
                                    : $basePrice + (float) $ov->price;
                            }
                        }
                        $client->updateVariant(trim($ov->sku), ['price' => $variantPrice]);
                    }
                }
            }

            $okCount++;
        }

        $msg = "Price push complete — {$okCount} updated";
        if ($errCount > 0) $msg .= ", {$errCount} failed";
        if ($skipCount > 0) $msg .= ", {$skipCount} skipped";

        return $this->productsRedirect($store, $group)->with('status', $msg);
    }

    // ── Unlink / Link / Mass Remove ─────────────────────────

    public function unlinkProduct(int $store, int $group, int $product)
    {
        $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        VentaProductGroupProduct::where('venta_product_group_id', $g->id)
            ->where('product_id', $product)
            ->update(['sync_status' => 'unlinked']);

        return $this->productsRedirect($store, $group)
            ->with('status', 'Product unlinked from Venta.');
    }

    public function deleteFromVenta(int $store, int $group, int $product)
    {
        $setting = $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $link = VentaProductLink::where('venta_setting_id', $store)
            ->where('product_id', $product)
            ->first();

        if (!$link || !$link->sku) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'Product not linked to Venta — nothing to delete.');
        }

        $client = new VentaClient($setting);
        $result = $client->delete("products/{$link->sku}");

        if ($result['ok']) {
            $link->delete();

            VentaProductGroupProduct::where('venta_product_group_id', $g->id)
                ->where('product_id', $product)
                ->update(['sync_status' => 'pending', 'push_error' => null, 'last_pushed_at' => null]);

            return $this->productsRedirect($store, $group)
                ->with('status', 'Product deleted from Venta store.');
        }

        $error = $result['body']['error'] ?? 'Unknown error';
        return $this->productsRedirect($store, $group)
            ->with('error', 'Failed to delete from Venta: ' . $error);
    }

    public function linkProduct(int $store, int $group, int $product)
    {
        $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $pivot = VentaProductGroupProduct::where('venta_product_group_id', $g->id)
            ->where('product_id', $product)
            ->first();

        if ($pivot) {
            // Check if a venta_product_link exists for this product
            $hasLink = VentaProductLink::where('venta_setting_id', $store)
                ->where('product_id', $product)
                ->whereNotNull('venta_product_id')
                ->where('venta_product_id', '!=', 0)
                ->exists();

            $pivot->update(['sync_status' => $hasLink ? 'synced' : 'pending']);
        }

        return $this->productsRedirect($store, $group)
            ->with('status', 'Product re-linked.');
    }

    public function massRemove(Request $request, int $store, int $group)
    {
        $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));

        if (!empty($ids)) {
            $g->products()->whereIn('product_id', $ids)->delete();
        }

        return $this->productsRedirect($store, $group)
            ->with('status', count($ids) . ' product(s) removed from group.');
    }

    // ── Add Products / Remove / Search ──────────────────────

    public function addProducts(Request $request, int $store, int $group)
    {
        $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return $this->productsRedirect($store, $group)
                ->with('status', 'No products selected.');
        }

        $ids = array_map('intval', (array) $ids);
        $existing = $g->products()->pluck('product_id')->toArray();
        $added = 0;

        foreach ($ids as $pid) {
            if (in_array($pid, $existing)) continue;
            VentaProductGroupProduct::create([
                'venta_product_group_id' => $g->id,
                'product_id'             => $pid,
            ]);
            $added++;
        }

        return $this->productsRedirect($store, $group)
            ->with('status', "{$added} product(s) added to product group.");
    }

    public function removeProduct(int $store, int $group, int $product)
    {
        $this->store_($store);
        $g = VentaProductGroup::where('venta_setting_id', $store)->findOrFail($group);

        VentaProductGroupProduct::where('venta_product_group_id', $g->id)
            ->where('product_id', $product)
            ->delete();

        return $this->productsRedirect($store, $group)
            ->with('status', 'Product removed from product group.');
    }

    public function searchProducts(Request $request, int $store)
    {
        $this->store_($store);

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
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
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

        $rows = $query->distinct()
            ->orderBy('p.product_id', 'desc')
            ->limit(100)
            ->get();

        return response()->json(['items' => $rows]);
    }

    // ── Helpers ──────────────────────────────────────────────

    private function getMatchingProductIds(VentaProductGroup $group): array
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

        $manualIds = [];
        if ($group->exists) {
            $manualIds = $group->products()->pluck('product_id')->toArray();
        }

        return array_values(array_unique(array_merge($filterIds, $manualIds)));
    }

    private function getProductImages(string $pfx, int $productId): array
    {
        $images = [];

        // Main image
        $mainImage = DB::table($pfx . 'product')
            ->where('product_id', $productId)
            ->value('image');

        if ($mainImage && trim($mainImage) !== '') {
            $images[] = $this->imageUrl($mainImage);
        }

        // Additional images
        $additionalImages = DB::table($pfx . 'product_image')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->pluck('image')
            ->toArray();

        foreach ($additionalImages as $img) {
            if ($img && trim($img) !== '') {
                $images[] = $this->imageUrl($img);
            }
        }

        return $images;
    }

    /**
     * Convert a relative image path to a fully encoded public URL.
     */
    private function imageUrl(string $path): string
    {
        $segments = explode('/', ltrim($path, '/'));
        $encoded = implode('/', array_map('rawurlencode', $segments));
        return asset('storage/' . $encoded);
    }
}
