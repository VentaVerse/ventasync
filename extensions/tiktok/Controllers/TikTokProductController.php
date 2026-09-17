<?php

namespace Extensions\tiktok\Controllers;

use App\Http\Controllers\Controller;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokCategory;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokAttributes;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Extensions\tiktok\Services\TikTok\TikTokListingReadiness;
use Extensions\tiktok\Services\TikTok\TikTokLiveListing;
use Extensions\tiktok\Services\TikTok\TikTokProductPush;
use Extensions\tiktok\Services\TikTok\TikTokStockPushService;
use Extensions\tiktok\Services\TikTokStoreProducts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * TikTok listings (#156): the index of every catalog product in scope
 * for TikTok Shop with the marketplace's own status fetched on this
 * load, readiness before a push, and the per-product verbs (push,
 * update in place, stock, price, activate, unlink, delete). Mirrors
 * Shopee's ShopeeProductController; every push runs through the one
 * builder (TikTokProductPush) on the product's OWN listing settings,
 * never a borrowed group's (#96).
 */
class TikTokProductController extends Controller
{
    public function index(Request $request)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->get('q', ''));
        $syncStatus = (string) $request->get('sync_status', 'all');
        /* The status menu speaks the shared words; the older listed and
         * not_listed links still land where they did. */
        $syncStatus = ['listed' => 'uploaded', 'not_listed' => 'not_uploaded'][$syncStatus] ?? $syncStatus;
        // A retired eligibility rung lands on the half it belonged to.
        if ($syncStatus !== 'error') {
            $syncStatus = \App\Integrations\Listings\StatusMenu::stand($syncStatus);
        }
        /* The status menu's two switches narrow whatever the tree has
         * chosen; an old sync_status=error link is Push failed switched on. */
        $failedFlag = $request->boolean('failed') || $syncStatus === 'error';
        $changeFlag = $request->boolean('change');
        if ($syncStatus === 'error') {
            $syncStatus = 'all';
        }
        $manufacturerFilter = (string) $request->get('manufacturer', 'all');
        /* The rail's choice: a group id, `none` for the products no group
           claims, else the whole store. */
        $groupFilter = \App\Integrations\Listings\StatusMenu::group($request->get('group'));
        $erpStatus = (string) $request->get('erp_status', 'all');
        $tiktokTab = (string) $request->get('tiktok_tab', 'all');
        if (!array_key_exists($tiktokTab, TikTokLiveListing::TABS)) {
            $tiktokTab = 'all';
        }

        /* ── Two surfaces on one workbench (#201, the owner 2026-09-05) ──
         * THIS STORE is what this store carries and works day to day; ADD
         * PRODUCTS is the main catalogue, where a product is added into the
         * store's list - the slide-over is the way in and this full page is
         * its overflow. It has its own lean flow, so it returns before the
         * live-status machinery below, which it does not need. */
        if ($request->query('list') === 'add') {
            return $this->addCatalogue($request);
        }

        /* The store's own statuses, from the MIRROR on the listing rows
         * (live_status, live_checked_at), refreshed every 30 minutes by
         * the automation, on demand by the Refresh button, and written
         * through by every activate and deactivate the ERP makes. A page
         * view asks TikTok for nothing. The tab row says when the mirror
         * was last refreshed. */
        $c = $this->creds();
        $liveTabError = null;
        $liveCounts = null;
        $liveCheckedAt = null;
        if ($c) {
            // Counted below, against the rows the status menu has chosen.
            $liveCounts = [];
            $checked = TikTokListing::query()->whereNotNull('tiktok_product_id')->max('live_checked_at');
            $liveCheckedAt = $checked ? \Illuminate\Support\Carbon::parse($checked) : null;
        } else {
            $liveTabError = 'Missing TikTok settings.';
            $tiktokTab = 'all';
        }

        $sortColumns = [
            'id' => 'p.product_id', 'product' => 'pd.name', 'quantity' => 'p.quantity',
            'price' => 'p.price', 'product_status' => 'p.status', 'manufacturer' => 'm.name',
        ];
        $sort = array_key_exists((string) $request->get('sort', 'id'), $sortColumns) ? (string) $request->get('sort', 'id') : 'id';
        $dir = strtolower((string) $request->get('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $groupsByProductId = DB::table('tiktok_product_group_products as tp')
            ->join('tiktok_product_groups as tg', 'tg.id', '=', 'tp.tiktok_product_group_id')
            ->where('tg.tiktok_setting_id', (int) (TikTokSetting::defaultStore()?->id ?? 0))
            ->get(['tp.product_id', 'tg.id', 'tg.name'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name])->all());

        $query = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->select('p.product_id', 'pd.name', 'p.image', 'p.model', 'p.sku', 'p.price', 'p.quantity', 'p.status', 'm.name as manufacturer_name');
        /* THIS STORE (#201, the owner 2026-09-05): the table is this store's own
         * list, not the catalogue. A product is on the store once it has a
         * listing record here (Add products, Import, a push) or a pushed
         * group pivot from before the record carried the truth -
         * TikTokStoreProducts is the one definition; the rest of the
         * catalogue waits behind Add products. Phase D (#184) had made this
         * the whole catalogue so a new user's first product was never
         * invisible; multi-store is what changes the answer, and the empty
         * state now leads to Add products instead. Filtering by a group
         * below still narrows within the store's list. */
        $query->whereIn('p.product_id', TikTokStoreProducts::query());
        // The set the listed / not listed places below answer from.
        $listedProductIds = TikTokStoreProducts::listedIds();

        /* The rail's choice, as a closure rather than a filter written into
           the query here: the rail counts every group under everything else
           the page has chosen EXCEPT the group, so a number and the list it
           opens always agree. Grouped means grouped ON THIS STORE. */
        $groupedIds = TikTokProductGroupProduct::query()->onStore()
            ->whereNotNull('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $applyGroup = function ($query, string $choice) use ($groupedIds) {
            if ($choice === 'none') {
                return $groupedIds === [] ? $query : $query->whereNotIn('p.product_id', $groupedIds);
            }
            if ($choice === 'all') {
                return $query;
            }
            $ids = TikTokProductGroupProduct::query()->where('tiktok_product_group_id', (int) $choice)
                ->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

            return $query->whereIn('p.product_id', $ids ?: [0]);
        };
        if ($manufacturerFilter !== 'all') {
            $query->where('p.manufacturer_id', (int) $manufacturerFilter);
        }
        if ($erpStatus === 'enabled') {
            $query->where('p.status', 1);
        } elseif ($erpStatus === 'disabled') {
            $query->where('p.status', 0);
        } else {
            /* The default view (#197): a disabled product TikTok Shop does
             * not hold is noise; a disabled product still listed needs
             * eyes. The filter still offers Disabled on purpose. */
            $query->where(function ($sub) {
                $sub->where('p.status', 1)
                    ->orWhereIn('p.product_id', TikTokListing::query()
                        ->whereNotNull('tiktok_product_id')->select('product_id'))
                    ->orWhereIn('p.product_id', \Extensions\tiktok\Models\TikTokProductGroupProduct::query()->onStore()
                        ->whereNotNull('tiktok_product_id')->select('product_id'));
            });
        }
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.model', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%')
                    // The row shows this store's own title, so the search finds it by that too.
                    ->orWhereIn('p.product_id', TikTokListing::query()
                        ->forStore(TikTokSetting::defaultStore())
                        ->where('title', 'like', '%' . $q . '%')
                        ->select('product_id'));
            });
        }
        /* One definition of each place in the tree, used for the rows and
         * for the menu's counts alike, so a number and its list agree. */
        $listed = fn ($query) => $query->whereIn('p.product_id', $listedProductIds ?: [0]);
        $notListed = fn ($query) => $query->whereNotIn('p.product_id', $listedProductIds ?: [0]);
        $applySync = function ($query, string $status) use ($listed, $notListed) {
            match ($status) {
                'uploaded' => $listed($query),
                'not_uploaded' => $notListed($query),
                default => $query,
            };

            return $query;
        };

        $errored = $this->states()->erroredProductIds();
        $changed = $this->states()->productIdsIn('drift');

        /* The status menu's choice, and the store's tab: each narrows the
         * rows, and each one's counts are taken under the other's choice. */
        $applyMenu = function ($query) use ($applySync, $applyGroup, $syncStatus, $groupFilter, $failedFlag, $changeFlag, $errored, $changed) {
            $applyGroup($query, $groupFilter);
            $applySync($query, $syncStatus);
            if ($failedFlag) {
                /* The rows that draw an error line, by the line's own engine. */
                $query->whereIn('p.product_id', $errored ?: [0]);
            }
            if ($changeFlag) {
                $query->whereIn('p.product_id', $changed ?: [0]);
            }

            return $query;
        };
        // A TikTok tab narrows to the products the mirror holds in that status.
        $applyTab = function ($query) use ($tiktokTab, $liveCounts) {
            if ($tiktokTab !== 'all' && $liveCounts !== null) {
                $query->whereIn('p.product_id', TikTokListing::query()->whereNotNull('tiktok_product_id')
                    ->where('live_status', TikTokLiveListing::TABS[$tiktokTab][1])->select('product_id'));
            }

            return $query;
        };
        // The mirror's count per tab, over the products the query holds.
        $tabCounts = function ($query): array {
            $byStatus = TikTokListing::query()
                ->whereNotNull('tiktok_product_id')
                ->whereNotNull('live_status')
                ->whereIn('product_id', (clone $query)->select('p.product_id'))
                ->selectRaw('live_status, COUNT(DISTINCT product_id) as n')
                ->groupBy('live_status')
                ->pluck('n', 'live_status');
            $out = [];
            foreach (TikTokLiveListing::TABS as $tabKey => [, $status]) {
                if ($status !== null) {
                    $out[$tabKey] = (int) ($byStatus[$status] ?? 0);
                }
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

        // The segment counts inside the rail's chosen group, never inside itself.
        $standBase = $withFlags($applyGroup(clone $menuBase, $groupFilter));
        $menuCounts = ['all' => (clone $standBase)->count()];
        foreach (['not_uploaded', 'uploaded'] as $status) {
            $menuCounts[$status] = $applySync(clone $standBase, $status)->count();
        }
        /* A filter narrows whatever the menu has already chosen: its link
           carries the group and the half along with it, and the other filter
           when that one is on. So each is counted inside that same choice
           and never across the store, or the number would promise one list
           and open a smaller one. */
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

        /* A group's number is the group's own size, and it does not move when
           the tabs above the table change. The tabs already carry their own
           numbers for the breakdown inside whichever group is open, so tying
           the two together only made the menu re-sort itself under the hand. */
        $railBase = clone $menuBase;
        $menuCounts['store'] = (clone $railBase)->count();
        $groupRail = [];
        foreach (TikTokProductGroup::query()->orderBy('name')->get(['id', 'name']) as $group) {
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

        $tabBase = $applyMenu(clone $query);
        // The tab row's All: every row the menu has chosen, before a tab narrows it.
        $catalogueTotal = (clone $tabBase)->count();
        if ($liveCounts !== null) {
            $liveCounts = $tabCounts($tabBase);
        }

        $applyMenu($query);
        $applyTab($query);


        /* The dashboard's own figures, as a filter (#240): a chip that says
         * "3 listings need attention" links here with ?state=attention, and
         * the rows are fetched by the SAME query that counted them. */
        $troubleFilter = \App\Integrations\Listings\ListingTrouble::asked($request->query('state'));
        if ($troubleFilter !== null) {
            $troubleIds = app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class)->productIdsIn($troubleFilter);
            $query->whereIn('p.product_id', $troubleIds ?: [0]);
        }

        $query->orderBy($sortColumns[$sort], $dir);
        if ($sortColumns[$sort] !== 'p.product_id') {
            $query->orderBy('p.product_id', 'asc');
        }
        $products = $query->paginate(50)->withQueryString();
        foreach ($products as $row) {
            $row->name = html_entity_decode((string) ($row->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $pageIds = $products->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $listingsByProductId = TikTokListing::query()->forStore(TikTokSetting::defaultStore())->whereIn('product_id', $pageIds ?: [0])->get()->keyBy(fn ($l) => (int) $l->product_id);
        // Each row is named by this store's own title, the catalog's name when it has none.
        $rowTitles = \App\Integrations\Listings\ListingContent::rowTitles($products, $listingsByProductId);
        // Products pushed before the record carried the truth: the pivots answer.
        $pivotTruth = TikTokProductGroupProduct::query()->onStore()
            ->whereIn('product_id', $pageIds ?: [0])->whereNotNull('tiktok_product_id')
            ->orderByDesc('last_pushed_at')->get()->groupBy(fn ($p) => (int) $p->product_id)->map(fn ($rows) => $rows->first());
        $lastAttempts = TikTokProductGroupProduct::query()->onStore()
            ->whereIn('product_id', $pageIds ?: [0])->orderByDesc('last_pushed_at')->get()
            ->groupBy(fn ($p) => (int) $p->product_id)->map(fn ($rows) => $rows->first());

        $unlistedPageIds = array_values(array_filter($pageIds, function ($id) use ($listingsByProductId, $pivotTruth) {
            return !(($listingsByProductId->get($id)?->tiktok_product_id) || $pivotTruth->has($id));
        }));
        $readinessByProductId = app(TikTokListingReadiness::class)->forProducts($unlistedPageIds);

        /* The mirror fills its own blanks (the owner, 2026-08-28: a product
         * pushed a minute ago should not read "Not checked yet"). A listing
         * that got its product id from a push has no status until someone
         * asks; the page asks TikTok once for those rows, up to ten a
         * view, then reads the mirror like every other row. */
        if ($liveCounts !== null && $c) {
            $blank = $listingsByProductId->filter(fn ($l) => $l->tiktok_product_id && $l->live_status === null)->take(10);
            if ($blank->isNotEmpty()) {
                $live = app(TikTokLiveListing::class);
                $filled = false;
                foreach ($blank as $l) {
                    $answer = $live->fetch((string) $l->tiktok_product_id, $c);
                    $status = $answer['live']['status'] ?? null;
                    if ($status === null || $status === '') {
                        /* TikTok is not answering: leave the rest blank
                           rather than guessed, and SAY so - a swallowed
                           refusal reads as rows that went unsynced by
                           themselves (the owner, 2026-09-09). */
                        session()->now('error', \App\Integrations\Listings\BlankFillRefusal::sentence('TikTok Shop', $answer['error'] ?? null));
                        break;
                    }
                    TikTokListing::query()->whereKey($l->id)->update(['live_status' => $status, 'live_checked_at' => now()]);
                    $l->live_status = $status;
                    $filled = true;
                }
                if ($filled) {
                    $liveCounts = $tabCounts($tabBase);
                    $liveCheckedAt = $liveCheckedAt ?? now();
                }
            }
        }

        // Per-row statuses, from the mirror: tiktok_product_id => status.
        $liveStatuses = [];
        foreach ($listingsByProductId as $l) {
            if ($l->tiktok_product_id && $l->live_status !== null) {
                $liveStatuses[(string) $l->tiktok_product_id] = (string) $l->live_status;
            }
        }

        /* ONE state per row (Phase D, #184): the engine folds the mirror,
         * the pivot fallback, drift and readiness into a single
         * ListingState the blade renders verbatim - badge, drift chip and
         * action menu alike (#188). Computed after the blank fill so it
         * reads a filled mirror. */
        $listingStates = app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class)
            ->forProducts($pageIds);
        // The Last sync column (the owner, 2026-09-05), one answer shared with the group pages.
        $rowErrors = app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class)->errors($pageIds);

        // How many of this store's products actually stand on TikTok Shop,
        // for the subtitle - listing rows plus pivot-only legacies, distinct.
        $listedTotal = count($listedProductIds);

        return view('ext-tiktok::products.index', [
            'products' => $products,
            'rowTitles' => $rowTitles,
            'troubleFilter' => $troubleFilter,
            'listingStates' => $listingStates,
            'rowErrors' => $rowErrors,
            'listedTotal' => $listedTotal,
            'q' => $q, 'sort' => $sort, 'dir' => $dir,
            'syncStatus' => $syncStatus, 'manufacturerFilter' => $manufacturerFilter,
            'groupFilter' => $groupFilter, 'erpStatus' => $erpStatus,
            'allManufacturers' => DB::table($pfx . 'manufacturer')->orderBy('name')->pluck('name', 'manufacturer_id'),
            'allGroups' => TikTokProductGroup::query()->orderBy('name')->pluck('name', 'id'),
            'groupsByProductId' => $groupsByProductId,
            'optionRowsByProductId' => \App\Support\VariationRows::forListing($pageIds, 'tiktok', (int) (TikTokSetting::defaultStore()?->id ?? 0)),
            'listingsByProductId' => $listingsByProductId,
            'pivotTruth' => $pivotTruth,
            'lastAttempts' => $lastAttempts,
            'tiktokTab' => $tiktokTab,
            'liveCounts' => $liveCounts,
            'liveTabError' => $liveTabError,
            'liveStatuses' => $liveStatuses,
            'liveCheckedAt' => $liveCheckedAt,
            'catalogueTotal' => $catalogueTotal,
            'readinessByProductId' => $readinessByProductId,
            'failedFlag' => $failedFlag,
            'changeFlag' => $changeFlag,
            'statusMenu' => \App\Integrations\Listings\StatusMenu::build([
                'url' => fn (array $params) => route('ext.tiktok.products.index', $params),
                'query' => $request->query(),
                'sync' => $syncStatus,
                'failed' => $failedFlag,
                'change' => $changeFlag,
                'counts' => $menuCounts,
                'group' => $groupFilter,
                'groups' => $groupRail,
                'newGroup' => route('ext.tiktok.product-groups.create'),
                // Live is a fact about a marketplace: a product is live ON somewhere.
                'store' => 'TikTok Shop',
            ]),
        ]);
    }

    /**
     * Whether each of this store's products could be pushed right now, listed
     * or not, by the same per-product readiness the row's own Readiness reads
     * (TikTokListingStates::forProducts), so a menu count and the rows it
     * leads to agree. Held for a minute: the menu counts it on every page.
     *
     * @return array<int, bool>  product id => ready
     */
    private function readyByProduct(): array
    {
        return array_map(fn (array $a) => (bool) $a['ready'], $this->readinessRich());
    }

    /**
     * @return array<int, list<string>>  product id => gap codes
     */
    private function gapsByProduct(): array
    {
        return array_map(
            fn (array $a) => array_values(array_map(fn ($g) => (string) ($g['code'] ?? ''), (array) $a['gaps'])),
            $this->readinessRich()
        );
    }

    /** Held for the request. */
    private ?array $readinessRich = null;

    /**
     * Ready + gaps for every product on the store, from the materialised
     * readiness cache; recomputed only for products whose inputs changed.
     *
     * @return array<int, array{ready: bool, gaps: list<array{code: string, label: string}>}>
     */
    private function readinessRich(): array
    {
        if ($this->readinessRich !== null) {
            return $this->readinessRich;
        }
        $storeId = (int) (TikTokSetting::defaultStore()?->id ?? 0);
        $scope = DB::query()->fromSub(TikTokStoreProducts::query(), 'os')
            ->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        return $this->readinessRich = \App\Integrations\Listings\ReadinessCache::resolve(
            'tiktok', $storeId, $scope,
            fn (array $ids) => $this->tiktokReadinessStamps($ids, $storeId),
            fn (array $ids) => $this->tiktokReadinessCompute($ids),
        );
    }

    private function tiktokReadinessStamps(array $ids, int $storeId): array
    {
        $pfx = (string) config('catalog.prefix');
        $prod = DB::table($pfx . 'product')->whereIn('product_id', $ids)->pluck('date_modified', 'product_id');
        $listing = DB::table('tiktok_listings')->whereIn('product_id', $ids)
            ->selectRaw('product_id, MAX(updated_at) as mu')->groupBy('product_id')->pluck('mu', 'product_id');
        $group = DB::table('tiktok_product_group_products as gp')
            ->join('tiktok_product_groups as g', 'g.id', '=', 'gp.tiktok_product_group_id')
            ->whereIn('gp.product_id', $ids)->where('g.tiktok_setting_id', $storeId)
            ->selectRaw('gp.product_id, MAX(g.updated_at) as mu')->groupBy('gp.product_id')->pluck('mu', 'product_id');

        $out = [];
        foreach ($ids as $pid) {
            $out[(int) $pid] = md5(($prod[$pid] ?? '') . '|' . ($listing[$pid] ?? '') . '|' . ($group[$pid] ?? ''));
        }

        return $out;
    }

    private function tiktokReadinessCompute(array $ids): array
    {
        $readiness = app(TikTokListingReadiness::class);
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

    /**
     * The full-catalogue Add products page (#201): the overflow behind the
     * slide-over, for a big first setup or a bulk pick. Your main catalogue,
     * each row flagged with whether this store already carries it. A lean
     * read - no live status, no readiness, no push.
     */
    private function addCatalogue(Request $request)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $q = trim((string) $request->query('q', ''));
        $manufacturerFilter = (string) $request->query('manufacturer', 'all');
        $groupFilter = (string) $request->query('group', 'all');
        $onStore = (string) $request->query('on_store', 'all'); // all | off (not yet) | on

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
            ->leftJoinSub(TikTokStoreProducts::query(), 'os', 'os.product_id', '=', 'p.product_id')
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
            $groupedIds = TikTokProductGroupProduct::query()
                ->whereIn('tiktok_product_group_id', TikTokProductGroup::query()->select('id'))
                ->pluck('product_id')->unique()->all();
            if (! empty($groupedIds)) {
                $query->whereNotIn('p.product_id', $groupedIds);
            }
        } elseif ($groupFilter !== 'all') {
            $specificIds = TikTokProductGroupProduct::query()->where('tiktok_product_group_id', (int) $groupFilter)->pluck('product_id')->all();
            $query->whereIn('p.product_id', $specificIds ?: [0]);
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
            $row->name = html_entity_decode((string) ($row->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $productThumbsById = $products->mapWithKeys(fn ($r) => [(int) $r->product_id => $this->thumbUrl($r->image ?? null)]);

        return view('ext-tiktok::products.add', [
            'products' => $products,
            'paginator' => $products,
            'productThumbsById' => $productThumbsById,
            'allManufacturers' => DB::table($pfx . 'manufacturer')->orderBy('name')->pluck('name', 'manufacturer_id'),
            'allGroups' => TikTokProductGroup::query()->orderBy('name')->pluck('name', 'id'),
            'q' => $q,
            'manufacturerFilter' => $manufacturerFilter,
            'groupFilter' => $groupFilter,
            'onStore' => $onStore,
            'sort' => $sort,
            'dir' => $dir,
            'catalogueTotal' => (int) DB::table($pfx . 'product')->count(),
            'onStoreTotal' => TikTokStoreProducts::count(),
            'listTab' => 'add',
        ]);
    }

    /** The same storage path the Listings rows draw their thumbnails from. */
    private function thumbUrl(?string $image): ?string
    {
        $image = trim((string) $image);

        return $image !== '' ? asset('storage/' . ltrim($image, '/')) : null;
    }

    /**
     * Add one catalogue product to this store's list (#201): a blank listing
     * record for this store is the membership. The listing page fills it
     * in, and readiness reads it as "needs a TikTok category" until then, so
     * the row lands under This store ready to configure and push through
     * the unchanged workflow. The BelongsToTikTokStore trait stamps the
     * store, so firstOrCreate never crosses stores or doubles a row.
     */
    public function addToStore(Request $request, int $productId)
    {
        $exists = DB::table((string) config('catalog.prefix') . 'product')->where('product_id', $productId)->exists();
        abort_unless($exists, 404);

        // "Already on the store" includes a product carried by a pushed
        // pivot alone; the listing record it gains here changes nothing for it.
        $wasOn = TikTokStoreProducts::has($productId);
        TikTokListing::firstOrCreate(['product_id' => $productId]);

        // The Add products panel adds in place and asks for JSON (#201,
        // Draft C); the full catalogue page still posts a form and lands back.
        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'product_id' => $productId, 'added' => ! $wasOn]);
        }

        return redirect()->back()->with('status', 'Added to this store. Configure and push it from This store whenever you are ready.');
    }

    /**
     * The Add products panel's search (#201, Draft C - the owner 2026-09-05): the
     * main catalogue, each row flagged with whether it is already on this
     * store. Capped, on purpose: a slide-over cannot list a whole catalogue,
     * so the operator narrows by searching and the total says how much is
     * still out of view; the full catalogue page is one link away for a
     * browse. Defaults to what is NOT yet on the store, since adding is the
     * panel's one job.
     */
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
            ->leftJoinSub(TikTokStoreProducts::query(), 'os', 'os.product_id', '=', 'p.product_id')
            ->select('p.product_id', 'pd.name', 'p.image', 'p.sku', 'p.model', 'p.quantity', 'p.price', 'os.product_id as on_store_pid')
            // A disabled catalogue product is not something to put on a store.
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

    /**
     * Remove a product from this store's list (#201) - the inverse of Add.
     * This store's group memberships and the listing record go, so the
     * product leaves This store and STOPS SYNCING at once. Deliberately no
     * blocker (the owner, 2026-09-05): nobody keeps a product on the marketplace
     * while stripping it here. It never touches TikTok Shop - a live product
     * stays up until it is removed there, which the confirm says plainly and
     * Delete from TikTok Shop already covers. Every query here is
     * store-scoped, so a product carried only by another store answers 404.
     */
    public function removeFromStore(int $productId)
    {
        abort_unless(TikTokStoreProducts::has($productId), 404);

        $ttIds = collect([
            TikTokListing::query()->where('product_id', $productId)->value('tiktok_product_id'),
        ])->merge(
            TikTokProductGroupProduct::query()->where('product_id', $productId)
                ->whereIn('tiktok_product_group_id', TikTokProductGroup::query()->select('id'))
                ->pluck('tiktok_product_id')
        )->filter()->unique()->values();

        TikTokProductGroupProduct::query()
            ->where('product_id', $productId)
            ->whereIn('tiktok_product_group_id', TikTokProductGroup::query()->select('id'))
            ->delete();
        TikTokListing::query()->where('product_id', $productId)->delete();

        $note = $ttIds->isNotEmpty()
            ? ' It no longer syncs; TikTok Shop product ' . $ttIds->implode(', ') . ' stays up until you remove it there.'
            : ' It no longer syncs.';

        return redirect()->back()->with('status', "Removed product #{$productId} from this store." . $note);
    }

    /**
     * Add the ticked catalogue products to this store's list in one go (#201).
     */
    public function addToStoreBulk(Request $request)
    {
        $data = $request->validate(['product_ids' => ['required', 'array'], 'product_ids.*' => ['integer']]);
        $ids = array_values(array_unique(array_map('intval', $data['product_ids'])));

        $valid = DB::table((string) config('catalog.prefix') . 'product')->whereIn('product_id', $ids)->pluck('product_id')->all();
        $added = 0;
        foreach ($valid as $pid) {
            $pid = (int) $pid;
            $wasOn = TikTokStoreProducts::has($pid);
            TikTokListing::firstOrCreate(['product_id' => $pid]);
            if (! $wasOn) {
                $added++;
            }
        }

        return redirect()->back()->with('status', $added === 1
            ? '1 product added to this store.'
            : number_format($added) . ' products added to this store.');
    }

    /** The Refresh button on the listings strip: the same walk the 30-minute automation runs. */
    public function refreshStatus(Request $request, TikTokLiveListing $live)
    {
        $c = $this->creds();
        if (!$c) {
            return redirect()->back()->with('error', 'Missing TikTok settings.');
        }

        $result = $live->refreshMirror($c);
        if ($result['error'] !== null) {
            return redirect()->back()->with('error', $result['error'] . ' The statuses shown are from the last refresh.');
        }
        /* Refresh is the one button (the owner, 2026-09-07): every status is
         * re-read first, then the links are checked against the store, the
         * old Repair links folded in. */
        // The check reads two hundred per run; the list starts after the last one checked, so "run it again" continues.
        $checked = app(\Extensions\tiktok\Services\TikTok\TikTokLinkCheck::class)->runNext($c, (int) (\Extensions\tiktok\Models\TikTokSetting::defaultStore()?->id ?? 0));
        $sheets = app(\Extensions\tiktok\Services\TikTok\TikTokSheetReads::class)
            ->readMissing($c, app(TikTokClient::class), \App\Integrations\Listings\SheetReads::PER_PRESS);

        // One word per state, the engine's (#184 D): 'unlisted', not 'deactivated'.
        $labels = ['ACTIVATE' => 'live', 'SELLER_DEACTIVATED' => 'unlisted', 'PLATFORM_DEACTIVATED' => 'deactivated by TikTok',
            'IN_REVIEW' => 'under review', 'PENDING' => 'under review', 'FAILED' => 'failed review', 'FREEZE' => 'frozen',
            'DRAFT' => 'draft', 'DELETED' => 'deleted', 'MISSING' => 'not found on TikTok Shop'];
        $parts = [];
        foreach ($result['counts'] as $status => $n) {
            $parts[] = number_format($n) . ' ' . ($labels[$status] ?? strtolower((string) $status));
        }

        return redirect()->back()->with('status', 'Refreshed from TikTok Shop: ' . ($parts ? implode(', ', $parts) : 'no linked products') . '.'
            . ($checked ? ' ' . $checked['summary'] : '')
            . ($sheets['summary'] !== '' ? ' ' . $sheets['summary'] : ''));
    }

    /** The one-click push on the product's OWN listing settings (#96). */
    /**
     * The Import page (cleanup point 10, the owner 2026-09-03): its own page
     * under the Catalog menu, not a card squatting on the workbench.
     */
    public function importPage(Request $request, \Extensions\tiktok\Services\TikTok\TikTokLiveListing $live)
    {
        $fetched = null;
        $fetchError = null;
        if ($request->boolean('fetch')) {
            [$fetched, $fetchError] = $this->fetchUnmatched($live);
        }

        return view('ext-tiktok::products.import', [
            'fetched' => $fetched,
            'fetchError' => $fetchError,
        ]);
    }

    public function pushDirect(Request $request, int $productId, TikTokClient $client)
    {
        $c = $this->creds();
        if (!$c) {
            return redirect()->back()->with('error', 'Push failed: missing TikTok settings.');
        }

        $answer = $this->pushOne($productId, $c, $client);
        if (! $answer['ok']) {
            // A refusal about the category lands under the category field on
            // the listing page as well as in the header (2026-09-06).
            $fieldErrors = preg_match('/categor/i', $answer['message']) ? ['tiktok_category_id' => 'TikTok: ' . $answer['message']] : [];

            return redirect()->back()->with('error', 'Push failed: ' . $answer['message'])->withErrors($fieldErrors);
        }

        // Accepted: back to the Listings page the operator came from, search and all (the owner, 2026-09-06).
        $done = \App\Support\BackTo::safe($request->input('back'), url()->previous(route('ext.tiktok.products.index')));

        return redirect()->to($done)->with(! empty($answer['warning']) ? 'warning' : 'status', $answer['message']);
    }

    /**
     * Send ONE product to TikTok Shop on its own settings, and answer for
     * itself. The single-product button and the multi-tick bar are the
     * same act on one product, so they are the same code: the button
     * redirects with the answer, the bar collects it beside nine others.
     *
     * @param  array<string, mixed>  $c
     * @return array{ok: bool, message: string}
     */
    private function pushOne(int $productId, array $c, TikTokClient $client): array
    {
        if ($this->truthFor($productId)) {
            return ['ok' => false, 'message' => 'this product is already on TikTok Shop. Use Update on TikTok Shop for it.'];
        }
        // The options read the category's sheet first, so the gate judges the real one.
        $opts = $this->listingPushOptions($productId, $c);
        $readiness = app(TikTokListingReadiness::class)->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => ['a TikTok category']];
        if (!$readiness['ready']) {
            $refusal = \App\Integrations\Listings\CatalogGaps::refusal($readiness);
            $this->states($c['setting'] ?? null)->recordOutcome($productId, $refusal);

            return ['ok' => false, 'message' => $refusal];
        }

        $outcome = app(TikTokProductPush::class)->create($this->erpProduct($productId), $opts, $c, $client);
        if (!$outcome['ok']) {
            $this->states($c['setting'] ?? null)->recordOutcome($productId, (string) $outcome['message']);

            return ['ok' => false, 'message' => (string) $outcome['message']];
        }
        TikTokListing::recordPush($productId, $outcome['product_id'], $outcome['sku_ids'], 'listing');
        TikTokProductGroupProduct::query()->onStore($c['setting'] ?? null)->where('product_id', $productId)->update([
            'tiktok_product_id' => $outcome['product_id'], 'tiktok_sku_id' => $outcome['sku_ids'],
            'sync_status' => 'pushed', 'last_pushed_at' => now(), 'push_error' => null,
        ]);
        $pictures = $outcome['pictures'] ?? null;
        $this->states($c['setting'] ?? null)->recordOutcome($productId, null);

        return [
            'ok' => true, 'warning' => $pictures,
            'message' => 'Pushed to TikTok Shop as product ' . $outcome['product_id'] . '. It goes live once TikTok has reviewed it.' . ($pictures ? ' ' . $pictures : ''),
        ];
    }

    /**
     * Multi-tick push: each ticked product goes up on its OWN settings and
     * reports for itself, in the words every channel shares.
     */
    public function bulkPush(Request $request, TikTokClient $client)
    {
        $c = $this->creds();
        if (!$c) {
            return redirect()->back()->with('error', 'Push failed: missing TikTok settings.');
        }

        $answer = \App\Integrations\Push\BulkPushRun::over(
            (array) $request->input('product_ids', []),
            'TikTok Shop',
            fn (int $productId) => $this->pushOne($productId, $c, $client)
        );

        return redirect()->back()->with($answer['key'], $answer['message']);
    }

    /**
     * Send the selection to TikTok Shop: new products are created, anything
     * already listed gets its content updated in place. Refuses to start
     * when an unlisted selection is not ready, naming what it needs.
     */
    /**
     * Ask TikTok Shop whether the links this page believes in are still
     * true (Phase F, #184): the group page's check, on the workbench,
     * via the shared TikTokLinkCheck. With rows ticked it checks those;
     * with none it checks every linked product.
     */
    public function checkAgainstTikTok(Request $request, \Extensions\tiktok\Services\TikTok\TikTokLinkCheck $check)
    {
        $c = $this->creds();
        if (!$c) {
            return redirect()->back()->with('error', 'Missing TikTok settings.');
        }

        $productIds = array_map('intval', array_filter((array) $request->input('product_ids', [])));
        if (empty($productIds)) {
            $productIds = TikTokListing::query()->whereNotNull('tiktok_product_id')->pluck('product_id')
                ->merge(\Extensions\tiktok\Models\TikTokProductGroupProduct::query()->onStore($c['setting'] ?? null)->whereNotNull('tiktok_product_id')->pluck('product_id'))
                ->map(fn ($v) => (int) $v)->unique()->sort()->values()->all();
        }
        if (empty($productIds)) {
            return redirect()->back()->with('error', 'Nothing is linked to TikTok Shop yet, so there is nothing to check.');
        }

        $r = $check->run($c, $productIds);

        return redirect()->back()->with($r['tone'], $r['summary']);
    }

    /**
     * Dress the selected products' listing records in a product group's shared
     * settings (#195): category and price rule, created where no record
     * exists yet. Then Send to TikTok Shop works, without one visit to
     * the Product Groups page.
     */
    /**
     * TikTok's import queue (#196): walk the shop once, keep what is
     * neither linked here nor matched by any catalog SKU - the same
     * scan Shopee and Lazada carry, in TikTok's anatomy.
     */
    /** The Link picker's catalog search: the shared shape, with the products this store already holds marked (App\Support\CatalogPickerSearch). */
    public function searchCatalogProducts(Request $request)
    {
        return response()->json(\App\Support\CatalogPickerSearch::items((string) $request->get('q', ''), function (array $ids) {
            $out = [];
            foreach (TikTokListing::query()->whereIn('product_id', $ids)->whereNotNull('tiktok_product_id')->get(['product_id', 'tiktok_product_id']) as $l) {
                $out[(int) $l->product_id] = 'TikTok product ' . $l->tiktok_product_id;
            }
            foreach (TikTokProductGroupProduct::query()->onStore()->whereIn('product_id', $ids)->whereNotNull('tiktok_product_id')->get(['product_id', 'tiktok_product_id']) as $p) {
                $out[(int) $p->product_id] ??= 'TikTok product ' . $p->tiktok_product_id;
            }

            return $out;
        }));
    }

    /**
     * Read the shop live and keep only products the catalog cannot place
     * - not linked, no SKU known. Pure read; one row per product.
     *
     * @return array{0: ?array{found: int, rows: list<array>}, 1: ?string}
     */
    private function fetchUnmatched(TikTokLiveListing $live): array
    {
        $c = $this->creds();
        if (!$c) {
            return [null, 'Missing TikTok settings.'];
        }

        $shop = $live->shop($c);
        if ($shop['products'] === null) {
            return [null, 'Fetch failed: ' . $shop['error']];
        }

        $linked = TikTokListing::query()->whereNotNull('tiktok_product_id')->pluck('tiktok_product_id')
            ->merge(\Extensions\tiktok\Models\TikTokProductGroupProduct::query()->onStore($c['setting'] ?? null)->whereNotNull('tiktok_product_id')->pluck('tiktok_product_id'))
            ->map(fn ($v) => (string) $v)->flip();

        $pfx = (string) config('catalog.prefix');
        $erpSkus = [];
        foreach (DB::table($pfx . 'product')->get(['sku', 'model']) as $p) {
            foreach ([$p->sku, $p->model] as $v) {
                if (trim((string) $v) !== '') $erpSkus[strtolower(trim((string) $v))] = true;
            }
        }
        foreach (DB::table($pfx . 'product_option_value')->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku') as $v) {
            $erpSkus[strtolower(trim((string) $v))] = true;
        }
        foreach (DB::table('product_option_combinations')->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku') as $v) {
            $erpSkus[strtolower(trim((string) $v))] = true;
        }

        $found = 0;
        $rows = [];
        foreach ($shop['products'] as $ttId => $p) {
            $found++;
            if ($linked->has((string) $ttId)) {
                continue;
            }
            $skus = array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) ($p['skus'] ?? []))));
            $matched = false;
            foreach ($skus as $sku) {
                if (isset($erpSkus[strtolower($sku)])) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                continue;
            }
            $rows[] = [
                'ref' => (string) $ttId,
                'sku' => (string) ($skus[0] ?? ''),
                'skus' => $skus,
                'channel_id' => (string) $ttId,
                'name' => (string) ($p['title'] ?? ''),
                'image_url' => (string) ($p['image'] ?? ''),
                'variants' => count($skus),
            ];
        }

        return [['found' => $found, 'rows' => $rows, 'truncated' => (bool) ($shop['truncated'] ?? false)], null];
    }

    /** Import one fetched product, re-read live by its id. */
    /**
     * The Import page's picture for one TikTok product, read from the product
     * detail and kept for a day: TikTok's product search answers no pictures
     * at all, only the detail does (the owner, 2026-09-07: the fetch showed
     * none while the import saved them). One row asks only when it scrolls
     * into view, so a page of fifty costs fifty reads once, not every load.
     */
    public function thumb(string $ref, TikTokClient $client)
    {
        $c = $this->creds();
        if (!$c || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $ref)) {
            return response()->json(['url' => '']);
        }
        $key = 'tiktok:thumb:' . (int) (TikTokSetting::defaultStore()?->id ?? 0) . ':' . $ref;
        $url = \Illuminate\Support\Facades\Cache::remember($key, now()->addDay(), function () use ($ref, $c, $client) {
            try {
                $r = $client->getProduct($c['app_key'], $c['app_secret'], $c['token'], $ref, $c['shop_cipher'] ?: null);
            } catch (\Throwable) {
                return '';
            }
            $url = (string) (data_get($r, 'body.data.main_images.0.urls.0') ?? data_get($r, 'body.data.main_images.0.url') ?? '');

            return preg_match('#^https://#i', $url) ? $url : '';
        });

        return response()->json(['url' => $url]);
    }

    public function importOne(Request $request, \Extensions\tiktok\Services\TikTok\TikTokItemImport $import)
    {
        $data = $request->validate(['ref' => 'required|string|max:64']);
        $c = $this->creds();
        if (!$c) {
            return redirect()->back()->with('error', 'Missing TikTok settings.');
        }

        $r = $import->import($c, (string) $data['ref']);

        return redirect()->back()->with($r['ok'] ? 'status' : 'error', $r['message']);
    }

    /** Import selected: every ticked row, each in its own transaction. */
    public function importSelected(Request $request, \Extensions\tiktok\Services\TikTok\TikTokItemImport $import)
    {
        $data = $request->validate(['refs' => 'required|array|min:1', 'refs.*' => 'string|max:64']);
        $c = $this->creds();
        if (!$c) {
            return redirect()->back()->with('error', 'Missing TikTok settings.');
        }

        $done = 0;
        $failed = [];
        $refs = array_values(array_unique($data['refs']));
        foreach ($refs as $ref) {
            $r = $import->import($c, (string) $ref);
            if ($r['ok'] ?? false) {
                $done++;
            } else {
                $failed[] = '#' . $ref . ': ' . ($r['message'] ?? 'not imported');
            }
        }

        $line = \App\Support\BulkImportSummary::line($done, count($refs), $failed);

        return redirect()->back()->with($line['tone'], $line['message']);
    }

    /** Link a fetched product to a catalog product it already is. */
    public function linkItem(Request $request)
    {
        $data = $request->validate([
            'ref' => 'required|string|max:64',
            'product_id' => 'required|integer|min:1',
        ]);
        $productId = (int) $data['product_id'];

        $pfx = (string) config('catalog.prefix');
        if (! DB::table($pfx . 'product')->where('product_id', $productId)->exists()) {
            return redirect()->back()->with('error', 'Catalog product not found.');
        }

        /* One catalog product to one TikTok product per store, both ways
         * (the owner, 2026-09-07). A second link is refused with what holds
         * the first; this action used to overwrite the row and wipe its
         * SKU map, so a wrong pick silently re-pointed every push. */
        $held = TikTokListing::query()->where('product_id', $productId)->whereNotNull('tiktok_product_id')->value('tiktok_product_id')
            ?: TikTokProductGroupProduct::query()->onStore()->where('product_id', $productId)->whereNotNull('tiktok_product_id')->value('tiktok_product_id');
        if ($held && (string) $held !== (string) $data['ref']) {
            return redirect()->back()->with('error', 'Catalog product #' . $productId . ' is already linked to TikTok product ' . $held . '. Unlink it first.');
        }
        $taken = TikTokListing::query()->where('tiktok_product_id', (string) $data['ref'])->where('product_id', '!=', $productId)->value('product_id')
            ?: TikTokProductGroupProduct::query()->onStore()->where('tiktok_product_id', (string) $data['ref'])->where('product_id', '!=', $productId)->value('product_id');
        if ($taken) {
            return redirect()->back()->with('error', 'TikTok product ' . $data['ref'] . ' is already linked to catalog product #' . $taken . '. Unlink that product first.');
        }
        TikTokListing::updateOrCreate(
            ['product_id' => $productId],
            ['tiktok_product_id' => (string) $data['ref'], 'tiktok_sku_id' => null, 'live_status' => null, 'live_checked_at' => null]
        );

        return redirect()->back()->with('status', 'Linked TikTok product #' . $data['ref'] . ' to catalog product #' . $productId . '.');
    }

    // ── The Listings page's bulk verbs (parity, 2026-09-07) ─────────────

    public function bulkPushStock(Request $request, TikTokClient $client)
    {
        return $this->bulkFigures($request, $client, 'stock');
    }

    public function bulkPushPrice(Request $request, TikTokClient $client)
    {
        return $this->bulkFigures($request, $client, 'price');
    }

    private function bulkFigures(Request $request, TikTokClient $client, string $what)
    {
        $c = $this->creds();
        if (!$c) {
            return redirect()->back()->with('error', 'Missing TikTok settings.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('product_ids', [])), fn ($v) => $v > 0)));
        if ($ids === []) {
            return redirect()->back()->with('error', 'No products selected.');
        }
        $rows = [];
        $skipped = 0;
        $listings = TikTokListing::query()->whereIn('product_id', $ids)->get()->keyBy(fn ($l) => (int) $l->product_id);
        foreach ($ids as $pid) {
            $truth = $this->truthFor($pid);
            if (!$truth) {
                $skipped++;
                continue;
            }
            $rows[] = (object) ['product_id' => $pid, 'tiktok_product_id' => $truth->tiktok_product_id, 'tiktok_sku_id' => $truth->tiktok_sku_id];
        }
        if ($rows === []) {
            return redirect()->back()->with('error', 'None of the selected products is on TikTok Shop, so there is nothing to push.');
        }
        // The one price rule (2026-09-07): the listing's own, else its group's, else the catalog price.
        $results = app(\Extensions\tiktok\Services\TikTok\TikTokStockPricePush::class)->push(
            $what === 'price' ? 'price' : 'stock', $c, $rows, (string) config('catalog.prefix'),
            \Extensions\tiktok\Commands\TikTokPushPrice::priceRule($rows)
        );
        \Extensions\tiktok\Services\TikTok\TikTokStockPricePush::recordOutcomes($what === 'price' ? 'price' : 'stock', $results['outcomes'], $c['setting'] ?? null);
        $clause = \Extensions\tiktok\Services\TikTok\TikTokStockPricePush::ledgerClause($results);
        $msg = ucfirst($what) . " pushed to TikTok Shop: {$results['ok']} updated" . ($results['err'] > 0 ? ", {$results['err']} failed" : '') . ($skipped > 0 ? ", {$skipped} skipped (not on TikTok Shop)" : '') . '.' . $clause;

        return redirect()->back()->with($results['err'] > 0 ? ($results['ok'] > 0 ? 'warning' : 'error') : 'status', $msg);
    }

    /** Delete from TikTok Shop for the ticked rows: the single verb, repeated. */
    public function bulkDeleteFromTikTok(Request $request, TikTokClient $client)
    {
        $c = $this->creds();
        if (!$c) {
            return redirect()->back()->with('error', 'Missing TikTok settings.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('product_ids', [])), fn ($v) => $v > 0)));
        if ($ids === []) {
            return redirect()->back()->with('error', 'No products selected.');
        }
        $deleted = 0;
        $skipped = 0;
        $errors = [];
        foreach ($ids as $pid) {
            $truth = $this->truthFor($pid);
            if (!$truth) {
                $skipped++;
                continue;
            }
            try {
                $result = $client->deleteProducts($c['app_key'], $c['app_secret'], $c['token'], [(string) $truth->tiktok_product_id], $c['shop_cipher'] ?: null);
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'status' => 0, 'body' => ['message' => \App\Support\TransportError::plain($e, 'TikTok Shop')]];
            }
            $this->log('DELETE', '/product/202309/products', ['product_ids' => [$truth->tiktok_product_id]], $result);
            if (!$this->ok($result) && !\App\Support\MarketplaceLink::looksMissingRemotely($this->message($result))) {
                $errors[] = "#{$pid}: " . $this->message($result);
                $this->states($c['setting'] ?? null)->recordOutcome($pid, 'Delete: ' . $this->message($result));
                continue;
            }
            TikTokListing::query()->where('product_id', $pid)->update(['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'live_status' => null, 'live_checked_at' => null]);
            TikTokProductGroupProduct::query()->onStore($c['setting'] ?? null)->where('product_id', $pid)->update(['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'sync_status' => 'pending']);
            $this->states($c['setting'] ?? null)->clearErrors([$pid]);
            $deleted++;
        }
        $msg = "Delete from TikTok Shop: {$deleted} deleted" . ($skipped > 0 ? ", {$skipped} skipped (not on TikTok Shop)" : '') . ($errors ? ', ' . count($errors) . ' failed. ' . implode('; ', array_slice($errors, 0, 3)) : '') . '.';

        return redirect()->back()->with($errors ? ($deleted > 0 ? 'warning' : 'error') : 'status', $msg);
    }

    /** Remove from this channel, for the ticked rows: the single verb, repeated. */
    public function bulkRemoveFromStore(Request $request)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('product_ids', [])), fn ($v) => $v > 0)));
        $n = 0;
        foreach ($ids as $pid) {
            if (!TikTokStoreProducts::has($pid)) {
                continue;
            }
            TikTokProductGroupProduct::query()->where('product_id', $pid)
                ->whereIn('tiktok_product_group_id', TikTokProductGroup::query()->select('id'))->delete();
            TikTokListing::query()->where('product_id', $pid)->delete();
            $n++;
        }

        return redirect()->back()->with('status', "Removed {$n} " . ($n === 1 ? 'product' : 'products') . ' from this channel. Anything already on TikTok Shop stays up until you delete it there.');
    }

    public function pushStock(int $productId, TikTokClient $client)
    {
        return $this->pushFigures($productId, $client, 'stock');
    }

    public function pushPrice(int $productId, TikTokClient $client)
    {
        return $this->pushFigures($productId, $client, 'price');
    }

    /** Stock or price for one product through the shared resolver. */
    private function pushFigures(int $productId, TikTokClient $client, string $what)
    {
        $c = $this->creds();
        $truth = $this->truthFor($productId);
        if (!$c || !$truth) {
            return redirect()->back()->with('error', ucfirst($what) . ' push failed: ' . (!$c ? 'missing TikTok settings.' : 'this product is not on TikTok Shop.'));
        }
        $listing = TikTokListing::query()->where('product_id', $productId)->first();
        $pseudo = (object) ['product_id' => $productId, 'tiktok_product_id' => $truth->tiktok_product_id, 'tiktok_sku_id' => $truth->tiktok_sku_id];

        /* The shared sender (Phase F, #184): skip ledger, per-SKU verdicts,
         * the one-shot map repair, the shared tone. */
        // The one price rule (2026-09-07): the listing's own, else its group's, else the catalog price.
        $results = app(\Extensions\tiktok\Services\TikTok\TikTokStockPricePush::class)->push(
            $what === 'price' ? 'price' : 'stock', $c, [$pseudo], (string) config('catalog.prefix'),
            \Extensions\tiktok\Commands\TikTokPushPrice::priceRule([$pseudo])
        );
        \Extensions\tiktok\Services\TikTok\TikTokStockPricePush::recordOutcomes($what === 'price' ? 'price' : 'stock', $results['outcomes'], $c['setting'] ?? null);
        $clause = \Extensions\tiktok\Services\TikTok\TikTokStockPricePush::ledgerClause($results);

        if ($results['ok'] === 0) {
            return redirect()->back()->with('error', ucfirst($what) . ' push failed: ' . (($results['last_error'] ?: null) ?? 'Unknown error') . $clause);
        }

        return redirect()->back()->with('status', ucfirst($what) . ' pushed to TikTok Shop.' . $clause);
    }

    /** Forget the link; the TikTok product stays up. */
    public function unlink(int $productId)
    {
        // The mirror described the link that was just cut (#184 D): a kept
        // status would be stale forever, and a later re-match would wear it.
        TikTokListing::query()->where('product_id', $productId)->update(['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'live_status' => null, 'live_checked_at' => null]);
        TikTokProductGroupProduct::query()->onStore()->where('product_id', $productId)->update(['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'sync_status' => 'unlinked']);
        $this->states()->clearErrors([$productId]);

        return redirect()->back()->with('status', 'Unlinked. The TikTok Shop product stays up and can be matched again later.');
    }

    /** Delete the product at TikTok Shop and forget the link. */
    public function deleteFromTikTok(int $productId, TikTokClient $client)
    {
        $c = $this->creds();
        $truth = $this->truthFor($productId);
        if (!$c || !$truth) {
            return redirect()->back()->with('error', 'Delete failed: ' . (!$c ? 'missing TikTok settings.' : 'this product is not on TikTok Shop.'));
        }
        try {
            $result = $client->deleteProducts($c['app_key'], $c['app_secret'], $c['token'], [(string) $truth->tiktok_product_id], $c['shop_cipher'] ?: null);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'status' => 0, 'body' => ['message' => \App\Support\TransportError::plain($e, 'TikTok Shop')]];
        }
        $this->log('DELETE', '/product/202309/products', ['product_ids' => [$truth->tiktok_product_id]], $result);
        if (!$this->ok($result)) {
            $this->states($c['setting'] ?? null)->recordOutcome($productId, 'Delete: ' . $this->message($result));

            return redirect()->back()->with('error', 'Delete failed: ' . $this->message($result));
        }
        // The link is gone; void the mirror rather than keep a status the
        // sweep can no longer correct (#184 D).
        TikTokListing::query()->where('product_id', $productId)->update(['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'live_status' => null, 'live_checked_at' => null]);
        TikTokProductGroupProduct::query()->onStore($c['setting'] ?? null)->where('product_id', $productId)->update(['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'sync_status' => 'pending']);
        $this->states($c['setting'] ?? null)->clearErrors([$productId]);

        return redirect()->back()->with('status', 'Deleted from TikTok Shop.');
    }

    // ── Combobox endpoints (the door is keyed on this controller) ─────

    public function searchCategories(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $rows = TikTokCategory::query()->where('is_leaf', true)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', '%' . $q . '%')->orWhere('id', $q)))
            ->orderBy('name')->limit(30)->get(['id', 'name']);

        return response()->json(['ok' => true, 'categories' => $rows->map(fn ($c) => ['category_id' => (string) $c->id, 'name' => (string) $c->name])->values()->all()]);
    }

    public function categoryLookup(Request $request)
    {
        $id = trim((string) $request->input('category_id', ''));
        $row = $id !== '' ? TikTokCategory::query()->where('id', $id)->first(['id', 'name']) : null;
        if (!$row) {
            return response()->json(['ok' => false], 404);
        }

        return response()->json(['ok' => true, 'category' => ['category_id' => (string) $row->id, 'name' => (string) $row->name]]);
    }

    public function fetchAttributes(Request $request)
    {
        return app(TikTokProductGroupController::class)->fetchAttributesAjax($request);
    }

    /** TikTok's brand list by name, live. */
    public function brandsSearch(Request $request, TikTokClient $client)
    {
        $q = trim((string) $request->input('q', ''));
        $c = $this->creds();
        if (!$c) {
            return response()->json(['ok' => false, 'message' => 'Missing TikTok settings.'], 422);
        }
        try {
            $result = $client->get($c['app_key'], $c['app_secret'], $c['token'], '/product/202309/brands',
                array_filter(['page_size' => 50, 'brand_name' => $q !== '' ? $q : null, 'category_id' => trim((string) $request->input('category_id', '')) ?: null]),
                $c['shop_cipher'] ?: null);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => \App\Support\TransportError::plain($e, 'TikTok Shop')], 502);
        }
        $this->log('GET', '/product/202309/brands', ['brand_name' => $q], $result);
        if (!$this->ok($result)) {
            return response()->json(['ok' => false, 'message' => $this->message($result)], 502);
        }
        $brands = [];
        foreach ((array) ($result['body']['data']['brands'] ?? []) as $b) {
            if (!empty($b['id'])) {
                $brands[] = ['brand_id' => (string) $b['id'], 'name' => (string) ($b['name'] ?? $b['id'])];
            }
        }

        return response()->json(['ok' => true, 'brands' => $brands]);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /** The product's TikTok truth: the listing record, then the pivots. */
    private function truthFor(int $productId): ?object
    {
        $listing = TikTokListing::query()->where('product_id', $productId)->first();
        if ($listing && $listing->tiktok_product_id) {
            return (object) ['tiktok_product_id' => $listing->tiktok_product_id, 'tiktok_sku_id' => $listing->tiktok_sku_id];
        }
        $pivot = TikTokProductGroupProduct::query()->onStore()->where('product_id', $productId)->whereNotNull('tiktok_product_id')->orderByDesc('last_pushed_at')->first();

        return $pivot ? (object) ['tiktok_product_id' => $pivot->tiktok_product_id, 'tiktok_sku_id' => $pivot->tiktok_sku_id] : null;
    }

    /** The push settings from the product's OWN listing record. */
    private function listingPushOptions(int $productId, array $c): array
    {
        /* A group is a preset the product inherits (the owner, 2026-09-05): a
         * blank category is filled from the group, in memory only - for a
         * product with no row yet as well, the way readiness judges it. */
        $listing = TikTokListing::query()->where('product_id', $productId)->first() ?? (new TikTokListing())->forceFill(['product_id' => $productId]);
        $inherit = app(\Extensions\tiktok\Services\TikTok\TikTokInheritedSettings::class);
        $listing = $inherit->fill($listing, $inherit->forProducts([$productId])[$productId] ?? null)['listing'];
        $service = app(TikTokAttributes::class);
        $template = $listing && $listing->tiktok_category_id
            ? $service->ensureTemplate((string) $listing->tiktok_category_id, app(TikTokClient::class), $c)
            : null;

        return [
            'category_id' => (string) ($listing->tiktok_category_id ?? ''),
            'brand_id' => $listing->brand_id ?? null,
            'attributes' => $service->payload($service->rows($template), $listing->attribute_values ?? []),
            'priceFor' => \Extensions\tiktok\Services\TikTok\TikTokInheritedSettings::priceRule($listing, $inherit->groupModel($productId)),
            'warehouse_id' => $c['warehouse_id'] ?: null,
            'title' => $listing->title ?? null,
            'description' => $listing->description ?? null,
            'image_order' => $listing->image_order ?? null,
        ];
    }

    private function erpProduct(int $productId): ?object
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        return DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->where('p.product_id', $productId)
            ->first(['p.product_id', 'pd.name', 'pd.description', 'p.model', 'p.sku', 'p.price', 'p.quantity', 'p.image', 'p.weight', 'p.length', 'p.width', 'p.height', 'p.status']);
    }

    /** The listing-state engine, pinned to the store this request acts for. */
    private function states(?TikTokSetting $store = null): \Extensions\tiktok\Services\TikTok\TikTokListingStates
    {
        $engine = app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class);
        $store ??= TikTokSetting::defaultStore();

        return $store ? $engine->forStore($store) : $engine;
    }

    private function creds(): ?array
    {
        $s = TikTokSetting::defaultStore();
        if (!$s) {
            return null;
        }
        $d = $s->decrypted();
        $sandbox = $s->mode === 'sandbox';
        $c = [
            'setting' => $s,
            'app_key' => $sandbox ? ($d->sandbox_app_key ?? '') : ($d->app_key ?? ''),
            'app_secret' => $sandbox ? ($d->sandbox_app_secret ?? '') : ($d->app_secret ?? ''),
            'token' => $sandbox ? ($d->sandbox_access_token ?? '') : ($d->access_token ?? ''),
            'shop_cipher' => $sandbox ? ($s->sandbox_shop_cipher ?? '') : ($s->shop_cipher ?? ''),
            'warehouse_id' => $sandbox ? ($s->sandbox_warehouse_id ?? '') : ($s->warehouse_id ?? ''),
        ];

        return ($c['app_key'] && $c['app_secret'] && $c['token']) ? $c : null;
    }

    private function ok(array $result): bool
    {
        return ($result['ok'] ?? false) && (int) ($result['body']['code'] ?? -1) === 0;
    }

    private function message(array $result): string
    {
        // The one reader that knows where every channel hides its reason.
        return \App\Support\MarketplaceAnswer::errorText('TikTok Shop', $result);
    }

    private function log(string $method, string $path, array $params, array $result): void
    {
        TikTokApiLog::safeCreate([
            'pack' => 'tiktok.listings', 'method' => $method, 'api_path' => $path, 'auth_required' => true,
            'request_params' => $params, 'response_status' => $result['status'] ?? 0,
            'ok' => (bool) ($result['ok'] ?? false), 'response_body' => $result['body'] ?? [], 'user_id' => auth()->id(),
        ]);
    }
}
