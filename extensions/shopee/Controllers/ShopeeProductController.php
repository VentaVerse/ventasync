<?php

namespace Extensions\shopee\Controllers;

use App\Http\Controllers\Controller;

use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeCategory;
use Extensions\shopee\Models\ShopeeItemCache;
use Extensions\shopee\Models\ShopeeLogistic;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeProductGroup;
use Extensions\shopee\Models\ShopeeSetting;
use App\Services\ActivityLogger;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Extensions\shopee\Services\Shopee\ShopeeLiveListing;
use Extensions\shopee\Services\ShopeeStoreProducts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopeeProductController extends Controller
{
    use \App\Http\Controllers\Concerns\ServesCategoryTree;

    protected function categoryChannel(): string
    {
        return 'shopee';
    }
    use \App\Http\Controllers\Concerns\ComparesWithTheCatalog;

    protected function catalogChangeListing(int $productId): \Illuminate\Database\Eloquent\Model
    {
        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        abort_if($listing === null, 404);

        return $listing;
    }

    protected function catalogChangeFallback(int $productId): string
    {
        return route('ext.shopee.listings.edit', $productId);
    }

    private function readyByProduct(): array
    {
        return array_map(fn (array $a) => (bool) $a['ready'], $this->readinessRich());
    }

    private function gapsByProduct(): array
    {
        return array_map(
            fn (array $a) => array_values(array_map(fn ($g) => (string) ($g['code'] ?? ''), (array) $a['gaps'])),
            $this->readinessRich()
        );
    }

    private ?array $readinessRich = null;

    private function readinessRich(): array
    {
        if ($this->readinessRich !== null) {
            return $this->readinessRich;
        }
        $storeId = (int) (ShopeeSetting::defaultStore()?->id ?? 0);
        $scope = DB::query()->fromSub(ShopeeStoreProducts::query(), 'os')
            ->pluck('os.product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        return $this->readinessRich = \App\Integrations\Listings\ReadinessCache::resolve(
            'shopee', $storeId, $scope,
            fn (array $ids) => $this->shopeeReadinessStamps($ids, $storeId),
            fn (array $ids) => $this->shopeeReadinessCompute($ids),
        );
    }

    private function shopeeReadinessStamps(array $ids, int $storeId): array
    {
        $pfx = (string) config('catalog.prefix');
        $prod = DB::table($pfx . 'product')->whereIn('product_id', $ids)->pluck('date_modified', 'product_id');
        $listing = DB::table('shopee_listings')->whereIn('product_id', $ids)
            ->selectRaw('product_id, MAX(updated_at) as mu')->groupBy('product_id')->pluck('mu', 'product_id');
        $link = DB::table('shopee_product_links')->whereIn('product_id', $ids)
            ->selectRaw('product_id, MAX(updated_at) as mu')->groupBy('product_id')->pluck('mu', 'product_id');
        $group = DB::table('shopee_product_group_products as gp')
            ->join('shopee_product_groups as g', 'g.id', '=', 'gp.shopee_product_group_id')
            ->whereIn('gp.product_id', $ids)->where('g.shopee_setting_id', $storeId)
            ->selectRaw('gp.product_id, MAX(g.updated_at) as mu')->groupBy('gp.product_id')->pluck('mu', 'product_id');

        $out = [];
        foreach ($ids as $pid) {
            $out[(int) $pid] = md5(($prod[$pid] ?? '') . '|' . ($listing[$pid] ?? '') . '|' . ($link[$pid] ?? '') . '|' . ($group[$pid] ?? ''));
        }

        return $out;
    }

    private function shopeeReadinessCompute(array $ids): array
    {
        $readiness = app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class);
        $answers = $ids === [] ? [] : $readiness->forProducts($ids);
        $out = [];
        foreach ($ids as $pid) {
            $r = $answers[(int) $pid] ?? null;
            $gaps = $r === null
                ? [['code' => 'unknown', 'label' => 'not checked yet']]
                : array_values((array) ($r['gaps'] ?? []));
            $out[(int) $pid] = ['ready' => $gaps === [], 'gaps' => $gaps];
        }

        return $out;
    }

    public function index(Request $request, ShopeeClient $client)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->get('q', ''));
        $syncStatus = (string) $request->get('sync_status', 'all');
        $syncStatus = ['listed' => 'uploaded', 'not_listed' => 'not_uploaded'][$syncStatus] ?? $syncStatus;
        if ($syncStatus !== 'error') {
            $syncStatus = \App\Integrations\Listings\StatusMenu::stand($syncStatus);
        }
        $failedFlag = $request->boolean('failed') || $syncStatus === 'error';
        $changeFlag = $request->boolean('change');
        if ($syncStatus === 'error') {
            $syncStatus = 'all';
        }
        $manufacturerFilter = (string) $request->get('manufacturer', 'all');
        $groupFilter = \App\Integrations\Listings\StatusMenu::group($request->get('group'));
        $erpStatus = (string) $request->get('erp_status', 'all');

        if ($request->query('list') === 'add') {
            return $this->addCatalogue($request);
        }

        $tabMap = ['live' => 'NORMAL', 'unlisted' => 'UNLIST', 'violation' => 'BANNED', 'reviewing' => 'REVIEWING'];
        $shopeeTab = (string) $request->get('shopee_tab', 'all');
        if (!array_key_exists($shopeeTab, $tabMap)) {
            $shopeeTab = 'all';
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        $liveCounts = null;
        $liveTabError = null;
        $liveCheckedAt = null;
        if ($setting && $auth['complete']) {
            $liveCounts = [];
            $liveCheckedAt = ShopeeProductLink::query()->max('live_checked_at');
            $liveCheckedAt = $liveCheckedAt ? \Illuminate\Support\Carbon::parse($liveCheckedAt) : null;
        } else {
            $liveTabError = 'Missing Shopee settings.';
            $shopeeTab = 'all';
        }

        // Sort columns are allow-listed so an edited URL cannot reach any other column.
        $sortColumns = [
            'id' => 'p.product_id',
            'product' => 'pd.name',
            'quantity' => 'p.quantity',
            'price' => 'p.price',
            'product_status' => 'p.status',
            'manufacturer' => 'm.name',
        ];
        $sort = (string) $request->get('sort', 'id');
        if (!array_key_exists($sort, $sortColumns)) {
            $sort = 'id';
        }
        $dir = strtolower((string) $request->get('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $groupMapping = $this->getGroupProductMapping();

        $query = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                  ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->select(
                'p.product_id',
                'pd.name',
                'p.image',
                'p.model',
                'p.sku',
                'p.price',
                'p.quantity',
                'p.status',
                'm.name as manufacturer_name'
            );

        $groupedIds = $groupMapping['ids'] ?: [];
        $applyGroup = function ($query, string $choice) use ($groupedIds) {
            if ($choice === 'none') {
                return $groupedIds === [] ? $query : $query->whereNotIn('p.product_id', $groupedIds);
            }
            if ($choice === 'all') {
                return $query;
            }

            return $query->whereIn('p.product_id', $this->getProductIdsByGroup((int) $choice) ?: [0]);
        };
        $query->whereIn('p.product_id', ShopeeStoreProducts::query());

        if ($manufacturerFilter !== 'all') {
            $query->where('p.manufacturer_id', (int) $manufacturerFilter);
        }

        if ($erpStatus === 'enabled') {
            $query->where('p.status', 1);
        } elseif ($erpStatus === 'disabled') {
            $query->where('p.status', 0);
        } else {
            $query->where(function ($sub) {
                $sub->where('p.status', 1)
                    ->orWhereIn('p.product_id', \Extensions\shopee\Models\ShopeeProductLink::query()
                        ->whereNotNull('shopee_item_id')->select('product_id'));
            });
        }

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.model', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%')
                    ->orWhereIn('p.product_id', \Extensions\shopee\Models\ShopeeListing::query()
                        ->forStore(ShopeeSetting::defaultStore())
                        ->where('item_name', 'like', '%' . $q . '%')
                        ->select('product_id'));
            });
        }

        $linkedIds = ShopeeProductLink::query()->pluck('product_id')
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
        $listed = fn ($query) => $query->whereIn('p.product_id', $linkedIds ?: [0]);
        $notListed = fn ($query) => $linkedIds === [] ? $query : $query->whereNotIn('p.product_id', $linkedIds);
        $applySync = function ($query, string $status) use ($listed, $notListed) {
            match ($status) {
                'uploaded' => $listed($query),
                'not_uploaded' => $notListed($query),
                default => $query,
            };

            return $query;
        };

        $errored = $this->listingStates()->erroredProductIds();
        $changed = $this->listingStates()->productIdsIn('drift');

        $applyMenu = function ($query) use ($applySync, $applyGroup, $syncStatus, $groupFilter, $failedFlag, $changeFlag, $errored, $changed) {
            $applyGroup($query, $groupFilter);
            $applySync($query, $syncStatus);
            if ($failedFlag) {
                $query->whereIn('p.product_id', $errored ?: [0]);
            }
            if ($changeFlag) {
                $query->whereIn('p.product_id', $changed ?: [0]);
            }

            return $query;
        };

        $troubleFilter = \App\Integrations\Listings\ListingTrouble::asked($request->query('state'));
        $troubleIds = $troubleFilter === null ? null
            : app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)->productIdsIn($troubleFilter);
        $applyTrouble = fn ($query) => $troubleIds === null ? $query : $query->whereIn('p.product_id', $troubleIds ?: [0]);

        $tabProductIds = ($shopeeTab !== 'all' && $liveCounts !== null)
            ? ShopeeProductLink::query()->where('live_status', $tabMap[$shopeeTab])
                ->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all()
            : null;
        $applyTab = fn ($query) => $tabProductIds === null ? $query : $query->whereIn('p.product_id', $tabProductIds ?: [0]);
        $tabCounts = function ($query) use ($tabMap): array {
            $byStatus = ShopeeProductLink::query()
                ->whereIn('product_id', (clone $query)->select('p.product_id'))
                ->whereNotNull('live_status')
                ->selectRaw('live_status, COUNT(DISTINCT product_id) as n')
                ->groupBy('live_status')
                ->pluck('n', 'live_status');
            $out = [];
            foreach ($tabMap as $tabKey => $status) {
                $out[$tabKey] = (int) ($byStatus[$status] ?? 0);
            }

            return $out;
        };

        $menuBase = $applyTab(clone $query);
        $withFlags = function ($sub) use ($failedFlag, $changeFlag, $errored, $changed) {
            if ($failedFlag) {
                $sub->whereIn('p.product_id', $errored ?: [0]);
            }
            if ($changeFlag) {
                $sub->whereIn('p.product_id', $changed ?: [0]);
            }

            return $sub;
        };

        $standBase = $withFlags($applyGroup(clone $menuBase, $groupFilter));
        $menuCounts = ['all' => (clone $standBase)->count()];
        foreach (['not_uploaded', 'uploaded'] as $status) {
            $menuCounts[$status] = $applySync(clone $standBase, $status)->count();
        }
        $filterBase = fn () => $applySync($applyGroup(clone $menuBase, $groupFilter), $syncStatus);
        $failedIn = $filterBase();
        if ($changeFlag) {
            $failedIn->whereIn('p.product_id', $changed ?: [0]);
        }
        $menuCounts['failed'] = $failedIn->whereIn('p.product_id', $errored ?: [0])->count();
        $changeIn = $filterBase();
        if ($failedFlag) {
            $changeIn->whereIn('p.product_id', $errored ?: [0]);
        }
        $menuCounts['change'] = $changeIn->whereIn('p.product_id', $changed ?: [0])->count();

        $railBase = clone $menuBase;
        $menuCounts['store'] = (clone $railBase)->count();
        $groupRail = [];
        foreach (ShopeeProductGroup::query()->orderBy('name')->get(['id', 'name']) as $group) {
            $groupRail[] = [
                'id' => (string) $group->id,
                'name' => (string) $group->name,
                'count' => $applyGroup(clone $railBase, (string) $group->id)->count(),
            ];
        }
        $groupRail[] = [
            'id' => 'none',
            'name' => \App\Integrations\Listings\StatusMenu::UNGROUPED,
            'count' => $applyGroup(clone $railBase, 'none')->count(),
        ];

        $tabBase = $applyTrouble($applyMenu(clone $query));
        $catalogueTotal = (clone $tabBase)->count();
        if ($liveCounts !== null) {
            $liveCounts = $tabCounts($tabBase);
        }
        $listedTotal = (int) ShopeeProductLink::query()->distinct()->count('product_id');

        $applyMenu($query);
        $applyTrouble($query);
        $applyTab($query);

        $storeId = \App\Integrations\Listings\ListingStore::id('shopee');
        $sortMenu = \Extensions\shopee\Services\ShopeeListingSort::columns($storeId);
        $order = \App\Integrations\Listings\ListingSort::chosen($request, 'shopee.listings.' . $storeId);
        if ($request->filled('sort')) {
            $query->orderBy($sortColumns[$sort], $dir);
            if ($sortColumns[$sort] !== 'p.product_id') {
                $query->orderBy('p.product_id', 'asc');
            }
        } else {
            \Extensions\shopee\Services\ShopeeListingSort::join($query, $storeId);
            \App\Integrations\Listings\ListingSort::apply($query, $order, $sortMenu);
        }

        $products = $query->paginate(50)->withQueryString();

        foreach ($products as $row) {
            if (isset($row->name)) {
                $row->name = html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        $pageIds = $products->pluck('product_id')->map(fn($v) => (int) $v)->all();
        $pageListings = $pageIds === [] ? collect() : \Extensions\shopee\Models\ShopeeListing::query()
            ->forStore(ShopeeSetting::defaultStore())
            ->whereIn('product_id', $pageIds)
            ->get();
        $rowTitles = \App\Integrations\Listings\ListingContent::rowTitles($products, $pageListings);
        $pageListings = $pageListings->keyBy(fn ($l) => (int) $l->product_id);
        $withVariations = ShopeeListing::withVariations($pageIds);
        $rowPrices = collect($products->items())->mapWithKeys(fn ($p) => [(int) $p->product_id => ($pageListings->get((int) $p->product_id) ?? new ShopeeListing())
            ->startingPrice((float) $p->price, isset($withVariations[(int) $p->product_id]))])->all();
        $shopeeLinks = collect();
        if (!empty($pageIds)) {
            $shopeeLinks = ShopeeProductLink::query()
                ->whereIn('product_id', $pageIds)
                ->get()
                ->groupBy(fn($l) => (int) $l->product_id);
        }

        $optionRowsByProductId = \App\Support\VariationRows::forListing($pageIds, 'shopee', (int) (ShopeeSetting::defaultStore()?->id ?? 0));

        if ($liveCounts !== null && $shopeeLinks->isNotEmpty()) {
            $fillError = null;
            $filled = app(\Extensions\shopee\Services\Shopee\ShopeeLiveListing::class)
                ->fillBlanks($auth, $shopeeLinks->flatten(), $fillError);
            if ($fillError !== null) {
                session()->now('error', \App\Integrations\Listings\BlankFillRefusal::sentence('Shopee', $fillError));
            }
            if ($filled) {
                $liveCounts = $tabCounts($tabBase);
                $liveCheckedAt = $liveCheckedAt ?? now();
            }
        }

        $liveStatuses = [];
        foreach ($shopeeLinks->flatten() as $l) {
            if ($l->live_status !== null) {
                $liveStatuses[(int) $l->shopee_item_id] = (string) $l->live_status;
            }
        }

        $listingStates = app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)
            ->forProducts(array_map('intval', $pageIds));


        $allManufacturers = DB::table($pfx . 'manufacturer')
            ->orderBy('name')
            ->pluck('name', 'manufacturer_id');

        $allGroups = ShopeeProductGroup::query()->orderBy('name')->pluck('name', 'id');

        $groupMap = $groupMapping['map'];
        $pageGroupMap = collect();
        foreach ($products as $p) {
            $pid = (int) $p->product_id;
            $pageGroupMap[$pid] = $groupMap[$pid] ?? [];
        }

        $unlistedIds = $products->filter(fn ($p) => !isset($shopeeLinks[(int) $p->product_id]))
            ->pluck('product_id')->map(fn ($v) => (int) $v)->values()->all();
        $pushReady = $unlistedIds !== []
            ? app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class)->forProducts($unlistedIds)
            : [];

        return view('ext-shopee::products.index', [
            'products' => $products,
            'rowTitles' => $rowTitles,
            'rowPrices' => $rowPrices,
            'troubleFilter' => $troubleFilter,
            'parcelGaps' => $this->parcelGapFor($products->pluck('product_id')->map(fn ($v) => (int) $v)->all()),
            'pushReady' => $pushReady,
            'q' => $q,
            'shopeeLinks' => $shopeeLinks,
            'sort' => $sort,
            'dir' => $dir,
            'order' => $order,
            'orderOptions' => \App\Integrations\Listings\ListingSort::options(array_keys($sortMenu)),
            'syncStatus' => $syncStatus,
            'manufacturerFilter' => $manufacturerFilter,
            'groupFilter' => $groupFilter,
            'erpStatus' => $erpStatus,
            'allManufacturers' => $allManufacturers,
            'allGroups' => $allGroups,
            'groupsByProductId' => $pageGroupMap,
            'optionRowsByProductId' => $optionRowsByProductId,
            'shopeeTab' => $shopeeTab,
            'liveCounts' => $liveCounts,
            'liveTabError' => $liveTabError,
            'liveStatuses' => $liveStatuses,
            'listingStates' => $listingStates,
            'rowErrors' => app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)->errors(array_map('intval', $pageIds)),
            'catalogueTotal' => $catalogueTotal,
            'listedTotal' => $listedTotal,
            'liveCheckedAt' => $liveCheckedAt,
            'failedFlag' => $failedFlag,
            'changeFlag' => $changeFlag,
            'groupSize' => ctype_digit((string) $groupFilter) ? app(\Extensions\shopee\Controllers\ShopeeProductGroupController::class)->groupSendSize((int) $groupFilter) : null,
            'statusMenu' => \App\Integrations\Listings\StatusMenu::build([
                'url' => fn (array $params) => route('ext.shopee.products.index', $params),
                'query' => $request->query(),
                'sync' => $syncStatus,
                'failed' => $failedFlag,
                'change' => $changeFlag,
                'counts' => $menuCounts,
                'group' => $groupFilter,
                'groups' => $groupRail,
                'newGroup' => route('ext.shopee.product-groups.create'),
                'store' => 'Shopee',
            ]),
        ]);
    }

    private function addCatalogue(Request $request)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $q = trim((string) $request->query('q', ''));
        $manufacturerFilter = (string) $request->query('manufacturer', 'all');
        $groupFilter = (string) $request->query('group', 'all');
        $onStore = (string) $request->query('on_store', 'all');

        $sort = (string) $request->query('sort', 'id');
        $sortColumns = ['id' => 'p.product_id', 'product' => 'pd.name', 'quantity' => 'p.quantity', 'price' => 'p.price'];
        if (! array_key_exists($sort, $sortColumns)) {
            $sort = 'id';
        }
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->leftJoinSub(ShopeeStoreProducts::query(), 'os', 'os.product_id', '=', 'p.product_id')
            ->select('p.product_id', 'pd.name', 'p.image', 'p.model', 'p.sku', 'p.price', 'p.quantity', 'p.status', 'm.name as manufacturer_name', 'os.product_id as on_store_pid');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.model', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%');
            });
        }
        if ($manufacturerFilter !== 'all') {
            $query->where('p.manufacturer_id', (int) $manufacturerFilter);
        }
        if ($groupFilter === 'none') {
            $groupedIds = $this->getGroupProductMapping()['ids'] ?? [];
            if (! empty($groupedIds)) {
                $query->whereNotIn('p.product_id', $groupedIds);
            }
        } elseif ($groupFilter !== 'all') {
            $query->whereIn('p.product_id', $this->getProductIdsByGroup((int) $groupFilter) ?: [0]);
        }
        if ($onStore === 'on') {
            $query->whereNotNull('os.product_id');
        } elseif ($onStore === 'off') {
            $query->whereNull('os.product_id');
        }

        $query->orderBy($sortColumns[$sort], $dir);
        if ($sortColumns[$sort] !== 'p.product_id') {
            $query->orderBy('p.product_id', 'asc');
        }

        $products = $query->paginate(50)->withQueryString();
        foreach ($products as $row) {
            if (isset($row->name)) {
                $row->name = html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        $productThumbsById = $products->mapWithKeys(fn ($r) => [(int) $r->product_id => $this->thumbUrl($r->image ?? null)]);

        return view('ext-shopee::products.add', [
            'products' => $products,
            'paginator' => $products,
            'productThumbsById' => $productThumbsById,
            'allManufacturers' => DB::table($pfx . 'manufacturer')->orderBy('name')->pluck('name', 'manufacturer_id'),
            'allGroups' => ShopeeProductGroup::query()->orderBy('name')->pluck('name', 'id'),
            'q' => $q,
            'manufacturerFilter' => $manufacturerFilter,
            'groupFilter' => $groupFilter,
            'onStore' => $onStore,
            'sort' => $sort,
            'dir' => $dir,
            'catalogueTotal' => (int) DB::table($pfx . 'product')->count(),
            'onStoreTotal' => ShopeeStoreProducts::count(),
            'listTab' => 'add',
        ]);
    }

    private function thumbUrl(?string $image): ?string
    {
        $image = trim((string) $image);

        return $image !== '' ? \App\Services\Media\ImageCache::url($image) : null;
    }

    public function addToStore(Request $request, int $productId)
    {
        $exists = DB::table((string) config('catalog.prefix') . 'product')->where('product_id', $productId)->exists();
        abort_unless($exists, 404);

        $wasOn = ShopeeStoreProducts::has($productId);
        ShopeeListing::firstOrCreate(['product_id' => $productId]);

        $groupId = (int) $request->input('group', 0);
        if ($groupId > 0) {
            \App\Integrations\OneGroupRule::place('shopee_product_group_products', 'shopee_product_group_id', 'shopee_product_groups', 'shopee_setting_id', \App\Integrations\Listings\ListingStore::id('shopee'), $groupId, $productId);
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'product_id' => $productId, 'added' => ! $wasOn]);
        }

        return redirect()->back()->with('status', 'Added to this store. Configure and push it from This store whenever you are ready.');
    }

    public function catalogueSearch(Request $request)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->query('q', ''));
        $showAll = $request->boolean('all');
        $limit = 25;

        $query = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
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
            $query->whereNull('os.product_id');
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('pd.name')->orderBy('p.product_id')->limit($limit)->get();

        $items = $rows->map(fn ($r) => [
            'id' => (int) $r->product_id,
            'name' => html_entity_decode((string) ($r->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Unnamed product',
            'sku' => (string) ($r->sku ?? ''),
            'quantity' => (int) ($r->quantity ?? 0),
            'price' => \App\Support\Money::base((float) ($r->price ?? 0)),
            'thumb' => $this->thumbUrl($r->image ?? null),
            'on_store' => ! is_null($r->on_store_pid),
        ])->values();

        return response()->json(['total' => $total, 'shown' => $items->count(), 'limit' => $limit, 'items' => $items]);
    }

    public function removeFromStore(int $productId)
    {
        abort_unless(ShopeeStoreProducts::has($productId), 404);

        $itemIds = ShopeeProductLink::query()->where('product_id', $productId)
            ->pluck('shopee_item_id')->filter()->unique()->values();

        ShopeeProductLink::unlinkProduct($productId, (int) app('shopee.route-store')->id);
        \Extensions\shopee\Models\ShopeeProductGroupProduct::query()
            ->where('product_id', $productId)
            ->whereIn('shopee_product_group_id', ShopeeProductGroup::query()->select('id'))
            ->delete();
        ShopeeListing::query()->where('product_id', $productId)->delete();

        $note = $itemIds->isNotEmpty()
            ? ' It no longer syncs; Shopee item ' . $itemIds->implode(', ') . ' stays up until you remove it there.'
            : ' It no longer syncs.';

        return redirect()->back()->with('status', "Removed product #{$productId} from this store." . $note);
    }

    public function bulkRemoveFromStore(Request $request)
    {
        $n = 0;
        foreach ($this->parseProductIds($request) as $pid) {
            if (!ShopeeStoreProducts::has($pid)) {
                continue;
            }
            ShopeeProductLink::unlinkProduct($pid, (int) app('shopee.route-store')->id);
            \Extensions\shopee\Models\ShopeeProductGroupProduct::query()->where('product_id', $pid)
                ->whereIn('shopee_product_group_id', ShopeeProductGroup::query()->select('id'))->delete();
            ShopeeListing::query()->where('product_id', $pid)->delete();
            $n++;
        }

        return redirect()->back()->with('status', "Removed {$n} " . ($n === 1 ? 'product' : 'products') . ' from this channel. Anything already on Shopee stays up until you delete it there.');
    }

    public function addToStoreBulk(Request $request)
    {
        $data = $request->validate(['product_ids' => ['required', 'array'], 'product_ids.*' => ['integer']]);
        $ids = array_values(array_unique(array_map('intval', $data['product_ids'])));

        $valid = DB::table((string) config('catalog.prefix') . 'product')->whereIn('product_id', $ids)->pluck('product_id')->all();
        $added = 0;
        foreach ($valid as $pid) {
            $pid = (int) $pid;
            $wasOn = ShopeeStoreProducts::has($pid);
            ShopeeListing::firstOrCreate(['product_id' => $pid]);
            if (! $wasOn) {
                $added++;
            }
        }

        return redirect()->back()->with('status', $added === 1
            ? '1 product added to this store.'
            : number_format($added) . ' products added to this store.');
    }

    public function refreshStatus(Request $request, \Extensions\shopee\Services\Shopee\ShopeeLiveListing $live)
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            return redirect()->back()->with('error', 'Missing Shopee settings.');
        }

        $result = $live->refreshMirror($auth);
        if ($result['error'] !== null) {
            return redirect()->back()->with('error', 'Shopee did not answer: ' . $result['error'] . ' The statuses shown are from the last refresh.');
        }
        $checked = app(\Extensions\shopee\Services\Shopee\ShopeeLinkCheck::class)->runNext(app(ShopeeClient::class), $auth, (int) $setting->id);
        $sheets = app(\Extensions\shopee\Services\Shopee\ShopeeSheetReads::class)
            ->readMissing($auth, app(ShopeeClient::class), \App\Integrations\Listings\SheetReads::PER_PRESS);

        $labels = ['NORMAL' => 'live', 'UNLIST' => 'unlisted', 'BANNED' => 'in violation', 'REVIEWING' => 'under review',
            'SELLER_DELETE' => 'deleted', 'SHOPEE_DELETE' => 'deleted by Shopee', 'MISSING' => 'not found on Shopee'];
        $parts = [];
        foreach ($result['counts'] as $status => $n) {
            $parts[] = number_format($n) . ' ' . ($labels[$status] ?? strtolower($status));
        }

        return redirect()->back()->with('status', 'Refreshed from Shopee: ' . ($parts ? implode(', ', $parts) : 'no linked items') . '.'
            . ($checked ? ' ' . $checked['summary'] : '')
            . ($sheets['summary'] !== '' ? ' ' . $sheets['summary'] : ''));
    }

    private function unlistItems(ShopeeClient $client, array $auth, array $itemIds, bool $unlist): array
    {
        $ok = [];
        $failed = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $itemIds))), 50) as $chunk) {
            $path = '/api/v2/product/unlist_item';
            $body = ['item_list' => array_map(fn ($id) => ['item_id' => $id, 'unlist' => $unlist], $chunk)];
            try {
                $result = $client->shopPost(
                    $auth['mode'],
                    (int) $auth['partner_id'], (string) $auth['partner_key'],
                    (string) $auth['access_token'], (int) $auth['shop_id'],
                    $path, [], $body
                );
            } catch (\Throwable $e) {
                return ['ok' => $ok, 'failed' => $failed, 'error' => \App\Support\TransportError::plain($e, 'Shopee')];
            }
            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.listings.toggle', 'method' => 'POST',
                'api_path' => $path, 'auth_required' => true,
                'request_params' => $body,
                'response_status' => $result['status'] ?? null,
                'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
            ]);
            if (!($result['ok'] ?? false)) {
                $msg = is_array($result['body'] ?? null)
                    ? (string) ($result['body']['message'] ?? ($result['body']['error'] ?? 'no response'))
                    : 'no response';
                return ['ok' => $ok, 'failed' => $failed, 'error' => $msg];
            }
            $refused = [];
            foreach ((($result['body'] ?? [])['response']['failure_list'] ?? []) as $f) {
                $refused[(int) ($f['item_id'] ?? 0)] = (string) ($f['failed_reason'] ?? 'refused');
            }
            $done = [];
            foreach ($chunk as $id) {
                if (isset($refused[$id])) {
                    $failed[$id] = $refused[$id];
                } else {
                    $ok[] = $id;
                    $done[] = $id;
                }
            }
            if ($done !== []) {
                ShopeeProductLink::query()->whereIn('shopee_item_id', $done)
                    ->update(['live_status' => $unlist ? 'UNLIST' : 'NORMAL', 'live_checked_at' => now()]);
            }
        }
        return ['ok' => $ok, 'failed' => $failed, 'error' => null];
    }

    public function bulkToggle(Request $request, ShopeeClient $client)
    {
        $request->validate([
            'action' => 'required|in:list,unlist',
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'integer',
        ]);
        $unlist = $request->input('action') === 'unlist';
        $verb = $unlist ? 'Delisted' : 'Published';

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            return redirect()->back()->with('error', 'Missing Shopee settings.');
        }

        $ids = array_values(array_unique(array_map('intval', (array) $request->input('product_ids'))));
        $links = ShopeeProductLink::query()->whereIn('product_id', $ids)->get();
        $notOnShopee = count($ids) - $links->count();
        if ($links->isEmpty()) {
            return redirect()->back()->with('error', 'None of the selected products is on Shopee, so there is nothing to ' . ($unlist ? 'unlist' : 'relist') . '.');
        }

        $heldBack = [];
        if (!$unlist) {
            $parcelGaps = $this->parcelGapFor($links->pluck('product_id')->map(fn ($v) => (int) $v)->all());
            [$held, $links] = $links->partition(fn ($l) => isset($parcelGaps[(int) $l->product_id]));
            foreach ($held as $l) {
                $heldBack[] = '#' . $l->product_id . ' needs ' . $parcelGaps[(int) $l->product_id];
                $this->listingStates()->recordOutcome((int) $l->product_id, 'Not published: Shopee will refuse it without ' . $parcelGaps[(int) $l->product_id] . '.');
            }
            if ($links->isEmpty()) {
                return redirect()->back()->with('error',
                    'Nothing was published: Shopee will refuse ' . (count($heldBack) === 1 ? 'it' : 'all of them') . ' without a parcel. '
                    . implode('; ', array_slice($heldBack, 0, 5))
                    . (count($heldBack) > 5 ? '; and ' . (count($heldBack) - 5) . ' more' : '')
                    . '. Add weight and size on the catalog products first.');
            }
        }

        $result = $this->unlistItems($client, $auth, $links->pluck('shopee_item_id')->all(), $unlist);
        $okItems = array_flip($result['ok']);
        $failedPrefix = $unlist ? 'Delist failed: ' : 'Publish failed: ';
        foreach ($links as $l) {
            $itemId = (int) $l->shopee_item_id;
            if (isset($okItems[$itemId])) {
                $this->listingStates()->recordOutcome((int) $l->product_id, null);
            } elseif (isset($result['failed'][$itemId])) {
                $this->listingStates()->recordOutcome((int) $l->product_id, $failedPrefix . $result['failed'][$itemId]);
            } elseif ($result['error'] !== null) {
                $this->listingStates()->recordOutcome((int) $l->product_id, $failedPrefix . $result['error']);
            }
        }
        foreach ($links as $l) {
            if (isset($okItems[(int) $l->shopee_item_id])) {
                ActivityLogger::log(
                    $unlist ? 'delisted' : 'published',
                    'Shopee Product',
                    (int) $l->product_id,
                    'Item ' . $l->shopee_item_id . ($unlist ? ' delisted from' : ' published on') . ' Shopee (selection)'
                );
            }
        }

        $parts = [];
        if ($result['ok'] !== []) {
            $parts[] = $verb . ' ' . count($result['ok']) . ' ' . (count($result['ok']) === 1 ? 'item' : 'items') . ($unlist ? ' from' : ' on') . ' Shopee.';
        }
        if ($result['failed'] !== []) {
            $reasons = array_unique(array_values($result['failed']));
            $parts[] = count($result['failed']) . ' refused: ' . implode('; ', array_slice($reasons, 0, 3)) . '.';
        }
        if ($notOnShopee > 0) {
            $parts[] = $notOnShopee . ' skipped, not on Shopee.';
        }
        if ($heldBack !== []) {
            $parts[] = count($heldBack) . ' held back - Shopee would refuse without a parcel: '
                . implode('; ', array_slice($heldBack, 0, 5))
                . (count($heldBack) > 5 ? '; and ' . (count($heldBack) - 5) . ' more' : '') . '.';
        }
        if ($result['error'] !== null) {
            $parts[] = 'Shopee did not answer: ' . $result['error'];
        }
        $message = implode(' ', $parts);
        $tone = ($result['ok'] === [] || $result['error'] !== null) ? 'error' : ($heldBack !== [] || $result['failed'] !== [] ? 'warning' : 'status');

        return redirect()->back()->with($tone, $message);
    }


    public function pushReview(int $productId)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->where('p.product_id', $productId)
            ->first(['p.product_id', 'pd.name', 'pd.description', 'p.sku', 'p.price', 'p.image']);
        if (!$product) {
            return response()->json(['ok' => false, 'message' => 'Product not found.'], 404);
        }

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        $readiness = app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class)
            ->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => []];

        $categoryName = $listing?->shopee_category_id
            ? \Extensions\shopee\Models\ShopeeCategory::query()->where('category_id', $listing->shopee_category_id)->value('name')
            : null;
        $courierNames = !empty($listing?->logistic_ids)
            ? \Extensions\shopee\Models\ShopeeLogistic::query()->whereIn('logistics_channel_id', $listing->logistic_ids)->pluck('logistics_channel_name')->all()
            : [];
        $imageCount = count(\App\Support\Catalog\ProductImages::paths($productId, $listing?->image_order));
        $variationCount = DB::table('product_option_combinations')->where('product_id', $productId)->count();

        $content = \App\Integrations\Listings\ListingContent::of(
            $listing,
            html_entity_decode((string) $product->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            \App\Support\Catalog\DescriptionText::of((string) ($product->description ?? '')),
            'shopee',
            (int) ($listing?->shopee_setting_id ?? 0)
        );

        $corePrice = (float) $product->price;
        $startPrice = $listing ? $listing->startingPrice($corePrice, $variationCount > 0 || ShopeeListing::hasVariations($productId)) : $corePrice;
        $finalPrice = $listing ? $listing->priceFor($startPrice) : $corePrice;
        $pct = (float) ($listing->markup_percent ?? 0);
        $fixed = (float) ($listing->markup_fixed ?? 0);
        $startWords = $startPrice !== $corePrice ? "this listing's price of " . number_format($startPrice, 2) : 'catalog price';
        $ruleWords = ($pct == 0.0 && $fixed == 0.0)
            ? $startWords
            : trim(($pct != 0.0 ? $pct . '%' : '') . ($pct != 0.0 && $fixed != 0.0 ? ' + ' : '') . ($fixed != 0.0 ? number_format($fixed, 2) : '')) . ' over ' . ($startPrice !== $corePrice ? $startWords : 'catalog');

        return response()->json([
            'ok' => true,
            'ready' => (bool) $readiness['ready'],
            'missing' => $readiness['missing'],
            'name' => html_entity_decode((string) $product->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'sku' => (string) $product->sku,
            'price' => number_format($finalPrice, 2),
            'core_price' => number_format($corePrice, 2),
            'rule' => $ruleWords,
            'category' => $categoryName ?: ($listing?->shopee_category_id ? '#' . $listing->shopee_category_id : null),
            'couriers' => $courierNames,
            'images' => $imageCount,
            'images_note' => null,
            'variations' => $variationCount,
            'title' => $content['title'],
            'description' => \App\Support\Catalog\DescriptionText::of($content['description']),
            'push_url' => route('ext.shopee.products.push_direct', $productId),
            'listing_url' => route('ext.shopee.listings.edit', $productId),
        ]);
    }

    public function pushDirect(Request $request, int $productId, ShopeeClient $client)
    {
        if ($request->filled('title') || $request->filled('description')) {
            $data = $request->validate([
                'title' => 'nullable|string|max:255',
                'description' => 'nullable|string|max:20000',
            ]);
            $description = null;
            if (trim((string) ($data['description'] ?? '')) !== '') {
                $description = \App\Integrations\Listings\ListingContent::descriptionToSave(
                    $data['description'],
                    ShopeeListing::query()->where('product_id', $productId)->value('description'),
                    null,
                    true
                );
            }
            ShopeeListing::query()->updateOrCreate(['product_id' => $productId], array_filter([
                'item_name' => $data['title'] ?? null,
                'description' => $description,
            ], fn ($v) => $v !== null && trim((string) $v) !== ''));
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Push failed: Missing Shopee {$modeLabel} settings.");
        }

        $answer = $this->pushOne($productId, $auth, $client);
        $this->listingStates()->recordOutcome($productId, $answer['ok'] ? null : $answer['message']);

        if (! $answer['ok']) {
            return redirect()->back()->with('error', $answer['message']);
        }

        return redirect()->to(\App\Support\BackTo::safe($request->input('back'), url()->previous(route('ext.shopee.products.index'))))->with('status', $answer['message']);
    }

    private function pushOne(int $productId, array $auth, ShopeeClient $client): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $existingLink = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if ($existingLink) {
            return ['ok' => false, 'message' => 'Push failed: Product already listed on Shopee (Item ID: ' . $existingLink->shopee_item_id . ').'];
        }

        $gateInherit = app(\Extensions\shopee\Services\Shopee\ShopeeInheritedSettings::class);
        $gateRow = ShopeeListing::query()->where('product_id', $productId)->first() ?? (new ShopeeListing())->forceFill(['product_id' => $productId]);
        $gateCategory = (int) ($gateInherit->fill($gateRow, $gateInherit->forProducts([$productId])[$productId] ?? null)['listing']->shopee_category_id ?? 0);
        if ($gateCategory > 0) {
            app(ShopeeProductGroupController::class)->ensureTemplate($gateCategory, $client);
        }
        $readiness = app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class)
            ->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => []];
        if (!$readiness['ready']) {
            return ['ok' => false, 'message' => \App\Integrations\Listings\CatalogGaps::refusal($readiness)];
        }

        $verdict = app(\Extensions\shopee\Services\Shopee\ShopeeLinkCheck::class)
            ->reconcile($client, $auth, $productId, $pfx);

        if (in_array($verdict['state'], ['confirmed', 'adopted', 'repointed'], true)) {
            return ['ok' => false, 'message' => 'Not pushed: Shopee already holds this product as item ' . $verdict['item_id']
                . '. It is linked here now, so use Push update to send these settings onto it.'];
        }
        if ($verdict['state'] === 'taken' || $verdict['state'] === 'unreachable') {
            return ['ok' => false, 'message' => 'Not pushed: ' . $verdict['message']];
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
                'p.manufacturer_id',
            ]);

        if (!$product) {
            return ['ok' => false, 'message' => 'Push failed: Product not found.'];
        }

        $saved = ShopeeListing::query()->where('product_id', $productId)->first();
        $inheritedFrom = [];
        $listing = null;
        if ($saved) {
            $inherit = app(\Extensions\shopee\Services\Shopee\ShopeeInheritedSettings::class);
            $filled = $inherit->fill($saved, $inherit->forProducts([$productId])[$productId] ?? null);
            $listing = $filled['listing'];
            $inheritedFrom = $filled['inherited'];
        }

        if (!$listing || !$listing->pushable()) {
            return ['ok' => false, 'message' => 'Push failed: this product has no Shopee listing settings yet. '
                . 'Open its listing (Listings → Open listing) and save its category, couriers and price rule, '
                . 'or push it from its product group.'];
        }

        $logisticIds = $listing->logistic_ids ?? [];

        $itemName = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $description = \App\Support\Catalog\DescriptionText::of((string) ($product->description ?? ''));

        $create = app(\Extensions\shopee\Services\Shopee\ShopeeItemCreate::class);
        $imagePaths = $create->catalogImagePaths($product, $listing);

        $up = $create->uploadImages($client, $auth, $imagePaths);
        if ($up['error'] !== null) {
            return ['ok' => false, 'message' => 'Push failed: ' . $up['error']];
        }
        $imageIds = $up['ids'];

        if (empty($imageIds)) {
            return ['ok' => false, 'message' => 'Push failed: No images were uploaded successfully.'];
        }

        $sellingPrice = $listing->itemPriceFor((float) $product->price, ShopeeListing::hasVariations($productId));

        $payload = $create->payloadFromListing(
            $listing, $product, $itemName, $description, $imageIds,
            $listing->attribute_values ?? []
        );

        $added = $create->addItem($client, $auth, $payload, 'shopee.products.add_item.direct');
        if (!$added['ok']) {
            return ['ok' => false, 'message' => 'Push failed: ' . $added['error']];
        }

        $itemId = $added['item_id'];
        if (!$itemId) {
            return ['ok' => false, 'message' => (string) $added['error']];
        }

        ShopeeProductLink::create([
            'product_id' => $productId,
            'shopee_item_id' => (int) $itemId,
            'shopee_model_id' => null,
            'sku' => $product->sku ?? '',
        ]);

        $saved->forceFill([
            'last_pushed_at' => now(),
            'last_push_source' => 'listing',
            'last_push_error' => null,
            'last_push_failed_at' => null,
            'last_push_settings' => [
                'category_id' => (int) $listing->shopee_category_id,
                'logistic_ids' => $logisticIds,
                'price' => $sellingPrice,
                'inherited_from' => $inheritedFrom,
            ],
        ])->save();

        app(\Extensions\shopee\Services\Shopee\ShopeeLiveListing::class)->confirm($auth, [(int) $itemId]);

        $rode = $create->variationsRideOrUndo(
            $client, $auth, (int) $itemId, $productId,
            fn (float $core) => $listing->priceFor($core)
        );

        if (!$rode['ok']) {
            return ['ok' => false, 'message' => $rode['undone']
                ? 'Not pushed: the variations were refused - ' . $rode['message']
                    . ' The item was removed from Shopee again, so nothing half-listed stays up.'
                : 'The variations were refused - ' . $rode['message']
                    . ' Shopee also refused to remove the half-created item (ID ' . $itemId . '). Delete it in Seller Centre, then push again.'];
        }

        $flash = 'Product pushed to Shopee. Item ID: ' . $itemId;
        if ($rode['models'] !== null) {
            $flash .= '. ' . $rode['models'] . ' variations went with it.';
        }

        return ['ok' => true, 'message' => $flash];
    }


    public function bulkPush(Request $request, ShopeeClient $client)
    {
        $productIds = array_values(array_unique(array_map('intval', $this->parseProductIds($request))));
        if ($productIds === []) {
            return redirect()->back()->with('error', 'No products selected.');
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';

            return redirect()->back()->with('error', "Push failed: Missing Shopee {$modeLabel} settings.");
        }

        $answer = \App\Integrations\Push\BulkPushRun::over(
            $productIds,
            'Shopee',
            function (int $productId) use ($auth, $client) {
                if (ShopeeProductLink::query()->where('product_id', $productId)->exists()) {
                    $r = $this->performListingUpdate($productId, $client, $auth);

                    return ['ok' => $r['ok'] && ($r['failure'] ?? null) === null, 'message' => (string) $r['message']];
                }
                $answer = $this->pushOne($productId, $auth, $client);
                $this->listingStates()->recordOutcome($productId, $answer['ok'] ? null : $answer['message']);

                return $answer;
            }
        );

        return redirect()->back()->with($answer['key'], $answer['message']);
    }

    public function syncQuantity(int $productId, ShopeeClient $client)
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $links = ShopeeProductLink::query()->where('product_id', $productId)->get();
        if ($links->isEmpty()) {
            return redirect()->back()->with('error', 'Product not linked to Shopee. Sync Product IDs first.');
        }

        $pfx = (string) config('catalog.prefix');

        $erpStatus = DB::table($pfx . 'product')->where('product_id', $productId)->value('status');
        if ((int) $erpStatus === 0) {
            return redirect()->back()->with('error', 'Cannot sync quantity for a disabled product. Enable it first.');
        }

        $results = app(\Extensions\shopee\Services\Shopee\ShopeeStockPricePush::class)
            ->push('stock', $auth, $links, $pfx);
        $clause = \Extensions\shopee\Services\Shopee\ShopeeStockPricePush::ledgerClause($results);

        $msg = $results['ok'] > 0
            ? "Stock synced to Shopee ({$results['ok']} model(s))." . $clause
            : 'Sync qty failed: ' . (($results['last_error'] ?: null) ?? 'Unknown error') . $clause;

        return redirect()->back()->with($results['ok'] > 0 ? 'status' : 'error', $msg);
    }

    public function syncPrice(int $productId, ShopeeClient $client)
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $links = ShopeeProductLink::query()->where('product_id', $productId)->get();
        if ($links->isEmpty()) {
            return redirect()->back()->with('error', 'Product not linked to Shopee. Sync Product IDs first.');
        }

        $pfx = (string) config('catalog.prefix');
        $results = app(\Extensions\shopee\Services\Shopee\ShopeeStockPricePush::class)
            ->push('price', $auth, $links, $pfx, \Extensions\shopee\Commands\ShopeePushPrice::priceRule([$productId]));
        $clause = \Extensions\shopee\Services\Shopee\ShopeeStockPricePush::ledgerClause($results);

        if (!empty($results['skipped_products']) && $results['ok'] === 0) {
            return redirect()->back()->with('error',
                'Price not synced: ' . implode('; ', array_slice(array_values($results['skipped_products']), 0, 3)));
        }

        $msg = $results['ok'] > 0
            ? "Price synced to Shopee ({$results['ok']} model(s))." . $clause
            : 'Sync price failed: ' . (($results['last_error'] ?: null) ?? 'Unknown error') . $clause;

        return redirect()->back()->with($results['ok'] > 0 ? 'status' : 'error', $msg);
    }

    public function unlink(int $productId)
    {
        $deleted = ShopeeProductLink::unlinkProduct($productId, (int) app('shopee.route-store')->id);

        if ($deleted === 0) {
            return redirect()->back()->with('error', 'Product was not linked to Shopee.');
        }

        return redirect()->back()->with('status', "Unlinked product from Shopee ($deleted link(s) removed, cache cleared). You can now re-sync.");
    }

    public function deleteFromShopee(int $productId, ShopeeClient $client)
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} credentials. Configure Shopee settings first.");
        }

        $link = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if (!$link || !$link->shopee_item_id) {
            return redirect()->back()->with('error', 'Product is not linked to a Shopee item.');
        }

        $itemId = (int) $link->shopee_item_id;
        $path = '/api/v2/product/delete_item';

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
            'pack'            => 'shopee.product.delete_item',
            'method'          => 'POST',
            'api_path'        => $path,
            'auth_required'   => true,
            'request_params'  => ['item_id' => $itemId, 'product_id' => $productId],
            'response_status' => (int) ($result['status'] ?? 0),
            'ok'              => (bool) ($result['ok'] ?? false),
            'response_body'   => $result['body'] ?? null,
        ]);

        $body = $result['body'] ?? [];
        $respError = is_array($body) ? ($body['error'] ?? '') : '';
        $respMsg = is_array($body) ? ($body['message'] ?? '') : '';

        if (($result['ok'] ?? false) && $respError === '') {
            ShopeeItemCache::query()->where('shopee_item_id', $link->shopee_item_id)->delete();
            ShopeeProductLink::query()->where('product_id', $productId)->delete();
            $this->listingStates()->clearErrors([$productId]);

            ActivityLogger::log('deleted', 'Shopee Product', $productId, 'Deleted item ' . $itemId . ' from Shopee');

            return redirect()->back()->with('status', 'Product deleted from Shopee (item ' . $itemId . ').');
        }

        $errorMsg = $respError ?: $respMsg ?: 'Unknown error';
        $this->listingStates()->recordOutcome($productId, 'Delete from Shopee failed: ' . $errorMsg);
        return redirect()->back()->with('error', 'Delete failed: ' . $errorMsg);
    }

    public function bulkDeleteFromShopee(Request $request, ShopeeClient $client)
    {
        $productIds = $request->input('product_ids', []);
        if (!is_array($productIds) || empty($productIds)) {
            return redirect()->back()->with('error', 'No products selected.');
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} credentials. Configure Shopee settings first.");
        }

        $okCount = 0;
        $errCount = 0;
        $imageNotes = [];
        $path = '/api/v2/product/delete_item';

        foreach ($productIds as $productId) {
            $productId = (int) $productId;
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
                'pack'            => 'shopee.product.delete_item.bulk',
                'method'          => 'POST',
                'api_path'        => $path,
                'auth_required'   => true,
                'request_params'  => ['item_id' => $itemId, 'product_id' => $productId],
                'response_status' => (int) ($result['status'] ?? 0),
                'ok'              => (bool) ($result['ok'] ?? false),
                'response_body'   => $result['body'] ?? null,
            ]);

            $body = $result['body'] ?? [];
            $respError = is_array($body) ? ($body['error'] ?? '') : '';

            if (($result['ok'] ?? false) && $respError === '') {
                ShopeeItemCache::query()->where('shopee_item_id', $link->shopee_item_id)->delete();
                ShopeeProductLink::query()->where('product_id', $productId)->delete();
                $this->listingStates()->clearErrors([$productId]);
                $okCount++;
            } else {
                $respMsg = is_array($body) ? (string) ($body['message'] ?? '') : '';
                $this->listingStates()->recordOutcome($productId, 'Delete from Shopee failed: ' . ($respError ?: $respMsg ?: 'Unknown error'));
                $errCount++;
            }

            usleep(200000);
        }

        ActivityLogger::log('deleted', 'Shopee Product', null, 'Bulk deleted ' . $okCount . ' product(s) from Shopee');

        return redirect()->back()->with('status', "Bulk Delete: {$okCount} deleted, {$errCount} failed out of " . count($productIds) . " selected.");
    }

    public function rebuildCache(ShopeeClient $client)
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $allItemIds = $this->fetchAllShopeeItemIds($client, $auth, 730, 'shopee.products.rebuild_cache');

        if (empty($allItemIds)) {
            return redirect()->back()->with('error', 'No items found on Shopee.');
        }

        DB::table('shopee_item_cache')
            ->when(app()->bound('shopee.route-store'), fn ($q) => $q->where('shopee_setting_id', app('shopee.route-store')->id))
            ->delete();

        $cached = 0;
        $modelsCached = 0;

        foreach (array_chunk($allItemIds, 50) as $chunk) {
            $infoResult = $client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_item_base_info',
                ['item_id_list' => implode(',', $chunk)]
            );

            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.products.rebuild_cache', 'method' => 'GET',
                'api_path' => '/api/v2/product/get_item_base_info', 'auth_required' => true,
                'request_params' => ['item_id_list' => implode(',', $chunk)],
                'response_status' => $infoResult['status'] ?? null,
                'ok' => (bool) ($infoResult['ok'] ?? false),
                'response_body' => $infoResult['body'] ?? null, 'user_id' => auth()->id(),
            ]);

            if (!($infoResult['ok'] ?? false)) continue;

            $itemList = (($infoResult['body'] ?? [])['response'] ?? ($infoResult['body'] ?? []))['item_list'] ?? [];

            foreach ($itemList as $item) {
                $itemId = (int) ($item['item_id'] ?? 0);
                $itemSku = trim((string) ($item['item_sku'] ?? ''));
                $hasModel = (bool) ($item['has_model'] ?? false);
                $itemName = trim((string) ($item['item_name'] ?? ''));
                $imageUrl = $item['image']['image_url_list'][0] ?? null;

                if (!$itemId) continue;

                if ($itemSku !== '') {
                    ShopeeItemCache::query()->create([
                        'shopee_item_id' => $itemId,
                        'shopee_model_id' => null,
                        'sku' => $itemSku,
                        'item_name' => $itemName,
                        'image_url' => $imageUrl,
                    ]);
                    $cached++;
                }

                if ($hasModel) {
                    $modelResult = $client->shopGet(
                        $auth['mode'],
                        (int) $auth['partner_id'], (string) $auth['partner_key'],
                        (string) $auth['access_token'], (int) $auth['shop_id'],
                        '/api/v2/product/get_model_list',
                        ['item_id' => $itemId]
                    );

                    if (!($modelResult['ok'] ?? false)) continue;

                    $models = (($modelResult['body'] ?? [])['response'] ?? ($modelResult['body'] ?? []))['model'] ?? [];

                    foreach ($models as $model) {
                        $modelId = (int) ($model['model_id'] ?? 0);
                        $modelSku = trim((string) ($model['model_sku'] ?? ''));
                        if ($modelSku === '' || !$modelId) continue;

                        ShopeeItemCache::query()->create([
                            'shopee_item_id' => $itemId,
                            'shopee_model_id' => $modelId,
                            'sku' => $modelSku,
                            'item_name' => $itemName,
                            'image_url' => $imageUrl,
                        ]);
                        $modelsCached++;
                    }
                }
            }
        }

        $total = $cached + $modelsCached;
        return redirect()->back()
            ->with('status', "Cache rebuilt: {$total} entries ({$cached} items + {$modelsCached} models) from " . count($allItemIds) . " Shopee products.");
    }

    public function importPage(Request $request, ShopeeClient $client)
    {
        $fetched = null;
        $fetchError = null;
        if ($request->boolean('fetch')) {
            [$fetched, $fetchError] = $this->fetchUnmatched($client);
        }

        return view('ext-shopee::products.import', [
            'fetched' => $fetched,
            'fetchError' => $fetchError,
        ]);
    }

    private function fetchUnmatched(ShopeeClient $client): array
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return [null, "Missing Shopee {$modeLabel} settings."];
        }

        $pfx = (string) config('catalog.prefix');

        $allItemIds = $this->fetchLiveItemIds($client, $auth);
        if ($allItemIds === null) {
            return [null, 'Shopee did not answer the fetch. Check the connection and try again.'];
        }
        if ($allItemIds === []) {
            return [null, 'No items found on Shopee.'];
        }

        $linkedCombos = ShopeeProductLink::query()->get(['shopee_item_id', 'shopee_model_id']);
        $linkedSet = [];
        foreach ($linkedCombos as $lc) {
            $key = (string) $lc->shopee_item_id . ':' . (string) ($lc->shopee_model_id ?? '');
            $linkedSet[$key] = true;
        }

        $erpSkus = [];
        $erpProducts = DB::table($pfx . 'product')->whereNotNull('model')->where('model', '!=', '')->get(['model', 'sku']);
        foreach ($erpProducts as $ep) {
            if ($ep->model !== null && $ep->model !== '') $erpSkus[strtolower(trim($ep->model))] = true;
            if ($ep->sku !== null && $ep->sku !== '') $erpSkus[strtolower(trim($ep->sku))] = true;
        }
        $optSkus = DB::table($pfx . 'product_option_value')
            ->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku');
        foreach ($optSkus as $os) {
            $erpSkus[strtolower(trim($os))] = true;
        }

        $found = 0;
        $rows = [];

        foreach (array_chunk($allItemIds, 50) as $chunk) {
            $infoResult = $client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_item_base_info',
                ['item_id_list' => implode(',', $chunk)]
            );

            if (!($infoResult['ok'] ?? false)) continue;

            $itemList = (($infoResult['body'] ?? [])['response'] ?? ($infoResult['body'] ?? []))['item_list'] ?? [];

            foreach ($itemList as $item) {
                $itemId = (int) ($item['item_id'] ?? 0);
                $itemSku = trim((string) ($item['item_sku'] ?? ''));
                $hasModel = (bool) ($item['has_model'] ?? false);
                $itemName = trim((string) ($item['item_name'] ?? ''));
                $imageUrl = $item['image']['image_url_list'][0] ?? null;

                if (!$itemId) continue;
                $found++;

                if ($hasModel) {
                    $modelResult = $client->shopGet(
                        $auth['mode'],
                        (int) $auth['partner_id'], (string) $auth['partner_key'],
                        (string) $auth['access_token'], (int) $auth['shop_id'],
                        '/api/v2/product/get_model_list',
                        ['item_id' => $itemId]
                    );

                    if (!($modelResult['ok'] ?? false)) continue;

                    $models = (($modelResult['body'] ?? [])['response'] ?? ($modelResult['body'] ?? []))['model'] ?? [];

                    $unplaced = null;
                    $modelSkus = [];
                    foreach ($models as $model) {
                        $modelId = (int) ($model['model_id'] ?? 0);
                        $modelSku = trim((string) ($model['model_sku'] ?? ''));
                        if ($modelSku !== '') $modelSkus[] = $modelSku;
                        if (!$modelId) continue;
                        if (isset($linkedSet[$itemId . ':' . $modelId])) continue;
                        if ($modelSku !== '' && isset($erpSkus[strtolower($modelSku)])) continue;
                        $unplaced = $modelSku ?: $unplaced;
                        $unplaced = $unplaced ?? '';
                    }
                    if ($unplaced === null) continue;

                    $rows[] = [
                        'ref' => $itemId,
                        'sku' => $itemSku !== '' ? $itemSku : (string) $unplaced,
                        'skus' => array_values(array_unique(array_filter(array_merge([$itemSku], $modelSkus)))),
                        'channel_id' => $itemId,
                        'name' => $itemName,
                        'image_url' => (string) ($imageUrl ?? ''),
                        'variants' => count($models),
                    ];
                } else {
                    if (isset($linkedSet[$itemId . ':'])) continue;
                    if ($itemSku !== '' && isset($erpSkus[strtolower($itemSku)])) continue;

                    $rows[] = [
                        'ref' => $itemId,
                        'sku' => $itemSku,
                        'skus' => $itemSku !== '' ? [$itemSku] : [],
                        'channel_id' => $itemId,
                        'name' => $itemName,
                        'image_url' => (string) ($imageUrl ?? ''),
                        'variants' => 0,
                    ];
                }
            }
        }

        return [['found' => $found, 'rows' => $rows], null];
    }

    public function checkAgainstShopee(Request $request, ShopeeClient $client, \Extensions\shopee\Services\Shopee\ShopeeLinkCheck $check)
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $productIds = $this->parseProductIds($request);
        if (empty($productIds)) {
            $productIds = $check->storeProductIds((int) $setting->id);
        }
        if (empty($productIds)) {
            return redirect()->back()->with('error', 'This store carries no products yet, so there is nothing to check.');
        }

        $r = $check->run($client, $auth, $productIds);

        return redirect()->back()->with($r['tone'], $r['summary']);
    }

    public function bulkSyncQuantity(Request $request, ShopeeClient $client)
    {
        $ids = $this->parseProductIds($request);
        if (empty($ids)) {
            return redirect()->back()->with('error', 'No products selected.');
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $pfx = (string) config('catalog.prefix');

        $enabledIds = DB::table($pfx . 'product')
            ->whereIn('product_id', $ids)
            ->where('status', 1)
            ->pluck('product_id')
            ->toArray();
        $disabledCount = count($ids) - count($enabledIds);

        $links = ShopeeProductLink::query()->whereIn('product_id', $enabledIds)->get();

        $skip = count($enabledIds) - $links->pluck('product_id')->unique()->count();

        $results = app(\Extensions\shopee\Services\Shopee\ShopeeStockPricePush::class)
            ->push('stock', $auth, $links, $pfx);
        $ok = $results['ok'];
        $err = $results['err'];

        $msg = "Bulk Sync Qty: {$ok} success, {$err} error, {$skip} skipped (not linked)";
        if ($disabledCount > 0) {
            $msg .= ", {$disabledCount} skipped (disabled)";
        }
        $msg .= '.' . \Extensions\shopee\Services\Shopee\ShopeeStockPricePush::ledgerClause($results);

        return redirect()->back()->with('status', $msg);
    }

    public function bulkSyncPrice(Request $request, ShopeeClient $client)
    {
        $ids = $this->parseProductIds($request);
        if (empty($ids)) {
            return redirect()->back()->with('error', 'No products selected.');
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $pfx = (string) config('catalog.prefix');
        $links = ShopeeProductLink::query()->whereIn('product_id', $ids)->get();

        $skip = count($ids) - $links->pluck('product_id')->unique()->count();

        $results = app(\Extensions\shopee\Services\Shopee\ShopeeStockPricePush::class)
            ->push('price', $auth, $links, $pfx, \Extensions\shopee\Commands\ShopeePushPrice::priceRule($ids));

        $summary = "Bulk Sync Price: {$results['ok']} success, {$results['err']} error, {$skip} skipped (not linked).";

        if (!empty($results['skipped_products'])) {
            $summary .= ' ' . count($results['skipped_products']) . ' without a listing price rule: '
                . implode('; ', array_slice(array_values($results['skipped_products']), 0, 5));
        }
        $summary .= \Extensions\shopee\Services\Shopee\ShopeeStockPricePush::ledgerClause($results);

        return redirect()->back()->with('status', $summary);
    }

    public function syncSingleProductId(int $productId, ShopeeClient $client)
    {
        $tag = '[ShopeeSync:single:' . $productId . ']';

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $existingLink = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if ($existingLink) {
            return redirect()->back()->with('status', 'Product already linked to Shopee item ' . $existingLink->shopee_item_id . ' (SKU: ' . $existingLink->sku . '). Use Unlink to re-sync.');
        }

        $pfx = (string) config('catalog.prefix');

        $ovSkus = DB::table($pfx . 'product_option_value')
            ->where('product_id', $productId)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->pluck('sku')
            ->map(fn($s) => trim((string) $s))
            ->filter(fn($s) => $s !== '')
            ->unique()
            ->values()
            ->toArray();

        $product = DB::table($pfx . 'product')->where('product_id', $productId)->first(['model', 'sku']);
        $mainModel = trim((string) ($product->model ?? ''));
        $mainSku = trim((string) ($product->sku ?? ''));

        $allSkus = $ovSkus;
        if ($mainModel !== '' && !in_array($mainModel, $allSkus)) $allSkus[] = $mainModel;
        if ($mainSku !== '' && !in_array($mainSku, $allSkus)) $allSkus[] = $mainSku;

        Log::info("$tag ERP product fields: model: '$mainModel', sku: '$mainSku', option_skus: " . json_encode($ovSkus));
        Log::info("$tag Looking for these SKUs: " . json_encode($allSkus));

        if (empty($allSkus)) {
            return redirect()->back()->with('error', 'Product has no SKU (main or option values).');
        }

        $lcSkus = array_map('strtolower', $allSkus);

        $cacheHits = ShopeeItemCache::query()
            ->whereIn(DB::raw('LOWER(sku)'), $lcSkus)
            ->get();

        if ($cacheHits->isNotEmpty()) {
            $hasOptions = !empty($ovSkus);
            $linkedAny = false;
            $cacheItemId = null;
            $cacheItemName = null;

            foreach ($cacheHits as $hit) {
                $isParent = ($hit->shopee_model_id === null);

                if ($isParent && $hasOptions) {
                    $hasModelEntries = ShopeeItemCache::query()
                        ->where('shopee_item_id', $hit->shopee_item_id)
                        ->whereNotNull('shopee_model_id')
                        ->exists();
                    if ($hasModelEntries) {
                        Log::info("$tag Cache: skipping parent hit (item {$hit->shopee_item_id}, sku: {$hit->sku}), ERP has option SKUs");
                        continue;
                    }
                }

                if ($isParent) {
                    ShopeeProductLink::create([
                        'product_id' => $productId,
                        'shopee_item_id' => (int) $hit->shopee_item_id,
                        'shopee_model_id' => null,
                        'sku' => $hit->sku,
                    ]);
                    Log::info("$tag MATCHED via cache (parent), shopee_item_id: {$hit->shopee_item_id}, sku: {$hit->sku}");
                    return redirect()->back()
                        ->with('status', "Linked to Shopee item {$hit->shopee_item_id} \"{$hit->item_name}\" (SKU: {$hit->sku}) [from cache].");
                }

                $exists = ShopeeProductLink::query()
                    ->where('shopee_item_id', $hit->shopee_item_id)
                    ->where('shopee_model_id', $hit->shopee_model_id)
                    ->exists();
                if (!$exists) {
                    ShopeeProductLink::create([
                        'product_id' => $productId,
                        'shopee_item_id' => (int) $hit->shopee_item_id,
                        'shopee_model_id' => (int) $hit->shopee_model_id,
                        'sku' => $hit->sku,
                    ]);
                }
                $linkedAny = true;
                $cacheItemId = $hit->shopee_item_id;
                $cacheItemName = $hit->item_name;
                Log::info("$tag MATCHED via cache (model), shopee_item_id: {$hit->shopee_item_id}, model_id: {$hit->shopee_model_id}, sku: {$hit->sku}");
            }

            if ($linkedAny) {
                return redirect()->back()
                    ->with('status', "Linked to Shopee item {$cacheItemId} \"{$cacheItemName}\" (models matched by SKU) [from cache].");
            }
        }

        Log::info("$tag No cache match, falling back to full API scan");

        $allItemIds = $this->fetchAllShopeeItemIds($client, $auth, 730, 'shopee.products.sync_single');
        Log::info("$tag Total unique Shopee items: " . count($allItemIds));

        if (empty($allItemIds)) {
            return redirect()->back()->with('error', 'No items found on Shopee.');
        }

        $simpleCount = 0;
        $modelItemCount = 0;
        $allShopeeSkus = [];

        foreach (array_chunk($allItemIds, 50) as $chunkIdx => $chunk) {
            $infoResult = $client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_item_base_info',
                ['item_id_list' => implode(',', $chunk)]
            );

            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.products.sync_single', 'method' => 'GET',
                'api_path' => '/api/v2/product/get_item_base_info', 'auth_required' => true,
                'request_params' => ['item_id_list' => implode(',', $chunk), 'product_id' => $productId],
                'response_status' => $infoResult['status'] ?? null,
                'ok' => (bool) ($infoResult['ok'] ?? false),
                'response_body' => $infoResult['body'] ?? null, 'user_id' => auth()->id(),
            ]);

            if (!($infoResult['ok'] ?? false)) {
                Log::warning("$tag get_item_base_info FAILED for chunk $chunkIdx: " . json_encode($infoResult['body'] ?? null));
                continue;
            }

            $itemList = (($infoResult['body'] ?? [])['response'] ?? ($infoResult['body'] ?? []))['item_list'] ?? [];

            foreach ($itemList as $item) {
                $itemId = (int) ($item['item_id'] ?? 0);
                $itemSku = trim((string) ($item['item_sku'] ?? ''));
                $hasModel = (bool) ($item['has_model'] ?? false);
                $itemName = trim((string) ($item['item_name'] ?? ''));
                $imageUrl = $item['image']['image_url_list'][0] ?? null;
                if (!$itemId) continue;

                if ($itemSku !== '') {
                    $simpleCount++;
                    $allShopeeSkus[] = $itemSku;

                    ShopeeItemCache::query()->updateOrCreate(
                        ['shopee_item_id' => $itemId, 'shopee_model_id' => null],
                        ['sku' => $itemSku, 'item_name' => $itemName, 'image_url' => $imageUrl]
                    );

                    if (in_array(strtolower($itemSku), $lcSkus)) {
                        $erpHasOptions = $hasModel && !empty($ovSkus);
                        if (!$erpHasOptions) {
                            ShopeeProductLink::create([
                                'product_id' => $productId, 'shopee_item_id' => $itemId,
                                'shopee_model_id' => null, 'sku' => $itemSku,
                            ]);
                            Log::info("$tag MATCHED via item_sku, shopee_item_id: $itemId, item_sku: $itemSku, item_name: '$itemName', has_model: " . ($hasModel ? 'true' : 'false'));
                            return redirect()->back()
                                ->with('status', "Linked to Shopee item {$itemId} \"{$itemName}\" (SKU: {$itemSku}).");
                        }
                        Log::info("$tag item_sku '$itemSku' matches but ERP has option SKUs, deferring to model-level matching");
                    }
                }

                if ($hasModel) {
                    $modelItemCount++;
                    $modelResult = $client->shopGet(
                        $auth['mode'],
                        (int) $auth['partner_id'], (string) $auth['partner_key'],
                        (string) $auth['access_token'], (int) $auth['shop_id'],
                        '/api/v2/product/get_model_list',
                        ['item_id' => $itemId]
                    );

                    ShopeeApiLog::safeCreate([
                        'pack' => 'shopee.products.sync_single', 'method' => 'GET',
                        'api_path' => '/api/v2/product/get_model_list', 'auth_required' => true,
                        'request_params' => ['item_id' => $itemId, 'product_id' => $productId],
                        'response_status' => $modelResult['status'] ?? null,
                        'ok' => (bool) ($modelResult['ok'] ?? false),
                        'response_body' => $modelResult['body'] ?? null, 'user_id' => auth()->id(),
                    ]);

                    if (!($modelResult['ok'] ?? false)) {
                        Log::warning("$tag get_model_list FAILED for item_id=$itemId: " . json_encode($modelResult['body'] ?? null));
                        continue;
                    }

                    $models = (($modelResult['body'] ?? [])['response'] ?? ($modelResult['body'] ?? []))['model'] ?? [];
                    $linkedAny = false;
                    foreach ($models as $model) {
                        $modelSku = trim((string) ($model['model_sku'] ?? ''));
                        $mId = (int) ($model['model_id'] ?? 0);

                        if ($modelSku !== '') {
                            $allShopeeSkus[] = $modelSku;
                            ShopeeItemCache::query()->updateOrCreate(
                                ['shopee_item_id' => $itemId, 'shopee_model_id' => $mId],
                                ['sku' => $modelSku, 'item_name' => $itemName, 'image_url' => $imageUrl]
                            );
                        }

                        if ($modelSku !== '' && in_array(strtolower($modelSku), $lcSkus)) {
                            $exists = ShopeeProductLink::query()
                                ->where('shopee_item_id', $itemId)
                                ->where('shopee_model_id', $mId)
                                ->exists();
                            if (!$exists) {
                                ShopeeProductLink::create([
                                    'product_id' => $productId, 'shopee_item_id' => $itemId,
                                    'shopee_model_id' => $mId, 'sku' => $modelSku,
                                ]);
                            }
                            $linkedAny = true;
                            Log::info("$tag MATCHED via model_sku, shopee_item_id: $itemId, model_id: $mId, model_sku: $modelSku");
                        }
                    }
                    if ($linkedAny) {
                        return redirect()->back()
                            ->with('status', "Linked to Shopee item {$itemId} \"{$itemName}\" (models matched by SKU).");
                    }
                }
            }
        }

        Log::warning("$tag NO MATCH: simple items scanned: $simpleCount, model items scanned: $modelItemCount, total Shopee SKUs seen: " . count($allShopeeSkus));
        Log::warning("$tag ERP SKUs wanted: " . json_encode($allSkus));
        Log::warning("$tag Shopee SKUs sample (first 50): " . json_encode(array_slice($allShopeeSkus, 0, 50)));

        return redirect()->back()
            ->with('error', 'No matching Shopee product found for SKUs: ' . implode(', ', $allSkus)
                . ' (Scanned ' . count($allItemIds) . ' Shopee items: ' . $simpleCount . ' simple, ' . $modelItemCount . ' with models)');
    }

    public function importOne(Request $request, \Extensions\shopee\Services\Shopee\ShopeeItemImport $import)
    {
        $data = $request->validate(['ref' => 'required|integer|min:1']);

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $r = $import->import($auth, (int) $data['ref']);

        return redirect()->back()->with($r['ok'] ? 'status' : 'error', $r['message']);
    }

    public function importSelected(Request $request, \Extensions\shopee\Services\Shopee\ShopeeItemImport $import)
    {
        $data = $request->validate(['refs' => 'required|array|min:1', 'refs.*' => 'integer|min:1']);

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $done = 0;
        $failed = [];
        $refs = array_values(array_unique(array_map('intval', $data['refs'])));
        foreach ($refs as $ref) {
            $r = $import->import($auth, $ref);
            if ($r['ok'] ?? false) {
                $done++;
            } else {
                $failed[] = '#' . $ref . ': ' . ($r['message'] ?? 'not imported');
            }
        }

        $line = \App\Support\BulkImportSummary::line($done, count($refs), $failed);

        return redirect()->back()->with($line['tone'], $line['message']);
    }

    public function linkItem(Request $request)
    {
        $data = $request->validate([
            'ref' => 'required|integer|min:1',
            'sku' => 'nullable|string|max:191',
            'product_id' => 'required|integer|min:1',
        ]);

        $pfx = (string) config('catalog.prefix');
        if (! DB::table($pfx . 'product')->where('product_id', (int) $data['product_id'])->exists()) {
            return redirect()->back()->with('error', 'Catalog product not found.');
        }

        $storeId = (int) (ShopeeSetting::defaultStore()?->id ?? 0);
        $scoped = fn () => ShopeeProductLink::query()->whereNull('shopee_model_id')->when($storeId > 0, fn ($q) => $q->where('shopee_setting_id', $storeId));
        $held = $scoped()->where('product_id', (int) $data['product_id'])->whereNotNull('shopee_item_id')->first();
        if ($held && (string) $held->shopee_item_id !== (string) $data['ref']) {
            return redirect()->back()->with('error', 'Catalog product #' . $data['product_id'] . ' is already linked to Shopee item ' . $held->shopee_item_id . '. Unlink it first.');
        }
        $taken = $scoped()->where('shopee_item_id', (string) $data['ref'])->where('product_id', '!=', (int) $data['product_id'])->first();
        if ($taken) {
            return redirect()->back()->with('error', 'Shopee item ' . $data['ref'] . ' is already linked to catalog product #' . $taken->product_id . '. Unlink that product first.');
        }
        if (! $held) {
            ShopeeProductLink::create([
                'product_id' => (int) $data['product_id'],
                'shopee_item_id' => (string) $data['ref'],
                'shopee_model_id' => null,
                'sku' => trim((string) ($data['sku'] ?? '')),
            ]);
        }

        return redirect()->back()->with('status', 'Linked Shopee item #' . $data['ref'] . ' to catalog product #' . $data['product_id'] . '.');
    }

    public function searchCatalogProducts(Request $request)
    {
        return response()->json(\App\Support\CatalogPickerSearch::items((string) $request->get('q', ''), function (array $ids) {
            $storeId = (int) (ShopeeSetting::defaultStore()?->id ?? 0);

            return ShopeeProductLink::query()->whereIn('product_id', $ids)->whereNotNull('shopee_item_id')
                ->when($storeId > 0, fn ($q) => $q->where('shopee_setting_id', $storeId))
                ->get(['product_id', 'shopee_item_id'])
                ->mapWithKeys(fn ($l) => [(int) $l->product_id => 'Shopee item ' . $l->shopee_item_id])->all();
        }));
    }



    private function parcelGapFor(array $productIds): array
    {
        $pfx = (string) config('catalog.prefix');
        $out = [];
        $rows = DB::table($pfx . 'product')->whereIn('product_id', $productIds)
            ->get(['product_id', 'weight', 'length', 'width', 'height']);
        foreach ($rows as $r) {
            $missing = [];
            if ((float) $r->weight <= 0) $missing[] = 'a package weight';
            if ((float) $r->length <= 0 || (float) $r->width <= 0 || (float) $r->height <= 0) $missing[] = 'a package size (L×W×H)';
            if ($missing !== []) {
                $out[(int) $r->product_id] = implode(' and ', $missing);
            }
        }

        return $out;
    }

    private function listingStates(): \Extensions\shopee\Services\Shopee\ShopeeListingStates
    {
        return app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class);
    }

    private function parseProductIds(Request $request): array
    {
        $ids = $request->input('product_ids', []);
        if (!is_array($ids)) $ids = [];
        return array_values(array_unique(array_filter(array_map(fn($v) => (int) $v, $ids), fn($v) => $v > 0)));
    }

    private function matchProductBySku(string $pfx, string $sku): int
    {
        if ($sku === '') return 0;

        $pov = DB::table($pfx . 'product_option_value')->where('sku', $sku)->first(['product_id']);
        if ($pov) return (int) $pov->product_id;

        $prod = DB::table($pfx . 'product')->where('model', $sku)->first(['product_id']);
        if ($prod) return (int) $prod->product_id;

        $prod = DB::table($pfx . 'product')->where('sku', $sku)->first(['product_id']);
        if ($prod) return (int) $prod->product_id;

        return 0;
    }


    private function getGroupProductMapping(): array
    {
        if (!ShopeeProductGroup::query()->exists()) {
            return ['ids' => null, 'map' => []];
        }

        $rows = DB::table('shopee_product_group_products as pgp')
            ->join('shopee_product_groups as g', 'g.id', '=', 'pgp.shopee_product_group_id')
            ->whereIn('g.id', ShopeeProductGroup::query()->pluck('id'))
            ->get(['pgp.product_id', 'g.id', 'g.name']);

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->product_id][] = ['id' => (int) $r->id, 'name' => (string) $r->name];
        }

        return ['ids' => array_keys($map), 'map' => $map];
    }

    private function getProductIdsByGroup(int $groupId): array
    {
        return DB::table('shopee_product_group_products')
            ->where('shopee_product_group_id', $groupId)
            ->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
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

    private function fetchLiveItemIds(ShopeeClient $client, array $auth): ?array
    {
        $ids = [];
        foreach (['NORMAL', 'UNLIST'] as $itemStatus) {
            $offset = 0;
            while (true) {
                $result = $client->shopGet(
                    $auth['mode'],
                    (int) $auth['partner_id'], (string) $auth['partner_key'],
                    (string) $auth['access_token'], (int) $auth['shop_id'],
                    '/api/v2/product/get_item_list',
                    ['offset' => $offset, 'page_size' => 100, 'item_status' => $itemStatus]
                );

                if (!($result['ok'] ?? false)) {
                    return null;
                }

                $response = ($result['body'] ?? [])['response'] ?? ($result['body'] ?? []);
                $items = $response['item'] ?? [];
                foreach ($items as $item) {
                    if ($id = $item['item_id'] ?? null) $ids[] = (int) $id;
                }

                if (!($response['has_next_page'] ?? false) || empty($items)) break;
                $offset = (int) ($response['next_offset'] ?? $offset + 100);
            }
        }

        return array_values(array_unique($ids));
    }

    private function fetchAllShopeeItemIds(ShopeeClient $client, array $auth, int $days = 730, ?string $logPack = null): array
    {
        $now = time();
        $allItemIds = [];
        $windowSize = 15 * 86400;
        $startFrom = $now - ($days * 86400);

        $timeFields = [
            ['update_time_from', 'update_time_to'],
            ['create_time_from', 'create_time_to'],
        ];

        foreach ($timeFields as [$fromKey, $toKey]) {
            foreach (['NORMAL', 'UNLIST'] as $itemStatus) {
                $windowStart = $startFrom;

                while ($windowStart < $now) {
                    $windowEnd = min($windowStart + $windowSize, $now);
                    $offset = 0;

                    while (true) {
                        $result = $client->shopGet(
                            $auth['mode'],
                            (int) $auth['partner_id'], (string) $auth['partner_key'],
                            (string) $auth['access_token'], (int) $auth['shop_id'],
                            '/api/v2/product/get_item_list',
                            ['offset' => $offset, 'page_size' => 100, $fromKey => $windowStart, $toKey => $windowEnd, 'item_status' => $itemStatus]
                        );

                        if ($logPack) {
                            ShopeeApiLog::safeCreate([
                                'pack' => $logPack, 'method' => 'GET',
                                'api_path' => '/api/v2/product/get_item_list', 'auth_required' => true,
                                'request_params' => ['offset' => $offset, 'item_status' => $itemStatus, 'time_field' => $fromKey, 'from' => date('Y-m-d', $windowStart), 'to' => date('Y-m-d', $windowEnd)],
                                'response_status' => $result['status'] ?? null,
                                'ok' => (bool) ($result['ok'] ?? false), 'response_body' => $result['body'] ?? null,
                                'user_id' => auth()->id(),
                            ]);
                        }

                        if (!($result['ok'] ?? false)) break;

                        $response = ($result['body'] ?? [])['response'] ?? ($result['body'] ?? []);
                        $items = $response['item'] ?? [];
                        foreach ($items as $item) {
                            if ($id = $item['item_id'] ?? null) $allItemIds[] = (int) $id;
                        }

                        if (!($response['has_next_page'] ?? false) || empty($items)) break;
                        $offset = (int) ($response['next_offset'] ?? $offset + 100);
                    }

                    $windowStart = $windowEnd;
                }
            }
        }

        return array_values(array_unique($allItemIds));
    }

    public function searchCategories(Request $request)
    {
        return app(ShopeeProductGroupController::class)->searchCategories($request);
    }

    public function categoryLookup(Request $request)
    {
        return app(ShopeeProductGroupController::class)->categoryLookup($request);
    }

    public function fetchAttributes(Request $request, ShopeeClient $client)
    {
        return app(ShopeeProductGroupController::class)->fetchAttributesAjax($request, $client);
    }

    public function brandsForCategory(Request $request, ShopeeClient $client)
    {
        return app(ShopeeProductGroupController::class)->brandsForCategory($request, $client);
    }

    public function listing(int $productId, ShopeeLiveListing $liveListing)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                  ->where('pd.language_id', '=', $langId);
            })
            ->where('p.product_id', $productId)
            ->first([
                'p.product_id', 'pd.name', 'pd.description', 'p.image',
                'p.model', 'p.sku', 'p.price', 'p.quantity', 'p.status',
                'p.weight', 'p.length', 'p.width', 'p.height',
                'p.date_modified',
            ]);

        if (!$product) {
            abort(404, 'Product not found.');
        }
        $product->name = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        $links = ShopeeProductLink::query()->where('product_id', $productId)->get();
        $link = $links->first();

        $live = null;
        $liveError = null;
        if ($link) {
            $setting = ShopeeSetting::defaultStore()?->decrypted();
            $auth = ShopeeSetting::activeAuth($setting);
            if (!$setting || !$auth['complete']) {
                $liveError = 'Shopee settings are incomplete, so the live state could not be fetched.';
            } else {
                $result = $liveListing->fetch($auth, (int) $link->shopee_item_id);
                $live = $result['live'];
                $liveError = $result['error'];
                $mirror = $live !== null ? (string) ($live['item_status'] ?? '')
                    : (str_contains((string) $liveError, 'holds no record') ? 'MISSING' : '');
                if ($mirror !== '') {
                    ShopeeProductLink::query()->whereKey($link->id)
                        ->update(['live_status' => $mirror, 'live_checked_at' => now()]);
                }
                if ($live !== null && (empty($live['has_model']) || ($live['models'] ?? []) !== [])) {
                    \Extensions\shopee\Services\Shopee\ShopeeListingStates::rememberHeld(
                        (int) ($auth['store_id'] ?? 0), $productId, array_column($live['models'] ?? [], 'model_sku')
                    );
                }
            }
        }


        $inherited = app(\Extensions\shopee\Services\Shopee\ShopeeInheritedSettings::class);
        $shown = $inherited->fill(
            $listing ?? (new ShopeeListing())->forceFill(['product_id' => $productId]),
            $inherited->forProducts([$productId])[$productId] ?? null
        )['listing'];

        $categoryId = (int) ($shown->shopee_category_id ?? 0);
        $groupController = app(ShopeeProductGroupController::class);
        if ($categoryId > 0) {
            $groupController->ensureTemplate($categoryId, app(ShopeeClient::class));
        }
        $sheet = $groupController->normalizedAttributesFor($categoryId);
        $savedAttributes = $shown->attribute_values ?? [];

        $readiness = app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class)
            ->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => [], 'gaps' => []];

        $listingState = app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)
            ->forProducts([$productId])[$productId] ?? null;

        $shopeeStoreId = (int) (ShopeeSetting::defaultStore()?->id ?? 0);
        $variationSkus = collect(\App\Integrations\Listings\ListingVariations::sold('shopee', $shopeeStoreId, [$productId], $pfx)[$productId] ?? []);
        $linkedSkus = $links->pluck('sku')->map(fn ($v) => strtolower(trim((string) $v)))->filter()->flip();
        $coverageRows = $variationSkus->map(fn ($sku) => [
            'name' => '',
            'sku' => $sku,
            'linked' => $linkedSkus->has(strtolower($sku)),
        ])->values();
        $coverage = [
            'total' => $coverageRows->count(),
            'linked' => $coverageRows->where('linked', true)->count(),
            'missing' => $coverageRows->where('linked', false)->values(),
        ];

        $initialBrands = $categoryId > 0
            ? \Extensions\shopee\Models\ShopeeBrand::query()
                ->where('category_id', $categoryId)
                ->orderBy('name')
                ->get(['brand_id', 'name'])
            : collect();

        return view('ext-shopee::listings.edit', \App\Integrations\Listings\ListingImages::cardData($productId, $listing->image_order ?? null, $listing ?? null, \App\Services\Media\ListingWatermark::groupTemplateId('shopee_product_groups', 'shopee_product_group_products', 'shopee_product_group_id', $productId, 'shopee_setting_id', (int) ($listing->shopee_setting_id ?? 0) ?: null), 'shopee', \App\Integrations\Listings\ListingStore::id('shopee')) + [
            'listingState' => $listingState,
            'product' => $product,
            'listing' => $listing,
            'initialBrands' => $initialBrands,
            'link' => $link,
            'links' => $links,
            'coverage' => $coverage,
            ...\App\Integrations\Listings\ListingVariations::cardData(
                'shopee', $shopeeStoreId, $productId,
                $live !== null
                    ? collect($live['models'] ?? [])->pluck('model_sku')->all()
                    : ($link ? $links->whereNotNull('shopee_model_id')->pluck('sku')->all() : [])
            ),
            'live' => $live,
            'liveError' => $liveError,
            'attrTemplate' => $sheet['template'],
            'attrRows' => $sheet['attributes'],
            'savedAttributes' => $savedAttributes,
            'readinessMissing' => $readiness['missing'],
            'readiness' => $readiness,
            'logistics' => $this->fetchLogistics(),
            'shown' => $shown,
            'hasVariations' => ShopeeListing::hasVariations($productId),
            'groupBand' => $this->groupBandFor($productId, $shopeeStoreId),
            'storeId' => (int) (\Extensions\shopee\Models\ShopeeSetting::defaultStore()?->id ?? 0),
            'descriptionTemplates' => \App\Models\DescriptionTemplate::forStore('shopee', (int) (\Extensions\shopee\Models\ShopeeSetting::defaultStore()?->id ?? 0)),
        ]);
    }

    private function groupBandFor(int $productId, int $storeId): array
    {
        $current = \App\Integrations\Listings\ListingGroup::current([
            'pivot' => 'shopee_product_group_products', 'fk' => 'shopee_product_group_id',
            'groups' => 'shopee_product_groups', 'storeFk' => 'shopee_setting_id',
            'storeId' => $storeId,
        ], $productId);

        $groups = ShopeeProductGroup::query()
            ->where('shopee_setting_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name', 'shopee_category_id', 'shopee_brand_id', 'logistic_ids', 'markup_percent', 'markup_fixed', 'watermark_template_id']);
        if ($groups->isEmpty()) {
            return ['current' => $current, 'groups' => [], 'values' => []];
        }

        $categoryIds = $groups->pluck('shopee_category_id')->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();
        $brandIds = $groups->pluck('shopee_brand_id')->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();

        $categoryNames = $categoryIds === [] ? collect()
            : ShopeeCategory::query()->whereIn('category_id', $categoryIds)->pluck('name', 'category_id');
        $brands = $brandIds === [] ? collect()
            : \Extensions\shopee\Models\ShopeeBrand::query()->whereIn('brand_id', $brandIds)->get(['brand_id', 'category_id', 'name']);
        $answers = \Extensions\shopee\Models\ShopeeProductGroupAttribute::query()
            ->whereIn('shopee_product_group_id', $groups->pluck('id')->all())
            ->get(['shopee_product_group_id', 'attribute_key', 'value'])
            ->filter(fn ($a) => trim((string) $a->value) !== '')
            ->groupBy('shopee_product_group_id');

        $values = [];
        foreach ($groups as $g) {
            $cid = (int) ($g->shopee_category_id ?? 0);
            $bid = (int) ($g->shopee_brand_id ?? 0);
            $categoryName = $cid > 0 ? $categoryNames->get($cid) : null;
            $brandName = null;
            if ($bid > 0) {
                $brand = $brands->first(fn ($b) => (int) $b->brand_id === $bid && (int) $b->category_id === $cid)
                    ?? $brands->firstWhere('brand_id', $bid);
                $brandName = $brand ? (string) $brand->name : '';
            }
            $values[(int) $g->id] = [
                'category' => $cid > 0 ? ['id' => $cid, 'label' => $categoryName !== null ? $categoryName . ' (' . $cid . ')' : (string) $cid] : null,
                'brand' => $bid > 0 ? ['id' => $bid, 'label' => $brandName] : null,
                'couriers' => array_values(array_filter(array_map('intval', (array) ($g->logistic_ids ?? [])))),
                'markup_percent' => $g->markup_percent !== null ? (float) $g->markup_percent : null,
                'markup_fixed' => $g->markup_fixed !== null ? (float) $g->markup_fixed : null,
                'watermark' => $g->watermark_template_id !== null ? (int) $g->watermark_template_id : null,
                'attributes' => ($answers->get($g->id) ?? collect())->pluck('value', 'attribute_key')->all(),
            ];
        }

        return [
            'current' => $current,
            'groups' => $groups->map(fn ($g) => [
                'id' => (int) $g->id,
                'name' => (string) $g->name,
                'url' => route('ext.shopee.product-groups.products', $g->id),
            ])->values()->all(),
            'values' => $values,
        ];
    }

    public function rereadVariations(int $productId)
    {
        $link = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if (!$link) {
            return redirect()->back()->with('error', 'Product not linked to Shopee. Match a Shopee ID first.');
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            return redirect()->back()->with('error', 'Missing Shopee settings.');
        }

        $pfx = (string) config('catalog.prefix');
        $skus = DB::table($pfx . 'product_option_value')
            ->where('product_id', $productId)->whereNotNull('sku')->where('sku', '!=', '')
            ->pluck('sku')->map(fn ($v) => strtolower(trim((string) $v)))->all();
        $comboSkus = DB::table('product_option_combinations')
            ->where('product_id', $productId)->whereNotNull('sku')->where('sku', '!=', '')
            ->pluck('sku')->map(fn ($v) => strtolower(trim((string) $v)))->all();
        $main = strtolower(trim((string) DB::table($pfx . 'product')->where('product_id', $productId)->value('sku')));
        $allSkus = array_values(array_unique(array_filter(array_merge($skus, $comboSkus, [$main]))));

        $r = app(\Extensions\shopee\Services\Shopee\ShopeeModelLinkRepair::class)
            ->forItem($auth, $productId, (int) $link->shopee_item_id, $allSkus);

        if (isset($r['error'])) {
            $this->listingStates()->recordOutcome($productId, 'Shopee did not answer: ' . $r['error']);
            return redirect()->back()->with('error', 'Shopee did not answer: ' . $r['error'] . ' Nothing was changed.');
        }
        $this->listingStates()->recordOutcome($productId, null);

        $msg = $r['mode'] === 'models'
            ? "Re-read from Shopee: {$r['linked']} variation(s) linked" . ($r['removed'] > 0 ? ", {$r['removed']} stale link(s) removed" : '') . '.'
            : 'Re-read from Shopee: the item has no variations; one item-level link kept.';
        if (!empty($r['unmatched'])) {
            $msg .= ' On Shopee but matching nothing in the catalogue: ' . implode(', ', array_slice($r['unmatched'], 0, 5)) . '.';
        }

        return redirect()->back()->with('status', $msg);
    }

    public function pushMissingVariations(int $productId, ShopeeClient $client)
    {
        $link = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if (!$link) {
            return redirect()->back()->with('error', 'Product not linked to Shopee. Match a Shopee ID first.');
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            return redirect()->back()->with('error', 'Missing Shopee settings.');
        }

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        if (!$listing || ($listing->markup_percent === null && $listing->markup_fixed === null)) {
            $this->listingStates()->recordOutcome($productId, 'Variations not added: this listing has no price rule of its own.');
            return redirect()->back()->with('error', 'Adding variations needs the listing\'s own price rule, and this listing has none. Push once from the form to give it one.');
        }

        $r = app(\Extensions\shopee\Services\Shopee\ShopeeVariationPush::class)->pushMissingModels(
            $client, $auth, (int) $link->shopee_item_id, $productId,
            fn (float $core) => (float) $listing->priceFor($core),
            app(\Extensions\shopee\Services\Shopee\ShopeeItemCreate::class)->tierImageUploader($client, $auth)
        );

        if (!$r['ok']) {
            $this->listingStates()->recordOutcome($productId, (string) $r['message']);
            return redirect()->back()->with('error', $r['message']);
        }
        $this->listingStates()->recordOutcome($productId, null);

        if ($r['added'] > 0) {
            $pfx = (string) config('catalog.prefix');
            $skus = DB::table($pfx . 'product_option_value')
                ->where('product_id', $productId)->whereNotNull('sku')->where('sku', '!=', '')
                ->pluck('sku')->map(fn ($v) => strtolower(trim((string) $v)))
                ->merge(DB::table('product_option_combinations')
                    ->where('product_id', $productId)->whereNotNull('sku')->where('sku', '!=', '')
                    ->pluck('sku')->map(fn ($v) => strtolower(trim((string) $v))))
                ->unique()->values()->all();
            app(\Extensions\shopee\Services\Shopee\ShopeeModelLinkRepair::class)
                ->forItem($auth, $productId, (int) $link->shopee_item_id, $skus);
        }

        return redirect()->back()->with('status', $r['message'] . ($r['added'] > 0 ? ' Links updated.' : ''));
    }

    public function saveListing(Request $request, int $productId, ShopeeClient $client)
    {
        $pfx = (string) config('catalog.prefix');
        $exists = DB::table($pfx . 'product')->where('product_id', $productId)->exists();
        if (!$exists) {
            abort(404, 'Product not found.');
        }

        $data = $request->validate([
            'shopee_category_id' => 'nullable|integer|min:1',
            'shopee_brand_id' => 'nullable|integer|min:0',
            'logistic_ids' => 'nullable|array',
            'logistic_ids.*' => 'integer|min:1',
            'markup_percent' => 'nullable|numeric|min:-100|max:1000',
            'markup_fixed' => 'nullable|numeric|min:-1000000|max:1000000',
            'price' => 'nullable|numeric|min:0|max:1000000000',
            'item_name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:20000',
            'weight' => 'nullable|numeric|min:0.01',
            'description_prefix_id' => 'nullable|integer',
            'watermark_template_id' => 'nullable|integer',
            'watermark_all_images' => 'nullable|boolean',
            'description_suffix_id' => 'nullable|integer',
            'package_length' => 'nullable|numeric|min:0.01|max:1000000',
            'package_width' => 'nullable|numeric|min:0.01|max:1000000',
            'package_height' => 'nullable|numeric|min:0.01|max:1000000',
            'attributes' => 'nullable|array',
            'attributes.*' => 'nullable|string|max:500',
            'image_order' => 'nullable|string|max:20000',
            ...\App\Integrations\Listings\ListingVariations::rules(),
            ...\App\Integrations\Listings\ListingGroup::rules(),
        ]);

        $imageOrder = \App\Integrations\Listings\ListingImages::submitted($productId, $data['image_order'] ?? null);
        $imageOff = \App\Integrations\Listings\ListingImages::submittedOff($productId, $request->input('image_off'));

        $blank = fn ($v) => ($v === null || trim((string) $v) === '') ? null : $v;

        $fields = [
            'shopee_category_id' => $data['shopee_category_id'] ?? null,
            'description_prefix_id' => ((int) ($data['description_prefix_id'] ?? 0)) ?: null,
            ...\App\Integrations\Listings\ListingImages::submittedWatermark($request, 'shopee', \App\Integrations\Listings\ListingStore::id('shopee')),
            ...\App\Integrations\Listings\ListingVideo::submitted($request),
            'image_off' => $imageOff,
            'description_suffix_id' => ((int) ($data['description_suffix_id'] ?? 0)) ?: null,
            'shopee_brand_id' => ((int) ($data['shopee_brand_id'] ?? 0)) > 0 ? (int) $data['shopee_brand_id'] : null,
            'logistic_ids' => array_map('intval', $data['logistic_ids'] ?? []),
            'markup_percent' => $blank($data['markup_percent'] ?? null),
            'markup_fixed' => $blank($data['markup_fixed'] ?? null),
            'price' => ShopeeListing::hasVariations($productId) ? null : $blank($data['price'] ?? null),
            'weight' => $blank($data['weight'] ?? null),
            'package_length' => $blank($data['package_length'] ?? null),
            'package_width' => $blank($data['package_width'] ?? null),
            'package_height' => $blank($data['package_height'] ?? null),
            'attribute_values' => array_filter(
                array_map(fn ($v) => trim((string) $v), $data['attributes'] ?? []),
                fn ($v) => $v !== ''
            ) ?: null,
        ];

        // Only a save that carries image_order may change it; the input is hidden and absent means unchanged.
        if ($request->has('image_order')) {
            $fields['image_order'] = $imageOrder;
        }

        if ($request->has('item_name')) {
            $fields['item_name'] = \App\Integrations\Listings\ListingContent::own($data['item_name'] ?? null);
        }
        if ($request->has('description')) {
            $current = ShopeeListing::query()->where('product_id', $productId)->first();
            $fields['description'] = \App\Integrations\Listings\ListingContent::descriptionToSave(
                $data['description'] ?? null,
                $current !== null
                    ? $current->description
                    : (\App\Integrations\Listings\CatalogCopy::catalog([$productId])[$productId]['description'] ?? null),
                $request->input('description_edited')
            );
        }

        $picked = \App\Integrations\Listings\ListingGroup::submitted($request);
        if ($picked['present']) {
            \App\Integrations\Listings\ListingGroup::assign([
                'pivot' => 'shopee_product_group_products', 'fk' => 'shopee_product_group_id',
                'groups' => 'shopee_product_groups', 'storeFk' => 'shopee_setting_id',
                'storeId' => (int) (ShopeeSetting::defaultStore()?->id ?? 0),
            ], $productId, $picked['id']);
        }

        ShopeeListing::query()->updateOrCreate(['product_id' => $productId], $fields);

        $followed = app(\Extensions\shopee\Services\Shopee\ShopeeInheritedSettings::class)->forProducts([$productId])[$productId] ?? null;
        $savedRow = ShopeeListing::query()->where('product_id', $productId)->first();
        if ($followed && $savedRow) {
            if (is_array($savedRow->attribute_values)) {
                $savedRow->attribute_values = \App\Integrations\Listings\ListingGroup::ownAnswers($savedRow->attribute_values, (array) ($followed['attributes'] ?? [])) ?: null;
            }
            \App\Integrations\Listings\ListingGroup::follow($savedRow, [
                'shopee_category_id' => $followed['category_id'],
                'shopee_brand_id' => $followed['brand_id'],
                'logistic_ids' => $followed['logistic_ids'],
                'watermark_template_id' => \Extensions\shopee\Models\ShopeeProductGroup::query()->whereKey($followed['group_id'])->value('watermark_template_id'),
                'markup_percent' => $followed['markup_percent'],
                'markup_fixed' => $followed['markup_fixed'],
            ], [['markup_percent', 'markup_fixed']]);
        }

        if (($sellHere = \App\Integrations\Listings\ListingVariations::submitted($request)) !== null) {
            \App\Integrations\Listings\ListingVariations::save('shopee', (int) (ShopeeSetting::defaultStore()?->id ?? 0), $productId, $sellHere);
        }

        $back = \App\Support\BackTo::safe($request->input('back'), '');
        $editRoute = $back !== '' ? [$productId, 'back' => $back] : $productId;

        if ($request->boolean('push_after')) {
            if (ShopeeProductLink::query()->where('product_id', $productId)->exists()) {
                $setting = ShopeeSetting::defaultStore()?->decrypted();
                $auth = ShopeeSetting::activeAuth($setting);
                if (!$setting || !$auth['complete']) {
                    $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';

                    return redirect()->route('ext.shopee.listings.edit', $editRoute)
                        ->with('error', "Saved, but not pushed: missing Shopee {$modeLabel} settings.");
                }

                $r = $this->performListingUpdate($productId, $client, $auth);
                $tone = self::updateTone($r);

                return ($tone !== 'error' && $back !== '' ? redirect()->to($back) : redirect()->route('ext.shopee.listings.edit', $editRoute))
                    ->with($tone, $r['message']);
            }

            $readiness = app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class)
                ->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => ['its readiness could not be checked']];

            if (!$readiness['ready']) {
                return redirect()->route('ext.shopee.listings.edit', $editRoute)
                    ->with('error', 'Saved, but not pushed: ' . \App\Integrations\Listings\CatalogGaps::stillNeeds($readiness['missing']));
            }

            return $this->pushDirect($request, $productId, $client);
        }

        return redirect()->route('ext.shopee.listings.edit', $editRoute)
            ->with('status', 'Listing saved.');
    }

    public function pushListingUpdate(int $productId, ShopeeClient $client)
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $r = $this->performListingUpdate($productId, $client, $auth);

        return redirect()->back()->with(self::updateTone($r), $r['message']);
    }

    private static function updateTone(array $r): string
    {
        return (! $r['ok'] || ($r['failure'] ?? null) !== null)
            ? 'error'
            : ((($r['warning'] ?? '') !== '') ? 'warning' : 'status');
    }

    private function performListingUpdate(int $productId, ShopeeClient $client, array $auth): array
    {
        $answer = $this->attemptListingUpdate($productId, $client, $auth);
        if ($answer['ok']) {
            $answer = $this->sendPriceAndStock($productId, $auth, $answer);
        }
        $this->listingStates()->recordOutcome($productId, $answer['ok'] ? ($answer['failure'] ?? null) : $answer['message']);

        return $answer;
    }

    private function sendPriceAndStock(int $productId, array $auth, array $answer): array
    {
        $links = ShopeeProductLink::query()->where('product_id', $productId)->get();
        if ($links->isEmpty()) {
            return $answer;
        }

        $pfx = (string) config('catalog.prefix');
        $push = app(\Extensions\shopee\Services\Shopee\ShopeeStockPricePush::class);
        $left = [];

        $price = $push->push('price', $auth, $links, $pfx, \Extensions\shopee\Commands\ShopeePushPrice::priceRule([$productId]));
        if (($price['ok'] ?? 0) < 1) {
            $left[] = 'the price (' . (($price['last_error'] ?: null) ?? 'Shopee did not say why') . ')';
        }

        if ((int) DB::table($pfx . 'product')->where('product_id', $productId)->value('status') === 1) {
            $stock = $push->push('stock', $auth, $links, $pfx);
            if (($stock['ok'] ?? 0) < 1) {
                $left[] = 'the stock (' . (($stock['last_error'] ?: null) ?? 'Shopee did not say why') . ')';
            }
        }

        if ($left !== []) {
            $answer['message'] = trim($answer['message'] . ' Shopee kept its own ' . implode(' and ', $left) . '.');
        }

        return $answer;
    }

    private function attemptListingUpdate(int $productId, ShopeeClient $client, array $auth): array
    {
        $link = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if (!$link) {
            return ['ok' => false, 'message' => 'This product is not on Shopee yet, so there is nothing to update. Push it first.'];
        }

        $readiness = app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class)
            ->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => []];
        if (!$readiness['ready']) {
            return ['ok' => false, 'message' => \App\Integrations\Listings\CatalogGaps::refusal($readiness, 'update')];
        }

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $product = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                  ->where('pd.language_id', '=', $langId);
            })
            ->where('p.product_id', $productId)
            ->first(['p.product_id', 'pd.name', 'pd.description', 'p.weight', 'p.length', 'p.width', 'p.height']);
        if (!$product) {
            return ['ok' => false, 'message' => 'Product not found.'];
        }

        $inherit = app(\Extensions\shopee\Services\Shopee\ShopeeInheritedSettings::class);
        $listing = $inherit->fill(
            ShopeeListing::query()->where('product_id', $productId)->first() ?? (new ShopeeListing())->forceFill(['product_id' => $productId]),
            $inherit->forProducts([$productId])[$productId] ?? null
        )['listing'];

        $coreName = html_entity_decode((string) ($product->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $coreDescription = \App\Support\Catalog\DescriptionText::of((string) ($product->description ?? ''));

        $content = \App\Integrations\Listings\ListingContent::of(
            $listing, $coreName, $coreDescription, 'shopee', (int) ($listing?->shopee_setting_id ?? 0)
        );
        $itemName = $content['title'];
        $description = $content['description'];

        $payload = [
            'item_id' => (int) $link->shopee_item_id,
            'item_name' => mb_substr($itemName, 0, 255),
            'description' => mb_substr(\App\Support\Catalog\DescriptionText::of($description), 0, 5000),
            'weight' => \App\Integrations\Listings\ParcelPrecision::of($listing?->weight, $product->weight, \App\Integrations\Listings\ParcelPrecision::SHOPEE['weight']),
            'dimension' => [
                'package_length' => \App\Integrations\Listings\ParcelPrecision::of($listing?->package_length, $product->length, \App\Integrations\Listings\ParcelPrecision::SHOPEE['dimension']),
                'package_width' => \App\Integrations\Listings\ParcelPrecision::of($listing?->package_width, $product->width, \App\Integrations\Listings\ParcelPrecision::SHOPEE['dimension']),
                'package_height' => \App\Integrations\Listings\ParcelPrecision::of($listing?->package_height, $product->height, \App\Integrations\Listings\ParcelPrecision::SHOPEE['dimension']),
            ],
        ];
        if ($listing && $listing->shopee_category_id) {
            $payload['category_id'] = (int) $listing->shopee_category_id;
        }
        if ($listing && !empty($listing->logistic_ids)) {
            $payload['logistic_info'] = array_map(
                fn ($lid) => ['logistic_id' => (int) $lid, 'enabled' => true],
                $listing->logistic_ids
            );
        }
        if ($listing && $listing->shopee_category_id) {
            $attributeList = app(ShopeeProductGroupController::class)
                ->attributeListFromSaved($listing->attribute_values ?? [], (int) $listing->shopee_category_id);
            if (!empty($attributeList)) {
                $payload['attribute_list'] = $attributeList;
            }
        }
        $payload['brand'] = app(ShopeeProductGroupController::class)
            ->buildBrandPayload((int) ($listing?->shopee_category_id ?? 0), (int) ($listing?->shopee_brand_id ?? 0));

        // Leave the image block out if uploads return nothing: an empty image_id_list removes every picture.
        $create = app(\Extensions\shopee\Services\Shopee\ShopeeItemCreate::class);
        $up = $create->uploadImages($client, $auth, $create->catalogImagePaths($product, $listing));
        if ($up['error'] !== null) {
            return ['ok' => false, 'message' => 'Update failed: ' . $up['error']];
        }
        if (!empty($up['ids'])) {
            $payload['image'] = ['image_id_list' => $up['ids']];
        }

        $described = (new \Extensions\shopee\Services\Shopee\ShopeeDescription($client))->write(
            $payload, $auth, (int) $link->shopee_item_id,
            \App\Integrations\Listings\ListingContent::of(
                $listing, $coreName, (string) ($product->description ?? ''), 'shopee', (int) ($listing?->shopee_setting_id ?? 0)
            )['description']
        );
        $payload = $described['payload'];
        $descNote = $described['note'] === '' ? '' : ' ' . $described['note'];

        $switches = app(\Extensions\shopee\Services\Shopee\ShopeeVariationSwitches::class);
        $read = $switches->readFor($client, $auth, (int) $link->shopee_item_id, $productId);

        $rename = app(\Extensions\shopee\Services\Shopee\ShopeeVariationRename::class);
        $plan = $rename->plan($client, $auth, (int) $link->shopee_item_id, $productId, $read);
        $renamed = $rename->apply($client, $auth, $plan);
        $warning = $renamed['ok'] ? '' : ' ' . $renamed['message'];

        $followed = $switches->follow(
            $client, $auth, (int) $link->shopee_item_id, $productId,
            \Extensions\shopee\Services\Shopee\ShopeeVariationSwitches::renamed($read, $plan, $renamed),
            fn (float $core) => (float) $listing->priceFor($core),
            $create->tierImageUploader($client, $auth)
        );
        $switchNote = \Extensions\shopee\Services\Shopee\ShopeeVariationSwitches::note($followed);
        $switchNote = $switchNote === '' ? '' : ' ' . $switchNote;

        $path = '/api/v2/product/update_item';
        try {
            $result = $client->shopPost(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                $path, [], $payload
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Update failed: ' . \App\Support\TransportError::plain($e, 'Shopee') . $switchNote];
        }

        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.listings.update_item', 'method' => 'POST',
            'api_path' => $path, 'auth_required' => true,
            'request_params' => $payload,
            'response_status' => $result['status'] ?? null,
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        $apiError = is_array($result['body'] ?? null) ? (string) ($result['body']['error'] ?? '') : '';
        if (!($result['ok'] ?? false) || $apiError !== '') {
            $msg = is_array($result['body'] ?? null)
                ? (string) ($result['body']['message'] ?? ($result['body']['error'] ?? 'no response'))
                : 'no response';
            return ['ok' => false, 'message' => 'Update failed: ' . $msg . $switchNote];
        }

        ShopeeListing::query()->updateOrCreate(
            ['product_id' => $productId],
            [
                'last_pushed_at' => now(),
                'last_push_source' => 'listing',
                'last_push_settings' => [
                    'category_id' => $listing?->shopee_category_id,
                    'logistic_ids' => $listing?->logistic_ids ?? [],
                    'markup_percent' => $listing?->markup_percent,
                    'markup_fixed' => $listing?->markup_fixed,
                ],
            ]
        );

        app(\Extensions\shopee\Services\Shopee\ShopeeLiveListing::class)->confirm(
            $auth,
            ShopeeProductLink::query()->where('product_id', $productId)->pluck('shopee_item_id')->all()
        );

        ActivityLogger::log(
            'updated',
            'Shopee Product',
            $productId,
            'Pushed a content update onto Shopee item ' . $link->shopee_item_id
        );

        return ['ok' => true, 'message' => 'Update pushed.' . $descNote . $warning . $switchNote, 'failure' => $followed['failure'], 'warning' => $described['note']];
    }

    public function toggleListing(Request $request, int $productId, ShopeeClient $client)
    {
        $request->validate(['action' => 'required|in:list,unlist']);
        $unlist = $request->input('action') === 'unlist';

        $link = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if (!$link) {
            return redirect()->back()->with('error', 'This product is not on Shopee, so there is nothing to ' . ($unlist ? 'unlist' : 'relist') . '.');
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        if (!$unlist && ($parcelGap = $this->parcelGapFor([$productId])[$productId] ?? null)) {
            $this->listingStates()->recordOutcome($productId, 'Not published: Shopee will refuse it without ' . $parcelGap . '.');
            return redirect()->back()->with('error',
                'Not published: Shopee will refuse it without ' . $parcelGap
                . '. Add them on the catalog product (Catalog, Products, edit), then publish.');
        }

        $result = $this->unlistItems($client, $auth, [(int) $link->shopee_item_id], $unlist);
        if ($result['error'] !== null || $result['failed'] !== []) {
            $msg = $result['error'] ?? (string) reset($result['failed']);
            $this->listingStates()->recordOutcome($productId, ($unlist ? 'Delist' : 'Publish') . ' failed: ' . $msg);
            return redirect()->back()->with('error', ($unlist ? 'Delist' : 'Publish') . ' failed: ' . $msg);
        }
        $this->listingStates()->recordOutcome($productId, null);

        ActivityLogger::log(
            $unlist ? 'delisted' : 'published',
            'Shopee Product',
            $productId,
            'Item ' . $link->shopee_item_id . ($unlist ? ' delisted from' : ' published on') . ' Shopee'
        );

        return redirect()->back()->with('status', $unlist
            ? 'Delisted. Buyers no longer see this item on Shopee.'
            : 'Published. The item is visible on Shopee again.');
    }


}
