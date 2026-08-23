<?php

namespace Extensions\shopee\Controllers;

use App\Http\Controllers\Controller;

use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeBrand;
use Extensions\shopee\Models\ShopeeCategory;
use Extensions\shopee\Models\ShopeeCategoryTemplate;
use Extensions\shopee\Models\ShopeeItemCache;
use Extensions\shopee\Models\ShopeeLogistic;
use Extensions\shopee\Models\ShopeeProductGroup;
use Extensions\shopee\Models\ShopeeProductGroupAttribute;
use Extensions\shopee\Models\ShopeeProductGroupProduct;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Illuminate\Support\Str;
use Extensions\shopee\Models\ShopeeSetting;
use App\Services\ActivityLogger;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Extensions\shopee\Services\ShopeeStockPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopeeProductGroupController extends Controller
{
    /** Memoised shop-wide SKU index; see shopeeSkuIndex(). */
    private ?array $shopeeSkuIndex = null;

    private function productsRedirect(int $id)
    {
        $return = request()->input('_return');
        if ($return && str_starts_with($return, '/')) {
            return redirect($return);
        }
        return redirect()->route('ext.shopee.product-groups.products', $id);
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

        return view('ext-shopee::product-groups.index', [
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

        // Shopee's product-create API only accepts leaf categories. Filter
        // non-leaves out of the picker so the operator can't pick one and
        // discover the rejection at push time. Falls back to the full list
        // only if no leaf flags are populated (e.g. legacy installs that
        // haven't refreshed the category cache).
        $leafQuery = ShopeeCategory::query()->where('leaf', 1)->orderBy('name')->limit(5000);
        $shopeeCategories = $leafQuery->get();
        if ($shopeeCategories->isEmpty()) {
            $shopeeCategories = ShopeeCategory::query()->orderBy('name')->limit(5000)->get();
        }

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

        // The brands already cached for whatever category this group is on.
        // The picker lazy-loads the rest over AJAX when the category
        // changes; this is only the set the <select> needs to be correct on
        // first paint, so an edit page shows the saved brand's own name
        // rather than an empty control until the fetch comes back.
        //
        // This query used to run inside the Blade. Round F Task 1 moved it
        // here unchanged: same model, same where, same order, same columns.
        // A view does not query.
        $initialBrands = $group->shopee_category_id
            ? ShopeeBrand::query()
                ->where('category_id', (int) $group->shopee_category_id)
                ->orderBy('name')
                ->get(['brand_id', 'name'])
            : collect();

        return view('ext-shopee::product-groups.form', [
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
            'initialBrands' => $initialBrands,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'shopee_category_id' => 'required|integer|min:1',
            'shopee_brand_id' => 'nullable|integer|min:0',
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
            'shopee_brand_id' => (int) ($data['shopee_brand_id'] ?? 0),
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

        return redirect()->route('ext.shopee.product-groups.edit', $group->id)
            ->with('status', 'Product group created. ' . count($productIds) . ' product(s) assigned.');
    }

    public function update(Request $request, int $id)
    {
        $group = ShopeeProductGroup::query()->findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'shopee_category_id' => 'required|integer|min:1',
            'shopee_brand_id' => 'nullable|integer|min:0',
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
            'shopee_brand_id' => (int) ($data['shopee_brand_id'] ?? 0),
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

        return redirect()->route('ext.shopee.product-groups.edit', $group->id)
            ->with('status', 'Product group saved.');
    }

    public function destroy(int $id)
    {
        $group = ShopeeProductGroup::query()->findOrFail($id);
        $name = $group->name;
        $group->delete();

        ActivityLogger::log('deleted', 'Shopee Product Group', (int) $id, $name);

        return redirect()->route('ext.shopee.product-groups.index')
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
                'gp.shopee_item_id', 'gp.sync_status', 'gp.last_pushed_at', 'gp.last_confirmed_at', 'gp.push_error'
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

        // These four must answer exactly what the badge shows, in the same
        // order the badge tests. 'pushed' used to be "has an item id and is
        // not unlinked", which INCLUDED failures - so filtering to Pushed
        // returned rows the badge calls Failed, in one screenful. A filter
        // that disagrees with the column it filters teaches the operator not
        // to trust either.
        if ($syncStatus === 'pushed') {
            $query->whereNotNull('gp.shopee_item_id')->where('gp.shopee_item_id', '!=', '')
                ->whereNotIn('gp.sync_status', ['unlinked', 'error']);
        } elseif ($syncStatus === 'pending') {
            $query->where(function ($w) {
                $w->whereNull('gp.shopee_item_id')->orWhere('gp.shopee_item_id', '');
            })->whereNotIn('gp.sync_status', ['error', 'unlinked']);
        } elseif ($syncStatus === 'error') {
            $query->where('gp.sync_status', 'error');
        } elseif ($syncStatus === 'unlinked') {
            // The badge has always had four states and the filter offered
            // three, so unlinked products could only be found by eye.
            $query->where('gp.sync_status', 'unlinked');
        }

        $products = $query->orderBy('p.product_id', 'desc')->paginate(50)->appends($request->except('page'));

        // Auto-fill pivot shopee_item_id from shopee_product_links.
        //
        // This is a WRITE, and it runs inside a GET whose route is gated
        // 'view_shopee|manage_shopee'. routes/web.php states the contract it
        // breaks in its own words: "Read routes (display-only, no DB write)".
        // So it is gated on the manage tier here: an operator holding only
        // view_shopee now reads the page without mutating a single row, which
        // is what their permission says they do.
        //
        // It still runs for a manager, because adopting a link created on
        // another page is genuinely useful, and it is recorded below rather
        // than happening in silence.
        $canWriteBackfill = auth()->user()?->hasPermission('manage_shopee/product_group') ?? false;

        $pivotProductIds = $products->pluck('product_id')->toArray();
        if ($canWriteBackfill && !empty($pivotProductIds)) {
            $existingLinks = DB::table('shopee_product_links')
                ->whereIn('product_id', $pivotProductIds)
                ->pluck('shopee_item_id', 'product_id');

            if ($existingLinks->isNotEmpty()) {
                $missingPivots = ShopeeProductGroupProduct::query()
                    ->where('shopee_product_group_id', $group->id)
                    ->whereIn('product_id', $existingLinks->keys()->toArray())
                    ->where(function ($w) {
                        $w->whereNull('shopee_item_id')->orWhere('shopee_item_id', '');
                    })
                    // 'error' is excluded, and that exclusion is the point.
                    //
                    // This back-fill runs on a GET. With only 'unlinked'
                    // excluded, loading the page took a row a failed push had
                    // just marked error-with-no-item-id, gave it an id from
                    // the links table and set it to synced - WITHOUT clearing
                    // push_error. The result rendered as a green Pushed badge
                    // sitting next to the words "invalid category". A refresh
                    // laundered a failure into a success, which is why a
                    // failed push kept looking like it had worked.
                    //
                    // Adopting a link the operator made elsewhere is fine.
                    // Overwriting a recorded failure is not, and a read has no
                    // business deciding a push succeeded.
                    ->whereNotIn('sync_status', ['unlinked', 'error'])
                    ->get();

                $adopted = 0;

                foreach ($missingPivots as $pivot) {
                    $linkId = $existingLinks->get($pivot->product_id);
                    if ($linkId) {
                        $adopted++;
                        $pivot->update([
                            'shopee_item_id' => (string) $linkId,
                            // 'pushed', not 'synced'. Two words for one state
                            // meant the vocabulary had five entries for four
                            // states, and this was the only writer of the
                            // fifth.
                            'sync_status' => 'pushed',
                        ]);
                    }
                }

                // Rows changed state without anyone asking, so it leaves a
                // trace. Only fires when something was actually adopted, so a
                // normal page load writes nothing here either.
                if ($adopted > 0) {
                    ActivityLogger::log(
                        'updated',
                        'Shopee Product Group',
                        $group->id,
                        "Matched {$adopted} product(s) to existing Shopee listings on page load",
                    );
                }
            }
        }

        // Pivot map for templates
        $pivotMap = ShopeeProductGroupProduct::query()
            ->where('shopee_product_group_id', $group->id)
            ->whereIn('product_id', $pivotProductIds)
            ->get()
            ->keyBy('product_id');

        // Option rows (variations) for display, combinations first, fallback
        // to option values. Single-option products store SKU/qty/price on
        // product_option_value; multi-option products store them on
        // product_option_combinations. Mirrors the Lazada listing fix.
        $optionRowsByProductId = collect();
        if (!empty($pivotProductIds)) {
            $combos = DB::table('product_option_combinations as poc')
                ->whereIn('poc.product_id', $pivotProductIds)
                ->orderBy('poc.product_id')
                ->orderBy('poc.sort_order')
                ->get();

            $comboProductIds = $combos->pluck('product_id')->unique()->toArray();

            if ($combos->isNotEmpty()) {
                $comboIds = $combos->pluck('id')->toArray();
                $comboValues = DB::table('product_option_combination_values as pocv')
                    ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                        $j->on('pocv.product_option_value_id', '=', 'ovd.option_value_id')
                            ->where('ovd.language_id', '=', $langId);
                    })
                    ->whereIn('pocv.combination_id', $comboIds)
                    ->select('pocv.combination_id', 'ovd.name as value_name')
                    ->get()
                    ->groupBy('combination_id');

                foreach ($combos as $c) {
                    $valueNames = ($comboValues->get($c->id) ?? collect())->pluck('value_name')->implode(' / ');
                    $row = (object) [
                        'product_id'              => $c->product_id,
                        'product_option_value_id' => $c->id,
                        'option_name'             => 'Option',
                        'option_value_name'       => $valueNames,
                        'option_quantity'         => (int) $c->quantity,
                        'option_sku'              => $c->sku,
                        'option_absolute_price'   => (float) $c->absolute_price,
                    ];
                    if (!$optionRowsByProductId->has($c->product_id)) {
                        $optionRowsByProductId[$c->product_id] = collect();
                    }
                    $optionRowsByProductId[$c->product_id]->push($row);
                }
            }

            $nonComboIds = array_diff($pivotProductIds, $comboProductIds);
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

                $optionRowsByProductId = $optionRowsByProductId->merge($optValues);
            }
        }

        // All product IDs in this group (for "Remove from Group" action)
        $manualIds = $group->groupProducts()->pluck('product_id')->toArray();

        // Counted across the WHOLE group, not the current page or filter. The
        // push summary was the only place failures were named, and it is a
        // flash message the next click destroys, so an operator who navigated
        // away had to remember which of twelve had failed. This is the same
        // fact, kept where it cannot be lost.
        $failedCount = ShopeeProductGroupProduct::query()
            ->where('shopee_product_group_id', $group->id)
            ->where('sync_status', 'error')
            ->count();

        return view('ext-shopee::product-groups.products', [
            'group' => $group,
            'failedCount' => $failedCount,
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
     * Build Shopee's tier_variation + per-combination model_list payload from
     * the ERP product's product_option_combinations rows. Single-option
     * products (whose data lives on product_option_value rather than
     * combinations) fall through and return empty arrays, the caller keeps
     * the existing flat add_item payload for those.
     *
     * Shape returned:
     *   [
     *     'tier_variation' => [
     *       ['name' => 'Color', 'option_list' => [['option' => 'black'], ['option' => 'Blue']]],
     *       ['name' => 'Length', 'option_list' => [['option' => '10'], ['option' => '20']]],
     *     ],
     *     'models' => [
     *       ['tier_index' => [0,0], 'model_sku' => 'pro-c-11', 'original_price' => 6020.0, 'stock' => 200],
     *       ...
     *     ],
     *   ]
     *
     * tier_index entries are 0-indexed positions into each option_list, in the
     * same order tier_variation enumerates the options.
     */
    private function buildTierVariation(int $productId, string $pfx, int $langId, float $markupPct, float $markupFixed, float $baseProductPrice): array
    {
        if ($productId <= 0) {
            return ['tier_variation' => [], 'models' => []];
        }

        $combos = DB::table('product_option_combinations')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->get(['id', 'sku', 'quantity', 'absolute_price']);
        if ($combos->isEmpty()) {
            return ['tier_variation' => [], 'models' => []];
        }

        $values = DB::table('product_option_combination_values as pocv')
            ->join($pfx . 'product_option_value as pov', 'pocv.product_option_value_id', '=', 'pov.product_option_value_id')
            ->join($pfx . 'option_description as od', function ($j) use ($langId) {
                $j->on('pov.option_id', '=', 'od.option_id')->where('od.language_id', '=', $langId);
            })
            ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('pov.option_value_id', '=', 'ovd.option_value_id')->where('ovd.language_id', '=', $langId);
            })
            ->whereIn('pocv.combination_id', $combos->pluck('id')->all())
            ->select(
                'pocv.combination_id',
                'pov.option_id',
                'od.name as option_name',
                'ovd.name as value_name'
            )
            ->orderBy('pocv.combination_id')
            ->orderBy('pov.option_id')
            ->get();
        if ($values->isEmpty()) {
            return ['tier_variation' => [], 'models' => []];
        }

        // Discover option groups in stable order (by option_id), each with its
        // value list (de-duped, order preserved by first occurrence).
        $orderedOptionIds = [];
        $optionNameById = [];
        $valuesByOption = [];
        foreach ($values as $v) {
            $oid = (int) $v->option_id;
            if (!in_array($oid, $orderedOptionIds, true)) {
                $orderedOptionIds[] = $oid;
                $optionNameById[$oid] = (string) $v->option_name;
                $valuesByOption[$oid] = [];
            }
            $name = (string) $v->value_name;
            if (!in_array($name, $valuesByOption[$oid], true)) {
                $valuesByOption[$oid][] = $name;
            }
        }

        $tierVariation = [];
        foreach ($orderedOptionIds as $oid) {
            $tierVariation[] = [
                'name' => $optionNameById[$oid],
                'option_list' => array_map(fn ($v) => ['option' => $v], $valuesByOption[$oid]),
            ];
        }

        // Index combination → [option_id => value_name].
        $valuesByCombo = [];
        foreach ($values as $v) {
            $valuesByCombo[(int) $v->combination_id][(int) $v->option_id] = (string) $v->value_name;
        }

        $models = [];
        foreach ($combos as $c) {
            $cid = (int) $c->id;
            $tierIndex = [];
            foreach ($orderedOptionIds as $oid) {
                $valueName = $valuesByCombo[$cid][$oid] ?? null;
                if ($valueName === null) {
                    // Combination missing a value for this option; skip, Shopee
                    // would reject the tier_index anyway.
                    continue 2;
                }
                $idx = array_search($valueName, $valuesByOption[$oid], true);
                if ($idx === false) {
                    continue 2;
                }
                $tierIndex[] = $idx;
            }

            $rawPrice = (float) ($c->absolute_price ?? $baseProductPrice);
            $finalPrice = round($rawPrice + ($rawPrice * $markupPct / 100) + $markupFixed, 2);
            $models[] = [
                'tier_index'     => $tierIndex,
                'model_sku'      => (string) ($c->sku ?? ''),
                'original_price' => max(0, $finalPrice),
                'stock'          => max(0, (int) ($c->quantity ?? 0)),
            ];
        }

        if (empty($models)) {
            return ['tier_variation' => [], 'models' => []];
        }

        return [
            'tier_variation' => $tierVariation,
            'models'         => $models,
        ];
    }

    /**
     * Build the `attribute_list` array Shopee's add_item / update_item expects.
     *
     * Reads the operator's per-attribute values from shopee_product_group_attributes
     * (saved when the form is submitted), then resolves each one against the
     * cached attribute tree in shopee_category_templates to figure out:
     *   - the numeric attribute_id Shopee wants (template has it; our pivot uses
     *     a human key);
     *   - whether the attribute is enum (value_id picked from the template's
     *     option list, original_value_name omitted) or free-text (value_id=0,
     *     original_value_name carries the value).
     *
     * Skips attributes whose value is blank, Shopee's validation flags the
     * mandatory ones it actually needs and stays quiet about the optional ones.
     */
    private function buildAttributeList(int $groupId, int $categoryId): array
    {
        if ($groupId <= 0 || $categoryId <= 0) {
            return [];
        }

        $savedByKey = ShopeeProductGroupAttribute::query()
            ->where('shopee_product_group_id', $groupId)
            ->pluck('value', 'attribute_key')
            ->toArray();
        if (empty($savedByKey)) {
            return [];
        }

        $template = ShopeeCategoryTemplate::query()
            ->where('category_id', $categoryId)
            ->first();
        if (!$template) {
            return [];
        }
        $raw = is_array($template->attributes) ? $template->attributes : (json_decode((string) $template->attributes, true) ?: []);
        // Template may have been cached as the full response wrapper or just the list.
        $list = $raw['attribute_list'] ?? $raw['attribute_tree'] ?? $raw['attributes'] ?? $raw;
        if (!is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $attr) {
            if (!is_array($attr)) continue;
            $attrId = (int) ($attr['attribute_id'] ?? 0);
            $attrKey = (string) ($attr['display_attribute_name'] ?? $attr['original_attribute_name'] ?? $attr['attribute_name'] ?? '');
            if ($attrId <= 0 || $attrKey === '') continue;

            $value = trim((string) ($savedByKey[$attrKey] ?? ''));
            if ($value === '') continue;

            // Determine input shape: enum (one of attribute_value_list) vs free text.
            $valueList = $attr['attribute_value_list'] ?? $attr['values'] ?? $attr['options'] ?? [];
            $matchedValueId = null;
            if (is_array($valueList)) {
                foreach ($valueList as $opt) {
                    if (!is_array($opt)) continue;
                    $optName = (string) ($opt['display_value_name'] ?? $opt['original_value_name'] ?? $opt['value_name'] ?? '');
                    if ($optName !== '' && strcasecmp($optName, $value) === 0) {
                        $matchedValueId = (int) ($opt['value_id'] ?? 0);
                        break;
                    }
                }
            }

            if ($matchedValueId !== null && $matchedValueId > 0) {
                $out[] = [
                    'attribute_id' => $attrId,
                    'attribute_value_list' => [
                        ['value_id' => $matchedValueId],
                    ],
                ];
            } else {
                // Free-text fallback. Some Shopee attributes (e.g. model names)
                // accept value_id=0 + original_value_name, treated as a custom value.
                $out[] = [
                    'attribute_id' => $attrId,
                    'attribute_value_list' => [
                        ['value_id' => 0, 'original_value_name' => $value],
                    ],
                ];
            }
        }

        return $out;
    }

    /**
     * Build the `brand` block for a Shopee add_item / update_item payload.
     * Looks up the human-readable brand name from the cached shopee_brands
     * table so Shopee accepts the brand_id + name pair. brand_id=0 → "No Brand"
     * default that every category accepts.
     */
    private function buildBrandPayload(int $categoryId, int $brandId): array
    {
        if ($brandId <= 0) {
            return ['brand_id' => 0, 'original_brand_name' => 'No Brand'];
        }
        $row = ShopeeBrand::query()
            ->where('category_id', $categoryId)
            ->where('brand_id', $brandId)
            ->first();
        $name = $row ? (string) $row->name : 'No Brand';
        return ['brand_id' => $brandId, 'original_brand_name' => $name];
    }

    /**
     * AJAX category search for the product-group form combobox. Matches by
     * substring on name OR category_id. Only leaves (Shopee's API requirement
     * for product creation) are returned. Limit kept tight so the dropdown
     * stays scannable.
     */
    public function searchCategories(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $limit = max(1, min(50, (int) $request->input('limit', 25)));

        $query = ShopeeCategory::query()->where('leaf', 1);

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', '%' . $q . '%');
                if (ctype_digit($q)) {
                    $w->orWhere('category_id', (int) $q);
                }
            });
        }

        $rows = $query->orderBy('name')->limit($limit)->get(['category_id', 'name']);

        return response()->json([
            'ok' => true,
            'categories' => $rows->map(fn ($c) => [
                'category_id' => (int) $c->category_id,
                'name'        => (string) $c->name,
            ])->values()->all(),
        ]);
    }

    /**
     * Return the cached name for a single category_id. Used by the combobox
     * to display the pre-selected category's name on edit-page load without
     * fetching the entire 2k-row list.
     */
    public function categoryLookup(Request $request)
    {
        $cid = (int) $request->input('category_id', 0);
        if ($cid <= 0) {
            return response()->json(['ok' => false], 422);
        }
        $row = ShopeeCategory::query()
            ->where('category_id', $cid)
            ->first(['category_id', 'name', 'leaf']);
        if (!$row) {
            return response()->json(['ok' => false], 404);
        }
        return response()->json([
            'ok' => true,
            'category' => [
                'category_id' => (int) $row->category_id,
                'name'        => (string) $row->name,
                'leaf'        => (bool) $row->leaf,
            ],
        ]);
    }

    /**
     * AJAX: return the brand list for a Shopee category. Lazy-fetches from
     * the Shopee API the first time a category is queried, then serves from
     * the shopee_brands cache table forever. Used by the product-group form
     * to populate the Brand dropdown once the operator picks a category.
     *
     * Shopee's /api/v2/product/get_brand_list is paginated (100 per page);
     * we walk all pages so a single fetch fully populates the category.
     */
    public function brandsForCategory(Request $request, ShopeeClient $client)
    {
        $categoryId = (int) $request->query('category_id', 0);
        if ($categoryId <= 0) {
            return response()->json(['ok' => false, 'message' => 'category_id is required.'], 422);
        }

        // Serve from cache if we've fetched this category before.
        $cached = ShopeeBrand::query()
            ->where('category_id', $categoryId)
            ->orderBy('name')
            ->get(['brand_id', 'name']);

        if ($cached->isNotEmpty()) {
            return response()->json([
                'ok' => true,
                'cached' => true,
                'brands' => $cached->map(fn ($b) => ['brand_id' => (int) $b->brand_id, 'name' => (string) $b->name])->all(),
            ]);
        }

        $setting = ShopeeSetting::query()->first()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return response()->json([
                'ok' => false,
                'message' => "Missing Shopee {$modeLabel} settings.",
            ], 412);
        }

        $path = '/api/v2/product/get_brand_list';
        $offset = 0;
        $pageSize = 100;
        $collected = [];
        $maxPages = 50; // safety: 5000 brands per category is already an absurd cap

        for ($page = 0; $page < $maxPages; $page++) {
            $result = $client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'],
                (string) $auth['partner_key'],
                (string) $auth['access_token'],
                (int) $auth['shop_id'],
                $path,
                [
                    'category_id' => $categoryId,
                    'offset'      => $offset,
                    'page_size'   => $pageSize,
                    'status'      => 1, // active brands only
                ]
            );

            if (!($result['ok'] ?? false)) {
                $body = $result['body'] ?? null;
                $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'API call failed')) : 'API call failed';
                return response()->json([
                    'ok' => false,
                    'message' => 'Failed to fetch brands: ' . $msg,
                ], 502);
            }

            $body = $result['body'] ?? [];
            $resp = $body['response'] ?? $body;
            $brandList = $resp['brand_list'] ?? [];
            if (!is_array($brandList) || empty($brandList)) {
                break;
            }

            foreach ($brandList as $b) {
                if (!is_array($b)) continue;
                $bid = (int) ($b['brand_id'] ?? 0);
                $name = trim((string) ($b['original_brand_name'] ?? $b['display_brand_name'] ?? $b['name'] ?? ''));
                if ($bid <= 0 || $name === '') continue;

                $collected[] = ['brand_id' => $bid, 'name' => $name, 'raw' => $b];
            }

            $hasNext = (bool) ($resp['has_next_page'] ?? false);
            if (!$hasNext) {
                break;
            }
            $offset += $pageSize;
        }

        // Persist + de-dup. Use upsert keyed on (category_id, brand_id).
        $now = now();
        foreach ($collected as $row) {
            ShopeeBrand::updateOrCreate(
                ['category_id' => $categoryId, 'brand_id' => $row['brand_id']],
                ['name' => $row['name'], 'status' => 1, 'raw' => $row['raw'], 'updated_at' => $now]
            );
        }

        $brands = ShopeeBrand::query()
            ->where('category_id', $categoryId)
            ->orderBy('name')
            ->get(['brand_id', 'name'])
            ->map(fn ($b) => ['brand_id' => (int) $b->brand_id, 'name' => (string) $b->name])
            ->all();

        return response()->json([
            'ok' => true,
            'cached' => false,
            'brands' => $brands,
            'fetched' => count($collected),
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

        // Duplicates were swallowed: adding 5 products of which 3 were already
        // in the group reported "2 product(s) added" and left the operator to
        // work out what happened to the other three.
        $skipped = count($ids) - $added;

        $summary = "{$added} product(s) added to group.";
        if ($skipped > 0) {
            $summary .= " {$skipped} already in the group.";
        }

        return $this->productsRedirect($group->id)
            ->with($added > 0 ? 'status' : 'warning', $summary);
    }

    /**
     * Unlink a product (keep in group but mark as unlinked).
     */
    public function unlinkProduct(int $groupId, int $productId)
    {
        $group = ShopeeProductGroup::query()->findOrFail($groupId);

        // Delete the LINK, not just the pivot's label. This used to set
        // sync_status to 'unlinked' and stop, so it reported success over a
        // shopee_product_links row that was still there - and the push loop
        // tests that table, so the product stayed skipped as "already linked"
        // however many times it was unlinked.
        $removed = ShopeeProductLink::unlinkProduct($productId);

        // Says what happened rather than announcing success either way. A
        // product that was never linked is not an error worth stopping for,
        // but the operator should not be told links were removed when none
        // were.
        return $this->productsRedirect($group->id)->with(
            'status',
            $removed > 0
                ? "Product unlinked from Shopee ({$removed} link(s) removed, cache cleared). You can push it again."
                : 'Product was not linked to Shopee. Nothing to remove.'
        );
    }

    /**
     * Re-link a previously unlinked product.
     */
    /**
     * Sync Shopee item ID for a single product by matching SKU via cache or API.
     */
    public function syncId(int $groupId, int $productId, \Extensions\shopee\Services\Shopee\ShopeeClient $client)
    {
        $group = ShopeeProductGroup::query()->findOrFail($groupId);
        $pivot = $group->groupProducts()->where('product_id', $productId)->first();
        if (!$pivot) {
            return redirect()->back()->with('error', 'Product not in this group.');
        }

        $setting = ShopeeSetting::query()->first()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} credentials.");
        }

        $pfx = (string) config('catalog.prefix');
        $mode = $auth['mode'];
        $pid = (int) $auth['partner_id'];
        $pkey = (string) $auth['partner_key'];
        $token = (string) $auth['access_token'];
        $shopId = (int) $auth['shop_id'];

        // Collect all SKUs for this product
        $product = DB::table($pfx . 'product')->where('product_id', $productId)->first(['sku', 'model']);
        $skus = [];
        if ($product->sku && trim($product->sku) !== '') $skus[] = strtolower(trim($product->sku));
        if ($product->model && trim($product->model) !== '') $skus[] = strtolower(trim($product->model));

        $optSkus = DB::table($pfx . 'product_option_value')
            ->where('product_id', $productId)
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->pluck('sku')->map(fn($s) => strtolower(trim($s)))->unique()->toArray();
        $skus = array_unique(array_merge($skus, $optSkus));

        if (empty($skus)) {
            return redirect()->back()->with('error', 'Product has no SKU to match against Shopee.');
        }

        $erpHasOptions = !empty($optSkus);

        // If already linked (has item_id and not unlinked), refresh models from API
        $existingItemId = $pivot->shopee_item_id ?? null;
        if ($existingItemId && ($pivot->sync_status ?? '') !== 'unlinked') {
            return $this->refreshShopeeProductLinks($pivot, $productId, (int) $existingItemId, $skus, $erpHasOptions, $client, $mode, $pid, $pkey, $token, $shopId);
        }

        // Step 1: Try cache for fast item_id lookup
        $matchedItemId = null;
        $cacheHits = ShopeeItemCache::query()
            ->whereIn(DB::raw('LOWER(sku)'), $skus)
            ->get();

        if ($cacheHits->isNotEmpty()) {
            $matchedItemId = (int) $cacheHits->first()->shopee_item_id;
        }

        // Step 2: If no cache hit, search Shopee API with batched detail fetches
        if (!$matchedItemId) {
            $offset = 0;
            $pageSize = 50;

            for ($page = 0; $page < 20; $page++) {
                $res = $client->shopGet($mode, $pid, $pkey, $token, $shopId,
                    '/api/v2/product/get_item_list', [
                        'offset' => $offset,
                        'page_size' => $pageSize,
                        'item_status' => 'NORMAL',
                    ]);

                $items = $res['body']['response']['item'] ?? [];
                if (empty($items)) break;

                // Batch fetch details, get_item_base_info accepts up to 50 item_ids
                $itemIds = array_filter(array_map(fn($i) => $i['item_id'] ?? null, $items));
                $detail = $client->shopGet($mode, $pid, $pkey, $token, $shopId,
                    '/api/v2/product/get_item_base_info', [
                        'item_id_list' => implode(',', $itemIds),
                    ]);

                $itemList = $detail['body']['response']['item_list'] ?? [];
                foreach ($itemList as $il) {
                    $itemSku = strtolower(trim($il['item_sku'] ?? ''));

                    if ($itemSku !== '' && in_array($itemSku, $skus)) {
                        $matchedItemId = (int) $il['item_id'];
                        break 2;
                    }

                    foreach ($il['model_list'] ?? [] as $model) {
                        $modelSku = strtolower(trim($model['model_sku'] ?? ''));
                        if ($modelSku !== '' && in_array($modelSku, $skus)) {
                            $matchedItemId = (int) $il['item_id'];
                            break 3;
                        }
                    }
                }

                if (!($res['body']['response']['has_next_page'] ?? false)) break;
                $offset += $pageSize;
            }
        }

        if (!$matchedItemId) {
            return redirect()->back()->with('error', 'No matching product found on Shopee for SKU(s): ' . implode(', ', $skus));
        }

        // Link and refresh models from API
        $pivot->update([
            'shopee_item_id' => (string) $matchedItemId,
            // 'pushed' everywhere. There were two words for one state, and
            // the only thing keeping them interchangeable was that nothing
            // read either of them. The first query written as
            // where('sync_status', 'pushed') would have silently missed every
            // row the Match-ID path had touched.
            'sync_status' => 'pushed',
        ]);

        return $this->refreshShopeeProductLinks($pivot, $productId, (int) $matchedItemId, $skus, $erpHasOptions, $client, $mode, $pid, $pkey, $token, $shopId);
    }

    /**
     * Refresh Shopee product links and cache from the API model list.
     * Called by syncId for both new links and already-linked products.
     */
    private function refreshShopeeProductLinks(
        object $pivot, int $productId, int $itemId, array $skus, bool $erpHasOptions,
        \Extensions\shopee\Services\Shopee\ShopeeClient $client, string $mode, int $pid, string $pkey, string $token, int $shopId
    ) {
        if ($erpHasOptions) {
            $modelRes = $client->shopGet($mode, $pid, $pkey, $token, $shopId,
                '/api/v2/product/get_model_list', ['item_id' => $itemId]);

            $models = ($modelRes['body']['response'] ?? $modelRes['body'] ?? [])['model'] ?? [];
            $linked = 0;
            $activeModelIds = [];

            // Remove stale parent-only link
            ShopeeProductLink::query()
                ->where('product_id', $productId)
                ->where('shopee_item_id', $itemId)
                ->whereNull('shopee_model_id')
                ->delete();

            foreach ($models as $model) {
                $modelSku = strtolower(trim($model['model_sku'] ?? ''));
                $modelId = (int) ($model['model_id'] ?? 0);
                if ($modelSku === '' || $modelId === 0) continue;

                $activeModelIds[] = $modelId;

                // Refresh cache
                ShopeeItemCache::query()->updateOrCreate(
                    ['shopee_item_id' => $itemId, 'shopee_model_id' => $modelId],
                    ['sku' => $model['model_sku']]
                );

                if (in_array($modelSku, $skus)) {
                    ShopeeProductLink::updateOrCreate(
                        ['product_id' => $productId, 'shopee_item_id' => $itemId, 'shopee_model_id' => $modelId],
                        ['sku' => $model['model_sku'], 'last_synced_at' => now(), 'last_sync_ok' => true, 'last_sync_error_code' => null, 'last_sync_error_message' => null]
                    );
                    $linked++;
                }
            }

            // Remove stale model links no longer on Shopee
            if (!empty($activeModelIds)) {
                ShopeeProductLink::query()
                    ->where('product_id', $productId)
                    ->where('shopee_item_id', $itemId)
                    ->whereNotNull('shopee_model_id')
                    ->whereNotIn('shopee_model_id', $activeModelIds)
                    ->delete();

                ShopeeItemCache::query()
                    ->where('shopee_item_id', $itemId)
                    ->whereNotNull('shopee_model_id')
                    ->whereNotIn('shopee_model_id', $activeModelIds)
                    ->delete();
            }

            $pivot->update(['shopee_item_id' => (string) $itemId, 'sync_status' => 'pushed']);

            return redirect()->back()->with('status', "Shopee item ID {$itemId} synced, {$linked} variation(s) linked.");
        }

        // Simple product, parent link only
        ShopeeProductLink::updateOrCreate(
            ['product_id' => $productId, 'shopee_item_id' => $itemId, 'shopee_model_id' => null],
            ['sku' => $skus[0] ?? '', 'last_synced_at' => now(), 'last_sync_ok' => true, 'last_sync_error_code' => null, 'last_sync_error_message' => null]
        );

        $pivot->update(['shopee_item_id' => (string) $itemId, 'sync_status' => 'pushed']);

        return redirect()->back()->with('status', "Shopee item ID {$itemId} synced.");
    }

    public function linkProduct(int $groupId, int $productId)
    {
        $group = ShopeeProductGroup::query()->findOrFail($groupId);
        $pivot = $group->groupProducts()->where('product_id', $productId)->first();

        // Said "Product re-linked." even for a product that is not in this
        // group at all, because the message sat outside the check.
        if (! $pivot) {
            return $this->productsRedirect($group->id)
                ->with('error', 'That product is not in this group.');
        }

        // Already holds an item id: re-linking is just clearing the unlinked
        // state.
        if (! empty($pivot->shopee_item_id)) {
            $pivot->update(['sync_status' => 'pushed']);

            return $this->productsRedirect($group->id)
                ->with('status', 'Product re-linked to Shopee item ' . $pivot->shopee_item_id . '.');
        }

        $linkId = DB::table('shopee_product_links')
            ->where('product_id', $productId)
            ->value('shopee_item_id');

        if ($linkId) {
            $pivot->update(['shopee_item_id' => (string) $linkId, 'sync_status' => 'pushed']);

            return $this->productsRedirect($group->id)
                ->with('status', 'Product re-linked to Shopee item ' . $linkId . '.');
        }

        // The branch that used to lie. There is no link to restore, so the
        // product is set back to not-pushed - which is the opposite of
        // re-linked, and was being reported as success.
        $pivot->update(['sync_status' => 'pending', 'push_error' => null]);

        return $this->productsRedirect($group->id)->with(
            'warning',
            'No Shopee link was found for this product, so it is set back to not pushed. Use Match Shopee ID to find an existing listing, or push it to create one.'
        );
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

        if (empty($ids)) {
            return $this->productsRedirect($group->id)->with('error', 'No products selected.');
        }

        // delete() returns rows actually removed. This used to report
        // count($ids), so posting ten ids of which four were not in the group
        // still claimed ten removals.
        $removed = $group->groupProducts()->whereIn('product_id', $ids)->delete();

        $summary = "{$removed} product(s) removed from group.";
        if ($removed < count($ids)) {
            $summary .= ' ' . (count($ids) - $removed) . ' were not in the group.';
        }

        return $this->productsRedirect($group->id)
            ->with($removed > 0 ? 'status' : 'warning', $summary);
    }

    /**
     * The SKUs this product could be known by on Shopee, lowercased.
     *
     * Includes variation SKUs, because Shopee stores a SKU on the item AND on
     * every model under it, and a product is just as findable by one of its
     * variations as by its own code.
     */
    private function shopeeCandidateSkus(int $productId, string $pfx): array
    {
        $product = DB::table($pfx.'product')->where('product_id', $productId)->first(['sku', 'model']);

        if (! $product) {
            return [];
        }

        $skus = [];
        foreach ([$product->sku ?? '', $product->model ?? ''] as $candidate) {
            $candidate = strtolower(trim((string) $candidate));
            if ($candidate !== '') {
                $skus[] = $candidate;
            }
        }

        $optSkus = DB::table($pfx.'product_option_value')
            ->where('product_id', $productId)
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->pluck('sku')->map(fn ($v) => strtolower(trim((string) $v)))->all();

        return array_values(array_unique(array_filter(array_merge($skus, $optSkus))));
    }

    /**
     * Does Shopee still have this item?
     *
     * @return string 'yes' | 'no' | 'unknown'
     */
    private function shopeeItemStillExists(ShopeeClient $client, array $auth, int $itemId): string
    {
        try {
            $res = $client->shopGet(
                $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_item_base_info',
                ['item_id_list' => (string) $itemId]
            );
        } catch (\Throwable $e) {
            // The client retries twice and then throws. A throw is the network
            // failing, never Shopee answering, so it can prove nothing.
            return 'unknown';
        }

        $body = is_array($res['body'] ?? null) ? $res['body'] : [];
        $err = strtolower((string) ($body['error'] ?? ''));

        if ($err !== '') {
            // Shopee replies 200 with an error field, so the HTTP status says
            // nothing. Only an error that NAMES the item as missing counts as
            // an answer; auth failures, rate limits and the rest are silence.
            return str_contains($err, 'not_found') || str_contains($err, 'not_exist') ? 'no' : 'unknown';
        }

        if (! ($res['ok'] ?? false)) {
            return 'unknown';
        }

        $items = $body['response']['item_list'] ?? [];

        foreach ($items as $item) {
            if ((int) ($item['item_id'] ?? 0) !== $itemId) {
                continue;
            }

            // DELETED is the only status that means gone. UNLIST and BANNED
            // items are still there - treating them as missing would delete a
            // good link and then create a duplicate beside the original.
            return strtoupper((string) ($item['item_status'] ?? '')) === 'DELETED' ? 'no' : 'yes';
        }

        return 'no';
    }

    /**
     * Every SKU in the shop, mapped to the item that carries it.
     *
     * Built ONCE per request and reused. Shopee has no "get item by SKU"
     * endpoint, so finding a product by its code means paging the whole shop
     * and fetching details in batches of fifty - about forty calls. Per
     * product that would be ruinous on a group of any size; shared across the
     * batch it is a fixed cost paid only when something actually needs
     * looking up.
     *
     * @return array{skus: array<string,int>, complete: bool}
     */
    private function shopeeSkuIndex(ShopeeClient $client, array $auth): array
    {
        if ($this->shopeeSkuIndex !== null) {
            return $this->shopeeSkuIndex;
        }

        $map = [];
        $complete = true;

        // UNLIST and BANNED included deliberately. An item hidden from the
        // storefront still occupies its SKU, and a search that ignored them
        // would report "not on Shopee" and create a second listing.
        foreach (['NORMAL', 'UNLIST', 'BANNED'] as $status) {
            $offset = 0;

            for ($page = 0; $page < 20; $page++) {
                try {
                    $res = $client->shopGet(
                        $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                        (string) $auth['access_token'], (int) $auth['shop_id'],
                        '/api/v2/product/get_item_list',
                        ['offset' => $offset, 'page_size' => 50, 'item_status' => $status]
                    );
                } catch (\Throwable $e) {
                    $complete = false;
                    break;
                }

                $items = $res['body']['response']['item'] ?? [];
                if (empty($items)) {
                    break;
                }

                $itemIds = array_filter(array_map(fn ($i) => $i['item_id'] ?? null, $items));
                if (empty($itemIds)) {
                    break;
                }

                try {
                    $detail = $client->shopGet(
                        $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                        (string) $auth['access_token'], (int) $auth['shop_id'],
                        '/api/v2/product/get_item_base_info',
                        ['item_id_list' => implode(',', $itemIds)]
                    );
                } catch (\Throwable $e) {
                    $complete = false;
                    break;
                }

                foreach ($detail['body']['response']['item_list'] ?? [] as $item) {
                    $itemId = (int) ($item['item_id'] ?? 0);
                    if ($itemId <= 0) {
                        continue;
                    }

                    $itemSku = strtolower(trim((string) ($item['item_sku'] ?? '')));
                    if ($itemSku !== '' && ! isset($map[$itemSku])) {
                        $map[$itemSku] = $itemId;
                    }

                    foreach ($item['model_list'] ?? [] as $model) {
                        $modelSku = strtolower(trim((string) ($model['model_sku'] ?? '')));
                        if ($modelSku !== '' && ! isset($map[$modelSku])) {
                            $map[$modelSku] = $itemId;
                        }
                    }
                }

                if (! ($res['body']['response']['has_next_page'] ?? false)) {
                    break;
                }

                $offset += 50;

                if ($page === 19) {
                    // Reported, never swallowed: a shop bigger than the ceiling
                    // would otherwise have its tail read as "not on Shopee".
                    $complete = false;
                }
            }
        }

        return $this->shopeeSkuIndex = ['skus' => $map, 'complete' => $complete];
    }

    /**
     * Ask Shopee what is actually true, and make the local link agree.
     *
     * The same reconcile Venta got, for the same reason: every action on a row
     * decided what to do from a link row, which is the ERP's own memory and
     * can be wrong in both directions. Delete an item in Seller Centre and the
     * ERP went on believing it was live, offering Update - which can only
     * answer item_not_found - while Push was skipped because a link existed.
     *
     * The order here is chosen around what each question costs. Confirming an
     * item we already know the id of is one call. Searching by SKU means
     * paging the shop, so it only happens when the cheap question came back
     * negative or there was no id to ask about.
     *
     * Returns confirmed, adopted, repointed, lost, new, taken or unreachable.
     *
     * @return array{state: string, item_id: int|null, message: string|null}
     */
    private function reconcileShopeeLink(ShopeeClient $client, array $auth, int $productId, string $pfx): array
    {
        $link = ShopeeProductLink::query()
            ->where('product_id', $productId)
            ->whereNotNull('shopee_item_id')
            ->first();

        $knownId = $link ? (int) $link->shopee_item_id : 0;

        if ($knownId > 0) {
            $verdict = $this->shopeeItemStillExists($client, $auth, $knownId);

            if ($verdict === 'unknown') {
                return [
                    'state' => 'unreachable',
                    'item_id' => $knownId,
                    'message' => 'Shopee did not answer, so nothing was checked or changed.',
                ];
            }

            if ($verdict === 'yes') {
                return ['state' => 'confirmed', 'item_id' => $knownId, 'message' => null];
            }
        }

        $skus = $this->shopeeCandidateSkus($productId, $pfx);

        if (empty($skus)) {
            // Nothing to search by. Say so rather than concluding absence: a
            // product with no SKU is unfindable, not missing.
            return [
                'state' => 'unreachable',
                'item_id' => null,
                'message' => 'This product has no SKU, so it cannot be matched against Shopee.',
            ];
        }

        $index = $this->shopeeSkuIndex($client, $auth);

        $found = null;
        foreach ($skus as $sku) {
            if (isset($index['skus'][$sku])) {
                $found = (int) $index['skus'][$sku];
                break;
            }
        }

        if ($found === null) {
            if (! $index['complete']) {
                return [
                    'state' => 'unreachable',
                    'item_id' => $knownId ?: null,
                    'message' => 'The shop could not be read all the way through, so this product was left alone.',
                ];
            }

            if ($knownId > 0) {
                // Gone from Shopee. Dropping the link is what lets Send create
                // instead of sending an update that can only answer
                // item_not_found. This also clears the item cache, which is
                // the other half of the same memory: a cached SKU to item_id
                // row would otherwise hand the dead id straight back.
                ShopeeProductLink::unlinkProduct($productId);

                // unlinkProduct leaves the row saying 'unlinked', which is the
                // word for a deliberate act by the operator. Nobody unlinked
                // this - Shopee lost it - and the row needs to read as not
                // pushed so that Send offers to create it again.
                $this->writeProductStatus($productId, [
                    'sync_status' => 'pending',
                    'push_error' => null,
                ]);

                return ['state' => 'lost', 'item_id' => null, 'message' => null];
            }

            return ['state' => 'new', 'item_id' => null, 'message' => null];
        }

        $otherHolder = ShopeeProductLink::query()
            ->where('shopee_item_id', $found)
            ->where('product_id', '!=', $productId)
            ->value('product_id');

        if ($otherHolder) {
            // Not taken over. That link may be perfectly good, and stealing it
            // would leave the other product pointing at nothing.
            return [
                'state' => 'taken',
                'item_id' => $found,
                'message' => "Shopee item {$found} is already linked to catalogue product #{$otherHolder}. "
                    .'Unlink that product first, then send this one again.',
            ];
        }

        ShopeeProductLink::updateOrCreate(
            ['product_id' => $productId, 'shopee_item_id' => $found, 'shopee_model_id' => null],
            ['sku' => $skus[0] ?? null]
        );

        return [
            'state' => $knownId > 0 ? 'repointed' : 'adopted',
            'item_id' => $found,
            'message' => null,
        ];
    }

    /**
     * Ask Shopee about these products and make the ERP agree. No writing to Shopee.
     *
     * The read half of the pair; Send is the write half. It replaces Match
     * Shopee ID, which could only ever fill in blanks - its first act was to
     * return early for any product that already had an item id, so the one
     * thing worth knowing, whether the id it already had is still real, was
     * the one thing it would not look at.
     *
     * A button rather than something the schedule does to everything on every
     * run. Confirming a known item is one call, but finding an unlinked one
     * means paging the whole shop, and doing that hourly for every group would
     * spend the API budget re-answering a question that only changes when
     * somebody edits Seller Centre directly.
     *
     * With rows ticked it checks those. With none ticked it checks the group.
     */
    public function checkAgainstShopee(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);

        $setting = ShopeeSetting::query()->first()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);

        if (! $setting || ! $auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';

            return $this->productsRedirect($id)->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $productIds = $this->parseIds($request);

        if (empty($productIds)) {
            $productIds = DB::table('shopee_product_group_products')
                ->where('shopee_product_group_id', $id)
                ->pluck('product_id')->map('intval')->all();
        }

        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'This group has no products to check.');
        }

        // A ceiling, reported rather than applied quietly: a check that looked
        // at the first 200 of 500 and said nothing would leave the other 300
        // appearing confirmed.
        $limit = 200;
        $skippedForSize = max(0, count($productIds) - $limit);
        $productIds = array_slice($productIds, 0, $limit);

        $pfx = (string) config('catalog.prefix');
        $verdicts = [];
        $problems = [];

        foreach ($productIds as $productId) {
            $verdict = $this->reconcileShopeeLink($client, $auth, (int) $productId, $pfx);
            $state = $verdict['state'];
            $verdicts[$state] = ($verdicts[$state] ?? 0) + 1;

            if ($state === 'unreachable') {
                // Nothing was learned, so nothing is written. The row keeps
                // what it said before, which is honest: it was a belief then
                // and it is still a belief now.
                $problems[] = "#{$productId}: {$verdict['message']}";
                continue;
            }

            if ($state === 'taken') {
                $problems[] = "#{$productId}: {$verdict['message']}";
                $this->writeProductStatus((int) $productId, [
                    'sync_status' => 'error',
                    'push_error' => Str::limit((string) $verdict['message'], 480),
                    'last_confirmed_at' => now(),
                ]);
                continue;
            }

            if ($state === 'lost' || $state === 'new') {
                // reconcileShopeeLink has already dropped a dead link and reset
                // the row; this only stamps the time it was established.
                $this->writeProductStatus((int) $productId, [
                    'sync_status' => 'pending',
                    'push_error' => null,
                    'last_confirmed_at' => now(),
                ]);
                continue;
            }

            $this->writeProductStatus((int) $productId, [
                'sync_status' => 'pushed',
                'shopee_item_id' => $verdict['item_id'] ? (string) $verdict['item_id'] : null,
                'push_error' => null,
                'last_confirmed_at' => now(),
            ]);
        }

        $checked = count($productIds) - ($verdicts['unreachable'] ?? 0);

        // Findings, not outcomes. Nothing here wrote to Shopee, so nothing may
        // suggest anything was fixed there.
        $parts = [];
        if (! empty($verdicts['confirmed'])) {
            $parts[] = $verdicts['confirmed'].' still on Shopee';
        }
        if (! empty($verdicts['adopted'])) {
            $parts[] = $verdicts['adopted'].' found and linked';
        }
        if (! empty($verdicts['repointed'])) {
            $parts[] = $verdicts['repointed'].' pointed at the wrong item and corrected';
        }
        if (! empty($verdicts['lost'])) {
            $parts[] = $verdicts['lost'].' no longer on Shopee, now marked not pushed';
        }
        if (! empty($verdicts['new'])) {
            $parts[] = $verdicts['new'].' not pushed yet';
        }
        if (! empty($verdicts['taken'])) {
            $parts[] = $verdicts['taken'].' blocked by another product holding the same item';
        }
        if (! empty($verdicts['unreachable'])) {
            $parts[] = $verdicts['unreachable'].' could not be checked';
        }

        $summary = "Checked {$checked} against Shopee. ".($parts ? implode('. ', $parts).'.' : 'Nothing to report.');

        if ($skippedForSize > 0) {
            $summary .= " {$skippedForSize} further "
                .($skippedForSize === 1 ? 'product was' : 'products were')
                .' not checked this time. Run it again to continue.';
        }

        if ($problems) {
            $summary .= ' '.implode('; ', array_slice($problems, 0, 2));
            if (count($problems) > 2) {
                $summary .= ' (and '.(count($problems) - 2).' more)';
            }
        }

        $failed = count($problems);
        $tone = $failed === 0 ? 'status' : ($checked > 0 ? 'warning' : 'error');

        return $this->productsRedirect($id)->with($tone, $summary);
    }

    /**
     * List items on Shopee that no catalogue product claims.
     *
     * The check answers "is what we have still there". This answers the mirror
     * question, "is there something there we do not know about", and only the
     * two together show the whole picture. Orphans come from ordinary events:
     * an item created in Seller Centre, a link removed, a catalogue product
     * deleted while its Shopee listing stayed live.
     *
     * Read-only on purpose. It reports; it never adopts, never deletes and
     * never writes a link. Adopting by SKU without asking is how a catalogue
     * product ends up pointed at a stranger, and only the operator knows
     * whether a familiar-looking code is really the same product.
     */
    public function orphans(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);

        $setting = ShopeeSetting::query()->first()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);

        if (! $setting || ! $auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';

            return $this->productsRedirect($id)->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $index = $this->shopeeSkuIndex($client, $auth);

        $known = ShopeeProductLink::query()
            ->whereNotNull('shopee_item_id')
            ->pluck('shopee_item_id')
            ->map('intval')
            ->flip();

        // The index is keyed by SKU, so several SKUs can point at one item.
        // Group them, or a product with four variations appears four times.
        $byItem = [];
        foreach ($index['skus'] as $sku => $itemId) {
            $byItem[$itemId][] = $sku;
        }

        $orphans = [];
        foreach ($byItem as $itemId => $skus) {
            if ($known->has((int) $itemId)) {
                continue;
            }

            sort($skus);
            $orphans[] = ['id' => (int) $itemId, 'skus' => $skus];
        }

        return view('ext-shopee::product-groups.orphans', [
            'group' => $group,
            'orphans' => $orphans,
            'scanned' => count($byItem),
            'complete' => $index['complete'],
        ]);
    }

    /**
     * One action that works whatever state the row is in.
     *
     * It replaces Push to Shopee, Update on Shopee, Match Shopee ID and Link
     * to Shopee again - four buttons that were really one question asked four
     * ways, each visible only when the ERP's own link row happened to be in a
     * particular shape. That shape was frequently wrong, and when it was, the
     * row offered nothing that could work: Push hidden because a link existed,
     * Update failing because the item did not.
     *
     * The order is the whole point. Reconcile first, so the link says what
     * Shopee actually holds, THEN let each product take the branch its
     * corrected link earns. createOnShopee and updateOnShopee still gate on
     * that link exactly as they always did; the difference is that the link is
     * now a checked fact rather than a memory.
     */
    public function send(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);

        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = ShopeeSetting::query()->first()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);

        if (! $setting || ! $auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';

            return $this->productsRedirect($id)->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $verdicts = [];
        $toCreate = [];
        $toUpdate = [];
        $blocked = [];

        foreach ($productIds as $productId) {
            $verdict = $this->reconcileShopeeLink($client, $auth, (int) $productId, $pfx);
            $verdicts[$verdict['state']] = ($verdicts[$verdict['state']] ?? 0) + 1;

            // Neither of these may be sent. 'taken' would tie two catalogue
            // products to one Shopee item; 'unreachable' means we do not know
            // what is on Shopee, and creating into that ignorance is how a
            // duplicate listing appears next to the original.
            if ($verdict['state'] === 'taken' || $verdict['state'] === 'unreachable') {
                $blocked[] = "#{$productId}: {$verdict['message']}";
                $this->writeProductStatus((int) $productId, [
                    'sync_status' => 'error',
                    'push_error' => Str::limit((string) $verdict['message'], 480),
                    'last_pushed_at' => now(),
                ]);
                continue;
            }

            // Shopee answered about this product, so the row is now a checked
            // fact rather than a belief.
            $this->writeProductStatus((int) $productId, ['last_confirmed_at' => now()]);

            if (in_array($verdict['state'], ['confirmed', 'adopted', 'repointed'], true)) {
                $toUpdate[] = (int) $productId;
            } else {
                $toCreate[] = (int) $productId;
            }
        }

        $created = ['ok' => 0, 'skip' => 0, 'err' => 0, 'errors' => []];
        $updated = ['ok' => 0, 'skip' => 0, 'err' => 0, 'errors' => []];

        if ($toCreate) {
            if (! $group->shopee_category_id) {
                $blocked[] = 'Nothing new could be created: this group has no Shopee category set.';
            } elseif (empty($group->logistic_ids ?? [])) {
                $blocked[] = 'Nothing new could be created: this group has no logistics channels set.';
            } else {
                $created = $this->createOnShopee($group, $toCreate, $client, $auth, $pfx, $langId);
            }
        }

        if ($toUpdate) {
            $updated = $this->updateOnShopee($group, $toUpdate, $client, $auth, $pfx, $langId);
        }

        // What reconciling FOUND, stated separately from what the send then
        // did about it. Collapsing the two once produced "recreated" for a
        // recreate that had failed a moment later.
        $notes = [];
        if (! empty($verdicts['lost'])) {
            $notes[] = $verdicts['lost'].' had been removed from Shopee';
        }
        if (! empty($verdicts['adopted'])) {
            $notes[] = $verdicts['adopted'].' already existed on Shopee and '
                .($verdicts['adopted'] === 1 ? 'was linked' : 'were linked');
        }
        if (! empty($verdicts['repointed'])) {
            $notes[] = $verdicts['repointed'].' had been linked to the wrong Shopee item';
        }
        if (! empty($verdicts['unreachable'])) {
            $notes[] = $verdicts['unreachable'].' could not be checked';
        }

        $okCount = $created['ok'] + $updated['ok'];
        $errCount = $created['err'] + $updated['err'] + count($blocked);
        $skipCount = $created['skip'] + $updated['skip'];

        $summary = "Send: {$created['ok']} created, {$updated['ok']} updated";
        if ($skipCount > 0) {
            $summary .= ", {$skipCount} skipped";
        }
        if ($errCount > 0) {
            $summary .= ", {$errCount} failed";
        }
        if ($notes) {
            $summary .= '. '.implode('. ', $notes).'.';
        }

        $errors = array_merge($blocked, $created['errors'], $updated['errors']);
        if ($errors) {
            $summary .= ' '.implode('; ', array_slice($errors, 0, 4));
            if (count($errors) > 4) {
                $summary .= ' (and '.(count($errors) - 4).' more)';
            }
        }

        return $this->productsRedirect($id)->with($this->batchTone($okCount, $errCount), $summary);
    }

    /**
     * Create these products on Shopee. The loop is unchanged; it was lifted
     * out of push() so that Send can drive it too.
     *
     * Its first act is still to skip a product that already has a link, and
     * that gate is only trustworthy because Send reconciles before calling
     * this: the link either reflects what Shopee holds or it has been dropped.
     * Called with a stale link it would do what it always did, which is skip a
     * product forever because the ERP remembers creating it once.
     *
     * @return array{ok:int,skip:int,err:int,errors:array<int,string>}
     */
    private function createOnShopee(ShopeeProductGroup $group, array $productIds, ShopeeClient $client, array $auth, string $pfx, int $langId): array
    {
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
                ]);

            if (!$product || (int) $product->status === 0) {
                $skipCount++;
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
            foreach ($imagePaths as $imgPath) {
                $localPath = $this->resolveLocalImagePath($imgPath);
                if (!$localPath || !file_exists($localPath)) continue;

                $uploadResult = $this->uploadImage($client, $auth, $localPath);
                if ($uploadResult['ok'] ?? false) {
                    $imgId = $uploadResult['body']['response']['image_info']['image_id'] ?? null;
                    if ($imgId) $imageIds[] = (string) $imgId;
                } else {
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
            foreach ($logisticIds as $lid) {
                $logisticInfo[] = ['logistic_id' => (int) $lid, 'enabled' => true];
            }

            $basePrice = (float) $product->price;
            $markupPct = (float) ($group->markup_percent ?? 0);
            $markupFixed = (float) ($group->markup_fixed ?? 0);
            $sellingPrice = round($basePrice + ($basePrice * $markupPct / 100) + $markupFixed, 2);

            // Build tier_variation + per-combination model list when the
            // product has multi-option variants in product_option_combinations.
            // Single-option products fall through to the legacy flat payload.
            $tierData = $this->buildTierVariation((int) $productId, $pfx, $langId, $markupPct, $markupFixed, $basePrice);
            $hasTierVariation = !empty($tierData['tier_variation']) && !empty($tierData['models']);

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
                'brand' => $this->buildBrandPayload((int) $group->shopee_category_id, (int) ($group->shopee_brand_id ?? 0)),
            ];

            // Shopee V2 does not honour tier_variation inside add_item, it
            // silently creates a plain single-SKU item and a subsequent
            // add_model fails with "Item without tier_variation. Please use
            // init_tier_variation". The correct flow is:
            //   1) add_item (basic, with item_sku)
            //   2) init_tier_variation (tier_variation + initial model_list)
            //   3) add_model only for appending later
            // Keep item_sku as the parent SKU for both flavours.

            $attributeList = $this->buildAttributeList(
                (int) $group->id,
                (int) $group->shopee_category_id
            );
            if (!empty($attributeList)) {
                $payload['attribute_list'] = $attributeList;
            }

            $path = '/api/v2/product/add_item';
            $result = $client->shopPost(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                $path, [], $payload
            );

            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.products.add_item.group', 'method' => 'POST', 'api_path' => $path,
                'auth_required' => true, 'request_params' => $payload,
                'response_status' => $result['status'] ?? null, 'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
            ]);

            $body = $result['body'] ?? [];
            $itemId = ($result['ok'] ?? false) ? ($body['response']['item_id'] ?? null) : null;

            if ($itemId) {
                // For multi-variant products, push per-combo models with
                // tier_index + model_sku + stock + price BEFORE seeding the
                // parent link, so refreshShopeeProductLinks below sees real
                // models and creates the per-model link rows the sync flow
                // depends on.
                if ($hasTierVariation && !empty($tierData['models'])) {
                    $initPath = '/api/v2/product/init_tier_variation';
                    $initPayload = [
                        'item_id' => (int) $itemId,
                        'tier_variation' => $tierData['tier_variation'],
                        'model' => array_map(function ($m) {
                            return [
                                'tier_index'     => $m['tier_index'],
                                'model_sku'      => (string) $m['model_sku'],
                                'original_price' => (float) $m['original_price'],
                                'seller_stock'   => [['stock' => (int) $m['stock']]],
                            ];
                        }, $tierData['models']),
                    ];
                    $initResult = $client->shopPost(
                        $auth['mode'],
                        (int) $auth['partner_id'], (string) $auth['partner_key'],
                        (string) $auth['access_token'], (int) $auth['shop_id'],
                        $initPath, [], $initPayload
                    );
                    ShopeeApiLog::safeCreate([
                        'pack' => 'shopee.products.init_tier_variation.group',
                        'method' => 'POST', 'api_path' => $initPath,
                        'auth_required' => true, 'request_params' => $initPayload,
                        'response_status' => $initResult['status'] ?? null,
                        'ok' => (bool) ($initResult['ok'] ?? false),
                        'response_body' => $initResult['body'] ?? null,
                        'user_id' => auth()->id(),
                    ]);
                }

                // Seed a parent link first so any later read sees the item_id
                // even if the model fetch below fails partway.
                ShopeeProductLink::create([
                    'product_id' => $productId,
                    'shopee_item_id' => (int) $itemId,
                    'shopee_model_id' => null,
                    'sku' => $product->sku ?? '',
                ]);

                /* Provenance only (#104): the push is auditable - which
                 * group's settings went, when - but a group push writes
                 * NOTHING onto the listing's own settings columns. That is
                 * the rule the owner settled: push from a group, the group's
                 * settings go; the listing's own stay its own. */
                ShopeeListing::query()->updateOrCreate(
                    ['product_id' => $productId],
                    [
                        'last_pushed_at' => now(),
                        'last_push_source' => 'group:' . $group->name,
                        'last_push_settings' => [
                            'category_id' => (int) $group->shopee_category_id,
                            'logistic_ids' => $group->logistic_ids ?? [],
                            'markup_percent' => (float) ($group->markup_percent ?? 0),
                            'markup_fixed' => (float) ($group->markup_fixed ?? 0),
                        ],
                    ]
                );

                // Update pivot sync_status
                $this->writeProductStatus($productId, [
                        'shopee_item_id' => (int) $itemId,
                        'sync_status' => 'pushed',
                        // Clears the previous failure. Without this a product
                        // that failed once and then pushed cleanly kept
                        // displaying the old reason next to its success.
                        'push_error' => null,
                        'last_pushed_at' => now(),
                    ]);

                // For multi-variant products, the parent link alone isn't
                // enough, Sync Qty/Price work per shopee_model_id. Fetch
                // model_list and create per-model link rows now so the
                // operator doesn't need to manually run "Sync ID" after a
                // push.
                $erpSkus = DB::table($pfx . 'product_option_value')
                    ->where('product_id', $productId)
                    ->whereNotNull('sku')
                    ->where('sku', '!=', '')
                    ->pluck('sku')
                    ->map(fn ($s) => strtolower(trim((string) $s)))
                    ->all();
                $comboSkus = DB::table('product_option_combinations')
                    ->where('product_id', $productId)
                    ->whereNotNull('sku')
                    ->where('sku', '!=', '')
                    ->pluck('sku')
                    ->map(fn ($s) => strtolower(trim((string) $s)))
                    ->all();
                $allSkus = array_values(array_unique(array_merge($erpSkus, $comboSkus)));
                $erpHasOptions = !empty($allSkus);

                if ($erpHasOptions) {
                    try {
                        $pivot = ShopeeProductGroupProduct::where('shopee_product_group_id', $group->id)
                            ->where('product_id', $productId)
                            ->first();
                        if ($pivot) {
                            $this->refreshShopeeProductLinks(
                                $pivot, $productId, (int) $itemId, $allSkus, true,
                                $client, $auth['mode'], (int) $auth['partner_id'],
                                (string) $auth['partner_key'], (string) $auth['access_token'],
                                (int) $auth['shop_id']
                            );
                        }
                    } catch (\Throwable $e) {
                        // Don't fail the whole push if the model refresh fails.
                        // The parent link above already exists and the operator
                        // can still hit "Sync ID" to recover variant links.
                        \Illuminate\Support\Facades\Log::warning('Shopee model link refresh failed after push', [
                            'product_id' => $productId,
                            'item_id' => $itemId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $okCount++;
            } else {
                $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'API error')) : 'API error';
                $errors[] = "#{$productId}: {$msg}";

                // The reason is SAVED, not just flashed. This used to write the
                // status alone, so "invalid category" appeared once in a banner
                // and was gone on the next page load - leaving a row marked
                // error with an empty reason column, which read as though
                // nothing had gone wrong. push_error is what that column is
                // for; the rest of this file already fills it.
                $this->writeProductStatus($productId, [
                        'sync_status' => 'error',
                        'push_error' => Str::limit($msg, 480),
                        'last_pushed_at' => now(),
                    ]);

                $errCount++;
            }

            usleep(300000); // 300ms rate limit between pushes
        }

        return ['ok' => $okCount, 'skip' => $skipCount, 'err' => $errCount, 'errors' => $errors];
    }

    /**
     * Update these products on Shopee. Lifted out of updateProduct() for the
     * same reason as createOnShopee, and carrying the mirror of its gate: it
     * skips anything NOT linked.
     *
     * @return array{ok:int,skip:int,err:int,errors:array<int,string>}
     */
    private function updateOnShopee(ShopeeProductGroup $group, array $productIds, ShopeeClient $client, array $auth, string $pfx, int $langId): array
    {
        $okCount = 0;
        $skipCount = 0;
        $errCount = 0;
        $errors = [];

        foreach ($productIds as $productId) {
            // Only process products that are already linked to Shopee
            $link = ShopeeProductLink::query()->where('product_id', $productId)->first();
            if (!$link || !$link->shopee_item_id) {
                $skipCount++;
                continue;
            }

            $itemId = (int) $link->shopee_item_id;

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
                ]);

            if (!$product || (int) $product->status === 0) {
                $errors[] = "#{$productId}: Product not found or disabled";
                $errCount++;
                continue;
            }

            $itemName = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $description = trim(strip_tags(html_entity_decode($product->description ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if (trim($itemName) === '' || trim($description) === '' || (float) $product->weight <= 0) {
                $errors[] = "#{$productId}: Missing name/description/weight";
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

            $imageIds = [];
            foreach ($imagePaths as $imgPath) {
                $localPath = $this->resolveLocalImagePath($imgPath);
                if (!$localPath || !file_exists($localPath)) continue;

                $uploadResult = $this->uploadImage($client, $auth, $localPath);
                if ($uploadResult['ok'] ?? false) {
                    $imgId = $uploadResult['body']['response']['image_info']['image_id'] ?? null;
                    if ($imgId) $imageIds[] = (string) $imgId;
                } else {
                    break;
                }
            }

            // Build update payload
            $payload = [
                'item_id' => $itemId,
                'description' => mb_substr($description, 0, 5000),
                'item_name' => mb_substr($itemName, 0, 255),
                'item_sku' => (string) ($product->sku ?? ''),
                'weight' => (float) $product->weight,
                'dimension' => [
                    'package_length' => max(1, (int) $product->length),
                    'package_width' => max(1, (int) $product->width),
                    'package_height' => max(1, (int) $product->height),
                ],
            ];

            // Only include images if we successfully uploaded at least one
            if (!empty($imageIds)) {
                $payload['image'] = ['image_id_list' => $imageIds];
            }

            $path = '/api/v2/product/update_item';
            $result = $client->shopPost(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                $path, [], $payload
            );

            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.products.update_item.group', 'method' => 'POST', 'api_path' => $path,
                'auth_required' => true, 'request_params' => $payload,
                'response_status' => $result['status'] ?? null, 'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
            ]);

            $body = $result['body'] ?? [];
            $respError = is_array($body) ? ($body['error'] ?? '') : '';

            if (($result['ok'] ?? false) && $respError === '') {
                // Also update price and stock via dedicated endpoints
                $links = ShopeeProductLink::query()->where('product_id', $productId)->get();
                $this->pushPriceForLinks($client, $auth, $pfx, $links, $group);
                $this->pushStockForLinks($client, $auth, $pfx, $links);

                $this->writeProductStatus($productId, [
                        'sync_status' => 'pushed',
                        // Both were missing. The row kept yesterday's failure
                        // reason next to today's success, and "Last push"
                        // went on reporting the last time a listing was
                        // CREATED rather than the last time it was written.
                        'push_error' => null,
                        'last_pushed_at' => now(),
                    ]);

                ActivityLogger::log('updated', 'Shopee Product', $productId, 'Updated item ' . $itemId . ' on Shopee via product group');
                $okCount++;
            } else {
                $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'API error')) : 'API error';
                $errors[] = "#{$productId}: {$msg}";

                $this->writeProductStatus($productId, [
                        'sync_status' => 'error',
                        'push_error' => Str::limit($msg, 480),
                        'last_pushed_at' => now(),
                    ]);

                $errCount++;
            }

            usleep(300000);
        }

        return ['ok' => $okCount, 'skip' => $skipCount, 'err' => $errCount, 'errors' => $errors];
    }

    public function push(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = ShopeeSetting::query()->first()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->productsRedirect($id)->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        if (!$group->shopee_category_id) {
            return $this->productsRedirect($id)->with('error', 'Product Group has no Shopee category configured. Set a category first.');
        }

        $logisticIds = $group->logistic_ids ?? [];
        if (empty($logisticIds)) {
            return $this->productsRedirect($id)->with('error', 'Product Group has no logistics channels configured.');
        }

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $r = $this->createOnShopee($group, $productIds, $client, $auth, $pfx, $langId);
        $okCount = $r['ok']; $skipCount = $r['skip']; $errCount = $r['err']; $errors = $r['errors'];

        $summary = "Push: {$okCount} pushed";
        if ($skipCount > 0) $summary .= ", {$skipCount} skipped (already linked or disabled)";
        if ($errCount > 0) $summary .= ", {$errCount} failed";
        if (!empty($errors)) $summary .= '. Errors: ' . implode('; ', array_slice($errors, 0, 5));

        return $this->productsRedirect($id)->with($okCount > 0 ? 'status' : 'error', $summary);
    }

    /**
     * Update existing Shopee listings with latest ERP data (name, description, images, weight, dimensions).
     * Also syncs price and stock via their dedicated Shopee endpoints.
     */
    public function updateProduct(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = ShopeeSetting::query()->first()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->productsRedirect($id)->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $r = $this->updateOnShopee($group, $productIds, $client, $auth, $pfx, $langId);
        $okCount = $r['ok']; $skipCount = $r['skip']; $errCount = $r['err']; $errors = $r['errors'];

        $summary = "Update: {$okCount} updated";
        if ($skipCount > 0) $summary .= ", {$skipCount} skipped (not linked)";
        if ($errCount > 0) $summary .= ", {$errCount} failed";
        if (!empty($errors)) $summary .= '. Errors: ' . implode('; ', array_slice($errors, 0, 5));

        return $this->productsRedirect($id)->with($this->batchTone($okCount, $errCount), $summary);
    }

    public function pushPrices(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = ShopeeSetting::query()->first()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->productsRedirect($id)->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $pfx = (string) config('catalog.prefix');
        $links = ShopeeProductLink::query()->whereIn('product_id', $productIds)->get();
        $skip = count($productIds) - $links->pluck('product_id')->unique()->count();

        $results = $this->pushPriceForLinks($client, $auth, $pfx, $links, $group);
        $this->recordSyncOutcomes($group->id, $results['outcomes'] ?? []);

        return $this->productsRedirect($id)->with(
            $this->batchTone($results['ok'], $results['err']),
            "Sync Price: {$results['ok']} success, {$results['err']} error, {$skip} skipped (not linked)."
        );
    }

    public function pushStock(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = ShopeeSetting::query()->first()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->productsRedirect($id)->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $pfx = (string) config('catalog.prefix');

        // Filter out disabled products
        $enabledIds = DB::table($pfx . 'product')
            ->whereIn('product_id', $productIds)
            ->where('status', 1)
            ->pluck('product_id')
            ->toArray();
        $disabledCount = count($productIds) - count($enabledIds);

        $links = ShopeeProductLink::query()->whereIn('product_id', $enabledIds)->get();
        $skip = count($enabledIds) - $links->pluck('product_id')->unique()->count();

        $results = $this->pushStockForLinks($client, $auth, $pfx, $links);
        $this->recordSyncOutcomes($group->id, $results['outcomes'] ?? []);

        $msg = "Sync Qty: {$results['ok']} success, {$results['err']} error, {$skip} skipped (not linked)";
        if ($disabledCount > 0) {
            $msg .= ", {$disabledCount} skipped (disabled)";
        }

        return $this->productsRedirect($id)->with(
            $this->batchTone($results['ok'], $results['err']),
            $msg . '.'
        );
    }

    public function deleteFromShopee(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = ShopeeSetting::query()->first()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->productsRedirect($id)->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $okCount = 0;
        $errCount = 0;
        $errors = [];
        $path = '/api/v2/product/delete_item';

        foreach ($productIds as $productId) {
            $link = ShopeeProductLink::query()->where('product_id', $productId)->first();
            if (!$link || !$link->shopee_item_id) {
                $errCount++;
                continue;
            }

            $itemId = (int) $link->shopee_item_id;

            $result = $client->shopPost(
                $auth['mode'],
                (int) $auth['partner_id'],
                (string) $auth['partner_key'],
                (string) $auth['access_token'],
                (int) $auth['shop_id'],
                $path,
                [],
                ['item_id' => $itemId]
            );

            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.product.delete_item.group',
                'method' => 'POST',
                'api_path' => $path,
                'auth_required' => true,
                'request_params' => ['item_id' => $itemId, 'product_id' => $productId],
                'response_status' => (int) ($result['status'] ?? 0),
                'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? null,
                'user_id' => auth()->id(),
            ]);

            $body = $result['body'] ?? [];
            $respError = is_array($body) ? ($body['error'] ?? '') : '';

            if (($result['ok'] ?? false) && $respError === '') {
                ShopeeItemCache::query()->where('shopee_item_id', $link->shopee_item_id)->delete();
                ShopeeProductLink::query()->where('product_id', $productId)->delete();

                // Clear pivot sync data
                $this->writeProductStatus($productId, ['shopee_item_id' => null, 'sync_status' => 'pending']);

                ActivityLogger::log('deleted', 'Shopee Product', $productId, 'Deleted item ' . $itemId . ' from Shopee via product group');
                $okCount++;
            } else {
                // The reason used to be discarded here, so "3 deleted, 9
                // failed" was the entire account of nine listings still
                // sitting on a public marketplace. Which nine, and why, was
                // not recoverable from anywhere in the interface.
                $msg = $this->apiFailureMessage($result);
                $errors[] = "#{$productId}: {$msg}";

                $this->writeProductStatus($productId, [
                        'sync_status' => 'error',
                        'push_error' => Str::limit('Delete from Shopee failed. ' . $msg, 480),
                        'last_pushed_at' => now(),
                    ]);

                $errCount++;
            }
        }

        $summary = "Delete from Shopee: {$okCount} deleted, {$errCount} failed.";
        if (! empty($errors)) {
            $summary .= ' ' . implode('; ', array_slice($errors, 0, 5));
            if (count($errors) > 5) {
                // Says so rather than trailing off. A truncated list that does
                // not admit it is truncated reads as the whole list.
                $summary .= ' (and ' . (count($errors) - 5) . ' more, shown against their rows)';
            }
        }

        return $this->productsRedirect($id)->with($this->batchTone($okCount, $errCount), $summary);
    }

    // ── Sync Helpers ─────────────────────────────────────────────────

    /**
     * Write a product's status to EVERY group that holds it, not just the one
     * the operator is standing in.
     *
     * Whether a product is on Shopee is one fact and it was stored once per
     * group, so the same product read differently on different pages. 42
     * products on this installation sit in more than one group. A push that
     * failed in one left that page red forever after a later success
     * elsewhere, and a success left a green badge on the other page hiding a
     * real failure - the quiet direction, and the dangerous one.
     *
     * No group scope is passed because Shopee is single-store: every group
     * belongs to the same shop, so every group is talking about the same
     * remote product.
     */
    private function writeProductStatus(int $productId, array $attributes): void
    {
        \App\Support\ChannelProductStatus::write(
            'shopee_product_group_products',
            'shopee_product_group_id',
            null,
            $productId,
            $attributes
        );
    }

    /**
     * The words Shopee refused with, or a stand-in that admits we have none.
     *
     * Never returns an empty string. A blank reason written to push_error is
     * indistinguishable from never having failed, which is the whole defect
     * this file has been carrying.
     */
    private function apiFailureMessage(array $result): string
    {
        $body = $result['body'] ?? [];

        if (! is_array($body)) {
            return 'API error';
        }

        foreach (['message', 'error'] as $key) {
            $value = trim((string) ($body[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'API error';
    }

    /**
     * Which flash key a batch has earned.
     *
     * Price and stock pushes used to flash 'status' unconditionally, and
     * 'status' renders as a green tick. "Sync Price: 0 success, 12 error"
     * arrived in the success colour, which is the most confident way an
     * interface can be wrong. A batch that lost rows is not a success, and a
     * batch that lost all of them is a failure.
     */
    private function batchTone(int $ok, int $err): string
    {
        if ($err === 0) {
            return 'status';
        }

        return $ok > 0 ? 'warning' : 'error';
    }

    /**
     * Write per-product outcomes onto the rows the operator is looking at.
     *
     * The push helpers already recorded each failure onto shopee_product_links
     * - but the products page reads the GROUP PIVOT, so every one of those
     * failures was invisible here. The only trace a price push had failed was
     * a flash message the next click destroyed.
     *
     * Success clears the previous reason as well as setting the status. A row
     * that failed yesterday and pushed cleanly today must not keep displaying
     * yesterday's complaint.
     */
    private function recordSyncOutcomes(int $groupId, array $outcomes): void
    {
        foreach ($outcomes as $productId => $outcome) {
            $this->writeProductStatus((int) $productId, [
                    'sync_status' => $outcome['ok'] ? 'pushed' : 'error',
                    'push_error' => $outcome['ok'] ? null : Str::limit($outcome['error'], 480),
                    'last_pushed_at' => now(),
                ]);
        }
    }

    private function parseIds(Request $request): array
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids)) $ids = [];
        return array_values(array_unique(array_filter(array_map(fn($v) => (int) $v, $ids), fn($v) => $v > 0)));
    }

    private function pushStockForLinks(ShopeeClient $client, array $auth, string $pfx, $links): array
    {
        $ok = 0;
        $err = 0;
        $outcomes = [];

        // Resolve qty per link through the shared service (combinations
        // first, POV fallback). Cron uses the same resolver.
        $resolved = app(ShopeeStockPushService::class)->resolve($links, $pfx);

        $grouped = [];
        foreach ($resolved as $row) {
            $grouped[$row['item_id']][] = $row;
        }

        foreach ($grouped as $itemId => $rows) {
            $stockList = [];
            $itemLinks = [];

            foreach ($rows as $row) {
                $stockList[] = ['model_id' => $row['model_id'], 'seller_stock' => [['stock' => $row['quantity']]]];
                $itemLinks[] = $row['link'];
            }

            if (empty($stockList)) continue;

            $path = '/api/v2/product/update_stock';
            $payload = ['item_id' => (int) $itemId, 'stock_list' => $stockList];

            $result = $client->shopPost(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
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
            $failure = $isOk ? null : $this->apiFailureMessage($result);

            foreach ($itemLinks as $link) {
                $link->update($syncData);

                // A product with several models has failed if ANY of them
                // failed: the listing is not right on Shopee until all of it
                // is. A recorded failure is never overwritten by a later
                // success on a sibling model.
                $pid = (int) $link->product_id;
                if (! $isOk) {
                    $outcomes[$pid] = ['ok' => false, 'error' => $failure];
                } elseif (! array_key_exists($pid, $outcomes)) {
                    $outcomes[$pid] = ['ok' => true, 'error' => null];
                }
            }

            if ($isOk) {
                $ok += count($stockList);
            } else {
                $err += count($stockList);
            }
        }

        return ['ok' => $ok, 'err' => $err, 'outcomes' => $outcomes];
    }

    private function pushPriceForLinks(ShopeeClient $client, array $auth, string $pfx, $links, ShopeeProductGroup $group): array
    {
        $ok = 0;
        $err = 0;
        $outcomes = [];

        $resolved = app(ShopeeStockPushService::class)->resolve($links, $pfx);

        $markupPct = (float) ($group->markup_percent ?? 0);
        $markupFixed = (float) ($group->markup_fixed ?? 0);

        $grouped = [];
        foreach ($resolved as $row) {
            $grouped[$row['item_id']][] = $row;
        }

        foreach ($grouped as $itemId => $rows) {
            $priceList = [];
            $itemLinks = [];

            foreach ($rows as $row) {
                $price = $row['price'];
                // Apply product group markup
                $price = $price + ($price * $markupPct / 100) + $markupFixed;
                $priceList[] = ['model_id' => $row['model_id'], 'original_price' => round($price, 2)];
                $itemLinks[] = $row['link'];
            }

            if (empty($priceList)) continue;

            $path = '/api/v2/product/update_price';
            $payload = ['item_id' => (int) $itemId, 'price_list' => $priceList];

            $result = $client->shopPost(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
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
            $failure = $isOk ? null : $this->apiFailureMessage($result);

            foreach ($itemLinks as $link) {
                $link->update($syncData);

                // See pushStockForLinks: any failed model fails the product.
                $pid = (int) $link->product_id;
                if (! $isOk) {
                    $outcomes[$pid] = ['ok' => false, 'error' => $failure];
                } elseif (! array_key_exists($pid, $outcomes)) {
                    $outcomes[$pid] = ['ok' => true, 'error' => null];
                }
            }

            if ($isOk) {
                $ok += count($priceList);
            } else {
                $err += count($priceList);
            }
        }

        return ['ok' => $ok, 'err' => $err, 'outcomes' => $outcomes];
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

    private function uploadImage(ShopeeClient $client, array $auth, string $localPath): array
    {
        $path = '/api/v2/media_space/upload_image';
        $timestamp = time();
        $sign = $client->signShop(
            (int) $auth['partner_id'], (string) $auth['partner_key'],
            $path, $timestamp, (string) $auth['access_token'], (int) $auth['shop_id']
        );

        $query = http_build_query([
            'partner_id' => (int) $auth['partner_id'], 'timestamp' => $timestamp,
            'sign' => $sign, 'access_token' => (string) $auth['access_token'],
            'shop_id' => (int) $auth['shop_id'],
        ]);

        $url = $client->baseUrl($auth['mode']) . $path . '?' . $query;

        $contents = file_get_contents($localPath);
        if ($contents === false) {
            return ['status' => 0, 'ok' => false, 'body' => ['error' => 'file_read_failed', 'message' => 'Could not read image file: ' . basename($localPath)]];
        }

        $response = Http::timeout(30)
            ->attach('image', $contents, basename($localPath))
            ->post($url);

        return ['status' => $response->status(), 'ok' => $response->ok(), 'body' => $response->json() ?? $response->body()];
    }

    public function syncTemplate(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::query()->findOrFail($id);

        $request->validate(['shopee_category_id' => 'required|integer|min:1']);
        $shopeeCategoryId = (int) $request->input('shopee_category_id');
        $group->update(['shopee_category_id' => $shopeeCategoryId]);

        $setting = ShopeeSetting::query()->first()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->route('ext.shopee.product-groups.edit', $group->id)
                ->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $path = '/api/v2/product/get_attribute_tree';

        $result = $client->shopGet(
            $auth['mode'],
            (int)$auth['partner_id'],
            (string)$auth['partner_key'],
            (string)$auth['access_token'],
            (int)$auth['shop_id'],
            $path,
            ['category_id' => $shopeeCategoryId, 'language' => $auth['region'] ?: 'en']
        );

        $body = $result['body'] ?? null;

        if (!($result['ok'] ?? false)) {
            $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'API call failed')) : 'API call failed';
            return redirect()->route('ext.shopee.product-groups.edit', $group->id)
                ->with('error', 'Sync failed: ' . $msg);
        }

        // Try multiple response keys, Shopee may use attribute_list or attribute_tree
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
                'region' => (string)($auth['region'] ?? ''),
                'attributes' => $attrList ?? ($response ?? $body),
                'fetched_at' => now(),
            ]
        );

        $count = is_array($attrList) ? count($attrList) : 0;
        $debugKeys = is_array($response ?? null) ? implode(', ', array_keys($response)) : 'n/a';

        return redirect()->route('ext.shopee.product-groups.edit', $group->id)
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
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return response()->json(['ok' => false, 'message' => "Missing Shopee {$modeLabel} settings."]);
        }

        $result = $client->shopGet(
            $auth['mode'],
            (int) $auth['partner_id'],
            (string) $auth['partner_key'],
            (string) $auth['access_token'],
            (int) $auth['shop_id'],
            '/api/v2/product/get_category',
            ['language' => $auth['region'] ?: 'en']
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

        // Mirror the index() filter: only leaves are pickable.
        $leafQuery = ShopeeCategory::query()->where('leaf', 1)->orderBy('name')->limit(5000);
        $categories = $leafQuery->get(['category_id', 'name']);
        if ($categories->isEmpty()) {
            $categories = ShopeeCategory::query()->orderBy('name')->limit(5000)->get(['category_id', 'name']);
        }
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
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return response()->json(['ok' => false, 'html' => '<div class="text-muted">Missing Shopee ' . $modeLabel . ' settings.</div>']);
        }

        $result = $client->shopGet(
            $auth['mode'],
            (int) $auth['partner_id'],
            (string) $auth['partner_key'],
            (string) $auth['access_token'],
            (int) $auth['shop_id'],
            '/api/v2/product/get_attribute_tree',
            ['category_id' => $categoryId, 'language' => $auth['region'] ?: 'en']
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
                'region' => (string) ($auth['region'] ?? ''),
                'attributes' => $attrList ?? ($response ?? $body),
                'fetched_at' => now(),
            ]
        );

        $attributes = $this->extractAttributes($template->attributes);

        $html = view('ext-shopee::product-groups._attributes', [
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
