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
use Extensions\shopee\Services\ShopeeStoreProducts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopeeProductGroupController extends Controller
{
    /** The last push's skip/heal ledger, appended to the flash (Phase E). */
    private array $lastPushLedger = [];

    /* One check instance for the whole request: send, orphans and the
     * check share its SKU-index and existence memos (Phase F, #184). */
    public function __construct(
        private readonly \Extensions\shopee\Services\Shopee\ShopeeLinkCheck $linkCheck,
    ) {
    }

    private function productsRedirect(int $id)
    {
        // Only a local path is followed: '//host' and '/\\host' would leave the site.
        $fallback = route('ext.shopee.product-groups.products', $id);

        return redirect(\App\Support\BackTo::safe(request()->input('_return'), $fallback));
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

        /* No product list here since #201 (the owner, 2026-09-05): this form is
         * the group's own settings only. Products join and leave the group
         * on its products page, through the Add products panel over THIS
         * STORE's list - never a whole-catalogue dual list. */

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
            'productCount' => $group->exists ? \Illuminate\Support\Facades\DB::table('shopee_product_group_products')->where('shopee_product_group_id', $group->id)->count() : 0,
            'group' => $group,
            'mode' => $mode,
            'shopeeCategories' => $shopeeCategories,
            'logistics' => $logistics,
            'template' => $template,
            'attributes' => $attributes,
            'saved' => $saved,
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
            'watermark_template_id' => 'nullable|integer',
        ], [
            'shopee_category_id.required' => 'Shopee Category is required.',
            'logistic_ids.required' => 'At least one Logistics Channel is required.',
        ]);

        $logIds = array_filter($data['logistic_ids'] ?? []);

        $group = ShopeeProductGroup::create([
            'name' => $data['name'],
            'shopee_category_id' => $data['shopee_category_id'] ?? null,
            'shopee_brand_id' => (int) ($data['shopee_brand_id'] ?? 0),
            'logistic_ids' => !empty($logIds) ? array_values(array_map('intval', $logIds)) : null,
            'markup_fixed' => $data['markup_fixed'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null, 'watermark_template_id' => \App\Models\WatermarkTemplate::idOrNull($data['watermark_template_id'] ?? null),
        ]);

        ActivityLogger::log('created', 'Shopee Product group', $group->id, $group->name);

        $this->saveGroupAttributes($group, (array) $request->input('attributes', []));

        // The next thing to do with a new group is put products in it, and
        // that happens on its products page (#201), so land there.
        return redirect()->route('ext.shopee.product-groups.products', $group->id)
            ->with('status', 'Product group created. Add products to it from this store\'s list.');
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
            'watermark_template_id' => 'nullable|integer',
        ], [
            'shopee_category_id.required' => 'Shopee Category is required.',
            'logistic_ids.required' => 'At least one Logistics Channel is required.',
        ]);

        $logIds = array_filter($data['logistic_ids'] ?? []);

        $group->update([
            'name' => $data['name'],
            'shopee_category_id' => $data['shopee_category_id'] ?? null,
            'shopee_brand_id' => (int) ($data['shopee_brand_id'] ?? 0),
            'logistic_ids' => !empty($logIds) ? array_values(array_map('intval', $logIds)) : null,
            'markup_fixed' => $data['markup_fixed'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null, 'watermark_template_id' => \App\Models\WatermarkTemplate::idOrNull($data['watermark_template_id'] ?? null),
        ]);

        ActivityLogger::log('updated', 'Shopee Product group', $group->id, $group->name);

        $this->saveGroupAttributes($group, (array) $request->input('attributes', []));

        // Membership is not this form's business since #201: a stray
        // product_ids[] in the request changes nothing.
        return redirect()->route('ext.shopee.product-groups.edit', $group->id)
            ->with('status', 'Product group saved.');
    }

    public function destroy(int $id)
    {
        $group = ShopeeProductGroup::query()->findOrFail($id);
        $name = $group->name;
        $group->delete();

        ActivityLogger::log('deleted', 'Shopee Product group', (int) $id, $name);

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

        /* The scope strips (cleanup point 4, the owner 2026-09-04): "the
         * product group IS the same as All Products, only filtered by
         * group" - so this page wears the same Your catalogue / On Shopee
         * strips as the workbench, counted over the MEMBERS. Same mirror,
         * same tab keys, same freshness stamp. */
        $tabMap = ['live' => 'NORMAL', 'unlisted' => 'UNLIST', 'violation' => 'BANNED', 'reviewing' => 'REVIEWING'];
        $shopeeTab = (string) $request->get('shopee_tab', 'all');
        if (!array_key_exists($shopeeTab, $tabMap)) {
            $shopeeTab = 'all';
        }
        $memberIds = $group->groupProducts()->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        $liveCounts = null;
        $liveCheckedAt = null;
        if ($setting && $auth['complete']) {
            $liveCounts = ShopeeProductLink::mirrorCounts($tabMap, $memberIds);
            $liveCheckedAt = ShopeeProductLink::query()->whereIn('product_id', $memberIds ?: [0])->max('live_checked_at');
            $liveCheckedAt = $liveCheckedAt ? \Illuminate\Support\Carbon::parse($liveCheckedAt) : null;
        } else {
            $shopeeTab = 'all';
        }

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
                'p.quantity', 'p.image', 'p.status', 'p.date_modified',
                'm.name as manufacturer_name',
                'gp.shopee_item_id', 'gp.sync_status', 'gp.last_pushed_at', 'gp.last_confirmed_at', 'gp.push_error'
            );

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%")
                    ->orWhere('p.model', 'like', "%{$q}%")
                    // The row shows this store's own title, so the search finds it by that too.
                    ->orWhereIn('p.product_id', ShopeeListing::query()
                        ->forStore(ShopeeSetting::defaultStore())
                        ->where('item_name', 'like', "%{$q}%")
                        ->select('product_id'));
            });
        }

        if ($erpStatus === 'enabled') {
            $query->where('p.status', 1);
        } elseif ($erpStatus === 'disabled') {
            $query->where('p.status', 0);
        }

        /* The filter answers what the column shows. Listed/Not listed are
         * PRODUCT facts now (stage 1 of the one-truth repointing,
         * 2026-08-24): they read shopee_product_links, the same record the
         * Listings page reads, so a product pushed through any door filters
         * the same on every page. 'error' is the error line itself, the same
         * set the Listings page filters on; 'unlinked' stays per-group. */
        $erroredIds = $this->listingStates()->erroredProductIds();
        $linkedProductIds = DB::table('shopee_product_links')
            ->when(app()->bound('shopee.route-store'), fn ($q) => $q->where('shopee_setting_id', app('shopee.route-store')->id))
            ->whereNotNull('shopee_item_id')
            ->select('product_id');
        if ($syncStatus === 'pushed') {
            $query->whereIn('p.product_id', $linkedProductIds);
        } elseif ($syncStatus === 'pending') {
            $query->whereNotIn('p.product_id', $linkedProductIds);
        } elseif ($syncStatus === 'error') {
            $query->whereIn('p.product_id', $erroredIds ?: [0]);
        } elseif ($syncStatus === 'unlinked') {
            // The badge has always had four states and the filter offered
            // three, so unlinked products could only be found by eye.
            $query->where('gp.sync_status', 'unlinked');
        }

        // The members' own total, before a Shopee tab narrows the table.
        $catalogueTotal = (clone $query)->count();

        if ($shopeeTab !== 'all' && $liveCounts !== null) {
            $tabProductIds = ShopeeProductLink::query()
                ->whereIn('product_id', $memberIds ?: [0])
                ->where('live_status', $tabMap[$shopeeTab])
                ->pluck('product_id')->map(fn ($v) => (int) $v)->all();
            $query->whereIn('p.product_id', $tabProductIds ?: [0]);
        }

        $products = $query->orderBy('p.product_id', 'desc')->paginate(50)->appends($request->except('page'));

        /* The GET-time back-fill that used to live here - adopting link
         * item ids into the pivot while rendering - is GONE (stage 2 of
         * the one-truth repointing, 2026-08-25). It existed to patch the
         * pivot's drifting copy of a product fact for display; stage 1
         * repointed the display at shopee_product_links itself, so the
         * page no longer needs a write to be right, and a read once again
         * writes nothing. */
        $pivotProductIds = $products->pluck('product_id')->toArray();

        $pivotMap = ShopeeProductGroupProduct::query()
            ->where('shopee_product_group_id', $group->id)
            ->whereIn('product_id', $pivotProductIds)
            ->get()
            ->keyBy('product_id');

        // Variation rows: the shared combinations-first query the core
        // product list uses, image and proper option names included
        // (App\Support\VariationRows). The pov fallback for never-resaved
        // products lives inside it.
        $optionRowsByProductId = \App\Support\VariationRows::forListing($pivotProductIds, 'shopee', (int) (ShopeeSetting::defaultStore()?->id ?? 0));

        // All product IDs in this group (for "Remove from Group" action)
        $manualIds = $group->groupProducts()->pluck('product_id')->toArray();

        // Counted across the WHOLE group, not the current page or filter, by
        // the same set the error filter lists, so the count and the rows it
        // links to agree.
        $failedCount = count(array_intersect($erroredIds, array_map('intval', $memberIds)));

        /* Product-level truth for the three columns that used to read the
         * pivot's private copies: the links (is it on Shopee, which item)
         * and the listing record (what the last push actually was, from
         * where). Same rows every group page and the Listings page read. */
        $displayIds = $products->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $linksByProductId = ShopeeProductLink::query()
            ->whereIn('product_id', $displayIds)
            ->get()
            ->groupBy(fn ($l) => (int) $l->product_id);
        $listingsByProductId = ShopeeListing::query()
            ->forStore(ShopeeSetting::defaultStore())
            ->whereIn('product_id', $displayIds)
            ->get()
            ->keyBy('product_id');
        // Each row is named by this store's own title, the catalog's name when it has none.
        $rowTitles = \App\Integrations\Listings\ListingContent::rowTitles($products, $listingsByProductId);
        // Each row's starting price: the listing's own Price, the catalog's when it has none.
        $withVariations = ShopeeListing::withVariations($displayIds);
        $rowPrices = $products->mapWithKeys(fn ($p) => [(int) $p->product_id => ($listingsByProductId->get((int) $p->product_id) ?? new ShopeeListing())
            ->startingPrice((float) $p->price, isset($withVariations[(int) $p->product_id]))])->all();

        /* ONE state per row (Phase B, #184): this page used to show
         * "Listed" for a product Shopee had banned, because its column
         * only asked whether a link row existed. */
        $listingStates = app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)
            ->forProducts($displayIds);

        return view('ext-shopee::product-groups.products', [
            'listingStates' => $listingStates,
            // The Last sync column (the owner, 2026-09-05), one answer shared with the Listings page.
            'rowErrors' => app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)->errors($displayIds),
            'group' => $group,
            'failedCount' => $failedCount,
            'products' => $products,
            'pivotMap' => $pivotMap,
            'optionRowsByProductId' => $optionRowsByProductId,
            'linksByProductId' => $linksByProductId,
            'listingsByProductId' => $listingsByProductId,
            'rowTitles' => $rowTitles,
            'rowPrices' => $rowPrices,
            'manualIds' => $manualIds,
            'q' => $q,
            'syncStatus' => $syncStatus,
            'erpStatus' => $erpStatus,
            'shopeeTab' => $shopeeTab,
            'liveCounts' => $liveCounts,
            'liveCheckedAt' => $liveCheckedAt,
            'catalogueTotal' => $catalogueTotal,
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
        $hidden = \App\Integrations\Listings\ListingVariations::hidden('shopee', (int) (\Extensions\shopee\Models\ShopeeSetting::defaultStore()?->id ?? 0), [$productId]);
        $combos = $combos->filter(fn ($c) => \App\Integrations\Listings\ListingVariations::allows($hidden, $productId, $c->sku))->values();
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
     * Resolve saved attribute values against the category's cached template
     * into the attribute_list shape add_item / update_item expect. Shared
     * with the LISTING pushes (#98), which hold their values on
     * shopee_listings.attribute_values.
     *
     * Saved values are looked up by attribute id FIRST, then by name. The
     * form has always posted attributes[<attribute_id>] (that is the key
     * the template rows carry), but this resolver used to read by display
     * NAME only - so every value saved under an id silently vanished from
     * the payload and group pushes sent an empty attribute_list. Found
     * while re-homing the form onto the listing (2026-08-24).
     */
    public function attributeListFromSaved(array $savedByKey, int $categoryId): array
    {
        if (empty($savedByKey) || $categoryId <= 0) {
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

            $value = trim((string) ($savedByKey[(string) $attrId] ?? $savedByKey[$attrKey] ?? ''));
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
     * The category's cached attribute sheet, normalized for rendering, plus
     * the template row itself. Shared with the listing page.
     */
    public function normalizedAttributesFor(int $categoryId): array
    {
        $template = $categoryId > 0
            ? ShopeeCategoryTemplate::query()->where('category_id', $categoryId)->first()
            : null;

        return [
            'template' => $template,
            'attributes' => $template ? $this->extractAttributes($template->attributes) : [],
        ];
    }

    /**
     * Fetch and cache the category's attribute tree when it has never been
     * read. Quiet on failure by design: the caller renders whatever the
     * cache holds, and Refresh reads it again. A caller walking several
     * stores passes the store's own auth.
     */
    public function ensureTemplate(int $categoryId, ShopeeClient $client, ?array $auth = null): void
    {
        if ($categoryId <= 0 || ShopeeCategoryTemplate::query()->where('category_id', $categoryId)->exists()) {
            return;
        }

        if ($auth === null) {
            $setting = ShopeeSetting::defaultStore()?->decrypted();
            $auth = ShopeeSetting::activeAuth($setting);
            if (!$setting) {
                return;
            }
        }
        if (!($auth['complete'] ?? false)) {
            return;
        }

        try {
            $result = $client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_attribute_tree',
                ['category_id' => $categoryId, 'language' => $auth['region'] ?: 'en']
            );
        } catch (\Throwable) {
            return;
        }

        // An error answer is not a sheet: filing it would read as a category with no required questions.
        if (!($result['ok'] ?? false) || !\App\Support\MarketplaceVerdict::ok('shopee', true, $result['body'] ?? null)) {
            return;
        }

        $body = $result['body'] ?? null;
        $response = is_array($body) ? ($body['response'] ?? $body) : null;
        $attrList = is_array($response)
            ? ($response['attribute_list'] ?? $response['attribute_tree'] ?? $response['attributes'] ?? null)
            : null;

        ShopeeCategoryTemplate::query()->updateOrCreate(
            ['category_id' => $categoryId],
            [
                'region' => (string) ($auth['region'] ?? ''),
                'attributes' => $attrList ?? ($response ?? $body),
                'fetched_at' => now(),
            ]
        );
    }

    /**
     * Build the `brand` block for a Shopee add_item / update_item payload.
     * Looks up the human-readable brand name from the cached shopee_brands
     * table so Shopee accepts the brand_id + name pair. brand_id=0 → "No Brand"
     * default that every category accepts.
     */
    public function buildBrandPayload(int $categoryId, int $brandId): array
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
    /** The cache key that says a category's brand list has been read to its last page. */
    private function brandsCompleteKey(int $categoryId): string
    {
        return 'shopee.brands.complete.' . $categoryId;
    }

    /**
     * One page of a category's brand list, stored, with where the next
     * page starts. Answers `complete` once Shopee says there is no next
     * page, and at once on a category already read to the end.
     */
    private function warmBrands(int $categoryId, int $offset, ShopeeClient $client)
    {
        $read = fn () => ShopeeBrand::query()->where('category_id', $categoryId)->count();

        if (\Illuminate\Support\Facades\Cache::get($this->brandsCompleteKey($categoryId))) {
            return response()->json(['ok' => true, 'complete' => true, 'read' => $read()]);
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            return response()->json(['ok' => false, 'message' => 'This store is not connected yet. Authorise it with Shopee first.'], 412);
        }

        $pageSize = 100;
        $result = $client->shopGet(
            $auth['mode'],
            (int) $auth['partner_id'], (string) $auth['partner_key'],
            (string) $auth['access_token'], (int) $auth['shop_id'],
            '/api/v2/product/get_brand_list',
            ['category_id' => $categoryId, 'offset' => $offset, 'page_size' => $pageSize, 'status' => 1]
        );

        if (!($result['ok'] ?? false)) {
            $body = $result['body'] ?? null;
            $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'the call failed')) : 'the call failed';

            return response()->json(['ok' => false, 'message' => 'Shopee did not answer with its brands: ' . $msg], 502);
        }

        $body = $result['body'] ?? [];
        $resp = $body['response'] ?? $body;
        $now = now();
        $before = $read();
        foreach ((array) ($resp['brand_list'] ?? []) as $b) {
            if (!is_array($b)) continue;
            $bid = (int) ($b['brand_id'] ?? 0);
            $name = trim((string) ($b['original_brand_name'] ?? $b['display_brand_name'] ?? $b['name'] ?? ''));
            if ($bid <= 0 || $name === '') continue;
            ShopeeBrand::updateOrCreate(
                ['category_id' => $categoryId, 'brand_id' => $bid],
                ['name' => $name, 'status' => 1, 'raw' => $b, 'updated_at' => $now]
            );
        }

        /* The end of the list is what Shopee says AND what the page grew
         * by (live, 2026-09-07: "Reading brands from Shopee... 100 so far"
         * never moved). A page that repeats brands already read, a page
         * with none, or an offset past twenty thousand ends the warm, so
         * the popup can never loop on a shop that keeps answering the same
         * page with has_next_page set. Shopee's own next_offset is used
         * when it gives one. */
        $after = $read();
        $nextOffset = (int) ($resp['next_offset'] ?? 0);
        if ($nextOffset <= $offset) {
            $nextOffset = $offset + $pageSize;
        }
        $hasNext = (bool) ($resp['has_next_page'] ?? false) && !empty($resp['brand_list']) && $after > $before && $nextOffset < 20000;
        if (!$hasNext) {
            \Illuminate\Support\Facades\Cache::forever($this->brandsCompleteKey($categoryId), true);
        }

        return response()->json([
            'ok' => true,
            'complete' => !$hasNext,
            'next_offset' => $nextOffset,
            'read' => $after,
        ]);
    }

    public function brandsForCategory(Request $request, ShopeeClient $client)
    {
        $categoryId = (int) $request->query('category_id', 0);
        if ($categoryId <= 0) {
            return response()->json(['ok' => false, 'message' => 'category_id is required.'], 422);
        }

        /* Optional search terms, for the brand COMBOBOX (2026-08-24): the
         * plain select swallowed the whole list, the combobox asks for the
         * 25 best matches of what the operator typed. Absent q/limit keeps
         * the original whole-list answer for any older caller. */
        $q = trim((string) $request->query('q', ''));
        $limit = (int) $request->query('limit', 0);
        $filterBrands = function ($query) use ($q, $limit) {
            if ($q !== '') {
                $query->where(function ($w) use ($q) {
                    $w->where('name', 'like', '%' . $q . '%');
                    if (ctype_digit($q)) {
                        $w->orWhere('brand_id', (int) $q);
                    }
                });
            }
            if ($limit > 0) {
                $query->limit(max(1, min(50, $limit)));
            }

            return $query;
        };

        /* Warming (the owner, 2026-09-05): a category's brand list is read from
         * Shopee one page per request, so the page can draw progress while
         * a first-time list of thousands comes in, instead of a search box
         * that hangs. The combobox warms on category pick; the search below
         * then answers from the cache. */
        if ($request->boolean('warm')) {
            return $this->warmBrands($categoryId, max(0, (int) $request->query('offset', 0)), $client);
        }

        // Serve from cache if we've fetched this category before.
        $cacheHasAny = ShopeeBrand::query()->where('category_id', $categoryId)->exists();
        if ($cacheHasAny) {
            $cached = $filterBrands(ShopeeBrand::query()
                ->where('category_id', $categoryId)
                ->orderBy('name'))
                ->get(['brand_id', 'name']);

            return response()->json([
                'ok' => true,
                'cached' => true,
                'brands' => $cached->map(fn ($b) => ['brand_id' => (int) $b->brand_id, 'name' => (string) $b->name])->all(),
            ]);
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
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

        $brands = $filterBrands(ShopeeBrand::query()
            ->where('category_id', $categoryId)
            ->orderBy('name'))
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
     * The group page's Add products panel search (#201, Draft C - the owner
     * 2026-09-05): THIS STORE's products, never the whole catalogue - a group
     * only holds what the store carries, so a product the store does not
     * have is not offered here; it is added on Listings first. Each row is
     * flagged with whether it is already in this group, or owned by another
     * group under the one-group rule (named, so the panel offers Move here
     * rather than a refusal after the press). Capped like the Listings
     * panel; defaults to what is NOT yet in this group.
     */
    public function productSearch(Request $request, int $id)
    {
        $group = ShopeeProductGroup::query()->findOrFail($id);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->query('q', ''));
        $showAll = $request->boolean('all');
        $limit = 25;

        $members = ShopeeProductGroupProduct::query()
            ->where('shopee_product_group_id', $group->id)
            ->select('product_id');

        $query = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            // The main catalogue, each row flagged with whether this store
            // carries it (the owner, 2026-09-05, option 2): a fresh store has
            // nothing listed yet, and a group must not be a dead end there.
            // Adding a product the store does not carry lists it as it joins.
            ->leftJoinSub(ShopeeStoreProducts::query(), 'os', 'os.product_id', '=', 'p.product_id')
            ->select('p.product_id', 'pd.name', 'p.image', 'p.sku', 'p.model', 'p.quantity', 'p.price', 'os.product_id as on_store_pid')
            ->where('p.status', 1);

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.model', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%');
            });
        }
        if (! $showAll) {
            $query->whereNotIn('p.product_id', $members);
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('pd.name')->orderBy('p.product_id')->limit($limit)->get();
        $ids = $rows->pluck('product_id')->map(fn ($v) => (int) $v)->all();

        $inGroup = $ids === [] ? collect() : ShopeeProductGroupProduct::query()
            ->where('shopee_product_group_id', $group->id)
            ->whereIn('product_id', $ids)
            ->pluck('product_id')->map(fn ($v) => (int) $v)->flip();

        $owners = $ids === [] ? collect() : DB::table('shopee_product_group_products as pv')
            ->join('shopee_product_groups as g', 'g.id', '=', 'pv.shopee_product_group_id')
            ->whereIn('pv.product_id', $ids)
            ->where('g.id', '!=', $group->id)
            ->when(app()->bound('shopee.route-store'), fn ($w) => $w->where('g.shopee_setting_id', app('shopee.route-store')->id))
            ->get(['pv.product_id', 'g.name'])
            ->keyBy('product_id');

        $items = $rows->map(function ($r) use ($inGroup, $owners) {
            $pid = (int) $r->product_id;
            $image = trim((string) ($r->image ?? ''));

            return [
                'id' => $pid,
                'name' => html_entity_decode((string) ($r->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Unnamed product',
                'sku' => (string) ($r->sku ?? ''),
                'quantity' => (int) ($r->quantity ?? 0),
                'price' => \App\Support\Money::base((float) ($r->price ?? 0)),
                'thumb' => $image !== '' ? asset('storage/' . ltrim($image, '/')) : null,
                // The panel's "already in" flag: in this group.
                'on_store' => $inGroup->has($pid),
                'elsewhere' => $owners->get($pid)?->name,
                // Whether this store carries it yet; adding lists it if not.
                'listed' => ! is_null($r->on_store_pid),
            ];
        })->values();

        return response()->json(['total' => $total, 'shown' => $items->count(), 'limit' => $limit, 'items' => $items]);
    }

    /**
     * Add a catalogue product to the group, in place (#201, Draft C). A
     * product this store does not carry yet is listed on it as it joins
     * (the owner, 2026-09-05, option 2): the same blank listing record the
     * Listings page's Add products creates, so the group is never a dead
     * end on a fresh store. The one-group rule holds - a product owned by
     * another group is held unless the panel said Move here (`move`), in
     * which case it moves.
     */
    public function addProduct(Request $request, int $id, int $productId)
    {
        $group = ShopeeProductGroup::query()->findOrFail($id);
        $inCatalogue = DB::table((string) config('catalog.prefix') . 'product')
            ->where('product_id', $productId)->where('status', 1)->exists();
        abort_unless($inCatalogue, 404);

        $listedNow = false;
        if (! ShopeeStoreProducts::has($productId)) {
            ShopeeListing::firstOrCreate(['product_id' => $productId]);
            $listedNow = true;
        }

        $already = $group->groupProducts()->where('product_id', $productId)->exists();
        $added = false;
        $movedFrom = null;
        $held = [];

        if (! $already) {
            $claim = \App\Integrations\OneGroupRule::claim(
                'shopee_product_group_products', 'shopee_product_group_id', 'shopee_product_groups', 'shopee_setting_id',
                $group->id, [$productId], $request->boolean('move') ? [$productId] : []
            );
            foreach (array_merge($claim['free'], array_keys($claim['moved'])) as $pid) {
                ShopeeProductGroupProduct::create([
                    'shopee_product_group_id' => $group->id,
                    'product_id' => (int) $pid,
                ]);
                $added = true;
            }
            $movedFrom = $claim['moved'][$productId] ?? null;
            $held = $claim['held'];
        }

        if ($request->wantsJson()) {
            if (! $already && ! $added) {
                // Held by another group and no Move here: say so, do nothing.
                return response()->json(['ok' => false, 'product_id' => $productId, 'message' => trim(\App\Integrations\OneGroupRule::heldClause($held))], 409);
            }

            return response()->json(['ok' => true, 'product_id' => $productId, 'added' => $added, 'moved_from' => $movedFrom, 'listed' => $listedNow]);
        }

        $status = $already
            ? "Product #{$productId} is already in this product group."
            : ($added
                ? "Product #{$productId} added to the product group." . ($movedFrom !== null ? " Moved here from '{$movedFrom}'." : '') . ($listedNow ? ' It is on this store now too.' : '')
                : trim(\App\Integrations\OneGroupRule::heldClause($held)));

        return $this->productsRedirect($group->id)->with($added ? 'status' : 'warning', $status);
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
        $removed = ShopeeProductLink::unlinkProduct($productId, (int) app('shopee.route-store')->id);

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
            return redirect()->back()->with('error', 'Product not in this product group.');
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
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

        // If already linked, refresh models from the API. Linked-ness is the
        // links table's answer (stage 2 of the one-truth repointing) - the
        // pivot's item id is a drifting copy, and unlink deletes links now,
        // so the old 'unlinked' pivot label decides nothing.
        $existingLink = ShopeeProductLink::query()
            ->where('product_id', $productId)
            ->whereNotNull('shopee_item_id')
            ->first();
        if ($existingLink) {
            return $this->refreshShopeeProductLinks($pivot, $productId, (int) $existingLink->shopee_item_id, $skus, $client, $mode, $pid, $pkey, $token, $shopId);
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

                    /* Models are their own call (2026-09-09): base info
                       carries has_model, never model_list. Read off the
                       base info this matched nothing, ever, so an item
                       with no parent SKU was unfindable here too. */
                    if (! empty($il['has_model'])) {
                        $modelRes = $client->shopGet($mode, $pid, $pkey, $token, $shopId,
                            '/api/v2/product/get_model_list', ['item_id' => (int) $il['item_id']]);
                        foreach (($modelRes['body']['response']['model'] ?? []) as $model) {
                            $modelSku = strtolower(trim((string) ($model['model_sku'] ?? '')));
                            if ($modelSku !== '' && in_array($modelSku, $skus)) {
                                $matchedItemId = (int) $il['item_id'];
                                break 3;
                            }
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

        return $this->refreshShopeeProductLinks($pivot, $productId, (int) $matchedItemId, $skus, $client, $mode, $pid, $pkey, $token, $shopId);
    }

    /**
     * Refresh Shopee product links and cache from the API model list.
     * Called by syncId for both new links and already-linked products.
     * The actual work lives in ShopeeModelLinkRepair (Phase E, #184) so a
     * refused push can run the same repair mid-flight; this wrapper only
     * keeps the pivot and the redirect. It branches on SHOPEE's answer,
     * not on whether the ERP has option SKUs.
     */
    private function refreshShopeeProductLinks(
        object $pivot, int $productId, int $itemId, array $skus,
        \Extensions\shopee\Services\Shopee\ShopeeClient $client, string $mode, int $pid, string $pkey, string $token, int $shopId
    ) {
        $auth = ['mode' => $mode, 'partner_id' => $pid, 'partner_key' => $pkey, 'access_token' => $token, 'shop_id' => $shopId];
        $r = app(\Extensions\shopee\Services\Shopee\ShopeeModelLinkRepair::class)
            ->forItem($auth, $productId, $itemId, $skus);

        if (isset($r['error'])) {
            $this->listingStates()->recordOutcome($productId, "Shopee did not answer for item {$itemId}: {$r['error']}");
            return redirect()->back()->with('error', "Shopee did not answer for item {$itemId}: {$r['error']}. Nothing was changed.");
        }
        $this->listingStates()->recordOutcome($productId, null);

        $pivot->update(['shopee_item_id' => (string) $itemId, 'sync_status' => 'pushed']);

        return redirect()->back()->with('status', $r['mode'] === 'models'
            ? "Shopee item ID {$itemId} synced, {$r['linked']} variation(s) linked."
            : "Shopee item ID {$itemId} synced.");
    }

    public function linkProduct(int $groupId, int $productId)
    {
        $group = ShopeeProductGroup::query()->findOrFail($groupId);
        $pivot = $group->groupProducts()->where('product_id', $productId)->first();

        // Said "Product re-linked." even for a product that is not in this
        // group at all, because the message sat outside the check.
        if (! $pivot) {
            return $this->productsRedirect($group->id)
                ->with('error', 'That product is not in this product group.');
        }

        // Already holds an item id: re-linking is just clearing the unlinked
        // state.
        if (! empty($pivot->shopee_item_id)) {
            $pivot->update(['sync_status' => 'pushed']);

            return $this->productsRedirect($group->id)
                ->with('status', 'Product re-linked to Shopee item ' . $pivot->shopee_item_id . '.');
        }

        $linkId = DB::table('shopee_product_links')
            ->when(app()->bound('shopee.route-store'), fn ($q) => $q->where('shopee_setting_id', app('shopee.route-store')->id))
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
        $this->listingStates()->clearErrors([$productId]);

        return $this->productsRedirect($group->id)
            ->with('status', 'Product removed from the product group.');
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
        $this->listingStates()->clearErrors($ids);

        $summary = "{$removed} product(s) removed from the product group.";
        if ($removed < count($ids)) {
            $summary .= ' ' . (count($ids) - $removed) . ' were not in the product group.';
        }

        return $this->productsRedirect($group->id)
            ->with($removed > 0 ? 'status' : 'warning', $summary);
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

        $setting = ShopeeSetting::defaultStore()?->decrypted();
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
            return $this->productsRedirect($id)->with('error', 'This product group has no products to check.');
        }

        $r = $this->linkCheck->run($client, $auth, $productIds);

        return $this->productsRedirect($id)->with($r['tone'], $r['summary']);
    }

    /**
     * List items on Shopee that no catalog product claims.
     *
     * The check answers "is what we have still there". This answers the mirror
     * question, "is there something there we do not know about", and only the
     * two together show the whole picture. Orphans come from ordinary events:
     * an item created in Seller Centre, a link removed, a catalog product
     * deleted while its Shopee listing stayed live.
     *
     * Read-only on purpose. It reports; it never adopts, never deletes and
     * never writes a link. Adopting by SKU without asking is how a catalog
     * product ends up pointed at a stranger, and only the operator knows
     * whether a familiar-looking code is really the same product.
     */
    public function orphans(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);

        if (! $setting || ! $auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';

            return $this->productsRedirect($id)->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $index = $this->linkCheck->skuIndex($client, $auth);

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

        $setting = ShopeeSetting::defaultStore()?->decrypted();
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
            $verdict = $this->linkCheck->reconcile($client, $auth, (int) $productId, $pfx);
            $verdicts[$verdict['state']] = ($verdicts[$verdict['state']] ?? 0) + 1;

            // Neither of these may be sent. 'taken' would tie two catalog
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

            // Shopee answered about this product, so the checked fact goes
            // onto the product's own record, visible from every page.
            ShopeeListing::query()->updateOrCreate(
                ['product_id' => (int) $productId],
                ['last_checked_at' => now()]
            );

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
                $blocked[] = 'Nothing new could be created: this product group has no Shopee category set.';
            } elseif (empty($group->logistic_ids ?? [])) {
                $blocked[] = 'Nothing new could be created: this product group has no logistics channels set.';
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
        $warnings = $updated['warnings'] ?? [];
        if ($warnings) {
            $summary .= ' '.implode(' ', array_slice($warnings, 0, 4));
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
        /* Derived HERE, not inherited from the caller. This body was
         * extracted out of push(), whose guard defined $logisticIds in the
         * shared scope - the extraction left the definition behind, so the
         * payload loop iterated null and every group push since sent
         * "logistic_info": [] (Shopee: "At least one shipping channel must
         * be enabled"). Found by the owner on QAS, 2026-08-24. */
        $logisticIds = array_map('intval', $group->logistic_ids ?? []);

        $okCount = 0;
        $skipCount = 0;
        $errCount = 0;
        $errors = [];

        /* THE GATE (the owner, 2026-09-06): the readiness the Ready column
         * shows, judged on what THIS push sends - the group's category,
         * couriers, price rule and attribute answers filling what each
         * listing leaves blank - so a product that read Ready is never turned
         * away here, and one that is not is named with what it needs. */
        $groupAnswers = ShopeeProductGroupAttribute::query()
            ->where('shopee_product_group_id', (int) $group->id)
            ->pluck('value', 'attribute_key')->toArray();
        $this->ensureTemplate((int) $group->shopee_category_id, $client); // a category never opened is read now
        $readinessAll = app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class)->forProducts($productIds, [
            'category_id' => (int) $group->shopee_category_id ?: null,
            'logistic_ids' => $logisticIds,
            'markup_percent' => $group->markup_percent !== null ? (float) $group->markup_percent : null,
            'markup_fixed' => $group->markup_fixed !== null ? (float) $group->markup_fixed : null,
            'attributes' => $groupAnswers,
        ]);
        // Each listing's own values, with its group filling only the blanks.
        $inherit = app(\Extensions\shopee\Services\Shopee\ShopeeInheritedSettings::class);
        $inheritedAll = $inherit->forProducts($productIds);

        foreach ($productIds as $productId) {
            // Skip already linked
            if (ShopeeProductLink::query()->where('product_id', $productId)->exists()) {
                $skipCount++;
                continue;
            }

            $readiness = $readinessAll[$productId] ?? ['ready' => false, 'missing' => []];
            if (!$readiness['ready']) {
                $refusal = \App\Integrations\Listings\CatalogGaps::refusal($readiness);
                $errors[] = "#{$productId}: {$refusal}";
                $this->writeProductStatus($productId, ['sync_status' => 'error', 'push_error' => $refusal]);
                $errCount++;
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

            if (!$product) {
                $skipCount++;
                continue;
            }

            /* The store listing's own words win over the catalog's, through
             * the one reader every push path shares (2026-09-07). This site
             * used to send the CATALOG's title while the listing's own sat
             * saved and unused. */
            $ownListing = ShopeeListing::query()->where('product_id', (int) $productId)->first();

            /* What this product sends: its listing's own category, couriers,
             * brand, price rule and attribute answers, the group filling only
             * what the listing leaves blank. The same values the listing
             * page's own push sends. */
            $eff = $inherit->fill($ownListing ?? (new ShopeeListing())->forceFill(['product_id' => (int) $productId]), $inheritedAll[(int) $productId] ?? null)['listing'];
            if ($eff->markup_percent === null && $eff->markup_fixed === null) {
                $eff->markup_percent = $group->markup_percent;
                $eff->markup_fixed = $group->markup_fixed;
            }
            $effCategory = (int) ($eff->shopee_category_id ?? 0) ?: (int) $group->shopee_category_id;
            $effLogistics = !empty($eff->logistic_ids) ? array_map('intval', (array) $eff->logistic_ids) : $logisticIds;
            $effBrand = (int) ($eff->shopee_brand_id ?? 0) ?: (int) ($group->shopee_brand_id ?? 0);
            if ($effCategory !== (int) $group->shopee_category_id) {
                $this->ensureTemplate($effCategory, $client);
            }
            $content = \App\Integrations\Listings\ListingContent::of(
                $ownListing,
                html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                \App\Support\Catalog\DescriptionText::of((string) ($product->description ?? '')),
                'shopee',
                (int) (\Extensions\shopee\Models\ShopeeSetting::defaultStore()?->id ?? 0)
            );
            $itemName = $content['title'];
            // Shopee accepts plain text only; description templates may contain HTML.
            $description = \App\Support\Catalog\DescriptionText::of($content['description']);

            /* The listing's own pictures, in its own order, through the
               one reader every push path shares (2026-09-08). This copy
               read the catalog directly and, unlike the listing push,
               forgot Shopee's nine-image cap. */
            $create = app(\Extensions\shopee\Services\Shopee\ShopeeItemCreate::class);
            $imagePaths = $create->catalogImagePaths($product, $ownListing);

            $imageIds = $create->uploadImages($client, $auth, $imagePaths)['ids'];

            if (empty($imageIds)) {
                $errors[] = "#{$productId}: Image upload failed";
                $this->writeProductStatus($productId, ['sync_status' => 'error', 'push_error' => 'Image upload failed']);
                $errCount++;
                continue;
            }

            // Build payload
            $logisticInfo = [];
            foreach ($effLogistics as $lid) {
                $logisticInfo[] = ['logistic_id' => (int) $lid, 'enabled' => true];
            }

            $basePrice = (float) $product->price;
            $markupPct = (float) ($eff->markup_percent ?? 0);
            $markupFixed = (float) ($eff->markup_fixed ?? 0);
            $sellingPrice = $eff->itemPriceFor($basePrice, ShopeeListing::hasVariations((int) $productId));

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
                // The listing's own parcel, each blank field following the catalog's.
                'weight' => (float) ($eff->weight ?? $product->weight),
                'dimension' => [
                    'package_length' => (int) ($eff->package_length ?? max(1, (int) $product->length)),
                    'package_width' => (int) ($eff->package_width ?? max(1, (int) $product->width)),
                    'package_height' => (int) ($eff->package_height ?? max(1, (int) $product->height)),
                ],
                'category_id' => $effCategory,
                'image' => ['image_id_list' => $imageIds],
                'logistic_info' => $logisticInfo,
                'brand' => $this->buildBrandPayload($effCategory, $effBrand),
            ];

            // Shopee V2 does not honour tier_variation inside add_item, it
            // silently creates a plain single-SKU item and a subsequent
            // add_model fails with "Item without tier_variation. Please use
            // init_tier_variation". The correct flow is:
            //   1) add_item (basic, with item_sku)
            //   2) init_tier_variation (tier_variation + initial model_list)
            //   3) add_model only for appending later
            // Keep item_sku as the parent SKU for both flavours.

            /* The listing's own attribute answers over the group's, the
             * same merge readiness judged (2026-09-06). */
            $attributeList = $this->attributeListFromSaved((array) ($eff->attribute_values ?? []), $effCategory);
            if (!empty($attributeList)) {
                $payload['attribute_list'] = $attributeList;
            }

            /* #192's variation refusals are readiness gaps, judged at the
             * gate above before anything was uploaded. */

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

                $confirmItemIds[] = (int) $itemId;
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

                /* Variations ride the group push too, priced by the rule
                 * that priced the item: the listing's own, else the
                 * group's. Tiers are structure, not wording. */
                $variations = app(\Extensions\shopee\Services\Shopee\ShopeeVariationPush::class)->pushForProduct(
                    $client, $auth, (int) $itemId, $productId,
                    fn (float $core) => $eff->priceFor($core),
                    null
                );
                if ($variations !== null && !$variations['ok']) {
                    /* #192: half-listed is worse than unlisted - take the
                     * just-created item down again and record ONE honest
                     * failure instead of a success wearing an error. */
                    $undone = app(\Extensions\shopee\Services\Shopee\ShopeeVariationPush::class)
                        ->undoCreate($client, $auth, $productId, (int) $itemId);
                    $reason = $undone
                        ? 'not pushed - its variations were refused (' . $variations['message'] . '); the item was removed from Shopee again'
                        : 'its variations were refused (' . $variations['message'] . ') and Shopee also refused to remove the half-created item ' . $itemId . ' - delete it in Seller Centre';
                    $errors[] = "#{$productId}: {$reason}";
                    $this->writeProductStatus($productId, ['shopee_item_id' => null, 'sync_status' => 'error', 'push_error' => $reason]);
                    $errCount++;
                    continue;
                }

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
                                $pivot, $productId, (int) $itemId, $allSkus,
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
                $msg = \App\Support\MarketplaceAnswer::plain('Shopee', ['ok' => false, 'body' => is_array($body) ? $body : []]);
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

        // Write-through for the batch: the mirror carries each pushed
        // item's real status (NORMAL or REVIEWING) before the page renders.
        app(\Extensions\shopee\Services\Shopee\ShopeeLiveListing::class)->confirm($auth, $confirmItemIds ?? []);

        return ['ok' => $okCount, 'skip' => $skipCount, 'err' => $errCount, 'errors' => $errors];
    }

    /**
     * Update these products on Shopee. Lifted out of updateProduct() for the
     * same reason as createOnShopee, and carrying the mirror of its gate: it
     * skips anything NOT linked.
     *
     * @return array{ok:int,skip:int,err:int,errors:array<int,string>,warnings:array<int,string>}
     */
    private function updateOnShopee(ShopeeProductGroup $group, array $productIds, ShopeeClient $client, array $auth, string $pfx, int $langId): array
    {
        $okCount = 0;
        $skipCount = 0;
        $errCount = 0;
        $errors = [];
        $warnings = [];

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
                $this->writeProductStatus($productId, ['sync_status' => 'error', 'push_error' => 'Product not found or disabled']);
                $errCount++;
                continue;
            }

            /* The store listing's own words win over the catalog's, through
             * the one reader every push path shares (2026-09-07). This site
             * used to send the CATALOG's title while the listing's own sat
             * saved and unused. */
            $ownListing = ShopeeListing::query()->where('product_id', (int) $productId)->first();
            $content = \App\Integrations\Listings\ListingContent::of(
                $ownListing,
                html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                \App\Support\Catalog\DescriptionText::of((string) ($product->description ?? '')),
                'shopee',
                (int) (\Extensions\shopee\Models\ShopeeSetting::defaultStore()?->id ?? 0)
            );
            $itemName = $content['title'];
            // Shopee accepts plain text only; description templates may contain HTML.
            $description = \App\Support\Catalog\DescriptionText::of($content['description']);
            if (trim($itemName) === '' || trim($description) === '' || (float) ($ownListing?->weight ?? $product->weight) <= 0) {
                $errors[] = "#{$productId}: Missing name/description/weight";
                $this->writeProductStatus($productId, ['sync_status' => 'error', 'push_error' => 'Missing name/description/weight']);
                $errCount++;
                continue;
            }

            /* The listing's own pictures, in its own order, through the
               one reader every push path shares (2026-09-08). This copy
               read the catalog directly and, unlike the listing push,
               forgot Shopee's nine-image cap. */
            $create = app(\Extensions\shopee\Services\Shopee\ShopeeItemCreate::class);
            $imagePaths = $create->catalogImagePaths($product, $ownListing);

            $imageIds = $create->uploadImages($client, $auth, $imagePaths)['ids'];

            // Build update payload
            $payload = [
                'item_id' => $itemId,
                'description' => mb_substr($description, 0, 5000),
                'item_name' => mb_substr($itemName, 0, 255),
                'item_sku' => (string) ($product->sku ?? ''),
                // The listing's own parcel, each blank field following the catalog's.
                'weight' => (float) ($ownListing?->weight ?? $product->weight),
                'dimension' => [
                    'package_length' => (int) ($ownListing?->package_length ?? max(1, (int) $product->length)),
                    'package_width' => (int) ($ownListing?->package_width ?? max(1, (int) $product->width)),
                    'package_height' => (int) ($ownListing?->package_height ?? max(1, (int) $product->height)),
                ],
            ];

            // Only include images if we successfully uploaded at least one
            if (!empty($imageIds)) {
                $payload['image'] = ['image_id_list' => $imageIds];
            }

            // The description goes in the item's own shape: plain, or Seller Center's extended list.
            $payload = \Extensions\shopee\Services\Shopee\ShopeeDescription::into(
                $payload, (string) $payload['description'],
                (new \Extensions\shopee\Services\Shopee\ShopeeDescription($client))->shapeOf($auth, (int) $itemId)
            );

            /* The item's models are read once; the rename and the store's
             * variation switches both work from that read. */
            $switches = app(\Extensions\shopee\Services\Shopee\ShopeeVariationSwitches::class);
            $read = $switches->readFor($client, $auth, $itemId, (int) $productId);

            /* The catalog names the item's own variation types. A rename that
             * cannot happen is skipped and said, and the rest of the update goes. */
            $rename = app(\Extensions\shopee\Services\Shopee\ShopeeVariationRename::class);
            $plan = $rename->plan($client, $auth, $itemId, (int) $productId, $read);
            $renamed = $rename->apply($client, $auth, $plan);
            if (!$renamed['ok']) {
                $warnings[] = "#{$productId}: {$renamed['message']}";
            }

            /* A variation switched off for this store stops selling: its
             * model is deleted. One sold here that the item lacks is added,
             * priced by the listing's own rule, else the group's. */
            $inherit = app(\Extensions\shopee\Services\Shopee\ShopeeInheritedSettings::class);
            $eff = $inherit->fill(
                $ownListing ?? (new ShopeeListing())->forceFill(['product_id' => (int) $productId]),
                $inherit->forProducts([(int) $productId])[(int) $productId] ?? null
            )['listing'];
            if ($eff->markup_percent === null && $eff->markup_fixed === null) {
                $eff->markup_percent = $group->markup_percent;
                $eff->markup_fixed = $group->markup_fixed;
            }
            $followed = $switches->follow(
                $client, $auth, $itemId, (int) $productId,
                \Extensions\shopee\Services\Shopee\ShopeeVariationSwitches::renamed($read, $plan, $renamed),
                fn (float $core) => (float) $eff->priceFor($core),
                $create->tierImageUploader($client, $auth)
            );
            $switchNote = \Extensions\shopee\Services\Shopee\ShopeeVariationSwitches::note($followed);
            if ($switchNote !== '') {
                $warnings[] = "#{$productId}: {$switchNote}";
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
                $synced = [
                    $this->pushPriceForLinks($client, $auth, $pfx, $links, $group),
                    $this->pushStockForLinks($client, $auth, $pfx, $links),
                ];
                // A content update can send the item back under review.
                app(\Extensions\shopee\Services\Shopee\ShopeeLiveListing::class)->confirm($auth, $links->pluck('shopee_item_id')->all());

                $this->writeProductStatus($productId, [
                        'sync_status' => 'pushed',
                        // Both were missing. The row kept yesterday's failure
                        // reason next to today's success, and "Last push"
                        // went on reporting the last time a listing was
                        // CREATED rather than the last time it was written.
                        'push_error' => null,
                        'last_pushed_at' => now(),
                    ]);
                // The update went; a price or stock refusal in the same run is still its outcome.
                foreach ($synced as $sync) {
                    $outcome = $sync['outcomes'][$productId] ?? null;
                    if ($outcome !== null && empty($outcome['ok'])) {
                        $this->listingStates()->recordOutcome($productId, (string) ($outcome['error'] ?? ''));
                        break;
                    }
                }
                // A variation Shopee would not remove or add is the outcome too.
                if ($followed['failure'] !== null) {
                    $this->listingStates()->recordOutcome($productId, $followed['failure']);
                }

                ActivityLogger::log('updated', 'Shopee Product', $productId, 'Updated item ' . $itemId . ' on Shopee via product group');
                $okCount++;
            } else {
                $msg = \App\Support\MarketplaceAnswer::plain('Shopee', ['ok' => false, 'body' => is_array($body) ? $body : []]);
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

        return ['ok' => $okCount, 'skip' => $skipCount, 'err' => $errCount, 'errors' => $errors, 'warnings' => $warnings];
    }

    public function push(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->productsRedirect($id)->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        if (!$group->shopee_category_id) {
            return $this->productsRedirect($id)->with('error', 'This product group has no Shopee category configured. Set a category first.');
        }

        $logisticIds = $group->logistic_ids ?? [];
        if (empty($logisticIds)) {
            return $this->productsRedirect($id)->with('error', 'This product group has no logistics channels configured.');
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

        $setting = ShopeeSetting::defaultStore()?->decrypted();
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
        if (!empty($r['warnings'])) $summary .= '. ' . implode(' ', array_slice($r['warnings'], 0, 5));

        return $this->productsRedirect($id)->with($this->batchTone($okCount, $errCount), $summary);
    }

    public function pushPrices(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
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
                . \Extensions\shopee\Services\Shopee\ShopeeStockPricePush::ledgerClause($this->lastPushLedger)
        );
    }

    public function pushStock(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
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
        $ledger = \Extensions\shopee\Services\Shopee\ShopeeStockPricePush::ledgerClause($this->lastPushLedger);

        return $this->productsRedirect($id)->with(
            $this->batchTone($results['ok'], $results['err']),
            $msg . '.' . $ledger
        );
    }

    public function deleteFromShopee(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
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
                $this->listingStates()->clearErrors([$productId]);

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
     * This store's groups only: a failure against one shop says nothing
     * about another.
     */
    private function writeProductStatus(int $productId, array $attributes): void
    {
        $storeId = (int) (ShopeeSetting::defaultStore()?->id ?? 0);
        \App\Support\ChannelProductStatus::write(
            'shopee_product_group_products',
            'shopee_product_group_id',
            $storeId > 0
                ? ShopeeProductGroup::query()->withoutGlobalScope('shopeeStore')->where('shopee_setting_id', $storeId)
                    ->pluck('id')->map(fn ($v) => (int) $v)->all()
                : null,
            $productId,
            $attributes
        );

        // The listing's error line is the outcome of this write.
        if (($attributes['sync_status'] ?? null) === 'error') {
            $this->listingStates()->recordOutcome($productId, (string) ($attributes['push_error'] ?? ''));
        } elseif (($attributes['sync_status'] ?? null) === 'pushed') {
            $this->listingStates()->recordOutcome($productId, null);
        }
    }

    /** The listing engine for the store this request stands in. */
    private function listingStates(): \Extensions\shopee\Services\Shopee\ShopeeListingStates
    {
        return app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class);
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
        return \App\Integrations\Push\PushLedger::batchTone($ok, $err);
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
        /* The shared sender (Phase E, #184, #190): link-driven plan, skip
         * ledger, failure_list verdicts and the one-shot model repair. This
         * wrapper keeps the outcomes shape the group flow records. */
        $results = app(\Extensions\shopee\Services\Shopee\ShopeeStockPricePush::class)
            ->push('stock', $auth, $links, $pfx);
        $this->lastPushLedger = $results;
        [$ok, $err, $outcomes] = [$results['ok'], $results['err'], $results['outcomes']];

        return ['ok' => $ok, 'err' => $err, 'outcomes' => $outcomes];
    }

    private function pushPriceForLinks(ShopeeClient $client, array $auth, string $pfx, $links, ShopeeProductGroup $group): array
    {
        /* The one price rule every push uses: the listing's own markup,
         * else its group's, else the catalog price. */
        $priceFor = \Extensions\shopee\Commands\ShopeePushPrice::priceRule(
            collect($links)->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all()
        );

        $results = app(\Extensions\shopee\Services\Shopee\ShopeeStockPricePush::class)
            ->push('price', $auth, $links, $pfx, $priceFor);
        $this->lastPushLedger = $results;
        [$ok, $err, $outcomes] = [$results['ok'], $results['err'], $results['outcomes']];

        return ['ok' => $ok, 'err' => $err, 'outcomes' => $outcomes];
    }

    public function syncTemplate(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::query()->findOrFail($id);

        $request->validate(['shopee_category_id' => 'required|integer|min:1']);
        $shopeeCategoryId = (int) $request->input('shopee_category_id');
        $group->update(['shopee_category_id' => $shopeeCategoryId]);

        $setting = ShopeeSetting::defaultStore()?->decrypted();
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
        $setting = ShopeeSetting::defaultStore()?->decrypted();
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
            $msg = \App\Support\MarketplaceAnswer::plain('Shopee', ['ok' => false, 'body' => is_array($body) ? $body : []]);
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

        $setting = ShopeeSetting::defaultStore()?->decrypted();
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
