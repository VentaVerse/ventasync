<?php

namespace Extensions\lazada\Controllers;

use App\Http\Controllers\Controller;

use Extensions\lazada\Models\LazadaCategoryTemplate;
use Extensions\lazada\Models\LazadaCategory;
use Extensions\lazada\Models\LazadaBrand;
use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaImageLink;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductAttribute;
use Extensions\lazada\Models\LazadaProductGroupAttribute;
use Extensions\lazada\Models\LazadaProductVariant;
use Extensions\lazada\Models\LazadaSetting;
use App\Services\ActivityLogger;
use Extensions\lazada\Services\Lazada\LazadaAttributes;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Extensions\lazada\Services\Lazada\LazadaImages;
use Extensions\lazada\Services\Lazada\LazadaItemCache;
use Extensions\lazada\Services\Lazada\LazadaItemMatcher;
use Extensions\lazada\Services\Lazada\LazadaPushPayload;
use Extensions\lazada\Services\Lazada\LazadaVariationSwitch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LazadaProductController extends Controller
{
    use \App\Http\Controllers\Concerns\ComparesWithTheCatalog;

    // NOTE: We standardize on lazada_product_id (no legacy lazada_listing_id support).

    /** The store's listing of the product: the one on Lazada, else the newest. */
    protected function catalogChangeListing(int $productId): \Illuminate\Database\Eloquent\Model
    {
        $rows = LazadaProduct::query()->where('product_id', $productId)->orderBy('id')->get();
        abort_if($rows->isEmpty(), 404);

        return $rows->first(fn ($l) => ! empty($l->lazada_item_id) && ! $l->unlinked_at) ?? $rows->last();
    }

    /**
     * Whether each of this store's products could be pushed right now, listed
     * or not, answered on the same row the row's own Readiness reads (the one
     * linked to Lazada, else the newest), so a menu count and the rows it
     * leads to agree. Held for a minute: the menu counts it on every page.
     *
     * @return array<int, bool>  product id => ready
     */
    private function readyByProduct(): array
    {
        return array_map(fn (array $a) => (bool) $a['ready'], $this->readinessRich());
    }

    /**
     * The same answer, keeping its gap codes, so the menu can count causes.
     *
     * @return array<int, list<string>>  product id => gap codes
     */
    private function gapsByProduct(): array
    {
        return array_map(
            fn (array $a) => array_values(array_map(fn ($g) => (string) ($g['code'] ?? ''), (array) $a['gaps'])),
            $this->readinessRich()
        );
    }

    /** Held for the request: the menu's several count passes read it once. */
    private ?array $readinessRich = null;

    /**
     * Ready + gaps for every product on the store, read from the materialised
     * readiness cache and recomputed only for products whose inputs changed
     * (catalogue edit, listing edit/push, group preset) — O(1) in catalogue
     * size on a warm load, never stale.
     *
     * @return array<int, array{ready: bool, gaps: list<array{code: string, label: string}>}>
     */
    private function readinessRich(): array
    {
        if ($this->readinessRich !== null) {
            return $this->readinessRich;
        }
        $storeId = (int) (LazadaSetting::defaultStore()?->id ?? 0);
        $scope = LazadaProduct::query()->whereNotNull('product_id')->distinct()
            ->pluck('product_id')->map(fn ($v) => (int) $v)->all();

        return $this->readinessRich = \App\Integrations\Listings\ReadinessCache::resolve(
            'lazada', $storeId, $scope,
            fn (array $ids) => $this->lazadaReadinessStamps($ids, $storeId),
            fn (array $ids) => $this->lazadaReadinessCompute($ids),
        );
    }

    /** The inputs a product's Lazada readiness depends on, as one stamp. */
    private function lazadaReadinessStamps(array $ids, int $storeId): array
    {
        $pfx = (string) config('catalog.prefix');
        $prod = DB::table($pfx . 'product')->whereIn('product_id', $ids)->pluck('date_modified', 'product_id');
        $listing = DB::table('lazada_products')->whereIn('product_id', $ids)
            ->selectRaw('product_id, MAX(updated_at) as mu')->groupBy('product_id')->pluck('mu', 'product_id');
        $group = DB::table('lazada_product_group_products as gp')
            ->join('lazada_product_groups as g', 'g.id', '=', 'gp.lazada_product_group_id')
            ->whereIn('gp.product_id', $ids)->where('g.lazada_setting_id', $storeId)
            ->selectRaw('gp.product_id, MAX(g.updated_at) as mu')->groupBy('gp.product_id')->pluck('mu', 'product_id');

        $out = [];
        foreach ($ids as $pid) {
            $out[(int) $pid] = md5(($prod[$pid] ?? '') . '|' . ($listing[$pid] ?? '') . '|' . ($group[$pid] ?? ''));
        }

        return $out;
    }

    /**
     * Fresh readiness for the given product ids, one batched pass through the
     * engine. The listing judged is the one the product carries: a linked row
     * if any, else the last.
     *
     * @return array<int, array{ready: bool, gaps: list<array{code: string, label: string}>}>
     */
    private function lazadaReadinessCompute(array $ids): array
    {
        $readiness = app(\Extensions\lazada\Services\Lazada\LazadaListingReadiness::class);
        $chosen = LazadaProduct::query()->whereNotNull('product_id')->whereIn('product_id', $ids ?: [0])
            ->orderBy('id')->get()->groupBy('product_id')
            ->map(fn ($rows) => $rows->first(fn ($l) => !empty($l->lazada_item_id) && !$l->unlinked_at) ?? $rows->last());
        $answers = $chosen->isEmpty() ? [] : $readiness->forListings($chosen->values());

        $out = [];
        foreach ($ids as $pid) {
            $row = $chosen->get($pid);
            $r = $row ? ($answers[(int) $row->id] ?? null) : null;
            $out[(int) $pid] = $r === null
                ? ['ready' => false, 'gaps' => [['code' => 'unknown', 'label' => 'not checked yet']]]
                : ['ready' => (bool) ($r['ready'] ?? false), 'gaps' => array_values((array) ($r['gaps'] ?? []))];
        }

        return $out;
    }

    protected function catalogChangeFallback(int $productId): string
    {
        return route('ext.lazada.products.edit', $productId);
    }

    /** The attribute vocabulary lives in its own service now (Phase C, #184). */
    private function attrs(): LazadaAttributes
    {
        return app(LazadaAttributes::class);
    }

    /** The item read-back and its four caches (Phase C, #184). */
    private function itemCache(): LazadaItemCache
    {
        return app(LazadaItemCache::class);
    }

    /** Image URL shapes and the inlink migration (Phase C, #184). */
    private function images(): LazadaImages
    {
        return app(LazadaImages::class);
    }

    /** SKU-to-item matching against the shop (Phase C, #184). */
    private function matcher(): LazadaItemMatcher
    {
        return app(LazadaItemMatcher::class);
    }

    /** The wire payloads and the reading of Lazada's answers (Phase C, #184). */
    private function payload(): LazadaPushPayload
    {
        return app(LazadaPushPayload::class);
    }


    /**
     * Build a unique ordered list of product images (main + additional) as public URLs.
     */
    


private function persistProductSyncStatus(LazadaProduct $listing, string $action, array $result, ?string $pushSource = null): void
{
    $e = $this->payload()->extractLazadaError($result);

    $listing->last_synced_at = now();
    $listing->last_sync_action = $action;
    $listing->last_sync_ok = (bool)($e['ok'] ?? false);
    $listing->last_sync_error_code = $e['code'] ?? null;
    $listing->last_sync_error_message = $e['message'] ?? null;
    if ($e['ok'] ?? false) {
        // One latest error per product (the owner, 2026-09-05): a success clears the shared refusal.
        $listing->last_push_error = null;
        $listing->last_push_failed_at = null;
    }

    /* Provenance (#104, Lazada half): a SUCCESSFUL content push records
     * what went and where its settings came from - 'listing' for pushes
     * run on the listing's own record, 'group:{name}' for group pushes.
     * Sync and failure calls pass no source and stamp nothing. */
    if ($pushSource !== null && ($e['ok'] ?? false)) {
        $listing->last_pushed_at = now();
        $listing->last_push_source = $pushSource;
        $listing->last_push_settings = [
            'category_id' => (int) ($listing->primary_category_id ?? 0),
            'brand_id' => (int) ($listing->brand_id ?? 0),
            'markup_percent' => $listing->markup_percent,
            'markup_fixed' => $listing->markup_fixed,
        ];
    }

    // Avoid throwing if DB schema isn't migrated yet.
    try {
        $listing->save();
    } catch (\Throwable $ex) {
        // ignore
    }
}

    /** The error line engine for the store this request stands in. */
    private function states(): \Extensions\lazada\Services\Lazada\LazadaListingStates
    {
        return app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class);
    }

    /** Listings-strip tab key => the status word Lazada returns on an item (and the mirror stores). */
    public const TAB_STATUS = [
        'active' => 'active',
        'inactive' => 'inactive',
        'pending' => 'pending',
        'violation' => 'rejected',
        'soldout' => 'sold-out',
        'deleted' => 'deleted',
    ];

    public function index(Request $request)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        /* ── Two surfaces on one workbench (#201, the owner 2026-09-05) ──
         *
         * THIS STORE is what this store carries and works day to day - its
         * own listing rows, with the seller-center state tabs. ADD PRODUCTS is
         * the main catalogue, where a product is added into the store's list.
         * Multi-store is what earned the split: a store's daily table stops
         * mirroring the whole catalogue and shows only what it consolidated.
         * The add surface has its own lean flow, so it returns before all the
         * live-status machinery below, which it does not need. */
        $listTab = $request->query('list') === 'add' ? 'add' : 'store';
        if ($listTab === 'add') {
            return $this->addCatalogue($request);
        }

        /* ── The seller center's own tabs (Lazada parity, 2026-08-24) ── */
        $tabMap = [
            'active' => 'live',
            'inactive' => 'inactive',
            'pending' => 'pending',
            'violation' => 'rejected',
            'soldout' => 'sold-out',
            'deleted' => 'deleted',
        ];
        $lazadaTab = (string) $request->query('lazada_tab', 'all');
        if (!array_key_exists($lazadaTab, $tabMap)) {
            $lazadaTab = 'all';
        }

        /* Since 2026-08-28 the counts and the per-row statuses come from
         * the MIRROR on the listing rows (live_status, live_checked_at),
         * refreshed every 30 minutes by the automation, on demand by the
         * Refresh button, and written through by every push, toggle,
         * match, delete and unlink the ERP makes (#184 C). A page view
         * asks Lazada for nothing beyond filling its own blanks. */
        $liveSetting = LazadaSetting::defaultStore()?->decrypted();
        $liveCreds = LazadaSetting::activeCredentials($liveSetting);
        $liveCounts = null;
        $liveTabError = null;
        $liveCheckedAt = null;
        if ($liveSetting && $liveCreds['complete']) {
            // Counted below, against the rows the status menu has chosen.
            $liveCounts = [];
            $liveCheckedAt = LazadaProduct::query()->max('live_checked_at');
            $liveCheckedAt = $liveCheckedAt ? \Illuminate\Support\Carbon::parse($liveCheckedAt) : null;
        } else {
            // Same silence Shopee had: the note existed and nothing set it.
            $liveTabError = 'Missing Lazada settings.';
            $lazadaTab = 'all';
        }

        // Search & filter params
        $q = trim((string) $request->query('q', ''));
        $syncStatus = (string) $request->query('sync_status', 'all');
        if (!in_array($syncStatus, [...\App\Integrations\Listings\StatusMenu::VALUES, 'deleted', 'error'], true)) {
            $syncStatus = 'all';
        }
        /* The status menu's two switches narrow whatever the tree has
         * chosen; an old sync_status=error link is Push failed switched on. */
        $failedFlag = $request->boolean('failed') || $syncStatus === 'error';
        $changeFlag = $request->boolean('change');
        if ($syncStatus === 'error') {
            $syncStatus = 'all';
        }
        /* One cause at a time: the menu counts WHY a product cannot go, and
         * this is the address that choice lives at. */
        $gapFilter = \App\Integrations\Listings\StatusMenu::cause($request->query('gap'));
        $groupFilter = (string) $request->query('group', 'all');
        $manufacturerFilter = (string) $request->query('manufacturer', 'all');
        $erpStatus = (string) $request->query('erp_status', 'all');

        /* Sorting: allow-listed and mapped to a real column, ordered in
         * SQL - this page paginates at the database now (Phase C, #184),
         * where it used to load every row and sort a collection. */
        $sortColumns = [
            'id' => 'p.product_id',
            'product' => 'pd.name',
            'quantity' => 'p.quantity',
            'price' => 'p.price',
            'product_status' => 'p.status',
            'manufacturer' => 'm.name',
        ];
        // Listing-side sorts are expressions over the joined row, stated
        // here rather than interpolated from input.
        $sortExpressions = [
            'lazada_item_id' => "(lp.lazada_item_id IS NULL OR lp.lazada_item_id = ''), lp.lazada_item_id",
            'lazada_status' => "CASE WHEN lp.lazada_item_id IS NOT NULL AND lp.lazada_item_id <> '' THEN 2 WHEN lp.lazada_deleted_at IS NOT NULL THEN 1 ELSE 0 END",
            'last_sync' => "CASE WHEN lp.last_sync_ok IS NULL THEN 0 WHEN lp.last_sync_ok = 1 THEN 1 ELSE 2 END, lp.last_synced_at",
        ];
        $sort = (string) $request->query('sort', 'id');
        if (!array_key_exists($sort, $sortColumns) && !array_key_exists($sort, $sortExpressions)) {
            $sort = 'id';
        }
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        /* ── The catalogue is the table (Phase C, #184) ──
         *
         * The query starts from the PRODUCT, exactly as Shopee's does,
         * and left-joins the listing row. Before this the page listed
         * lazada_products rows only - the CedCommerce worldview, where a
         * product existed for Lazada once someone had made a mapping row
         * for it. A product without one was invisible even under the
         * "Not listed" filter, the "All products" count was really the
         * mapping count, and every sort loaded the whole table into
         * memory. One preferred listing row per product: the one actually
         * linked to Lazada, else the newest. */
        $lpPick = DB::table('lazada_products')
            ->selectRaw("product_id, COALESCE(MAX(CASE WHEN lazada_item_id IS NOT NULL AND lazada_item_id <> '' AND unlinked_at IS NULL THEN id END), MAX(id)) as pick_id")
            ->whereNotNull('product_id')
            // Raw DB::table bypasses the BelongsToLazadaStore global scope, so
            // scope the listing pick to the current store explicitly.
            ->when(app()->bound('lazada.route-store'), fn ($q) => $q->where('lazada_setting_id', app('lazada.route-store')->id))
            ->groupBy('product_id');

        $query = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->leftJoinSub($lpPick, 'lps', 'lps.product_id', '=', 'p.product_id')
            ->leftJoin('lazada_products as lp', 'lp.id', '=', 'lps.pick_id')
            ->select(
                'p.product_id', 'pd.name', 'p.image', 'p.model', 'p.sku',
                'p.price', 'p.quantity', 'p.status', 'm.name as manufacturer_name',
                'lp.id as listing_id', 'lp.lazada_item_id', 'lp.live_status',
                'lp.lazada_deleted_at', 'lp.unlinked_at',
                'lp.last_sync_ok', 'lp.last_synced_at',
                'lp.last_sync_error_code', 'lp.last_sync_error_message'
            );

        /* THIS STORE is the store's own list (#201): only products that have a
         * listing row on this store. A catalogue product with none is not on
         * this store yet - it lives under Add products until it is added. This
         * is the one line that turns "the whole catalogue" into "this store". */
        $query->whereNotNull('lp.id');

        // Product group filter, through the pivot's denormalised product_id
        if ($groupFilter === 'none') {
            // Grouped on this store; another store's group does not count.
            $groupedIds = \Extensions\lazada\Models\LazadaProductGroupProduct::query()->onStore()->whereNotNull('product_id')->pluck('product_id')->unique()->all();
            if (!empty($groupedIds)) {
                $query->whereNotIn('p.product_id', $groupedIds);
            }
        } elseif ($groupFilter !== 'all') {
            $specificIds = DB::table('lazada_product_group_products')
                ->where('lazada_product_group_id', (int) $groupFilter)
                ->whereNotNull('product_id')->pluck('product_id')->unique()->all();
            $query->whereIn('p.product_id', $specificIds ?: [0]);
        }

        // Manufacturer filter
        if ($manufacturerFilter !== 'all') {
            $query->where('p.manufacturer_id', (int) $manufacturerFilter);
        }

        // ERP Status filter
        if ($erpStatus === 'enabled') {
            $query->where('p.status', 1);
        } elseif ($erpStatus === 'disabled') {
            $query->where('p.status', 0);
        } else {
            /* The default view (#197): a disabled product Lazada does not
             * hold is noise; a disabled product still listed needs eyes.
             * The catalog-status filter still offers Disabled on purpose. */
            $query->where(function ($sub) {
                $sub->where('p.status', 1)
                    ->orWhereIn('p.product_id', LazadaProduct::query()
                        ->whereNotNull('lazada_item_id')->whereNull('lazada_deleted_at')->select('product_id'));
            });
        }

        // Text search: catalogue fields, or the Lazada item id itself
        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.model', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%')
                    ->orWhere('lp.lazada_item_id', 'like', '%' . $q . '%')
                    // The row shows this store's own title, so the search finds it by that too.
                    ->orWhereIn('p.product_id', LazadaProduct::query()
                        ->forStore(LazadaSetting::defaultStore())
                        ->where('item_name', 'like', '%' . $q . '%')
                        ->select('product_id'));
            });
        }

        /* One definition of each place in the tree, used for the rows and
         * for the menu's counts alike, so a number and its list agree. */
        $listed = fn ($query) => $query->whereNotNull('lp.lazada_item_id')->where('lp.lazada_item_id', '!=', '');
        $notListed = fn ($query) => $query->where(function ($sub) {
            $sub->whereNull('lp.lazada_item_id')->orWhere('lp.lazada_item_id', '=', '');
        })->whereNull('lp.lazada_deleted_at');
        // Held for this request: readyByProduct scans the whole catalog, and the
        // menu counts ask for it several times. Computing it once, not ~6x, is the
        // difference between a slow page and a fast one on a large store.
        $readyMap = null;
        $readyIs = function ($query, bool $wantReady) use (&$readyMap) {
            $readyMap ??= $this->readyByProduct();
            $matching = array_keys(array_filter($readyMap, fn ($r) => $r === $wantReady));

            return $query->whereIn('p.product_id', $matching ?: [0]);
        };
        $applySync = function ($query, string $status) use ($listed, $notListed, $readyIs) {
            match ($status) {
                'uploaded' => $listed($query),
                'listed_ready' => $readyIs($listed($query), true),
                'listed_not_ready' => $readyIs($listed($query), false),
                'not_uploaded' => $notListed($query),
                'ready' => $readyIs($notListed($query), true),
                'not_ready' => $readyIs($notListed($query), false),
                'deleted' => $query->whereNotNull('lp.lazada_deleted_at'),
                default => $query,
            };

            return $query;
        };

        /* A cause narrows the rows exactly as a status does: the products
         * whose readiness answer carries that gap code. */
        $gapMap = null;
        $withGap = function ($query, string $code) use (&$gapMap) {
            $gapMap ??= $this->gapsByProduct();
            $matching = array_keys(array_filter($gapMap, fn ($codes) => in_array($code, $codes, true)));

            return $query->whereIn('p.product_id', $matching ?: [0]);
        };
        $applyGap = fn ($query) => $gapFilter === 'all' ? $query : $withGap($query, $gapFilter);

        $errored = $this->states()->erroredProductIds();
        $changed = $this->states()->productIdsIn('drift');

        /* The status menu's choice, and the store's tab: each narrows the
         * rows, and each one's counts are taken under the other's choice. */
        $applyMenu = function ($query) use ($applySync, $applyGap, $syncStatus, $failedFlag, $changeFlag, $errored, $changed) {
            $applySync($query, $syncStatus);
            $applyGap($query);
            if ($failedFlag) {
                /* The rows that draw an error line, by the line's own engine. */
                $query->whereIn('p.product_id', $errored ?: [0]);
            }
            if ($changeFlag) {
                $query->whereIn('p.product_id', $changed ?: [0]);
            }

            return $query;
        };
        // A tab narrows the table to the items Lazada holds in that status. Only listed rows can match.
        $applyTab = function ($query) use ($lazadaTab, $liveCounts, $listed) {
            if ($lazadaTab !== 'all' && $liveCounts !== null) {
                $listed($query)->where('lp.live_status', self::TAB_STATUS[$lazadaTab]);
            }

            return $query;
        };
        $tabCounts = function ($query) use ($listed): array {
            $byStatus = $listed(clone $query)->whereNotNull('lp.live_status')
                ->select(DB::raw('lp.live_status as live'), DB::raw('COUNT(DISTINCT p.product_id) as n'))
                ->groupBy('lp.live_status')
                ->pluck('n', 'live');
            $out = [];
            foreach (self::TAB_STATUS as $tabKey => $status) {
                $out[$tabKey] = (int) ($byStatus[$status] ?? 0);
            }

            return $out;
        };

        $menuBase = $applyTab(clone $query);
        $menuCounts = ['all' => (clone $menuBase)->count()];
        foreach (['not_uploaded', 'ready', 'not_ready', 'uploaded', 'listed_ready', 'listed_not_ready'] as $status) {
            $menuCounts[$status] = $applySync(clone $menuBase, $status)->count();
        }
        $menuCounts['failed'] = (clone $menuBase)->whereIn('p.product_id', $errored ?: [0])->count();
        $menuCounts['change'] = (clone $menuBase)->whereIn('p.product_id', $changed ?: [0])->count();
        /* One number per cause, under everything chosen EXCEPT the cause
         * itself, counted within the half it hangs under. */
        $causeCounts = [];
        foreach (['not_ready', 'listed_not_ready'] as $half) {
            $halfBase = $applySync(clone $menuBase, $half);
            if ($failedFlag) {
                $halfBase->whereIn('p.product_id', $errored ?: [0]);
            }
            if ($changeFlag) {
                $halfBase->whereIn('p.product_id', $changed ?: [0]);
            }
            foreach (array_keys(\App\Integrations\Listings\StatusMenu::CAUSES) as $code) {
                $causeCounts[$half][$code] = $withGap(clone $halfBase, $code)->count();
            }
        }

        $tabBase = $applyMenu(clone $query);
        // The tab row's All: every row the menu has chosen, before a tab narrows it.
        $catalogueTotal = (clone $tabBase)->count();
        if ($liveCounts !== null) {
            $liveCounts = $tabCounts($tabBase);
        }

        $applyMenu($query);
        $applyTab($query);

        // Unlinked leftovers stay pinned to the top, as they always were:
        // a cut link is unfinished business, not one row among two thousand.
        $query->orderByRaw('(lp.unlinked_at IS NOT NULL) desc');
        if (isset($sortExpressions[$sort])) {
            $query->orderByRaw($sortExpressions[$sort] . ' ' . $dir);
        } else {
            $query->orderBy($sortColumns[$sort], $dir);
        }

        /* The dashboard's own figures, as a filter (#240): a chip that says
         * "3 listings need attention" links here with ?state=attention, and
         * the rows are fetched by the SAME query that counted them. */
        $troubleFilter = \App\Integrations\Listings\ListingTrouble::asked($request->query('state'));
        if ($troubleFilter !== null) {
            $troubleIds = app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class)->productIdsIn($troubleFilter);
            $query->whereIn('p.product_id', $troubleIds ?: [0]);
        }

        // A stable tiebreak, or two products with the same name swap places
        // between pages and a row appears twice while another never shows.
        if (($sortColumns[$sort] ?? null) !== 'p.product_id') {
            $query->orderBy('p.product_id', 'asc');
        }

        $products = $query->paginate(50)->withQueryString();

        foreach ($products as $row) {
            if (isset($row->name)) {
                $row->name = html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        $pageIds = $products->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $pageListingIds = $products->pluck('listing_id')->filter()->map(fn ($v) => (int) $v)->all();

        // The listing models for this page (groups ride along for display)
        $listingsById = LazadaProduct::query()
            ->with('groups')
            ->whereIn('id', $pageListingIds ?: [0])
            ->get()
            ->keyBy('id');
        // Each row is named by this store's own title, the catalog's name when it has none.
        $rowTitles = \App\Integrations\Listings\ListingContent::rowTitles($products, $listingsById);

        // Thumbnails and variation rows, page-sized
        $productThumbsById = collect();
        $optionRowsByProductId = collect();
        if (!empty($pageIds)) {
            $productThumbsById = $products->mapWithKeys(function ($r) {
                return [(int) $r->product_id => $this->images()->toDisplayImageUrl($r->image ?? null)];
            });
            $optionRowsByProductId = \App\Support\VariationRows::forListing($pageIds, 'lazada', (int) (LazadaSetting::defaultStore()?->id ?? 0));
        }

        /* The mirror fills its own blanks (the owner, 2026-08-28): a row with
         * an item id and no status is asked about once, up to ten per
         * view, then the page reads the mirror like every other row. */
        if ($liveCounts !== null) {
            $blank = $listingsById->filter(fn ($l) => !empty($l->lazada_item_id) && $l->live_status === null)->take(10);
            if ($blank->isNotEmpty()) {
                $live = app(\Extensions\lazada\Services\Lazada\LazadaLiveListing::class);
                $filled = false;
                foreach ($blank as $l) {
                    $answer = $live->fetch($liveSetting, $liveCreds, (string) $l->lazada_item_id);
                    $status = null;
                    if ($answer['live'] !== null && ($answer['live']['status'] ?? '') !== '') {
                        $status = (string) $answer['live']['status'];
                    } elseif ($answer['live'] === null && str_contains((string) $answer['error'], 'holds no record')) {
                        $status = 'missing';
                    }
                    if ($status === null) {
                        /* Lazada is not answering: leave the rest blank
                           rather than guessed, and SAY so - a swallowed
                           refusal reads as rows that went unsynced by
                           themselves (the owner, 2026-09-09). */
                        session()->now('error', \App\Integrations\Listings\BlankFillRefusal::sentence('Lazada', $answer['error'] ?? null));
                        break;
                    }
                    LazadaProduct::query()->where('id', $l->id)
                        ->update(['live_status' => $status, 'live_checked_at' => now()]);
                    $l->live_status = $status;
                    $filled = true;
                }
                if ($filled) {
                    $liveCounts = $tabCounts($tabBase);
                    $liveCheckedAt = $liveCheckedAt ?? now();
                }
            }
        }

        /* ONE state per row (Phase C, #184): the engine folds the mirror,
         * drift and readiness into a single ListingState the blade renders
         * verbatim - badge, readiness and row actions alike (#188).
         * Computed after the blank fill so it reads a filled mirror. */
        $listingStates = app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class)
            ->forProducts($pageIds);
        // The Last sync column (the owner, 2026-09-05), one answer shared with the group pages.
        $rowErrors = app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class)->errors($pageIds);

        // How many catalogue products actually stand on Lazada, for the
        // page's subtitle - an honest count, not the mapping-row count.
        $listedTotal = LazadaProduct::query()
            ->whereNotNull('lazada_item_id')->where('lazada_item_id', '!=', '')
            ->whereNull('unlinked_at')->whereNotNull('product_id')
            ->distinct()->count('product_id');



        $allManufacturers = DB::table($pfx . 'manufacturer')
            ->orderBy('name')
            ->pluck('name', 'manufacturer_id');

        $allGroups = \Extensions\lazada\Models\LazadaProductGroup::query()->orderBy('name')->pluck('name', 'id');

        return view('ext-lazada::products.index', [
            'products' => $products,
            'rowTitles' => $rowTitles,
            'troubleFilter' => $troubleFilter,
            'listingsById' => $listingsById,
            'listingStates' => $listingStates,
            'rowErrors' => $rowErrors,
            'productThumbsById' => $productThumbsById,
            'optionRowsByProductId' => $optionRowsByProductId,
            'allGroups' => $allGroups,
            'allManufacturers' => $allManufacturers,
            'sort' => $sort,
            'dir' => $dir,
            'q' => $q,
            'syncStatus' => $syncStatus,
            'manufacturerFilter' => $manufacturerFilter,
            'erpStatus' => $erpStatus,
            'groupFilter' => $groupFilter,
            'paginator' => $products,
            'lazadaTab' => $lazadaTab,
            'liveCounts' => $liveCounts,
            'liveTabError' => $liveTabError,
            'liveCheckedAt' => $liveCheckedAt,
            'catalogueTotal' => $catalogueTotal,
            'listedTotal' => $listedTotal,
            'listTab' => 'store',
            'failedFlag' => $failedFlag,
            'changeFlag' => $changeFlag,
            'statusMenu' => \App\Integrations\Listings\StatusMenu::build([
                'url' => fn (array $params) => route('ext.lazada.products.index', $params),
                'query' => $request->query(),
                'sync' => $syncStatus,
                'failed' => $failedFlag,
                'change' => $changeFlag,
                'counts' => $menuCounts,
                'gap' => $gapFilter,
                'causes' => $causeCounts,
                // Live is a fact about a marketplace: a product is live ON somewhere.
                'store' => 'Lazada',
            ]),
        ]);
    }

    /**
     * ADD PRODUCTS (#201, the owner 2026-09-05): the main catalogue, each product
     * shown with whether it is already on this store and, if not, an Add to
     * store action. This is where a store's list is populated. It is a lean
     * read - no live-status walk, no readiness engine, no orphan queue - so it
     * lives apart from index()'s daily table rather than branching through it.
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

        // One listing row per product for THIS store answers "already on this
        // store?" - the same store-scoped pick index() builds, kept lean.
        $lpPick = DB::table('lazada_products')
            ->selectRaw('product_id, MIN(id) as pick_id')
            ->whereNotNull('product_id')
            ->when(app()->bound('lazada.route-store'), fn ($sub) => $sub->where('lazada_setting_id', app('lazada.route-store')->id))
            ->groupBy('product_id');

        $query = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->leftJoinSub($lpPick, 'lps', 'lps.product_id', '=', 'p.product_id')
            ->select('p.product_id', 'pd.name', 'p.image', 'p.model', 'p.sku', 'p.price', 'p.quantity', 'p.status', 'm.name as manufacturer_name', 'lps.pick_id as listing_id');

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
            // Grouped on this store; another store's group does not count.
            $groupedIds = \Extensions\lazada\Models\LazadaProductGroupProduct::query()->onStore()->whereNotNull('product_id')->pluck('product_id')->unique()->all();
            if (! empty($groupedIds)) {
                $query->whereNotIn('p.product_id', $groupedIds);
            }
        } elseif ($groupFilter !== 'all') {
            $specificIds = DB::table('lazada_product_group_products')->where('lazada_product_group_id', (int) $groupFilter)->whereNotNull('product_id')->pluck('product_id')->unique()->all();
            $query->whereIn('p.product_id', $specificIds ?: [0]);
        }
        if ($onStore === 'on') {
            $query->whereNotNull('lps.pick_id');
        } elseif ($onStore === 'off') {
            $query->whereNull('lps.pick_id');
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

        $productThumbsById = $products->mapWithKeys(fn ($r) => [(int) $r->product_id => $this->images()->toDisplayImageUrl($r->image ?? null)]);

        // The two totals the header names: the whole catalogue, and how much of
        // it this store already carries.
        $catalogueTotal = (int) DB::table($pfx . 'product')->count();
        $onStoreTotal = (int) LazadaProduct::query()->whereNotNull('product_id')->distinct()->count('product_id');

        $allManufacturers = DB::table($pfx . 'manufacturer')->orderBy('name')->pluck('name', 'manufacturer_id');
        $allGroups = \Extensions\lazada\Models\LazadaProductGroup::query()->orderBy('name')->pluck('name', 'id');

        return view('ext-lazada::products.add', [
            'products' => $products,
            'paginator' => $products,
            'productThumbsById' => $productThumbsById,
            'allManufacturers' => $allManufacturers,
            'allGroups' => $allGroups,
            'q' => $q,
            'manufacturerFilter' => $manufacturerFilter,
            'groupFilter' => $groupFilter,
            'onStore' => $onStore,
            'sort' => $sort,
            'dir' => $dir,
            'catalogueTotal' => $catalogueTotal,
            'onStoreTotal' => $onStoreTotal,
            'listTab' => 'add',
        ]);
    }

    /**
     * Add one catalogue product to this store's list (#201): create the store
     * listing row, unpushed. It then appears under This store, ready to
     * configure and push through the unchanged workflow. The BelongsToLazada
     * Store trait stamps the store, so firstOrCreate never crosses stores or
     * doubles a row.
     */
    public function addToStore(Request $request, int $productId)
    {
        $exists = DB::table((string) config('catalog.prefix') . 'product')->where('product_id', $productId)->exists();
        abort_unless($exists, 404);

        $row = LazadaProduct::firstOrCreate(['product_id' => $productId]);

        // The Add products panel adds in place and asks for JSON (#201,
        // Draft C); the full catalogue page still posts a form and lands back.
        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'product_id' => $productId, 'added' => (bool) $row->wasRecentlyCreated]);
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

        $lpPick = DB::table('lazada_products')
            ->selectRaw('product_id, MIN(id) as pick_id')
            ->whereNotNull('product_id')
            ->when(app()->bound('lazada.route-store'), fn ($sub) => $sub->where('lazada_setting_id', app('lazada.route-store')->id))
            ->groupBy('product_id');

        $query = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->leftJoinSub($lpPick, 'lps', 'lps.product_id', '=', 'p.product_id')
            ->select('p.product_id', 'pd.name', 'p.image', 'p.sku', 'p.model', 'p.quantity', 'p.price', 'lps.pick_id as listing_id')
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
            $query->whereNull('lps.pick_id');
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('pd.name')->orderBy('p.product_id')->limit($limit)->get();

        $items = $rows->map(fn ($r) => [
            'id' => (int) $r->product_id,
            'name' => html_entity_decode((string) ($r->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Unnamed product',
            'sku' => (string) ($r->sku ?? ''),
            'quantity' => (int) ($r->quantity ?? 0),
            'price' => \App\Support\Money::base((float) ($r->price ?? 0)),
            'thumb' => $this->images()->toDisplayImageUrl($r->image ?? null),
            'on_store' => ! is_null($r->listing_id),
        ])->values();

        return response()->json(['total' => $total, 'shown' => $items->count(), 'limit' => $limit, 'items' => $items]);
    }

    /**
     * Remove a product from this store's list (#201) - the inverse of Add.
     * The listing row goes, variants and group memberships with it, so the
     * product leaves This store and STOPS SYNCING at once. Deliberately no
     * blocker (the owner, 2026-09-05): nobody keeps a product on the marketplace
     * while stripping it here. It never touches Lazada - a live listing stays
     * up until it is removed there, which the confirm says plainly and Delete
     * from Lazada already covers. The store-scoped findOrFail means a row on
     * another store can never be reached from this one.
     */
    /**
     * Every product route takes the CATALOG product id, the Shopee shape
     * (the owner, 2026-09-07). The store's listing row is resolved from it here:
     * it must exist for an action on a listing, it is a blank in memory for
     * the edit page, and it is made on the first save.
     */
    private function listingFor(int $productId): LazadaProduct
    {
        return LazadaProduct::query()->where('product_id', $productId)
            ->orderByRaw("(lazada_item_id IS NOT NULL AND lazada_item_id <> '' AND unlinked_at IS NULL) desc")
            ->orderByDesc('id')->firstOrFail();
    }

    private function listingOrBlank(int $productId): LazadaProduct
    {
        return LazadaProduct::query()->where('product_id', $productId)
            ->orderByRaw("(lazada_item_id IS NOT NULL AND lazada_item_id <> '' AND unlinked_at IS NULL) desc")
            ->orderByDesc('id')->first() ?? (new LazadaProduct())->forceFill(['product_id' => $productId]);
    }

    private function listingRowFor(int $productId): LazadaProduct
    {
        $row = $this->listingOrBlank($productId);
        if (! $row->exists) {
            $row->save();
            ActivityLogger::log('created', 'Lazada Product', $row->id, 'ERP #' . $productId);
        }

        return $row;
    }

    /** The bulk forms post product_ids[]; the actions work on the store's listing rows. @return list<int> */
    private function listingIdsFor(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), fn ($v) => $v > 0)));

        return $productIds === [] ? [] : LazadaProduct::query()->whereIn('product_id', $productIds)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public function removeFromStore(int $productId)
    {
        $listing = $this->listingFor($productId);
        $itemId = $listing->lazada_item_id;
        $productId = $listing->product_id;

        $this->states()->clearErrors([(int) $productId]);
        $listing->variants()->delete();
        $listing->groups()->detach();
        $listing->delete();

        $note = $itemId
            ? " It no longer syncs; Lazada item {$itemId} stays up until you remove it there."
            : ' It no longer syncs.';

        return redirect()->back()->with('status', "Removed product #{$productId} from this store." . $note);
    }

    /**
     * Add the ticked catalogue products to this store's list in one go (#201).
     */
    /** Remove from this channel, for the ticked rows: the single verb, repeated (parity, 2026-09-07). */
    public function bulkRemoveFromStore(Request $request)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('product_ids', [])), fn ($v) => $v > 0)));
        $n = 0;
        $this->states()->clearErrors($ids);
        foreach (LazadaProduct::query()->whereIn('product_id', $ids)->get() as $listing) {
            $listing->variants()->delete();
            $listing->groups()->detach();
            $listing->delete();
            $n++;
        }

        return redirect()->back()->with('status', "Removed {$n} " . ($n === 1 ? 'product' : 'products') . ' from this channel. Anything already on Lazada stays up until you delete it there.');
    }

    public function addToStoreBulk(Request $request)
    {
        $data = $request->validate(['product_ids' => ['required', 'array'], 'product_ids.*' => ['integer']]);
        $ids = array_values(array_unique(array_map('intval', $data['product_ids'])));

        $valid = DB::table((string) config('catalog.prefix') . 'product')->whereIn('product_id', $ids)->pluck('product_id')->all();
        $added = 0;
        foreach ($valid as $pid) {
            $row = LazadaProduct::firstOrCreate(['product_id' => (int) $pid]);
            if ($row->wasRecentlyCreated) {
                $added++;
            }
        }

        return redirect()->back()->with('status', $added === 1
            ? '1 product added to this store.'
            : number_format($added) . ' products added to this store.');
    }

    /** The Refresh button on the listings strip: the same walk the 30-minute automation runs. */
    public function refreshStatus(Request $request, \Extensions\lazada\Services\Lazada\LazadaLiveListing $live)
    {
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$creds['complete']) {
            return redirect()->back()->with('error', 'Missing Lazada settings.');
        }

        $result = $live->refreshMirror($setting, $creds);
        if ($result['error'] !== null) {
            return redirect()->back()->with('error', 'Lazada did not answer: ' . $result['error'] . ' The statuses shown are from the last refresh.');
        }
        /* Refresh is the one button (the owner, 2026-09-07): every status is
         * re-read first, then the links are checked against the store, the
         * old Repair links folded in. */
        // The check reads two hundred per run; the list starts after the last one checked, so "run it again" continues.
        $checked = app(\Extensions\lazada\Services\Lazada\LazadaLinkCheck::class)->runNext($setting, $creds);
        $sheets = app(\Extensions\lazada\Services\Lazada\LazadaSheetReads::class)
            ->readMissing($setting, app(LazadaClient::class), \App\Integrations\Listings\SheetReads::PER_PRESS);

        // One word per state, the engine's (#184 C): 'rejected', not 'in violation'.
        $labels = ['active' => 'active', 'inactive' => 'inactive', 'pending' => 'pending QC', 'rejected' => 'rejected',
            'sold-out' => 'sold out', 'soldout' => 'sold out', 'deleted' => 'deleted', 'missing' => 'not found on Lazada'];
        $parts = [];
        foreach ($result['counts'] as $status => $n) {
            $parts[] = number_format($n) . ' ' . ($labels[$status] ?? $status);
        }

        return redirect()->back()->with('status', 'Refreshed from Lazada: ' . ($parts ? implode(', ', $parts) : 'no uploaded listings') . '.'
            . ($checked ? ' ' . $checked['summary'] : '')
            . ($sheets['summary'] !== '' ? ' ' . $sheets['summary'] : ''));
    }

    /**
     * Sync ERP product quantity to Lazada using Seller SKU.
     * NOTE: Your app may not have permission for this API yet — we still call it so you can see the exact error.
     */
    public function syncQuantity(Request $request, int $productId, LazadaClient $client)
    {
        $listing = $this->listingFor($productId);

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
        }

        $pfx = (string) config('catalog.prefix');

        $productId = (int) $listing->product_id;

        $product = DB::table($pfx.'product as p')
            ->where('p.product_id', $productId)
            ->first(['p.product_id', 'p.sku', 'p.quantity', 'p.status']);

        if (!$product) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Cannot sync: ERP product not found for listing #' . $listing->id . '.');
        }

        if ((int) $product->status === 0) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Cannot sync quantity: product #' . $productId . ' is disabled. Enable it first.');
        }

        /* The shared sender (Phase F, #184): skip ledger by SKU name, the
         * one-shot sku_id heal, per-SKU verdicts rolled into an honest
         * listing message - the synthetic PARTIAL_FAILURE count sentence
         * is dead. */
        $results = app(\Extensions\lazada\Services\Lazada\LazadaStockPricePush::class)
            ->push('stock', $setting, $creds, [$listing], $pfx);
        $clause = \Extensions\lazada\Services\Lazada\LazadaStockPricePush::ledgerClause($results);

        $msg = $results['ok'] > 0
            ? 'Stock synced to Lazada.' . $clause
            : 'Sync qty failed: ' . (($results['last_error'] ?: null) ?? 'Unknown error') . $clause;

        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with(\App\Integrations\Push\PushLedger::batchTone($results['ok'], $results['err']), $msg);
    }

    /**
     * Sync ERP product price to Lazada using Seller SKU.
     * NOTE: Your app may not have permission for this API yet — we still call it so you can see the exact error.
     */
    public function syncPrice(Request $request, int $productId, LazadaClient $client)
    {
        $listing = $this->listingFor($productId);

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
        }

        $pfx = (string) config('catalog.prefix');

        $productId = (int) $listing->product_id;

        $product = DB::table($pfx.'product as p')
            ->where('p.product_id', $productId)
            ->first(['p.product_id', 'p.sku', 'p.price']);

        if (!$product) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Cannot sync: ERP product not found for listing #' . $listing->id . '.');
        }

        $basePrice = (float) ($product->price ?? 0);
        if ($basePrice < 0) {
            $basePrice = 0;
        }

        // Resolve effective markup (listing-level first, then group fallback)
        $fixedMarkup = $listing->markup_fixed;
        $percentMarkup = $listing->markup_percent;
        if ($fixedMarkup === null && $percentMarkup === null) {
            $mkGroup = $listing->groups()->first();
            if ($mkGroup) {
                $fixedMarkup = $mkGroup->markup_fixed;
                $percentMarkup = $mkGroup->markup_percent;
            }
        }

        /* The shared sender (Phase F, #184), priced by the listing's own
         * rule (group fallback resolved above). */
        $mPct = (float) ($percentMarkup ?? 0);
        $mFixed = (float) ($fixedMarkup ?? 0);
        $results = app(\Extensions\lazada\Services\Lazada\LazadaStockPricePush::class)
            ->push('price', $setting, $creds, [$listing], $pfx,
                fn (int $pid, float $base) => \Extensions\lazada\Services\Lazada\LazadaPushPayload::computeFinalPrice($base, $mFixed, $mPct));
        $clause = \Extensions\lazada\Services\Lazada\LazadaStockPricePush::ledgerClause($results);

        $msg = $results['ok'] > 0
            ? 'Price synced to Lazada.' . $clause
            : 'Sync price failed: ' . (($results['last_error'] ?: null) ?? 'Unknown error') . $clause;

        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with(\App\Integrations\Push\PushLedger::batchTone($results['ok'], $results['err']), $msg);
    }

    /**
     * Delete/Remove a product on Lazada using Seller SKU.
     * API Path: /product/remove
     * NOTE: Your app may not have permission for this API yet — we still call it so you can see the exact error.
     */
    public function deleteFromLazada(Request $request, int $productId, LazadaClient $client)
    {
        $listing = $this->listingFor($productId);

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
        }

        $pfx = (string) config('catalog.prefix');

        $product = DB::table($pfx.'product as p')
            ->where('p.product_id', (int) $listing->product_id)
            ->first(['p.product_id', 'p.sku']);

        if (!$product) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Cannot delete: ERP product not found for listing #' . $listing->id . '.');
        }

        $productId = (int) ($product->product_id ?? 0);
        $mainSku = trim((string) ($product->sku ?? ''));

        // For products with options, remove all variant SKUs (SellerSku list) when possible.
        $variantSkus = $this->payload()->getErpVariantStockByProductId($productId);
        $sellerSkuList = [];
        foreach ($variantSkus as $v) {
            $s = trim((string)($v['seller_sku'] ?? ''));
            if ($s !== '') {
                $sellerSkuList[] = $s;
            }
        }
        $sellerSkuList = array_values(array_unique($sellerSkuList));

        if (empty($sellerSkuList)) {
            if ($mainSku === '') {
                return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                    ->with('status', 'Cannot delete: ERP product SKU is empty for product #' . (int)$productId . '.');
            }
            $sellerSkuList = [$mainSku];
        } else {
            if ($mainSku !== '' && !in_array($mainSku, $sellerSkuList, true)) {
                $sellerSkuList[] = $mainSku;
            }
        }

        $apiPath = '/product/remove';
        $timestamp = (string) round(microtime(true) * 1000);
        // Lazada /product/remove expects seller_sku_list and/or sku_id_list as API params (NOT XML payload).
        // Prefer sku_id_list when we have lazada_item_id + sku_id to avoid ambiguity when duplicate SellerSku exists.

        $skuIdList = [];

        // IMPORTANT:
        // We currently do not persist Lazada sku_id mapping for each variant in DB.
        // To keep delete reliable (and match previously successful calls), if we have lazada_item_id,
        // fetch sku ids via /product/item/get and build sku_id_list.
        $itemId = trim((string) ($listing->lazada_item_id ?? ''));
        if ($itemId !== '') {
            try {
                $skuIdList = $this->itemCache()->fetchSkuIdListByItemId(
                    $client,
                    (string) $setting->region,
                    (string) $creds['app_key'],
                    (string) $creds['app_secret'],
                    (string) $creds['access_token'],
                    $itemId
                );
            } catch (\Throwable $ex) {
                // best-effort only
            }
        }

        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => (string) $creds['access_token'],
        ];

        // If sku_id_list is available, prefer sending ONLY sku_id_list.
        // This matches the previously successful behavior in your logs and avoids Lazada edge cases where
        // seller_sku_list-only calls return E006.
        if (!empty($skuIdList)) {
            $params['sku_id_list'] = json_encode($skuIdList, JSON_UNESCAPED_SLASHES);
        } else {
            $params['seller_sku_list'] = json_encode($sellerSkuList, JSON_UNESCAPED_SLASHES);
        }
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

        // /product/remove does NOT consistently support GET across regions/accounts.
        // We keep this strictly POST (asForm) to avoid UnsupportedHTTPMethod.
        // For E006 (internal error), do a single safe POST retry with a fresh timestamp/sign.
        $attempts = [];
        $result = $client->post((string) $setting->region, $apiPath, $params);
        $attempts[] = ['method' => 'POST', 'params' => $params, 'result' => $result];

        $err = $this->payload()->extractLazadaError($result);
        if (!empty($err['code']) && (string) $err['code'] === '6') {
            $paramsRetry = $params;
            $paramsRetry['timestamp'] = (string) round(microtime(true) * 1000);
            $paramsRetry['sign'] = $client->sign($apiPath, $paramsRetry, (string) $creds['app_secret']);
            $result = $client->post((string) $setting->region, $apiPath, $paramsRetry);
            $attempts[] = ['method' => 'POST', 'params' => $paramsRetry, 'result' => $result];
        }

        $this->persistProductSyncStatus($listing, 'delete_from_lazada', $result);

        // If delete is successful, mark as deleted locally and clear lazada_item_id.
        $e = $this->payload()->extractLazadaError($result);
        if (!empty($e['ok'])) {
            try {
                $listing->lazada_item_id = null;
                $listing->lazada_deleted_at = now();
                // The link is gone; a mirror value for it would be stale the
                // moment it survived (#184 C).
                $listing->live_status = null;
                $listing->live_checked_at = null;
                $listing->save();
            } catch (\Throwable $ex) {
                // Avoid throwing if DB schema isn't migrated yet.
            }
            $this->states()->clearErrors([(int) $listing->product_id]);
        }

        // Log the final attempt + any fallback attempt for troubleshooting.
        foreach ($attempts as $a) {
            $r = $a['result'] ?? [];
            LazadaApiLog::safeCreate([
                'pack' => 'lazada.product.remove',
                'method' => (string) ($a['method'] ?? 'POST'),
                'api_path' => $apiPath,
                'auth_required' => true,
                'request_params' => (array) ($a['params'] ?? $params),
                'response_status' => (int) ($r['status'] ?? 0),
                'ok' => (bool) ($r['ok'] ?? false),
                'response_body' => $r['body'] ?? $r,
                'user_id' => auth()->id(),
            ]);
        }

        ActivityLogger::log('deleted', 'Lazada Product', $listing->id, 'ERP #' . (int)$listing->product_id);

        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with('status', $this->payload()->formatLazadaResultMessage('Delete (Lazada)', $result));
    }

    // ── Unlink Product from Lazada ──────────────────────────────────

    public function unlink(int $productId)
    {
        $listing = $this->listingFor($productId);

        $productId = $listing->product_id;
        $itemId = $listing->lazada_item_id;

        // Mark as unlinked — product_id stays so display info is preserved
        $listing->lazada_item_id = null;
        $listing->unlinked_at = now();
        // The mirror described the link that was just cut (#184 C).
        $listing->live_status = null;
        $listing->live_checked_at = null;
        $listing->save();

        // Remove cached Lazada variants so sync jobs skip this listing
        $listing->variants()->delete();
        $this->states()->clearErrors([(int) $productId]);

        $label = $itemId ? "Lazada item {$itemId}" : "product #{$productId}";

        return redirect()->back()->with('status', "Unlinked ERP product #{$productId} from {$label}.");
    }

    /**
     * Upload/Create a product on Lazada for this listing.
     * API Path: /product/create
     */

    /**
     * Sync Lazada Item ID into ERP by Seller SKU.
     * Use case: product exists on Lazada already (manual upload / migrated catalog), but ERP has no lazada_item_id yet.
     * Only allowed when lazada_item_id is NULL to avoid accidental relinking.
     */
    public function syncLazadaId(Request $request, int $productId, LazadaClient $client)
    {
        $listing = $this->listingFor($productId);

        if (!empty($listing->lazada_item_id) && !$listing->unlinked_at) {
            // Already linked and not unlinked — full cache refresh
            $setting = LazadaSetting::defaultStore()?->decrypted();
            $creds = LazadaSetting::activeCredentials($setting);
            if ($setting) {
                $this->itemCache()->refreshListing($listing, $setting, $client);
            }
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Sync Lazada ID: cache refreshed.');
        }

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
        }

        $pfx = (string) config('catalog.prefix');

        $product = DB::table($pfx.'product as p')
            ->where('p.product_id', (int) $listing->product_id)
            ->first(['p.product_id', 'p.sku']);

        if (!$product) {
            $this->persistProductSyncStatus($listing, 'sync_lazada_id', ['ok' => false, 'body' => ['code' => 'ERP_PRODUCT_NOT_FOUND', 'message' => 'ERP product not found for this Lazada product mapping.']]);
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Sync Lazada ID: ERP product not found.');
        }

        // Collect all SKUs: option value SKUs first (Lazada stores variant SKUs), main SKU as fallback
        $mainSku = trim((string) ($product->sku ?? ''));

        $ovSkus = DB::table($pfx . 'product_option_value')
            ->where('product_id', (int) $listing->product_id)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->pluck('sku')
            ->map(fn($s) => trim((string) $s))
            ->filter(fn($s) => $s !== '')
            ->unique()
            ->values()
            ->toArray();

        $allSkus = $ovSkus;
        if ($mainSku !== '' && !in_array($mainSku, $allSkus)) {
            $allSkus[] = $mainSku;
        }

        if (empty($allSkus)) {
            $this->persistProductSyncStatus($listing, 'sync_lazada_id', ['ok' => false, 'body' => ['code' => 'MISSING_SKU', 'message' => 'ERP product has no SKU (main or option values).']]);
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Sync Lazada ID: product has no SKU.');
        }

        // Search Lazada page by page, try each SKU until a match is found
        $matchedItemId = null;
        $matchCount = 0;
        foreach ($allSkus as $s) {
            [$found, $count] = $this->matcher()->findLazadaItemBySku($setting, $client, $s);
            if ($found) {
                $matchedItemId = $found;
                $matchCount = $count;
                break;
            }
            $matchCount = max($matchCount, $count);
        }

        if ($matchCount > 1) {
            $this->persistProductSyncStatus($listing, 'sync_lazada_id', [
                'ok' => false,
                'body' => ['code' => 'MULTIPLE_MATCHES', 'message' => 'Multiple Lazada items matched. Please resolve duplicates in Lazada first.'],
            ]);
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Sync Lazada ID: multiple Lazada items matched.');
        }

        if ($matchedItemId) {
            try {
                $listing->lazada_item_id = (string) $matchedItemId;
                $listing->lazada_deleted_at = null;
                $listing->unlinked_at = null;
                // A fresh link voids the mirror; the refresh below reads the
                // item back and fills it with Lazada's answer (#184 C).
                $listing->live_status = null;
                $listing->live_checked_at = null;
                $listing->save();

                // Full cache refresh: variants, mirror, stale cleanup, sync_status
                $this->itemCache()->refreshListing($listing, $setting, $client);
            } catch (\Throwable $ex) {
                // keep status log; avoid throwing in production
            }
            $this->states()->recordOutcome((int) $listing->product_id, null);

            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Sync Lazada ID: linked successfully.');
        }

        $this->persistProductSyncStatus($listing, 'sync_lazada_id', ['ok' => false, 'body' => ['code' => 'NOT_FOUND', 'message' => 'No Lazada product found for SKUs: ' . implode(', ', $allSkus)]]);
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with('status', 'Sync Lazada ID: no Lazada product found for SKUs: ' . implode(', ', $allSkus));
    }

    /**
     * Send ONE listing to Lazada on its own settings, and answer for
     * itself: create when the item is not there, update in place when it
     * is. The single-product button and the multi-tick bar are the same
     * act on one product, so they are the same code - the button redirects
     * with the answer and the row errors, the bar collects the sentence
     * beside nine others.
     *
     * @return array{ok: bool, message: string, errors: array<string, mixed>}
     */
    private function pushOne(int $productId, LazadaClient $client): array
    {
        $saved = $this->listingFor($productId);
        /* A group is a preset the product inherits (the owner, 2026-09-05): the
         * payload is built from an in-memory copy whose blank category and
         * brand are filled from the group. Only $saved is ever written. */
        $inherit = app(\Extensions\lazada\Services\Lazada\LazadaInheritedSettings::class);
        $listing = $inherit->fill($saved, $inherit->forProducts([(int) $saved->product_id])[(int) $saved->product_id] ?? null)['listing'];

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return ['ok' => false, 'message' => 'Missing Lazada settings (region/app key/app secret/access token).', 'errors' => []];
        }


        // Determine create vs update
        $isUpdate = !empty($listing->lazada_item_id) && empty($listing->lazada_deleted_at);

        /* THE GATE (the owner, 2026-09-06): the one readiness computation the
         * Ready column shows is the one this upload refuses through, so a
         * listing that read Ready is never turned away here. The category,
         * the enabled flag, the SKU, the image, the template's required
         * attributes and the variation rules are all gaps there; the
         * builder's own refusals below stay as the last defence and can
         * only repeat what readiness already said. */
        // A category never opened is read now, so the gate judges the real sheet.
        if ($listing->primary_category_id && !\Extensions\lazada\Models\LazadaCategoryTemplate::query()->where('region', (string) $setting->region)->where('primary_category_id', (int) $listing->primary_category_id)->whereNotNull('template_body')->exists()) {
            $this->readCategoryTemplate((int) $listing->primary_category_id, $client);
        }
        $readiness = app(\Extensions\lazada\Services\Lazada\LazadaListingReadiness::class)->forListings(collect([$saved]))[(int) $saved->id] ?? ['ready' => false, 'missing' => [], 'gaps' => []];
        if (!$readiness['ready']) {
            $refusal = \App\Integrations\Listings\CatalogGaps::refusal($readiness, $isUpdate ? 'update' : 'push');
            $this->persistProductSyncStatus($saved, 'upload_to_lazada', ['ok' => false, 'body' => ['code' => 'NOT_READY', 'message' => $refusal]]);
            $this->states()->recordOutcome((int) $listing->product_id, $refusal);
            // Each blank required attribute lands on its own row, in the builder's words.
            $rowErrors = [];
            foreach ($readiness['gaps'] ?? [] as $gap) {
                foreach ((array) ($gap['keys'] ?? []) as $key => $name) {
                    $rowErrors['attributes.' . $key] = $name . ' is mandatory.';
                }
            }

            return ['ok' => false, 'message' => $refusal, 'errors' => $rowErrors];
        }
        $pfx = (string) config('catalog.prefix');

        // Build payload from listing mapping. If mapping is incomplete, we do not send an invalid payload.
        $picturesLeftOut = 0;
        try {
            [$productPayload, $preview] = $this->payload()->buildLazadaProductCreatePayload($listing, $setting, $client);
            $productPayload = $this->images()->ensureLazadaInlinkImages($productPayload, $setting, $client);
            [$productPayload, $picturesLeftOut] = $this->images()->ensureLazadaDescriptionImages($productPayload, $setting, $client);
        } catch (\Illuminate\Validation\ValidationException $e) {
            /* Our own refusal, before anything is sent: every missing answer
             * named at once, as an ERROR (a refusal is never green), and on
             * the row it belongs to, since the builder keys its messages
             * attributes.<key> the way the sheet reads them (the owner, 2026-09-06:
             * the header alone lost the row-level "required" the sheet had). */
            $all = collect($e->errors())->flatten()->all();
            $msg = $all !== [] ? implode(' ', $all) : 'Listing mapping is incomplete.';
            $this->persistProductSyncStatus($saved, 'upload_to_lazada', ['ok' => false, 'body' => ['code' => 'VALIDATION_ERROR', 'message' => (string)$msg]]);
            $this->states()->recordOutcome((int) $listing->product_id, 'Upload refused: ' . $msg);

            return ['ok' => false, 'message' => 'Upload refused before anything was sent: ' . $msg, 'errors' => $e->errors()];
        }

        // For updates, inject ItemId and SkuId so Lazada knows which product/SKUs to update
        if ($isUpdate) {
            $productPayload['Request']['Product']['ItemId'] = (int) $listing->lazada_item_id;

            // Inject SkuId into each SKU entry from cached variants
            $variantMap = LazadaProductVariant::where('lazada_product_id', $listing->id)
                ->whereNotNull('sku_id')
                ->pluck('sku_id', 'seller_sku')
                ->toArray();

            foreach ($productPayload['Request']['Product']['Skus']['Sku'] as $idx => $sku) {
                $sellerSku = $sku['SellerSku'] ?? '';
                if (isset($variantMap[$sellerSku])) {
                    $productPayload['Request']['Product']['Skus']['Sku'][$idx]['SkuId'] = (int) $variantMap[$sellerSku];
                }
            }
        }

        $apiPath = $isUpdate ? '/product/update' : '/product/create';
        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => (string) $creds['access_token'],
            'payload' => json_encode($productPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

        $result = $client->post((string) $setting->region, $apiPath, $params);

        // A variation switched off for this store stops selling, whatever the update's answer.
        $switched = $isUpdate
            ? app(LazadaVariationSwitch::class)->switchOff($setting, $creds, (string) $listing->lazada_item_id, $preview['variations_off'] ?? [])
            : ['off' => [], 'refused' => []];
        $switchRefusal = LazadaVariationSwitch::refusal((int) $saved->product_id, $switched['refused']);

        $this->persistProductSyncStatus($saved, 'upload_to_lazada', $result, 'listing');

        // If successful, store lazada_item_id (create) or refresh variants (update).
        $pictureNote = LazadaImages::descriptionNote($picturesLeftOut);
        $e = $this->payload()->extractLazadaError($result);
        if (!empty($e['ok'])) {
            $this->states()->recordOutcome((int) $saved->product_id, null);
            if ($isUpdate) {
                // Refresh variant cache after update; the same read tells whether a rename was taken.
                $readBack = $this->itemCache()->refreshListing($saved, $setting, $client);
                $kept = $this->payload()->renameReadBack($preview['variation_renames'] ?? [], $readBack);
                $switchNote = LazadaVariationSwitch::summary((int) $saved->product_id, $switched['off'], $preview['variations_back'] ?? []);
                $failure = LazadaVariationSwitch::line($kept, $switchRefusal);
                if ($failure !== null) {
                    $this->states()->recordOutcome((int) $saved->product_id, $failure);

                    return ['ok' => false, 'message' => LazadaVariationSwitch::line($kept === null ? 'Update pushed.' : null, $switchNote, $pictureNote, $failure), 'errors' => []];
                }

                $note = $this->payload()->renameWarning($preview);

                return ['ok' => true, 'message' => LazadaVariationSwitch::line('Update pushed.', $switchNote, $pictureNote, $note), 'errors' => [], 'warning' => $pictureNote !== null];
            }

            $itemId = $this->payload()->extractCreatedItemId($result);
            try {
                if ($itemId) {
                    $saved->lazada_item_id = $itemId;
                }
                $saved->lazada_deleted_at = null;
                $listing->unlinked_at = null;
                // A fresh link voids the mirror: whatever status the row wore
                // belonged to the previous link. Read back below, never guessed.
                $saved->live_status = null;
                $saved->live_checked_at = null;
                $saved->save();
                // Persist Lazada sku_id and shop_sku (returned from /product/create) into variants table.
                // Also resolve product_option_value_id so push-stock can map SKU → ERP qty.
                $skuList = data_get($result, 'body.data.sku_list', []);
                if (is_array($skuList)) {
                    // Build SKU → product_option_value_id map from ERP option values.
                    $pfxPost = (string) config('catalog.prefix');
                    $erpPovRows = DB::table($pfxPost . 'product_option_value')
                        ->where('product_id', (int) $listing->product_id)
                        ->whereNotNull('sku')
                        ->where('sku', '!=', '')
                        ->get(['product_option_value_id', 'sku']);
                    $skuToPovId = [];
                    foreach ($erpPovRows as $epov) {
                        $skuToPovId[trim((string) $epov->sku)] = (int) $epov->product_option_value_id;
                    }

                    foreach ($skuList as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $sellerSku = $row['seller_sku'] ?? $row['SellerSku'] ?? null;
                        if (!$sellerSku) {
                            continue;
                        }
                        $shopSku = $row['shop_sku'] ?? null;
                        $skuId = $row['sku_id'] ?? null;

                        // Resolve product_option_value_id: check if variant row already has it,
                        // otherwise try matching seller_sku against ERP option value SKUs.
                        $existingVariant = \Extensions\lazada\Models\LazadaProductVariant::where('lazada_product_id', $listing->id)
                            ->where('seller_sku', (string) $sellerSku)
                            ->first();
                        $povId = $existingVariant->product_option_value_id ?? null;

                        if ($povId === null) {
                            // Direct match
                            $povId = $skuToPovId[(string) $sellerSku] ?? null;
                            // Suffixed match (e.g., "SKU-123" where 123 is pov_id)
                            if ($povId === null) {
                                foreach ($skuToPovId as $erpSku => $id) {
                                    if (str_starts_with((string) $sellerSku, $erpSku . '-')) {
                                        $povId = $id;
                                        break;
                                    }
                                }
                            }
                        }

                        try {
                            \Extensions\lazada\Models\LazadaProductVariant::updateOrCreate(
                                [
                                    'lazada_product_id' => $listing->id,
                                    'seller_sku' => (string) $sellerSku,
                                ],
                                [
                                    'product_option_value_id' => $povId,
                                    'shop_sku' => $shopSku ? (string) $shopSku : null,
                                    'sku_id' => $skuId !== null ? (int) $skuId : null,
                                ]
                            );
                        } catch (\Throwable $ex2) {
                            // ignore
                        }
                    }
                }

                // Write-through (#184 C): read the item straight back so the
                // mirror learns what this push did without waiting for the sweep.
                $this->itemCache()->refreshListing($listing, $setting, $client);
            } catch (\Throwable $ex) {
                // ignore
            }
        }

        LazadaApiLog::safeCreate([
            'pack' => 'lazada.product.create',
            'method' => 'POST',
            'api_path' => $apiPath,
            'auth_required' => true,
            'request_params' => $params,
            'response_status' => (int)($result['status'] ?? 0),
            'ok' => (bool)($result['ok'] ?? false),
            'response_body' => $result['body'] ?? $result,
            'user_id' => auth()->id(),
        ]);

        // Lazada's verdict in words (the owner, 2026-09-06): the detail it gave,
        // with the field named by the category template when it can be, as
        // an error, and kept on the record as this product's latest failure.
        $template = LazadaCategoryTemplate::query()
            ->where('region', (string) $setting->region)
            ->where('primary_category_id', (int) $listing->primary_category_id)
            ->first();
        $labeller = $template && $template->template_body ? $this->attrs()->fieldLabeller($template->template_body) : null;
        $line = $this->payload()->formatLazadaResultMessage('Upload', $result, $labeller);
        if (! ($e['ok'] ?? false)) {
            $this->states()->recordOutcome((int) $listing->product_id, LazadaVariationSwitch::line($line, $switchRefusal));

            /* The refusal on the row it is about (the owner, 2026-09-06: the
             * sheet used to say "this field is required" under the row; the
             * header line alone lost that). Lazada names a field by id or
             * by name in detail[], or only inside the sentence. */
            $rowErrors = $template && $template->template_body
                ? $this->attributeRowErrors($template->template_body, $this->payload()->extractLazadaError($result))
                : [];

            $switchNote = LazadaVariationSwitch::summary((int) $saved->product_id, $switched['off'], []);

            return ['ok' => false, 'message' => LazadaVariationSwitch::line($line, $switchNote, $switchRefusal), 'errors' => $rowErrors];
        }

        return ['ok' => true, 'message' => LazadaVariationSwitch::line($line, $pictureNote), 'errors' => [], 'warning' => $pictureNote !== null];
    }

    /**
     * The single-product Push and Save-and-push: one product, then back to
     * the Listings page the operator came from, search and all (the owner,
     * 2026-09-06). A push from the edit form (push=1) goes to the Listings
     * page even without a remembered search; a push from the Listings page
     * itself returns to it as before.
     */
    public function uploadToLazada(Request $request, int $productId, LazadaClient $client)
    {
        $answer = $this->pushOne($productId, $client);

        if (! $answer['ok']) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('error', $answer['message'])
                ->withErrors($answer['errors']);
        }

        $done = \App\Support\BackTo::safe($request->input('back'),
            $request->boolean('push') ? route('ext.lazada.products.index') : url()->previous(route('ext.lazada.products.index')));

        return redirect()->to($done)->with(! empty($answer['warning']) ? 'warning' : 'status', $answer['message']);
    }

    /**
     * Multi-tick push: each ticked product goes up on its OWN settings and
     * reports for itself, in the words every channel shares.
     */
    public function bulkPush(Request $request, LazadaClient $client)
    {
        $answer = \App\Integrations\Push\BulkPushRun::over(
            (array) $request->input('product_ids', []),
            'Lazada',
            fn (int $productId) => $this->pushOne($productId, $client)
        );

        return redirect()->back()->with($answer['key'], $answer['message']);
    }

    /**
     * The seller center's Active switch (Lazada). Deactivate is the
     * documented /product/deactivate (ItemId mandatory). Lazada publishes
     * NO activate endpoint - reactivation goes through /product/update
     * with SKU-level Status 'active', which is how its own payload schema
     * expresses it; whatever the marketplace answers is reported verbatim.
     */
    public function toggleListing(Request $request, int $productId, LazadaClient $client)
    {
        $request->validate(['action' => 'required|in:activate,deactivate']);
        $deactivate = $request->input('action') === 'deactivate';
        $listing = $this->listingFor($productId);
        if (empty($listing->lazada_item_id)) {
            return redirect()->back()->with('error', 'This listing is not on Lazada, so there is nothing to ' . ($deactivate ? 'deactivate' : 'activate') . '.');
        }
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$creds['complete']) {
            return redirect()->back()->with('error', 'Missing Lazada settings.');
        }

        $r = $this->toggleOne($listing, $deactivate, $setting, $creds, $client);
        if (!$r['ok']) {
            return redirect()->back()->with('error', ($deactivate ? 'Deactivate' : 'Activate') . ' failed: ' . $r['message']);
        }

        return redirect()->back()->with('status', $deactivate
            ? 'Deactivated. Buyers no longer see this item on Lazada.'
            : 'Activation sent. Lazada usually applies it within a minute; Refresh from Lazada reads the truth back.');
    }

    /**
     * The live listing as Lazada holds it, in the shape /product/update
     * wants it back: its attributes verbatim, and its SKUs reduced to the
     * writable fields.
     *
     * Only writable fields are carried. Lazada returns read-only ones on
     * item/get (Url, ShopSku, and friends) that /product/update rejects,
     * and price and quantity are carried precisely so that a status change
     * cannot blank them if Lazada treats the Sku node as a replacement
     * rather than a patch.
     *
     * @return array{ok: bool, attributes?: array<string, mixed>, skus?: list<array<string, mixed>>, message?: string}
     */
    private function fetchLazadaItemForUpdate(LazadaClient $client, object $setting, array $creds, string $itemId): array
    {
        if (trim($itemId) === '') {
            return ['ok' => false, 'message' => 'This listing has no Lazada item id.'];
        }

        $apiPath = '/product/item/get';
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => (string) round(microtime(true) * 1000),
            'access_token' => (string) $creds['access_token'],
            'item_id' => $itemId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

        try {
            $res = $client->get((string) $setting->region, $apiPath, $params);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => \App\Support\TransportError::plain($e, 'Lazada')];
        }

        LazadaApiLog::safeCreate([
            'pack' => 'lazada.listings.toggle.read', 'method' => 'GET',
            'api_path' => $apiPath, 'auth_required' => true,
            'request_params' => $params,
            'response_status' => (int) ($res['status'] ?? 0),
            'ok' => (bool) ($res['ok'] ?? false),
            'response_body' => $res['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        $e = $this->payload()->extractLazadaError($res);
        if (! ($e['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) ($e['message'] ?? 'Lazada refused the read.')];
        }

        $body = $res['body'] ?? [];
        $data = is_array($body) ? ($body['data'] ?? $body) : [];

        // item/get answers with lower-case keys; /product/update wants the
        // same machine names it was given, which is what these already are.
        $attributes = data_get($data, 'attributes') ?? data_get($data, 'Attributes') ?? [];
        $attributes = is_array($attributes) ? array_filter($attributes, fn ($v) => $v !== null && $v !== '') : [];

        $rawSkus = data_get($data, 'skus') ?? data_get($data, 'Skus.Sku') ?? [];
        $skus = [];
        foreach ((array) $rawSkus as $sku) {
            if (! is_array($sku)) {
                continue;
            }
            $skuId = $sku['SkuId'] ?? $sku['sku_id'] ?? null;
            if ($skuId === null || $skuId === '') {
                continue;
            }

            $row = ['SkuId' => (int) $skuId, 'Status' => 'active'];
            foreach ([
                'SellerSku' => ['SellerSku', 'SellerSKU', 'seller_sku'],
                'Quantity'  => ['quantity', 'Quantity'],
                'price'     => ['price', 'Price'],
            ] as $out => $candidates) {
                foreach ($candidates as $key) {
                    if (isset($sku[$key]) && $sku[$key] !== '' && $sku[$key] !== null) {
                        $row[$out] = $sku[$key];
                        break;
                    }
                }
            }

            $skus[] = $row;
        }

        return ['ok' => true, 'attributes' => $attributes, 'skus' => $skus];
    }

    /**
     * One activate or deactivate, the same call for the row menu, the
     * listing page and the selection bar. Lazada deactivates by ItemId;
     * activating goes through /product/update with the SKU ids the ERP has
     * cached, SKU-level Status active. On success the mirror is written
     * through so the next page view reads it without a refresh.
     *
     * @return array{ok: bool, message: string}
     */
    private function toggleOne(LazadaProduct $listing, bool $deactivate, object $setting, array $creds, LazadaClient $client): array
    {
        $r = $this->toggleOnLazada($listing, $deactivate, $setting, $creds, $client);
        $this->states()->recordOutcome((int) $listing->product_id, $r['ok'] ? null
            : ($deactivate ? 'Deactivate' : 'Activate') . ' failed: ' . $r['message']);

        return $r;
    }

    /** @return array{ok: bool, message: string} */
    private function toggleOnLazada(LazadaProduct $listing, bool $deactivate, object $setting, array $creds, LazadaClient $client): array
    {
        if ($deactivate) {
            $apiPath = '/product/deactivate';
            $productPayload = ['Request' => ['Product' => ['ItemId' => (int) $listing->lazada_item_id]]];
        } else {
            /* Reactivation goes through /product/update, which is NOT a patch
             * endpoint: Lazada validates the Product node you send against the
             * category schema instead of merging it into the stored listing,
             * so a Product without Attributes is refused with
             *
             *     E001: Parameter Attributes is mandatory
             *
             * even though the listing is already live and already has them
             * (the owner, live, 2026-09-01). The attributes therefore have to
             * travel with the call.
             *
             * They are read back from Lazada rather than rebuilt from the
             * ERP's own answers, for two reasons. The operator pressed
             * Activate, not Push changes: sending ERP content here would
             * overwrite the live name, description and dimensions as a side
             * effect of flipping a status. And the ERP's sheet may be
             * half-answered (see #175) on a listing Lazada is perfectly happy
             * with, which would make a live product impossible to switch back
             * on. Round-tripping the marketplace's own values changes exactly
             * one thing: Status.
             */
            $live = $this->fetchLazadaItemForUpdate($client, $setting, $creds, (string) $listing->lazada_item_id);
            if (! ($live['ok'] ?? false)) {
                return ['ok' => false, 'message' => (string) ($live['message'] ?? 'Could not read the listing from Lazada.')];
            }
            if (empty($live['attributes'])) {
                return ['ok' => false, 'message' => 'Lazada returned no attributes for this item, so activating it would send an incomplete product. Open the listing on Lazada and check it still exists.'];
            }
            if (empty($live['skus'])) {
                return ['ok' => false, 'message' => 'Lazada returned no SKUs for this item, so there is nothing to activate.'];
            }
            // A variation switched off for this store stays off when the item comes back.
            $hidden = \App\Integrations\Listings\ListingVariations::hidden('lazada', (int) ($listing->lazada_setting_id ?? 0), [(int) $listing->product_id]);
            $live['skus'] = array_values(array_filter($live['skus'], fn ($s) => \App\Integrations\Listings\ListingVariations::allows($hidden, (int) $listing->product_id, (string) ($s['SellerSku'] ?? ''))));
            if ($live['skus'] === []) {
                return ['ok' => false, 'message' => \App\Integrations\Listings\ListingVariations::noneSoldMessage('Lazada')];
            }

            $apiPath = '/product/update';
            $productPayload = ['Request' => ['Product' => [
                'ItemId' => (int) $listing->lazada_item_id,
                'Attributes' => $live['attributes'],
                'Skus' => ['Sku' => $live['skus']],
            ]]];
        }
        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => (string) $creds['access_token'],
            'payload' => json_encode($productPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
        try {
            $result = $client->post((string) $setting->region, $apiPath, $params);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => \App\Support\TransportError::plain($e, 'Lazada')];
        }
        LazadaApiLog::safeCreate([
            'pack' => 'lazada.listings.toggle', 'method' => 'POST',
            'api_path' => $apiPath, 'auth_required' => true,
            'request_params' => $params,
            'response_status' => (int) ($result['status'] ?? 0),
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
        ]);
        $e = $this->payload()->extractLazadaError($result);
        if (!($e['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) ($e['message'] ?? 'Unknown error')];
        }

        $listing->forceFill(['live_status' => $deactivate ? 'inactive' : 'active', 'live_checked_at' => now()])->save();
        ActivityLogger::log(
            $deactivate ? 'deactivated' : 'activated',
            'Lazada Product',
            (int) $listing->id,
            'Item ' . $listing->lazada_item_id . ($deactivate ? ' deactivated on' : ' activated on') . ' Lazada'
        );

        return ['ok' => true, 'message' => ''];
    }

    /** The selection bar's Deactivate / Activate (the owner, 2026-08-28): the same call as the row's, for many rows. */
    public function bulkToggle(Request $request, LazadaClient $client)
    {
        $request->validate([
            'action' => 'required|in:activate,deactivate',
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'integer',
        ]);
        $deactivate = $request->input('action') === 'deactivate';
        $verb = $deactivate ? 'Deactivated' : 'Activated';

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$creds['complete']) {
            return redirect()->back()->with('error', 'Missing Lazada settings.');
        }

        $ids = $this->listingIdsFor((array) $request->input('product_ids'));
        $listings = LazadaProduct::query()->whereIn('id', $ids)->get();
        $onLazada = $listings->filter(fn ($l) => !empty($l->lazada_item_id));
        $notOnLazada = count($ids) - $onLazada->count();
        if ($onLazada->isEmpty()) {
            return redirect()->back()->with('error', 'None of the selected listings is on Lazada, so there is nothing to ' . ($deactivate ? 'deactivate' : 'activate') . '.');
        }

        $done = 0;
        $refused = [];
        foreach ($onLazada as $listing) {
            $r = $this->toggleOne($listing, $deactivate, $setting, $creds, $client);
            if ($r['ok']) {
                $done++;
            } else {
                $refused[] = $r['message'];
            }
        }

        $parts = [];
        if ($done > 0) {
            $parts[] = $verb . ' ' . $done . ' ' . ($done === 1 ? 'item' : 'items') . ' on Lazada.';
        }
        if ($refused !== []) {
            $parts[] = count($refused) . ' refused: ' . implode('; ', array_slice(array_unique($refused), 0, 3)) . '.';
        }
        if ($notOnLazada > 0) {
            $parts[] = $notOnLazada . ' skipped, not on Lazada.';
        }

        return redirect()->back()->with($done === 0 ? 'error' : 'status', implode(' ', $parts));
    }

    public function bulkSyncLazadaId(Request $request, LazadaClient $client)
    {
        $ids = $this->listingIdsFor((array) $request->input('product_ids', []));

        if (empty($ids)) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Bulk Sync Lazada ID: no products selected.');
        }

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
        }

        $listings = LazadaProduct::query()->whereIn('id', $ids)->get();
        $pfx = (string) config('catalog.prefix');
        $okCount = 0;
        $errCount = 0;
        $skipCount = 0;

        // Count how many actually need syncing
        $needSync = $listings->filter(fn($l) => empty($l->lazada_item_id))->count();

        // For any multi-product batch, fetch the entire Lazada catalog ONCE and
        // match every selected product against that single map. The per-product
        // path (below) falls back to a full catalog scan whenever Lazada's
        // `search` misses a SellerSku — which is common — so N products meant N
        // scans and a request that could stall for minutes. One shared pass
        // removes the multiply. A lone product keeps the per-product path (a
        // hit via `search`/`sku_seller_list` is a single fast call).
        $skuToItemId = null;
        if ($needSync >= 2) {
            $map = $this->matcher()->fetchLazadaSkuMap($setting, $client);
            // Index case-insensitively: Lazada SellerSku casing may differ from
            // the ERP SKU, and the per-product matcher already uses strcasecmp.
            $skuToItemId = [];
            foreach ((array) $map as $sellerSku => $itemIds) {
                $skuToItemId[strtolower((string) $sellerSku)] = $itemIds;
            }
        }

        foreach ($listings as $listing) {
            // Skip if already linked
            if (!empty($listing->lazada_item_id)) {
                $skipCount++;
                continue;
            }

            // Collect all SKUs for this product: main SKU + option value SKUs
            $product = DB::table($pfx . 'product')->where('product_id', (int) $listing->product_id)->first(['sku']);
            $mainSku = trim((string) ($product->sku ?? ''));

            // Option value SKUs first (Lazada stores variant SKUs), main SKU as fallback
            $ovSkus = DB::table($pfx . 'product_option_value')
                ->where('product_id', (int) $listing->product_id)
                ->whereNotNull('sku')
                ->where('sku', '!=', '')
                ->pluck('sku')
                ->map(fn($s) => trim((string) $s))
                ->filter(fn($s) => $s !== '')
                ->unique()
                ->values()
                ->toArray();

            $allSkus = $ovSkus;
            if ($mainSku !== '' && !in_array($mainSku, $allSkus)) {
                $allSkus[] = $mainSku;
            }

            if (empty($allSkus)) {
                $this->persistProductSyncStatus($listing, 'bulk_sync_lazada_id', ['ok' => false, 'body' => ['code' => 'MISSING_SKU', 'message' => 'ERP product has no SKU (main or option values).']]);
                $errCount++;
                continue;
            }

            // Find matching Lazada item_id
            $matchedItemId = null;
            $matchCount = 0;

            if ($skuToItemId !== null) {
                // Bulk mode: look up from pre-fetched map
                $matchedItemIds = [];
                foreach ($allSkus as $s) {
                    foreach ($skuToItemId[strtolower((string) $s)] ?? [] as $itemId) {
                        $matchedItemIds[$itemId] = true;
                    }
                }
                $matchCount = count($matchedItemIds);
                if ($matchCount === 1) {
                    $matchedItemId = array_key_first($matchedItemIds);
                }
            } else {
                // Small batch: search per product, stops on first match
                foreach ($allSkus as $s) {
                    [$found, $count] = $this->matcher()->findLazadaItemBySku($setting, $client, $s);
                    if ($found) {
                        $matchedItemId = $found;
                        $matchCount = $count;
                        break;
                    }
                    $matchCount = max($matchCount, $count);
                }
            }

            if ($matchCount > 1) {
                $this->persistProductSyncStatus($listing, 'bulk_sync_lazada_id', [
                    'ok' => false,
                    'body' => ['code' => 'MULTIPLE_MATCHES', 'message' => 'Multiple Lazada items matched SKUs for this product.'],
                ]);
                $errCount++;
                continue;
            }

            if ($matchedItemId) {
                try {
                    $listing->lazada_item_id = (string) $matchedItemId;
                    $listing->lazada_deleted_at = null;
                    // Re-linking clears an earlier unlink: a row that still
                    // carried unlinked_at read as both linked (has item id) and
                    // unlinked, and the row menu — which shows the push actions
                    // only when linked AND not unlinked — collapsed to just Edit
                    // and Remove. Matching an id is the act of linking it.
                    $listing->unlinked_at = null;
                    // A fresh link voids the mirror: the row reads "Not checked
                    // yet" until the page filler or sweep asks Lazada, never a
                    // stale status from a previous link (#184 C).
                    $listing->live_status = null;
                    $listing->live_checked_at = null;
                    // Record the success so the row reads as linked: without this
                    // the item id was saved but last_sync_ok / error_message kept
                    // whatever a prior failed run left, so a freshly matched
                    // listing still showed "No Lazada product found for SKU".
                    // ['ok' => true] with no error body clears the stale code and
                    // message and stamps last_synced_at + last_sync_action.
                    $this->persistProductSyncStatus($listing, 'bulk_sync_lazada_id', ['ok' => true]);
                    $this->states()->recordOutcome((int) $listing->product_id, null);
                    $okCount++;
                } catch (\Throwable $ex) {
                    $errCount++;
                }
            } else {
                $this->persistProductSyncStatus($listing, 'bulk_sync_lazada_id', ['ok' => false, 'body' => ['code' => 'NOT_FOUND', 'message' => 'No Lazada product found for SKUs: ' . implode(', ', $allSkus) . '.']]);
                $errCount++;
            }
        }

        $msg = "Bulk Sync Lazada ID: processed " . count($ids) . " product(s). Linked: {$okCount}, Error: {$errCount}";
        if ($skipCount > 0) {
            $msg .= ", Skipped (already linked): {$skipCount}";
        }
        $msg .= ".";

        return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', $msg);
    }

    /**
     * Ask Lazada whether the links this page believes in are still true
     * (Phase F, #184): the group page's check, on the workbench, via the
     * shared LazadaLinkCheck. The bulk bar posts product_ids[]; with
     * none it checks every linked listing, 200 at a time. No group here
     * means adoption creates a bare listing for the workbench to dress.
     */
    public function checkAgainstLazada(Request $request, \Extensions\lazada\Services\Lazada\LazadaLinkCheck $check)
    {
        try { @set_time_limit(0); } catch (\Throwable $e) {}

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['complete']) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('error', 'Missing Lazada settings (region/app key/app secret/access token).');
        }

        $productIds = array_values(array_unique(array_filter(array_map(fn ($v) => (int) $v, (array) $request->input('product_ids', [])), fn ($v) => $v > 0)));
        $productIds = $productIds !== []
            ? $productIds
            : LazadaProduct::query()->whereNotNull('lazada_item_id')->whereNull('lazada_deleted_at')
                ->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        if (empty($productIds)) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('error', 'Nothing is linked to Lazada yet, so there is nothing to check.');
        }

        $r = $check->run($setting, $creds, $productIds);

        return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with($r['tone'], $r['summary']);
    }

public function bulkSyncQuantity(Request $request, LazadaClient $client)
{
    $ids = $this->listingIdsFor((array) $request->input('product_ids', []));

    if (empty($ids)) {
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Bulk Sync Qty: no listings selected.');
    }

    $setting = LazadaSetting::defaultStore()?->decrypted();
    $creds = LazadaSetting::activeCredentials($setting);
    if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
    }

    $pfx = (string) config('catalog.prefix');
    $listings = LazadaProduct::query()->whereIn('id', $ids)->get();

    /* The shared sender (Phase F, #184): one plan, one ledger, one tone. */
    $results = app(\Extensions\lazada\Services\Lazada\LazadaStockPricePush::class)
        ->push('stock', $setting, $creds, $listings, $pfx);

    $msg = "Bulk Sync Qty: {$results['ok']} ok, {$results['err']} failed"
        . ($results['skipped'] > 0 ? ", {$results['skipped']} skipped (disabled)" : '') . '.'
        . \Extensions\lazada\Services\Lazada\LazadaStockPricePush::ledgerClause($results);

    return redirect()->to(url()->previous(route('ext.lazada.products.index')))
        ->with(\App\Integrations\Push\PushLedger::batchTone($results['ok'], $results['err']), $msg);
}

public function bulkSyncPrice(Request $request, LazadaClient $client)
{
    $ids = $this->listingIdsFor((array) $request->input('product_ids', []));

    if (empty($ids)) {
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Bulk Sync Price: no listings selected.');
    }

    $setting = LazadaSetting::defaultStore()?->decrypted();
    $creds = LazadaSetting::activeCredentials($setting);
    if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
    }

    $pfx = (string) config('catalog.prefix');
    $listings = LazadaProduct::query()->whereIn('id', $ids)->get();

    /* Each listing's own price rule (group fallback), resolved per
     * product inside the closure - a bulk push must not borrow one
     * listing's markup for another. */
    $rules = [];
    foreach ($listings as $l) {
        $fixed = $l->markup_fixed;
        $pct = $l->markup_percent;
        if ($fixed === null && $pct === null) {
            $g = $l->groups()->first();
            if ($g) { $fixed = $g->markup_fixed; $pct = $g->markup_percent; }
        }
        $rules[(int) $l->product_id] = [$fixed, $pct];
    }

    $results = app(\Extensions\lazada\Services\Lazada\LazadaStockPricePush::class)
        ->push('price', $setting, $creds, $listings, $pfx,
            function (int $pid, float $base) use ($rules): float {
                [$fixed, $pct] = $rules[$pid] ?? [null, null];

                return \Extensions\lazada\Services\Lazada\LazadaPushPayload::computeFinalPrice($base, (float) ($fixed ?? 0), (float) ($pct ?? 0));
            });

    $msg = "Bulk Sync Price: {$results['ok']} ok, {$results['err']} failed"
        . ($results['skipped'] > 0 ? ", {$results['skipped']} skipped (disabled)" : '') . '.'
        . \Extensions\lazada\Services\Lazada\LazadaStockPricePush::ledgerClause($results);

    return redirect()->to(url()->previous(route('ext.lazada.products.index')))
        ->with(\App\Integrations\Push\PushLedger::batchTone($results['ok'], $results['err']), $msg);
}

public function bulkDeleteFromLazada(Request $request, LazadaClient $client)
{
    $ids = $this->listingIdsFor((array) $request->input('product_ids', []));

    if (empty($ids)) {
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Bulk Delete: no listings selected.');
    }

    $setting = LazadaSetting::defaultStore()?->decrypted();
    $creds = LazadaSetting::activeCredentials($setting);
    if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
    }

    $pfx = (string) config('catalog.prefix');
    $listings = LazadaProduct::query()->whereIn('id', $ids)->get();
    $okCount = 0;
    $errCount = 0;

    foreach ($listings as $listing) {
        $product = DB::table($pfx.'product as p')
            ->where('p.product_id', (int) $listing->product_id)
            ->first(['p.product_id', 'p.sku']);

        if (!$product) {
            $this->persistProductSyncStatus($listing, 'bulk_delete_from_lazada', ['ok' => false, 'body' => ['code' => 'ERP_PRODUCT_NOT_FOUND', 'message' => 'ERP product not found']]);
            $errCount++;
            continue;
        }

        $productId = (int) ($product->product_id ?? 0);
        $mainSku = trim((string) ($product->sku ?? ''));

        $variantSkus = $this->payload()->getErpVariantStockByProductId($productId);
        $sellerSkuList = [];
        foreach ($variantSkus as $v) {
            $s = trim((string)($v['seller_sku'] ?? ''));
            if ($s !== '') {
                $sellerSkuList[] = $s;
            }
        }
        $sellerSkuList = array_values(array_unique($sellerSkuList));

        if (empty($sellerSkuList)) {
            if ($mainSku === '') {
                $this->persistProductSyncStatus($listing, 'bulk_delete_from_lazada', ['ok' => false, 'body' => ['code' => 'EMPTY_SKU', 'message' => 'ERP product SKU is empty']]);
                $errCount++;
                continue;
            }
            $sellerSkuList = [$mainSku];
        } else {
            if ($mainSku !== '' && !in_array($mainSku, $sellerSkuList, true)) {
                $sellerSkuList[] = $mainSku;
            }
        }

        $apiPath = '/product/remove';
        $timestamp = (string) round(microtime(true) * 1000);

        $skuIdList = [];
        $itemId = trim((string) ($listing->lazada_item_id ?? ''));
        if ($itemId !== '') {
            try {
                $skuIdList = $this->itemCache()->fetchSkuIdListByItemId(
                    $client,
                    (string) $setting->region,
                    (string) $creds['app_key'],
                    (string) $creds['app_secret'],
                    (string) $creds['access_token'],
                    $itemId
                );
            } catch (\Throwable $ex) {
                // best-effort only
            }
        }

        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => (string) $creds['access_token'],
        ];

        if (!empty($skuIdList)) {
            $params['sku_id_list'] = json_encode($skuIdList, JSON_UNESCAPED_SLASHES);
        } else {
            $params['seller_sku_list'] = json_encode($sellerSkuList, JSON_UNESCAPED_SLASHES);
        }
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

        $result = $client->post((string) $setting->region, $apiPath, $params);

        $err = $this->payload()->extractLazadaError($result);
        if (!empty($err['code']) && (string) $err['code'] === '6') {
            $paramsRetry = $params;
            $paramsRetry['timestamp'] = (string) round(microtime(true) * 1000);
            $paramsRetry['sign'] = $client->sign($apiPath, $paramsRetry, (string) $creds['app_secret']);
            $result = $client->post((string) $setting->region, $apiPath, $paramsRetry);
        }

        $this->persistProductSyncStatus($listing, 'bulk_delete_from_lazada', $result);

        $e = $this->payload()->extractLazadaError($result);
        if (!empty($e['ok'])) {
            $okCount++;
            try {
                $listing->lazada_item_id = null;
                $listing->lazada_deleted_at = now();
                // The link is gone; void the mirror rather than keep a stale
                // status the sweep can no longer correct (#184 C).
                $listing->live_status = null;
                $listing->live_checked_at = null;
                $listing->save();
            } catch (\Throwable $ex) {
                // ignore
            }
            $this->states()->clearErrors([(int) $listing->product_id]);
        } else {
            $errCount++;
        }

        LazadaApiLog::safeCreate([
            'pack' => 'lazada.product.remove.bulk',
            'method' => 'POST',
            'api_path' => $apiPath,
            'auth_required' => true,
            'request_params' => $params,
            'response_status' => (int)($result['status'] ?? 0),
            'ok' => (bool)($result['ok'] ?? false),
            'response_body' => $result['body'] ?? $result,
            'user_id' => auth()->id(),
        ]);
    }

    return redirect()->to(url()->previous(route('ext.lazada.products.index')))
        ->with('status', "Bulk Delete: processed ".count($ids)." product(s). Success: {$okCount}, Error: {$errCount}.");
}

    /**
     * The Import page (cleanup point 10, the owner 2026-09-03): its own page
     * under the Catalog menu, not a card squatting on the workbench.
     */
    /**
     * The Import page, stateless (the owner, 2026-09-04): nothing about the
     * store's items is saved - blank until Fetch reads Lazada live, rows
     * exist only in the render, Import re-reads by item id.
     */
    public function importPage(Request $request, LazadaClient $client)
    {
        $fetched = null;
        $fetchError = null;
        if ($request->boolean('fetch')) {
            [$fetched, $fetchError] = $this->fetchUnmatched($client);
        }

        return view('ext-lazada::products.import', [
            'fetched' => $fetched,
            'fetchError' => $fetchError,
        ]);
    }

    public function edit(int $productId, \Extensions\lazada\Services\Lazada\LazadaLiveListing $liveListing)
    {
        $listing = $this->listingOrBlank($productId);

        /* The live half (Lazada parity with the Shopee listing page,
         * 2026-08-24): what Lazada says about this item RIGHT NOW, fetched
         * on this page load. Only an uploaded listing has a live half. */
        $live = null;
        $liveError = null;
        if (!empty($listing->lazada_item_id)) {
            $liveSetting = LazadaSetting::defaultStore()?->decrypted();
            $liveCreds = LazadaSetting::activeCredentials($liveSetting);
            if (!$liveSetting || !$liveCreds['complete']) {
                $liveError = 'Lazada settings are incomplete, so the live state could not be fetched.';
            } else {
                $result = $liveListing->fetch($liveSetting, $liveCreds, (string) $listing->lazada_item_id);
                $live = $result['live'];
                $liveError = $result['error'];
                // The freshest answer there is: write it to the mirror so the
                // lists agree with this page at once (the owner, 2026-08-28).
                $mirror = $live !== null ? (string) ($live['status'] ?? '')
                    : (str_contains((string) $liveError, 'holds no record') ? 'missing' : '');
                if ($mirror !== '') {
                    LazadaProduct::query()->whereKey($listing->id)
                        ->update(['live_status' => $mirror, 'live_checked_at' => now()]);
                }
            }
        }

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $products = DB::table($pfx.'product as p')
            ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->orderByDesc('p.product_id')
            ->limit(200)
            ->get(['p.product_id', 'p.sku', 'p.model', 'p.quantity', 'p.status', 'p.image', 'pd.name']);

        // Cached Lazada categories for dropdown selection
        $categories = LazadaCategory::query()
            ->orderBy('name')
            ->limit(5000)
            ->get(['category_id', 'name']);

        $template = null;
        $attributes = [];
        $selectedBrandName = '';
        /* A blank category or brand shows its group's: the value a push
         * sends. Saving it unchanged keeps it following the group. */
        $inheritSvc = app(\Extensions\lazada\Services\Lazada\LazadaInheritedSettings::class);
        $shown = $inheritSvc->fill($listing, $inheritSvc->forProducts([(int) $listing->product_id])[(int) $listing->product_id] ?? null)['listing'];
        if (!empty($shown->brand_id)) {
            $b = LazadaBrand::query()
                ->where('region', $this->region())
                ->where('brand_id', (int)$shown->brand_id)
                ->first();
            $selectedBrandName = $b ? (string)$b->name : '';
        }
        if ($shown->primary_category_id) {
            $template = LazadaCategoryTemplate::query()
                ->where('region', $this->region())
                ->where('primary_category_id', (int)$shown->primary_category_id)
                ->first();

            if ($template && $template->template_body) {
                $attributes = $this->attrs()->extractAttributes($template->template_body);
                // Brand is handled by the top-level Brand selector (listing.brand_id); do not render as a mappable attribute.
                $attributes = array_values(array_filter($attributes, function ($a) {
                    $k = strtolower(trim((string)($a['key'] ?? '')));
                    return $k !== 'brand';
                }));
            }
        }

        // Variants: use OpenCart-style product_option_value rows
        $variants = DB::table($pfx.'product_option_value as pov')
            ->leftJoin($pfx.'option_description as od', function ($j) use ($langId) {
                $j->on('pov.option_id', '=', 'od.option_id')
                    ->where('od.language_id', '=', $langId);
            })
            ->leftJoin($pfx.'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                    ->where('ovd.language_id', '=', $langId);
            })
            ->where('pov.product_id', (int)$listing->product_id)
            ->orderBy('pov.product_option_value_id')
            ->get([
                'pov.product_option_value_id',
                'pov.option_id',
                'pov.option_value_id',
                'pov.sku',
                'pov.quantity',
                'pov.absolute_price',
                'od.name as option_name',
                'ovd.name as option_value_name',
            ]);

        $variantMap = LazadaProductVariant::query()
            ->where('lazada_product_id', $listing->id)
            ->get()
            ->keyBy(function ($v) {
                return $v->product_option_value_id === null ? 'base' : (string)$v->product_option_value_id;
            });

        // The attribute sheet: saved answers, group defaults, the catalogue
        // row and its suggestions. Shared with fetchAttributesAjax() so the
        // sheet a category change injects is the sheet a page load renders.
        $sheet = $this->attributeSheet($listing, $attributes);
        $productOwnSaved = $sheet['productOwnAttrs'];
        $groupAttrs = $sheet['groupAttrs'];
        $saved = $sheet['saved'];
        $productRow = $sheet['productRow'];
        $suggested = $sheet['suggested'];

        // For display, so browser facing rather than Lazada facing.
        $productImageUrl = $this->images()->toDisplayImageUrl($productRow->image ?? null);

        // Brand suggestions
        $brandSuggestion = $this->attrs()->suggestBrandFromManufacturer($productRow->manufacturer_name ?? null);
        if (!$listing->brand_id && $brandSuggestion['brand_id']) {
            // Do not auto-save; just suggest in UI
        }

        /* Variation coverage (Phase F, #184): the catalogue's SKUs vs the
         * variant rows Lazada holds, so this page can say "On Lazada:
         * 1 of 2 variations" and name the missing side - and say that
         * UPDATE ON LAZADA is the verb that sends them, because the update
         * payload carries every catalogue variation. The unlink-and-resync
         * ritual existed only because nothing said so. */
        $covSkus = \App\Integrations\Listings\ListingVariations::sold('lazada', (int) ($listing->lazada_setting_id ?? 0), [(int) $listing->product_id], $pfx)[(int) $listing->product_id] ?? [];
        $covVariantSkus = $listing->variants->pluck('seller_sku')->map(fn ($v) => strtolower(trim((string) $v)))->flip();
        $coverage = [
            'total' => count($covSkus),
            'linked' => count(array_filter($covSkus, fn ($sku) => $covVariantSkus->has(strtolower($sku)))),
            'missing' => array_values(array_filter($covSkus, fn ($sku) => !$covVariantSkus->has(strtolower($sku)))),
        ];

        /* The strip's state word comes from the engine, the same one the
           Listings page reads (the one vocabulary, #184 Phase B), so this
           page cannot drift into a second set of words for the same
           condition. */
        $listingState = $listing->exists
            ? (app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class)
                ->forProducts([(int) $listing->product_id])[(int) $listing->product_id] ?? null)
            : null;

        /* What this listing still needs before Lazada would take it, by
           the same computation the Ready column and the upload gate read,
           so the page cannot promise what the push refuses. A listing with
           no row yet is judged as the blank row a push would make. */
        $readiness = $listing->exists
            ? (app(\Extensions\lazada\Services\Lazada\LazadaListingReadiness::class)->forListings(collect([$listing]))[(int) $listing->id] ?? null)
            : null;

        $groupBand = $listing->exists ? $this->groupBandFor($listing) : ['current' => null, 'groups' => [], 'values' => []];

        return view('ext-lazada::products.form', \App\Integrations\Listings\ListingImages::cardData((int) $listing->product_id, $listing->image_order ?? null, $listing ?? null, \App\Services\Media\ListingWatermark::groupTemplateId('lazada_product_groups', 'lazada_product_group_products', 'lazada_product_group_id', (int) $listing->product_id, 'lazada_setting_id', (int) ($listing->lazada_setting_id ?? 0) ?: null)) + [
            'readiness' => $readiness,
            'listingState' => $listingState,
            'coverage' => $coverage,
            ...\App\Integrations\Listings\ListingVariations::cardData(
                'lazada', (int) ($listing->lazada_setting_id ?? 0), (int) $listing->product_id,
                !empty($listing->lazada_item_id) ? $listing->variants->pluck('seller_sku')->all() : []
            ),
            'groupBand' => $groupBand,
            'storeId' => (int) (LazadaSetting::defaultStore()?->id ?? 0),
            'descriptionTemplates' => \App\Models\DescriptionTemplate::forStore('lazada', (int) (LazadaSetting::defaultStore()?->id ?? 0)),
            'mode' => $listing->exists ? 'edit' : 'create',
            'listing' => $listing,
            'effectiveCategoryId' => $shown->primary_category_id,
            'effectiveBrandId' => $shown->brand_id,
            'effectiveBrandOverride' => $shown->brand_name_override,
            'products' => $products,
            'categories' => $categories,
            'template' => $template,
            'attributes' => $attributes,
            'saved' => $saved,
            'variants' => $variants,
            'variantMap' => $variantMap,
            'productRow' => $productRow,
            'suggested' => $suggested,
            'groupAttrs' => $groupAttrs,
            'productOwnAttrs' => $productOwnSaved,
            'productImageUrl' => $productImageUrl,
            'brands' => collect(),
            'brandSuggestion' => $brandSuggestion,
            'selectedBrandName' => $selectedBrandName,
            'erpSourceFields' => LazadaAttributes::ERP_SOURCE_FIELDS,
            'live' => $live,
            'liveError' => $liveError,
        ]);
    }

    /**
     * The Product group band's data: the group holding this product on the
     * listing's store, the store's groups, and each group's values keyed as
     * the page's band fields.
     *
     * @return array{current: ?int, groups: list<array{id: int, name: string, url: string}>, values: array<int, array<string, mixed>>}
     */
    private function groupBandFor(LazadaProduct $listing): array
    {
        $storeId = (int) ($listing->lazada_setting_id ?? 0);
        $current = \App\Integrations\Listings\ListingGroup::current([
            'pivot' => 'lazada_product_group_products', 'fk' => 'lazada_product_group_id',
            'groups' => 'lazada_product_groups', 'storeFk' => 'lazada_setting_id',
            'storeId' => $storeId,
        ], (int) $listing->product_id);

        $groups = \Extensions\lazada\Models\LazadaProductGroup::query()
            ->where('lazada_setting_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name', 'lazada_category_id', 'brand_id', 'brand_name_override', 'markup_fixed', 'markup_percent', 'watermark_template_id']);
        $groupIds = $groups->pluck('id')->map(fn ($v) => (int) $v)->all();

        $categoryNames = LazadaCategory::query()
            ->whereIn('category_id', $groups->pluck('lazada_category_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all() ?: [0])
            ->pluck('name', 'category_id');
        $brandNames = LazadaBrand::query()
            ->where('region', $this->region())
            ->whereIn('brand_id', $groups->pluck('brand_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all() ?: [0])
            ->pluck('name', 'brand_id');
        $answers = LazadaProductGroupAttribute::query()
            ->whereIn('lazada_product_group_id', $groupIds ?: [0])
            ->get(['lazada_product_group_id', 'attribute_key', 'value'])
            ->filter(fn ($a) => trim((string) $a->value) !== '' && strtolower(trim((string) $a->attribute_key)) !== 'brand')
            ->groupBy('lazada_product_group_id');

        $list = [];
        $values = [];
        foreach ($groups as $g) {
            $id = (int) $g->id;
            $list[] = [
                'id' => $id,
                'name' => (string) $g->name,
                'url' => $storeId > 0
                    ? route('ext.lazada.product-groups.products', ['store' => $storeId, 'id' => $id])
                    : route('ext.lazada.product-groups.products', $id),
            ];

            $categoryId = (int) ($g->lazada_category_id ?? 0);
            $categoryName = $categoryId > 0 ? (string) ($categoryNames[$categoryId] ?? '') : '';

            $brandId = (int) ($g->brand_id ?? 0);
            $override = trim((string) ($g->brand_name_override ?? ''));
            $none = strtolower($override) === 'no brand';
            $brand = null;
            if ($brandId > 0 || $override !== '') {
                $brand = [
                    'id' => $brandId ?: null,
                    'label' => $none ? 'No Brand' : (string) ($brandNames[$brandId] ?? $override),
                    'none' => $none,
                ];
            }

            $values[$id] = [
                'category' => $categoryId > 0
                    ? ['id' => $categoryId, 'label' => $categoryName !== '' ? $categoryName . ' (' . $categoryId . ')' : (string) $categoryId]
                    : null,
                'brand' => $brand,
                'markup_percent' => $g->markup_percent,
                'markup_fixed' => $g->markup_fixed,
                'watermark' => $g->watermark_template_id !== null ? (int) $g->watermark_template_id : null,
                'attributes' => ($answers[$id] ?? collect())->pluck('value', 'attribute_key')->all(),
            ];
        }

        return ['current' => $current, 'groups' => $list, 'values' => $values];
    }

    public function update(Request $request, int $productId)
    {
        $listing = $this->listingRowFor($productId);

        $data = $request->validate([
            'primary_category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'no_brand' => 'nullable|boolean',
            'markup_fixed' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0',
            'attributes' => 'nullable|array',
            'attributes.*' => 'nullable|string|max:2000',
            'item_name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:20000',
            'description_prefix_id' => 'nullable|integer',
            'watermark_template_id' => 'nullable|integer',
            'watermark_all_images' => 'nullable|boolean',
            'description_suffix_id' => 'nullable|integer',
            // The listing's own starting price and parcel; blank follows the catalog.
            'price' => 'nullable|numeric|min:0|max:99999999',
            'weight' => 'nullable|numeric|min:0.001|max:99999',
            'package_length' => 'nullable|numeric|min:0.01|max:99999',
            'package_width' => 'nullable|numeric|min:0.01|max:99999',
            'package_height' => 'nullable|numeric|min:0.01|max:99999',
            // A variation's own price rides this form; its SKU and stock
            // always come from the catalog.
            'variants' => 'nullable|array',
            'variants.*.price' => 'nullable|numeric|min:0|max:99999999',
            'image_order' => 'nullable|string|max:20000',
            ...\App\Integrations\Listings\ListingVariations::rules(),
            ...\App\Integrations\Listings\ListingGroup::rules(),
            'push' => 'nullable|boolean',
        ]);

        $noBrand = (bool)($data['no_brand'] ?? false);
        if ($noBrand) {
            $data['brand_id'] = null;
        }

        $blank = fn ($v) => ($v === null || trim((string) $v) === '') ? null : (string) $v;
        /* The listing's own words, as typed. Only a save that carried a
         * field changes it. */
        if ($request->has('item_name')) {
            $listing->item_name = \App\Integrations\Listings\ListingContent::own($data['item_name'] ?? null);
        }
        if ($request->has('description')) {
            $listing->description = \App\Integrations\Listings\ListingContent::descriptionToSave($data['description'] ?? null, $listing->description, $request->input('description_edited'));
        }
        $listing->description_prefix_id = ((int) ($data['description_prefix_id'] ?? 0)) ?: null;
        $listing->forceFill(\App\Integrations\Listings\ListingImages::submittedWatermark($request));
        $listing->description_suffix_id = ((int) ($data['description_suffix_id'] ?? 0)) ?: null;
        $listing->primary_category_id = $data['primary_category_id'] ?? null;
        $listing->brand_id = $data['brand_id'] ?? null;
        $listing->brand_name_override = $noBrand ? 'No Brand' : null;
        $listing->markup_fixed = $data['markup_fixed'] ?? null;
        $listing->markup_percent = $data['markup_percent'] ?? null;

        /* A product with variations prices each variation, so it keeps no
         * price of its own. Only a save that carried a field changes it. */
        $pfx = (string) config('catalog.prefix');
        $hasVariations = DB::table($pfx . 'product_option_value')->where('product_id', (int) $listing->product_id)->exists();
        if ($hasVariations) {
            $listing->price = null;
        } elseif ($request->has('price')) {
            $listing->price = $blank($data['price'] ?? null);
        }
        foreach (['weight', 'package_length', 'package_width', 'package_height'] as $parcelField) {
            if ($request->has($parcelField)) {
                $listing->{$parcelField} = $blank($data[$parcelField] ?? null);
            }
        }

        /* The group dropdown: picking a group moves the product into it,
         * No group takes it out. The membership row points at this listing,
         * so the listing is saved first. */
        $picked = \App\Integrations\Listings\ListingGroup::submitted($request);
        if ($picked['present']) {
            if (! $listing->exists) {
                $listing->save();
            }
            \App\Integrations\Listings\ListingGroup::assign([
                'pivot' => 'lazada_product_group_products', 'fk' => 'lazada_product_group_id',
                'groups' => 'lazada_product_groups', 'storeFk' => 'lazada_setting_id',
                'storeId' => (int) ($listing->lazada_setting_id ?? 0),
            ], (int) $listing->product_id, $picked['id'], ['lazada_product_id' => $listing->id, 'created_at' => now()]);
        }

        /* A value equal to the group's is stored as following the group, so
         * the page's pre-filled group values never become the listing's own
         * and a later change to the group reaches this listing. */
        $followGroup = app(\Extensions\lazada\Services\Lazada\LazadaInheritedSettings::class)->forProducts([(int) $listing->product_id])[(int) $listing->product_id] ?? null;
        if ($followGroup) {
            $groupWatermark = (int) \Extensions\lazada\Models\LazadaProductGroup::query()->whereKey($followGroup['group_id'] ?? 0)->value('watermark_template_id');
            if ((int) ($listing->watermark_template_id ?? 0) > 0 && (int) $listing->watermark_template_id === $groupWatermark) {
                $listing->watermark_template_id = null;
            }
            if ((int) ($listing->primary_category_id ?? 0) > 0 && (int) $listing->primary_category_id === (int) ($followGroup['category_id'] ?? 0)) {
                $listing->primary_category_id = null;
            }
            if ((int) ($listing->brand_id ?? 0) === (int) ($followGroup['brand_id'] ?? 0)
                && strtolower(trim((string) $listing->brand_name_override)) === strtolower(trim((string) ($followGroup['brand_name'] ?? '')))) {
                $listing->brand_id = null;
                $listing->brand_name_override = null;
            }
            $num = fn ($v) => ($v === null || $v === '') ? null : (float) $v;
            if ($num($listing->markup_fixed) === $num($followGroup['markup_fixed'] ?? null)
                && $num($listing->markup_percent) === $num($followGroup['markup_percent'] ?? null)) {
                $listing->markup_fixed = null;
                $listing->markup_percent = null;
            }
        }

        /* The pictures this listing sends, in the order the card arranged
         * them. Only a save that CARRIED the arrangement may change it:
         * the input is invisible, so a caller that does not send it must
         * not be read as one that cleared it. */
        if ($request->has('image_order')) {
            $listing->image_order = \App\Integrations\Listings\ListingImages::submitted(
                (int) $listing->product_id,
                $data['image_order'] ?? null
            );
        }

        /* The discount RULE (#146): percent off with a window; the sale
         * price itself is never stored - it is computed from the final
         * price at push time. Clearing the percent clears the rule. */

        $listing->save();

        /* The attribute answers ride the same form since 2026-09-06 (the owner:
         * "Everything should be in Save and Push"). A request without the
         * key leaves the answers alone; one with it saves them as given.
         * Brand is the form's own control, never an attribute row. */
        if ($request->has('attributes')) {
            $this->storeAttributes($listing, (array) ($data['attributes'] ?? []), (array) ($followGroup['attributes'] ?? []));
        }
        if ($request->has('variants')) {
            $this->storeVariants($listing, (array) ($data['variants'] ?? []));
        }
        if (($sellHere = \App\Integrations\Listings\ListingVariations::submitted($request)) !== null) {
            \App\Integrations\Listings\ListingVariations::save('lazada', (int) ($listing->lazada_setting_id ?? 0), (int) $listing->product_id, $sellHere);
        }

        ActivityLogger::log('updated', 'Lazada Product', $listing->id, 'ERP #' . (int)$listing->product_id);

        // Save and push: the one press that saves everything above and sends
        // the listing, as a create or, once linked, as an update.
        if ($request->boolean('push')) {
            return $this->uploadToLazada($request, $productId, app(LazadaClient::class));
        }

        $note = '';
        if ($request->has('attributes')) {
            $ready = app(\Extensions\lazada\Services\Lazada\LazadaListingReadiness::class)->forListings(collect([$listing->fresh()]))[$listing->id] ?? null;
            $note = $ready && ! $ready['ready'] ? ' Still needed before a push: ' . implode('; ', $ready['missing']) . '.' : '';
        }

        // A plain save stays on the page and keeps the way back to the search.
        $back = \App\Support\BackTo::safe($request->input('back'), '');

        return redirect()->route('ext.lazada.products.edit', $back !== '' ? ['productId' => $listing->product_id, 'back' => $back] : ['productId' => $listing->product_id])->with('status', 'Listing saved.' . $note);
    }

    /**
     * `attributes.<key>` => Lazada's sentence, for every attribute row a
     * refusal names. Empty when the refusal is about something else.
     */
    private function attributeRowErrors($templateBody, array $answer): array
    {
        $keyOf = $this->attrs()->fieldKeyMapper($templateBody);
        $keys = array_map(fn ($a) => (string) ($a['key'] ?? ''), $this->attrs()->extractAttributes($templateBody));
        $errors = [];
        foreach ((array) ($answer['field_errors'] ?? []) as $fe) {
            $text = (string) ($fe['message'] ?? '');
            $named = [];
            if (($fe['field'] ?? '') !== '' && ($key = $keyOf((string) $fe['field'])) !== null) {
                $named = [$key];
            } else {
                $named = LazadaAttributes::keysNamedIn($text, $keys);
            }
            foreach ($named as $key) {
                $errors['attributes.' . $key] = 'Lazada: ' . $text;
            }
        }
        if ($errors === [] && ($answer['message'] ?? '') !== '') {
            foreach (LazadaAttributes::keysNamedIn((string) $answer['message'], $keys) as $key) {
                $errors['attributes.' . $key] = 'Lazada: ' . $answer['message'];
            }
        }

        return $errors;
    }

    /** Saves the attribute answers as given; an empty answer clears the row. */
    private function storeAttributes(LazadaProduct $listing, array $attrs, array $groupAnswers = []): void
    {
        unset($attrs['brand']);

        DB::transaction(function () use ($listing, $attrs, $groupAnswers) {
            foreach ($attrs as $key => $value) {
                $key = trim((string) $key);
                if ($key === '') {
                    continue;
                }
                // The group's own answer, unchanged, follows the group.
                if (array_key_exists($key, $groupAnswers)
                    && strtolower(trim((string) $value)) === strtolower(trim((string) $groupAnswers[$key]))) {
                    LazadaProductAttribute::query()->where('lazada_product_id', $listing->id)->where('attribute_key', $key)->delete();
                    continue;
                }
                LazadaProductAttribute::query()->updateOrCreate(
                    ['lazada_product_id' => $listing->id, 'attribute_key' => $key],
                    ['value' => $value === null || $value === '' ? null : (string) $value]
                );
            }
        });
    }

    /**
     * The attribute sheet for one listing: its own saved answers over the
     * group's defaults, the catalogue row, and what the catalogue suggests
     * for each attribute. One source for the edit page and the sheet an
     * AJAX category change injects.
     *
     * @return array{productOwnAttrs: array, groupAttrs: array, saved: array, productRow: ?object, suggested: array}
     */
    private function attributeSheet(LazadaProduct $listing, array $attributes): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productOwnSaved = LazadaProductAttribute::query()
            ->where('lazada_product_id', $listing->id)
            ->pluck('value', 'attribute_key')
            ->toArray();

        // The linked group's values serve as defaults; the listing's own win.
        $groupAttrs = [];
        $firstGroup = $listing->groups()->first();
        if ($firstGroup) {
            $groupAttrs = LazadaProductGroupAttribute::query()
                ->where('lazada_product_group_id', $firstGroup->id)
                ->pluck('value', 'attribute_key')
                ->toArray();
        }
        $saved = array_merge($groupAttrs, $productOwnSaved);

        $productRow = DB::table($pfx.'product as p')
            ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx.'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->where('p.product_id', (int)$listing->product_id)
            ->first([
                'p.product_id','p.sku','p.model','p.image','p.price','p.quantity',
                'p.weight','p.length','p.width','p.height','p.date_modified',
                'p.upc','p.ean','p.jan','p.isbn','p.mpn',
                'pd.name','pd.description','pd.meta_title','pd.meta_description',
                'm.name as manufacturer_name',
            ]);

        return [
            'productOwnAttrs' => $productOwnSaved,
            'groupAttrs' => $groupAttrs,
            'saved' => $saved,
            'productRow' => $productRow,
            'suggested' => $this->attrs()->suggestForAttributes($attributes, $productRow, $saved),
        ];
    }

    /**
     * The attribute sheet for a category, as the listing page's category
     * combo asks for it (the owner, 2026-09-06: "auto fetch attribute while
     * choosing a category"). A template already on file answers at once;
     * a category never read, or a re-read, asks Lazada and files it.
     * Nothing about the listing is saved here: the form does that.
     */
    public function fetchAttributesAjax(Request $request, int $productId, LazadaClient $client)
    {
        $listing = $this->listingRowFor($productId);

        $data = $request->validate([
            'primary_category_id' => 'required|integer|min:1',
            'reread' => 'nullable|boolean',
        ]);
        $categoryId = (int) $data['primary_category_id'];

        $template = LazadaCategoryTemplate::query()
            ->where('region', $this->region())
            ->where('primary_category_id', $categoryId)
            ->first();

        if (! $template || $request->boolean('reread') || empty($template->template_body)) {
            $read = $this->readCategoryTemplate($categoryId, $client);
            if (! $read['ok']) {
                return response()->json(['ok' => false, 'message' => $read['message']], 422);
            }
            $template = $read['template'];
        }

        $attributes = array_values(array_filter(
            $this->attrs()->extractAttributes((array) $template->template_body),
            fn ($a) => strtolower(trim((string) ($a['key'] ?? ''))) !== 'brand'
        ));
        $sheet = $this->attributeSheet($listing, $attributes);

        $html = $attributes === [] ? '' : view('ext-lazada::products._attributes', [
            'template' => $template,
            'attributes' => $attributes,
            'saved' => $sheet['saved'],
            'suggested' => $sheet['suggested'],
            'groupAttrs' => $sheet['groupAttrs'],
            'productOwnAttrs' => $sheet['productOwnAttrs'],
            'erpSourceFields' => LazadaAttributes::ERP_SOURCE_FIELDS,
        ])->render();

        return response()->json([
            'ok' => true,
            'html' => $html,
            'count' => count($attributes),
            'fetched_at' => $template->fetched_at ? $template->fetched_at->format('Y-m-d H:i') : null,
            'message' => $attributes === [] ? 'This category has no attributes to fill in.' : null,
        ]);
    }

    /**
     * Asks Lazada what a category needs and files the answer as the
     * store's template for that category.
     *
     * @return array{ok: bool, template: ?LazadaCategoryTemplate, message: string}
     */
    public function readCategoryTemplate(int $categoryId, LazadaClient $client): array
    {
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret']) {
            return ['ok' => false, 'template' => null, 'message' => 'This store is not connected to Lazada yet, so its categories cannot be read.'];
        }

        $apiPath = '/category/attributes/get';
        $params = [
            'app_key' => (string)$creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => (string)round(microtime(true) * 1000),
            'primary_category_id' => (string)$categoryId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string)$creds['app_secret']);
        $result = $client->get((string)$setting->region, $apiPath, $params);

        LazadaApiLog::safeCreate([
            'pack' => 'lazada.category.attributes',
            'method' => 'GET',
            'api_path' => $apiPath,
            'auth_required' => false,
            'request_params' => ['primary_category_id' => $categoryId],
            'response_status' => (int) ($result['status'] ?? 0),
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null,
            'user_id' => auth()->id(),
        ]);

        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        if (! ($result['ok'] ?? false) || ! \App\Support\MarketplaceVerdict::ok('lazada', true, $body)) {
            $answer = app(LazadaPushPayload::class)->extractLazadaError($result);

            return ['ok' => false, 'template' => null, 'message' => 'Lazada did not return the attributes for this category: ' . ($answer['message'] ?: 'no answer') . '.'];
        }

        $template = LazadaCategoryTemplate::query()->updateOrCreate(
            ['region' => (string)$setting->region, 'primary_category_id' => $categoryId],
            ['template_body' => $body, 'fetched_at' => now()]
        );

        return ['ok' => true, 'template' => $template, 'message' => ''];
    }

    public function syncBrands(Request $request, int $productId, LazadaClient $client)
    {
        $listing = $this->listingRowFor($productId);

        $req = $request->validate([
            'max_pages' => 'nullable|integer|min:1|max:200',
            'page_size' => 'nullable|integer|min:1|max:200',
            // Backward compatible with older UI/explorer inputs
            'page_no' => 'nullable|integer|min:1|max:100000',
            'startRow' => 'nullable|integer|min:0|max:100000000',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ]);

        $maxPages = (int)($req['max_pages'] ?? 20);
        // Prefer Lazada-native key if supplied
        $pageSize = (int)($req['pageSize'] ?? ($req['page_size'] ?? 200));

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret']) {
            return redirect()->route('ext.lazada.products.edit', $listing->product_id)
                ->with('status', 'Missing Lazada settings (region/app key/app secret).');
        }

        $apiPath = '/category/brands/query';
        $region = (string)$setting->region;
        $totalSaved = 0;

        // Lazada Open Platform GetBrandByPages requires startRow + pageSize (NOT page_no/page_size)
        // startRow is zero-based row offset.
        $explicitStartRow = isset($req['startRow']) ? (int)$req['startRow'] : null;
        $explicitPageNo = isset($req['page_no']) ? (int)$req['page_no'] : null;

        // If a startRow was explicitly provided, we will fetch starting from there (single page unless max_pages > 1).
        // Otherwise we page from 0 using max_pages.
        $startPageNo = $explicitPageNo && $explicitPageNo > 0 ? $explicitPageNo : 1;
        for ($pageNo = $startPageNo; $pageNo <= $maxPages; $pageNo++) {
            $timestamp = (string)round(microtime(true) * 1000);
            $startRow = $explicitStartRow !== null
                ? (int)($explicitStartRow + (($pageNo - $startPageNo) * $pageSize))
                : (int)(($pageNo - 1) * $pageSize);
            $params = [
                'app_key' => (string)$creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'startRow' => (string)$startRow,
                'pageSize' => (string)$pageSize,
                // Optional: if you want localized brand names, add languageCode (e.g. en_PH)
                // 'languageCode' => 'en_PH',
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string)$creds['app_secret']);
            $result = $client->get($region, $apiPath, $params);

			LazadaApiLog::safeCreate([
                'pack' => 'lazada.brands.query',
                'method' => 'GET',
                'api_path' => $apiPath,
                'auth_required' => false,
                'request_params' => $params,
                'response_status' => (int)($result['status'] ?? 0),
                'ok' => (bool)($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
			]);

			if (!(bool)($result['ok'] ?? false)) {
                break;
            }

            $brands = $this->extractBrandRows($result['body'] ?? null);
            if (empty($brands)) {
                break;
            }

            DB::transaction(function () use ($brands, $region, &$totalSaved) {
                foreach ($brands as $b) {
                    $brandId = (int)($b['brand_id'] ?? $b['id'] ?? 0);
                    $name = (string)($b['name'] ?? $b['brand_name'] ?? '');
                    $name = trim($name);
                    if ($brandId <= 0 || $name === '') {
                        continue;
                    }

                    LazadaBrand::query()->updateOrCreate(
                        ['region' => $region, 'brand_id' => $brandId],
                        ['name' => $name, 'raw' => is_array($b) ? $b : null]
                    );
                    $totalSaved++;
                }
            });
        }

        return redirect()->route('ext.lazada.products.edit', $listing->product_id)
            ->with('status', 'Brands synced. Saved/updated: ' . $totalSaved);
    }

    /**
     * Sync Lazada brands without requiring a listing record (useful on Create Listing).
     * This keeps brand cache up to date for dropdown usage.
     */
    public function syncBrandsGlobal(Request $request, LazadaClient $client)
    {
        $req = $request->validate([
            'max_pages' => 'nullable|integer|min:1|max:200',
            'page_size' => 'nullable|integer|min:1|max:200',
            // Backward compatible with older UI/explorer inputs
            'page_no' => 'nullable|integer|min:1|max:100000',
            'startRow' => 'nullable|integer|min:0|max:100000000',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ]);

        $maxPages = (int)($req['max_pages'] ?? 20);
        $pageSize = (int)($req['pageSize'] ?? ($req['page_size'] ?? 200));

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret']) {
            return back()->with('status', 'Missing Lazada settings (region/app key/app secret).');
        }

        $apiPath = '/category/brands/query';
        $region = (string)$setting->region;
        $totalSaved = 0;

        $explicitStartRow = isset($req['startRow']) ? (int)$req['startRow'] : null;
        $explicitPageNo = isset($req['page_no']) ? (int)$req['page_no'] : null;
        $startPageNo = $explicitPageNo && $explicitPageNo > 0 ? $explicitPageNo : 1;

        for ($pageNo = $startPageNo; $pageNo <= $maxPages; $pageNo++) {
            $timestamp = (string)round(microtime(true) * 1000);
            $startRow = $explicitStartRow !== null
                ? (int)($explicitStartRow + (($pageNo - $startPageNo) * $pageSize))
                : (int)(($pageNo - 1) * $pageSize);

            $params = [
                'app_key' => (string)$creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'startRow' => (string)$startRow,
                'pageSize' => (string)$pageSize,
            ];

            $params['sign'] = $client->sign($apiPath, $params, (string)$creds['app_secret']);
            $result = $client->get($region, $apiPath, $params);

            LazadaApiLog::safeCreate([
                'pack' => 'lazada.brands.query',
                'method' => 'GET',
                'api_path' => $apiPath,
                'auth_required' => false,
                'request_params' => $params,
                'response_status' => (int)($result['status'] ?? 0),
                'ok' => (bool)($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
            ]);

            if (!(bool)($result['ok'] ?? false)) {
                break;
            }

            $brands = $this->extractBrandRows($result['body'] ?? null);
            if (empty($brands)) {
                break;
            }

            DB::transaction(function () use ($brands, $region, &$totalSaved) {
                foreach ($brands as $b) {
                    $brandId = (int)($b['brand_id'] ?? $b['id'] ?? 0);
                    $name = (string)($b['name'] ?? $b['brand_name'] ?? '');
                    $name = trim($name);
                    if ($brandId <= 0 || $name === '') {
                        continue;
                    }

                    LazadaBrand::query()->updateOrCreate(
                        ['region' => $region, 'brand_id' => $brandId],
                        ['name' => $name, 'raw' => is_array($b) ? $b : null]
                    );
                    $totalSaved++;
                }
            });
        }

        return back()->with('status', 'Brands synced. Saved/updated: ' . $totalSaved);
    }

    private function extractBrandRows($body): array
    {
        if (!is_array($body)) {
            return [];
        }

        // Lazada responses vary by endpoint/version. We support common shapes and also
        // do a recursive search for the first list that looks like brand rows.
        $data = $body['data'] ?? $body;

        // Fast paths for known keys
        $candidates = [
            $data['brands'] ?? null,
            $data['brand_list'] ?? null,
            $data['brandList'] ?? null,
            $data['module']['brands'] ?? null,
            $data['result']['brands'] ?? null,
            $data['items'] ?? null,
        ];

        foreach ($candidates as $cand) {
            if (is_array($cand) && array_is_list($cand)) {
                return $cand;
            }
        }

        $found = $this->findBrandRowListRecursive($data);
        return $found ?? [];
    }

    private function findBrandRowListRecursive($node): ?array
    {
        if (!is_array($node)) {
            return null;
        }

        if (array_is_list($node) && !empty($node) && is_array($node[0])) {
            $first = $node[0];
            $hasId = array_key_exists('brand_id', $first) || array_key_exists('id', $first);
            $hasName = array_key_exists('name', $first) || array_key_exists('brand_name', $first);
            if ($hasId && $hasName) {
                return $node;
            }
        }

        foreach ($node as $v) {
            if (is_array($v)) {
                $r = $this->findBrandRowListRecursive($v);
                if ($r !== null) {
                    return $r;
                }
            }
        }

        return null;
    }


    private function findBrandListRecursive($node): ?array
    {
        if (!is_array($node)) {
            return null;
        }

        // If this is a list, check if its elements look like brand rows.
        if (array_is_list($node) && !empty($node)) {
            $first = $node[0];
            if (is_array($first)) {
                $hasId = array_key_exists('brand_id', $first) || array_key_exists('brandId', $first) || array_key_exists('id', $first);
                $hasName = array_key_exists('name', $first) || array_key_exists('brand_name', $first) || array_key_exists('brandName', $first);
                if ($hasId && $hasName) {
                    return $node;
                }
            }
        }

        // Otherwise, traverse children.
        foreach ($node as $v) {
            if (is_array($v)) {
                $res = $this->findBrandListRecursive($v);
                if ($res !== null) {
                    return $res;
                }
            }
        }
        return null;
    }

    public function saveVariants(Request $request, int $productId)
    {
        $listing = $this->listingRowFor($productId);

        $data = $request->validate([
            'variants' => 'array',
            'variants.*.price' => 'nullable|numeric|min:0|max:99999999',
        ]);

        $this->storeVariants($listing, (array) ($data['variants'] ?? []));

        return redirect()->route('ext.lazada.products.edit', $listing->product_id)->with('status', 'Variant mapping saved.');
    }

    /**
     * The per-variation prices, saved as given; an empty box means the
     * catalog's own price. SKU and stock are never saved here: both always
     * come from the catalog. Shared with update().
     *
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function storeVariants(LazadaProduct $listing, array $rows): void
    {
        DB::transaction(function () use ($listing, $rows) {
            foreach ($rows as $povId => $row) {
                $povId = (string) $povId;
                if ($povId === '' || ! is_array($row)) {
                    continue;
                }
                LazadaProductVariant::query()->updateOrCreate(
                    [
                        'lazada_product_id' => $listing->id,
                        'product_option_value_id' => ctype_digit($povId) ? (int) $povId : null,
                    ],
                    [
                        'price' => isset($row['price']) && $row['price'] !== '' ? (float) $row['price'] : null,
                    ]
                );
            }
        });
    }

    private function region(): string
    {
        $setting = LazadaSetting::defaultStore();
        return (string)($setting->region ?? '');
    }

    // ── Unmatched Items: Sync / Link / Dismiss ─────────────────────

    /**
     * Read the store live and keep only items the catalog cannot place -
     * not linked in lazada_products, no SKU known. Pure read; one row per
     * ITEM (importing brings the whole item, SKUs and all).
     *
     * @return array{0: ?array{found: int, rows: list<array>}, 1: ?string}
     */
    private function fetchUnmatched(LazadaClient $client): array
    {
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return [null, 'Missing Lazada settings.'];
        }

        $skuMap = $this->matcher()->fetchLazadaSkuMap($setting, $client);

        $itemSkus = [];
        foreach ($skuMap as $sku => $itemIds) {
            foreach ($itemIds as $itemId) {
                $itemSkus[$itemId][] = $sku;
            }
        }

        $allProducts = [];
        $offset = 0;
        $limit = 50;
        $maxPages = 60;
        $page = 0;

        do {
            $apiPath = '/products/get';
            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
                'filter' => 'all',
                'offset' => (string) $offset,
                'limit' => (string) $limit,
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

            if ($page > 0) usleep(500000);
            $result = $client->get((string) $setting->region, $apiPath, $params);
            if (empty($result['ok'])) break;

            $body = $result['body'] ?? [];
            $data = $body['data'] ?? [];
            $products = $data['products'] ?? [];
            $totalProducts = (int) ($data['total_products'] ?? 0);

            foreach ($products as $p) {
                $itemId = $p['item_id'] ?? ($p['itemId'] ?? null);
                if ($itemId === null) continue;
                $allProducts[(string) $itemId] = $p;
            }

            $offset += $limit;
            $page++;
        } while ($offset < $totalProducts && $page < $maxPages);

        if ($allProducts === []) {
            return [null, 'Lazada answered with no products, or the read failed partway.'];
        }

        $linkedItemIds = LazadaProduct::query()
            ->whereNotNull('lazada_item_id')
            ->where('lazada_item_id', '!=', '')
            ->pluck('lazada_item_id')
            ->map(fn($v) => (string) $v)
            ->unique()
            ->all();

        $pfx = (string) config('catalog.prefix');
        $existingSkus = [];
        $lazProducts = LazadaProduct::query()->get(['id', 'product_id']);
        foreach ($lazProducts as $lp) {
            $pid = (int) $lp->product_id;
            $mainSku = DB::table($pfx . 'product')->where('product_id', $pid)->value('model');
            if ($mainSku) $existingSkus[trim((string) $mainSku)] = true;
            $ovSkus = DB::table($pfx . 'product_option_value')
                ->where('product_id', $pid)
                ->whereNotNull('sku')
                ->where('sku', '!=', '')
                ->pluck('sku');
            foreach ($ovSkus as $os) {
                $existingSkus[trim((string) $os)] = true;
            }
        }

        $rows = [];
        foreach ($allProducts as $itemId => $product) {
            if (in_array((string) $itemId, $linkedItemIds, true)) {
                continue;
            }

            $skusForItem = $itemSkus[(string) $itemId] ?? [];
            $matched = false;
            foreach ($skusForItem as $sku) {
                if (isset($existingSkus[$sku])) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                continue;
            }

            $itemName = $product['attributes']['name'] ?? ($product['name'] ?? 'Unknown');
            $imageUrl = null;
            $images = $product['images'] ?? ($product['skus'][0]['Images'] ?? []);
            if (is_array($images) && !empty($images)) {
                $imageUrl = is_string($images[0] ?? null) ? $images[0] : null;
            }
            $skuCount = is_array($product['skus'] ?? null) ? count($product['skus']) : 0;

            $rows[] = [
                'ref' => (string) $itemId,
                'sku' => (string) ($skusForItem[0] ?? ''),
                'skus' => array_values(array_map('strval', $skusForItem)),
                'channel_id' => (string) $itemId,
                'name' => (string) $itemName,
                'image_url' => (string) ($imageUrl ?? ''),
                'variants' => $skuCount,
            ];
        }

        // 60 pages of 50 is the read's ceiling; past it the page must say so rather than pass 3,000 off as everything.
        return [['found' => count($allProducts), 'rows' => $rows, 'truncated' => $offset < $totalProducts], null];
    }

    /** Import one fetched item, re-read live by its item id. */
    public function importOne(Request $request, \Extensions\lazada\Services\Lazada\LazadaItemImport $import)
    {
        $data = $request->validate(['ref' => 'required|string|max:64']);

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['complete']) {
            return redirect()->back()->with('error', 'Missing Lazada settings.');
        }

        $r = $import->import($setting, $creds, (string) $data['ref']);

        return redirect()->back()->with($r['ok'] ? 'status' : 'error', $r['message']);
    }

    /** Import selected: every ticked row, each in its own transaction. */
    public function importSelected(Request $request, \Extensions\lazada\Services\Lazada\LazadaItemImport $import)
    {
        $data = $request->validate(['refs' => 'required|array|min:1', 'refs.*' => 'string|max:64']);

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['complete']) {
            return redirect()->back()->with('error', 'Missing Lazada settings.');
        }

        $done = 0;
        $failed = [];
        $refs = array_values(array_unique($data['refs']));
        foreach ($refs as $ref) {
            $r = $import->import($setting, $creds, (string) $ref);
            if ($r['ok'] ?? false) {
                $done++;
            } else {
                $failed[] = '#' . $ref . ': ' . ($r['message'] ?? 'not imported');
            }
        }

        $line = \App\Support\BulkImportSummary::line($done, count($refs), $failed);

        return redirect()->back()->with($line['tone'], $line['message']);
    }

    /** Link a fetched item to a catalog product it already is. */
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

        /* One catalog product to one Lazada item per store, both ways
         * (the owner, 2026-09-07). A second link is refused with what holds
         * the first; this action used to keep the old link and still say
         * "Linked". */
        $listing = LazadaProduct::query()->where('product_id', $productId)->first();
        $heldItem = $listing && ! empty($listing->lazada_item_id) && is_null($listing->lazada_deleted_at) && is_null($listing->unlinked_at)
            ? (string) $listing->lazada_item_id : '';
        if ($heldItem !== '' && $heldItem !== (string) $data['ref']) {
            return redirect()->back()->with('error', 'Catalog product #' . $productId . ' is already linked to Lazada item ' . $heldItem . '. Unlink it first.');
        }
        $taken = LazadaProduct::query()->where('lazada_item_id', (string) $data['ref'])->where('product_id', '!=', $productId)
            ->whereNull('lazada_deleted_at')->whereNull('unlinked_at')->first();
        if ($taken) {
            return redirect()->back()->with('error', 'Lazada item ' . $data['ref'] . ' is already linked to catalog product #' . $taken->product_id . '. Unlink that product first.');
        }
        if ($listing) {
            $listing->lazada_item_id = (string) $data['ref'];
            $listing->lazada_deleted_at = null;
            $listing->unlinked_at = null;
            $listing->save();
        } else {
            LazadaProduct::create([
                'product_id' => $productId,
                'lazada_item_id' => (string) $data['ref'],
            ]);
        }
        $this->states()->recordOutcome($productId, null);

        return redirect()->back()->with('status', 'Linked Lazada item #' . $data['ref'] . ' to catalog product #' . $productId . '.');
    }

    /** The Link picker's catalog search: the shared shape, with the products this store already holds marked (App\Support\CatalogPickerSearch). */
    public function searchCatalogProducts(Request $request)
    {
        return response()->json(\App\Support\CatalogPickerSearch::items((string) $request->get('q', ''), function (array $ids) {
            return LazadaProduct::query()->whereIn('product_id', $ids)->whereNotNull('lazada_item_id')->where('lazada_item_id', '!=', '')
                ->whereNull('lazada_deleted_at')->whereNull('unlinked_at')
                ->get(['product_id', 'lazada_item_id'])
                ->mapWithKeys(fn ($l) => [(int) $l->product_id => 'Lazada item ' . $l->lazada_item_id])->all();
        }));
    }


}
