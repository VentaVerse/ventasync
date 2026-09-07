<?php

namespace Extensions\venta\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\venta\Models\VentaListing;
use Extensions\venta\Models\VentaProductGroup;
use Extensions\venta\Models\VentaProductGroupProduct;
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaListingMirror;
use Extensions\venta\Services\Venta\VentaProductPush;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The per-product Venta surface (#171): one product on one store, the way
 * Shopee's and TikTok's listing pages show theirs. The live half is read
 * from the store on this load and written to the mirror; the record
 * beneath it (price rule, content on this store) is what the next push
 * from here sends. Saving never writes the catalogue product.
 *
 * Multi-store, like every Venta URL: the store is a path segment and the
 * listing record is one row per product per store.
 */
class VentaListingController extends Controller
{
    /**
     * The store's listings index: one row per catalogue product in scope
     * (any group on this store, plus anything already linked), the
     * store's own status from the mirror, and the row's verbs in its menu.
     * Same anatomy as Shopee's, Lazada's and TikTok's.
     */
    /**
     * Ask this store about the links the page believes in - the product group
     * page's check, on the workbench (#184's one-workbench rule). With
     * rows ticked it checks those; with none it checks every linked
     * product, 200 at a time.
     */
    public function checkAgainstVenta(Request $request, int $store, \Extensions\venta\Services\Venta\VentaLinkCheck $check)
    {
        $setting = VentaSetting::findOrFail($store);
        if (! $setting->enabled) {
            return redirect()->back()->with('error',
                'That Venta store is turned off, so nothing could be checked. Enable it in its settings first.');
        }

        $ids = array_values(array_unique(array_map('intval', array_filter((array) $request->input('product_ids', [])))));
        if (empty($ids)) {
            $ids = \Extensions\venta\Models\VentaProductLink::query()
                ->where('venta_setting_id', $store)
                ->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        }
        if (empty($ids)) {
            return redirect()->back()->with('error', 'Nothing is linked to this store yet, so there is nothing to check.');
        }

        $r = $check->run($setting, $ids);

        return redirect()->back()->with($r['tone'], $r['summary']);
    }

    /**
     * The Import page, stateless (the owner, 2026-09-04: "lets not even save
     * them"). Nothing about the store's items is persisted: the page is
     * blank until Fetch reads the store live, the rows exist only in the
     * rendered page, and Import / Link re-read the item by its SKU at the
     * moment they act. No queue tables, no dismissed state - what the
     * store holds is what you see, every time.
     */
    public function importPage(Request $request, int $store)
    {
        $setting = VentaSetting::findOrFail($store);

        $fetched = null;
        $fetchError = null;
        if ($request->boolean('fetch')) {
            [$fetched, $fetchError] = $this->fetchUnmatched($setting);
        }

        return view('ext-venta::products.import', [
            'setting' => $setting,
            'store' => $store,
            'fetched' => $fetched,
            'fetchError' => $fetchError,
        ]);
    }

    /**
     * Read the store live and keep only what the catalog cannot place -
     * not on a product, not on a variation, not a known model. Pure read:
     * the same diff the old scan ran, minus every write.
     *
     * @return array{0: ?array{found: int, rows: list<array>}, 1: ?string}
     */
    private function fetchUnmatched(VentaSetting $setting): array
    {
        if (! $setting->enabled) {
            return [null, 'That Venta store is turned off, so nothing was fetched.'];
        }

        $client = new \Extensions\venta\Services\Venta\VentaClient($setting);
        $known = $this->knownSkus();

        $found = 0;
        $rows = [];
        $page = 1;
        do {
            $answer = $client->get('products', ['per_page' => 100, 'page' => $page]);
            if (! ($answer['ok'] ?? false)) {
                return [null, 'Venta did not answer the fetch: '
                    . \App\Support\MarketplaceAnswer::plain('Venta', $answer)
                    . ($found > 0 ? " ({$found} items were read before it stopped.)" : '')];
            }
            $items = array_values((array) (($answer['body']['data'] ?? $answer['body']) ?: []));
            foreach ($items as $item) {
                $sku = trim((string) ($item['sku'] ?? ''));
                if ($sku === '') {
                    continue;
                }
                $found++;
                if (isset($known[strtolower($sku)])) {
                    continue;
                }
                $rows[] = [
                    'ref' => $sku,
                    'sku' => $sku,
                    'skus' => array_values(array_unique(array_filter(array_merge([$sku], array_map(fn ($v) => trim((string) ($v['sku'] ?? '')), is_array($item['variants'] ?? null) ? $item['variants'] : []))))),
                    'channel_id' => (int) ($item['id'] ?? 0) ?: null,
                    'name' => \Illuminate\Support\Str::limit((string) ($item['name'] ?? ''), 250, ''),
                    'image_url' => (string) (is_array($item['images'] ?? null)
                        ? (is_array(($item['images'][0] ?? null)) ? ($item['images'][0]['url'] ?? $item['images'][0]['src'] ?? '') : ($item['images'][0] ?? ''))
                        : ''),
                    'variants' => is_array($item['variants'] ?? null) ? count($item['variants']) : 0,
                ];
            }
            $page++;
        } while (count($items) === 100 && $page <= 100);

        return [['found' => $found, 'rows' => $rows], null];
    }

    /** Import one fetched item, re-read live by its SKU. */
    public function importOne(Request $request, int $store, \Extensions\venta\Services\Venta\VentaItemImport $import)
    {
        $data = $request->validate(['ref' => 'required|string|max:191']);
        $setting = VentaSetting::findOrFail($store);

        $r = $import->import($setting, $data['ref']);

        return redirect()->back()->with($r['ok'] ? 'status' : 'error', $r['message']);
    }

    /** Import selected: every ticked row, each in its own transaction. */
    public function importSelected(Request $request, int $store, \Extensions\venta\Services\Venta\VentaItemImport $import)
    {
        $data = $request->validate(['refs' => 'required|array|min:1', 'refs.*' => 'string|max:191']);
        $setting = VentaSetting::findOrFail($store);

        $done = 0;
        $failed = [];
        foreach (array_values(array_unique($data['refs'])) as $ref) {
            $r = $import->import($setting, $ref);
            if ($r['ok'] ?? false) {
                $done++;
            } else {
                $failed[] = $ref . ': ' . ($r['message'] ?? 'not imported');
            }
        }

        $line = \App\Support\BulkImportSummary::line($done, count(array_unique($data['refs'])), $failed);

        return redirect()->back()->with($line['tone'], $line['message']);
    }

    /** Link a fetched item to a catalog product it already is. */
    public function linkItem(Request $request, int $store)
    {
        VentaSetting::findOrFail($store);
        $data = $request->validate([
            'ref' => 'required|string|max:191',
            'channel_id' => 'nullable|integer',
            'product_id' => 'required|integer|min:1',
        ]);

        $pfx = (string) config('catalog.prefix');
        if (! DB::table($pfx . 'product')->where('product_id', (int) $data['product_id'])->exists()) {
            return redirect()->back()->with('error', 'That catalog product does not exist.');
        }

        /* One catalog product to one Venta product per store, both ways (the owner, 2026-09-07). */
        $ventaId = (int) ($data['channel_id'] ?? 0) ?: null;
        $held = VentaProductLink::query()->where('venta_setting_id', $store)->where('product_id', (int) $data['product_id'])->whereNotNull('venta_product_id')->first();
        if ($held && $ventaId !== null && (int) $held->venta_product_id !== $ventaId) {
            return redirect()->back()->with('error', 'Catalog product #' . $data['product_id'] . ' is already linked to Venta product ' . $held->venta_product_id . '. Unlink it first.');
        }
        if ($ventaId !== null) {
            $taken = VentaProductLink::query()->where('venta_setting_id', $store)->where('venta_product_id', $ventaId)->where('product_id', '!=', (int) $data['product_id'])->first();
            if ($taken) {
                return redirect()->back()->with('error', 'Venta product ' . $ventaId . ' is already linked to catalog product #' . $taken->product_id . '. Unlink that product first.');
            }
        }
        VentaProductLink::updateOrCreate(
            ['venta_setting_id' => $store, 'product_id' => (int) $data['product_id']],
            ['venta_product_id' => $ventaId, 'sku' => $data['ref']]
        );

        return redirect()->back()->with('status', 'Linked "' . $data['ref'] . '" to catalog product #' . $data['product_id'] . '.');
    }

    /** Every catalog SKU-ish identifier, lowercased, for the fetch diff. */
    private function knownSkus(): \Illuminate\Support\Collection
    {
        $pfx = (string) config('catalog.prefix');

        return collect()
            ->merge(DB::table($pfx . 'product')->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku'))
            ->merge(DB::table($pfx . 'product')->whereNotNull('model')->where('model', '!=', '')->pluck('model'))
            ->merge(DB::table($pfx . 'product_option_value')->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku'))
            ->merge(DB::table('product_option_combinations')->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku'))
            ->map(fn ($v) => strtolower(trim((string) $v)))
            ->flip();
    }

    /** The Link picker's catalog search: the shared shape, with the products this store already holds marked (App\Support\CatalogPickerSearch). */
    public function searchCatalog(Request $request, int $store)
    {
        VentaSetting::findOrFail($store);

        return response()->json(\App\Support\CatalogPickerSearch::items((string) $request->query('q', ''), function (array $ids) use ($store) {
            return VentaProductLink::query()->where('venta_setting_id', $store)->whereIn('product_id', $ids)->whereNotNull('venta_product_id')
                ->get(['product_id', 'venta_product_id'])
                ->mapWithKeys(fn ($l) => [(int) $l->product_id => 'Venta product ' . $l->venta_product_id])->all();
        }));
    }

    public function index(Request $request, int $store)
    {
        $setting = VentaSetting::findOrFail($store);
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $q = trim((string) $request->get('q', ''));
        $groupFilter = (string) $request->get('group', 'all');
        $erpStatus = (string) $request->get('erp_status', 'all');
        $syncStatus = (string) $request->get('sync_status', 'all');
        $tab = (string) $request->get('venta_tab', 'all');
        if (! array_key_exists($tab, VentaListingMirror::TABS)) {
            $tab = 'all';
        }

        $sortColumns = ['id' => 'p.product_id', 'product' => 'pd.name', 'quantity' => 'p.quantity', 'price' => 'p.price'];
        $sort = array_key_exists((string) $request->get('sort', 'id'), $sortColumns) ? (string) $request->get('sort', 'id') : 'id';
        $dir = strtolower((string) $request->get('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $mirror = VentaListingMirror::for($setting);
        $liveCounts = $mirror->counts();
        $liveCheckedAt = $mirror->checkedAt();

        // Scope: every product a group on this store holds, plus anything
        // already linked to this store.
        $groupIds = VentaProductGroup::where('venta_setting_id', $store)->pluck('id')->all();
        $pivotProductIds = VentaProductGroupProduct::query()->whereIn('venta_product_group_id', $groupIds ?: [0])
            ->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->all();
        $linkedProductIds = VentaProductLink::where('venta_setting_id', $store)->whereNotNull('venta_product_id')
            ->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        // "This store's list" (#201): a group's products, anything linked, and
        // anything Add products brought in (a listing row), one query shape
        // shared with the slide-over and the state engine.
        $scopeIds = array_values(array_unique(array_merge($pivotProductIds, $linkedProductIds, \Extensions\venta\Services\Venta\VentaStoreProducts::ids($store))));

        $groupsByProductId = DB::table('venta_product_group_products as gp')
            ->join('venta_product_groups as g', 'g.id', '=', 'gp.venta_product_group_id')
            ->where('g.venta_setting_id', $store)
            ->get(['gp.product_id', 'g.name'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->pluck('name')->all());

        $query = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->whereIn('p.product_id', $scopeIds ?: [0])
            ->select('p.product_id', 'pd.name', 'p.image', 'p.model', 'p.sku', 'p.price', 'p.quantity', 'p.status', 'm.name as manufacturer_name');

        if ($groupFilter !== 'all') {
            $inGroup = VentaProductGroupProduct::query()->where('venta_product_group_id', (int) $groupFilter)->pluck('product_id')->all();
            $query->whereIn('p.product_id', $inGroup ?: [0]);
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
            $query->whereIn('p.product_id', $linkedProductIds ?: [0]);
        } elseif ($syncStatus === 'not_listed') {
            $query->whereNotIn('p.product_id', $linkedProductIds ?: [0]);
        } elseif ($syncStatus === 'error') {
            $errored = VentaProductGroupProduct::query()->whereIn('venta_product_group_id', $groupIds ?: [0])
                ->whereIn('sync_status', ['error', 'failed'])->pluck('product_id')->all();
            $query->whereIn('p.product_id', $errored ?: [0]);
        }

        // The catalogue's own total, before a store tab narrows the table.
        $catalogueTotal = (clone $query)->count();

        if ($tab !== 'all') {
            $wanted = VentaListingMirror::TABS[$tab][1];
            $tabIds = VentaListing::query()->where('venta_setting_id', $store)
                ->whereIn('product_id', $linkedProductIds ?: [0])
                ->where('live_status', $wanted)->pluck('product_id')->all();
            $query->whereIn('p.product_id', $tabIds ?: [0]);
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
        $linksByProductId = VentaProductLink::where('venta_setting_id', $store)->whereIn('product_id', $pageIds ?: [0])
            ->whereNotNull('venta_product_id')->get()->keyBy(fn ($l) => (int) $l->product_id);

        // The mirror fills its own blanks (#166): linked rows never
        // checked are asked about once, up to ten a view.
        if ($setting->enabled && $linksByProductId->isNotEmpty() && $mirror->fillBlanks($linksByProductId) > 0) {
            $liveCounts = $mirror->counts();
            $liveCheckedAt = $mirror->checkedAt();
        }

        $listingsByProductId = VentaListing::query()->where('venta_setting_id', $store)
            ->whereIn('product_id', $pageIds ?: [0])->get()->keyBy(fn ($l) => (int) $l->product_id);
        $lastAttempts = VentaProductGroupProduct::query()
            ->whereIn('venta_product_group_id', $groupIds ?: [0])
            ->whereIn('product_id', $pageIds ?: [0])
            ->orderByDesc('last_pushed_at')->get()
            ->groupBy(fn ($p) => (int) $p->product_id)->map(fn ($rows) => $rows->first());

        return view('ext-venta::listings.index', [
            'setting' => $setting,
            'products' => $products,
            'q' => $q, 'sort' => $sort, 'dir' => $dir,
            'groupFilter' => $groupFilter, 'erpStatus' => $erpStatus, 'syncStatus' => $syncStatus,
            'allGroups' => VentaProductGroup::where('venta_setting_id', $store)->orderBy('name')->pluck('name', 'id'),
            'groupsByProductId' => $groupsByProductId,
            'optionRowsByProductId' => \App\Support\VariationRows::forProducts($pageIds),
            'linksByProductId' => $linksByProductId,
            'listingsByProductId' => $listingsByProductId,
            'lastAttempts' => $lastAttempts,
            'listingStates' => \Extensions\venta\Services\Venta\VentaListingStates::for($store)->forProducts($pageIds),
            'lastSync' => \Extensions\venta\Services\Venta\VentaListingStates::for($store)->lastSync($pageIds),
            'ventaTab' => $tab,
            'liveCounts' => $liveCounts,
            'liveCheckedAt' => $liveCheckedAt,
            'catalogueTotal' => $catalogueTotal,
        ]);
    }

    // ── Add products slide-over (#201, on Venta since 2026-09-06) ──────────

    /** The slide-over's search: the catalog, saying what is already on this store. */
    public function catalogueSearch(Request $request, int $store)
    {
        VentaSetting::findOrFail($store);
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->query('q', ''));
        $showAll = $request->boolean('all');
        $limit = 25;
        $query = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', fn ($j) => $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId))
            ->leftJoinSub(\Extensions\venta\Services\Venta\VentaStoreProducts::query($store), 'os', 'os.product_id', '=', 'p.product_id')
            ->select('p.product_id', 'pd.name', 'p.image', 'p.sku', 'p.model', 'p.quantity', 'p.price', 'os.product_id as on_store_pid')
            ->where('p.status', 1);
        if ($q !== '') {
            $query->where(fn ($sub) => $sub->where('pd.name', 'like', '%' . $q . '%')->orWhere('p.model', 'like', '%' . $q . '%')->orWhere('p.sku', 'like', '%' . $q . '%'));
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
            'thumb' => trim((string) ($r->image ?? '')) !== '' ? asset('storage/' . ltrim((string) $r->image, '/')) : null,
            'on_store' => ! is_null($r->on_store_pid),
        ])->values();

        return response()->json(['total' => $total, 'shown' => $items->count(), 'limit' => $limit, 'items' => $items]);
    }

    /** Add one product to this store's list: a listing row, nothing sent. */
    public function addToStore(Request $request, int $store, int $productId)
    {
        VentaSetting::findOrFail($store);
        abort_unless(DB::table((string) config('catalog.prefix') . 'product')->where('product_id', $productId)->exists(), 404);
        $wasOn = \Extensions\venta\Services\Venta\VentaStoreProducts::has($store, $productId);
        VentaListing::query()->firstOrCreate(['venta_setting_id' => $store, 'product_id' => $productId]);
        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'product_id' => $productId, 'added' => ! $wasOn]);
        }

        return redirect()->back()->with('status', 'Added to this store. Configure and push it from Listings whenever you are ready.');
    }

    /** Add several at once. */
    public function addToStoreBulk(Request $request, int $store)
    {
        VentaSetting::findOrFail($store);
        $data = $request->validate(['product_ids' => ['required', 'array'], 'product_ids.*' => ['integer']]);
        $valid = DB::table((string) config('catalog.prefix') . 'product')->whereIn('product_id', array_map('intval', $data['product_ids']))->pluck('product_id')->all();
        $added = 0;
        foreach ($valid as $pid) {
            $wasOn = \Extensions\venta\Services\Venta\VentaStoreProducts::has($store, (int) $pid);
            VentaListing::query()->firstOrCreate(['venta_setting_id' => $store, 'product_id' => (int) $pid]);
            $added += $wasOn ? 0 : 1;
        }

        return redirect()->back()->with('status', number_format($added) . ' ' . \Illuminate\Support\Str::plural('product', $added) . ' added to this store.');
    }

    /**
     * Remove a product from this store's list: its listing row, its link
     * and its place in the store's groups go; the store itself is never
     * touched (the owner, 2026-09-05: "remove from store" is free).
     */
    public function removeFromStore(int $store, int $productId)
    {
        VentaSetting::findOrFail($store);
        abort_unless(\Extensions\venta\Services\Venta\VentaStoreProducts::has($store, $productId), 404);
        $ventaIds = VentaProductLink::query()->where('venta_setting_id', $store)->where('product_id', $productId)->pluck('venta_product_id')->filter()->unique();
        VentaProductGroupProduct::query()->where('product_id', $productId)
            ->whereIn('venta_product_group_id', VentaProductGroup::query()->where('venta_setting_id', $store)->select('id'))->delete();
        VentaListing::query()->where('venta_setting_id', $store)->where('product_id', $productId)->delete();
        VentaProductLink::query()->where('venta_setting_id', $store)->where('product_id', $productId)->delete();

        return redirect()->back()->with('status', "Removed product #{$productId} from this store." . ($ventaIds->isNotEmpty()
            ? ' It no longer syncs; the store product ' . $ventaIds->implode(', ') . ' stays up until you remove it there.'
            : ' It no longer syncs.'));
    }

    /** The Refresh button: one walk of the store's product list onto the mirror. */
    public function refreshStatus(int $store)
    {
        $setting = VentaSetting::where('id', $store)->where('enabled', true)->first();
        if (! $setting) {
            return redirect()->back()->with('error', 'That Venta store is turned off, so it was not asked.');
        }

        $result = VentaListingMirror::for($setting)->refresh();
        if ($result['error'] !== null) {
            return redirect()->back()->with('error', $result['error'] . ' The statuses shown are from the last refresh.');
        }
        /* Refresh is the one button (the owner, 2026-09-07): every status is
         * re-read first, then the links are checked against the store, the
         * old Repair links folded in. */
        $linked = VentaProductLink::query()->where('venta_setting_id', $store)->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        // The check reads two hundred per run; the list starts after the last one checked, so "run it again" continues.
        $linked = \App\Integrations\Support\LinkCheckCursor::rotate('venta:' . (int) $store, $linked);
        $checked = $linked !== [] ? app(\Extensions\venta\Services\Venta\VentaLinkCheck::class)->run($setting, $linked) : null;

        $parts = [];
        foreach ($result['counts'] as $status => $n) {
            $parts[] = number_format($n) . ' ' . strtolower(VentaListingMirror::STATUS_MAP[$status][0] ?? $status);
        }

        return redirect()->back()->with('status', 'Refreshed from ' . $setting->store_name . ': ' . ($parts ? implode(', ', $parts) : 'no linked products') . '.'
            . ($checked ? ' ' . $checked['summary'] : ''));
    }

    /**
     * The selection bar's Unlist / Relist: the row's call, for many rows.
     * The storefront has no bulk status endpoint, so this is one PUT per
     * product, written through to the mirror as each one succeeds.
     */
    // ── The Listings page's missing verbs (parity, 2026-09-07) ──────────

    /** Push stock for one row. */
    public function pushStock(int $store, int $productId)
    {
        return $this->figures($store, [$productId], true, false, 'Stock');
    }

    /** Push price for one row. */
    public function pushPrice(int $store, int $productId)
    {
        return $this->figures($store, [$productId], false, true, 'Price');
    }

    public function bulkPushStock(Request $request, int $store)
    {
        return $this->figures($store, $this->tickedIds($request), true, false, 'Stock');
    }

    public function bulkPushPrices(Request $request, int $store)
    {
        return $this->figures($store, $this->tickedIds($request), false, true, 'Price');
    }

    private function figures(int $store, array $ids, bool $stock, bool $price, string $what)
    {
        $setting = VentaSetting::where('id', $store)->where('enabled', true)->first();
        if (! $setting) {
            return redirect()->back()->with('error', 'That Venta store is turned off, so nothing was sent to it.');
        }
        if ($ids === []) {
            return redirect()->back()->with('error', 'No products selected.');
        }
        $r = \Extensions\venta\Services\Venta\VentaStockPricePush::for($setting)->push($ids, $stock, $price);
        foreach ($r['errors'] as $pid => $text) {
            VentaListing::query()->updateOrCreate(['venta_setting_id' => $store, 'product_id' => (int) $pid], [])
                ->forceFill(['last_push_error' => Str::limit($text, 480), 'last_push_failed_at' => now()])->save();
        }

        return redirect()->back()->with($r['failed'] > 0 ? ($r['ok'] > 0 ? 'warning' : 'error') : 'status',
            \Extensions\venta\Services\Venta\VentaStockPricePush::sentence($r, $what, (string) $setting->store_name));
    }

    /** Unlink ID: forget the link only; the store product stays up. */
    public function unlink(int $store, int $productId)
    {
        VentaSetting::findOrFail($store);
        $removed = VentaProductLink::query()->where('venta_setting_id', $store)->where('product_id', $productId)->delete();
        VentaListing::query()->where('venta_setting_id', $store)->where('product_id', $productId)->update(['live_status' => null, 'live_checked_at' => null]);
        VentaProductGroupProduct::query()->where('product_id', $productId)
            ->whereIn('venta_product_group_id', VentaProductGroup::query()->where('venta_setting_id', $store)->select('id'))
            ->update(['sync_status' => 'pending', 'push_error' => null]);

        return redirect()->back()->with('status', $removed > 0
            ? 'Unlinked. The store product stays up; Link ID or a push connects this product again.'
            : 'That product was not linked, so there was nothing to unlink.');
    }

    /** Delete from {Store}: removed AT the store, then forgotten here. */
    public function deleteFromStore(int $store, int $productId)
    {
        return $this->deleteOnStore($store, [$productId]);
    }

    public function bulkDeleteFromStore(Request $request, int $store)
    {
        return $this->deleteOnStore($store, $this->tickedIds($request));
    }

    private function deleteOnStore(int $store, array $ids)
    {
        $setting = VentaSetting::where('id', $store)->where('enabled', true)->first();
        if (! $setting) {
            return redirect()->back()->with('error', 'That Venta store is turned off, so nothing was sent to it.');
        }
        if ($ids === []) {
            return redirect()->back()->with('error', 'No products selected.');
        }
        $r = \Extensions\venta\Services\Venta\VentaStoreDelete::for($setting)->delete($ids);

        return redirect()->back()->with($r['errors'] ? ($r['deleted'] > 0 ? 'warning' : 'error') : 'status',
            \Extensions\venta\Services\Venta\VentaStoreDelete::sentence($r, (string) $setting->store_name));
    }

    /** Remove from this channel, for the ticked rows: the single verb, repeated. */
    public function bulkRemoveFromStore(Request $request, int $store)
    {
        VentaSetting::findOrFail($store);
        $ids = $this->tickedIds($request);
        $n = 0;
        foreach ($ids as $pid) {
            if (! \Extensions\venta\Services\Venta\VentaStoreProducts::has($store, $pid)) {
                continue;
            }
            VentaProductGroupProduct::query()->where('product_id', $pid)
                ->whereIn('venta_product_group_id', VentaProductGroup::query()->where('venta_setting_id', $store)->select('id'))->delete();
            VentaListing::query()->where('venta_setting_id', $store)->where('product_id', $pid)->delete();
            VentaProductLink::query()->where('venta_setting_id', $store)->where('product_id', $pid)->delete();
            $n++;
        }

        return redirect()->back()->with('status', "Removed {$n} " . ($n === 1 ? 'product' : 'products') . ' from this channel. Anything already on the store stays up until you delete it there.');
    }

    /** @return list<int> */
    private function tickedIds(Request $request): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $request->input('product_ids', [])), fn ($v) => $v > 0)));
    }

    public function bulkToggle(Request $request, int $store)
    {
        $request->validate([
            'action' => 'required|in:unlist,relist',
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'integer',
        ]);
        $active = $request->input('action') === 'relist';
        $verb = $active ? 'Relisted' : 'Unlisted';

        $setting = VentaSetting::where('id', $store)->where('enabled', true)->first();
        if (! $setting) {
            return redirect()->back()->with('error', 'That Venta store is turned off, so nothing was changed on it.');
        }

        $ids = array_values(array_unique(array_map('intval', (array) $request->input('product_ids'))));
        $linked = VentaProductLink::where('venta_setting_id', $store)->whereIn('product_id', $ids)
            ->whereNotNull('venta_product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $notOnStore = count($ids) - count($linked);

        if ($linked === []) {
            return redirect()->back()->with('error', 'None of the selected products is on ' . $setting->store_name . ', so there is nothing to ' . ($active ? 'relist' : 'unlist') . '.');
        }

        $push = VentaProductPush::for($setting);
        $done = 0;
        $refused = [];
        foreach ($linked as $productId) {
            $result = $push->setActive($productId, $active);
            if (! $result['ok']) {
                $refused[] = $result['message'];
                continue;
            }
            $listing = VentaListing::query()->firstOrNew(['venta_setting_id' => $store, 'product_id' => $productId]);
            $listing->mirror($active ? VentaListing::STATUS_ACTIVE : VentaListing::STATUS_INACTIVE, $listing->live_price, $listing->live_quantity);
            $done++;
        }

        $parts = [];
        if ($done > 0) {
            $parts[] = $verb . ' ' . $done . ' ' . ($done === 1 ? 'item' : 'items') . ' on ' . $setting->store_name . '.';
        }
        if ($refused !== []) {
            $parts[] = count($refused) . ' refused: ' . Str::limit($refused[0], 160) . '.';
        }
        if ($notOnStore > 0) {
            $parts[] = $notOnStore . ' skipped, not on ' . $setting->store_name . '.';
        }

        return redirect()->back()->with($done === 0 ? 'error' : 'status', implode(' ', $parts));
    }

    public function edit(int $store, int $productId)
    {
        $setting = VentaSetting::findOrFail($store);
        $push = VentaProductPush::for($setting);
        $product = $push->product($productId);
        abort_unless((bool) $product, 404);

        $listing = VentaListing::query()->where('venta_setting_id', $store)->where('product_id', $productId)->first();
        $link = VentaProductLink::where('venta_setting_id', $store)->where('product_id', $productId)->first();

        // The live half, read from the store on this load. A disabled store
        // is not asked: its answer would be about a shop nobody is selling
        // from, and the page still needs to render so the record can be
        // edited. The freshest answer there is goes to the mirror, so the
        // group pages agree with this page at once (#166).
        $live = null;
        $liveError = null;
        if ($setting->enabled && VentaProductPush::skuOf($product) !== '') {
            $live = $push->read($productId);
            if ($live['state'] === 'unreachable') {
                $liveError = $live['message'];
                $live = null;
            } else {
                $mirror = $listing ?? new VentaListing(['venta_setting_id' => $store, 'product_id' => $productId]);
                $mirror->mirror($live['status'], $live['price'], $live['quantity']);
                $listing = $mirror;

                // A product the store holds under our SKU with no link on
                // record is a link waiting to be made, and reconcile would
                // make it on the next push anyway. Made here so the page
                // can say "on the store" without a push.
                if ($live['state'] === 'found' && ! $link && $live['venta_id']) {
                    if ($push->linkProductTo($productId, $live['venta_id'], $live['sku']) === null) {
                        $link = VentaProductLink::where('venta_setting_id', $store)->where('product_id', $productId)->first();
                    }
                }
            }
        }

        // The one group this product is in on this store (one product, one
        // group), for the rail's Product group card and the Category section.
        $groupRow = VentaProductGroupProduct::query()
            ->join('venta_product_groups as g', 'g.id', '=', 'venta_product_group_products.venta_product_group_id')
            ->leftJoin('venta_categories as c', fn ($j) => $j->on('c.venta_category_id', '=', 'g.venta_category_id')->where('c.venta_setting_id', '=', $store))
            ->where('g.venta_setting_id', $store)
            ->where('venta_product_group_products.product_id', $productId)
            ->orderBy('venta_product_group_products.id')
            ->first(['g.id', 'g.name', 'g.venta_category_id', 'g.markup_percent', 'g.markup_fixed', 'c.name as category_name']);
        $groupCategory = $groupRow && $groupRow->venta_category_id ? (string) ($groupRow->category_name ?: '#' . $groupRow->venta_category_id) : null;
        $groupCard = null;
        if ($groupRow) {
            $lends = [];
            if ($groupCategory !== null) {
                $lends[] = 'category ' . $groupCategory;
            }
            $markup = \App\Support\MarkupWords::of($groupRow->markup_percent !== null ? (float) $groupRow->markup_percent : null, $groupRow->markup_fixed !== null ? (float) $groupRow->markup_fixed : null);
            if ($markup !== 'none') {
                $lends[] = 'markup ' . $markup;
            }
            $groupCard = [
                'id' => (int) $groupRow->id,
                'name' => (string) $groupRow->name,
                'url' => route('ext.venta.product-groups.products', [$store, $groupRow->id]),
                'lends' => $lends,
            ];
        }

        return view('ext-venta::listings.edit', [
            'setting' => $setting,
            'product' => $product,
            'sku' => VentaProductPush::skuOf($product),
            'listing' => $listing,
            'link' => $link,
            'live' => $live,
            'liveError' => $liveError,
            'groupCard' => $groupCard,
            'groupCategory' => $groupCategory,
            'images' => $push->images($productId),
        ]);
    }

    public function update(Request $request, int $store, int $productId)
    {
        $setting = VentaSetting::findOrFail($store);
        abort_unless((bool) VentaProductPush::for($setting)->product($productId), 404);

        $data = $request->validate([
            'markup_percent' => 'nullable|numeric|min:-100|max:1000',
            'markup_fixed' => 'nullable|numeric|min:-1000000|max:1000000',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:20000',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
        ]);

        // Content fields are OVERRIDES: an emptied field returns to null,
        // which means "follows the catalogue". Saving here never writes the
        // core product.
        $blank = fn ($v) => ($v === null || trim((string) $v) === '') ? null : $v;

        VentaListing::query()->updateOrCreate(
            ['venta_setting_id' => $store, 'product_id' => $productId],
            [
                'markup_percent' => $blank($data['markup_percent'] ?? null),
                'markup_fixed' => $blank($data['markup_fixed'] ?? null),
                'name' => $blank($data['name'] ?? null),
                'description' => $blank($data['description'] ?? null),
                'meta_title' => $blank($data['meta_title'] ?? null),
                'meta_description' => $blank($data['meta_description'] ?? null),
            ]
        );

        $back = \App\Support\BackTo::safe($request->input('back'), '');

        // Save and push (parity, the owner 2026-09-06): the save ran; a product
        // not yet on the store goes on to the push, which names its own refusal.
        if ($request->boolean('push_after')) {
            if (\Extensions\venta\Models\VentaProductLink::query()->where('venta_setting_id', $store)->where('product_id', $productId)->whereNotNull('venta_product_id')->exists()) {
                return redirect()->route('ext.venta.listings.edit', $back !== '' ? [$store, $productId, 'back' => $back] : [$store, $productId])
                    ->with('status', 'Listing saved. It is already on ' . $setting->store_name . '; use Push update to send these settings onto the live product.');
            }

            return $this->push($request, $store, $productId);
        }

        return redirect()->route('ext.venta.listings.edit', $back !== '' ? [$store, $productId, 'back' => $back] : [$store, $productId])
            ->with('status', 'Listing saved. These settings drive the next push to ' . $setting->store_name . '.');
    }

    /**
     * Push this product to the store with the record's own content and
     * price rule: creates when the store does not have it, updates in
     * place when it does, and says which.
     */
    public function push(Request $request, int $store, int $productId)
    {
        $setting = VentaSetting::where('id', $store)->where('enabled', true)->first();
        if (! $setting) {
            return redirect()->back()->with('error', 'That Venta store is turned off, so nothing was sent to it. Enable it in its settings first.');
        }

        $listing = VentaListing::query()->firstOrNew(['venta_setting_id' => $store, 'product_id' => $productId]);
        $push = VentaProductPush::for($setting);

        $outcome = $push->push(
            $productId,
            $listing->overrides(),
            $listing->hasPriceRule() ? fn (float $base) => $listing->priceFor($base) : null
        );

        if (! $outcome['ok']) {
            return redirect()->back()->with('error', 'Push failed: ' . $outcome['message']);
        }

        $payload = $outcome['payload'];
        $listing->forceFill([
            'last_pushed_at' => now(),
            'last_push_source' => 'listing',
            'last_push_settings' => [
                'state' => $outcome['state'],
                'price' => $payload['price'] ?? null,
                'markup_percent' => $listing->markup_percent,
                'markup_fixed' => $listing->markup_fixed,
                'overrides' => array_keys($listing->overrides()),
                'variants' => count($payload['variants'] ?? []),
                'images' => $outcome['images'],
            ],
        ])->save();
        $listing->mirror(
            ! empty($payload['status']) ? VentaListing::STATUS_ACTIVE : VentaListing::STATUS_INACTIVE,
            isset($payload['price']) ? (float) $payload['price'] : null,
            isset($payload['quantity']) ? (int) $payload['quantity'] : null
        );

        ActivityLogger::log('pushed', 'VentaListing', (int) $listing->id,
            ucfirst($outcome['state']) . ' product #' . $productId . ' on ' . $setting->store_name,
            ['venta_id' => $outcome['venta_id'], 'settings' => $listing->last_push_settings]);

        $sentence = $outcome['message'] . ' ' . VentaProductPush::imagesSentence($outcome['images']);
        $tone = ($outcome['images']['sent'] > 0 && $outcome['images']['stored'] < $outcome['images']['sent']) ? 'warning' : 'status';

        // Accepted: back to the Listings page the operator came from, search and all (the owner, 2026-09-06); the page itself when there is none.
        $back = \App\Support\BackTo::safe($request->input('back'), '');

        return ($back !== '' ? redirect()->to($back) : redirect()->route('ext.venta.listings.edit', [$store, $productId]))->with($tone, trim($sentence));
    }

    /** Unlist or relist on the store, in the store's own terms: active or not. */
    public function toggle(Request $request, int $store, int $productId)
    {
        $request->validate(['action' => 'required|in:unlist,relist']);
        $active = $request->input('action') === 'relist';

        $setting = VentaSetting::where('id', $store)->where('enabled', true)->first();
        if (! $setting) {
            return redirect()->back()->with('error', 'That Venta store is turned off, so nothing was changed on it.');
        }

        $link = VentaProductLink::where('venta_setting_id', $store)->where('product_id', $productId)->first();
        if (! $link || ! $link->venta_product_id) {
            return redirect()->back()->with('error', ($active ? 'Relist' : 'Unlist') . ' failed: this product is not on ' . $setting->store_name . '.');
        }

        $result = VentaProductPush::for($setting)->setActive($productId, $active);
        if (! $result['ok']) {
            return redirect()->back()->with('error', ($active ? 'Relist' : 'Unlist') . ' failed: ' . $result['message']);
        }

        // Written through, so the next page view and every list agree
        // without waiting for a refresh (#166).
        $listing = VentaListing::query()->firstOrNew(['venta_setting_id' => $store, 'product_id' => $productId]);
        $listing->mirror($active ? VentaListing::STATUS_ACTIVE : VentaListing::STATUS_INACTIVE, $listing->live_price, $listing->live_quantity);

        ActivityLogger::log($active ? 'relisted' : 'unlisted', 'VentaListing', (int) $listing->id,
            ($active ? 'Relisted' : 'Unlisted') . ' product #' . $productId . ' on ' . $setting->store_name);

        return redirect()->back()->with('status', $result['message']);
    }
}
