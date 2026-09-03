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
use Extensions\shopee\Models\ShopeeUnmatchedItem;
use App\Services\ActivityLogger;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Extensions\shopee\Services\Shopee\ShopeeLiveListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

        /* ── The seller center's own tabs (quest 1 slice 2, 2026-08-24) ──
         *
         * Live / Unlisted / Violation / Under review, Shopee's own
         * item_status buckets. They were answered by Shopee on every page
         * load until 2026-08-28, when the owner reversed that: the calls had
         * become the cost of the page. They now read the MIRROR on the
         * link rows (see below); "All products" is the catalogue's own
         * view, whether pushed or not, and the strip names both. */
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
        /* No else: the default view is THE CATALOGUE (Phase C, #184).
         *
         * It used to be "products in a group, plus products already
         * linked" - the CedCommerce worldview, where a product only
         * existed for Shopee once a group claimed it. A groupless,
         * never-pushed product was invisible even under the not_listed
         * and ready filters (this whereIn intersected them away), which
         * for a new user meant their very first product rendered an
         * empty Shopee page. The strip already names the tab "Your
         * catalogue / All products"; now it tells the truth. Filtering
         * by a group still narrows to that group above. */

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
                    ->orWhere('p.sku', 'like', '%' . $q . '%');
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
            /* The reason to open this page. last_sync_ok is written on every
             * push and was readable in one column and filterable from
             * nowhere, so finding what failed among two thousand products
             * meant paging through forty screens looking for red. */
            $failedIds = ShopeeProductLink::query()
                ->where('last_sync_ok', false)
                ->whereNotNull('last_sync_ok')
                ->pluck('product_id')->unique()->toArray();

            if (empty($failedIds)) {
                $query->whereRaw('1=0');
            } else {
                $query->whereIn('p.product_id', $failedIds);
            }
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

        // A live tab narrows the table to the products whose Shopee items
        // hold that status right now. Only linked products can match: the
        // tab is a view of the shop, and an unpushed product is not in it.
        // The catalogue's own total, before a Shopee tab narrows the table:
        // the "All products" tab wears it so it reads as a total, not a status.
        $catalogueTotal = (clone $query)->count();

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
        $optionRowsByProductId = \App\Support\VariationRows::forProducts($pageIds);

        /* The mirror fills its own blanks (the owner, 2026-08-28: a product
         * pushed a minute ago should not read "Not checked yet"). A link
         * created by a push or a match has no status until someone asks;
         * the page asks once, for those rows only, then reads the mirror
         * like every other row. A shop whose links are all checked pays
         * nothing here. */
        if ($liveCounts !== null && $shopeeLinks->isNotEmpty()) {
            $filled = app(\Extensions\shopee\Services\Shopee\ShopeeLiveListing::class)
                ->fillBlanks($auth, $shopeeLinks->flatten());
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

        // Load unmatched items
        /* Orphan links (Phase F, #184): rows whose catalog product no
         * longer exists. The catalogue-first table cannot carry them and
         * hiding them would hide money possibly still sitting on Shopee -
         * the same queue Lazada's index has had since Phase C. */
        $orphanLinks = ShopeeProductLink::query()
            ->whereNotExists(function ($q) use ($pfx) {
                $q->select(DB::raw(1))
                    ->from($pfx . 'product as op')
                    ->whereColumn('op.product_id', 'shopee_product_links.product_id');
            })
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn ($l) => (int) $l->product_id);
        $orphanStates = app(\Extensions\shopee\Services\Shopee\ShopeeListingStates::class)
            ->forProducts($orphanLinks->keys()->map(fn ($v) => (int) $v)->all());

        $unmatchedItems = ShopeeUnmatchedItem::query()
            ->where('status', 'unmatched')
            ->orderBy('item_name')
            ->get();

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

        return view('ext-shopee::products.index', [
            'products' => $products,
            /* The relist preflight's page-side half (#191): a row whose
             * Relist would certainly be refused offers the fix instead. */
            'parcelGaps' => $this->parcelGapFor($products->pluck('product_id')->map(fn ($v) => (int) $v)->all()),
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
            'unmatchedItems' => $unmatchedItems,
            'orphanLinks' => $orphanLinks,
            'orphanStates' => $orphanStates,
            'optionRowsByProductId' => $optionRowsByProductId,
            'shopeeTab' => $shopeeTab,
            'liveCounts' => $liveCounts,
            'liveTabError' => $liveTabError,
            'liveStatuses' => $liveStatuses,
            'listingStates' => $listingStates,
            'catalogueTotal' => $catalogueTotal,
            'liveCheckedAt' => $liveCheckedAt,
        ]);
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

        $labels = ['NORMAL' => 'live', 'UNLIST' => 'unlisted', 'BANNED' => 'in violation', 'REVIEWING' => 'under review',
            'SELLER_DELETE' => 'deleted', 'SHOPEE_DELETE' => 'deleted by Shopee', 'MISSING' => 'not found on Shopee'];
        $parts = [];
        foreach ($result['counts'] as $status => $n) {
            $parts[] = number_format($n) . ' ' . ($labels[$status] ?? strtolower($status));
        }

        return redirect()->back()->with('status', 'Refreshed from Shopee: ' . ($parts ? implode(', ', $parts) : 'no linked items') . '.');
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
        $verb = $unlist ? 'Unlisted' : 'Relisted';

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
            }
            if ($links->isEmpty()) {
                return redirect()->back()->with('error',
                    'Nothing was relisted: Shopee will refuse ' . (count($heldBack) === 1 ? 'it' : 'all of them') . ' without a parcel. '
                    . implode('; ', array_slice($heldBack, 0, 5))
                    . (count($heldBack) > 5 ? '; and ' . (count($heldBack) - 5) . ' more' : '')
                    . '. Add weight and size on the catalog products first.');
            }
        }

        $result = $this->unlistItems($client, $auth, $links->pluck('shopee_item_id')->all(), $unlist);
        $okItems = array_flip($result['ok']);
        foreach ($links as $l) {
            if (isset($okItems[(int) $l->shopee_item_id])) {
                ActivityLogger::log(
                    $unlist ? 'unlisted' : 'relisted',
                    'Shopee Product',
                    (int) $l->product_id,
                    'Item ' . $l->shopee_item_id . ($unlist ? ' unlisted from' : ' relisted on') . ' Shopee (selection)'
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


    public function pushForm(Request $request, int $productId, ShopeeClient $client)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                  ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->where('p.product_id', $productId)
            ->first([
                'p.product_id', 'pd.name', 'pd.description', 'p.image',
                'p.model', 'p.sku', 'p.price', 'p.quantity',
                'p.weight', 'p.length', 'p.width', 'p.height',
                'p.manufacturer_id',
                'm.name as manufacturer_name',
            ]);

        if (!$product) {
            abort(404, 'Product not found.');
        }

        // Check product status by fetching it separately (not in the join query)
        $erpStatus = DB::table($pfx . 'product')->where('product_id', $productId)->value('status');
        if ((int) $erpStatus === 0) {
            return redirect()->route('ext.shopee.products.index')
                ->with('error', 'Cannot push a disabled product to Shopee. Enable it first.');
        }

        $product->name = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $existingLink = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if ($existingLink) {
            return redirect()->route('ext.shopee.products.index')
                ->with('error', 'Product is already listed on Shopee (Item ID: ' . $existingLink->shopee_item_id . ').');
        }

        $images = [];
        if (!empty($product->image)) {
            $images[] = $product->image;
        }
        $additionalImages = DB::table($pfx . 'product_image')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->pluck('image')
            ->toArray();
        $images = array_merge($images, $additionalImages);


        $logistics = $this->fetchLogistics();

        /* Prefill from the product's OWN listing record (#96/#97), never
         * from a borrowed group. The borrow used to take the FIRST group
         * whose filter matched, and with every live group's filters empty
         * that meant primary-key order chose the markup. A product with no
         * listing yet starts from the catalog values, visibly. */
        $listing = ShopeeListing::query()->where('product_id', $productId)->first();
        $groupLogisticIds = $listing ? ($listing->logistic_ids ?? []) : [];
        $groupCategoryId = $listing ? ($listing->shopee_category_id ?? null) : null;

        // Apply product group price markup (query params from Add Product page override group)
        $basePrice = (float) $product->price;
        $qMarkupPct = $request->query('markup_percent');
        $qMarkupFixed = $request->query('markup_fixed');
        $qCategoryId = $request->query('category_id');

        if ($qMarkupPct !== null || $qMarkupFixed !== null) {
            $markupPct = $qMarkupPct !== null ? (float) $qMarkupPct : 0;
            $markupFixed = $qMarkupFixed !== null ? (float) $qMarkupFixed : 0;
        } else {
            $markupPct = $listing ? (float) ($listing->markup_percent ?? 0) : 0;
            $markupFixed = $listing ? (float) ($listing->markup_fixed ?? 0) : 0;
        }
        $suggestedPrice = round($basePrice + ($basePrice * $markupPct / 100) + $markupFixed, 2);

        if ($qCategoryId !== null) {
            $groupCategoryId = (int) $qCategoryId ?: null;
        }

        /* The category's attribute sheet (#98, form half): a FIRST push can
         * answer what the category asks right here, instead of going up
         * bare and filling attributes on the listing afterwards. Same
         * template, same partial, same reload-on-category-change as the
         * listing page and the group form. */
        $formCategoryId = (int) ($groupCategoryId ?? 0);
        $groupController = app(ShopeeProductGroupController::class);
        if ($formCategoryId > 0) {
            $groupController->ensureTemplate($formCategoryId, app(ShopeeClient::class));
        }
        $sheet = $groupController->normalizedAttributesFor($formCategoryId);

        return view('ext-shopee::products.push', [
            'product' => $product,
            'images' => $images,
            'logistics' => $logistics,
            'groupLogisticIds' => $groupLogisticIds,
            'groupCategoryId' => $groupCategoryId,
            'groupName' => $listing ? 'this product\'s own listing' : null,
            'suggestedPrice' => $suggestedPrice,
            'markupPercent' => $markupPct != 0 ? $markupPct : null,
            'markupFixed' => $markupFixed != 0 ? $markupFixed : null,
            'attrTemplate' => $sheet['template'],
            'attrRows' => $sheet['attributes'],
            'savedAttributes' => $listing->attribute_values ?? [],
        ]);
    }

    public function push(Request $request, int $productId, ShopeeClient $client)
    {
        $request->validate([
            'item_name' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            // The rule (#150). original_price stays accepted for a caller
            // that states a final figure; the form no longer sends it.
            'markup_percent' => 'nullable|numeric|min:-100|max:1000',
            'markup_fixed' => 'nullable|numeric|min:-1000000|max:1000000',
            'original_price' => 'nullable|numeric|min:0.01',
            'normal_stock' => 'required|integer|min:0', // mapped to seller_stock in payload
            'item_sku' => 'nullable|string|max:100',
            'weight' => 'required|numeric|min:0.01',
            'package_length' => 'required|integer|min:1',
            'package_width' => 'required|integer|min:1',
            'package_height' => 'required|integer|min:1',
            'category_id' => 'required|integer|min:1',
            'logistic_ids' => 'required|array|min:1',
            'logistic_ids.*' => 'integer',
            'selected_images' => 'required|array|min:1',
            'selected_images.*' => 'string',
            'attributes' => 'nullable|array',
            'attributes.*' => 'nullable|string|max:500',
        ]);

        /* The price is computed HERE, from the rule, over the catalog price
         * at this moment (#150). A final figure is accepted only when no
         * rule was given, for the callers that still speak that way. */
        $pfxForPrice = (string) config('catalog.prefix');
        $corePrice = (float) DB::table($pfxForPrice . 'product')->where('product_id', $productId)->value('price');
        $ruleGiven = $request->filled('markup_percent') || $request->filled('markup_fixed');
        $markupPercent = $ruleGiven ? (float) $request->input('markup_percent', 0) : null;
        $markupFixed = $ruleGiven ? (float) $request->input('markup_fixed', 0) : null;
        if ($ruleGiven) {
            $pushedPrice = round($corePrice + ($corePrice * $markupPercent / 100) + $markupFixed, 2);
        } elseif ($request->filled('original_price')) {
            $pushedPrice = (float) $request->input('original_price');
        } else {
            $pushedPrice = round($corePrice, 2);
        }
        if ($pushedPrice <= 0) {
            return back()->withInput()->withErrors(['markup_fixed' => 'That rule prices the product at ' . \App\Support\Money::base($pushedPrice) . '. Shopee needs a price above zero.']);
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()
                ->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $existingLink = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if ($existingLink) {
            return redirect()->back()
                ->with('error', 'Product already listed on Shopee (Item ID: ' . $existingLink->shopee_item_id . ').');
        }

        // Skip disabled products
        $pfx = (string) config('catalog.prefix');
        $erpStatus = DB::table($pfx . 'product')->where('product_id', $productId)->value('status');
        if ((int) $erpStatus === 0) {
            return redirect()->back()
                ->with('error', 'Cannot push a disabled product to Shopee. Enable it first.');
        }

        // Step 1: Upload images
        $imageIds = [];
        foreach ($request->input('selected_images', []) as $imgPath) {
            $localPath = $this->resolveLocalImagePath($imgPath);
            if (!$localPath || !file_exists($localPath)) {
                continue;
            }

            $uploadResult = $this->uploadImage($client, $auth, $localPath);

            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.products.upload_image',
                'method' => 'POST',
                'api_path' => '/api/v2/media_space/upload_image',
                'auth_required' => true,
                'request_params' => ['image' => $imgPath],
                'response_status' => $uploadResult['status'] ?? null,
                'ok' => (bool) ($uploadResult['ok'] ?? false),
                'response_body' => $uploadResult['body'] ?? null,
                'user_id' => auth()->id(),
            ]);

            if (!($uploadResult['ok'] ?? false)) {
                $msg = is_array($uploadResult['body'] ?? null)
                    ? ($uploadResult['body']['message'] ?? ($uploadResult['body']['error'] ?? 'Upload failed'))
                    : 'Upload failed';
                return redirect()->route('ext.shopee.products.push', $productId)
                    ->with('error', 'Image upload failed for "' . basename($imgPath) . '": ' . $msg)
                    ->withInput();
            }

            $imageId = $uploadResult['body']['response']['image_info']['image_id'] ?? null;
            if ($imageId) {
                $imageIds[] = (string) $imageId;
            }
        }

        if (empty($imageIds)) {
            return redirect()->route('ext.shopee.products.push', $productId)
                ->with('error', 'No images were uploaded successfully.')
                ->withInput();
        }

        /* #192: what Shopee will knowably refuse about the variations is
         * checked BEFORE the item exists. A parent that goes up and then
         * loses its variations is a half-listed item selling the wrong
         * thing - the owner's #2062 - so a knowable refusal (the 5x price
         * ratio, the 50-variation cap) stops the push at the door. */
        $vPct = $ruleGiven ? (float) $markupPercent : 0.0;
        $vFixed = $ruleGiven ? (float) $markupFixed : round($pushedPrice - $corePrice, 2);
        $refusal = app(\Extensions\shopee\Services\Shopee\ShopeeVariationPush::class)
            ->refusalFor($productId, fn (float $core) => round($core + ($core * $vPct / 100) + $vFixed, 2));
        if ($refusal !== null) {
            return redirect()->route('ext.shopee.products.push', $productId)
                ->with('error', 'Not pushed: ' . $refusal)
                ->withInput();
        }

        // Step 2: Build add_item payload
        $logisticInfo = [];
        foreach ($request->input('logistic_ids', []) as $lid) {
            $logisticInfo[] = ['logistic_id' => (int) $lid, 'enabled' => true];
        }

        $payload = [
            'original_price' => $pushedPrice,
            'description' => (string) $request->input('description'),
            'item_name' => (string) $request->input('item_name'),
            'seller_stock' => [['stock' => (int) $request->input('normal_stock')]],
            'item_sku' => (string) ($request->input('item_sku') ?? ''),
            'weight' => (float) $request->input('weight'),
            'dimension' => [
                'package_length' => (int) $request->input('package_length'),
                'package_width' => (int) $request->input('package_width'),
                'package_height' => (int) $request->input('package_height'),
            ],
            'category_id' => (int) $request->input('category_id'),
            'image' => ['image_id_list' => $imageIds],
            'logistic_info' => $logisticInfo,
            'brand' => ['brand_id' => 0, 'original_brand_name' => 'No Brand'],
        ];

        /* The answers the operator just gave on THIS form (#98, form
         * half); anything already held on the listing fills the gaps, so
         * a re-push after unlink does not forget old answers. */
        $postedAttributes = array_filter(
            array_map(fn ($v) => trim((string) $v), (array) $request->input('attributes', [])),
            fn ($v) => $v !== ''
        );
        $existingListing = ShopeeListing::query()->where('product_id', $productId)->first();
        $mergedAttributes = $postedAttributes + ($existingListing?->attribute_values ?? []);
        $attributeList = app(ShopeeProductGroupController::class)
            ->attributeListFromSaved($mergedAttributes, (int) $request->input('category_id'));
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
            'pack' => 'shopee.products.add_item', 'method' => 'POST', 'api_path' => $path,
            'auth_required' => true, 'request_params' => $payload,
            'response_status' => $result['status'] ?? null, 'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        $body = $result['body'] ?? null;
        if (!($result['ok'] ?? false)) {
            $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'API call failed')) : 'API call failed';
            return redirect()->route('ext.shopee.products.push', $productId)
                ->with('error', 'Push failed: ' . $msg)->withInput();
        }

        $itemId = $body['response']['item_id'] ?? null;
        if (!$itemId) {
            return redirect()->route('ext.shopee.products.push', $productId)
                ->with('error', 'Shopee accepted the push but returned no item id, so the link could not be recorded. Run Check against Shopee to adopt the item it created.')->withInput();
        }

        ShopeeProductLink::create([
            'product_id' => $productId,
            'shopee_item_id' => (int) $itemId,
            'shopee_model_id' => null,
            'sku' => $request->input('item_sku') ?? '',
        ]);

        /* THE PUSH FORM IS WHERE A LISTING'S SETTINGS ARE BORN (#96/#97).
         * What the operator just chose - category, couriers, content - is
         * saved as the product's OWN listing record, so every later
         * one-click push and price sync uses these settings instead of
         * borrowing a group's by primary-key order.
         *
         * The markup rule: the form posts a final price, so the rule the
         * operator expressed is "this listing sells at core plus the
         * difference". Stored as a fixed markup; price is NEVER stored
         * (card #97), so a core price change moves this listing with it. */
        ShopeeListing::query()->updateOrCreate(
            ['product_id' => $productId],
            [
                'shopee_category_id' => (int) $request->input('category_id'),
                'logistic_ids' => array_map('intval', (array) $request->input('logistic_ids', [])),
                // The rule as stated; a final figure becomes a fixed markup.
                'markup_percent' => $ruleGiven ? $markupPercent : 0,
                'markup_fixed' => $ruleGiven ? $markupFixed : round($pushedPrice - $corePrice, 2),
                'item_name' => (string) $request->input('item_name'),
                'description' => (string) $request->input('description'),
                'attribute_values' => $mergedAttributes ?: null,
                'weight' => (float) $request->input('weight'),
                'package_length' => (int) $request->input('package_length'),
                'package_width' => (int) $request->input('package_width'),
                'package_height' => (int) $request->input('package_height'),
                'last_pushed_at' => now(),
                'last_push_source' => 'form',
                'last_push_settings' => [
                    'category_id' => (int) $request->input('category_id'),
                    'logistic_ids' => array_map('intval', (array) $request->input('logistic_ids', [])),
                    'price' => $pushedPrice,
                    'markup_percent' => $ruleGiven ? $markupPercent : 0,
                    'markup_fixed' => $ruleGiven ? $markupFixed : round($pushedPrice - $corePrice, 2),
                ],
            ]
        );

        // The mirror learns the pushed item's real status at once (it can
        // be NORMAL or REVIEWING; only Shopee knows which), so the row
        // never reads "Not checked yet" for a push made a minute ago.
        app(\Extensions\shopee\Services\Shopee\ShopeeLiveListing::class)->confirm($auth, [(int) $itemId]);

        // Variations ride the push: one Shopee model per combination,
        // priced by the rule the listing just stored.
        $savedListing = ShopeeListing::query()->where('product_id', $productId)->first();
        $variations = app(\Extensions\shopee\Services\Shopee\ShopeeVariationPush::class)->pushForProduct(
            $client, $auth, (int) $itemId, $productId,
            fn (float $core) => $savedListing->priceFor($core),
            $this->tierImageUploader($client, $auth)
        );

        if ($variations !== null && !$variations['ok']) {
            /* #192: half-listed is worse than unlisted. The item was just
             * created and its variations were refused, so it stands with
             * only the parent SKU - selling the wrong thing. Take it down
             * again and report ONE honest failure. */
            $undone = app(\Extensions\shopee\Services\Shopee\ShopeeVariationPush::class)
                ->undoCreate($client, $auth, $productId, (int) $itemId);

            return redirect()->route('ext.shopee.products.index')->with('error', $undone
                ? 'Not pushed: the variations were refused - ' . $variations['message']
                    . ' The item was removed from Shopee again, so nothing half-listed stays up.'
                : 'The variations were refused - ' . $variations['message']
                    . ' Shopee also refused to remove the half-created item (ID ' . $itemId . '). Delete it in Seller Centre, then push again.');
        }

        $flash = 'Product pushed to Shopee. Item ID: ' . $itemId . '. Its settings are saved on the listing for one-click pushes and price sync.';
        if ($variations !== null) {
            $flash .= ' ' . $variations['models'] . ' variations went with it.';
        }

        return redirect()->route('ext.shopee.products.index')->with('status', $flash);
    }

    // ── Push Direct (auto-populate from product + product group) ─────────

    public function pushDirect(int $productId, ShopeeClient $client)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Push failed: Missing Shopee {$modeLabel} settings.");
        }

        $existingLink = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if ($existingLink) {
            return redirect()->back()->with('error', 'Push failed: Product already listed on Shopee (Item ID: ' . $existingLink->shopee_item_id . ').');
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
            return redirect()->back()->with('error', 'Push failed: Product not found.');
        }

        if ((int) $product->status === 0) {
            return redirect()->back()->with('error', 'Push failed: Product is disabled. Enable it first.');
        }

        /* REFUSE INSTEAD OF BORROW (#96). This used to take the FIRST
         * product group whose filter matched; every live group's filters
         * are empty, so primary-key order chose the markup and nothing on
         * screen said which group priced the push. It succeeded at the
         * wrong price, silently. Now the one-click push runs only on the
         * product's OWN listing settings - born on the push form - and
         * says exactly what to do when there are none. */
        $listing = ShopeeListing::query()->where('product_id', $productId)->first();

        if (!$listing || !$listing->pushable()) {
            return redirect()->back()->with('error',
                'Push failed: this product has no Shopee listing settings yet. '
                . 'Push it once from the push form (its category, couriers and price rule are saved there), '
                . 'or push it from a preset whose settings you mean.');
        }

        $logisticIds = $listing->logistic_ids ?? [];

        // Validate required fields
        $itemName = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $description = trim(strip_tags(html_entity_decode($product->description ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (trim($itemName) === '' || trim($description) === '') {
            return redirect()->back()->with('error', 'Push failed: Product name or description is empty.');
        }
        if ((float) $product->price <= 0) {
            return redirect()->back()->with('error', 'Push failed: Product price must be greater than 0.');
        }
        if ((float) $product->weight <= 0) {
            return redirect()->back()->with('error', 'Push failed: Product weight must be greater than 0.');
        }
        $pkgLength = max(1, (int) $product->length);
        $pkgWidth = max(1, (int) $product->width);
        $pkgHeight = max(1, (int) $product->height);

        // Step 1: Upload images
        $imagePaths = [];
        if (!empty($product->image)) {
            $imagePaths[] = $product->image;
        }
        $additionalImages = DB::table($pfx . 'product_image')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->pluck('image')
            ->toArray();
        $imagePaths = array_merge($imagePaths, $additionalImages);

        if (empty($imagePaths)) {
            return redirect()->back()->with('error', 'Push failed: Product has no images.');
        }

        $imageIds = [];
        foreach ($imagePaths as $imgPath) {
            $localPath = $this->resolveLocalImagePath($imgPath);
            if (!$localPath || !file_exists($localPath)) {
                continue;
            }

            $uploadResult = $this->uploadImage($client, $auth, $localPath);

            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.products.upload_image',
                'method' => 'POST',
                'api_path' => '/api/v2/media_space/upload_image',
                'auth_required' => true,
                'request_params' => ['image' => $imgPath],
                'response_status' => $uploadResult['status'] ?? null,
                'ok' => (bool) ($uploadResult['ok'] ?? false),
                'response_body' => $uploadResult['body'] ?? null,
                'user_id' => auth()->id(),
            ]);

            if (!($uploadResult['ok'] ?? false)) {
                $msg = is_array($uploadResult['body'] ?? null)
                    ? ($uploadResult['body']['message'] ?? ($uploadResult['body']['error'] ?? 'Upload failed'))
                    : 'Upload failed';
                return redirect()->back()->with('error', 'Push failed: Image upload failed for "' . basename($imgPath) . '": ' . $msg);
            }

            $imageId = $uploadResult['body']['response']['image_info']['image_id'] ?? null;
            if ($imageId) {
                $imageIds[] = (string) $imageId;
            }
        }

        if (empty($imageIds)) {
            return redirect()->back()->with('error', 'Push failed: No images were uploaded successfully.');
        }

        // Step 2: Build add_item payload
        $logisticInfo = [];
        foreach ($logisticIds as $lid) {
            $logisticInfo[] = ['logistic_id' => (int) $lid, 'enabled' => true];
        }

        /* The listing's own price rule; price itself is never stored, so
         * this follows the catalog price wherever it goes. Content the
         * operator wrote on the listing overrides the catalog's; null
         * means never set, so the catalog value goes. SKU is always the
         * catalog's - reconcile matches by it (card #97). */
        /* #192: what Shopee will knowably refuse about the variations is
         * checked BEFORE the item exists. A parent that goes up and then
         * loses its variations is a half-listed item selling the wrong
         * thing - the owner's #2062 - so a knowable refusal (the 5x price
         * ratio, the 50-variation cap) stops the push at the door. */
        $refusal = app(\Extensions\shopee\Services\Shopee\ShopeeVariationPush::class)
            ->refusalFor($productId, fn (float $core) => $listing->priceFor($core));
        if ($refusal !== null) {
            return redirect()->back()->with('error', 'Not pushed: ' . $refusal);
        }

        $sellingPrice = $listing->priceFor((float) $product->price);

        $payload = [
            'original_price' => $sellingPrice,
            'description' => mb_substr(($listing->description !== null && trim($listing->description) !== '') ? $listing->description : $description, 0, 5000),
            'item_name' => mb_substr(($listing->item_name !== null && trim($listing->item_name) !== '') ? $listing->item_name : $itemName, 0, 255),
            'seller_stock' => [['stock' => max(0, (int) $product->quantity)]],
            'item_sku' => (string) ($product->sku ?? ''),
            'weight' => (float) ($listing->weight ?? $product->weight),
            'dimension' => [
                'package_length' => (int) ($listing->package_length ?? $pkgLength),
                'package_width' => (int) ($listing->package_width ?? $pkgWidth),
                'package_height' => (int) ($listing->package_height ?? $pkgHeight),
            ],
            'category_id' => (int) $listing->shopee_category_id,
            'image' => ['image_id_list' => $imageIds],
            'logistic_info' => $logisticInfo,
            'brand' => app(ShopeeProductGroupController::class)
                ->buildBrandPayload((int) $listing->shopee_category_id, (int) ($listing->shopee_brand_id ?? 0)),
        ];

        // The listing's answers to the category's attribute sheet (#98).
        $attributeList = app(ShopeeProductGroupController::class)
            ->attributeListFromSaved($listing->attribute_values ?? [], (int) $listing->shopee_category_id);
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
            'pack' => 'shopee.products.add_item.direct', 'method' => 'POST', 'api_path' => $path,
            'auth_required' => true, 'request_params' => $payload,
            'response_status' => $result['status'] ?? null, 'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        $body = $result['body'] ?? null;
        if (!($result['ok'] ?? false)) {
            $msg = is_array($body) ? ($body['message'] ?? ($body['error'] ?? 'API call failed')) : 'API call failed';
            return redirect()->back()->with('error', 'Push failed: ' . $msg);
        }

        $itemId = $body['response']['item_id'] ?? null;
        if (!$itemId) {
            return redirect()->back()->with('error', 'Shopee accepted the push but returned no item id, so the link could not be recorded. Run Check against Shopee to adopt the item it created.');
        }

        ShopeeProductLink::create([
            'product_id' => $productId,
            'shopee_item_id' => (int) $itemId,
            'shopee_model_id' => null,
            'sku' => $product->sku ?? '',
        ]);

        // Provenance (#104): what went, from where, when.
        $listing->forceFill([
            'last_pushed_at' => now(),
            'last_push_source' => 'listing',
            'last_push_settings' => [
                'category_id' => (int) $listing->shopee_category_id,
                'logistic_ids' => $logisticIds,
                'price' => $sellingPrice,
            ],
        ])->save();

        // Write-through: the mirror learns the item's real status now.
        app(\Extensions\shopee\Services\Shopee\ShopeeLiveListing::class)->confirm($auth, [(int) $itemId]);

        // Variations ride the push, priced by the listing's own rule.
        $variations = app(\Extensions\shopee\Services\Shopee\ShopeeVariationPush::class)->pushForProduct(
            $client, $auth, (int) $itemId, $productId,
            fn (float $core) => $listing->priceFor($core),
            $this->tierImageUploader($client, $auth)
        );

        if ($variations !== null && !$variations['ok']) {
            // #192: see push() - never leave a half-listed item standing.
            $undone = app(\Extensions\shopee\Services\Shopee\ShopeeVariationPush::class)
                ->undoCreate($client, $auth, $productId, (int) $itemId);

            return redirect()->back()->with('error', $undone
                ? 'Not pushed: the variations were refused - ' . $variations['message']
                    . ' The item was removed from Shopee again, so nothing half-listed stays up.'
                : 'The variations were refused - ' . $variations['message']
                    . ' Shopee also refused to remove the half-created item (ID ' . $itemId . '). Delete it in Seller Centre, then push again.');
        }

        $flash = 'Product pushed to Shopee. Item ID: ' . $itemId;
        if ($variations !== null) {
            $flash .= '. ' . $variations['models'] . ' variations went with it.';
        }

        return redirect()->back()->with('status', $flash);
    }

    // ── Bulk Push to Shopee ───────────────────────────────────────

    /**
     * The combined review form for a multi-select push from the index
     * (#151): one page for the whole selection, reviewing only what the
     * products can share, couriers, category and the price rule. Content
     * (title, description, images, attributes) stays each listing's own.
     * Blank means "each listing's own"; a value given here rides the whole
     * batch the way a group's settings ride a group push, and is recorded
     * as what was sent, never written onto the listings.
     */
    public function bulkPushForm(Request $request)
    {
        $productIds = array_values(array_unique(array_map('intval', array_filter((array) $request->query('product_ids', [])))));
        if (empty($productIds)) {
            return redirect()->route('ext.shopee.products.index')->with('error', 'Select at least one product first.');
        }
        $productIds = array_slice($productIds, 0, 200);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $products = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->whereIn('p.product_id', $productIds)
            ->get(['p.product_id', 'pd.name', 'p.sku', 'p.price', 'p.image'])
            ->keyBy('product_id');
        $listings = ShopeeListing::query()->whereIn('product_id', $productIds)->get()->keyBy('product_id');
        $linked = ShopeeProductLink::query()->whereIn('product_id', $productIds)->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $readiness = app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class)
            ->forProducts(array_values(array_diff($productIds, $linked)));

        $rows = [];
        foreach ($productIds as $pid) {
            $product = $products->get($pid);
            if (!$product) {
                continue;
            }
            $listing = $listings->get($pid);
            $rows[] = [
                'product_id' => $pid,
                'name' => (string) ($product->name ?? ''),
                'sku' => (string) ($product->sku ?? ''),
                'price' => (float) $product->price,
                'image' => trim((string) $product->image) !== '' ? asset('storage/' . ltrim((string) $product->image, '/')) : null,
                'listed' => in_array($pid, $linked, true),
                'ready' => in_array($pid, $linked, true) ? true : (bool) ($readiness[$pid]['ready'] ?? false),
                'missing' => $readiness[$pid]['missing'] ?? [],
                'category_id' => $listing?->shopee_category_id,
                'logistic_ids' => $listing?->logistic_ids ?? [],
                'markup_percent' => $listing?->markup_percent,
                'markup_fixed' => $listing?->markup_fixed,
            ];
        }

        /* Presets (Phase F, #184): the product GROUPS, read as reusable
         * settings. Loading one prefills the shared fields below and its
         * attribute defaults ride the push - the batch does everything a
         * group's Send does, from the workbench, and the group table is
         * the preset store: no new schema, no second vocabulary. */
        $presets = ShopeeProductGroup::query()->orderBy('name')->get(['id', 'name', 'shopee_category_id', 'logistic_ids', 'markup_percent', 'markup_fixed']);
        $preset = null;
        $presetId = (int) $request->query('preset', 0);
        if ($presetId > 0) {
            $preset = $presets->firstWhere('id', $presetId);
        }

        // The preset's category, by name, for the combo's visible input.
        $presetCategoryName = null;
        if ($preset && $preset->shopee_category_id) {
            $presetCategoryName = \Extensions\shopee\Models\ShopeeCategory::query()
                ->where('category_id', $preset->shopee_category_id)->value('name');
        }

        return view('ext-shopee::products.bulk-push', [
            'rows' => $rows,
            'logistics' => $this->fetchLogistics(),
            'presets' => $presets,
            'preset' => $preset,
            'presetCategoryName' => $presetCategoryName,
        ]);
    }

    public function bulkPushToShopee(Request $request, ShopeeClient $client)
    {
        $productIds = array_map('intval', array_filter($request->input('product_ids', [])));
        if (empty($productIds)) {
            return redirect()->back()->with('error', 'No products selected.');
        }

        /* The batch's shared settings from the combined review form (#151).
         * Each is optional; blank keeps every listing's own. */
        $request->validate([
            'shared_category_id' => 'nullable|integer|min:1',
            'shared_logistic_ids' => 'nullable|array',
            'shared_logistic_ids.*' => 'integer',
            'shared_markup_percent' => 'nullable|numeric|min:-100|max:1000',
            'shared_markup_fixed' => 'nullable|numeric|min:-1000000|max:1000000',
        ]);
        $request->validate([
            'preset_group_id' => 'nullable|integer|exists:shopee_product_groups,id',
            'preset_save_name' => 'nullable|string|max:100',
        ]);
        $sharedCategory = (int) $request->input('shared_category_id', 0) ?: null;
        $sharedLogistics = $request->filled('shared_logistic_ids') ? array_map('intval', (array) $request->input('shared_logistic_ids')) : null;
        $sharedRule = $request->filled('shared_markup_percent') || $request->filled('shared_markup_fixed');

        /* The loaded preset's attribute defaults ride the batch. Each
         * listing's OWN answers still win - the preset fills the gaps,
         * the same courtesy Lazada's sheet shows group defaults. */
        $presetAttrs = [];
        if ($request->filled('preset_group_id')) {
            $presetAttrs = \Extensions\shopee\Models\ShopeeProductGroupAttribute::query()
                ->where('shopee_product_group_id', (int) $request->input('preset_group_id'))
                ->pluck('value', 'attribute_key')
                ->toArray();
        }
        $sharedPercent = $sharedRule ? (float) $request->input('shared_markup_percent', 0) : null;
        $sharedFixed = $sharedRule ? (float) $request->input('shared_markup_fixed', 0) : null;
        $hasShared = $sharedCategory || $sharedLogistics || $sharedRule;

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} credentials. Configure Shopee settings first.");
        }

        /* REFUSE TO START rather than half-fail (#101): if any selected
         * product is not ready, nothing is pushed and the unready ones are
         * named with what they need. Already-listed selections are exempt
         * - the loop below skips them anyway. */
        $alreadyLinked = ShopeeProductLink::query()
            ->whereIn('product_id', $productIds)
            ->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $readiness = app(\Extensions\shopee\Services\Shopee\ShopeeListingReadiness::class)
            ->forProducts(array_values(array_diff($productIds, $alreadyLinked)), ['category_id' => $sharedCategory, 'logistic_ids' => $sharedLogistics]);
        $unready = array_filter($readiness, fn ($r) => !$r['ready']);
        if (!empty($unready)) {
            $lines = [];
            foreach (array_slice($unready, 0, 5, true) as $pid => $r) {
                $lines[] = "#{$pid} needs " . implode(', ', $r['missing']);
            }
            $summary = 'Nothing was pushed. ' . count($unready) . ' of ' . count($productIds)
                . ' selected ' . (count($unready) === 1 ? 'is' : 'are') . ' not ready: '
                . implode('; ', $lines)
                . (count($unready) > 5 ? '; and ' . (count($unready) - 5) . ' more' : '')
                . '. Untick them, or finish their listings first.';

            return redirect()->back()->with('error', $summary);
        }

        $okCount = 0;
        $skipCount = 0;
        $updatedCount = 0;
        $errCount = 0;
        $errors = [];

        foreach ($productIds as $productId) {
            /* SEND SEMANTICS (critique P1, 2026-08-25): already-listed
             * selections get a content UPDATE instead of a silent skip.
             * The skip-forever defect - a listing deleted in Seller Centre
             * keeps its link row and is skipped on every push - was
             * documented on the group page and fixed there; the index's
             * bulk now speaks the same honest verb. */
            if (ShopeeProductLink::query()->where('product_id', $productId)->exists()) {
                $u = $this->performListingUpdate((int) $productId, $client, $auth);
                if ($u['ok']) {
                    $updatedCount++;
                } else {
                    $errors[] = "#{$productId}: " . $u['message'];
                    $errCount++;
                }
                usleep(300000);
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
                    'p.manufacturer_id',
                ]);

            if (!$product || (int) $product->status === 0) {
                $skipCount++;
                continue;
            }

            /* Each product pushes on its OWN listing settings (#96) - a
             * bulk push must not borrow a group's either. A product without
             * them is named in the error line, not silently priced. */
            $listing = ShopeeListing::query()->where('product_id', $productId)->first();
            /* The record the push READS is the listing with the batch's
             * shared settings laid over it, in memory only; the record that
             * is SAVED below is the listing itself, so a shared setting is
             * sent and recorded as sent, never written onto the listing. */
            $saved = $listing ?: new ShopeeListing(['product_id' => $productId]);
            $listing = clone $saved;
            if ($sharedCategory) {
                $listing->shopee_category_id = $sharedCategory;
            }
            if ($sharedLogistics) {
                $listing->logistic_ids = $sharedLogistics;
            }
            if ($sharedRule) {
                $listing->markup_percent = $sharedPercent;
                $listing->markup_fixed = $sharedFixed;
            }
            if (!$listing->pushable()) {
                $errors[] = "#{$productId}: no listing settings - push it once from the push form, or from a preset";
                $errCount++;
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
            $imgFailed = false;
            foreach ($imagePaths as $imgPath) {
                $localPath = $this->resolveLocalImagePath($imgPath);
                if (!$localPath || !file_exists($localPath)) continue;

                $uploadResult = $this->uploadImage($client, $auth, $localPath);
                if ($uploadResult['ok'] ?? false) {
                    $imgId = $uploadResult['body']['response']['image_info']['image_id'] ?? null;
                    if ($imgId) $imageIds[] = (string) $imgId;
                } else {
                    $imgFailed = true;
                    break;
                }
            }

            if (empty($imageIds)) {
                $errors[] = "#{$productId}: Image upload failed";
                $errCount++;
                continue;
            }

            // Build payload from the listing's own settings and content
            $logisticInfo = [];
            foreach (($listing->logistic_ids ?? []) as $lid) {
                $logisticInfo[] = ['logistic_id' => (int) $lid, 'enabled' => true];
            }

            /* #192: knowable variation refusals stop this product before
             * its item exists; the batch moves on and the reason is named. */
            $refusal = app(\Extensions\shopee\Services\Shopee\ShopeeVariationPush::class)
                ->refusalFor($productId, fn (float $core) => $listing->priceFor($core));
            if ($refusal !== null) {
                $errors[] = "#{$productId}: not pushed - {$refusal}";
                $errCount++;
                continue;
            }

            $sellingPrice = $listing->priceFor((float) $product->price);

            $payload = [
                'original_price' => $sellingPrice,
                'description' => mb_substr(($listing->description !== null && trim($listing->description) !== '') ? $listing->description : $description, 0, 5000),
                'item_name' => mb_substr(($listing->item_name !== null && trim($listing->item_name) !== '') ? $listing->item_name : $itemName, 0, 255),
                'seller_stock' => [['stock' => max(0, (int) $product->quantity)]],
                'item_sku' => (string) ($product->sku ?? ''),
                'weight' => (float) ($listing->weight ?? $product->weight),
                'dimension' => [
                    'package_length' => (int) ($listing->package_length ?? max(1, (int) $product->length)),
                    'package_width' => (int) ($listing->package_width ?? max(1, (int) $product->width)),
                    'package_height' => (int) ($listing->package_height ?? max(1, (int) $product->height)),
                ],
                'category_id' => (int) $listing->shopee_category_id,
                'image' => ['image_id_list' => $imageIds],
                'logistic_info' => $logisticInfo,
                'brand' => app(ShopeeProductGroupController::class)
                    ->buildBrandPayload((int) $listing->shopee_category_id, (int) ($listing->shopee_brand_id ?? 0)),
            ];

            // The listing's attribute answers ride the bulk push too (#98).
            $attributeList = app(ShopeeProductGroupController::class)
                ->attributeListFromSaved(($listing->attribute_values ?? []) + $presetAttrs, (int) $listing->shopee_category_id);
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
                'pack' => 'shopee.products.add_item.bulk', 'method' => 'POST', 'api_path' => $path,
                'auth_required' => true, 'request_params' => $payload,
                'response_status' => $result['status'] ?? null, 'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
            ]);

            $body = $result['body'] ?? [];
            $itemId = ($result['ok'] ?? false) ? ($body['response']['item_id'] ?? null) : null;

            if ($itemId) {
                $confirmItemIds[] = (int) $itemId;
                ShopeeProductLink::create([
                    'product_id' => $productId,
                    'shopee_item_id' => (int) $itemId,
                    'shopee_model_id' => null,
                    'sku' => $product->sku ?? '',
                ]);
                // Provenance (#104): what was sent, and where it came from.
                $saved->forceFill([
                    'last_pushed_at' => now(),
                    'last_push_source' => $hasShared ? 'bulk' : 'listing',
                    'last_push_settings' => [
                        'category_id' => (int) $listing->shopee_category_id,
                        'logistic_ids' => $listing->logistic_ids ?? [],
                        'price' => $sellingPrice,
                        'markup_percent' => (float) ($listing->markup_percent ?? 0),
                        'markup_fixed' => (float) ($listing->markup_fixed ?? 0),
                    ],
                ])->save();
                // Variations ride the push, priced by the listing's rule.
                $variations = app(\Extensions\shopee\Services\Shopee\ShopeeVariationPush::class)->pushForProduct(
                    $client, $auth, (int) $itemId, $productId,
                    fn (float $core) => $listing->priceFor($core),
                    $this->tierImageUploader($client, $auth)
                );
                if ($variations !== null && !$variations['ok']) {
                    // #192: never leave a half-listed item standing.
                    $undone = app(\Extensions\shopee\Services\Shopee\ShopeeVariationPush::class)
                        ->undoCreate($client, $auth, $productId, (int) $itemId);
                    $errors[] = $undone
                        ? "#{$productId}: not pushed - its variations were refused (" . $variations['message'] . '); the item was removed from Shopee again'
                        : "#{$productId}: its variations were refused (" . $variations['message'] . ') and Shopee also refused to remove the half-created item ' . $itemId . ' - delete it in Seller Centre';
                    $errCount++;
                    usleep(300000);
                    continue;
                }
                $okCount++;
            } else {
                $msg = \App\Support\MarketplaceAnswer::plain('Shopee', ['ok' => false, 'body' => is_array($body) ? $body : []]);
                $errors[] = "#{$productId}: {$msg}";
                $errCount++;
            }

            usleep(300000); // 300ms rate limit between pushes
        }

        // Write-through, once for the whole batch (50 items per call):
        // every pushed row's mirror carries its real status immediately.
        app(\Extensions\shopee\Services\Shopee\ShopeeLiveListing::class)->confirm($auth, $confirmItemIds ?? []);

        /* Save-as-preset (Phase F): the shared settings just reviewed
         * become a reusable preset by name - created here, applied here,
         * never a page to go work in. */
        $presetNote = '';
        $saveName = trim((string) $request->input('preset_save_name', ''));
        if ($saveName !== '') {
            ShopeeProductGroup::updateOrCreate(
                ['name' => $saveName],
                [
                    'shopee_category_id' => $sharedCategory,
                    'logistic_ids' => $sharedLogistics,
                    'markup_percent' => $sharedRule ? $sharedPercent : null,
                    'markup_fixed' => $sharedRule ? $sharedFixed : null,
                ]
            );
            $presetNote = " Shared settings saved as preset '{$saveName}'.";
        }

        $summary = "Bulk Push: {$okCount} pushed";
        if ($updatedCount > 0) $summary .= ", {$updatedCount} already listed and updated in place";
        if ($skipCount > 0) $summary .= ", {$skipCount} skipped (disabled)";
        if ($errCount > 0) $summary .= ", {$errCount} failed";
        if (!empty($errors)) $summary .= '. Errors: ' . implode('; ', array_slice($errors, 0, 5));
        $summary .= $presetNote;

        return redirect()->back()->with($okCount > 0 ? 'status' : 'error', $summary);
    }

    /* syncProductIds ("Match Shopee IDs") lived here until Phase F (#184):
     * it could only fill in blank links by SKU, never question an id a row
     * already had. Check against Shopee (ShopeeLinkCheck) does both. */

    private function listingPriceFor(): callable
    {
        $cache = [];

        return function (int $productId, float $price) use (&$cache): ?float {
            if (!array_key_exists($productId, $cache)) {
                $cache[$productId] = ShopeeListing::query()->where('product_id', $productId)->first();
            }
            $listing = $cache[$productId];
            if (!$listing || ($listing->markup_percent === null && $listing->markup_fixed === null)) {
                return null;
            }

            return (float) $listing->priceFor($price);
        };
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
        $results = app(\Extensions\shopee\Services\Shopee\ShopeeStockPricePush::class)
            ->push('price', $auth, $links, $pfx, $this->listingPriceFor());
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
        $deleted = ShopeeProductLink::unlinkProduct($productId);

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

            ActivityLogger::log('deleted', 'Shopee Product', $productId, 'Deleted item ' . $itemId . ' from Shopee');

            return redirect()->back()->with('status', 'Product deleted from Shopee (item ' . $itemId . ').');
        }

        $errorMsg = $respError ?: $respMsg ?: 'Unknown error';
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
                $okCount++;
            } else {
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

    public function syncUnmatchedItems(ShopeeClient $client)
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->route('ext.shopee.products.index')->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $pfx = (string) config('catalog.prefix');

        // 1. Fetch all Shopee item IDs
        $allItemIds = $this->fetchAllShopeeItemIds($client, $auth, 730, 'shopee.products.sync_unmatched');
        if (empty($allItemIds)) {
            return redirect()->route('ext.shopee.products.index')->with('error', 'No items found on Shopee.');
        }

        // 2. Get all linked item+model combos from ShopeeProductLink
        $linkedCombos = ShopeeProductLink::query()->get(['shopee_item_id', 'shopee_model_id']);
        $linkedSet = [];
        foreach ($linkedCombos as $lc) {
            $key = (string) $lc->shopee_item_id . ':' . (string) ($lc->shopee_model_id ?? '');
            $linkedSet[$key] = true;
        }

        // 3. Build ERP SKU set for matching (case-insensitive)
        $erpSkus = [];
        // Product model + sku fields
        $erpProducts = DB::table($pfx . 'product')->whereNotNull('model')->where('model', '!=', '')->get(['model', 'sku']);
        foreach ($erpProducts as $ep) {
            if ($ep->model !== null && $ep->model !== '') $erpSkus[strtolower(trim($ep->model))] = true;
            if ($ep->sku !== null && $ep->sku !== '') $erpSkus[strtolower(trim($ep->sku))] = true;
        }
        // Option value SKUs
        $optSkus = DB::table($pfx . 'product_option_value')
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->pluck('sku');
        foreach ($optSkus as $s) {
            $erpSkus[strtolower(trim($s))] = true;
        }

        // 4. Clear old unmatched entries
        ShopeeUnmatchedItem::query()->where('status', 'unmatched')->delete();

        $unmatchedCount = 0;
        $matchedCount = 0;
        $totalScanned = 0;

        // 5. Fetch item info in chunks and check matches
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
                $totalScanned++;

                if ($hasModel) {
                    // Fetch models for this item
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
                        if (!$modelId) continue;

                        $linkKey = $itemId . ':' . $modelId;

                        // Already linked?
                        if (isset($linkedSet[$linkKey])) {
                            $matchedCount++;
                            continue;
                        }

                        // SKU matches ERP?
                        if ($modelSku !== '' && isset($erpSkus[strtolower($modelSku)])) {
                            $matchedCount++;
                            continue;
                        }

                        // Unmatched model
                        $unmatchedCount++;
                        ShopeeUnmatchedItem::updateOrCreate(
                            ['shopee_item_id' => (string) $itemId, 'shopee_model_id' => (string) $modelId],
                            [
                                'item_name' => $itemName . ($modelSku ? ' [' . $modelSku . ']' : ''),
                                'sku' => $modelSku,
                                'image_url' => $imageUrl,
                                'status' => 'unmatched',
                                'linked_product_id' => null,
                            ]
                        );
                    }
                } else {
                    // Simple item (no models)
                    $linkKey = $itemId . ':';

                    if (isset($linkedSet[$linkKey])) {
                        $matchedCount++;
                        continue;
                    }

                    // SKU matches ERP?
                    if ($itemSku !== '' && isset($erpSkus[strtolower($itemSku)])) {
                        $matchedCount++;
                        continue;
                    }

                    // Unmatched item
                    $unmatchedCount++;
                    ShopeeUnmatchedItem::updateOrCreate(
                        ['shopee_item_id' => (string) $itemId, 'shopee_model_id' => null],
                        [
                            'item_name' => $itemName,
                            'sku' => $itemSku,
                            'image_url' => $imageUrl,
                            'status' => 'unmatched',
                            'linked_product_id' => null,
                        ]
                    );
                }
            }
        }

        return redirect()->route('ext.shopee.products.index')
            ->with('status', "Unmatched scan complete. Shopee items scanned: {$totalScanned}. Already matched: {$matchedCount}. Unmatched: {$unmatchedCount}.");
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

        $productIds = $this->parseProductIds($request);
        if (empty($productIds)) {
            $productIds = ShopeeProductLink::query()->whereNotNull('shopee_item_id')
                ->distinct()->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        }
        if (empty($productIds)) {
            return redirect()->back()->with('error', 'Nothing is linked to Shopee yet, so there is nothing to check.');
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

        $results = app(\Extensions\shopee\Services\Shopee\ShopeeStockPricePush::class)
            ->push('price', $auth, $links, $pfx, $this->listingPriceFor());

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
    public function importUnmatched(int $unmatchedId, \Extensions\shopee\Services\Shopee\ShopeeItemImport $import)
    {
        $item = ShopeeUnmatchedItem::findOrFail($unmatchedId);

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->back()->with('error', "Missing Shopee {$modeLabel} settings.");
        }

        $r = $import->import($auth, (int) $item->shopee_item_id);

        return redirect()->back()->with($r['ok'] ? 'status' : 'error', $r['message']);
    }

    public function linkUnmatched(Request $request, int $unmatchedId)
    {
        $request->validate(['product_id' => 'required|integer|min:1']);

        $item = ShopeeUnmatchedItem::findOrFail($unmatchedId);
        $productId = (int) $request->input('product_id');

        $pfx = (string) config('catalog.prefix');
        $product = DB::table($pfx . 'product')->where('product_id', $productId)->first(['product_id']);
        if (!$product) {
            return redirect()->back()->with('error', 'Catalog product not found.');
        }

        // Create the link
        $exists = ShopeeProductLink::query()
            ->where('shopee_item_id', $item->shopee_item_id)
            ->where('shopee_model_id', $item->shopee_model_id)
            ->exists();

        if (!$exists) {
            ShopeeProductLink::create([
                'product_id' => $productId,
                'shopee_item_id' => $item->shopee_item_id,
                'shopee_model_id' => $item->shopee_model_id,
                'sku' => $item->sku ?? '',
            ]);
        }

        $item->update(['status' => 'linked', 'linked_product_id' => $productId]);

        return redirect()->back()
            ->with('status', 'Linked "' . ($item->item_name ?? 'Unknown') . '" to product #' . $productId . '.');
    }

    public function dismissUnmatched(int $unmatchedId)
    {
        $item = ShopeeUnmatchedItem::findOrFail($unmatchedId);
        $item->update(['status' => 'dismissed']);

        return redirect()->back()
            ->with('status', 'Dismissed "' . ($item->item_name ?? 'Unknown') . '".');
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
            ->get(['p.product_id', 'pd.name', 'p.model', 'p.sku', 'p.image']);

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
                'model' => $r->model ?? '',
                'sku' => $r->sku ?? '',
                'image' => $r->image ? asset('storage/' . ltrim($r->image, '/')) : null,
                'options' => $optionList,
            ];
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


    private function getGroupProductMapping(): array
    {
        $groups = ShopeeProductGroup::all();
        if ($groups->isEmpty()) return ['ids' => null, 'map' => []];

        $pfx = (string) config('catalog.prefix');
        $allIds = collect();
        $map = [];

        foreach ($groups as $group) {
            $catIds = $group->catalog_category_ids ?? [];
            $mfgIds = $group->manufacturer_ids ?? [];

            $q = DB::table($pfx . 'product as p')->select('p.product_id');

            if (!empty($catIds)) {
                $q->join($pfx . 'product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                    ->whereIn('ptc.category_id', $catIds);
            }
            if (!empty($mfgIds)) {
                $q->whereIn('p.manufacturer_id', $mfgIds);
            }

            $pids = $q->distinct()->pluck('p.product_id');
            $allIds = $allIds->merge($pids);

            foreach ($pids as $pid) {
                $map[(int) $pid][] = $group->name;
            }
        }

        return ['ids' => $allIds->unique()->values()->all(), 'map' => $map];
    }

    private function getProductIdsByGroup(int $groupId): array
    {
        $group = ShopeeProductGroup::find($groupId);
        if (!$group) return [];

        $pfx = (string) config('catalog.prefix');
        $catIds = $group->catalog_category_ids ?? [];
        $mfgIds = $group->manufacturer_ids ?? [];

        $q = DB::table($pfx . 'product as p')->select('p.product_id');

        if (!empty($catIds)) {
            $q->join($pfx . 'product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                ->whereIn('ptc.category_id', $catIds);
        }
        if (!empty($mfgIds)) {
            $q->whereIn('p.manufacturer_id', $mfgIds);
        }

        return $q->distinct()->pluck('p.product_id')->all();
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

    /**
     * A closure the variation push can use to turn a combination's local
     * image path into a Shopee image_id. Best effort by design: a missing
     * file or failed upload returns null and the option simply goes
     * without a picture.
     */
    private function tierImageUploader(ShopeeClient $client, array $auth): \Closure
    {
        return function (string $path) use ($client, $auth): ?string {
            $localPath = $this->resolveLocalImagePath($path);
            if (!$localPath || !file_exists($localPath)) {
                return null;
            }
            $result = $this->uploadImage($client, $auth, $localPath);
            $imageId = ($result['body']['response']['image_info']['image_id'] ?? null);

            return ($result['ok'] ?? false) && $imageId ? (string) $imageId : null;
        };
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
            }
        }


        /* The category's attribute sheet (#98): what Shopee's own form
         * would ask for this category, read from the marketplace once and
         * cached. Fetched here when never read, so the section appears the
         * first time an operator lands on a listing whose category we have
         * not seen - the mirrored form, not a hardcoded one. */
        $categoryId = (int) ($listing->shopee_category_id ?? 0);
        $groupController = app(ShopeeProductGroupController::class);
        if ($categoryId > 0) {
            $groupController->ensureTemplate($categoryId, app(ShopeeClient::class));
        }
        $sheet = $groupController->normalizedAttributesFor($categoryId);
        $savedAttributes = $listing->attribute_values ?? [];

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
        // Counted from the same SKU sources the pushes act on (combos
        // first, then option values), so this line and the push ledger can
        // never disagree about what "3 variations" means.
        $variationSkus = DB::table('product_option_combinations')
            ->where('product_id', $productId)->whereNotNull('sku')->where('sku', '!=', '')
            ->pluck('sku')
            ->merge(DB::table($pfx . 'product_option_value')
                ->where('product_id', $productId)->whereNotNull('sku')->where('sku', '!=', '')
                ->pluck('sku'))
            ->map(fn ($v) => trim((string) $v))->filter()->unique()->values();
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
        $linkedModelIds = $links->pluck('shopee_model_id')->filter()->map(fn ($v) => (int) $v)->flip();

        $initialBrands = $categoryId > 0
            ? \Extensions\shopee\Models\ShopeeBrand::query()
                ->where('category_id', $categoryId)
                ->orderBy('name')
                ->get(['brand_id', 'name'])
            : collect();

        return view('ext-shopee::listings.edit', [
            'listingState' => $listingState,
            'product' => $product,
            'listing' => $listing,
            'initialBrands' => $initialBrands,
            'link' => $link,
            'links' => $links,
            'coverage' => $coverage,
            'linkedModelIds' => $linkedModelIds,
            'live' => $live,
            'liveError' => $liveError,
            'attrTemplate' => $sheet['template'],
            'attrRows' => $sheet['attributes'],
            'savedAttributes' => $savedAttributes,
            'readinessMissing' => $readiness['missing'],
            'logistics' => $this->fetchLogistics(),
        ]);
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
            return redirect()->back()->with('error', 'Shopee did not answer: ' . $r['error'] . ' Nothing was changed.');
        }

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
            return redirect()->back()->with('error', 'Adding variations needs the listing\'s own price rule, and this listing has none. Push once from the form to give it one.');
        }

        $r = app(\Extensions\shopee\Services\Shopee\ShopeeVariationPush::class)->pushMissingModels(
            $client, $auth, (int) $link->shopee_item_id, $productId,
            fn (float $core) => (float) $listing->priceFor($core),
            $this->tierImageUploader($client, $auth)
        );

        if (!$r['ok']) {
            return redirect()->back()->with('error', $r['message']);
        }

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

    public function saveListing(Request $request, int $productId)
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
            'sale_percent' => 'nullable|numeric|min:1|max:99',
            'sale_starts_at' => 'nullable|date|required_with:sale_percent',
            'sale_ends_at' => 'nullable|date|required_with:sale_percent|after:sale_starts_at',
            'item_name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'weight' => 'nullable|numeric|min:0.01',
            'package_length' => 'nullable|integer|min:1|max:1000000',
            'package_width' => 'nullable|integer|min:1|max:1000000',
            'package_height' => 'nullable|integer|min:1|max:1000000',
            'attributes' => 'nullable|array',
            'attributes.*' => 'nullable|string|max:500',
        ]);

        /* Content fields are OVERRIDES: an emptied field returns to null,
         * which means "follows the catalog". That is the whole model -
         * saving here never writes the core product, and clearing a field
         * is how a divergence is deliberately ended. */
        $blank = fn ($v) => ($v === null || trim((string) $v) === '') ? null : $v;

        ShopeeListing::query()->updateOrCreate(
            ['product_id' => $productId],
            [
                'shopee_category_id' => $data['shopee_category_id'] ?? null,
                'shopee_brand_id' => ((int) ($data['shopee_brand_id'] ?? 0)) > 0 ? (int) $data['shopee_brand_id'] : null,
                'logistic_ids' => array_map('intval', $data['logistic_ids'] ?? []),
                'markup_percent' => $blank($data['markup_percent'] ?? null),
                'markup_fixed' => $blank($data['markup_fixed'] ?? null),
                // The discount RULE (#146): percent off with a window;
                // the promo price is computed at push time. Clearing the
                // percent clears the rule.
                'sale_percent' => $blank($data['sale_percent'] ?? null),
                'sale_starts_at' => ($data['sale_percent'] ?? null) !== null ? ($data['sale_starts_at'] ?? null) : null,
                'sale_ends_at' => ($data['sale_percent'] ?? null) !== null ? ($data['sale_ends_at'] ?? null) : null,
                'item_name' => $blank($data['item_name'] ?? null),
                'description' => $blank($data['description'] ?? null),
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
            ]
        );

        return redirect()->route('ext.shopee.listings.edit', $productId)
            ->with('status', 'Listing saved. These settings drive the next push and price sync.');
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
        $link = ShopeeProductLink::query()->where('product_id', $productId)->first();
        if (!$link) {
            return ['ok' => false, 'message' => 'This product is not on Shopee yet, so there is nothing to update. Push it first.'];
        }

        /* THE PREFLIGHT (#191): update_item carries the parcel, and Shopee
         * refuses without one - knowable here, so no request is fired. */
        if ($gap = $this->parcelGapFor([$productId])[$productId] ?? null) {
            return ['ok' => false, 'message' => 'Not updated: Shopee will refuse it without ' . $gap
                . '. Add them on the catalog product (Catalog → Products → edit), then push the update.'];
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

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();

        // The same merge rule as every push: the listing's own value when
        // set, the catalog's otherwise.
        $coreName = html_entity_decode((string) ($product->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $coreDescription = trim(strip_tags(html_entity_decode((string) ($product->description ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        $itemName = ($listing && $listing->item_name !== null && trim($listing->item_name) !== '') ? $listing->item_name : $coreName;
        $description = ($listing && $listing->description !== null && trim($listing->description) !== '') ? $listing->description : $coreDescription;

        $payload = [
            'item_id' => (int) $link->shopee_item_id,
            'item_name' => mb_substr($itemName, 0, 255),
            'description' => mb_substr($description, 0, 5000),
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

        return ['ok' => true, 'message' => 'Update pushed. Shopee now shows this listing\'s content. Price and stock were not touched - they have their own syncs.'];
    }

    /**
     * Push (or end) the listing's discount on Shopee (#146). Shopee wants
     * a NAMED promotion object with a server-side window, so VentaSync
     * manages one object per window and listings JOIN it - ten listings
     * on one sale share one object. The promo price is computed here from
     * the listing's own price rule, never stored.
     */
    public function pushListingDiscount(Request $request, int $productId, ShopeeClient $client)
    {
        $request->validate(['action' => 'required|in:push,end']);
        $ending = $request->input('action') === 'end';

        $links = ShopeeProductLink::query()->where('product_id', $productId)->get();
        $link = $links->first();
        if (!$link) {
            return redirect()->back()->with('error', 'This product is not on Shopee, so there is no price to discount.');
        }
        $itemId = (int) $link->shopee_item_id;

        $listing = ShopeeListing::query()->where('product_id', $productId)->first();

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            return redirect()->back()->with('error', 'Missing Shopee settings.');
        }

        if ($ending) {
            if (!$listing || !$listing->shopee_discount_id) {
                return redirect()->back()->with('error', 'No Shopee discount is recorded for this listing.');
            }

            $result = $this->shopeeDiscountCall($client, $auth, '/api/v2/discount/delete_discount_item', [
                'discount_id' => (int) $listing->shopee_discount_id,
                'item_id' => $itemId,
                'model_id' => 0,
            ]);
            $apiError = is_array($result['body'] ?? null) ? (string) ($result['body']['error'] ?? '') : 'no_body';
            if (!($result['ok'] ?? false) || $apiError !== '') {
                $msg = is_array($result['body'] ?? null)
                    ? (string) ($result['body']['message'] ?? ($result['body']['error'] ?? 'no response'))
                    : 'no response';
                return redirect()->back()->with('error', 'End discount failed: ' . $msg);
            }

            $listing->forceFill([
                'sale_percent' => null, 'sale_starts_at' => null,
                'sale_ends_at' => null, 'shopee_discount_id' => null,
            ])->save();

            return redirect()->back()->with('status', 'Discount ended. Buyers pay the full price again.');
        }

        if (!$listing || $listing->sale_percent === null || !$listing->sale_starts_at || !$listing->sale_ends_at) {
            return redirect()->back()->with('error', 'Set the discount first: a percent off, a start and an end. Save, then push it.');
        }
        if ($listing->sale_starts_at->lt(now()->addHour())) {
            return redirect()->back()->with('error', 'Shopee requires a discount to start at least an hour from now. Move the start later and save first.');
        }

        $pfx = (string) config('catalog.prefix');
        $corePrice = (float) DB::table($pfx . 'product')->where('product_id', $productId)->value('price');
        $pct = (float) $listing->sale_percent;

        /* One object per window, shared. Another listing already holding a
         * Shopee discount for this exact window donates its object;
         * otherwise a new one is created and named after the window. */
        $discountId = (int) (ShopeeListing::query()
            ->where('sale_starts_at', $listing->sale_starts_at)
            ->where('sale_ends_at', $listing->sale_ends_at)
            ->whereNotNull('shopee_discount_id')
            ->value('shopee_discount_id') ?? 0);

        if ($discountId <= 0) {
            $result = $this->shopeeDiscountCall($client, $auth, '/api/v2/discount/add_discount', [
                'discount_name' => mb_substr('VentaSync ' . $listing->sale_starts_at->format('M j') . ' to ' . $listing->sale_ends_at->format('M j'), 0, 25),
                'start_time' => $listing->sale_starts_at->timestamp,
                'end_time' => $listing->sale_ends_at->timestamp,
            ]);
            $apiError = is_array($result['body'] ?? null) ? (string) ($result['body']['error'] ?? '') : 'no_body';
            $discountId = (int) (($result['body']['response']['discount_id'] ?? 0));
            if (!($result['ok'] ?? false) || $apiError !== '' || $discountId <= 0) {
                $msg = is_array($result['body'] ?? null)
                    ? (string) ($result['body']['message'] ?? ($result['body']['error'] ?? 'no response'))
                    : 'no response';
                return redirect()->back()->with('error', 'Discount push failed creating the promotion: ' . $msg);
            }
        }

        // The item joins the object at its computed promo price(s): the
        // listing's own price rule, then the percent off.
        $modelLinks = $links->filter(fn ($l) => !empty($l->shopee_model_id));
        $item = ['item_id' => $itemId, 'purchase_limit' => 0];
        if ($modelLinks->isNotEmpty()) {
            $combosBySku = DB::table('product_option_combinations')
                ->where('product_id', $productId)
                ->get(['sku', 'absolute_price'])
                ->keyBy(fn ($c) => strtolower(trim((string) $c->sku)));
            $item['model_list'] = $modelLinks->map(function ($l) use ($combosBySku, $listing, $corePrice, $pct) {
                $combo = $combosBySku->get(strtolower(trim((string) $l->sku)));
                $base = $combo && (float) $combo->absolute_price > 0 ? (float) $combo->absolute_price : $corePrice;
                return [
                    'model_id' => (int) $l->shopee_model_id,
                    'model_promotion_price' => max(0.01, round($listing->priceFor($base) * (1 - $pct / 100), 2)),
                ];
            })->values()->all();
        } else {
            $item['item_promotion_price'] = max(0.01, round($listing->priceFor($corePrice) * (1 - $pct / 100), 2));
        }

        $result = $this->shopeeDiscountCall($client, $auth, '/api/v2/discount/add_discount_item', [
            'discount_id' => $discountId,
            'item_list' => [$item],
        ]);
        $apiError = is_array($result['body'] ?? null) ? (string) ($result['body']['error'] ?? '') : 'no_body';
        $itemErrors = $result['body']['response']['error_list'] ?? [];
        if (!($result['ok'] ?? false) || $apiError !== '' || !empty($itemErrors)) {
            $msg = !empty($itemErrors)
                ? (string) ($itemErrors[0]['fail_message'] ?? ($itemErrors[0]['fail_error'] ?? 'item rejected'))
                : (is_array($result['body'] ?? null)
                    ? (string) ($result['body']['message'] ?? ($result['body']['error'] ?? 'no response'))
                    : 'no response');
            return redirect()->back()->with('error', 'Discount push failed adding the item: ' . $msg);
        }

        $listing->forceFill(['shopee_discount_id' => $discountId])->save();

        return redirect()->back()->with('status',
            "Discount pushed: {$pct}% off from " . $listing->sale_starts_at->format('M j') . ' to ' . $listing->sale_ends_at->format('M j')
            . '. Shopee shows the cut price once the window opens.');
    }

    /** One signed discount call, logged. */
    private function shopeeDiscountCall(ShopeeClient $client, array $auth, string $path, array $body): array
    {
        try {
            $result = $client->shopPost(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                $path, [], $body
            );
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'status' => 0, 'body' => ['error' => 'network', 'message' => \App\Support\TransportError::plain($e, 'Shopee')]];
        }

        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.listings.discount', 'method' => 'POST',
            'api_path' => $path, 'auth_required' => true,
            'request_params' => $body,
            'response_status' => $result['status'] ?? null,
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        return $result;
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
            return redirect()->back()->with('error',
                'Not relisted: Shopee will refuse it without ' . $parcelGap
                . '. Add them on the catalog product (Catalog → Products → edit), then relist.');
        }

        $result = $this->unlistItems($client, $auth, [(int) $link->shopee_item_id], $unlist);
        if ($result['error'] !== null || $result['failed'] !== []) {
            $msg = $result['error'] ?? (string) reset($result['failed']);
            return redirect()->back()->with('error', ($unlist ? 'Unlist' : 'Relist') . ' failed: ' . $msg);
        }

        ActivityLogger::log(
            $unlist ? 'unlisted' : 'relisted',
            'Shopee Product',
            $productId,
            'Item ' . $link->shopee_item_id . ($unlist ? ' unlisted from' : ' relisted on') . ' Shopee'
        );

        return redirect()->back()->with('status', $unlist
            ? 'Unlisted. Buyers no longer see this item on Shopee.'
            : 'Relisted. The item is visible on Shopee again.');
    }

}
