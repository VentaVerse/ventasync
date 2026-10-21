<?php

namespace Extensions\Shopee\Controllers;

use App\Http\Controllers\Controller;

use Extensions\Shopee\Models\ShopeeApiLog;
use Extensions\Shopee\Models\ShopeeCategory;
use Extensions\Shopee\Models\ShopeeItemCache;
use Extensions\Shopee\Models\ShopeeLogistic;
use Extensions\Shopee\Models\ShopeeListing;
use Extensions\Shopee\Models\ShopeeListingLink;
use Extensions\Shopee\Models\ShopeeProductGroup;
use Extensions\Shopee\Models\ShopeeStore;
use App\Services\ActivityLogger;
use Extensions\Shopee\Services\Shopee\ShopeeClient;
use Extensions\Shopee\Services\Shopee\ShopeeLiveListing;
use Extensions\Shopee\Services\ShopeeStoreProducts;
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
        $storeId = (int) (ShopeeStore::defaultStore()?->id ?? 0);
        $scope = DB::query()->fromSub(ShopeeStoreProducts::query(), 'os')
            ->pluck('os.product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        return $this->readinessRich = \App\Channels\Listings\ReadinessCache::resolve(
            'shopee', $storeId, $scope,
            fn (array $ids) => $this->shopeeReadinessStamps($ids, $storeId),
            fn (array $ids) => $this->shopeeReadinessCompute($ids),
        );
    }

    private function shopeeReadinessStamps(array $ids, int $storeId): array
    {
        $prod = DB::table('products')->whereIn('product_id', $ids)->pluck('date_modified', 'product_id');
        $listing = DB::table('ch_shopee_listings')->whereIn('product_id', $ids)
            ->selectRaw('product_id, MAX(updated_at) as mu')->groupBy('product_id')->pluck('mu', 'product_id');
        $link = DB::table('ch_shopee_listing_links')->whereIn('product_id', $ids)
            ->selectRaw('product_id, MAX(updated_at) as mu')->groupBy('product_id')->pluck('mu', 'product_id');
        $group = DB::table('ch_shopee_product_group_products as gp')
            ->join('ch_shopee_product_groups as g', 'g.id', '=', 'gp.shopee_product_group_id')
            ->whereIn('gp.product_id', $ids)->where('g.store_id', $storeId)
            ->selectRaw('gp.product_id, MAX(g.updated_at) as mu')->groupBy('gp.product_id')->pluck('mu', 'product_id');

        $out = [];
        foreach ($ids as $pid) {
            $out[(int) $pid] = md5(($prod[$pid] ?? '') . '|' . ($listing[$pid] ?? '') . '|' . ($link[$pid] ?? '') . '|' . ($group[$pid] ?? ''));
        }

        return $out;
    }

    private function shopeeReadinessCompute(array $ids): array
    {
        $readiness = app(\Extensions\Shopee\Services\Shopee\ShopeeListingReadiness::class);
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
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->get('q', ''));
        $syncStatus = (string) $request->get('sync_status', 'all');
        $syncStatus = ['listed' => 'uploaded', 'not_listed' => 'not_uploaded'][$syncStatus] ?? $syncStatus;
        if ($syncStatus !== 'error') {
            $syncStatus = \App\Channels\Listings\StatusMenu::stand($syncStatus);
        }
        $failedFlag = $request->boolean('failed') || $syncStatus === 'error';
        $changeFlag = $request->boolean('change');
        if ($syncStatus === 'error') {
            $syncStatus = 'all';
        }
        $brandFilter = (string) $request->get('brand', 'all');
        $groupFilter = \App\Channels\Listings\StatusMenu::group($request->get('group'));
        $catalogStatus = (string) $request->get('catalog_status', 'all');

        if ($request->query('list') === 'add') {
            return $this->addCatalog($request);
        }

        $tabMap = ['live' => 'NORMAL', 'unlisted' => 'UNLIST', 'violation' => 'BANNED', 'reviewing' => 'REVIEWING'];
        $shopeeTab = (string) $request->get('shopee_tab', 'all');
        if (!array_key_exists($shopeeTab, $tabMap)) {
            $shopeeTab = 'all';
        }

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        $liveCounts = null;
        $liveTabError = null;
        $liveCheckedAt = null;
        if ($store && $auth['complete']) {
            $liveCounts = [];
            $liveCheckedAt = ShopeeListingLink::query()->max('live_checked_at');
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
            'brand' => 'm.name',
        ];
        $sort = (string) $request->get('sort', 'id');
        if (!array_key_exists($sort, $sortColumns)) {
            $sort = 'id';
        }
        $dir = strtolower((string) $request->get('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $groupMapping = $this->getGroupProductMapping();

        $query = DB::table('products as p')
            ->leftJoin('product_descriptions as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                  ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin('brands as m', 'p.brand_id', '=', 'm.brand_id')
            ->select(
                'p.product_id',
                'pd.name',
                'p.image',
                'p.sku',
                'p.price',
                'p.quantity',
                'p.status',
                'm.name as brand_name'
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

        if ($brandFilter !== 'all') {
            $query->where('p.brand_id', (int) $brandFilter);
        }

        if ($catalogStatus === 'enabled') {
            $query->where('p.status', 1);
        } elseif ($catalogStatus === 'disabled') {
            $query->where('p.status', 0);
        } else {
            $query->where(function ($sub) {
                $sub->where('p.status', 1)
                    ->orWhereIn('p.product_id', \Extensions\Shopee\Models\ShopeeListingLink::query()
                        ->whereNotNull('shopee_item_id')->select('product_id'));
            });
        }

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%')
                    ->orWhereIn('p.product_id', \Extensions\Shopee\Models\ShopeeListing::query()
                        ->forStore(ShopeeStore::defaultStore())
                        ->where('item_name', 'like', '%' . $q . '%')
                        ->select('product_id'));
            });
        }

        $linkedIds = ShopeeListingLink::query()->pluck('product_id')
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

        $troubleFilter = \App\Channels\Listings\ListingTrouble::asked($request->query('state'));
        $troubleIds = $troubleFilter === null ? null
            : app(\Extensions\Shopee\Services\Shopee\ShopeeListingStates::class)->productIdsIn($troubleFilter);
        $applyTrouble = fn ($query) => $troubleIds === null ? $query : $query->whereIn('p.product_id', $troubleIds ?: [0]);

        $tabProductIds = ($shopeeTab !== 'all' && $liveCounts !== null)
            ? ShopeeListingLink::query()->where('live_status', $tabMap[$shopeeTab])
                ->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all()
            : null;
        $applyTab = fn ($query) => $tabProductIds === null ? $query : $query->whereIn('p.product_id', $tabProductIds ?: [0]);
        $tabCounts = function ($query) use ($tabMap): array {
            $byStatus = ShopeeListingLink::query()
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
            'name' => \App\Channels\Listings\StatusMenu::UNGROUPED,
            'count' => $applyGroup(clone $railBase, 'none')->count(),
        ];

        $tabBase = $applyTrouble($applyMenu(clone $query));
        $catalogTotal = (clone $tabBase)->count();
        if ($liveCounts !== null) {
            $liveCounts = $tabCounts($tabBase);
        }
        $listedTotal = (int) ShopeeListingLink::query()->distinct()->count('product_id');

        $applyMenu($query);
        $applyTrouble($query);
        $applyTab($query);

        $storeId = \App\Channels\Listings\ListingStore::id('shopee');
        $sortMenu = \Extensions\Shopee\Services\ShopeeListingSort::columns($storeId);
        $order = \App\Channels\Listings\ListingSort::chosen($request, 'shopee.listings.' . $storeId);
        if ($request->filled('sort')) {
            $query->orderBy($sortColumns[$sort], $dir);
            if ($sortColumns[$sort] !== 'p.product_id') {
                $query->orderBy('p.product_id', 'asc');
            }
        } else {
            \Extensions\Shopee\Services\ShopeeListingSort::join($query, $storeId);
            \App\Channels\Listings\ListingSort::apply($query, $order, $sortMenu);
        }

        $products = $query->paginate(50)->withQueryString();

        foreach ($products as $row) {
            if (isset($row->name)) {
                $row->name = html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        $pageIds = $products->pluck('product_id')->map(fn($v) => (int) $v)->all();
        $pageListings = $pageIds === [] ? collect() : \Extensions\Shopee\Models\ShopeeListing::query()
            ->forStore(ShopeeStore::defaultStore())
            ->whereIn('product_id', $pageIds)
            ->get();
        $rowTitles = \App\Channels\Listings\ListingContent::rowTitles($products, $pageListings);
        $pageListings = $pageListings->keyBy(fn ($l) => (int) $l->product_id);
        $withVariations = ShopeeListing::withVariations($pageIds);
        $rowPrices = collect($products->items())->mapWithKeys(fn ($p) => [(int) $p->product_id => ($pageListings->get((int) $p->product_id) ?? new ShopeeListing())
            ->startingPrice((float) $p->price, isset($withVariations[(int) $p->product_id]))])->all();
        $shopeeLinks = collect();
        if (!empty($pageIds)) {
            $shopeeLinks = ShopeeListingLink::query()
                ->whereIn('product_id', $pageIds)
                ->get()
                ->groupBy(fn($l) => (int) $l->product_id);
        }

        $variationRowsByProductId = \App\Support\VariationRows::forListing($pageIds, 'shopee', (int) (ShopeeStore::defaultStore()?->id ?? 0));

        if ($liveCounts !== null && $shopeeLinks->isNotEmpty()) {
            $fillError = null;
            $filled = app(\Extensions\Shopee\Services\Shopee\ShopeeLiveListing::class)
                ->fillBlanks($auth, $shopeeLinks->flatten(), $fillError);
            if ($fillError !== null) {
                session()->now('error', \App\Channels\Listings\BlankFillRefusal::sentence('Shopee', $fillError));
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

        $listingStates = app(\Extensions\Shopee\Services\Shopee\ShopeeListingStates::class)
            ->forProducts(array_map('intval', $pageIds));

        $allBrands = DB::table('brands')
            ->orderBy('name')
            ->pluck('name', 'brand_id');

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
            ? app(\Extensions\Shopee\Services\Shopee\ShopeeListingReadiness::class)->forProducts($unlistedIds)
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
            'orderVariations' => \App\Channels\Listings\ListingSort::options(array_keys($sortMenu)),
            'syncStatus' => $syncStatus,
            'brandFilter' => $brandFilter,
            'groupFilter' => $groupFilter,
            'catalogStatus' => $catalogStatus,
            'allBrands' => $allBrands,
            'allGroups' => $allGroups,
            'groupsByProductId' => $pageGroupMap,
            'variationRowsByProductId' => $variationRowsByProductId,
            'shopeeTab' => $shopeeTab,
            'liveCounts' => $liveCounts,
            'liveTabError' => $liveTabError,
            'liveStatuses' => $liveStatuses,
            'listingStates' => $listingStates,
            'rowErrors' => app(\Extensions\Shopee\Services\Shopee\ShopeeListingStates::class)->errors(array_map('intval', $pageIds)),
            'catalogTotal' => $catalogTotal,
            'listedTotal' => $listedTotal,
            'liveCheckedAt' => $liveCheckedAt,
            'failedFlag' => $failedFlag,
            'changeFlag' => $changeFlag,
            'statusMenu' => \App\Channels\Listings\StatusMenu::build([
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

    private function addCatalog(Request $request)
    {
        $langId = (int) config('catalog.default_language_id');

        $q = trim((string) $request->query('q', ''));
        $brandFilter = (string) $request->query('brand', 'all');
        $groupFilter = (string) $request->query('group', 'all');
        $onStore = (string) $request->query('on_store', 'all');

        $sort = (string) $request->query('sort', 'id');
        $sortColumns = ['id' => 'p.product_id', 'product' => 'pd.name', 'quantity' => 'p.quantity', 'price' => 'p.price'];
        if (! array_key_exists($sort, $sortColumns)) {
            $sort = 'id';
        }
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = DB::table('products as p')
            ->leftJoin('product_descriptions as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->leftJoin('brands as m', 'p.brand_id', '=', 'm.brand_id')
            ->leftJoinSub(ShopeeStoreProducts::query(), 'os', 'os.product_id', '=', 'p.product_id')
            ->select('p.product_id', 'pd.name', 'p.image', 'p.sku', 'p.price', 'p.quantity', 'p.status', 'm.name as brand_name', 'os.product_id as on_store_pid');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%');
            });
        }
        if ($brandFilter !== 'all') {
            $query->where('p.brand_id', (int) $brandFilter);
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
            'allBrands' => DB::table('brands')->orderBy('name')->pluck('name', 'brand_id'),
            'allGroups' => ShopeeProductGroup::query()->orderBy('name')->pluck('name', 'id'),
            'q' => $q,
            'brandFilter' => $brandFilter,
            'groupFilter' => $groupFilter,
            'onStore' => $onStore,
            'sort' => $sort,
            'dir' => $dir,
            'catalogTotal' => (int) DB::table('products')->count(),
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
        $exists = DB::table('products')->where('product_id', $productId)->exists();
        abort_unless($exists, 404);

        $wasOn = ShopeeStoreProducts::has($productId);
        ShopeeListing::firstOrCreate(['product_id' => $productId]);

        $groupId = (int) $request->input('group', 0);
        if ($groupId > 0) {
            \App\Channels\OneGroupRule::place('ch_shopee_product_group_products', 'shopee_product_group_id', 'ch_shopee_product_groups', 'store_id', \App\Channels\Listings\ListingStore::id('shopee'), $groupId, $productId);
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'product_id' => $productId, 'added' => ! $wasOn]);
        }

        return redirect()->back()->with('status', 'Added to this store. Configure and push it from This store whenever you are ready.');
    }

    public function catalogSearch(Request $request)
    {
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->query('q', ''));
        $showAll = $request->boolean('all');
        $limit = 25;

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

        $itemIds = ShopeeListingLink::query()->where('product_id', $productId)
            ->pluck('shopee_item_id')->filter()->unique()->values();

        ShopeeListingLink::unlinkProduct($productId, (int) app('shopee.route-store')->id);
        \Extensions\Shopee\Models\ShopeeProductGroupProduct::query()
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
            ShopeeListingLink::unlinkProduct($pid, (int) app('shopee.route-store')->id);
            \Extensions\Shopee\Models\ShopeeProductGroupProduct::query()->where('product_id', $pid)
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

        $valid = DB::table('products')->whereIn('product_id', $ids)->pluck('product_id')->all();
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

    public function refreshStatus(Request $request, \Extensions\Shopee\Services\Shopee\ShopeeLiveListing $live)
    {
        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            return redirect()->back()->with('error', 'Missing Shopee settings.');
        }

        $result = $live->refreshMirror($auth);
        if ($result['error'] !== null) {
            return redirect()->back()->with('error', 'Shopee did not answer: ' . $result['error'] . ' The statuses shown are from the last refresh.');
        }
        $checked = app(\Extensions\Shopee\Services\Shopee\ShopeeLinkCheck::class)->runNext(app(ShopeeClient::class), $auth, (int) $store->id);
        $sheets = app(\Extensions\Shopee\Services\Shopee\ShopeeSheetReads::class)
            ->readMissing($auth, app(ShopeeClient::class), \App\Channels\Listings\SheetReads::PER_PRESS);

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
                ShopeeListingLink::query()->whereIn('shopee_item_id', $done)
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

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            return redirect()->back()->with('error', 'Missing Shopee settings.');
        }

        $ids = array_values(array_unique(array_map('intval', (array) $request->input('product_ids'))));
        $links = ShopeeListingLink::query()->whereIn('product_id', $ids)->get();
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
                    . '. Add weight and size on the Master Catalog products first.');
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
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table('products as p')
            ->leftJoin('product_descriptions as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->where('p.product_id', $productId)
            ->first(['p.product_id', 'pd.name', 'pd.description', 'p.sku', 'p.price', 'p.image']);
        if (!$product) {
            return response()->json(['ok' => false, 'message' => 'Product not found.'], 404);
        }

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        $readiness = app(\Extensions\Shopee\Services\Shopee\ShopeeListingReadiness::class)
            ->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => []];

        $categoryName = $listing?->shopee_category_id
            ? \Extensions\Shopee\Models\ShopeeCategory::query()->where('category_id', $listing->shopee_category_id)->value('name')
            : null;
        $courierNames = !empty($listing?->logistic_ids)
            ? \Extensions\Shopee\Models\ShopeeLogistic::query()->whereIn('logistics_channel_id', $listing->logistic_ids)->pluck('logistics_channel_name')->all()
            : [];
        $imageCount = count(\App\Support\Catalog\ProductImages::paths($productId, $listing?->image_order));
        $variationCount = DB::table('product_variations')->where('product_id', $productId)->count();

        $content = \App\Channels\Listings\ListingContent::of(
            $listing,
            html_entity_decode((string) $product->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            \App\Support\Catalog\DescriptionText::of((string) ($product->description ?? '')),
            'shopee',
            (int) ($listing?->store_id ?? 0)
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
                $description = \App\Channels\Listings\ListingContent::descriptionToSave(
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

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
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
        $langId = (int) config('catalog.default_language_id');

        $existingLink = ShopeeListingLink::query()->where('product_id', $productId)->first();
        if ($existingLink) {
            return ['ok' => false, 'message' => 'Push failed: Product already listed on Shopee (Item ID: ' . $existingLink->shopee_item_id . ').'];
        }

        $gateCategory = (int) (ShopeeListing::query()->where('product_id', $productId)->value('shopee_category_id') ?? 0);
        if ($gateCategory > 0) {
            app(ShopeeProductGroupController::class)->ensureTemplate($gateCategory, $client);
        }
        $readiness = app(\Extensions\Shopee\Services\Shopee\ShopeeListingReadiness::class)
            ->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => []];
        if (!$readiness['ready']) {
            return ['ok' => false, 'message' => \App\Channels\Listings\CatalogGaps::refusal($readiness)];
        }

        $verdict = app(\Extensions\Shopee\Services\Shopee\ShopeeLinkCheck::class)
            ->reconcile($client, $auth, $productId);

        if (in_array($verdict['state'], ['confirmed', 'adopted', 'repointed'], true)) {
            return ['ok' => false, 'message' => 'Not pushed: Shopee already holds this product as item ' . $verdict['item_id']
                . '. It is linked here now, so use Push update to send these settings onto it.'];
        }
        if ($verdict['state'] === 'taken' || $verdict['state'] === 'unreachable') {
            return ['ok' => false, 'message' => 'Not pushed: ' . $verdict['message']];
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
                'p.brand_id',
            ]);

        if (!$product) {
            return ['ok' => false, 'message' => 'Push failed: Product not found.'];
        }

        $saved = ShopeeListing::query()->where('product_id', $productId)->first();
        $listing = $saved;

        if (!$listing || !$listing->pushable()) {
            return ['ok' => false, 'message' => 'Push failed: this product has no Shopee listing settings yet. '
                . 'Open its listing (Products → Open listing) and save its category and couriers, or apply a listing template.'];
        }

        $logisticIds = $listing->logistic_ids ?? [];

        $itemName = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $description = \App\Support\Catalog\DescriptionText::of((string) ($product->description ?? ''));

        $create = app(\Extensions\Shopee\Services\Shopee\ShopeeItemCreate::class);
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

        ShopeeListingLink::create([
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
            ],
        ])->save();

        app(\Extensions\Shopee\Services\Shopee\ShopeeLiveListing::class)->confirm($auth, [(int) $itemId]);

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

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';

            return redirect()->back()->with('error', "Push failed: Missing Shopee {$modeLabel} settings.");
        }

        $answer = \App\Channels\Push\BulkPushRun::over(
            $productIds,
            'Shopee',
            function (int $productId) use ($auth, $client) {
                if (ShopeeListingLink::query()->where('product_id', $productId)->exists()) {
                    $r = $this->performListingUpdate($productId, $client, $auth);

                    return ['ok' => $r['ok'] && ($r['failure'] ?? null) === null, 'message' => (string) $r['message'],
                        'note' => trim((string) preg_replace('/^Update pushed\.\s*/', '', (string) $r['message'])) ?: null, 'warning' => ! empty($r['warning'])];
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
        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $links = ShopeeListingLink::query()->where('product_id', $productId)->get();
        if ($links->isEmpty()) {
            return redirect()->back()->with('error', 'Product not linked to Shopee. Sync Product IDs first.');
        }

        $catalogStatus = DB::table('products')->where('product_id', $productId)->value('status');
        if ((int) $catalogStatus === 0) {
            return redirect()->back()->with('error', 'Cannot sync quantity for a disabled product. Enable it first.');
        }

        $results = app(\Extensions\Shopee\Services\Shopee\ShopeeStockPricePush::class)
            ->push('stock', $auth, $links);
        $clause = \Extensions\Shopee\Services\Shopee\ShopeeStockPricePush::ledgerClause($results);

        $msg = $results['ok'] > 0
            ? "Stock synced to Shopee ({$results['ok']} model(s))." . $clause
            : 'Sync qty failed: ' . (($results['last_error'] ?: null) ?? 'Unknown error') . $clause;

        return redirect()->back()->with($results['ok'] > 0 ? 'status' : 'error', $msg);
    }

    public function syncPrice(int $productId, ShopeeClient $client)
    {
        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $links = ShopeeListingLink::query()->where('product_id', $productId)->get();
        if ($links->isEmpty()) {
            return redirect()->back()->with('error', 'Product not linked to Shopee. Sync Product IDs first.');
        }
        $results = app(\Extensions\Shopee\Services\Shopee\ShopeeStockPricePush::class)
            ->push('price', $auth, $links, \Extensions\Shopee\Commands\ShopeePushPrice::priceRule([$productId]));
        $clause = \Extensions\Shopee\Services\Shopee\ShopeeStockPricePush::ledgerClause($results);

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
        $deleted = ShopeeListingLink::unlinkProduct($productId, (int) app('shopee.route-store')->id);

        if ($deleted === 0) {
            return redirect()->back()->with('error', 'Product was not linked to Shopee.');
        }

        return redirect()->back()->with('status', "Unlinked product from Shopee ($deleted link(s) removed, cache cleared). You can now re-sync.");
    }

    public function deleteFromShopee(int $productId, ShopeeClient $client)
    {
        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} credentials. Configure Shopee settings first.");
        }

        $link = ShopeeListingLink::query()->where('product_id', $productId)->first();
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
            ShopeeListingLink::query()->where('product_id', $productId)->delete();
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

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} credentials. Configure Shopee settings first.");
        }

        $okCount = 0;
        $errCount = 0;
        $imageNotes = [];
        $path = '/api/v2/product/delete_item';

        foreach ($productIds as $productId) {
            $productId = (int) $productId;
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
                ShopeeListingLink::query()->where('product_id', $productId)->delete();
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
        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $allItemIds = $this->fetchAllShopeeItemIds($client, $auth, 730, 'shopee.products.rebuild_cache');

        if (empty($allItemIds)) {
            return redirect()->back()->with('error', 'No items found on Shopee.');
        }

        DB::table('ch_shopee_item_cache')
            ->when(app()->bound('shopee.route-store'), fn ($q) => $q->where('store_id', app('shopee.route-store')->id))
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
        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return [null, "Missing Shopee {$modeLabel} settings."];
        }

        $allItemIds = $this->fetchLiveItemIds($client, $auth);
        if ($allItemIds === null) {
            return [null, 'Shopee did not answer the fetch. Check the connection and try again.'];
        }
        if ($allItemIds === []) {
            return [null, 'No items found on Shopee.'];
        }

        $linkedCombos = ShopeeListingLink::query()->get(['shopee_item_id', 'shopee_model_id']);
        $linkedSet = [];
        foreach ($linkedCombos as $lc) {
            $key = (string) $lc->shopee_item_id . ':' . (string) ($lc->shopee_model_id ?? '');
            $linkedSet[$key] = true;
        }

        $catalogSkus = [];
        $catalogProducts = DB::table('products')->whereNotNull('sku')->where('sku', '!=', '')->get(['sku']);
        foreach ($catalogProducts as $ep) {
            if ($ep->sku !== null && $ep->sku !== '') $catalogSkus[strtolower(trim($ep->sku))] = true;
        }
        $optSkus = DB::table('product_variation_values')
            ->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku');
        foreach ($optSkus as $os) {
            $catalogSkus[strtolower(trim($os))] = true;
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
                        if ($modelSku !== '' && isset($catalogSkus[strtolower($modelSku)])) continue;
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
                    if ($itemSku !== '' && isset($catalogSkus[strtolower($itemSku)])) continue;

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

    public function checkAgainstShopee(Request $request, ShopeeClient $client, \Extensions\Shopee\Services\Shopee\ShopeeLinkCheck $check)
    {
        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $productIds = $this->parseProductIds($request);
        if (empty($productIds)) {
            $productIds = $check->storeProductIds((int) $store->id);
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

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $enabledIds = DB::table('products')
            ->whereIn('product_id', $ids)
            ->where('status', 1)
            ->pluck('product_id')
            ->toArray();
        $disabledCount = count($ids) - count($enabledIds);

        $links = ShopeeListingLink::query()->whereIn('product_id', $enabledIds)->get();

        $skip = count($enabledIds) - $links->pluck('product_id')->unique()->count();

        $results = app(\Extensions\Shopee\Services\Shopee\ShopeeStockPricePush::class)
            ->push('stock', $auth, $links);
        $ok = $results['ok'];
        $err = $results['err'];

        $msg = "Bulk Sync Qty: {$ok} success, {$err} error, {$skip} skipped (not linked)";
        if ($disabledCount > 0) {
            $msg .= ", {$disabledCount} skipped (disabled)";
        }
        $msg .= '.' . \Extensions\Shopee\Services\Shopee\ShopeeStockPricePush::ledgerClause($results);

        return redirect()->back()->with('status', $msg);
    }

    public function bulkSyncPrice(Request $request, ShopeeClient $client)
    {
        $ids = $this->parseProductIds($request);
        if (empty($ids)) {
            return redirect()->back()->with('error', 'No products selected.');
        }

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }
        $links = ShopeeListingLink::query()->whereIn('product_id', $ids)->get();

        $skip = count($ids) - $links->pluck('product_id')->unique()->count();

        $results = app(\Extensions\Shopee\Services\Shopee\ShopeeStockPricePush::class)
            ->push('price', $auth, $links, \Extensions\Shopee\Commands\ShopeePushPrice::priceRule($ids));

        $summary = "Bulk Sync Price: {$results['ok']} success, {$results['err']} error, {$skip} skipped (not linked).";

        if (!empty($results['skipped_products'])) {
            $summary .= ' ' . count($results['skipped_products']) . ' without a listing price rule: '
                . implode('; ', array_slice(array_values($results['skipped_products']), 0, 5));
        }
        $summary .= \Extensions\Shopee\Services\Shopee\ShopeeStockPricePush::ledgerClause($results);

        return redirect()->back()->with('status', $summary);
    }

    public function importOne(Request $request, \Extensions\Shopee\Services\Shopee\ShopeeItemImport $import)
    {
        $data = $request->validate(['ref' => 'required|integer|min:1']);

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $r = $import->import($auth, (int) $data['ref']);

        return redirect()->back()->with($r['ok'] ? 'status' : 'error', $r['message']);
    }

    public function importSelected(Request $request, \Extensions\Shopee\Services\Shopee\ShopeeItemImport $import)
    {
        $data = $request->validate(['refs' => 'required|array|min:1', 'refs.*' => 'integer|min:1']);

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
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
        if (! DB::table('products')->where('product_id', (int) $data['product_id'])->exists()) {
            return redirect()->back()->with('error', 'Master Catalog product not found.');
        }

        $storeId = (int) (ShopeeStore::defaultStore()?->id ?? 0);
        $scoped = fn () => ShopeeListingLink::query()->whereNull('shopee_model_id')->when($storeId > 0, fn ($q) => $q->where('store_id', $storeId));
        $held = $scoped()->where('product_id', (int) $data['product_id'])->whereNotNull('shopee_item_id')->first();
        if ($held && (string) $held->shopee_item_id !== (string) $data['ref']) {
            return redirect()->back()->with('error', 'Master Catalog product #' . $data['product_id'] . ' is already linked to Shopee item ' . $held->shopee_item_id . '. Unlink it first.');
        }
        $taken = $scoped()->where('shopee_item_id', (string) $data['ref'])->where('product_id', '!=', (int) $data['product_id'])->first();
        if ($taken) {
            return redirect()->back()->with('error', 'Shopee item ' . $data['ref'] . ' is already linked to Master Catalog product #' . $taken->product_id . '. Unlink that product first.');
        }
        if (! $held) {
            ShopeeListingLink::create([
                'product_id' => (int) $data['product_id'],
                'shopee_item_id' => (string) $data['ref'],
                'shopee_model_id' => null,
                'sku' => trim((string) ($data['sku'] ?? '')),
            ]);
        }

        return redirect()->back()->with('status', 'Linked Shopee item #' . $data['ref'] . ' to Master Catalog product #' . $data['product_id'] . '.');
    }

    public function searchCatalogProducts(Request $request)
    {
        return response()->json(\App\Support\CatalogPickerSearch::items((string) $request->get('q', ''), function (array $ids) {
            $storeId = (int) (ShopeeStore::defaultStore()?->id ?? 0);

            return ShopeeListingLink::query()->whereIn('product_id', $ids)->whereNotNull('shopee_item_id')
                ->when($storeId > 0, fn ($q) => $q->where('store_id', $storeId))
                ->get(['product_id', 'shopee_item_id'])
                ->mapWithKeys(fn ($l) => [(int) $l->product_id => 'Shopee item ' . $l->shopee_item_id])->all();
        }));
    }


    private function parcelGapFor(array $productIds): array
    {
        $out = [];
        $rows = DB::table('products')->whereIn('product_id', $productIds)
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

    private function listingStates(): \Extensions\Shopee\Services\Shopee\ShopeeListingStates
    {
        return app(\Extensions\Shopee\Services\Shopee\ShopeeListingStates::class);
    }

    private function parseProductIds(Request $request): array
    {
        $ids = $request->input('product_ids', []);
        if (!is_array($ids)) $ids = [];
        return array_values(array_unique(array_filter(array_map(fn($v) => (int) $v, $ids), fn($v) => $v > 0)));
    }

    private function matchProductBySku( string $sku): int
    {
        if ($sku === '') return 0;

        $pov = DB::table('product_variation_values')->where('sku', $sku)->first(['product_id']);
        if ($pov) return (int) $pov->product_id;

        $prod = DB::table('products')->where('sku', $sku)->first(['product_id']);
        if ($prod) return (int) $prod->product_id;

        return 0;
    }

    private function getGroupProductMapping(): array
    {
        if (!ShopeeProductGroup::query()->exists()) {
            return ['ids' => null, 'map' => []];
        }

        $rows = DB::table('ch_shopee_product_group_products as pgp')
            ->join('ch_shopee_product_groups as g', 'g.id', '=', 'pgp.shopee_product_group_id')
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
        return DB::table('ch_shopee_product_group_products')
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
        $langId = (int) config('catalog.default_language_id');

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
                'p.date_modified',
            ]);

        if (!$product) {
            abort(404, 'Product not found.');
        }
        $product->name = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        $links = ShopeeListingLink::query()->where('product_id', $productId)->get();
        $link = $links->first();

        $live = null;
        $liveError = null;
        if ($link) {
            $store = ShopeeStore::defaultStore()?->decrypted();
            $auth = ShopeeStore::activeAuth($store);
            if (!$store || !$auth['complete']) {
                $liveError = 'Shopee settings are incomplete, so the live state could not be fetched.';
            } else {
                $result = $liveListing->fetch($auth, (int) $link->shopee_item_id);
                $live = $result['live'];
                $liveError = $result['error'];
                $mirror = $live !== null ? (string) ($live['item_status'] ?? '')
                    : (str_contains((string) $liveError, 'holds no record') ? 'MISSING' : '');
                if ($mirror !== '') {
                    ShopeeListingLink::query()->whereKey($link->id)
                        ->update(['live_status' => $mirror, 'live_checked_at' => now()]);
                }
                if ($live !== null && (empty($live['has_model']) || ($live['models'] ?? []) !== [])) {
                    \Extensions\Shopee\Services\Shopee\ShopeeListingStates::rememberHeld(
                        (int) ($auth['store_id'] ?? 0), $productId, array_column($live['models'] ?? [], 'model_sku')
                    );
                }
            }
        }

        $shown = $listing ?? (new ShopeeListing())->forceFill(['product_id' => $productId]);

        $categoryId = (int) ($shown->shopee_category_id ?? 0);
        $groupController = app(ShopeeProductGroupController::class);
        if ($categoryId > 0) {
            $groupController->ensureTemplate($categoryId, app(ShopeeClient::class));
        }
        $sheet = $groupController->normalizedAttributesFor($categoryId);
        $savedAttributes = $shown->attribute_values ?? [];

        $readiness = app(\Extensions\Shopee\Services\Shopee\ShopeeListingReadiness::class)
            ->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => [], 'gaps' => []];

        $listingState = app(\Extensions\Shopee\Services\Shopee\ShopeeListingStates::class)
            ->forProducts([$productId])[$productId] ?? null;

        $shopeeStoreId = (int) (ShopeeStore::defaultStore()?->id ?? 0);
        $variationSkus = collect(\App\Channels\Listings\ListingVariations::sold('shopee', $shopeeStoreId, [$productId])[$productId] ?? []);
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
            ? \Extensions\Shopee\Models\ShopeeBrand::query()
                ->where('category_id', $categoryId)
                ->orderBy('name')
                ->get(['brand_id', 'name'])
            : collect();

        return view('ext-shopee::listings.edit', \App\Channels\Listings\ListingImages::cardData($productId, $listing->image_order ?? null, $listing ?? null, 'shopee', \App\Channels\Listings\ListingStore::id('shopee')) + [
            'listingState' => $listingState,
            'product' => $product,
            'listing' => $listing,
            'initialBrands' => $initialBrands,
            'link' => $link,
            'links' => $links,
            'coverage' => $coverage,
            ...\App\Channels\Listings\ListingVariations::cardData(
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
            'storeId' => (int) (\Extensions\Shopee\Models\ShopeeStore::defaultStore()?->id ?? 0),
            'descriptionTemplates' => \App\Models\DescriptionTemplate::forStore('shopee', (int) (\Extensions\Shopee\Models\ShopeeStore::defaultStore()?->id ?? 0)),
        ]);
    }

    private function groupBandFor(int $productId, int $storeId): array
    {
        return \App\Channels\Listings\ListingGroup::band([
            'pivot' => 'ch_shopee_product_group_products', 'fk' => 'shopee_product_group_id',
            'groups' => 'ch_shopee_product_groups', 'storeFk' => 'store_id', 'storeId' => $storeId,
        ], $productId, 'ext.shopee.product-groups.edit');
    }

    public function rereadVariations(int $productId)
    {
        $link = ShopeeListingLink::query()->where('product_id', $productId)->first();
        if (!$link) {
            return redirect()->back()->with('error', 'Product not linked to Shopee. Match a Shopee ID first.');
        }

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            return redirect()->back()->with('error', 'Missing Shopee settings.');
        }
        $skus = DB::table('product_variation_values')
            ->where('product_id', $productId)->whereNotNull('sku')->where('sku', '!=', '')
            ->pluck('sku')->map(fn ($v) => strtolower(trim((string) $v)))->all();
        $comboSkus = DB::table('product_variations')
            ->where('product_id', $productId)->whereNotNull('sku')->where('sku', '!=', '')
            ->pluck('sku')->map(fn ($v) => strtolower(trim((string) $v)))->all();
        $main = strtolower(trim((string) DB::table('products')->where('product_id', $productId)->value('sku')));
        $allSkus = array_values(array_unique(array_filter(array_merge($skus, $comboSkus, [$main]))));

        $r = app(\Extensions\Shopee\Services\Shopee\ShopeeModelLinkRepair::class)
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
            $msg .= ' On Shopee but matching nothing in the catalog: ' . implode(', ', array_slice($r['unmatched'], 0, 5)) . '.';
        }

        return redirect()->back()->with('status', $msg);
    }

    public function pushMissingVariations(int $productId, ShopeeClient $client)
    {
        $link = ShopeeListingLink::query()->where('product_id', $productId)->first();
        if (!$link) {
            return redirect()->back()->with('error', 'Product not linked to Shopee. Match a Shopee ID first.');
        }

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            return redirect()->back()->with('error', 'Missing Shopee settings.');
        }

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        if (!$listing || ($listing->markup_percent === null && $listing->markup_fixed === null)) {
            $this->listingStates()->recordOutcome($productId, 'Variations not added: this listing has no price rule of its own.');
            return redirect()->back()->with('error', 'Adding variations needs the listing\'s own price rule, and this listing has none. Push once from the form to give it one.');
        }

        $r = app(\Extensions\Shopee\Services\Shopee\ShopeeVariationPush::class)->pushMissingModels(
            $client, $auth, (int) $link->shopee_item_id, $productId,
            fn (float $core) => (float) $listing->priceFor($core),
            app(\Extensions\Shopee\Services\Shopee\ShopeeItemCreate::class)->tierImageUploader($client, $auth)
        );

        if (!$r['ok']) {
            $this->listingStates()->recordOutcome($productId, (string) $r['message']);
            return redirect()->back()->with('error', $r['message']);
        }
        $this->listingStates()->recordOutcome($productId, null);

        if ($r['added'] > 0) {
            $skus = DB::table('product_variation_values')
                ->where('product_id', $productId)->whereNotNull('sku')->where('sku', '!=', '')
                ->pluck('sku')->map(fn ($v) => strtolower(trim((string) $v)))
                ->merge(DB::table('product_variations')
                    ->where('product_id', $productId)->whereNotNull('sku')->where('sku', '!=', '')
                    ->pluck('sku')->map(fn ($v) => strtolower(trim((string) $v))))
                ->unique()->values()->all();
            app(\Extensions\Shopee\Services\Shopee\ShopeeModelLinkRepair::class)
                ->forItem($auth, $productId, (int) $link->shopee_item_id, $skus);
        }

        return redirect()->back()->with('status', $r['message'] . ($r['added'] > 0 ? ' Links updated.' : ''));
    }

    public function saveListing(Request $request, int $productId, ShopeeClient $client)
    {
        $exists = DB::table('products')->where('product_id', $productId)->exists();
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
            ...\App\Channels\Listings\ListingVariations::rules(),
            ...\App\Channels\Listings\VariationPrices::rules(),
        ]);

        $imageOrder = \App\Channels\Listings\ListingImages::submitted($productId, $data['image_order'] ?? null);
        $imageOff = \App\Channels\Listings\ListingImages::submittedOff($productId, $request->input('image_off'));

        $blank = fn ($v) => ($v === null || trim((string) $v) === '') ? null : $v;

        $fields = [
            'shopee_category_id' => $data['shopee_category_id'] ?? null,
            'description_prefix_id' => ((int) ($data['description_prefix_id'] ?? 0)) ?: null,
            ...\App\Channels\Listings\ListingImages::submittedWatermark($request, 'shopee', \App\Channels\Listings\ListingStore::id('shopee')),
            ...\App\Channels\Listings\ListingVideo::submitted($request),
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
            $fields['item_name'] = \App\Channels\Listings\ListingContent::own($data['item_name'] ?? null);
        }
        if ($request->has('description')) {
            $current = ShopeeListing::query()->where('product_id', $productId)->first();
            $fields['description'] = \App\Channels\Listings\ListingContent::descriptionToSave(
                $data['description'] ?? null,
                $current !== null
                    ? $current->description
                    : (\App\Channels\Listings\CatalogCopy::catalog([$productId])[$productId]['description'] ?? null),
                $request->input('description_edited')
            );
        }

        ShopeeListing::query()->updateOrCreate(['product_id' => $productId], $fields);

        if (($sellHere = \App\Channels\Listings\ListingVariations::submitted($request)) !== null) {
            \App\Channels\Listings\ListingVariations::save('shopee', (int) (ShopeeStore::defaultStore()?->id ?? 0), $productId, $sellHere);
        }
        if (($variationPrices = \App\Channels\Listings\VariationPrices::submitted($request)) !== null) {
            \App\Channels\Listings\VariationPrices::save('shopee', (int) (ShopeeStore::defaultStore()?->id ?? 0), $productId, $variationPrices);
        }

        $back = \App\Support\BackTo::safe($request->input('back'), '');
        $editRoute = $back !== '' ? [$productId, 'back' => $back] : $productId;

        if ($request->boolean('push_after')) {
            if (ShopeeListingLink::query()->where('product_id', $productId)->exists()) {
                $store = ShopeeStore::defaultStore()?->decrypted();
                $auth = ShopeeStore::activeAuth($store);
                if (!$store || !$auth['complete']) {
                    $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';

                    return redirect()->route('ext.shopee.listings.edit', $editRoute)
                        ->with('error', "Saved, but not pushed: missing Shopee {$modeLabel} settings.");
                }

                $r = $this->performListingUpdate($productId, $client, $auth);
                $tone = self::updateTone($r);

                return ($tone !== 'error' && $back !== '' ? redirect()->to($back) : redirect()->route('ext.shopee.listings.edit', $editRoute))
                    ->with($tone, $r['message']);
            }

            $readiness = app(\Extensions\Shopee\Services\Shopee\ShopeeListingReadiness::class)
                ->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => ['its readiness could not be checked']];

            if (!$readiness['ready']) {
                return redirect()->route('ext.shopee.listings.edit', $editRoute)
                    ->with('error', 'Saved, but not pushed: ' . \App\Channels\Listings\CatalogGaps::stillNeeds($readiness['missing']));
            }

            return $this->pushDirect($request, $productId, $client);
        }

        return redirect()->route('ext.shopee.listings.edit', $editRoute)
            ->with('status', 'Listing saved.');
    }

    public function pushListingUpdate(int $productId, ShopeeClient $client)
    {
        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
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
        $links = ShopeeListingLink::query()->where('product_id', $productId)->get();
        if ($links->isEmpty()) {
            return $answer;
        }
        $push = app(\Extensions\Shopee\Services\Shopee\ShopeeStockPricePush::class);
        $left = [];

        $price = $push->push('price', $auth, $links, \Extensions\Shopee\Commands\ShopeePushPrice::priceRule([$productId]));
        if (($price['ok'] ?? 0) < 1) {
            $left[] = 'the price (' . (($price['last_error'] ?: null) ?? 'Shopee did not say why') . ')';
        }

        if ((int) DB::table('products')->where('product_id', $productId)->value('status') === 1) {
            $stock = $push->push('stock', $auth, $links);
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
        $link = ShopeeListingLink::query()->where('product_id', $productId)->first();
        if (!$link) {
            return ['ok' => false, 'message' => 'This product is not on Shopee yet, so there is nothing to update. Push it first.'];
        }

        $readiness = app(\Extensions\Shopee\Services\Shopee\ShopeeListingReadiness::class)
            ->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => []];
        if (!$readiness['ready']) {
            return ['ok' => false, 'message' => \App\Channels\Listings\CatalogGaps::refusal($readiness, 'update')];
        }
        $langId = (int) config('catalog.default_language_id');
        $product = DB::table('products as p')
            ->leftJoin('product_descriptions as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                  ->where('pd.language_id', '=', $langId);
            })
            ->where('p.product_id', $productId)
            ->first(['p.product_id', 'pd.name', 'pd.description', 'p.weight', 'p.length', 'p.width', 'p.height']);
        if (!$product) {
            return ['ok' => false, 'message' => 'Product not found.'];
        }

        $listing = ShopeeListing::query()->where('product_id', $productId)->first() ?? (new ShopeeListing())->forceFill(['product_id' => $productId]);

        $coreName = html_entity_decode((string) ($product->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $coreDescription = \App\Support\Catalog\DescriptionText::of((string) ($product->description ?? ''));

        $content = \App\Channels\Listings\ListingContent::of(
            $listing, $coreName, $coreDescription, 'shopee', (int) ($listing?->store_id ?? 0)
        );
        $itemName = $content['title'];
        $description = $content['description'];

        $payload = [
            'item_id' => (int) $link->shopee_item_id,
            'item_name' => mb_substr($itemName, 0, 255),
            'description' => mb_substr(\App\Support\Catalog\DescriptionText::of($description), 0, 5000),
            'weight' => \App\Channels\Listings\ParcelPrecision::of($listing?->weight, $product->weight, \App\Channels\Listings\ParcelPrecision::SHOPEE['weight']),
            'dimension' => [
                'package_length' => \App\Channels\Listings\ParcelPrecision::of($listing?->package_length, $product->length, \App\Channels\Listings\ParcelPrecision::SHOPEE['dimension']),
                'package_width' => \App\Channels\Listings\ParcelPrecision::of($listing?->package_width, $product->width, \App\Channels\Listings\ParcelPrecision::SHOPEE['dimension']),
                'package_height' => \App\Channels\Listings\ParcelPrecision::of($listing?->package_height, $product->height, \App\Channels\Listings\ParcelPrecision::SHOPEE['dimension']),
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
        $create = app(\Extensions\Shopee\Services\Shopee\ShopeeItemCreate::class);
        $up = $create->uploadImages($client, $auth, $create->catalogImagePaths($product, $listing));
        if ($up['error'] !== null) {
            return ['ok' => false, 'message' => 'Update failed: ' . $up['error']];
        }
        if (!empty($up['ids'])) {
            $payload['image'] = ['image_id_list' => $up['ids']];
        }

        $described = (new \Extensions\Shopee\Services\Shopee\ShopeeDescription($client))->write(
            $payload, $auth, (int) $link->shopee_item_id,
            \App\Channels\Listings\ListingContent::of(
                $listing, $coreName, (string) ($product->description ?? ''), 'shopee', (int) ($listing?->store_id ?? 0)
            )['description']
        );
        $payload = $described['payload'];
        $descNote = $described['note'] === '' ? '' : ' ' . $described['note'];

        $switches = app(\Extensions\Shopee\Services\Shopee\ShopeeVariationSwitches::class);
        $read = $switches->readFor($client, $auth, (int) $link->shopee_item_id, $productId);

        $rename = app(\Extensions\Shopee\Services\Shopee\ShopeeVariationRename::class);
        $plan = $rename->plan($client, $auth, (int) $link->shopee_item_id, $productId, $read);
        $renamed = $rename->apply($client, $auth, $plan);
        $warning = $renamed['ok'] ? '' : ' ' . $renamed['message'];

        $followed = $switches->follow(
            $client, $auth, (int) $link->shopee_item_id, $productId,
            \Extensions\Shopee\Services\Shopee\ShopeeVariationSwitches::renamed($read, $plan, $renamed),
            fn (float $core) => (float) $listing->priceFor($core),
            $create->tierImageUploader($client, $auth)
        );
        $switchNote = \Extensions\Shopee\Services\Shopee\ShopeeVariationSwitches::note($followed);
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

        app(\Extensions\Shopee\Services\Shopee\ShopeeLiveListing::class)->confirm(
            $auth,
            ShopeeListingLink::query()->where('product_id', $productId)->pluck('shopee_item_id')->all()
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

        $link = ShopeeListingLink::query()->where('product_id', $productId)->first();
        if (!$link) {
            return redirect()->back()->with('error', 'This product is not on Shopee, so there is nothing to ' . ($unlist ? 'unlist' : 'relist') . '.');
        }

        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        if (!$unlist && ($parcelGap = $this->parcelGapFor([$productId])[$productId] ?? null)) {
            $this->listingStates()->recordOutcome($productId, 'Not published: Shopee will refuse it without ' . $parcelGap . '.');
            return redirect()->back()->with('error',
                'Not published: Shopee will refuse it without ' . $parcelGap
                . '. Add them on the Master Catalog product (Master Catalog, Products, edit), then publish.');
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
            ? 'Delisted. Customers no longer see this item on Shopee.'
            : 'Published. The item is visible on Shopee again.');
    }

}
