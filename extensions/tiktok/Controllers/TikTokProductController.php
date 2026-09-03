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
        $manufacturerFilter = (string) $request->get('manufacturer', 'all');
        $groupFilter = (string) $request->get('group', 'all');
        $erpStatus = (string) $request->get('erp_status', 'all');
        $tiktokTab = (string) $request->get('tiktok_tab', 'all');
        if (!array_key_exists($tiktokTab, TikTokLiveListing::TABS)) {
            $tiktokTab = 'all';
        }

        /* The seller center's tabs, from the MIRROR on the listing rows
         * (live_status, live_checked_at), refreshed every 30 minutes by
         * the automation, on demand by the Refresh button, and written
         * through by every activate and deactivate the ERP makes. A page
         * view asks TikTok for nothing (the owner, 2026-08-28: the walk on
         * every click had become the cost of the page). The strip says
         * when the mirror was last refreshed. */
        $c = $this->creds();
        $liveTabError = null;
        $liveCounts = null;
        $liveCheckedAt = null;
        if ($c) {
            $liveCounts = TikTokListing::mirrorCounts(TikTokLiveListing::TABS);
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
            ->get(['tp.product_id', 'tg.name'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->pluck('name')->all());

        $query = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->select('p.product_id', 'pd.name', 'p.image', 'p.model', 'p.sku', 'p.price', 'p.quantity', 'p.status', 'm.name as manufacturer_name');
        /* No scope whereIn: the default view is THE CATALOGUE (Phase D,
         * #184), as on Shopee and Lazada since Phase C. It used to be
         * "products in a group, plus products already linked" - the
         * CedCommerce worldview, where a product only existed for TikTok
         * once a group claimed it, and a groupless never-pushed product
         * was invisible even to the not_listed filter. Filtering by a
         * group still narrows above. */

        if ($groupFilter !== 'all') {
            $inGroup = TikTokProductGroupProduct::query()->where('tiktok_product_group_id', (int) $groupFilter)->pluck('product_id')->all();
            $query->whereIn('p.product_id', $inGroup ?: [0]);
        }
        if ($manufacturerFilter !== 'all') {
            $query->where('p.manufacturer_id', (int) $manufacturerFilter);
        }
        if ($erpStatus === 'enabled') {
            $query->where('p.status', 1);
        } elseif ($erpStatus === 'disabled') {
            $query->where('p.status', 0);
        }
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.model', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%');
            });
        }
        if ($syncStatus === 'listed') {
            $query->whereIn('p.product_id', $listedProductIds ?: [0]);
        } elseif ($syncStatus === 'not_listed') {
            $query->whereNotIn('p.product_id', $listedProductIds ?: [0]);
        } elseif ($syncStatus === 'error') {
            $errored = TikTokProductGroupProduct::query()->where('sync_status', 'error')->pluck('product_id')->all();
            $query->whereIn('p.product_id', $errored ?: [0]);
        } elseif ($syncStatus === 'ready' || $syncStatus === 'not_ready') {
            $candidates = array_values(array_diff($scopeIds, $listedProductIds));
            $readinessAll = app(TikTokListingReadiness::class)->forProducts($candidates);
            $matching = array_keys(array_filter($readinessAll, fn ($r) => $syncStatus === 'ready' ? $r['ready'] : !$r['ready']));
            $query->whereIn('p.product_id', $matching ?: [0]);
        }

        // The catalogue's own total, before a TikTok tab narrows the table:
        // the "All products" tab wears it so it reads as a total, not a status.
        $catalogueTotal = (clone $query)->count();

        // A TikTok tab narrows to the products the mirror holds in that status.
        if ($tiktokTab !== 'all' && $liveCounts !== null) {
            $wanted = TikTokLiveListing::TABS[$tiktokTab][1];
            $tabProductIds = TikTokListing::query()->whereNotNull('tiktok_product_id')
                ->where('live_status', $wanted)->pluck('product_id')->all();
            $query->whereIn('p.product_id', $tabProductIds ?: [0]);
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
        $listingsByProductId = TikTokListing::query()->whereIn('product_id', $pageIds ?: [0])->get()->keyBy(fn ($l) => (int) $l->product_id);
        // Products pushed before the record carried the truth: the pivots answer.
        $pivotTruth = TikTokProductGroupProduct::query()
            ->whereIn('product_id', $pageIds ?: [0])->whereNotNull('tiktok_product_id')
            ->orderByDesc('last_pushed_at')->get()->groupBy(fn ($p) => (int) $p->product_id)->map(fn ($rows) => $rows->first());
        $lastAttempts = TikTokProductGroupProduct::query()
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
                        break; // TikTok is not answering; leave the rest blank rather than guessed
                    }
                    TikTokListing::query()->whereKey($l->id)->update(['live_status' => $status, 'live_checked_at' => now()]);
                    $l->live_status = $status;
                    $filled = true;
                }
                if ($filled) {
                    $liveCounts = TikTokListing::mirrorCounts(TikTokLiveListing::TABS);
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

        // How many catalogue products actually stand on TikTok Shop, for
        // the subtitle - listing rows plus pivot-only legacies, distinct.
        $listedTotal = count(array_unique(array_merge(
            TikTokListing::query()->whereNotNull('tiktok_product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->all(),
            TikTokProductGroupProduct::query()->whereNotNull('tiktok_product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->all()
        )));

        return view('ext-tiktok::products.index', [
            'products' => $products,
            'listingStates' => $listingStates,
            'listedTotal' => $listedTotal,
            'q' => $q, 'sort' => $sort, 'dir' => $dir,
            'syncStatus' => $syncStatus, 'manufacturerFilter' => $manufacturerFilter,
            'groupFilter' => $groupFilter, 'erpStatus' => $erpStatus,
            'allManufacturers' => DB::table($pfx . 'manufacturer')->orderBy('name')->pluck('name', 'manufacturer_id'),
            'allGroups' => TikTokProductGroup::query()->orderBy('name')->pluck('name', 'id'),
            'unmatchedItems' => \Extensions\tiktok\Models\TikTokUnmatchedItem::query()
                ->where('status', 'unmatched')->orderBy('item_name')->get(),
            'groupsByProductId' => $groupsByProductId,
            'optionRowsByProductId' => \App\Support\VariationRows::forProducts($pageIds),
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
        ]);
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

        // One word per state, the engine's (#184 D): 'unlisted', not 'deactivated'.
        $labels = ['ACTIVATE' => 'live', 'SELLER_DEACTIVATED' => 'unlisted', 'PLATFORM_DEACTIVATED' => 'deactivated by TikTok',
            'IN_REVIEW' => 'under review', 'PENDING' => 'under review', 'FAILED' => 'failed review', 'FREEZE' => 'frozen',
            'DRAFT' => 'draft', 'DELETED' => 'deleted', 'MISSING' => 'not found on TikTok Shop'];
        $parts = [];
        foreach ($result['counts'] as $status => $n) {
            $parts[] = number_format($n) . ' ' . ($labels[$status] ?? strtolower((string) $status));
        }

        return redirect()->back()->with('status', 'Refreshed from TikTok Shop: ' . ($parts ? implode(', ', $parts) : 'no linked products') . '.');
    }

    /** The one-click push on the product's OWN listing settings (#96). */
    public function pushDirect(int $productId, TikTokClient $client)
    {
        $c = $this->creds();
        if (!$c) {
            return redirect()->back()->with('error', 'Push failed: missing TikTok settings.');
        }
        if ($this->truthFor($productId)) {
            return redirect()->back()->with('error', 'Push failed: this product is already on TikTok Shop. Use Update on TikTok Shop for it.');
        }
        $readiness = app(TikTokListingReadiness::class)->forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => ['a TikTok category']];
        if (!$readiness['ready']) {
            return redirect()->back()->with('error', 'Push failed: this listing still needs ' . implode(', ', $readiness['missing']) . '. Set what is missing on its listing page and save.');
        }

        $outcome = app(TikTokProductPush::class)->create($this->erpProduct($productId), $this->listingPushOptions($productId, $c), $c, $client);
        if (!$outcome['ok']) {
            return redirect()->back()->with('error', 'Push failed: ' . $outcome['message']);
        }
        TikTokListing::recordPush($productId, $outcome['product_id'], $outcome['sku_ids'], 'listing');
        TikTokProductGroupProduct::query()->where('product_id', $productId)->update([
            'tiktok_product_id' => $outcome['product_id'], 'tiktok_sku_id' => $outcome['sku_ids'],
            'sync_status' => 'pushed', 'last_pushed_at' => now(), 'push_error' => null,
        ]);

        return redirect()->back()->with('status', 'Pushed to TikTok Shop as product ' . $outcome['product_id'] . '. It goes live once TikTok has reviewed it.');
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
                ->merge(\Extensions\tiktok\Models\TikTokProductGroupProduct::query()->whereNotNull('tiktok_product_id')->pluck('product_id'))
                ->map(fn ($v) => (int) $v)->unique()->sort()->values()->all();
        }
        if (empty($productIds)) {
            return redirect()->back()->with('error', 'Nothing is linked to TikTok Shop yet, so there is nothing to check.');
        }

        $r = $check->run($c, $productIds);

        return redirect()->back()->with($r['tone'], $r['summary']);
    }

    /**
     * Dress the selected products' listing records in a preset's shared
     * settings (#195): category and price rule, created where no record
     * exists yet. Then Send to TikTok Shop works, without one visit to
     * the Presets page.
     */
    /**
     * TikTok's import queue (#196): walk the shop once, keep what is
     * neither linked here nor matched by any catalog SKU - the same
     * scan Shopee and Lazada carry, in TikTok's anatomy.
     */
    /**
     * Catalog search for the import queue's Link picker (#196) - the
     * same JSON shape Shopee's picker reads, so the shared module works
     * unchanged.
     */
    public function searchCatalogProducts(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if ($q === '' || strlen($q) < 2) {
            return response()->json([]);
        }

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $results = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                  ->where('pd.language_id', '=', $langId);
            })
            ->where(function ($sub) use ($q) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.model', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%');
            })
            ->limit(20)
            ->get(['p.product_id', 'pd.name', 'p.model', 'p.sku', 'p.image']);

        return response()->json($results->map(fn ($r) => [
            'product_id' => (int) $r->product_id,
            'name' => (string) ($r->name ?? ''),
            'model' => (string) ($r->model ?? ''),
            'sku' => (string) ($r->sku ?? ''),
            'image' => (string) ($r->image ?? ''),
            'options' => [],
        ]));
    }

    public function syncUnmatchedItems(TikTokLiveListing $live)
    {
        $c = $this->creds();
        if (!$c) {
            return redirect()->back()->with('error', 'Missing TikTok settings.');
        }

        $shop = $live->shop($c);
        if ($shop['products'] === null) {
            return redirect()->back()->with('error', 'Scan failed: ' . $shop['error']);
        }

        $linked = TikTokListing::query()->whereNotNull('tiktok_product_id')->pluck('tiktok_product_id')
            ->merge(\Extensions\tiktok\Models\TikTokProductGroupProduct::query()->whereNotNull('tiktok_product_id')->pluck('tiktok_product_id'))
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

        \Extensions\tiktok\Models\TikTokUnmatchedItem::query()->where('status', 'unmatched')->delete();

        $found = 0;
        $scanned = 0;
        foreach ($shop['products'] as $ttId => $p) {
            $scanned++;
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
            foreach (($skus ?: [null]) as $sku) {
                \Extensions\tiktok\Models\TikTokUnmatchedItem::updateOrCreate(
                    ['tiktok_product_id' => (string) $ttId, 'sku' => $sku],
                    ['item_name' => (string) ($p['title'] ?? ''), 'status' => 'unmatched', 'linked_product_id' => null]
                );
                $found++;
            }
        }

        return redirect()->back()->with('status',
            "Scanned {$scanned} products on TikTok Shop: " . ($found > 0 ? "{$found} not in the catalog." : 'everything is matched or linked.'));
    }

    /** Import one queued product into the core catalog (#196). */
    public function importUnmatched(int $unmatchedId, \Extensions\tiktok\Services\TikTok\TikTokItemImport $import)
    {
        $item = \Extensions\tiktok\Models\TikTokUnmatchedItem::findOrFail($unmatchedId);
        $c = $this->creds();
        if (!$c) {
            return redirect()->back()->with('error', 'Missing TikTok settings.');
        }

        $r = $import->import($c, (string) $item->tiktok_product_id);

        return redirect()->back()->with($r['ok'] ? 'status' : 'error', $r['message']);
    }

    /** Link a queued product to a catalog product it already is. */
    public function linkUnmatched(Request $request, int $unmatchedId)
    {
        $request->validate(['product_id' => 'required|integer|min:1']);
        $item = \Extensions\tiktok\Models\TikTokUnmatchedItem::findOrFail($unmatchedId);
        $productId = (int) $request->input('product_id');

        $pfx = (string) config('catalog.prefix');
        if (! DB::table($pfx . 'product')->where('product_id', $productId)->exists()) {
            return redirect()->back()->with('error', 'Catalog product not found.');
        }

        TikTokListing::updateOrCreate(
            ['product_id' => $productId],
            ['tiktok_product_id' => (string) $item->tiktok_product_id, 'tiktok_sku_id' => null, 'live_status' => null, 'live_checked_at' => null]
        );
        \Extensions\tiktok\Models\TikTokUnmatchedItem::query()
            ->where('tiktok_product_id', $item->tiktok_product_id)
            ->update(['status' => 'linked', 'linked_product_id' => $productId]);

        return redirect()->back()->with('status', 'Linked "' . ($item->item_name ?: 'Unnamed product') . '" to catalog product #' . $productId . '.');
    }

    /** Stop tracking a queued product. */
    public function dismissUnmatched(int $unmatchedId)
    {
        $item = \Extensions\tiktok\Models\TikTokUnmatchedItem::findOrFail($unmatchedId);
        \Extensions\tiktok\Models\TikTokUnmatchedItem::query()
            ->where('tiktok_product_id', $item->tiktok_product_id)
            ->update(['status' => 'dismissed']);

        return redirect()->back()->with('status', 'Dismissed "' . ($item->item_name ?: 'Unnamed product') . '".');
    }

    public function bulkApplyPreset(Request $request)
    {
        $data = $request->validate([
            'preset_group_id' => 'required|integer|exists:tiktok_product_groups,id',
            'product_ids' => 'required|array|min:1',
        ]);
        $productIds = array_values(array_unique(array_filter(array_map('intval', (array) $data['product_ids']))));

        $preset = \Extensions\tiktok\Models\TikTokProductGroup::findOrFail((int) $data['preset_group_id']);

        foreach ($productIds as $pid) {
            $listing = TikTokListing::query()->firstOrNew(['product_id' => $pid]);
            $listing->forceFill(array_filter([
                'tiktok_category_id' => $preset->tiktok_category_id,
                'markup_percent' => $preset->markup_percent,
                'markup_fixed' => $preset->markup_fixed,
            ], fn ($v) => $v !== null))->save();
        }

        $applied = [];
        if ($preset->tiktok_category_id) $applied[] = 'category';
        if ($preset->markup_percent !== null || $preset->markup_fixed !== null) $applied[] = 'price rule';

        return redirect()->back()
            ->with('status', "Preset '{$preset->name}' applied to " . count($productIds) . ' product' . (count($productIds) === 1 ? '' : 's')
                . ($applied ? ' (' . implode(', ', $applied) . ')' : '')
                . '. Send or Push update carries these settings.');
    }

    public function bulkSend(Request $request, TikTokClient $client)
    {
        $productIds = array_map('intval', array_filter((array) $request->input('product_ids', [])));
        if (empty($productIds)) {
            return redirect()->back()->with('error', 'No products selected.');
        }
        $c = $this->creds();
        if (!$c) {
            return redirect()->back()->with('error', 'Missing TikTok settings.');
        }

        $truths = [];
        foreach ($productIds as $pid) {
            $truths[$pid] = $this->truthFor($pid);
        }
        $unlisted = array_values(array_filter($productIds, fn ($pid) => $truths[$pid] === null));
        $readiness = app(TikTokListingReadiness::class)->forProducts($unlisted);
        $unready = array_filter($readiness, fn ($r) => !$r['ready']);
        if (!empty($unready)) {
            $lines = [];
            foreach (array_slice($unready, 0, 5, true) as $pid => $r) {
                $lines[] = "#{$pid} needs " . implode(', ', $r['missing']);
            }

            return redirect()->back()->with('error',
                'Nothing was sent. ' . count($unready) . ' of ' . count($productIds) . ' selected '
                . (count($unready) === 1 ? 'is' : 'are') . ' not ready: ' . implode('; ', $lines)
                . (count($unready) > 5 ? '; and ' . (count($unready) - 5) . ' more' : '')
                . '. Untick them, or finish their listings first.');
        }

        $pusher = app(TikTokProductPush::class);
        $created = 0;
        $updated = 0;
        $errors = [];
        foreach ($productIds as $pid) {
            $erp = $this->erpProduct($pid);
            if (!$erp) {
                $errors[] = "#{$pid}: not in the catalog";
                continue;
            }
            $opts = $this->listingPushOptions($pid, $c);
            $truth = $truths[$pid];
            if ($truth) {
                $outcome = $pusher->edit($erp, (string) $truth->tiktok_product_id, $truth->tiktok_sku_id, $opts, $c, $client);
                if ($outcome['ok']) {
                    TikTokListing::recordPush($pid, (string) $truth->tiktok_product_id, $outcome['sku_ids'] ?? $truth->tiktok_sku_id, 'listing');
                    $updated++;
                } else {
                    $errors[] = "#{$pid}: " . $outcome['message'];
                }
            } else {
                $outcome = $pusher->create($erp, $opts, $c, $client);
                if ($outcome['ok']) {
                    TikTokListing::recordPush($pid, $outcome['product_id'], $outcome['sku_ids'], 'listing');
                    TikTokProductGroupProduct::query()->where('product_id', $pid)->update([
                        'tiktok_product_id' => $outcome['product_id'], 'tiktok_sku_id' => $outcome['sku_ids'],
                        'sync_status' => 'pushed', 'last_pushed_at' => now(), 'push_error' => null,
                    ]);
                    $created++;
                } else {
                    $errors[] = "#{$pid}: " . $outcome['message'];
                }
            }
            usleep(300000);
        }

        $summary = "Send: {$created} created, {$updated} already listed and updated in place";
        if ($errors) {
            $summary .= ', ' . count($errors) . ' failed (' . implode('; ', array_slice($errors, 0, 3)) . (count($errors) > 3 ? '; ...' : '') . ')';
        }

        return redirect()->back()->with($errors ? 'error' : 'status', $summary . '.');
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
        $results = app(\Extensions\tiktok\Services\TikTok\TikTokStockPricePush::class)->push(
            $what === 'price' ? 'price' : 'stock', $c, [$pseudo], (string) config('catalog.prefix'),
            fn (float $base, object $p) => $listing ? $listing->priceFor($base) : $base
        );
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
        TikTokProductGroupProduct::query()->where('product_id', $productId)->update(['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'sync_status' => 'unlinked']);

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
            return redirect()->back()->with('error', 'Delete failed: ' . $this->message($result));
        }
        // The link is gone; void the mirror rather than keep a status the
        // sweep can no longer correct (#184 D).
        TikTokListing::query()->where('product_id', $productId)->update(['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'live_status' => null, 'live_checked_at' => null]);
        TikTokProductGroupProduct::query()->where('product_id', $productId)->update(['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'sync_status' => 'pending']);

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
        $pivot = TikTokProductGroupProduct::query()->where('product_id', $productId)->whereNotNull('tiktok_product_id')->orderByDesc('last_pushed_at')->first();

        return $pivot ? (object) ['tiktok_product_id' => $pivot->tiktok_product_id, 'tiktok_sku_id' => $pivot->tiktok_sku_id] : null;
    }

    /** The push settings from the product's OWN listing record. */
    private function listingPushOptions(int $productId, array $c): array
    {
        $listing = TikTokListing::query()->where('product_id', $productId)->first();
        $service = app(TikTokAttributes::class);
        $template = $listing && $listing->tiktok_category_id
            ? $service->ensureTemplate((string) $listing->tiktok_category_id, app(TikTokClient::class), $c)
            : null;

        return [
            'category_id' => (string) ($listing->tiktok_category_id ?? ''),
            'brand_id' => $listing->brand_id ?? null,
            'attributes' => $service->payload($service->rows($template), $listing->attribute_values ?? []),
            'priceFor' => fn (float $base) => $listing ? $listing->priceFor($base) : $base,
            'warehouse_id' => $c['warehouse_id'] ?: null,
            'title' => $listing->title ?? null,
            'description' => $listing->description ?? null,
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

    private function creds(): ?array
    {
        $s = TikTokSetting::query()->first();
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
