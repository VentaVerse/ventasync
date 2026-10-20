<?php

namespace Extensions\Shopee\Controllers;

use App\Http\Controllers\Controller;

use Extensions\Shopee\Models\ShopeeApiLog;
use Extensions\Shopee\Models\ShopeeBrand;
use Extensions\Shopee\Models\ShopeeCategory;
use Extensions\Shopee\Models\ShopeeCategoryTemplate;
use Extensions\Shopee\Models\ShopeeItemCache;
use Extensions\Shopee\Models\ShopeeLogistic;
use Extensions\Shopee\Models\ShopeeProductGroup;
use Extensions\Shopee\Models\ShopeeProductGroupAttribute;
use Extensions\Shopee\Models\ShopeeProductGroupProduct;
use Extensions\Shopee\Models\ShopeeListing;
use Extensions\Shopee\Models\ShopeeListingLink;
use Illuminate\Support\Str;
use Extensions\Shopee\Models\ShopeeStore;
use App\Services\ActivityLogger;
use Extensions\Shopee\Services\Shopee\ShopeeClient;
use Extensions\Shopee\Services\ShopeeStockPushService;
use Extensions\Shopee\Services\ShopeeStoreProducts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopeeProductGroupController extends Controller
{
    use \App\Http\Controllers\Concerns\DrivesGroupSendRuns;

    protected function groupSendChannel(): string
    {
        return 'shopee';
    }

    protected function groupSendStoreId(Request $request): int
    {
        return (int) ShopeeStore::defaultStore()?->id;
    }

    protected function groupSendMembers(int $groupId): array
    {
        $group = ShopeeProductGroup::query()->where('store_id', (int) ShopeeStore::defaultStore()?->id)->findOrFail($groupId);

        return $group->groupProducts()->whereIn('product_id', \Extensions\Shopee\Services\ShopeeStoreProducts::query())->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
    }

    protected function groupSendChunk(int $groupId, array $productIds): array
    {
        return $this->sendProducts(ShopeeProductGroup::query()->findOrFail($groupId), $productIds, app(ShopeeClient::class));
    }

    private array $lastPushLedger = [];

    public function __construct(
        private readonly \Extensions\Shopee\Services\Shopee\ShopeeLinkCheck $linkCheck,
    ) {
    }

    private function productsRedirect(int $id)
    {
        $fallback = route('ext.shopee.product-groups.products', $id);

        return redirect(\App\Support\BackTo::safe(request()->input('_return'), $fallback));
    }

    public function index()
    {
        $groups = ShopeeProductGroup::query()->orderByDesc('id')->get();

        $shopeeCatIds = $groups->pluck('shopee_category_id')->filter()->unique()->values()->all();
        $shopeeCategoryNames = collect();
        if (!empty($shopeeCatIds)) {
            $shopeeCategoryNames = ShopeeCategory::query()
                ->whereIn('category_id', $shopeeCatIds)
                ->pluck('name', 'category_id');
        }

        $productCounts = DB::table('ch_shopee_product_group_products')
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
        $leafQuery = ShopeeCategory::query()->where('leaf', 1)->orderBy('name')->limit(5000);
        $shopeeCategories = $leafQuery->get();
        if ($shopeeCategories->isEmpty()) {
            $shopeeCategories = ShopeeCategory::query()->orderBy('name')->limit(5000)->get();
        }

        $logistics = $this->fetchLogistics();

        $template = null;
        $attributes = [];
        $saved = [];
        if ($group->shopee_category_id) {
            $template = ShopeeCategoryTemplate::query()
                ->where('category_id', (int) $group->shopee_category_id)
                ->first();

            if ($template && $template->attributes) {
                $attributes = \Extensions\Shopee\Services\Shopee\ShopeeCategoryAttributes::extract($template->attributes);
            }

            if ($group->exists) {
                $saved = ShopeeProductGroupAttribute::query()
                    ->where('shopee_product_group_id', $group->id)
                    ->pluck('value', 'attribute_key')
                    ->toArray();
            }
        }

        $initialBrands = $group->shopee_category_id
            ? ShopeeBrand::query()
                ->where('category_id', (int) $group->shopee_category_id)
                ->orderBy('name')
                ->get(['brand_id', 'name'])
            : collect();

        return view('ext-shopee::product-groups.form', [
            'productCount' => $group->exists ? \Illuminate\Support\Facades\DB::table('ch_shopee_product_group_products')->where('shopee_product_group_id', $group->id)->count() : 0,
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
            'markup_percent' => $data['markup_percent'] ?? null, 'watermark_template_id' => \App\Models\WatermarkTemplate::idOrNull($data['watermark_template_id'] ?? null, 'shopee', \App\Channels\Listings\ListingStore::id('shopee')),
        ]);

        ActivityLogger::log('created', 'Shopee Product group', $group->id, $group->name);

        $this->saveGroupAttributes($group, (array) $request->input('attributes', []));

        return redirect(\App\Support\BackTo::safe(
            $request->input('_return'),
            route('ext.shopee.product-groups.products', $group->id)
        ))->with('status', 'Product group created. Add products to it from this store\'s list.');
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
            'markup_percent' => $data['markup_percent'] ?? null, 'watermark_template_id' => \App\Models\WatermarkTemplate::idOrNull($data['watermark_template_id'] ?? null, 'shopee', \App\Channels\Listings\ListingStore::id('shopee')),
        ]);

        ActivityLogger::log('updated', 'Shopee Product group', $group->id, $group->name);

        $this->saveGroupAttributes($group, (array) $request->input('attributes', []));

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

    public function products(Request $request, int $id)
    {
        $group = ShopeeProductGroup::query()->findOrFail($id);
        $langId = (int) config('catalog.default_language_id');

        $q = trim((string) $request->input('q'));
        $syncStatus = (string) $request->input('sync_status', 'all');
        $catalogStatus = (string) $request->input('catalog_status', 'all');

        $tabMap = ['live' => 'NORMAL', 'unlisted' => 'UNLIST', 'violation' => 'BANNED', 'reviewing' => 'REVIEWING'];
        $shopeeTab = (string) $request->get('shopee_tab', 'all');
        if (!array_key_exists($shopeeTab, $tabMap)) {
            $shopeeTab = 'all';
        }
        $memberIds = $group->groupProducts()->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        $liveCounts = null;
        $liveCheckedAt = null;
        if ($store && $auth['complete']) {
            $liveCounts = ShopeeListingLink::mirrorCounts($tabMap, $memberIds);
            $liveCheckedAt = ShopeeListingLink::query()->whereIn('product_id', $memberIds ?: [0])->max('live_checked_at');
            $liveCheckedAt = $liveCheckedAt ? \Illuminate\Support\Carbon::parse($liveCheckedAt) : null;
        } else {
            $shopeeTab = 'all';
        }

        $pivotTable = 'ch_shopee_product_group_products';

        $query = DB::table('products as p')
            ->join('product_descriptions as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->join($pivotTable . ' as gp', function ($j) use ($group) {
                $j->on('p.product_id', '=', 'gp.product_id')
                    ->where('gp.shopee_product_group_id', '=', $group->id);
            })
            ->leftJoin('brands as m', 'p.brand_id', '=', 'm.brand_id')
            ->select(
                'p.product_id', 'pd.name', 'p.sku', 'p.price',
                'p.quantity', 'p.image', 'p.status', 'p.date_modified',
                'm.name as brand_name',
                'gp.shopee_item_id', 'gp.sync_status', 'gp.last_pushed_at', 'gp.last_confirmed_at', 'gp.push_error'
            );

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%")
                    ->orWhereIn('p.product_id', ShopeeListing::query()
                        ->forStore(ShopeeStore::defaultStore())
                        ->where('item_name', 'like', "%{$q}%")
                        ->select('product_id'));
            });
        }

        if ($catalogStatus === 'enabled') {
            $query->where('p.status', 1);
        } elseif ($catalogStatus === 'disabled') {
            $query->where('p.status', 0);
        }

        $erroredIds = $this->listingStates()->erroredProductIds();
        $linkedProductIds = DB::table('ch_shopee_listing_links')
            ->when(app()->bound('shopee.route-store'), fn ($q) => $q->where('store_id', app('shopee.route-store')->id))
            ->whereNotNull('shopee_item_id')
            ->select('product_id');
        if ($syncStatus === 'pushed') {
            $query->whereIn('p.product_id', $linkedProductIds);
        } elseif ($syncStatus === 'pending') {
            $query->whereNotIn('p.product_id', $linkedProductIds);
        } elseif ($syncStatus === 'error') {
            $query->whereIn('p.product_id', $erroredIds ?: [0]);
        } elseif ($syncStatus === 'unlinked') {
            $query->where('gp.sync_status', 'unlinked');
        }

        $catalogTotal = (clone $query)->count();

        if ($shopeeTab !== 'all' && $liveCounts !== null) {
            $tabProductIds = ShopeeListingLink::query()
                ->whereIn('product_id', $memberIds ?: [0])
                ->where('live_status', $tabMap[$shopeeTab])
                ->pluck('product_id')->map(fn ($v) => (int) $v)->all();
            $query->whereIn('p.product_id', $tabProductIds ?: [0]);
        }

        $storeId = \App\Channels\Listings\ListingStore::id('shopee');
        $sortMenu = ['added' => 'gp.id', 'pushed' => 'gp.last_pushed_at'] + \Extensions\Shopee\Services\ShopeeListingSort::columns($storeId);
        $order = \App\Channels\Listings\ListingSort::chosen($request, 'shopee.group.' . $group->id);
        \Extensions\Shopee\Services\ShopeeListingSort::join($query, $storeId);
        \App\Channels\Listings\ListingSort::apply($query, $order, $sortMenu);

        $products = $query->paginate(50)->appends($request->except('page'));

        $pivotProductIds = $products->pluck('product_id')->toArray();

        $pivotMap = ShopeeProductGroupProduct::query()
            ->where('shopee_product_group_id', $group->id)
            ->whereIn('product_id', $pivotProductIds)
            ->get()
            ->keyBy('product_id');

        $variationRowsByProductId = \App\Support\VariationRows::forListing($pivotProductIds, 'shopee', (int) (ShopeeStore::defaultStore()?->id ?? 0));

        $manualIds = $group->groupProducts()->pluck('product_id')->toArray();

        $failedCount = count(array_intersect($erroredIds, array_map('intval', $memberIds)));

        $displayIds = $products->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $linksByProductId = ShopeeListingLink::query()
            ->whereIn('product_id', $displayIds)
            ->get()
            ->groupBy(fn ($l) => (int) $l->product_id);
        $listingsByProductId = ShopeeListing::query()
            ->forStore(ShopeeStore::defaultStore())
            ->whereIn('product_id', $displayIds)
            ->get()
            ->keyBy('product_id');
        $rowTitles = \App\Channels\Listings\ListingContent::rowTitles($products, $listingsByProductId);
        $withVariations = ShopeeListing::withVariations($displayIds);
        $rowPrices = $products->mapWithKeys(fn ($p) => [(int) $p->product_id => ($listingsByProductId->get((int) $p->product_id) ?? new ShopeeListing())
            ->startingPrice((float) $p->price, isset($withVariations[(int) $p->product_id]))])->all();

        $listingStates = app(\Extensions\Shopee\Services\Shopee\ShopeeListingStates::class)
            ->forProducts($displayIds);

        return view('ext-shopee::product-groups.products', [
            'listingStates' => $listingStates,
            'order' => $order,
            'orderVariations' => \App\Channels\Listings\ListingSort::options(array_keys($sortMenu)),
            'rowErrors' => app(\Extensions\Shopee\Services\Shopee\ShopeeListingStates::class)->errors($displayIds),
            'group' => $group,
            'failedCount' => $failedCount,
            'products' => $products,
            'pivotMap' => $pivotMap,
            'variationRowsByProductId' => $variationRowsByProductId,
            'linksByProductId' => $linksByProductId,
            'listingsByProductId' => $listingsByProductId,
            'rowTitles' => $rowTitles,
            'rowPrices' => $rowPrices,
            'manualIds' => $manualIds,
            'q' => $q,
            'syncStatus' => $syncStatus,
            'catalogStatus' => $catalogStatus,
            'shopeeTab' => $shopeeTab,
            'liveCounts' => $liveCounts,
            'liveCheckedAt' => $liveCheckedAt,
            'catalogTotal' => $catalogTotal,
        ]);
    }

    private function buildTierVariation(int $productId, int $langId, float $markupPct, float $markupFixed, float $baseProductPrice): array
    {
        if ($productId <= 0) {
            return ['tier_variation' => [], 'models' => []];
        }

        $combos = DB::table('product_variations')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->get(['id', 'sku', 'quantity', 'absolute_price']);
        $hidden = \App\Channels\Listings\ListingVariations::hidden('shopee', (int) (\Extensions\Shopee\Models\ShopeeStore::defaultStore()?->id ?? 0), [$productId]);
        $combos = $combos->filter(fn ($c) => \App\Channels\Listings\ListingVariations::allows($hidden, $productId, $c->sku))->values();
        if ($combos->isEmpty()) {
            return ['tier_variation' => [], 'models' => []];
        }

        $values = DB::table('product_variation_parts as pocv')
            ->join('product_variation_values as pov', 'pocv.product_variation_value_id', '=', 'pov.product_variation_value_id')
            ->join('variation_type_descriptions as od', function ($j) use ($langId) {
                $j->on('pov.variation_type_id', '=', 'od.variation_type_id')->where('od.language_id', '=', $langId);
            })
            ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')->where('ovd.language_id', '=', $langId);
            })
            ->whereIn('pocv.product_variation_id', $combos->pluck('id')->all())
            ->select(
                'pocv.product_variation_id',
                'pov.variation_type_id',
                'od.name as variation_type_name',
                'ovd.name as value_name'
            )
            ->orderBy('pocv.product_variation_id')
            ->orderBy('pov.variation_type_id')
            ->get();
        if ($values->isEmpty()) {
            return ['tier_variation' => [], 'models' => []];
        }

        $orderedVariationTypeIds = [];
        $variationTypeNameById = [];
        $valuesByVariationType = [];
        foreach ($values as $v) {
            $oid = (int) $v->variation_type_id;
            if (!in_array($oid, $orderedVariationTypeIds, true)) {
                $orderedVariationTypeIds[] = $oid;
                $variationTypeNameById[$oid] = (string) $v->variation_type_name;
                $valuesByVariationType[$oid] = [];
            }
            $name = (string) $v->value_name;
            if (!in_array($name, $valuesByVariationType[$oid], true)) {
                $valuesByVariationType[$oid][] = $name;
            }
        }

        $tierVariation = [];
        foreach ($orderedVariationTypeIds as $oid) {
            $tierVariation[] = [
                'name' => $variationTypeNameById[$oid],
                'option_list' => array_map(fn ($v) => ['option' => $v], $valuesByVariationType[$oid]),
            ];
        }

        $valuesByCombo = [];
        foreach ($values as $v) {
            $valuesByCombo[(int) $v->product_variation_id][(int) $v->variation_type_id] = (string) $v->value_name;
        }

        $models = [];
        foreach ($combos as $c) {
            $cid = (int) $c->id;
            $tierIndex = [];
            foreach ($orderedVariationTypeIds as $oid) {
                $valueName = $valuesByCombo[$cid][$oid] ?? null;
                if ($valueName === null) {
                    continue 2;
                }
                $idx = array_search($valueName, $valuesByVariationType[$oid], true);
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

    public function normalizedAttributesFor(int $categoryId): array
    {
        $template = $categoryId > 0
            ? ShopeeCategoryTemplate::query()->where('category_id', $categoryId)->first()
            : null;

        return [
            'template' => $template,
            'attributes' => $template ? \Extensions\Shopee\Services\Shopee\ShopeeCategoryAttributes::extract($template->attributes) : [],
        ];
    }

    public function ensureTemplate(int $categoryId, ShopeeClient $client, ?array $auth = null): void
    {
        if ($categoryId <= 0 || ShopeeCategoryTemplate::query()->where('category_id', $categoryId)->exists()) {
            return;
        }

        if ($auth === null) {
            $store = ShopeeStore::defaultStore()?->decrypted();
            $auth = ShopeeStore::activeAuth($store);
            if (!$store) {
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

    private function brandsCompleteKey(int $categoryId): string
    {
        return 'shopee.brands.complete.' . $categoryId;
    }

    private function warmBrands(int $categoryId, int $offset, ShopeeClient $client)
    {
        $read = fn () => ShopeeBrand::query()->where('category_id', $categoryId)->count();

        if (\Illuminate\Support\Facades\Cache::get($this->brandsCompleteKey($categoryId))) {
            return response()->json(['ok' => true, 'complete' => true, 'read' => $read()]);
        }

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
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

        if ($request->boolean('warm')) {
            return $this->warmBrands($categoryId, max(0, (int) $request->query('offset', 0)), $client);
        }

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

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
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
        $maxPages = 50;

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
                    'status'      => 1,
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

    public function productSearch(Request $request, int $id)
    {
        $group = ShopeeProductGroup::query()->findOrFail($id);
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->query('q', ''));
        $showAll = $request->boolean('all');
        $limit = 25;

        $members = ShopeeProductGroupProduct::query()
            ->where('shopee_product_group_id', $group->id)
            ->select('product_id');

        $query = DB::table('products as p')
            ->leftJoin('product_descriptions as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->leftJoinSub(ShopeeStoreProducts::query(), 'os', 'os.product_id', '=', 'p.product_id')
            ->select('p.product_id', 'pd.name', 'p.image', 'p.sku', 'p.quantity', 'p.price', 'os.product_id as on_store_pid')
            ->where('p.status', 1);

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
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

        $owners = $ids === [] ? collect() : DB::table('ch_shopee_product_group_products as pv')
            ->join('ch_shopee_product_groups as g', 'g.id', '=', 'pv.shopee_product_group_id')
            ->whereIn('pv.product_id', $ids)
            ->where('g.id', '!=', $group->id)
            ->when(app()->bound('shopee.route-store'), fn ($w) => $w->where('g.store_id', app('shopee.route-store')->id))
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
                'thumb' => $image !== '' ? \App\Services\Media\ImageCache::url($image) : null,
                'on_store' => $inGroup->has($pid),
                'elsewhere' => $owners->get($pid)?->name,
                'listed' => ! is_null($r->on_store_pid),
            ];
        })->values();

        return response()->json(['total' => $total, 'shown' => $items->count(), 'limit' => $limit, 'items' => $items]);
    }

    public function addProduct(Request $request, int $id, int $productId)
    {
        $group = ShopeeProductGroup::query()->findOrFail($id);
        $inCatalog = DB::table('products')
            ->where('product_id', $productId)->where('status', 1)->exists();
        abort_unless($inCatalog, 404);

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
            $claim = \App\Channels\OneGroupRule::claim(
                'ch_shopee_product_group_products', 'shopee_product_group_id', 'ch_shopee_product_groups', 'store_id',
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
                return response()->json(['ok' => false, 'product_id' => $productId, 'message' => trim(\App\Channels\OneGroupRule::heldClause($held))], 409);
            }

            return response()->json(['ok' => true, 'product_id' => $productId, 'added' => $added, 'moved_from' => $movedFrom, 'listed' => $listedNow]);
        }

        $status = $already
            ? "Product #{$productId} is already in this product group."
            : ($added
                ? "Product #{$productId} added to the product group." . ($movedFrom !== null ? " Moved here from '{$movedFrom}'." : '') . ($listedNow ? ' It is on this store now too.' : '')
                : trim(\App\Channels\OneGroupRule::heldClause($held)));

        return $this->productsRedirect($group->id)->with($added ? 'status' : 'warning', $status);
    }

    public function unlinkProduct(int $groupId, int $productId)
    {
        $group = ShopeeProductGroup::query()->findOrFail($groupId);

        $removed = ShopeeListingLink::unlinkProduct($productId, (int) app('shopee.route-store')->id);

        return $this->productsRedirect($group->id)->with(
            'status',
            $removed > 0
                ? "Product unlinked from Shopee ({$removed} link(s) removed, cache cleared). You can push it again."
                : 'Product was not linked to Shopee. Nothing to remove.'
        );
    }

    private function refreshShopeeProductLinks(
        object $pivot, int $productId, int $itemId, array $skus,
        \Extensions\Shopee\Services\Shopee\ShopeeClient $client, string $mode, int $pid, string $pkey, string $token, int $shopId
    ) {
        $auth = ['mode' => $mode, 'partner_id' => $pid, 'partner_key' => $pkey, 'access_token' => $token, 'shop_id' => $shopId];
        $r = app(\Extensions\Shopee\Services\Shopee\ShopeeModelLinkRepair::class)
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

    public function removeProduct(int $groupId, int $productId)
    {
        $group = ShopeeProductGroup::query()->findOrFail($groupId);
        $group->groupProducts()->where('product_id', $productId)->delete();
        $this->listingStates()->clearErrors([$productId]);

        return $this->productsRedirect($group->id)
            ->with('status', 'Product removed from the product group.');
    }

    public function moveProducts(Request $request)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])), fn ($v) => $v > 0)));
        $back = \App\Support\BackTo::safe($request->input('_return'), route('ext.shopee.products.index'));
        if ($ids === []) {
            return redirect($back)->with('warning', 'No products selected.');
        }
        $target = (string) $request->input('group', '');
        if ($target === 'none') {
            $removed = ShopeeProductGroupProduct::query()
                ->whereIn('shopee_product_group_id', ShopeeProductGroup::query()->select('id'))
                ->whereIn('product_id', $ids)->delete();

            return redirect($back)->with('status', $removed . ' ' . \Illuminate\Support\Str::plural('product', $removed) . ' ungrouped.');
        }
        $group = ShopeeProductGroup::query()->findOrFail((int) $target);

        $already = $group->groupProducts()->whereIn('product_id', $ids)->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $wanted = array_values(array_diff($ids, $already));
        $claim = \App\Channels\OneGroupRule::claim('ch_shopee_product_group_products', 'shopee_product_group_id', 'ch_shopee_product_groups', 'store_id', $group->id, $wanted, $wanted);
        $moved = 0;
        foreach (array_merge($claim['free'], array_keys($claim['moved'])) as $pid) {
            ShopeeProductGroupProduct::create(['shopee_product_group_id' => $group->id, 'product_id' => (int) $pid]);
            $moved++;
        }
        if ($moved === 0) {
            return redirect($back)->with('warning', 'Those products are already in ' . $group->name . '.');
        }

        return redirect($back)->with('status', $moved . ' ' . \Illuminate\Support\Str::plural('product', $moved) . ' moved to ' . $group->name . '.');
    }

    public function massRemove(Request $request, int $id)
    {
        $group = ShopeeProductGroup::query()->findOrFail($id);
        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));

        if (empty($ids)) {
            return $this->productsRedirect($group->id)->with('error', 'No products selected.');
        }

        $removed = $group->groupProducts()->whereIn('product_id', $ids)->delete();
        $this->listingStates()->clearErrors($ids);

        $summary = "{$removed} product(s) removed from the product group.";
        if ($removed < count($ids)) {
            $summary .= ' ' . (count($ids) - $removed) . ' were not in the product group.';
        }

        return $this->productsRedirect($group->id)
            ->with($removed > 0 ? 'status' : 'warning', $summary);
    }

    public function checkAgainstShopee(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);

        if (! $store || ! $auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';

            return $this->productsRedirect($id)->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $productIds = $this->parseIds($request);

        if (empty($productIds)) {
            $productIds = DB::table('ch_shopee_product_group_products')
                ->where('shopee_product_group_id', $id)
                ->pluck('product_id')->map('intval')->all();
        }

        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'This product group has no products to check.');
        }

        $r = $this->linkCheck->run($client, $auth, $productIds);

        return $this->productsRedirect($id)->with($r['tone'], $r['summary']);
    }

    public function orphans(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);

        if (! $store || ! $auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';

            return $this->productsRedirect($id)->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $index = $this->linkCheck->skuIndex($client, $auth);

        $known = ShopeeListingLink::query()
            ->whereNotNull('shopee_item_id')
            ->pluck('shopee_item_id')
            ->map('intval')
            ->flip();

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

    public function send(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);

        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $r = $this->sendProducts($group, $productIds, $client);

        return $this->productsRedirect($id)->with($r['tone'], $r['summary']);
    }

    public function sendProducts(ShopeeProductGroup $group, array $productIds, ShopeeClient $client): array
    {
        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);

        if (! $store || ! $auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';

            return ['tone' => 'error', 'summary' => "Missing Shopee {$modeLabel} settings.", 'failed' => 0, 'stop' => true];
        }
        $langId = (int) config('catalog.default_language_id');

        $verdicts = [];
        $toCreate = [];
        $toUpdate = [];
        $blocked = [];

        foreach ($productIds as $productId) {
            $verdict = $this->linkCheck->reconcile($client, $auth, (int) $productId);
            $verdicts[$verdict['state']] = ($verdicts[$verdict['state']] ?? 0) + 1;

            if ($verdict['state'] === 'taken' || $verdict['state'] === 'unreachable') {
                $blocked[] = "#{$productId}: {$verdict['message']}";
                $this->writeProductStatus((int) $productId, [
                    'sync_status' => 'error',
                    'push_error' => Str::limit((string) $verdict['message'], 480),
                    'last_pushed_at' => now(),
                ]);
                continue;
            }

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
                $created = $this->createOnShopee($group, $toCreate, $client, $auth, $langId);
            }
        }

        if ($toUpdate) {
            $updated = $this->updateOnShopee($group, $toUpdate, $client, $auth, $langId);
        }

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

        $tone = ($warnings && $errCount === 0) ? 'warning' : $this->batchTone($okCount, $errCount);

        return ['tone' => $tone, 'summary' => $summary, 'failed' => min(count($productIds), $errCount), 'stop' => false];
    }

    private function createOnShopee(ShopeeProductGroup $group, array $productIds, ShopeeClient $client, array $auth, int $langId): array
    {
        $logisticIds = array_map('intval', $group->logistic_ids ?? []);

        $okCount = 0;
        $skipCount = 0;
        $errCount = 0;
        $errors = [];

        $groupAnswers = ShopeeProductGroupAttribute::query()
            ->where('shopee_product_group_id', (int) $group->id)
            ->pluck('value', 'attribute_key')->toArray();
        $this->ensureTemplate((int) $group->shopee_category_id, $client);
        $readinessAll = app(\Extensions\Shopee\Services\Shopee\ShopeeListingReadiness::class)->forProducts($productIds, [
            'category_id' => (int) $group->shopee_category_id ?: null,
            'logistic_ids' => $logisticIds,
            'markup_percent' => $group->markup_percent !== null ? (float) $group->markup_percent : null,
            'markup_fixed' => $group->markup_fixed !== null ? (float) $group->markup_fixed : null,
            'attributes' => $groupAnswers,
        ]);
        $inherit = app(\Extensions\Shopee\Services\Shopee\ShopeeInheritedSettings::class);
        $inheritedAll = $inherit->forProducts($productIds);

        foreach ($productIds as $productId) {
            if (ShopeeListingLink::query()->where('product_id', $productId)->exists()) {
                $skipCount++;
                continue;
            }

            $readiness = $readinessAll[$productId] ?? ['ready' => false, 'missing' => []];
            if (!$readiness['ready']) {
                $refusal = \App\Channels\Listings\CatalogGaps::refusal($readiness);
                $errors[] = "#{$productId}: {$refusal}";
                $this->writeProductStatus($productId, ['sync_status' => 'error', 'push_error' => $refusal]);
                $errCount++;
                continue;
            }

            $product = DB::table('products as p')
                ->leftJoin('product_descriptions as pd', function ($j) use ($langId) {
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

            $ownListing = ShopeeListing::query()->where('product_id', (int) $productId)->first();

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
            $content = \App\Channels\Listings\ListingContent::of(
                $ownListing,
                html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                \App\Support\Catalog\DescriptionText::of((string) ($product->description ?? '')),
                'shopee',
                (int) (\Extensions\Shopee\Models\ShopeeStore::defaultStore()?->id ?? 0)
            );
            $itemName = $content['title'];
            $description = \App\Support\Catalog\DescriptionText::of($content['description']);

            $create = app(\Extensions\Shopee\Services\Shopee\ShopeeItemCreate::class);
            $imagePaths = $create->catalogImagePaths($product, $ownListing);

            $imageIds = $create->uploadImages($client, $auth, $imagePaths)['ids'];

            if (empty($imageIds)) {
                $errors[] = "#{$productId}: Image upload failed";
                $this->writeProductStatus($productId, ['sync_status' => 'error', 'push_error' => 'Image upload failed']);
                $errCount++;
                continue;
            }

            $logisticInfo = [];
            foreach ($effLogistics as $lid) {
                $logisticInfo[] = ['logistic_id' => (int) $lid, 'enabled' => true];
            }

            $basePrice = (float) $product->price;
            $markupPct = (float) ($eff->markup_percent ?? 0);
            $markupFixed = (float) ($eff->markup_fixed ?? 0);
            $sellingPrice = $eff->itemPriceFor($basePrice, ShopeeListing::hasVariations((int) $productId));

            $tierData = $this->buildTierVariation((int) $productId, $langId, $markupPct, $markupFixed, $basePrice);
            $hasTierVariation = !empty($tierData['tier_variation']) && !empty($tierData['models']);

            $payload = [
                'original_price' => $sellingPrice,
                'description' => mb_substr($description, 0, 5000),
                'item_name' => mb_substr($itemName, 0, 255),
                'seller_stock' => [['stock' => max(0, (int) $product->quantity)]],
                'item_sku' => (string) ($product->sku ?? ''),
                'weight' => \App\Channels\Listings\ParcelPrecision::of($eff->weight, $product->weight, \App\Channels\Listings\ParcelPrecision::SHOPEE['weight']),
                'dimension' => [
                    'package_length' => \App\Channels\Listings\ParcelPrecision::of($eff->package_length, $product->length, \App\Channels\Listings\ParcelPrecision::SHOPEE['dimension']),
                    'package_width' => \App\Channels\Listings\ParcelPrecision::of($eff->package_width, $product->width, \App\Channels\Listings\ParcelPrecision::SHOPEE['dimension']),
                    'package_height' => \App\Channels\Listings\ParcelPrecision::of($eff->package_height, $product->height, \App\Channels\Listings\ParcelPrecision::SHOPEE['dimension']),
                ],
                'category_id' => $effCategory,
                'image' => ['image_id_list' => $imageIds],
                'logistic_info' => $logisticInfo,
                'brand' => $this->buildBrandPayload($effCategory, $effBrand),
            ];

            // Shopee ignores tier_variation in add_item: add_item, then init_tier_variation, then add_model only to append.

            $attributeList = $this->attributeListFromSaved((array) ($eff->attribute_values ?? []), $effCategory);
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
                ShopeeListingLink::create([
                    'product_id' => $productId,
                    'shopee_item_id' => (int) $itemId,
                    'shopee_model_id' => null,
                    'sku' => $product->sku ?? '',
                ]);

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

                $variations = app(\Extensions\Shopee\Services\Shopee\ShopeeVariationPush::class)->pushForProduct(
                    $client, $auth, (int) $itemId, $productId,
                    fn (float $core) => $eff->priceFor($core),
                    null
                );
                if ($variations !== null && !$variations['ok']) {
                    $undone = app(\Extensions\Shopee\Services\Shopee\ShopeeVariationPush::class)
                        ->undoCreate($client, $auth, $productId, (int) $itemId);
                    $reason = $undone
                        ? 'not pushed - its variations were refused (' . $variations['message'] . '); the item was removed from Shopee again'
                        : 'its variations were refused (' . $variations['message'] . ') and Shopee also refused to remove the half-created item ' . $itemId . ' - delete it in Seller Centre';
                    $errors[] = "#{$productId}: {$reason}";
                    $this->writeProductStatus($productId, ['shopee_item_id' => null, 'sync_status' => 'error', 'push_error' => $reason]);
                    $errCount++;
                    continue;
                }

                $this->writeProductStatus($productId, [
                        'shopee_item_id' => (int) $itemId,
                        'sync_status' => 'pushed',
                        'push_error' => null,
                        'last_pushed_at' => now(),
                    ]);

                $catalogSkus = DB::table('product_variation_values')
                    ->where('product_id', $productId)
                    ->whereNotNull('sku')
                    ->where('sku', '!=', '')
                    ->pluck('sku')
                    ->map(fn ($s) => strtolower(trim((string) $s)))
                    ->all();
                $comboSkus = DB::table('product_variations')
                    ->where('product_id', $productId)
                    ->whereNotNull('sku')
                    ->where('sku', '!=', '')
                    ->pluck('sku')
                    ->map(fn ($s) => strtolower(trim((string) $s)))
                    ->all();
                $allSkus = array_values(array_unique(array_merge($catalogSkus, $comboSkus)));
                $catalogHasVariations = !empty($allSkus);

                if ($catalogHasVariations) {
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
                        \Illuminate\Support\Facades\Log::warning('Shopee model link refresh failed after push', [
                            'product_id' => $productId,
                            'item_id' => $itemId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $okCount++;
            } else {
                $msg = \App\Support\ChannelAnswer::plain('Shopee', ['ok' => false, 'body' => is_array($body) ? $body : []]);
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

        app(\Extensions\Shopee\Services\Shopee\ShopeeLiveListing::class)->confirm($auth, $confirmItemIds ?? []);

        return ['ok' => $okCount, 'skip' => $skipCount, 'err' => $errCount, 'errors' => $errors];
    }

    private function updateOnShopee(ShopeeProductGroup $group, array $productIds, ShopeeClient $client, array $auth, int $langId): array
    {
        $okCount = 0;
        $skipCount = 0;
        $errCount = 0;
        $errors = [];
        $warnings = [];

        foreach ($productIds as $productId) {
            $link = ShopeeListingLink::query()->where('product_id', $productId)->first();
            if (!$link || !$link->shopee_item_id) {
                $skipCount++;
                continue;
            }

            $itemId = (int) $link->shopee_item_id;

            $product = DB::table('products as p')
                ->leftJoin('product_descriptions as pd', function ($j) use ($langId) {
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

            $ownListing = ShopeeListing::query()->where('product_id', (int) $productId)->first();
            $content = \App\Channels\Listings\ListingContent::of(
                $ownListing,
                html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                \App\Support\Catalog\DescriptionText::of((string) ($product->description ?? '')),
                'shopee',
                (int) (\Extensions\Shopee\Models\ShopeeStore::defaultStore()?->id ?? 0)
            );
            $itemName = $content['title'];
            $description = \App\Support\Catalog\DescriptionText::of($content['description']);
            if (trim($itemName) === '' || trim($description) === '' || (float) ($ownListing?->weight ?? $product->weight) <= 0) {
                $errors[] = "#{$productId}: Missing name/description/weight";
                $this->writeProductStatus($productId, ['sync_status' => 'error', 'push_error' => 'Missing name/description/weight']);
                $errCount++;
                continue;
            }

            $create = app(\Extensions\Shopee\Services\Shopee\ShopeeItemCreate::class);
            $imagePaths = $create->catalogImagePaths($product, $ownListing);

            $imageIds = $create->uploadImages($client, $auth, $imagePaths)['ids'];

            $payload = [
                'item_id' => $itemId,
                'description' => mb_substr($description, 0, 5000),
                'item_name' => mb_substr($itemName, 0, 255),
                'item_sku' => (string) ($product->sku ?? ''),
                'weight' => \App\Channels\Listings\ParcelPrecision::of($ownListing?->weight, $product->weight, \App\Channels\Listings\ParcelPrecision::SHOPEE['weight']),
                'dimension' => [
                    'package_length' => \App\Channels\Listings\ParcelPrecision::of($ownListing?->package_length, $product->length, \App\Channels\Listings\ParcelPrecision::SHOPEE['dimension']),
                    'package_width' => \App\Channels\Listings\ParcelPrecision::of($ownListing?->package_width, $product->width, \App\Channels\Listings\ParcelPrecision::SHOPEE['dimension']),
                    'package_height' => \App\Channels\Listings\ParcelPrecision::of($ownListing?->package_height, $product->height, \App\Channels\Listings\ParcelPrecision::SHOPEE['dimension']),
                ],
            ];

            if (!empty($imageIds)) {
                $payload['image'] = ['image_id_list' => $imageIds];
            }

            $described = (new \Extensions\Shopee\Services\Shopee\ShopeeDescription($client))->write(
                $payload, $auth, (int) $itemId,
                \App\Channels\Listings\ListingContent::of(
                    $ownListing,
                    html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    (string) ($product->description ?? ''),
                    'shopee',
                    (int) (\Extensions\Shopee\Models\ShopeeStore::defaultStore()?->id ?? 0)
                )['description']
            );
            $payload = $described['payload'];
            if ($described['note'] !== '') {
                $warnings[] = "#{$productId}: {$described['note']}";
            }

            $switches = app(\Extensions\Shopee\Services\Shopee\ShopeeVariationSwitches::class);
            $read = $switches->readFor($client, $auth, $itemId, (int) $productId);

            $rename = app(\Extensions\Shopee\Services\Shopee\ShopeeVariationRename::class);
            $plan = $rename->plan($client, $auth, $itemId, (int) $productId, $read);
            $renamed = $rename->apply($client, $auth, $plan);
            if (!$renamed['ok']) {
                $warnings[] = "#{$productId}: {$renamed['message']}";
            }

            $inherit = app(\Extensions\Shopee\Services\Shopee\ShopeeInheritedSettings::class);
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
                \Extensions\Shopee\Services\Shopee\ShopeeVariationSwitches::renamed($read, $plan, $renamed),
                fn (float $core) => (float) $eff->priceFor($core),
                $create->tierImageUploader($client, $auth)
            );
            $switchNote = \Extensions\Shopee\Services\Shopee\ShopeeVariationSwitches::note($followed);
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
                $links = ShopeeListingLink::query()->where('product_id', $productId)->get();
                $synced = [
                    $this->pushPriceForLinks($client, $auth, $links, $group),
                    $this->pushStockForLinks($client, $auth, $links),
                ];
                app(\Extensions\Shopee\Services\Shopee\ShopeeLiveListing::class)->confirm($auth, $links->pluck('shopee_item_id')->all());

                $this->writeProductStatus($productId, [
                        'sync_status' => 'pushed',
                        'push_error' => null,
                        'last_pushed_at' => now(),
                    ]);
                $failed = false;
                foreach ($synced as $sync) {
                    $outcome = $sync['outcomes'][$productId] ?? null;
                    if ($outcome !== null && empty($outcome['ok'])) {
                        $this->listingStates()->recordOutcome($productId, (string) ($outcome['error'] ?? ''));
                        $failed = true;
                        break;
                    }
                }
                if ($followed['failure'] !== null) {
                    $this->listingStates()->recordOutcome($productId, $followed['failure']);
                    $failed = true;
                }
                if (! $failed) {
                    $this->listingStates()->recordOutcome($productId, null);
                }

                ActivityLogger::log('updated', 'Shopee Product', $productId, 'Updated item ' . $itemId . ' on Shopee via product group');
                $okCount++;
            } else {
                $msg = \App\Support\ChannelAnswer::plain('Shopee', ['ok' => false, 'body' => is_array($body) ? $body : []]);
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

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
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
        $langId = (int) config('catalog.default_language_id');

        $r = $this->createOnShopee($group, $productIds, $client, $auth, $langId);
        $okCount = $r['ok']; $skipCount = $r['skip']; $errCount = $r['err']; $errors = $r['errors'];

        $summary = "Push: {$okCount} pushed";
        if ($skipCount > 0) $summary .= ", {$skipCount} skipped (already linked or disabled)";
        if ($errCount > 0) $summary .= ", {$errCount} failed";
        if (!empty($errors)) $summary .= '. Errors: ' . implode('; ', array_slice($errors, 0, 5));

        return $this->productsRedirect($id)->with($okCount > 0 ? 'status' : 'error', $summary);
    }

    public function updateProduct(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->productsRedirect($id)->with('error', "Missing Shopee {$modeLabel} settings.");
        }
        $langId = (int) config('catalog.default_language_id');

        $r = $this->updateOnShopee($group, $productIds, $client, $auth, $langId);
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

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->productsRedirect($id)->with('error', "Missing Shopee {$modeLabel} settings.");
        }
        $links = ShopeeListingLink::query()->whereIn('product_id', $productIds)->get();
        $skip = count($productIds) - $links->pluck('product_id')->unique()->count();

        $results = $this->pushPriceForLinks($client, $auth, $links, $group);
        $this->recordSyncOutcomes($group->id, $results['outcomes'] ?? []);

        return $this->productsRedirect($id)->with(
            $this->batchTone($results['ok'], $results['err']),
            "Sync Price: {$results['ok']} success, {$results['err']} error, {$skip} skipped (not linked)."
                . \Extensions\Shopee\Services\Shopee\ShopeeStockPricePush::ledgerClause($this->lastPushLedger)
        );
    }

    public function pushStock(Request $request, int $id, ShopeeClient $client)
    {
        $group = ShopeeProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->productsRedirect($id)->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $enabledIds = DB::table('products')
            ->whereIn('product_id', $productIds)
            ->where('status', 1)
            ->pluck('product_id')
            ->toArray();
        $disabledCount = count($productIds) - count($enabledIds);

        $links = ShopeeListingLink::query()->whereIn('product_id', $enabledIds)->get();
        $skip = count($enabledIds) - $links->pluck('product_id')->unique()->count();

        $results = $this->pushStockForLinks($client, $auth, $links);
        $this->recordSyncOutcomes($group->id, $results['outcomes'] ?? []);

        $msg = "Sync Qty: {$results['ok']} success, {$results['err']} error, {$skip} skipped (not linked)";
        if ($disabledCount > 0) {
            $msg .= ", {$disabledCount} skipped (disabled)";
        }
        $ledger = \Extensions\Shopee\Services\Shopee\ShopeeStockPricePush::ledgerClause($this->lastPushLedger);

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

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return $this->productsRedirect($id)->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $okCount = 0;
        $errCount = 0;
        $errors = [];
        $path = '/api/v2/product/delete_item';

        foreach ($productIds as $productId) {
            $link = ShopeeListingLink::query()->where('product_id', $productId)->first();
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
                ShopeeListingLink::query()->where('product_id', $productId)->delete();

                $this->writeProductStatus($productId, ['shopee_item_id' => null, 'sync_status' => 'pending']);
                $this->listingStates()->clearErrors([$productId]);

                ActivityLogger::log('deleted', 'Shopee Product', $productId, 'Deleted item ' . $itemId . ' from Shopee via product group');
                $okCount++;
            } else {
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
                $summary .= ' (and ' . (count($errors) - 5) . ' more, shown against their rows)';
            }
        }

        return $this->productsRedirect($id)->with($this->batchTone($okCount, $errCount), $summary);
    }

    private function writeProductStatus(int $productId, array $attributes): void
    {
        $storeId = (int) (ShopeeStore::defaultStore()?->id ?? 0);
        \App\Support\ChannelProductStatus::write(
            'ch_shopee_product_group_products',
            'shopee_product_group_id',
            $storeId > 0
                ? ShopeeProductGroup::query()->withoutGlobalScope('shopeeStore')->where('store_id', $storeId)
                    ->pluck('id')->map(fn ($v) => (int) $v)->all()
                : null,
            $productId,
            $attributes
        );

        if (($attributes['sync_status'] ?? null) === 'error') {
            $this->listingStates()->recordOutcome($productId, (string) ($attributes['push_error'] ?? ''));
        } elseif (($attributes['sync_status'] ?? null) === 'pushed') {
            $this->listingStates()->recordOutcome($productId, null);
        }
    }

    private function listingStates(): \Extensions\Shopee\Services\Shopee\ShopeeListingStates
    {
        return app(\Extensions\Shopee\Services\Shopee\ShopeeListingStates::class);
    }

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

    private function batchTone(int $ok, int $err): string
    {
        return \App\Channels\Push\PushLedger::batchTone($ok, $err);
    }

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

    private function pushStockForLinks(ShopeeClient $client, array $auth, $links): array
    {
        $results = app(\Extensions\Shopee\Services\Shopee\ShopeeStockPricePush::class)
            ->push('stock', $auth, $links);
        $this->lastPushLedger = $results;
        [$ok, $err, $outcomes] = [$results['ok'], $results['err'], $results['outcomes']];

        return ['ok' => $ok, 'err' => $err, 'outcomes' => $outcomes];
    }

    private function pushPriceForLinks(ShopeeClient $client, array $auth, $links, ShopeeProductGroup $group): array
    {
        $priceFor = \Extensions\Shopee\Commands\ShopeePushPrice::priceRule(
            collect($links)->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all()
        );

        $results = app(\Extensions\Shopee\Services\Shopee\ShopeeStockPricePush::class)
            ->push('price', $auth, $links, $priceFor);
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

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
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

    public function refreshCategories(ShopeeClient $client)
    {
        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
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
            $msg = \App\Support\ChannelAnswer::plain('Shopee', ['ok' => false, 'body' => is_array($body) ? $body : []]);
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
            DB::table('ch_shopee_categories')->delete();
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('ch_shopee_categories')->insert($chunk);
            }
        });

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

    public function fetchAttributesAjax(Request $request, ShopeeClient $client)
    {
        $categoryId = (int) $request->input('shopee_category_id', 0);
        if ($categoryId <= 0) {
            return response()->json(['ok' => false, 'html' => '']);
        }

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
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

        $attributes = \Extensions\Shopee\Services\Shopee\ShopeeCategoryAttributes::extract($template->attributes);

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
