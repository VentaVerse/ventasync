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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LazadaProductController extends Controller
{
    // NOTE: We standardize on lazada_product_id (no legacy lazada_listing_id support).

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
        $liveSetting = LazadaSetting::query()->first()?->decrypted();
        $liveCreds = LazadaSetting::activeCredentials($liveSetting);
        $liveCounts = null;
        $liveTabError = null;
        $liveCheckedAt = null;
        if ($liveSetting && $liveCreds['complete']) {
            $statusMap = self::TAB_STATUS;
            $liveCounts = LazadaProduct::mirrorCounts($statusMap);
            $liveCheckedAt = LazadaProduct::query()->max('live_checked_at');
            $liveCheckedAt = $liveCheckedAt ? \Illuminate\Support\Carbon::parse($liveCheckedAt) : null;
        } else {
            $lazadaTab = 'all';
        }

        // Search & filter params
        $q = trim((string) $request->query('q', ''));
        $syncStatus = (string) $request->query('sync_status', 'all');
        if (!in_array($syncStatus, ['all', 'not_uploaded', 'uploaded', 'deleted', 'error', 'ready', 'not_ready'], true)) {
            $syncStatus = 'all';
        }
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

        // Product group filter, through the pivot's denormalised product_id
        if ($groupFilter === 'none') {
            $groupedIds = DB::table('lazada_product_group_products')->whereNotNull('product_id')->pluck('product_id')->unique()->all();
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
                    ->orWhere('lp.lazada_item_id', 'like', '%' . $q . '%');
            });
        }

        // Sync status filter
        if ($syncStatus === 'uploaded') {
            $query->whereNotNull('lp.lazada_item_id')->where('lp.lazada_item_id', '!=', '');
        } elseif ($syncStatus === 'not_uploaded') {
            $query->where(function ($sub) {
                $sub->whereNull('lp.lazada_item_id')->orWhere('lp.lazada_item_id', '=', '');
            })->whereNull('lp.lazada_deleted_at');
        } elseif ($syncStatus === 'deleted') {
            $query->whereNotNull('lp.lazada_deleted_at');
        } elseif ($syncStatus === 'error') {
            /* The reason to open this page: what failed, filterable. */
            $query->where('lp.last_sync_ok', false)->whereNotNull('lp.last_sync_ok');
        } elseif ($syncStatus === 'ready' || $syncStatus === 'not_ready') {
            /* The computed answer (#101): which unlisted products could be
             * pushed RIGHT NOW - by the same LazadaListingReadiness every
             * other surface reads, rows and rowless products alike. */
            $readiness = app(\Extensions\lazada\Services\Lazada\LazadaListingReadiness::class);
            $candidateRows = LazadaProduct::query()
                ->where(function ($sub) {
                    $sub->whereNull('lazada_item_id')->orWhere('lazada_item_id', '=', '');
                })
                ->whereNull('lazada_deleted_at')->whereNotNull('product_id')->get();
            $rowAnswers = $readiness->forListings($candidateRows);
            $readyByProduct = [];
            foreach ($candidateRows as $row) {
                $readyByProduct[(int) $row->product_id] = (bool) ($rowAnswers[(int) $row->id]['ready'] ?? false);
            }
            $mappedIds = LazadaProduct::query()->whereNotNull('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->all();
            $rowlessIds = DB::table($pfx . 'product')
                ->when(!empty($mappedIds), fn ($qq) => $qq->whereNotIn('product_id', $mappedIds))
                ->pluck('product_id')->map(fn ($v) => (int) $v)->all();
            foreach ($readiness->forProducts($rowlessIds) as $pid => $answer) {
                $readyByProduct[$pid] = (bool) $answer['ready'];
            }
            $wantReady = $syncStatus === 'ready';
            $matching = array_keys(array_filter($readyByProduct, fn ($r) => $r === $wantReady));
            $query->whereIn('p.product_id', $matching ?: [0]);
        }

        // The catalogue's own total, before a Lazada tab narrows the table.
        $catalogueTotal = (clone $query)->count();

        // A live tab narrows the table to the items Lazada holds in that
        // status right now. Only uploaded rows can match.
        if ($lazadaTab !== 'all' && $liveCounts !== null) {
            $query->where('lp.live_status', self::TAB_STATUS[$lazadaTab])
                ->whereNotNull('lp.lazada_item_id')->where('lp.lazada_item_id', '!=', '');
        }

        // Unlinked leftovers stay pinned to the top, as they always were:
        // a cut link is unfinished business, not one row among two thousand.
        $query->orderByRaw('(lp.unlinked_at IS NOT NULL) desc');
        if (isset($sortExpressions[$sort])) {
            $query->orderByRaw($sortExpressions[$sort] . ' ' . $dir);
        } else {
            $query->orderBy($sortColumns[$sort], $dir);
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

        // Thumbnails and variation rows, page-sized
        $productThumbsById = collect();
        $optionRowsByProductId = collect();
        if (!empty($pageIds)) {
            $productThumbsById = $products->mapWithKeys(function ($r) {
                return [(int) $r->product_id => $this->images()->toDisplayImageUrl($r->image ?? null)];
            });
            $optionRowsByProductId = \App\Support\VariationRows::forProducts($pageIds);
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
                        break; // Lazada is not answering; leave the rest blank rather than guessed
                    }
                    LazadaProduct::query()->where('id', $l->id)
                        ->update(['live_status' => $status, 'live_checked_at' => now()]);
                    $l->live_status = $status;
                    $filled = true;
                }
                if ($filled) {
                    $liveCounts = LazadaProduct::mirrorCounts(self::TAB_STATUS);
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

        // How many catalogue products actually stand on Lazada, for the
        // page's subtitle - an honest count, not the mapping-row count.
        $listedTotal = LazadaProduct::query()
            ->whereNotNull('lazada_item_id')->where('lazada_item_id', '!=', '')
            ->whereNull('unlinked_at')->whereNotNull('product_id')
            ->distinct()->count('product_id');

        /* Orphan listings (Phase C, #184): rows whose catalog product no
         * longer exists. The catalogue-first table cannot carry them -
         * there is no product row to hang them on - and hiding them would
         * hide money possibly still sitting on Lazada, so they surface as
         * their own short queue above the table, like unmatched items. */
        $orphanListings = LazadaProduct::query()
            ->where(function ($sub) use ($pfx) {
                $sub->whereNull('product_id')
                    ->orWhereNotExists(function ($qq) use ($pfx) {
                        $qq->select(DB::raw(1))
                            ->from($pfx . 'product as op')
                            ->whereColumn('op.product_id', 'lazada_products.product_id');
                    });
            })
            ->orderByDesc('id')
            ->get();
        $orphanStates = app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class)
            ->forProducts($orphanListings->pluck('product_id')->filter()->map(fn ($v) => (int) $v)->all());


        $allManufacturers = DB::table($pfx . 'manufacturer')
            ->orderBy('name')
            ->pluck('name', 'manufacturer_id');

        $allGroups = \Extensions\lazada\Models\LazadaProductGroup::query()->orderBy('name')->pluck('name', 'id');

        return view('ext-lazada::products.index', [
            'products' => $products,
            'listingsById' => $listingsById,
            'listingStates' => $listingStates,
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
            'orphanListings' => $orphanListings,
            'orphanStates' => $orphanStates,
            'lazadaTab' => $lazadaTab,
            'liveCounts' => $liveCounts,
            'liveTabError' => $liveTabError,
            'liveCheckedAt' => $liveCheckedAt,
            'catalogueTotal' => $catalogueTotal,
            'listedTotal' => $listedTotal,
        ]);
    }

    /** The Refresh button on the listings strip: the same walk the 30-minute automation runs. */
    public function refreshStatus(Request $request, \Extensions\lazada\Services\Lazada\LazadaLiveListing $live)
    {
        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$creds['complete']) {
            return redirect()->back()->with('error', 'Missing Lazada settings.');
        }

        $result = $live->refreshMirror($setting, $creds);
        if ($result['error'] !== null) {
            return redirect()->back()->with('error', 'Lazada did not answer: ' . $result['error'] . ' The statuses shown are from the last refresh.');
        }

        // One word per state, the engine's (#184 C): 'rejected', not 'in violation'.
        $labels = ['active' => 'active', 'inactive' => 'inactive', 'pending' => 'pending QC', 'rejected' => 'rejected',
            'sold-out' => 'sold out', 'soldout' => 'sold out', 'deleted' => 'deleted', 'missing' => 'not found on Lazada'];
        $parts = [];
        foreach ($result['counts'] as $status => $n) {
            $parts[] = number_format($n) . ' ' . ($labels[$status] ?? $status);
        }

        return redirect()->back()->with('status', 'Refreshed from Lazada: ' . ($parts ? implode(', ', $parts) : 'no uploaded listings') . '.');
    }

    /**
     * Sync ERP product quantity to Lazada using Seller SKU.
     * NOTE: Your app may not have permission for this API yet — we still call it so you can see the exact error.
     */
    public function syncQuantity(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $setting = LazadaSetting::query()->first()?->decrypted();
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
    public function syncPrice(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $setting = LazadaSetting::query()->first()?->decrypted();
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
    public function deleteFromLazada(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $setting = LazadaSetting::query()->first()?->decrypted();
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

    public function unlink(int $id)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

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

        $label = $itemId ? "Lazada item {$itemId}" : "listing #{$id}";

        return redirect()->back()->with('status', "Unlinked ERP product #{$productId} from {$label}.");
    }

    // ── Remove unlinked listing from local list ─────────────────────

    public function removeFromList(int $id)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        if (!$listing->unlinked_at) {
            return redirect()->back()->with('error', 'Cannot remove a linked product. Unlink it first.');
        }

        $itemId = $listing->lazada_item_id;

        // Delete variant mappings and the listing record locally — does NOT touch Lazada
        $listing->variants()->delete();
        $listing->groups()->detach();
        $listing->delete();

        $label = $itemId ? "Lazada item {$itemId}" : "listing #{$id}";

        return redirect()->back()->with('status', "Removed {$label} from the local list. The Lazada listing was not affected.");
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
    public function syncLazadaId(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        if (!empty($listing->lazada_item_id) && !$listing->unlinked_at) {
            // Already linked and not unlinked — full cache refresh
            $setting = LazadaSetting::query()->first()?->decrypted();
            $creds = LazadaSetting::activeCredentials($setting);
            if ($setting) {
                $this->itemCache()->refreshListing($listing, $setting, $client);
            }
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Sync Lazada ID: cache refreshed.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
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

            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Sync Lazada ID: linked successfully.');
        }

        $this->persistProductSyncStatus($listing, 'sync_lazada_id', ['ok' => false, 'body' => ['code' => 'NOT_FOUND', 'message' => 'No Lazada product found for SKUs: ' . implode(', ', $allSkus)]]);
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with('status', 'Sync Lazada ID: no Lazada product found for SKUs: ' . implode(', ', $allSkus));
    }

    public function uploadToLazada(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
        }


        // Determine create vs update
        $isUpdate = !empty($listing->lazada_item_id) && empty($listing->lazada_deleted_at);

        if (empty($listing->primary_category_id)) {
            $this->persistProductSyncStatus($listing, 'upload_to_lazada', ['ok' => false, 'body' => ['code' => 'MISSING_PRIMARY_CATEGORY', 'message' => 'Primary category is required before upload.']]);
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Upload: Primary Category is required before uploading.');
        }

        // Validate that the ERP product has a SKU (or model as fallback).
        $pfx = (string) config('catalog.prefix');
        $erpProduct = DB::table($pfx . 'product')->where('product_id', (int) $listing->product_id)->first(['sku', 'model', 'status']);

        if ((int) ($erpProduct->status ?? 1) === 0) {
            $this->persistProductSyncStatus($listing, 'upload_to_lazada', ['ok' => false, 'body' => ['code' => 'PRODUCT_DISABLED', 'message' => 'ERP product is disabled. Enable it before uploading.']]);
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Upload: ERP product is disabled. Enable it before uploading to Lazada.');
        }

        $erpSku = trim((string) ($erpProduct->sku ?? ''));
        if ($erpSku === '') {
            $erpSku = trim((string) ($erpProduct->model ?? ''));
        }
        if ($erpSku === '') {
            $this->persistProductSyncStatus($listing, 'upload_to_lazada', ['ok' => false, 'body' => ['code' => 'MISSING_SKU', 'message' => 'Product SKU is required. Please set a SKU (or Model) in the ERP product first.']]);
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Upload: Product SKU is required. Please set a SKU (or Model) in the core product first.');
        }

        // Build payload from listing mapping. If mapping is incomplete, we do not send an invalid payload.
        try {
            [$productPayload, $preview] = $this->payload()->buildLazadaProductCreatePayload($listing, $setting, $client);
            $productPayload = $this->images()->ensureLazadaInlinkImages($productPayload, $setting, $client);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Listing mapping is incomplete.';
            $this->persistProductSyncStatus($listing, 'upload_to_lazada', ['ok' => false, 'body' => ['code' => 'VALIDATION_ERROR', 'message' => (string)$msg]]);
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Upload: ' . $msg);
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

        $this->persistProductSyncStatus($listing, 'upload_to_lazada', $result, 'listing');

        // If successful, store lazada_item_id (create) or refresh variants (update).
        $e = $this->payload()->extractLazadaError($result);
        if (!empty($e['ok'])) {
            if ($isUpdate) {
                // Refresh variant cache after update
                $this->itemCache()->refreshListing($listing, $setting, $client);
                return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                    ->with('status', 'Product updated on Lazada successfully.');
            }

            $itemId = $this->payload()->extractCreatedItemId($result);
            try {
                if ($itemId) {
                    $listing->lazada_item_id = $itemId;
                }
                $listing->lazada_deleted_at = null;
                $listing->unlinked_at = null;
                // A fresh link voids the mirror: whatever status the row wore
                // belonged to the previous link. Read back below, never guessed.
                $listing->live_status = null;
                $listing->live_checked_at = null;
                $listing->save();
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

        return redirect()->to(url()->previous(route('ext.lazada.products.index')))
            ->with('status', $this->payload()->formatLazadaResultMessage('Upload', $result));
    }

    /**
     * The seller center's Active switch (Lazada). Deactivate is the
     * documented /product/deactivate (ItemId mandatory). Lazada publishes
     * NO activate endpoint - reactivation goes through /product/update
     * with SKU-level Status 'active', which is how its own payload schema
     * expresses it; whatever the marketplace answers is reported verbatim.
     */
    /**
     * Push (or end) the listing's discount on Lazada (#146). The rule is
     * a percent off with a window; each SKU's SalePrice is computed HERE
     * from the same final price a price sync would send, so a catalog
     * price change moves the discount with it. Ending sends SalePrice 0,
     * which clears the special price on Lazada's side.
     */
    public function pushDiscount(Request $request, int $id, LazadaClient $client)
    {
        $request->validate(['action' => 'required|in:push,end']);
        $ending = $request->input('action') === 'end';

        $listing = LazadaProduct::query()->findOrFail($id);
        if (empty($listing->lazada_item_id)) {
            return redirect()->back()->with('error', 'This listing is not on Lazada, so there is no price to discount.');
        }
        if (!$ending) {
            if ($listing->sale_percent === null || !$listing->sale_starts_at || !$listing->sale_ends_at) {
                return redirect()->back()->with('error', 'Set the discount first: a percent off, a start and an end. Save, then push it.');
            }
            if ($listing->sale_ends_at->isPast()) {
                return redirect()->back()->with('error', 'This discount\'s window has already ended. Set new dates and save first.');
            }
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$creds['complete']) {
            return redirect()->back()->with('error', 'Missing Lazada settings.');
        }

        $pfx = (string) config('catalog.prefix');
        $product = DB::table($pfx.'product as p')
            ->where('p.product_id', (int) $listing->product_id)
            ->first(['p.product_id', 'p.sku', 'p.price']);
        if (!$product) {
            return redirect()->back()->with('error', 'The catalog product behind this listing is gone.');
        }
        $basePrice = max(0.0, (float) ($product->price ?? 0));

        $fixedMarkup = $listing->markup_fixed;
        $percentMarkup = $listing->markup_percent;
        if ($fixedMarkup === null && $percentMarkup === null) {
            $mkGroup = $listing->groups()->first();
            if ($mkGroup) {
                $fixedMarkup = $mkGroup->markup_fixed;
                $percentMarkup = $mkGroup->markup_percent;
            }
        }

        $skuIdMap = $this->itemCache()->resolveSkuIds($listing, $setting, $client);
        if (empty($skuIdMap)) {
            return redirect()->back()->with('error', 'Could not resolve this listing\'s Lazada SKU ids. Open the listing to refresh its variants, then try again.');
        }

        $variantPrices = $this->payload()->getErpVariantPricesByProductId((int) $listing->product_id, $basePrice);
        if (empty($variantPrices)) {
            $sku = trim((string) ($product->sku ?? ''));
            if ($sku === '') {
                return redirect()->back()->with('error', 'The catalog product has no SKU.');
            }
            $variantPrices = [['seller_sku' => $sku, 'price' => $basePrice]];
        }

        $saleStart = $listing->sale_starts_at?->format('Y-m-d');
        $saleEnd = $listing->sale_ends_at?->format('Y-m-d');
        $pct = (float) ($listing->sale_percent ?? 0);

        $apiPath = '/product/price_quantity/update';
        $ok = 0;
        $err = 0;
        $lastMessage = null;

        foreach ($variantPrices as $v) {
            $sku = trim((string) ($v['seller_sku'] ?? ''));
            $skuId = $sku !== '' ? ($skuIdMap[$sku] ?? null) : null;
            if ($skuId === null) {
                $err++;
                continue;
            }

            $price = LazadaPushPayload::computeFinalPrice((float) ($v['price'] ?? 0), $fixedMarkup, $percentMarkup);
            $priceStr = number_format(max(0, $price), 2, '.', '');
            $saleStr = $ending
                ? '0'
                : number_format(max(0.01, round($price * (1 - $pct / 100), 2)), 2, '.', '');

            $payloadXml = $this->payload()->buildPriceUpdateXml(
                (int) $skuId,
                $priceStr,
                $saleStr,
                $ending ? null : $saleStart,
                $ending ? null : $saleEnd
            );

            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
                'payload' => $payloadXml,
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

            try {
                $result = $client->post((string) $setting->region, $apiPath, $params);
            } catch (\Throwable $e) {
                $err++;
                $lastMessage = \App\Support\TransportError::plain($e, 'Lazada');
                continue;
            }

            LazadaApiLog::safeCreate([
                'pack' => 'lazada.listings.discount', 'method' => 'POST',
                'api_path' => $apiPath, 'auth_required' => true,
                'request_params' => $params,
                'response_status' => (int) ($result['status'] ?? 0),
                'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
            ]);

            $e = $this->payload()->extractLazadaError($result);
            if ($e['ok']) {
                $ok++;
            } else {
                $err++;
                $lastMessage = $e['message'] ?? 'Unknown error';
            }
        }

        if ($ending && $err === 0) {
            $listing->forceFill(['sale_percent' => null, 'sale_starts_at' => null, 'sale_ends_at' => null, 'sale_pushed_at' => null])->save();
        } elseif (!$ending && $err === 0) {
            // Lazada reports no promotion id back; the stamp is how the
            // record knows its rule reached the marketplace at all.
            $listing->forceFill(['sale_pushed_at' => now()])->save();
        }

        if ($err > 0) {
            return redirect()->back()->with('error',
                ($ending ? 'End discount' : 'Discount push') . ": {$ok} SKU(s) ok, {$err} failed"
                . ($lastMessage ? ' - ' . $lastMessage : '') . '.');
        }

        return redirect()->back()->with('status', $ending
            ? 'Discount ended. Lazada sells at the full price again.'
            : "Discount pushed: {$ok} SKU(s) at {$pct}% off from {$saleStart} to {$saleEnd}.");
    }

    public function toggleListing(Request $request, int $id, LazadaClient $client)
    {
        $request->validate(['action' => 'required|in:activate,deactivate']);
        $deactivate = $request->input('action') === 'deactivate';
        $listing = LazadaProduct::query()->findOrFail($id);
        if (empty($listing->lazada_item_id)) {
            return redirect()->back()->with('error', 'This listing is not on Lazada, so there is nothing to ' . ($deactivate ? 'deactivate' : 'activate') . '.');
        }
        $setting = LazadaSetting::query()->first()?->decrypted();
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
            'listing_ids' => 'required|array|min:1',
            'listing_ids.*' => 'integer',
        ]);
        $deactivate = $request->input('action') === 'deactivate';
        $verb = $deactivate ? 'Deactivated' : 'Activated';

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$creds['complete']) {
            return redirect()->back()->with('error', 'Missing Lazada settings.');
        }

        $ids = array_values(array_unique(array_map('intval', (array) $request->input('listing_ids'))));
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

    public function bulkUploadToLazada(Request $request, LazadaClient $client)
    {
        $ids = $request->input('listing_ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map(fn($v) => (int)$v, $ids), fn($v) => $v > 0)));

        if (empty($ids)) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Bulk Upload: no products selected.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
        }

        $listings = LazadaProduct::query()->whereIn('id', $ids)->get();

        /* REFUSE TO START rather than half-fail (#101, Lazada half): if any
         * selected listing is not ready, nothing is uploaded and the
         * unready are named with what they need. Already-uploaded rows are
         * exempt - re-uploads update in place. */
        $toCheck = $listings->filter(fn ($l) => empty($l->lazada_item_id));
        $readiness = app(\Extensions\lazada\Services\Lazada\LazadaListingReadiness::class)
            ->forListings($toCheck);
        $unready = array_filter($readiness, fn ($r) => !$r['ready']);
        if (!empty($unready)) {
            $lines = [];
            foreach (array_slice($unready, 0, 5, true) as $lid => $r) {
                $pid = (int) ($listings->firstWhere('id', $lid)?->product_id ?? 0);
                $lines[] = "#{$pid} needs " . implode(', ', $r['missing']);
            }
            $summary = 'Nothing was uploaded. ' . count($unready) . ' of ' . count($ids)
                . ' selected ' . (count($unready) === 1 ? 'is' : 'are') . ' not ready: '
                . implode('; ', $lines)
                . (count($unready) > 5 ? '; and ' . (count($unready) - 5) . ' more' : '')
                . '. Untick them, or finish their listings first.';

            return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('error', $summary);
        }

        $okCount = 0;
        $updatedCount = 0;
        $errCount = 0;

        $pfx = (string) config('catalog.prefix');

        foreach ($listings as $listing) {
            if (empty($listing->primary_category_id)) {
                $this->persistProductSyncStatus($listing, 'bulk_upload_to_lazada', ['ok' => false, 'body' => ['code' => 'MISSING_PRIMARY_CATEGORY', 'message' => 'Primary category is required before upload.']]);
                $errCount++;
                continue;
            }

            // Validate SKU
            $erpProduct = DB::table($pfx . 'product')->where('product_id', (int) $listing->product_id)->first(['sku', 'model', 'status']);

            if ((int) ($erpProduct->status ?? 1) === 0) {
                $this->persistProductSyncStatus($listing, 'bulk_upload_to_lazada', ['ok' => false, 'body' => ['code' => 'PRODUCT_DISABLED', 'message' => 'ERP product is disabled (status=0).']]);
                $errCount++;
                continue;
            }

            $erpSku = trim((string) ($erpProduct->sku ?? ''));
            if ($erpSku === '') {
                $erpSku = trim((string) ($erpProduct->model ?? ''));
            }
            if ($erpSku === '') {
                $this->persistProductSyncStatus($listing, 'bulk_upload_to_lazada', ['ok' => false, 'body' => ['code' => 'MISSING_SKU', 'message' => 'Product SKU is required.']]);
                $errCount++;
                continue;
            }

            try {
                [$productPayload, $preview] = $this->payload()->buildLazadaProductCreatePayload($listing, $setting, $client);
                $productPayload = $this->images()->ensureLazadaInlinkImages($productPayload, $setting, $client);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $msg = collect($e->errors())->flatten()->first() ?: 'Listing mapping is incomplete.';
                $this->persistProductSyncStatus($listing, 'bulk_upload_to_lazada', ['ok' => false, 'body' => ['code' => 'VALIDATION_ERROR', 'message' => (string)$msg]]);
                $errCount++;
                continue;
            }

            /* SEND SEMANTICS (parity #156, item 4): a listing already on
             * Lazada is updated in place - ItemId and each cached SkuId
             * injected so Lazada edits rather than duplicates - instead of
             * being created again. */
            $isUpdate = !empty($listing->lazada_item_id) && empty($listing->lazada_deleted_at);
            if ($isUpdate) {
                $productPayload['Request']['Product']['ItemId'] = (int) $listing->lazada_item_id;
                $variantMap = LazadaProductVariant::where('lazada_product_id', $listing->id)
                    ->whereNotNull('sku_id')->pluck('sku_id', 'seller_sku')->toArray();
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

            $this->persistProductSyncStatus($listing, $isUpdate ? 'bulk_update_on_lazada' : 'bulk_upload_to_lazada', $result, 'listing');

            $e = $this->payload()->extractLazadaError($result);
            if (!empty($e['ok'])) {
                $isUpdate ? $updatedCount++ : $okCount++;
                $itemId = $isUpdate ? null : $this->payload()->extractCreatedItemId($result);
                try {
                    if ($itemId) {
                        $listing->lazada_item_id = $itemId;
                        // A fresh link voids the mirror; the read-back below
                        // fills it with Lazada's answer, never a guess.
                        $listing->live_status = null;
                        $listing->live_checked_at = null;
                    }
                    $listing->lazada_deleted_at = null;
                    $listing->save();
                    // Write-through (#184 C): the mirror learns what this push
                    // did without waiting for the 30-minute sweep.
                    $this->itemCache()->refreshListing($listing, $setting, $client);
                } catch (\Throwable $ex) {
                    // ignore
                }
            } else {
                $errCount++;
            }

            LazadaApiLog::safeCreate([
                'pack' => $isUpdate ? 'lazada.product.update.bulk' : 'lazada.product.create.bulk',
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
            ->with($errCount > 0 && ($okCount + $updatedCount) === 0 ? 'error' : 'status',
                "Send: {$okCount} created, {$updatedCount} already listed and updated in place" . ($errCount > 0 ? ", {$errCount} failed" : '') . '.');
    }

    public function bulkSyncLazadaId(Request $request, LazadaClient $client)
    {
        $ids = $request->input('listing_ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map(fn($v) => (int)$v, $ids), fn($v) => $v > 0)));

        if (empty($ids)) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Bulk Sync Lazada ID: no products selected.');
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
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
     * shared LazadaLinkCheck. The bulk bar posts listing_ids[]; with
     * none it checks every linked listing, 200 at a time. No group here
     * means adoption creates a bare listing for the workbench to dress.
     */
    public function checkAgainstLazada(Request $request, \Extensions\lazada\Services\Lazada\LazadaLinkCheck $check)
    {
        try { @set_time_limit(0); } catch (\Throwable $e) {}

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['complete']) {
            return redirect()->to(url()->previous(route('ext.lazada.products.index')))
                ->with('error', 'Missing Lazada settings (region/app key/app secret/access token).');
        }

        $listingIds = array_values(array_unique(array_filter(array_map(fn ($v) => (int) $v, (array) $request->input('listing_ids', [])), fn ($v) => $v > 0)));
        $productIds = $listingIds !== []
            ? LazadaProduct::query()->whereIn('id', $listingIds)->pluck('product_id')->map(fn ($v) => (int) $v)->all()
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
    $ids = $request->input('listing_ids', []);
    if (!is_array($ids)) {
        $ids = [];
    }
    $ids = array_values(array_unique(array_filter(array_map(fn($v) => (int)$v, $ids), fn($v) => $v > 0)));

    if (empty($ids)) {
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Bulk Sync Qty: no listings selected.');
    }

    $setting = LazadaSetting::query()->first()?->decrypted();
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
    $ids = $request->input('listing_ids', []);
    if (!is_array($ids)) {
        $ids = [];
    }
    $ids = array_values(array_unique(array_filter(array_map(fn($v) => (int)$v, $ids), fn($v) => $v > 0)));

    if (empty($ids)) {
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Bulk Sync Price: no listings selected.');
    }

    $setting = LazadaSetting::query()->first()?->decrypted();
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
    $ids = $request->input('listing_ids', []);
    if (!is_array($ids)) {
        $ids = [];
    }
    $ids = array_values(array_unique(array_filter(array_map(fn($v) => (int)$v, $ids), fn($v) => $v > 0)));

    if (empty($ids)) {
        return redirect()->to(url()->previous(route('ext.lazada.products.index')))->with('status', 'Bulk Delete: no listings selected.');
    }

    $setting = LazadaSetting::query()->first()?->decrypted();
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

    public function create(Request $request)
    {
        // Cached Lazada categories for dropdown selection
        $categories = LazadaCategory::query()
            ->orderBy('name')
            ->limit(5000)
            ->get(['category_id', 'name']);

        // Brand search is handled by AJAX autocomplete; no need to preload all brands.
        // Product selection is handled by AJAX search (searchCatalogProducts endpoint).

        $blank = new LazadaProduct([
            'product_id' => $request->input('product_id'),
        ]);

        return view('ext-lazada::products.form', [
            'mode' => 'create',
            'listing' => $blank,
            'categories' => $categories,
            'template' => null,
            'attributes' => [],
            'saved' => [],
            'suggested' => [],
            'groupAttrs' => [],
            'productOwnAttrs' => [],
            'productImageUrl' => null,
            'brands' => collect(),
            'brandSuggestion' => ['brand_id' => null, 'brand_name' => null],
            'selectedBrandName' => '',
            'erpSourceFields' => LazadaAttributes::ERP_SOURCE_FIELDS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|min:1',
            'primary_category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'no_brand' => 'nullable|boolean',
            'markup_fixed' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0',
            'sale_percent' => 'nullable|numeric|min:1|max:99',
            'sale_starts_at' => 'nullable|date|required_with:sale_percent',
            'sale_ends_at' => 'nullable|date|required_with:sale_percent|after:sale_starts_at',
        ]);

        $noBrand = (bool)($data['no_brand'] ?? false);
        if ($noBrand) {
            $data['brand_id'] = null;
        }

        $listing = LazadaProduct::query()->create([
            'product_id' => (int)$data['product_id'],
            'primary_category_id' => $data['primary_category_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'brand_name_override' => $noBrand ? 'No Brand' : null,
            'markup_fixed' => $data['markup_fixed'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null,
        ]);

        ActivityLogger::log('created', 'Lazada Product', $listing->id, 'ERP #' . (int)$data['product_id']);

        return redirect()->route('ext.lazada.products.edit', $listing->id)
            ->with('status', 'Listing settings saved.');
    }


    public function edit(int $id, \Extensions\lazada\Services\Lazada\LazadaLiveListing $liveListing)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        /* The live half (Lazada parity with the Shopee listing page,
         * 2026-08-24): what Lazada says about this item RIGHT NOW, fetched
         * on this page load. Only an uploaded listing has a live half. */
        $live = null;
        $liveError = null;
        if (!empty($listing->lazada_item_id)) {
            $liveSetting = LazadaSetting::query()->first()?->decrypted();
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
        if (!empty($listing->brand_id)) {
            $b = LazadaBrand::query()
                ->where('region', $this->region())
                ->where('brand_id', (int)$listing->brand_id)
                ->first();
            $selectedBrandName = $b ? (string)$b->name : '';
        }
        if ($listing->primary_category_id) {
            $template = LazadaCategoryTemplate::query()
                ->where('region', $this->region())
                ->where('primary_category_id', (int)$listing->primary_category_id)
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

        $productOwnSaved = LazadaProductAttribute::query()
            ->where('lazada_product_id', $listing->id)
            ->pluck('value', 'attribute_key')
            ->toArray();

        // Auto-populate from linked product group: group values serve as defaults
        $groupAttrs = [];
        $firstGroup = $listing->groups()->first();
        if ($firstGroup) {
            $groupAttrs = LazadaProductGroupAttribute::query()
                ->where('lazada_product_group_id', $firstGroup->id)
                ->pluck('value', 'attribute_key')
                ->toArray();
        }

        // Merge: product's own values take priority over group defaults
        $saved = array_merge($groupAttrs, $productOwnSaved);


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

        // Base product info for payload preview (includes extra fields for mapping)
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

        // Resolve __map: values to actual product field values for display
        $suggested = $this->attrs()->suggestForAttributes($attributes, $productRow, $saved);

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
        $covSkus = \App\Integrations\Push\PushLedger::erpVariationSkus([(int) $listing->product_id], $pfx)[(int) $listing->product_id] ?? [];
        $covVariantSkus = $listing->variants->pluck('seller_sku')->map(fn ($v) => strtolower(trim((string) $v)))->flip();
        $coverage = [
            'total' => count($covSkus),
            'linked' => count(array_filter($covSkus, fn ($sku) => $covVariantSkus->has(strtolower($sku)))),
            'missing' => array_values(array_filter($covSkus, fn ($sku) => !$covVariantSkus->has(strtolower($sku)))),
        ];

        return view('ext-lazada::products.form', [
            'coverage' => $coverage,
            'mode' => 'edit',
            'listing' => $listing,
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
            'payloadPreview' => session('payload_preview_json'),
            'pushRequest' => session('push_request_json'),
            'pushResponse' => session('push_response_json'),
            'live' => $live,
            'liveError' => $liveError,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $data = $request->validate([
            'primary_category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'no_brand' => 'nullable|boolean',
            'markup_fixed' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0',
            'sale_percent' => 'nullable|numeric|min:1|max:99',
            'sale_starts_at' => 'nullable|date|required_with:sale_percent',
            'sale_ends_at' => 'nullable|date|required_with:sale_percent|after:sale_starts_at',
        ]);

        $noBrand = (bool)($data['no_brand'] ?? false);
        if ($noBrand) {
            $data['brand_id'] = null;
        }

        $listing->primary_category_id = $data['primary_category_id'] ?? null;
        $listing->brand_id = $data['brand_id'] ?? null;
        $listing->brand_name_override = $noBrand ? 'No Brand' : null;
        $listing->markup_fixed = $data['markup_fixed'] ?? null;
        $listing->markup_percent = $data['markup_percent'] ?? null;

        /* The discount RULE (#146): percent off with a window; the sale
         * price itself is never stored - it is computed from the final
         * price at push time. Clearing the percent clears the rule. */
        $listing->sale_percent = ($data['sale_percent'] ?? null) !== null ? (float) $data['sale_percent'] : null;
        $listing->sale_starts_at = $listing->sale_percent !== null ? ($data['sale_starts_at'] ?? null) : null;
        $listing->sale_ends_at = $listing->sale_percent !== null ? ($data['sale_ends_at'] ?? null) : null;

        // Compute total_price from ERP base price + effective markup
        $pfx = (string) config('catalog.prefix');
        $erpPrice = (float) DB::table($pfx . 'product')->where('product_id', (int)$listing->product_id)->value('price');

        $fixedMarkup = $listing->markup_fixed;
        $percentMarkup = $listing->markup_percent;

        // Fall back to group markup if product has none
        if ($fixedMarkup === null && $percentMarkup === null) {
            $mkGroup = $listing->groups()->first();
            if ($mkGroup) {
                $fixedMarkup = $mkGroup->markup_fixed;
                $percentMarkup = $mkGroup->markup_percent;
            }
        }

        $listing->total_price = LazadaPushPayload::computeFinalPrice($erpPrice, $fixedMarkup, $percentMarkup);
        $listing->save();

        ActivityLogger::log('updated', 'Lazada Product', $listing->id, 'ERP #' . (int)$listing->product_id);

        return redirect()->route('ext.lazada.products.edit', $listing->id)->with('status', 'Listing updated.');
    }

    public function syncBrands(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

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

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret']) {
            return redirect()->route('ext.lazada.products.edit', $listing->id)
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

        return redirect()->route('ext.lazada.products.edit', $listing->id)
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

        $setting = LazadaSetting::query()->first()?->decrypted();
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

    public function syncTemplate(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $request->validate([
            'primary_category_id' => 'required|integer|min:1',
        ]);

        $primaryCategoryId = (int)$request->input('primary_category_id');
        $listing->primary_category_id = $primaryCategoryId;
        $listing->save();

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret']) {
            return redirect()->route('ext.lazada.products.edit', $listing->id)->with('status', 'Missing Lazada settings.');
        }

        $apiPath = '/category/attributes/get';
        $timestamp = (string)round(microtime(true) * 1000);
        $params = [
            'app_key' => (string)$creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'primary_category_id' => (string)$primaryCategoryId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string)$creds['app_secret']);
        $result = $client->get((string)$setting->region, $apiPath, $params);

        LazadaCategoryTemplate::query()->updateOrCreate(
            [
                'region' => (string)$setting->region,
                'primary_category_id' => $primaryCategoryId,
            ],
            [
                'template_body' => $result['body'] ?? null,
                'fetched_at' => now(),
            ]
        );

        return redirect()->route('ext.lazada.products.edit', $listing->id)->with('status', 'Category template synced.');
    }

    public function saveAttributes(Request $request, int $id)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $data = $request->validate([
            'attributes' => 'array',
            'attributes.*' => 'nullable|string|max:2000',
        ]);

        $attrs = (array)($data['attributes'] ?? []);

        // Brand is handled by the top-level Brand selector (listing.brand_id); ignore any submitted "brand" attribute.
        if (array_key_exists('brand', $attrs)) {
            unset($attrs['brand']);
        }

        // Force mapped attributes to always use ERP values based on mappings.
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
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

        // Images are mandatory for Lazada product creation.
        $imageUrls = $this->images()->getProductImageUrls((int)$listing->product_id, $productRow);
        $imageUrls = array_values(array_filter(array_map(fn($u) => $this->images()->normalizeImageUrl((string)$u), $imageUrls)));
        if (empty($imageUrls)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'images' => 'At least 1 product image is required. Please upload a main image (and optional additional images) in the core ERP Product page first.'
            ]);
        }

        // Validate mandatory (required) attributes from the cached category template.
        $requiredKeys = [];
        $requiredNames = [];
        if ($listing->primary_category_id) {
            $template = LazadaCategoryTemplate::query()
                ->where('region', $this->region())
                ->where('primary_category_id', (int)$listing->primary_category_id)
                ->first();

            if ($template && $template->template_body) {
                $templateAttrs = $this->attrs()->extractAttributes($template->template_body);

                foreach ($templateAttrs as $a) {
                    if (!empty($a['required'])) {
                        $k = (string)($a['key'] ?? '');
                        if ($k !== '') {
                            $requiredKeys[] = $k;
                            $requiredNames[$k] = (string)($a['name'] ?? $k);
                        }
                    }
                }
            }
        }

        if (!empty($requiredKeys)) {
            // Build a merged map: existing saved values + incoming form values
            $existing = LazadaProductAttribute::query()
                ->where('lazada_product_id', $listing->id)
                ->pluck('value', 'attribute_key')
                ->toArray();

            $merged = $existing;
            foreach ($attrs as $k => $v) {
                $k = trim((string)$k);
                if ($k === '') continue;
                $merged[$k] = ($v === '' ? null : $v);
            }

            $errs = [];
            foreach ($requiredKeys as $k) {
                $keyLower = strtolower(trim((string)$k));
                if ($keyLower === 'brand') {
                    // Brand is handled by the top-level Brand selector (listing.brand_id).
                    continue;
                }
                if ($this->attrs()->isSkuLevelRequiredKey($k)) {
                    // Lazada marks some SKU-level fields as mandatory in templates (e.g. SellerSku, price).
                    // Those are handled in the Variant Mapping section / payload builder.
                    continue;
                }
                if (LazadaAttributes::isAutoFilledAttributeKey($k)) {
                    // Name, description and friends fill themselves from the
                    // catalogue at push time; blank here is not a problem.
                    continue;
                }
                $val = $merged[$k] ?? null;
                if ($val === null || trim((string)$val) === '') {
                    $label = $requiredNames[$k] ?? $k;
                    $errs['attributes.'.$k] = $label . ' is mandatory.';
                }
            }

            if (!empty($errs)) {
                return redirect()->route('ext.lazada.products.edit', $listing->id)
                    ->withErrors($errs)
                    ->withInput();
            }
        }

        $attrFk = 'lazada_product_id';
        DB::transaction(function () use ($listing, $attrs, $attrFk) {
            foreach ($attrs as $key => $value) {
                $key = trim((string)$key);
                if ($key === '') {
                    continue;
                }
                LazadaProductAttribute::query()->updateOrCreate(
                    [
                        $attrFk => $listing->id,
                        'attribute_key' => $key,
                    ],
                    [
                        'value' => $value === '' ? null : $value,
                    ]
                );
            }
        });

        return redirect()->route('ext.lazada.products.edit', $listing->id)->with('status', 'Attributes saved.');
    }

    public function saveVariants(Request $request, int $id)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $data = $request->validate([
            'variants' => 'array',
            'variants.*.seller_sku' => 'nullable|string|max:64',
            'variants.*.price' => 'nullable|numeric',
            'variants.*.quantity' => 'nullable|integer',
        ]);

        $rows = (array)($data['variants'] ?? []);

        $variantFk = 'lazada_product_id';
        DB::transaction(function () use ($listing, $rows, $variantFk) {
            foreach ($rows as $povId => $row) {
                $povId = (string)$povId;
                if ($povId === '' || !is_array($row)) {
                    continue;
                }
                $idVal = ctype_digit($povId) ? (int)$povId : null;
                LazadaProductVariant::query()->updateOrCreate(
                    [
                        $variantFk => $listing->id,
                        'product_option_value_id' => $idVal,
                    ],
                    [
                        'seller_sku' => isset($row['seller_sku']) && $row['seller_sku'] !== '' ? (string)$row['seller_sku'] : null,
                        'price' => isset($row['price']) && $row['price'] !== '' ? (float)$row['price'] : null,
                        'quantity' => isset($row['quantity']) && $row['quantity'] !== '' ? (int)$row['quantity'] : null,
                    ]
                );
            }
        });

        return redirect()->route('ext.lazada.products.edit', $listing->id)->with('status', 'Variant mapping saved.');
    }

    public function buildPayload(Request $request, int $id)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table($pfx.'product as p')
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

        // Images are mandatory for Lazada product creation.
        $imageUrls = $this->images()->getProductImageUrls((int)$listing->product_id, $product);
        $imageUrls = array_values(array_filter(array_map(fn($u) => $this->images()->normalizeImageUrl((string)$u), $imageUrls)));
        if (empty($imageUrls)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'images' => 'At least 1 product image is required. Please upload a main image (and optional additional images) in the core ERP Product page first.'
            ]);
        }

        $attrs = LazadaProductAttribute::query()
            ->where('lazada_product_id', $listing->id)
            ->pluck('value', 'attribute_key')
            ->toArray();

        // Ensure Brand exists in attributes before validating required template keys.
        $brandId = (int)($listing->brand_id ?? 0);
        $brandOverride = trim((string)($listing->brand_name_override ?? ''));
        $isNoBrand = $brandOverride !== '' && strtolower($brandOverride) === 'no brand';

        if ($isNoBrand) {
            $attrs['brand'] = 'No Brand';
            unset($attrs['brand_id']);
        } elseif ($brandId > 0) {
            $brandName = LazadaBrand::query()
                ->where('region', $this->region())
                ->where('brand_id', $brandId)
                ->value('name');
            $attrs['brand'] = $brandName ? (string)$brandName : (string)$brandId;
            $attrs['brand_id'] = $brandId;
        } else {
            $attrs['brand'] = 'No Brand';
            unset($attrs['brand_id']);
        }

        // Remove present-but-null values for cleaner preview.
        $attrs = (array) $this->payload()->compactPayloadValue($attrs);

        // Resolve __map: attribute values to actual product field values for payload.
        $attrs = $this->attrs()->resolveMapAttributes($attrs, $product);
        $attrs = $this->attrs()->autoFillBasics($attrs, $product);

        // Validate mandatory attributes before building payload
        $requiredKeys = [];
        $requiredNames = [];
        if ($listing->primary_category_id) {
            $template = LazadaCategoryTemplate::query()
                ->where('region', $this->region())
                ->where('primary_category_id', (int)$listing->primary_category_id)
                ->first();
            if ($template && $template->template_body) {
                $templateAttrs = $this->attrs()->extractAttributes($template->template_body);

                foreach ($templateAttrs as $a) {
                    if (!empty($a['required'])) {
                        $k = (string)($a['key'] ?? '');
                        if ($k !== '') {
                            $requiredKeys[] = $k;
                            $requiredNames[$k] = (string)($a['name'] ?? $k);
                        }
                    }
                }
            }
        }
        if (!empty($requiredKeys)) {
            $errs = [];
            foreach ($requiredKeys as $k) {
                if ($this->attrs()->isSkuLevelRequiredKey($k)) {
                    continue;
                }
                $val = $attrs[$k] ?? null;
                if ($val === null || trim((string)$val) === '') {
                    $label = $requiredNames[$k] ?? $k;
                    $errs['attributes.'.$k] = $label . ' is mandatory.';
                }
            }
            if (!empty($errs)) {
                return redirect()->route('ext.lazada.products.edit', $listing->id)
                    ->withErrors($errs);
            }
        }

        $povRows = DB::table($pfx.'product_option_value')
            ->where('product_id', (int)$listing->product_id)
            ->orderBy('product_option_value_id')
            ->get(['product_option_value_id','sku','quantity','absolute_price']);

        $map = LazadaProductVariant::query()
            ->where('lazada_product_id', $listing->id)
            ->get()
            ->keyBy(function ($v) {
                return $v->product_option_value_id === null ? 'base' : (string)$v->product_option_value_id;
            });

        $skus = [];
        $basePrice = (float)($product->price ?? 0);
        $usedSellerSkus = [];

        // Effective markup for variant price computation
        $effectiveFixed = $listing->markup_fixed;
        $effectivePercent = $listing->markup_percent;
        if ($effectiveFixed === null && $effectivePercent === null) {
            $mkGroup = $listing->groups()->first();
            if ($mkGroup) {
                $effectiveFixed = $mkGroup->markup_fixed;
                $effectivePercent = $mkGroup->markup_percent;
            }
        }

        // SellerSku fallback: sku then model.
        $fallbackBaseSku = null;
        if ($product) {
            $fallbackBaseSku = trim((string)($product->sku ?? ''));
            if ($fallbackBaseSku === '') {
                $fallbackBaseSku = trim((string)($product->model ?? ''));
            }
            if ($fallbackBaseSku === '') {
                $fallbackBaseSku = null;
            }
        }
        if ($povRows->count() > 0) {
            foreach ($povRows as $r) {
                $key = (string)$r->product_option_value_id;
                $m = $map->get($key);

                // SellerSku mapping rule:
                // 1) Listing variant override
                // 2) ERP product.sku (single source of truth)
                // 3) ERP product.model
                $candidate = trim((string)($m && $m->seller_sku ? $m->seller_sku : ''));
                if ($candidate === '') {
                    $candidate = $fallbackBaseSku;
                }
                // Ensure uniqueness across variant rows
                if ($candidate !== null && $candidate !== '') {
                    if (isset($usedSellerSkus[$candidate])) {
                        $candidate = $candidate . '-' . (int)$r->product_option_value_id;
                    }
                    $usedSellerSkus[$candidate] = true;
                }

                $erpPrice = (float)($r->absolute_price ?? $basePrice);
                $finalPrice = $m && $m->price !== null ? (float)$m->price : $erpPrice;
                $finalPrice = LazadaPushPayload::computeFinalPrice($finalPrice, $effectiveFixed, $effectivePercent);
                $skus[] = [
                    'product_option_value_id' => (int)$r->product_option_value_id,
                    'seller_sku' => $candidate,
                    'price' => $finalPrice,
                    'quantity' => $m && $m->quantity !== null ? (int)$m->quantity : (int)$r->quantity,
                ];
            }
        } else {
            // No variants in ERP; show a single base SKU in preview.
            // Use stored total_price (base + markup) if available.
            $singlePrice = $listing->total_price ?? (float)($product->price ?? 0);
            $skus[] = [
                'product_option_value_id' => null,
                'seller_sku' => $fallbackBaseSku,
                'price' => (float)$singlePrice,
                'quantity' => (int)($product->quantity ?? 0),
            ];
        }

        // Validate SKU-level mandatory fields (SellerSku, price) if Lazada template requires them.
        if (!empty($requiredKeys)) {
            $skuErrs = [];
            $needsSellerSku = false;
            $needsPrice = false;
            foreach ($requiredKeys as $k) {
                $lk = strtolower((string)$k);
                if ($lk === 'sellersku' || $lk === 'seller_sku') $needsSellerSku = true;
                if ($lk === 'price') $needsPrice = true;
            }
            if ($needsSellerSku) {
                foreach ($skus as $i => $s) {
                    if (empty($s['seller_sku'])) {
                        $skuErrs['variants'] = 'SellerSku is mandatory. Please set SKU in your ERP variants or fill Variant Mapping overrides.';
                        break;
                    }
                }
            }
            if ($needsPrice) {
                foreach ($skus as $i => $s) {
                    if (!isset($s['price']) || $s['price'] === null || (float)$s['price'] <= 0) {
                        $skuErrs['variants'] = 'Price is mandatory. Please set product price in ERP (base price) and/or option price adjustments, or fill Variant Mapping overrides.';
                        break;
                    }
                }
            }
            if (!empty($skuErrs)) {
                return redirect()->route('ext.lazada.products.edit', $listing->id)
                    ->withErrors($skuErrs);
            }
        }

        // Brand resolution (preview only)
        $brandSuggestion = $this->attrs()->suggestBrandFromManufacturer($product?->manufacturer_name ?? null);
        $effectiveBrandId = $listing->brand_id ?: ($brandSuggestion['brand_id'] ?? null);
        $effectiveBrandName = $listing->brand_name_override;
        if ($effectiveBrandName === null || $effectiveBrandName === '') {
            // If we found a Lazada brand match, leave name null (ID is enough).
            // Otherwise, use manufacturer name as the text override.
            $effectiveBrandName = ($brandSuggestion['brand_id'] ?? null) ? null : (string)($product?->manufacturer_name ?? '');
        }

        $payload = [
            'region' => $this->region(),
            'product_id' => $listing->product_id,
            'primary_category_id' => $listing->primary_category_id,
            'brand_id' => $listing->brand_id,
            'brand_name_override' => $listing->brand_name_override,
            'effective_brand' => [
                'brand_id' => $effectiveBrandId,
                'brand_name' => $effectiveBrandName,
                'source' => $listing->brand_id ? 'listing.brand_id' : ($brandSuggestion['brand_id'] ? 'manufacturer->lazada_brand_match' : 'manufacturer_name'),
            ],
            'product' => $product ? [
                'sku' => $product->sku ?? null,
                'model' => $product->model ?? null,
                'name' => $product->name ?? null,
                'description' => $product->description ?? null,
                'manufacturer' => $product->manufacturer_name ?? null,
                'weight' => $product->weight ?? null,
                'dimensions' => [
                    'length' => $product->length ?? null,
                    'width' => $product->width ?? null,
                    'height' => $product->height ?? null,
                ],
            ] : null,
            'attributes' => $attrFinal ?? $attrs,
            'skus' => $skus,
        ];

        return redirect()->route('ext.lazada.products.edit', $listing->id)
            ->with('status', 'Payload built (preview only).')
            ->with('payload_preview_json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function pushSample(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaProduct::query()->findOrFail($id);

        $setting = LazadaSetting::query()->first()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->route('ext.lazada.products.edit', $listing->id)
                ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
        }

        if (empty($listing->primary_category_id)) {
            return redirect()->route('ext.lazada.products.edit', $listing->id)
                ->withErrors(['primary_category_id' => 'Primary Category is required before pushing.']);
        }

        // Build a Lazada-ready product payload (best-effort). We'll learn from QC errors and iterate.
        try {
            [$productPayload, $preview] = $this->payload()->buildLazadaProductCreatePayload($listing, $setting, $client);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->route('ext.lazada.products.edit', $listing->id)
                ->withErrors($e->errors())
                ->withInput();
        }

        // Lazada QC rejects non-Lazada image URLs (outer links).
        // Convert ERP/public image URLs to Lazada inlinks before pushing.
        try {
            $productPayload = $this->images()->ensureLazadaInlinkImages($productPayload, $setting, $client);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->route('ext.lazada.products.edit', $listing->id)
                ->withErrors($e->errors())
                ->withInput();
        }

        // Keep preview aligned with the final payload we are about to send.
        $preview['images'] = data_get($productPayload, 'Request.Product.Images.Image', []);
        $preview['payload'] = $productPayload;

        // Lazada product create expects a JSON string payload.
        $apiPath = '/product/create';
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

        return redirect()->route('ext.lazada.products.edit', $listing->id)
            ->with('status', 'Sample push sent to Lazada. Review the response below (QC errors will appear there).')
            ->with('push_request_json', json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->with('push_response_json', json_encode($result['body'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }


    private function region(): string
    {
        $setting = LazadaSetting::query()->first();
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
        $setting = LazadaSetting::query()->first()?->decrypted();
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
                'channel_id' => (string) $itemId,
                'name' => (string) $itemName,
                'image_url' => (string) ($imageUrl ?? ''),
                'variants' => $skuCount,
            ];
        }

        return [['found' => count($allProducts), 'rows' => $rows], null];
    }

    /** Import one fetched item, re-read live by its item id. */
    public function importOne(Request $request, \Extensions\lazada\Services\Lazada\LazadaItemImport $import)
    {
        $data = $request->validate(['ref' => 'required|string|max:64']);

        $setting = LazadaSetting::query()->first()?->decrypted();
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
        $data = $request->validate(['refs' => 'required|array|min:1|max:100', 'refs.*' => 'string|max:64']);

        $setting = LazadaSetting::query()->first()?->decrypted();
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

        $listing = LazadaProduct::query()->where('product_id', $productId)->first();
        if ($listing) {
            if (empty($listing->lazada_item_id)) {
                $listing->lazada_item_id = (string) $data['ref'];
                $listing->lazada_deleted_at = null;
                $listing->unlinked_at = null;
                $listing->save();
            }
        } else {
            LazadaProduct::create([
                'product_id' => $productId,
                'lazada_item_id' => (string) $data['ref'],
            ]);
        }

        return redirect()->back()->with('status', 'Linked Lazada item #' . $data['ref'] . ' to catalog product #' . $productId . '.');
    }

    public function searchCatalogProducts(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if ($q === '' || strlen($q) < 2) {
            return response()->json([]);
        }

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        // Find product IDs matching by option value SKU
        $optionMatchIds = DB::table($pfx . 'product_option_value')
            ->where('sku', 'like', '%' . $q . '%')
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->pluck('product_id')
            ->unique()
            ->toArray();

        $results = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                  ->where('pd.language_id', '=', $langId);
            })
            ->where(function ($sub) use ($q, $optionMatchIds) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.model', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%');
                if (!empty($optionMatchIds)) {
                    $sub->orWhereIn('p.product_id', $optionMatchIds);
                }
            })
            ->limit(20)
            ->get(['p.product_id', 'pd.name', 'p.model as sku', 'p.image']);

        // Fetch option values for these products
        $productIds = $results->pluck('product_id')->map(fn($v) => (int) $v)->all();
        $optionValues = collect();
        if (!empty($productIds)) {
            $optionValues = DB::table($pfx . 'product_option_value as pov')
                ->leftJoin($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                    $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                      ->where('ovd.language_id', '=', $langId);
                })
                ->whereIn('pov.product_id', $productIds)
                ->whereNotNull('pov.sku')
                ->where('pov.sku', '!=', '')
                ->get(['pov.product_id', 'pov.sku', 'pov.quantity', 'ovd.name as value_name'])
                ->groupBy('product_id');
        }

        return response()->json($results->map(function ($r) use ($optionValues) {
            $opts = $optionValues->get((int) $r->product_id, collect());
            $optionList = $opts->map(function ($ov) {
                return [
                    'sku' => $ov->sku,
                    'name' => $ov->value_name ?? '',
                    'qty' => (int) $ov->quantity,
                ];
            })->values()->toArray();

            return [
                'product_id' => (int) $r->product_id,
                'name' => html_entity_decode($r->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'sku' => $r->sku ?? '',
                'image' => $r->image ? asset('storage/' . ltrim($r->image, '/')) : null,
                'options' => $optionList,
            ];
        }));
    }


}
