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
    public function index(Request $request, ShopeeClient $client)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->get('q', ''));
        $syncStatus = (string) $request->get('sync_status', 'all');
        $manufacturerFilter = (string) $request->get('manufacturer', 'all');
        $groupFilter = (string) $request->get('group', 'all');
        $erpStatus = (string) $request->get('erp_status', 'all');

        /* ── Two surfaces on one workbench (#201, the owner 2026-09-05) ──
         *
         * THIS STORE is what this store carries and works day to day - its
         * own list, with the seller-center state tabs. ADD PRODUCTS is the
         * main catalogue, where a product is added into the store's list;
         * the slide-over is the way in and this full page is its overflow.
         * Multi-store is what earned the split: a store's daily table stops
         * mirroring the whole catalogue and shows only what it carries. The
         * add surface has its own lean flow, so it returns before all the
         * live-status machinery below, which it does not need. */
        if ($request->query('list') === 'add') {
            return $this->addCatalogue($request);
        }

        /* ── The seller center's own tabs (quest 1 slice 2, 2026-08-24) ──
         *
         * Live / Unlisted / Violation / Under review, Shopee's own
         * item_status buckets. They were answered by Shopee on every page
         * load until 2026-08-28, when the owner reversed that: the calls had
         * become the cost of the page. They now read the MIRROR on the
         * link rows (see below); "All" is this store's own list (#201),
         * pushed or not, and the strip names both. */
        $tabMap = ['live' => 'NORMAL', 'unlisted' => 'UNLIST', 'violation' => 'BANNED', 'reviewing' => 'REVIEWING'];
        $shopeeTab = (string) $request->get('shopee_tab', 'all');
        if (!array_key_exists($shopeeTab, $tabMap)) {
            $shopeeTab = 'all';
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        $liveCounts = null;
        $liveTabError = null;
        /* Since 2026-08-28 the counts and the per-row statuses come from
         * the MIRROR on the link rows (live_status, live_checked_at),
         * refreshed every 30 minutes by the automation, on demand by the
         * Refresh button, and written through by every push, unlist and
         * relist the ERP makes. A page view asks Shopee for nothing
         * (the owner: five calls per click had become the cost of the page).
         * The strip says when the mirror was last refreshed. */
        $liveCheckedAt = null;
        if ($setting && $auth['complete']) {
            $liveCounts = ShopeeProductLink::mirrorCounts($tabMap);
            $liveCheckedAt = ShopeeProductLink::query()->max('live_checked_at');
            $liveCheckedAt = $liveCheckedAt ? \Illuminate\Support\Carbon::parse($liveCheckedAt) : null;
        } else {
            /* The page has carried this note since it was built and nothing
               ever set it, so the one state it exists to explain - the
               credentials being incomplete, which is what an expired token
               looks like until the refresh cron runs - showed as every row
               reading "not checked yet" and no reason anywhere (the owner,
               2026-09-09: "Thats weird"). TikTok has always said it. */
            $liveTabError = 'Missing Shopee settings.';
            $shopeeTab = 'all';
        }

        /* Sorting. Lazada's listings page has had six sortable headers since
         * it was built and this one had none, on tables that look identical -
         * so a seller who runs both learns to click a header on one channel
         * and it silently does nothing on the other.
         *
         * Allow-listed and mapped to a real column here rather than
         * interpolated, so an edited URL cannot reach anything this array
         * does not name. Ordered in SQL because this page paginates at the
         * database, unlike Lazada's, which sorts a collection. */
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

        // Build product group → product mapping
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

        // Product group filter
        if ($groupFilter === 'none') {
            // Show products NOT matching any product group
            $allMatchedIds = $groupMapping['ids'];
            if ($allMatchedIds === null || empty($allMatchedIds)) {
                // No product groups or no matches — show all products
            } else {
                $query->whereNotIn('p.product_id', $allMatchedIds);
            }
        } elseif ($groupFilter !== 'all') {
            // Show products matching a specific product group
            $specificIds = $this->getProductIdsByGroup((int) $groupFilter);
            if (empty($specificIds)) {
                $query->whereRaw('1=0');
            } else {
                $query->whereIn('p.product_id', $specificIds);
            }
        }
        /* THIS STORE (#201, the owner 2026-09-05): the table is this store's own
         * list, not the catalogue. A product is on the store once it has a
         * listing record here (Add products, Import) or a link row here (a
         * push or a match) - ShopeeStoreProducts is the one definition; the
         * rest of the catalogue waits behind Add products. Phase C (#184)
         * had made this the whole catalogue so a new user's first product
         * was never invisible; multi-store is what changes the answer, and
         * the empty state now leads to Add products instead. Filtering by
         * a group above still narrows within the store's list. */
        $query->whereIn('p.product_id', ShopeeStoreProducts::query());

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
            /* The default view (#197, the owner): a disabled product the channel
             * does not hold is noise - the pushes skip it anyway. A disabled
             * product still LISTED here is exactly what an operator must
             * see: it likely needs unlisting, and it wears its Disabled
             * badge. The catalog-status filter still offers Disabled for
             * looking at the hidden ones on purpose. */
            $query->where(function ($sub) {
                $sub->where('p.status', 1)
                    ->orWhereIn('p.product_id', \Extensions\shopee\Models\ShopeeProductLink::query()
                        ->whereNotNull('shopee_item_id')->select('product_id'));
            });
        }

        // Text search
        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.model', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%')
                    // The row shows this store's own title, so the search finds it by that too.
                    ->orWhereIn('p.product_id', \Extensions\shopee\Models\ShopeeListing::query()
                        ->forStore(ShopeeSetting::defaultStore())
                        ->where('item_name', 'like', '%' . $q . '%')
                        ->select('product_id'));
            });
        }

        // Sync status filter (listed/not listed on Shopee)
        if ($syncStatus === 'listed') {
            $listedIds = ShopeeProductLink::query()->pluck('product_id')->unique()->toArray();
            if (empty($listedIds)) {
                $query->whereRaw('1=0');
            } else {
                $query->whereIn('p.product_id', $listedIds);
            }
        } elseif ($syncStatus === 'not_listed') {
            $listedIds = ShopeeProductLink::query()->pluck('product_id')->unique()->toArray();
            if (!empty($listedIds)) {
                $query->whereNotIn('p.product_id', $listedIds);
            }
        } elseif ($syncStatus === 'error') {
            // Exactly the rows that carry an error line.
            $query->whereIn('p.product_id', $this->listingStates()->erroredProductIds() ?: [0]);
        } elseif ($syncStatus === 'ready' || $syncStatus === 'not_ready') {
            /* The computed answer, filterable (#101): which unlisted
             * products could be one-click pushed RIGHT NOW, and which
             * still need something - by the same ShopeeListingReadiness
             * the rows, the listing page and the bulk push read. */
            $listedIds = ShopeeProductLink::query()->pluck('product_id')
                ->map(fn ($v) => (int) $v)->unique()->all();
            $candidateIds = DB::table($pfx . 'product')
                ->when(!empty($listedIds), fn ($q) => $q->whereNotIn('product_id', $listedIds))
                ->pluck('product_id')->map(fn ($v) => (int) $v)->all();
            $readinessAll = app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class)
                ->forProducts($candidateIds);
            $matching = array_keys(array_filter(
                $readinessAll,
                fn ($r) => $syncStatus === 'ready' ? $r['ready'] : !$r['ready']
            ));
            $query->whereIn('p.product_id', $matching ?: [0]);
        }

        /* The dashboard's own figures, as a filter (#240). A chip that says
         * "3 listings need attention" links here with ?state=attention, and
         * the rows it narrows to are fetched by the SAME query that counted
         * them, so the number and the list cannot disagree. */
        $troubleFilter = \App\Integrations\Listings\ListingTrouble::asked($request->query('state'));
        if ($troubleFilter !== null) {
            $troubleIds = app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)
                ->productIdsIn($troubleFilter);
            $query->whereIn('p.product_id', $troubleIds ?: [0]);
        }

        // A live tab narrows the table to the products whose Shopee items
        // hold that status right now. Only linked products can match: the
        // tab is a view of the shop, and an unpushed product is not in it.
        // The catalogue's own total, before a Shopee tab narrows the table:
        // the "All products" tab wears it so it reads as a total, not a status.
        $catalogueTotal = (clone $query)->count();
        // The header's second number: how much of this store's list is on Shopee.
        $listedTotal = (int) ShopeeProductLink::query()->distinct()->count('product_id');

        if ($shopeeTab !== 'all' && $liveCounts !== null) {
            $tabProductIds = ShopeeProductLink::query()
                ->where('live_status', $tabMap[$shopeeTab])
                ->pluck('product_id')->map(fn ($v) => (int) $v)->all();
            $query->whereIn('p.product_id', $tabProductIds ?: [0]);
        }

        $query->orderBy($sortColumns[$sort], $dir);
        // A stable tiebreak, or two products with the same name swap places
        // between pages and a row appears twice while another never shows.
        if ($sortColumns[$sort] !== 'p.product_id') {
            $query->orderBy('p.product_id', 'asc');
        }

        $products = $query->paginate(50)->withQueryString();

        foreach ($products as $row) {
            if (isset($row->name)) {
                $row->name = html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        // Load Shopee links for products on this page
        $pageIds = $products->pluck('product_id')->map(fn($v) => (int) $v)->all();
        // Each row is named by this store's own title, the catalog's name when it has none.
        $rowTitles = \App\Integrations\Listings\ListingContent::rowTitles($products, $pageIds === [] ? [] : \Extensions\shopee\Models\ShopeeListing::query()
            ->forStore(ShopeeSetting::defaultStore())
            ->whereIn('product_id', $pageIds)
            ->get());
        $shopeeLinks = collect();
        if (!empty($pageIds)) {
            $shopeeLinks = ShopeeProductLink::query()
                ->whereIn('product_id', $pageIds)
                ->get()
                ->groupBy(fn($l) => (int) $l->product_id);
        }

        // Variation rows for this page: combinations with their own image
        // and SKU, exactly what the core product list shows, one shared
        // query (App\Support\VariationRows).
        $optionRowsByProductId = \App\Support\VariationRows::forListing($pageIds, 'shopee', (int) (ShopeeSetting::defaultStore()?->id ?? 0));

        /* The mirror fills its own blanks (the owner, 2026-08-28: a product
         * pushed a minute ago should not read "Not checked yet"). A link
         * created by a push or a match has no status until someone asks;
         * the page asks once, for those rows only, then reads the mirror
         * like every other row. A shop whose links are all checked pays
         * nothing here. */
        if ($liveCounts !== null && $shopeeLinks->isNotEmpty()) {
            /* A refusal here is reported, not swallowed - but NOT through
               $liveTabError, which means "the store cannot be asked at all"
               and correctly takes the tab bar down with it. The counts are
               real when the fill fails, so the tabs stay and the refusal
               rides the page's own flash instead. */
            $fillError = null;
            $filled = app(\Extensions\shopee\Services\Shopee\ShopeeLiveListing::class)
                ->fillBlanks($auth, $shopeeLinks->flatten(), $fillError);
            if ($fillError !== null) {
                session()->now('error', \App\Integrations\Listings\BlankFillRefusal::sentence('Shopee', $fillError));
            }
            if ($filled) {
                $liveCounts = ShopeeProductLink::mirrorCounts($tabMap);
                $liveCheckedAt = $liveCheckedAt ?? now();
            }
        }

        /* The status chip per linked row on this page, from the mirror on
         * the link rows. Null (Shopee did not answer for it) renders as
         * "Not checked yet", never as a guess. */
        $liveStatuses = [];
        foreach ($shopeeLinks->flatten() as $l) {
            if ($l->live_status !== null) {
                $liveStatuses[(int) $l->shopee_item_id] = (string) $l->live_status;
            }
        }

        /* ONE state per row (Phase B, #184): the engine folds the mirror,
         * the listing record, drift and readiness into a single
         * ListingState the blade renders verbatim. Computed after
         * fillBlanks so it reads a filled mirror. This replaces the
         * page's separate readiness call and its own label map. */
        $listingStates = app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)
            ->forProducts(array_map('intval', $pageIds));


        // Manufacturer list for filter dropdown
        $allManufacturers = DB::table($pfx . 'manufacturer')
            ->orderBy('name')
            ->pluck('name', 'manufacturer_id');

        // Product group list for filter dropdown
        $allGroups = ShopeeProductGroup::query()->orderBy('name')->pluck('name', 'id');

        // Compute product group names for products on this page
        $groupMap = $groupMapping['map'];
        $pageGroupMap = collect();
        foreach ($products as $p) {
            $pid = (int) $p->product_id;
            $pageGroupMap[$pid] = $groupMap[$pid] ?? [];
        }

        /* The push preflight (2026-09-03, the one-editor merge): a row not
         * yet on Shopee offers a DIRECT push only when its listing would
         * certainly be accepted; otherwise the same words lead into the
         * listing editor, which names what is still missing. Computed for
         * the unlisted rows on this page only. */
        $unlistedIds = $products->filter(fn ($p) => !isset($shopeeLinks[(int) $p->product_id]))
            ->pluck('product_id')->map(fn ($v) => (int) $v)->values()->all();
        $pushReady = $unlistedIds !== []
            ? app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class)->forProducts($unlistedIds)
            : [];

        return view('ext-shopee::products.index', [
            'products' => $products,
            'rowTitles' => $rowTitles,
            'troubleFilter' => $troubleFilter,
            /* The relist preflight's page-side half (#191): a row whose
             * Relist would certainly be refused offers the fix instead. */
            'parcelGaps' => $this->parcelGapFor($products->pluck('product_id')->map(fn ($v) => (int) $v)->all()),
            'pushReady' => $pushReady,
            'q' => $q,
            'shopeeLinks' => $shopeeLinks,
            'sort' => $sort,
            'dir' => $dir,
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
            // The Last sync column (the owner, 2026-09-05), one answer shared with the group pages.
            'rowErrors' => app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)->errors(array_map('intval', $pageIds)),
            'catalogueTotal' => $catalogueTotal,
            'listedTotal' => $listedTotal,
            'liveCheckedAt' => $liveCheckedAt,
        ]);
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

    /** The same storage path the Listings rows draw their thumbnails from. */
    private function thumbUrl(?string $image): ?string
    {
        $image = trim((string) $image);

        return $image !== '' ? asset('storage/' . ltrim($image, '/')) : null;
    }

    /**
     * Add one catalogue product to this store's list (#201): a blank listing
     * record for this store is the membership. The listing editor fills it
     * in, and readiness reads it as "needs a category and couriers" until
     * then, so the row lands under This store ready to configure and push
     * through the unchanged workflow. The BelongsToShopeeStore trait stamps
     * the store, so firstOrCreate never crosses stores or doubles a row.
     */
    public function addToStore(Request $request, int $productId)
    {
        $exists = DB::table((string) config('catalog.prefix') . 'product')->where('product_id', $productId)->exists();
        abort_unless($exists, 404);

        // "Already on the store" includes a product carried by a link row
        // alone; the listing record it gains here changes nothing for it.
        $wasOn = ShopeeStoreProducts::has($productId);
        ShopeeListing::firstOrCreate(['product_id' => $productId]);

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
            ->leftJoinSub(ShopeeStoreProducts::query(), 'os', 'os.product_id', '=', 'p.product_id')
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
     * The link rows go through the same door Unlink uses (item cache with
     * them), this store's group memberships and the listing record follow,
     * so the product leaves This store and STOPS SYNCING at once.
     * Deliberately no blocker (the owner, 2026-09-05): nobody keeps a product on
     * the marketplace while stripping it here. It never touches Shopee - a
     * live listing stays up until it is removed there, which the confirm
     * says plainly and Delete from Shopee already covers. Every query here
     * is store-scoped, so a product carried only by another store answers
     * 404 from this one.
     */
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

    /**
     * Add the ticked catalogue products to this store's list in one go (#201).
     */
    /** Remove from this channel, for the ticked rows: the single verb, repeated (parity, 2026-09-07). */
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

    /** The Refresh button on the listings strip: the same walk the 30-minute automation runs. */
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
        /* Refresh is the one button (the owner, 2026-09-07): every status is
         * re-read first, then the links are checked against the store, the
         * old Repair links folded in. */
        // The check reads two hundred per run; the list starts after the last one checked, so "run it again" continues.
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

    /**
     * Unlist or relist several items in one call. Shopee's unlist_item
     * takes a list, so a selection of fifty is one request, and its
     * failure_list names each item it refused.
     *
     * @return array{ok: list<int>, failed: array<int, string>, error: ?string}
     */
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
            // Write through to the mirror: the ERP just changed the status,
            // so the next page view reads it without waiting for a refresh.
            if ($done !== []) {
                ShopeeProductLink::query()->whereIn('shopee_item_id', $done)
                    ->update(['live_status' => $unlist ? 'UNLIST' : 'NORMAL', 'live_checked_at' => now()]);
            }
        }
        return ['ok' => $ok, 'failed' => $failed, 'error' => null];
    }

    /** The selection bar's Unlist / Relist (the owner, 2026-08-28): the same call as the row's, for many rows. */
    public function bulkToggle(Request $request, ShopeeClient $client)
    {
        $request->validate([
            'action' => 'required|in:list,unlist',
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'integer',
        ]);
        $unlist = $request->input('action') === 'unlist';
        // Shopee's own words (the owner, 2026-09-06): Delist and Publish.
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

        /* THE PREFLIGHT, per row (#191): relist candidates missing their
         * parcel are held back BY NAME, never fired at Shopee to collect
         * the refusal one row at a time. */
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


    /**
     * What a push would send, as JSON for the review modal (cleanup 7+9,
     * approved from the draft 2026-09-03): the computed price WITH its
     * arithmetic, category and couriers by NAME, image and variation
     * counts, and the title/description that would go - the only two
     * fields the modal lets the operator edit on the way out.
     */
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
        /* What THIS listing sends, not what the catalog holds: a listing
           with its own arrangement sends its own pictures, and a review
           that counted the catalog's would promise the wrong number. */
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
        $finalPrice = $listing ? $listing->priceFor($corePrice) : $corePrice;
        $pct = (float) ($listing->markup_percent ?? 0);
        $fixed = (float) ($listing->markup_fixed ?? 0);
        $ruleWords = ($pct == 0.0 && $fixed == 0.0)
            ? 'catalog price'
            : trim(($pct != 0.0 ? $pct . '%' : '') . ($pct != 0.0 && $fixed != 0.0 ? ' + ' : '') . ($fixed != 0.0 ? number_format($fixed, 2) : '')) . ' over catalog';

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
            // Not a gap: the push goes, with the first nine (the owner, 2026-09-05).
            'images_note' => $imageCount > \Extensions\shopee\Services\Shopee\ShopeeItemCreate::MAX_IMAGES
                ? 'Shopee takes at most ' . \Extensions\shopee\Services\Shopee\ShopeeItemCreate::MAX_IMAGES . ', so the first ' . \Extensions\shopee\Services\Shopee\ShopeeItemCreate::MAX_IMAGES . ' go up'
                : null,
            'variations' => $variationCount,
            // The words exactly as the push will send them, templates included.
            'title' => $content['title'],
            'description' => \App\Support\Catalog\DescriptionText::of($content['description']),
            'push_url' => route('ext.shopee.products.push_direct', $productId),
            'listing_url' => route('ext.shopee.listings.edit', $productId),
        ]);
    }

    public function pushDirect(Request $request, int $productId, ShopeeClient $client)
    {
        /* The review modal's two editable fields (cleanup 7+9): edits are
         * saved as the listing's own wording FIRST, then the push runs on
         * what was just saved - the same order Save and push uses, so a
         * refusal never loses the typing. Absent fields change nothing. */
        if ($request->filled('title') || $request->filled('description')) {
            $data = $request->validate([
                'title' => 'nullable|string|max:255',
                'description' => 'nullable|string|max:5000',
            ]);
            ShopeeListing::query()->updateOrCreate(['product_id' => $productId], array_filter([
                'item_name' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
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

        // Accepted: back to the Listings page the operator came from, search and all (the owner, 2026-09-06).
        return redirect()->to(\App\Support\BackTo::safe($request->input('back'), url()->previous(route('ext.shopee.products.index'))))->with('status', $answer['message']);
    }

    /**
     * PUSH ONE PRODUCT, AND SAY WHAT HAPPENED.
     *
     * The whole create, refusals and all, with no opinion about where the
     * answer is drawn. pushDirect turns it into a redirect for the one
     * product an operator pressed; bulkPush calls it once per ticked
     * product and reports each on its own line - which is the only honest
     * shape for a batch, because ten creates can be refused for ten
     * different reasons (the owner, 2026-09-09).
     *
     * @return array{ok: bool, message: string}
     */
    private function pushOne(int $productId, array $auth, ShopeeClient $client): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $existingLink = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if ($existingLink) {
            return ['ok' => false, 'message' => 'Push failed: Product already listed on Shopee (Item ID: ' . $existingLink->shopee_item_id . ').'];
        }

        /* THE GATE (the owner, 2026-09-06): the one readiness computation the
         * Ready column shows is the one this push refuses through, so a
         * product that read Ready is never turned away here. Every check
         * this path used to make by hand (disabled, name, description,
         * price, parcel, images, settings, the variation refusals) is a
         * gap there now. */
        $gateInherit = app(\Extensions\shopee\Services\Shopee\ShopeeInheritedSettings::class);
        $gateRow = ShopeeListing::query()->where('product_id', $productId)->first() ?? (new ShopeeListing())->forceFill(['product_id' => $productId]);
        $gateCategory = (int) ($gateInherit->fill($gateRow, $gateInherit->forProducts([$productId])[$productId] ?? null)['listing']->shopee_category_id ?? 0);
        if ($gateCategory > 0) {
            app(ShopeeProductGroupController::class)->ensureTemplate($gateCategory, $client); // a category never opened is read now
        }
        $readiness = app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class)
            ->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => []];
        if (!$readiness['ready']) {
            return ['ok' => false, 'message' => \App\Integrations\Listings\CatalogGaps::refusal($readiness)];
        }

        /* ASK SHOPEE, NOT ONLY OURSELVES (the owner, 2026-09-09).
         *
         * The absence of a link row was taken as proof that Shopee holds
         * nothing, and it is not: a store deleted here takes every link
         * with it while the shop keeps every item, and so does a database
         * restored from before a push. Every push after that looked like a
         * first push, and Shopee does not refuse a duplicate. His test
         * shop ended with three copies of one product and four of another.
         *
         * The group push has always reconciled before deciding create or
         * update, in these exact words: creating into that ignorance is
         * how a duplicate listing appears next to the original. The
         * one-click push did not, and it is the one an operator presses
         * from a row. Same rule now, same service.
         *
         * Paid only when the ERP has no link, which is the only case that
         * can create, and the shop scan inside is memoised per instance.
         */
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

        /* REFUSE INSTEAD OF BORROW (#96). This used to take the FIRST
         * product group whose filter matched; every live group's filters
         * are empty, so primary-key order chose the markup and nothing on
         * screen said which group priced the push. It succeeded at the
         * wrong price, silently. Now the one-click push runs only on the
         * product's OWN listing settings - saved on its listing page - and
         * says exactly what to do when there are none. */
        $saved = ShopeeListing::query()->where('product_id', $productId)->first();
        /* A group is a preset the product inherits (the owner, 2026-09-05): the
         * record the push READS has its blanks filled from the group, in
         * memory; the record SAVED below is the listing itself. */
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

        // The catalog's words; the listing's own override them in the payload.
        $itemName = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $description = trim(strip_tags(html_entity_decode($product->description ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        // Step 1: Upload images
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

        /* The listing's own price rule; price itself is never stored, so
         * this follows the catalog price wherever it goes. Content the
         * operator wrote on the listing overrides the catalog's; null
         * means never set, so the catalog value goes. SKU is always the
         * catalog's - reconcile matches by it (card #97). */
        /* #192's variation refusals (the 5x price ratio, the 50-variation
         * cap) are readiness gaps now, judged at the gate above. */
        $sellingPrice = $listing->priceFor((float) $product->price);

        // The listing's own content/price/parcel over the catalog's, and
        // its answers to the category's attribute sheet (#98).
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
            // Accepted with no item: addItem() says what Shopee did say.
            return ['ok' => false, 'message' => (string) $added['error']];
        }

        ShopeeProductLink::create([
            'product_id' => $productId,
            'shopee_item_id' => (int) $itemId,
            'shopee_model_id' => null,
            'sku' => $product->sku ?? '',
        ]);

        // Provenance (#104): what went, from where, when.
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

        // Write-through: the mirror learns the item's real status now.
        app(\Extensions\shopee\Services\Shopee\ShopeeLiveListing::class)->confirm($auth, [(int) $itemId]);

        // Variations ride the push, priced by the listing's own rule.
        $rode = $create->variationsRideOrUndo(
            $client, $auth, (int) $itemId, $productId,
            fn (float $core) => $listing->priceFor($core)
        );

        if (!$rode['ok']) {
            // #192: see push() - never leave a half-listed item standing.
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
        // More images than Shopee takes is not an error: say what went (the owner, 2026-09-05).
        if ($imageNote = $create->imageNote($product, $listing)) {
            $flash .= ' ' . $imageNote;
        }

        // Accepted: back to the Listings page the operator came from, search and all (the owner, 2026-09-06).

        return ['ok' => true, 'message' => $flash];
    }


    // ── Bulk Push to Shopee ───────────────────────────────────────

    /**
     * PUSH THE TICKED PRODUCTS, AND REPORT EACH ONE.
     *
     * Multi-tick push came off the Listings page on 2026-09-06 for a
     * reason that has since expired twice over: a batch needed a combined
     * form for couriers, category and markup, so it belonged on a group
     * page; and a bar reporting one outcome cannot describe ten creates
     * refused for ten different reasons.
     *
     * Neither holds now. Every listing takes its own price rule, else its
     * group's, so there is nothing shared left to ask for. And the answer
     * below is per product, by name, with the marketplace's own words -
     * which is what the old bar could not say (the owner, 2026-09-09).
     *
     * Not ready is not a failure and does not stop the batch: those
     * products are named with what they still need, and everything ready
     * goes up. One shop scan serves the whole batch, because the link
     * check is one instance for the request.
     */
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

        /* The walk, the ceiling and the words are every channel's
         * (App\Integrations\Push\BulkPushRun); only pushing one product
         * is Shopee's. */
        $answer = \App\Integrations\Push\BulkPushRun::over(
            $productIds,
            'Shopee',
            function (int $productId) use ($auth, $client) {
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

        // Skip disabled products
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

    // ── Sync Price (single, model-aware) ────────────────────────────

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
        // The one price rule (2026-09-07): the listing's own, else its group's, else the catalog price.
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

    // ── Unlink Product from Shopee ──────────────────────────────────

    public function unlink(int $productId)
    {
        // Deleting the links and clearing their cache entries is one operation
        // and lives in one place, so this page and the product-group page
        // cannot drift apart again.
        $deleted = ShopeeProductLink::unlinkProduct($productId, (int) app('shopee.route-store')->id);

        if ($deleted === 0) {
            return redirect()->back()->with('error', 'Product was not linked to Shopee.');
        }

        return redirect()->back()->with('status', "Unlinked product from Shopee ($deleted link(s) removed, cache cleared). You can now re-sync.");
    }

    // ── Delete Product from Shopee ────────────────────────────────

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
            // Remove links and cache entries for this item
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

            usleep(200000); // 200ms rate limit
        }

        ActivityLogger::log('deleted', 'Shopee Product', null, 'Bulk deleted ' . $okCount . ' product(s) from Shopee');

        return redirect()->back()->with('status', "Bulk Delete: {$okCount} deleted, {$errCount} failed out of " . count($productIds) . " selected.");
    }

    // ── Rebuild Shopee Item Cache ──────────────────────────────────

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

        // Clear existing cache - this store's half of it only (S4b, #194).
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

    // ── Scan for Unmatched Shopee Items ────────────────────────────

    /**
     * The Import page (cleanup point 10, the owner 2026-09-03): its own page
     * under the Catalog menu, not a card squatting on the workbench - the
     * card sat permanently open saying "Nothing is waiting" to everyone
     * except a fresh install. Scan, then Import / Link / Dismiss per row.
     */
    /**
     * The Import page, stateless (the owner, 2026-09-04): nothing about the
     * shop's items is saved - blank until Fetch reads Shopee live, rows
     * exist only in the render, Import re-reads by item id.
     */
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

    /**
     * Read the shop live and keep only items the catalog cannot place.
     * Model-aware: an item is listed when ANY of its models is neither
     * linked nor known by SKU - importing brings the whole item, models
     * and all. Pure read.
     *
     * @return array{0: ?array{found: int, rows: list<array>}, 1: ?string}
     */
    private function fetchUnmatched(ShopeeClient $client): array
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return [null, "Missing Shopee {$modeLabel} settings."];
        }

        $pfx = (string) config('catalog.prefix');

        /* Plain pagination, NOT fetchAllShopeeItemIds: that helper sweeps
         * 730 days in 15-day windows x two time-fields x two statuses -
         * ~196 calls before it has looked at a single product, whatever
         * the shop holds (the owner's 2-product sandbox paid all 196, into a
         * flaky sandbox host, and the page hung). A live fetch needs the
         * CURRENT shop, and get_item_list pages it without time filters:
         * one call per 100 items per status. */
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

    // ── Bulk Sync Qty (model-aware) ─────────────────────────────────

    /**
     * Ask Shopee whether the links this page believes in are still true
     * (Phase F, #184): the group page's check, on the workbench, via the
     * shared ShopeeLinkCheck. With rows ticked it checks those; with
     * none it checks every linked product, 200 at a time.
     */
    public function checkAgainstShopee(Request $request, ShopeeClient $client, \Extensions\shopee\Services\Shopee\ShopeeLinkCheck $check)
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        /* Everything this store carries, not only what is already linked
         * (the owner, 2026-09-09). Asked over a shop of thirty-two items with
         * no link rows left, this used to answer "nothing to check" - the
         * one moment it was most needed. A product Shopee does not hold
         * simply reads "not pushed yet" and nothing is written. */
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

        // Filter out disabled products
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

    // ── Bulk Sync Price (model-aware) ───────────────────────────────

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

        // The one price rule (2026-09-07): the listing's own, else its group's, else the catalog price.
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

    // ── Sync Single Product ID ────────────────────────────────────

    public function syncSingleProductId(int $productId, ShopeeClient $client)
    {
        $tag = '[ShopeeSync:single:' . $productId . ']';

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        // Already linked?
        $existingLink = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if ($existingLink) {
            return redirect()->back()->with('status', 'Product already linked to Shopee item ' . $existingLink->shopee_item_id . ' (SKU: ' . $existingLink->sku . '). Use Unlink to re-sync.');
        }

        $pfx = (string) config('catalog.prefix');

        // Collect all SKUs for this product: option value SKUs + main model + sku column
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

        Log::info("$tag ERP product fields — model: '$mainModel', sku: '$mainSku', option_skus: " . json_encode($ovSkus));
        Log::info("$tag Looking for these SKUs: " . json_encode($allSkus));

        if (empty($allSkus)) {
            return redirect()->back()->with('error', 'Product has no SKU (main or option values).');
        }

        // Build lowercase lookup for case-insensitive matching
        $lcSkus = array_map('strtolower', $allSkus);

        // ── Try cache first ──────────────────────────────────────────
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

                // Skip parent-level hit when ERP product has option SKUs and Shopee item has models
                if ($isParent && $hasOptions) {
                    $hasModelEntries = ShopeeItemCache::query()
                        ->where('shopee_item_id', $hit->shopee_item_id)
                        ->whereNotNull('shopee_model_id')
                        ->exists();
                    if ($hasModelEntries) {
                        Log::info("$tag Cache: skipping parent hit (item {$hit->shopee_item_id}, sku: {$hit->sku}) — ERP has option SKUs");
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
                    Log::info("$tag MATCHED via cache (parent) — shopee_item_id: {$hit->shopee_item_id}, sku: {$hit->sku}");
                    return redirect()->back()
                        ->with('status', "Linked to Shopee item {$hit->shopee_item_id} \"{$hit->item_name}\" (SKU: {$hit->sku}) [from cache].");
                }

                // Model-level match
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
                Log::info("$tag MATCHED via cache (model) — shopee_item_id: {$hit->shopee_item_id}, model_id: {$hit->shopee_model_id}, sku: {$hit->sku}");
            }

            if ($linkedAny) {
                return redirect()->back()
                    ->with('status', "Linked to Shopee item {$cacheItemId} \"{$cacheItemName}\" (models matched by SKU) [from cache].");
            }
        }

        Log::info("$tag No cache match, falling back to full API scan");

        // ── Cache miss — fallback to full API scan ──────────────────
        $allItemIds = $this->fetchAllShopeeItemIds($client, $auth, 730, 'shopee.products.sync_single');
        Log::info("$tag Total unique Shopee items: " . count($allItemIds));

        if (empty($allItemIds)) {
            return redirect()->back()->with('error', 'No items found on Shopee.');
        }

        // Fetch base info and try to match
        $simpleCount = 0;
        $modelItemCount = 0;
        $allShopeeSkus = []; // collect for debugging

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
                Log::warning("$tag get_item_base_info FAILED for chunk $chunkIdx — " . json_encode($infoResult['body'] ?? null));
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

                // Always check item_sku first, regardless of has_model
                if ($itemSku !== '') {
                    $simpleCount++;
                    $allShopeeSkus[] = $itemSku;

                    ShopeeItemCache::query()->updateOrCreate(
                        ['shopee_item_id' => $itemId, 'shopee_model_id' => null],
                        ['sku' => $itemSku, 'item_name' => $itemName, 'image_url' => $imageUrl]
                    );

                    if (in_array(strtolower($itemSku), $lcSkus)) {
                        // If ERP product has option SKUs and Shopee item has models,
                        // skip parent link — let model matching below handle it.
                        $erpHasOptions = $hasModel && !empty($ovSkus);
                        if (!$erpHasOptions) {
                            ShopeeProductLink::create([
                                'product_id' => $productId, 'shopee_item_id' => $itemId,
                                'shopee_model_id' => null, 'sku' => $itemSku,
                            ]);
                            Log::info("$tag MATCHED via item_sku — shopee_item_id: $itemId, item_sku: $itemSku, item_name: '$itemName', has_model: " . ($hasModel ? 'true' : 'false'));
                            return redirect()->back()
                                ->with('status', "Linked to Shopee item {$itemId} \"{$itemName}\" (SKU: {$itemSku}).");
                        }
                        Log::info("$tag item_sku '$itemSku' matches but ERP has option SKUs — deferring to model-level matching");
                    }
                }

                // Additionally check model SKUs if has_model
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
                        Log::warning("$tag get_model_list FAILED for item_id=$itemId — " . json_encode($modelResult['body'] ?? null));
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
                            Log::info("$tag MATCHED via model_sku — shopee_item_id: $itemId, model_id: $mId, model_sku: $modelSku");
                        }
                    }
                    if ($linkedAny) {
                        return redirect()->back()
                            ->with('status', "Linked to Shopee item {$itemId} \"{$itemName}\" (models matched by SKU).");
                    }
                }
            }
        }

        Log::warning("$tag NO MATCH — simple items scanned: $simpleCount, model items scanned: $modelItemCount, total Shopee SKUs seen: " . count($allShopeeSkus));
        Log::warning("$tag ERP SKUs wanted: " . json_encode($allSkus));
        Log::warning("$tag Shopee SKUs sample (first 50): " . json_encode(array_slice($allShopeeSkus, 0, 50)));

        return redirect()->back()
            ->with('error', 'No matching Shopee product found for SKUs: ' . implode(', ', $allSkus)
                . ' (Scanned ' . count($allItemIds) . ' Shopee items: ' . $simpleCount . ' simple, ' . $modelItemCount . ' with models)');
    }

    // ── Manual Link / Dismiss Unmatched ─────────────────────────────

    /**
     * Import a Shopee item into the core catalog (#196): the whole item -
     * name, price, stock, parcel, description, images, variations - lands
     * as a real product, Shopee-only details ride its listing record, and
     * the link is made so it behaves as listed at once. One item id may
     * hold many queue rows (one per variation); importing any of them
     * imports the item and clears them all.
     */
    /** Import one fetched item, re-read live by its item id. */
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

    /** Import selected: every ticked row, each in its own transaction. */
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

    /** Link a fetched item to a catalog product it already is (item-level). */
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

        /* One catalog product to one Shopee item per store, both ways
         * (the owner, 2026-09-07). A second link is refused with what holds
         * the first, never written over or quietly skipped. */
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

    /** The Link picker's catalog search: the shared shape, with the products this store already holds marked (App\Support\CatalogPickerSearch). */
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

    // ── Model-Aware Stock Push Helper ───────────────────────────────


    // ── Model-Aware Price Push Helper ───────────────────────────────


    // ── General Helpers ──────────────────────────────────────────────

    /**
     * The parcel gap per product (#191): weight and package dimensions on
     * the catalog product, which Shopee demands for pushes AND relists.
     * @return array<int, string> product id => gap sentence (absent = fine)
     */
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

    /** The listing engine for the store this request stands in. */
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

        // Try option value SKU
        $pov = DB::table($pfx . 'product_option_value')->where('sku', $sku)->first(['product_id']);
        if ($pov) return (int) $pov->product_id;

        // Try product model (main SKU)
        $prod = DB::table($pfx . 'product')->where('model', $sku)->first(['product_id']);
        if ($prod) return (int) $prod->product_id;

        // Try product sku column
        $prod = DB::table($pfx . 'product')->where('sku', $sku)->first(['product_id']);
        if ($prod) return (int) $prod->product_id;

        return 0;
    }


    /**
     * Which group each product belongs to, PIVOT-ONLY since the cleanup
     * (point 2, the owner 2026-09-03). The filter-based resolution this
     * replaces never read the pivot at all - and a group with empty
     * filters matched EVERY product, the catch-all trap that once priced
     * pushes silently (#96).
     */
    private function getGroupProductMapping(): array
    {
        if (!ShopeeProductGroup::query()->exists()) {
            return ['ids' => null, 'map' => []];
        }

        $rows = DB::table('shopee_product_group_products as pgp')
            ->join('shopee_product_groups as g', 'g.id', '=', 'pgp.shopee_product_group_id')
            ->whereIn('g.id', ShopeeProductGroup::query()->pluck('id'))
            ->get(['pgp.product_id', 'g.name']);

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->product_id][] = (string) $r->name;
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

    /**
     * Fetch all Shopee item IDs across 15-day time windows (API limit).
     * Scans NORMAL + UNLIST statuses, going back $days days.
     */
    /**
     * Every item id currently on the shop, by plain offset pagination -
     * the stateless fetch's read. Null when Shopee never answered (as
     * distinct from an empty shop, which is []).
     *
     * @return ?list<int>
     */
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
                    // A failed first read is "Shopee is not answering";
                    // a failure mid-way still surfaces rather than
                    // presenting half a shop as the whole of it.
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
        $windowSize = 15 * 86400; // 15 days in seconds
        $startFrom = $now - ($days * 86400);

        // Scan by both update_time AND create_time to catch all items.
        // Items created long ago that were never updated via API would be missed
        // by update_time-only scanning.
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

    /**
     * The category combobox's two JSON endpoints, for the push form, the
     * add-product page and the listing page. Same answers the group form's
     * combobox already speaks, delegated to the one implementation - but
     * living on THIS controller so they answer to the shopee/product key
     * these pages are gated on, not shopee/product_group (the door keys on
     * the routed controller).
     */
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

    // ── The individual listing page (quest 1, 2026-08-24) ──────────────
    //
    // One product on Shopee, presented the way the seller center presents
    // it: the LIVE state fetched from the marketplace on every load, and
    // the listing record's own settings and content editable beneath it.
    // Lives on this controller so it shares the Listings surface's derived
    // permission key (shopee/product), exactly as Lazada's edit-listing
    // form shares lazada/product.

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

        // The live half. Only a linked product HAS a live half; an unpushed
        // one shows readiness instead.
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
                // What this page just read is the freshest answer there is:
                // write it to the mirror, so the lists agree with it at once
                // (the owner, 2026-08-28: show the status once fetched after pushing).
                $mirror = $live !== null ? (string) ($live['item_status'] ?? '')
                    : (str_contains((string) $liveError, 'holds no record') ? 'MISSING' : '');
                if ($mirror !== '') {
                    ShopeeProductLink::query()->whereKey($link->id)
                        ->update(['live_status' => $mirror, 'live_checked_at' => now()]);
                }
                // An item with models whose model list came back empty was not read.
                if ($live !== null && (empty($live['has_model']) || ($live['models'] ?? []) !== [])) {
                    \Extensions\shopee\Services\Shopee\ShopeeListingStates::rememberHeld(
                        (int) ($auth['store_id'] ?? 0), $productId, array_column($live['models'] ?? [], 'model_sku')
                    );
                }
            }
        }


        /* The category's attribute sheet (#98): what Shopee's own form
         * would ask for this category, read from the marketplace once and
         * cached. Fetched here when never read, so the section appears the
         * first time an operator lands on a listing whose category we have
         * not seen - the mirrored form, not a hardcoded one. */
        // The listing with its blanks filled from its group: the values in effect.
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

        /* Readiness through the ONE shared computation (#101), so "Ready"
         * on this page, the listings index and the bulk push all mean the
         * same thing. */
        $readiness = app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class)
            ->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => [], 'gaps' => []];

        /* The engine's answer (Phase B, #184), computed AFTER the live
         * fetch wrote the mirror, so this page and every list agree the
         * moment this page has looked. */
        $listingState = app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)
            ->forProducts([$productId])[$productId] ?? null;

        /* Variation coverage (Phase E, #184, #190): the catalogue's
         * variations, the link rows and Shopee's models compared once, so
         * this page can say "On Shopee: 2 of 3 variations" and name what
         * is missing on which side - the fact the deleted-1pc incident
         * had no surface for. */
        // The variations this store sells, the list every push acts on, so
        // this line and the pushes never disagree about what "3 variations"
        // means. One switched off here or in the catalog is not counted.
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

        return view('ext-shopee::listings.edit', \App\Integrations\Listings\ListingImages::cardData($productId, $listing->image_order ?? null, $listing ?? null, \App\Services\Media\ListingWatermark::groupTemplateId('shopee_product_groups', 'shopee_product_group_products', 'shopee_product_group_id', $productId, 'shopee_setting_id', (int) ($listing->shopee_setting_id ?? 0) ?: null)) + [
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
            /* The gaps themselves, not only their words: the strip's notes
               link each one at the field that fills it, and a view that had
               only the sentences was silently linking nothing. */
            'readiness' => $readiness,
            'logistics' => $this->fetchLogistics(),
            'shown' => $shown,
            'groupBand' => $this->groupBandFor($productId, $shopeeStoreId),
            'storeId' => (int) (\Extensions\shopee\Models\ShopeeSetting::defaultStore()?->id ?? 0),
            'descriptionTemplates' => \App\Models\DescriptionTemplate::forStore('shopee', (int) (\Extensions\shopee\Models\ShopeeSetting::defaultStore()?->id ?? 0)),
        ]);
    }

    /**
     * The Product group band's data (partials/listing-group-band): the
     * group this product is in on this store, the store's groups, and each
     * group's values keyed the way the page's field descriptors are.
     *
     * @return array{current: ?int, groups: list<array{id: int, name: string, url: string}>, values: array<int, array<string, mixed>>}
     */
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
            // The label reads the way the category picker writes it: name, then the id.
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

    /**
     * Re-read this item's variations from Shopee and make the link rows
     * match (Phase E, #184, #190): the same repair Sync ID and the push
     * self-heal run, offered as a verb - before this, a LINKED product had
     * no button that could re-read models, and the only way out of a stale
     * link was Unlink and start over.
     */
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

    /**
     * Add the catalogue's missing variations to the live listing (Phase F,
     * #184): one push where the daily loop used to be unlink, re-match,
     * and hope. On success the link rows are re-read at once, so the
     * coverage line and the pushes see the new models immediately.
     */
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
            // The links learn the new models at once, by the same repair
            // everything else uses.
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
        // The catalog's words, shaped exactly as the page pre-filled them.
        $coreWords = \App\Support\Catalog\CoreWords::of($productId);
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
            'item_name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'weight' => 'nullable|numeric|min:0.01',
            'description_prefix_id' => 'nullable|integer',
            'watermark_template_id' => 'nullable|integer',
            'watermark_all_images' => 'nullable|boolean',
            'description_suffix_id' => 'nullable|integer',
            'package_length' => 'nullable|integer|min:1|max:1000000',
            'package_width' => 'nullable|integer|min:1|max:1000000',
            'package_height' => 'nullable|integer|min:1|max:1000000',
            'attributes' => 'nullable|array',
            'attributes.*' => 'nullable|string|max:500',
            'image_order' => 'nullable|string|max:20000',
            ...\App\Integrations\Listings\ListingVariations::rules(),
            ...\App\Integrations\Listings\ListingGroup::rules(),
        ]);

        /* The pictures this listing sends, in the order the card arranged
         * them (the owner, 2026-09-08; every channel since VENT2-131). One
         * hidden input, JSON, holding the library paths; the rules for
         * reading it are ListingImages'. */
        $imageOrder = \App\Integrations\Listings\ListingImages::submitted($productId, $data['image_order'] ?? null);

        /* Content fields are OVERRIDES: an emptied field returns to null,
         * which means "follows the catalog". That is the whole model -
         * saving here never writes the core product, and clearing a field
         * is how a divergence is deliberately ended. */
        $blank = fn ($v) => ($v === null || trim((string) $v) === '') ? null : $v;

        $fields = [
            'shopee_category_id' => $data['shopee_category_id'] ?? null,
            'description_prefix_id' => ((int) ($data['description_prefix_id'] ?? 0)) ?: null,
            ...\App\Integrations\Listings\ListingImages::submittedWatermark($request),
            'description_suffix_id' => ((int) ($data['description_suffix_id'] ?? 0)) ?: null,
            'shopee_brand_id' => ((int) ($data['shopee_brand_id'] ?? 0)) > 0 ? (int) $data['shopee_brand_id'] : null,
            'logistic_ids' => array_map('intval', $data['logistic_ids'] ?? []),
            'markup_percent' => $blank($data['markup_percent'] ?? null),
            'markup_fixed' => $blank($data['markup_fixed'] ?? null),
            /* The Content boxes are pre-filled with the catalog's words so
             * a few can be changed without retyping the rest (the owner,
             * 2026-09-07). Text still equal to the catalog's is stored as
             * null, which is what "follow the catalog" is, so pre-filling
             * never freezes a listing into a copy. */
            'item_name' => \App\Integrations\Listings\ListingContent::overrideOrNull($data['item_name'] ?? null, $coreWords['title']),
            // Shopee descriptions are plain text, so the value is compared as text.
            'description' => \App\Integrations\Listings\ListingContent::descriptionToSave($data['description'] ?? null, $coreWords['description'], null, null, true),
            'weight' => $blank($data['weight'] ?? null),
            'package_length' => $blank($data['package_length'] ?? null),
            'package_width' => $blank($data['package_width'] ?? null),
            'package_height' => $blank($data['package_height'] ?? null),
            // Only answered attributes are kept; an emptied field is
            // simply no longer an answer.
            'attribute_values' => array_filter(
                array_map(fn ($v) => trim((string) $v), $data['attributes'] ?? []),
                fn ($v) => $v !== ''
            ) ?: null,
        ];

        /* Only a save that CARRIED the arrangement may change it. The input
         * is invisible, so a caller that does not send it must not be read
         * as one that cleared it. */
        if ($request->has('image_order')) {
            $fields['image_order'] = $imageOrder;
        }

        /* The group dropdown: picking a group moves the product into it,
         * No group takes it out. */
        $picked = \App\Integrations\Listings\ListingGroup::submitted($request);
        if ($picked['present']) {
            \App\Integrations\Listings\ListingGroup::assign([
                'pivot' => 'shopee_product_group_products', 'fk' => 'shopee_product_group_id',
                'groups' => 'shopee_product_groups', 'storeFk' => 'shopee_setting_id',
                'storeId' => (int) (ShopeeSetting::defaultStore()?->id ?? 0),
            ], $productId, $picked['id']);
        }

        ShopeeListing::query()->updateOrCreate(['product_id' => $productId], $fields);

        // A value equal to the group's follows the group from now on.
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

        /* Save and push (2026-09-03, the one-editor merge): the save half
         * has already landed, so a refusal below never loses the typing.
         * Readiness is re-checked on what was JUST saved and every gap is
         * named - the button never leads to a refusal it could have
         * spelled out. */
        if ($request->boolean('push_after')) {
            if (ShopeeProductLink::query()->where('product_id', $productId)->exists()) {
                return redirect()->route('ext.shopee.listings.edit', $productId)
                    ->with('status', 'Listing saved.');
            }

            $readiness = app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class)
                ->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => ['its readiness could not be checked']];

            if (!$readiness['ready']) {
                return redirect()->route('ext.shopee.listings.edit', $productId)
                    ->with('error', 'Saved, but not pushed: this listing still needs '
                        . implode(', ', $readiness['missing']) . '. Set what is missing and try again.');
            }

            return $this->pushDirect($request, $productId, $client);
        }

        return redirect()->route('ext.shopee.listings.edit', $productId)
            ->with('status', 'Listing saved.');
    }

    /**
     * Push the listing's content and settings onto the LIVE Shopee item.
     *
     * The missing half of the listing lifecycle: Save writes the record and
     * the record drives the NEXT push - but an already-listed item never
     * gets another add_item, so until this, an edited title or parcel never
     * reached Shopee at all. update_item is a partial update: only content
     * and settings go. Price and stock are deliberately absent - they have
     * their own syncs and their own rules (the price rule computes, stock
     * is live).
     */
    public function pushListingUpdate(int $productId, ShopeeClient $client)
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $r = $this->performListingUpdate($productId, $client, $auth);

        return redirect()->back()->with($r['ok'] ? 'status' : 'error', $r['message']);
    }

    /**
     * The reusable core of a content update onto the live item, shared by
     * the listing page's Push update and the bulk Send path (which updates
     * already-listed selections instead of silently skipping them).
     *
     * @return array{ok: bool, message: string}
     */
    private function performListingUpdate(int $productId, ShopeeClient $client, array $auth): array
    {
        $answer = $this->attemptListingUpdate($productId, $client, $auth);
        $this->listingStates()->recordOutcome($productId, $answer['ok'] ? null : $answer['message']);

        return $answer;
    }

    /** @return array{ok: bool, message: string} */
    private function attemptListingUpdate(int $productId, ShopeeClient $client, array $auth): array
    {
        $link = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if (!$link) {
            return ['ok' => false, 'message' => 'This product is not on Shopee yet, so there is nothing to update. Push it first.'];
        }

        /* THE GATE (#191, and the owner's Ready column, 2026-09-06): update_item
         * carries the parcel, the name and the description, and Shopee
         * refuses without them - knowable here, so no request is fired.
         * The same readiness the Ready column shows for a linked product. */
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

        /* A field the listing leaves blank follows its group, as on every
         * other push; the listing's own value wins. */
        $inherit = app(\Extensions\shopee\Services\Shopee\ShopeeInheritedSettings::class);
        $listing = $inherit->fill(
            ShopeeListing::query()->where('product_id', $productId)->first() ?? (new ShopeeListing())->forceFill(['product_id' => $productId]),
            $inherit->forProducts([$productId])[$productId] ?? null
        )['listing'];

        // The same merge rule as every push: the listing's own value when
        // set, the catalog's otherwise.
        $coreName = html_entity_decode((string) ($product->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $coreDescription = \App\Support\Catalog\DescriptionText::of((string) ($product->description ?? ''));

        /* The one reader, the same one the first push and the group push
         * use (the owner, 2026-09-13): the listing's own words win, and the
         * store's prefix and suffix templates wrap whichever won. This path
         * picked the words by hand and sent them bare, so a template chosen
         * on the listing page reached Shopee from every door but this one. */
        $content = \App\Integrations\Listings\ListingContent::of(
            $listing, $coreName, $coreDescription, 'shopee', (int) ($listing?->shopee_setting_id ?? 0)
        );
        $itemName = $content['title'];
        $description = $content['description'];

        $payload = [
            'item_id' => (int) $link->shopee_item_id,
            'item_name' => mb_substr($itemName, 0, 255),
            'description' => mb_substr(\App\Support\Catalog\DescriptionText::of($description), 0, 5000),
            'weight' => (float) (($listing?->weight) ?? $product->weight),
            'dimension' => [
                'package_length' => (int) (($listing?->package_length) ?? $product->length),
                'package_width' => (int) (($listing?->package_width) ?? $product->width),
                'package_height' => (int) (($listing?->package_height) ?? $product->height),
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
        /* Always sent. Shopee refuses an update without a brand block
         * ("Update failed: Brand information required", found by the owner on
         * QAS 2026-08-24) even though add_item treats it as optional-ish;
         * the listing's own brand goes, No Brand when it has none. */
        $payload['brand'] = app(ShopeeProductGroupController::class)
            ->buildBrandPayload((int) ($listing?->shopee_category_id ?? 0), (int) ($listing?->shopee_brand_id ?? 0));

        /* The pictures, in this listing's own order (critique 2026-09-08).
         * Until now this payload carried no image block at all, so the
         * arrangement made on the listing page went up when a GROUP pushed
         * the product and was silently dropped when the page's own Push
         * update ran - under a green flash saying it had gone. The group
         * path has always sent them; this is the same call.
         *
         * An upload that answers with nothing leaves the block out rather
         * than sending an empty list: Shopee reads an empty image_id_list
         * as "remove every picture", and a network hiccup must never strip
         * a live listing. */
        $create = app(\Extensions\shopee\Services\Shopee\ShopeeItemCreate::class);
        $up = $create->uploadImages($client, $auth, $create->catalogImagePaths($product, $listing));
        if ($up['error'] !== null) {
            /* A HALF-UPLOADED LIST IS WORSE THAN NO UPDATE. image_id_list
               replaces what the live item holds, so sending the three that
               got through would delete the other six from the listing. The
               whole update stands down and says why. */
            return ['ok' => false, 'message' => 'Update failed: ' . $up['error']];
        }
        if (!empty($up['ids'])) {
            $payload['image'] = ['image_id_list' => $up['ids']];
        }

        // The description goes in the item's own shape: plain, or Seller Center's extended list.
        $payload = \Extensions\shopee\Services\Shopee\ShopeeDescription::into(
            $payload, (string) $payload['description'],
            (new \Extensions\shopee\Services\Shopee\ShopeeDescription($client))->shapeOf($auth, (int) $link->shopee_item_id)
        );

        $path = '/api/v2/product/update_item';
        try {
            $result = $client->shopPost(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                $path, [], $payload
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Update failed: ' . \App\Support\TransportError::plain($e, 'Shopee')];
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
            return ['ok' => false, 'message' => 'Update failed: ' . $msg];
        }

        // Provenance (#104): what went, from where, when.
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

        // A content update can send the item back under Shopee review, so
        // the mirror is asked rather than assumed.
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

        return ['ok' => true, 'message' => 'Update pushed.'];
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

        /* THE PREFLIGHT (#191, the owner's own failure): Shopee refuses a
         * relist of an item without a parcel, and used to say so only
         * AFTER the call - then the next error, then the next. What is
         * knowable in advance is checked in advance; the flash names the
         * fields and where they live, and no request is fired. */
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
