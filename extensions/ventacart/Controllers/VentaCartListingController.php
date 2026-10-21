<?php

namespace Extensions\VentaCart\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\VentaCart\Models\VentaCartListing;
use Extensions\VentaCart\Models\VentaCartProductGroup;
use Extensions\VentaCart\Models\VentaCartProductGroupProduct;
use Extensions\VentaCart\Models\VentaCartListingLink;
use Extensions\VentaCart\Models\VentaCartStore;
use Extensions\VentaCart\Services\VentaCart\VentaCartListingMirror;
use Extensions\VentaCart\Services\VentaCart\VentaCartProductPush;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VentaCartListingController extends Controller
{
    use \App\Http\Controllers\Concerns\ServesCategoryTree;

    protected function categoryChannel(): string
    {
        return 'ventacart';
    }
    use \App\Http\Controllers\Concerns\ComparesWithTheCatalog {
        catalogChangeSave as private saveCatalogChange;
        catalogChangeIgnore as private ignoreCatalogChange;
    }

    private int $catalogChangeStore = 0;

    public function catalogChangeSave(Request $request, int $store, int $productId)
    {
        $this->catalogChangeStore = $store;

        return $this->saveCatalogChange($request, $productId);
    }

    public function catalogChangeIgnore(Request $request, int $store, int $productId)
    {
        $this->catalogChangeStore = $store;

        return $this->ignoreCatalogChange($request, $productId);
    }

    protected function catalogChangeListing(int $productId): \Illuminate\Database\Eloquent\Model
    {
        VentaCartStore::findOrFail($this->catalogChangeStore);

        return VentaCartListing::query()->where('store_id', $this->catalogChangeStore)->where('product_id', $productId)->firstOrFail();
    }

    protected function catalogChangeFallback(int $productId): string
    {
        return route('ext.ventacart.listings.edit', [$this->catalogChangeStore, $productId]);
    }

    public function checkAgainstVenta(Request $request, int $store, \Extensions\VentaCart\Services\VentaCart\VentaCartLinkCheck $check)
    {
        $setting = VentaCartStore::findOrFail($store);
        if (! $setting->enabled) {
            return redirect()->back()->with('error',
                'That VentaCart store is turned off, so nothing could be checked. Enable it in its settings first.');
        }

        $ids = array_values(array_unique(array_map('intval', array_filter((array) $request->input('product_ids', [])))));
        if (empty($ids)) {
            $ids = \Extensions\VentaCart\Models\VentaCartListingLink::query()
                ->where('store_id', $store)
                ->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        }
        if (empty($ids)) {
            return redirect()->back()->with('error', 'Nothing is linked to this store yet, so there is nothing to check.');
        }

        $r = $check->run($setting, $ids);

        return redirect()->back()->with($r['tone'], $r['summary']);
    }

    public function importPage(Request $request, int $store)
    {
        $setting = VentaCartStore::findOrFail($store);

        $fetched = null;
        $fetchError = null;
        if ($request->boolean('fetch')) {
            [$fetched, $fetchError] = $this->fetchUnmatched($setting);
        }

        return view('ext-ventacart::products.import', [
            'setting' => $setting,
            'store' => $store,
            'fetched' => $fetched,
            'fetchError' => $fetchError,
        ]);
    }

    private function fetchUnmatched(VentaCartStore $store): array
    {
        if (! $store->enabled) {
            return [null, 'That VentaCart store is turned off, so nothing was fetched.'];
        }

        $client = new \Extensions\VentaCart\Services\VentaCart\VentaCartClient($store);
        $known = $this->knownSkus();

        $found = 0;
        $rows = [];
        $page = 1;
        do {
            $answer = $client->get('products', ['per_page' => 100, 'page' => $page]);
            if (! ($answer['ok'] ?? false)) {
                return [null, 'VentaCart did not answer the fetch: '
                    . \App\Support\ChannelAnswer::plain('VentaCart', $answer)
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

    public function importOne(Request $request, int $store, \Extensions\VentaCart\Services\VentaCart\VentaCartItemImport $import)
    {
        $data = $request->validate(['ref' => 'required|string|max:191']);
        $setting = VentaCartStore::findOrFail($store);

        $r = $import->import($setting, $data['ref']);

        return redirect()->back()->with($r['ok'] ? 'status' : 'error', $r['message']);
    }

    public function importSelected(Request $request, int $store, \Extensions\VentaCart\Services\VentaCart\VentaCartItemImport $import)
    {
        $data = $request->validate(['refs' => 'required|array|min:1', 'refs.*' => 'string|max:191']);
        $setting = VentaCartStore::findOrFail($store);

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

    public function linkItem(Request $request, int $store)
    {
        VentaCartStore::findOrFail($store);
        $data = $request->validate([
            'ref' => 'required|string|max:191',
            'channel_id' => 'nullable|integer',
            'product_id' => 'required|integer|min:1',
        ]);
        if (! DB::table('products')->where('product_id', (int) $data['product_id'])->exists()) {
            return redirect()->back()->with('error', 'That Master Catalog product does not exist.');
        }

        $ventaCartId = (int) ($data['channel_id'] ?? 0) ?: null;
        $held = VentaCartListingLink::query()->where('store_id', $store)->where('product_id', (int) $data['product_id'])->whereNotNull('ventacart_product_id')->first();
        if ($held && $ventaCartId !== null && (int) $held->ventacart_product_id !== $ventaCartId) {
            return redirect()->back()->with('error', 'Master Catalog product #' . $data['product_id'] . ' is already linked to VentaCart product ' . $held->ventacart_product_id . '. Unlink it first.');
        }
        if ($ventaCartId !== null) {
            $taken = VentaCartListingLink::query()->where('store_id', $store)->where('ventacart_product_id', $ventaCartId)->where('product_id', '!=', (int) $data['product_id'])->first();
            if ($taken) {
                return redirect()->back()->with('error', 'VentaCart product ' . $ventaCartId . ' is already linked to Master Catalog product #' . $taken->product_id . '. Unlink that product first.');
            }
        }
        VentaCartListingLink::updateOrCreate(
            ['store_id' => $store, 'product_id' => (int) $data['product_id']],
            ['ventacart_product_id' => $ventaCartId, 'sku' => $data['ref']]
        );
        \Extensions\VentaCart\Services\VentaCart\VentaCartListingStates::for($store)->recordOutcome((int) $data['product_id'], null);

        return redirect()->back()->with('status', 'Linked "' . $data['ref'] . '" to Master Catalog product #' . $data['product_id'] . '.');
    }

    private function knownSkus(): \Illuminate\Support\Collection
    {

        return collect()
            ->merge(DB::table('products')->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku'))
            ->merge(DB::table('product_variation_values')->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku'))
            ->merge(DB::table('product_variations')->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku'))
            ->map(fn ($v) => strtolower(trim((string) $v)))
            ->flip();
    }

    public function searchCatalog(Request $request, int $store)
    {
        VentaCartStore::findOrFail($store);

        return response()->json(\App\Support\CatalogPickerSearch::items((string) $request->query('q', ''), function (array $ids) use ($store) {
            return VentaCartListingLink::query()->where('store_id', $store)->whereIn('product_id', $ids)->whereNotNull('ventacart_product_id')
                ->get(['product_id', 'ventacart_product_id'])
                ->mapWithKeys(fn ($l) => [(int) $l->product_id => 'VentaCart product ' . $l->ventacart_product_id])->all();
        }));
    }

    public function index(Request $request, int $store)
    {
        $setting = VentaCartStore::findOrFail($store);
        $langId = (int) config('catalog.default_language_id');

        $q = trim((string) $request->get('q', ''));
        $groupFilter = \App\Channels\Listings\StatusMenu::group($request->get('group'));
        $catalogStatus = (string) $request->get('catalog_status', 'all');
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
        $tab = (string) $request->get('ventacart_tab', 'all');
        if (! array_key_exists($tab, VentaCartListingMirror::TABS)) {
            $tab = 'all';
        }

        $sortColumns = ['id' => 'p.product_id', 'product' => 'pd.name', 'quantity' => 'p.quantity', 'price' => 'p.price'];
        $sort = array_key_exists((string) $request->get('sort', 'id'), $sortColumns) ? (string) $request->get('sort', 'id') : 'id';
        $dir = strtolower((string) $request->get('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $mirror = VentaCartListingMirror::for($setting);
        $liveCounts = [];
        $liveCheckedAt = $mirror->checkedAt();

        $liveTabError = ($setting->enabled && $setting->isConnected()) ? null : 'Missing ' . $setting->store_name . ' settings.';

        $groupIds = VentaCartProductGroup::where('store_id', $store)->pluck('id')->all();
        $pivotProductIds = VentaCartProductGroupProduct::query()->whereIn('ventacart_product_group_id', $groupIds ?: [0])
            ->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->all();
        $linkedProductIds = VentaCartListingLink::where('store_id', $store)->whereNotNull('ventacart_product_id')
            ->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $scopeIds = array_values(array_unique(array_merge($pivotProductIds, $linkedProductIds, \Extensions\VentaCart\Services\VentaCart\VentaCartStoreProducts::ids($store))));

        $groupsByProductId = DB::table('ch_ventacart_product_group_products as gp')
            ->join('ch_ventacart_product_groups as g', 'g.id', '=', 'gp.ventacart_product_group_id')
            ->where('g.store_id', $store)
            ->get(['gp.product_id', 'g.id', 'g.name'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name])->all());

        $query = DB::table('products as p')
            ->leftJoin('product_descriptions as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->leftJoin('brands as m', 'p.brand_id', '=', 'm.brand_id')
            ->whereIn('p.product_id', $scopeIds ?: [0])
            ->select('p.product_id', 'pd.name', 'p.image', 'p.sku', 'p.price', 'p.quantity', 'p.status', 'm.name as brand_name');

        $groupedIds = VentaCartProductGroupProduct::query()
            ->whereIn('ventacart_product_group_id', VentaCartProductGroup::query()->where('store_id', $store)->select('id'))
            ->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $applyGroup = function ($query, string $choice) use ($groupedIds) {
            if ($choice === 'none') {
                return $groupedIds === [] ? $query : $query->whereNotIn('p.product_id', $groupedIds);
            }
            if ($choice === 'all') {
                return $query;
            }
            $ids = VentaCartProductGroupProduct::query()->where('ventacart_product_group_id', (int) $choice)
                ->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

            return $query->whereIn('p.product_id', $ids ?: [0]);
        };
        if ($catalogStatus === 'enabled') {
            $query->where('p.status', 1);
        } elseif ($catalogStatus === 'disabled') {
            $query->where('p.status', 0);
        }
        if ($q !== '') {
            $query->where(function ($w) use ($q, $store) {
                $w->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%')
                    ->orWhereIn('p.product_id', VentaCartListing::query()
                        ->where('store_id', $store)
                        ->where('name', 'like', '%' . $q . '%')
                        ->select('product_id'));
            });
        }
        $states = \Extensions\VentaCart\Services\VentaCart\VentaCartListingStates::for($store);

        $troubleFilter = \App\Channels\Listings\ListingTrouble::asked($request->query('state'));
        if ($troubleFilter !== null) {
            $query->whereIn('p.product_id', $states->productIdsIn($troubleFilter) ?: [0]);
        }

        $listed = fn ($query) => $query->whereIn('p.product_id', $linkedProductIds ?: [0]);
        $notListed = fn ($query) => $query->whereNotIn('p.product_id', $linkedProductIds ?: [0]);
        $applySync = function ($query, string $status) use ($listed, $notListed) {
            match ($status) {
                'uploaded' => $listed($query),
                'not_uploaded' => $notListed($query),
                default => $query,
            };

            return $query;
        };

        $errored = $states->erroredProductIds();
        $changed = $states->productIdsIn('drift');

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
        $applyTab = function ($query) use ($tab, $store, $linkedProductIds) {
            if ($tab !== 'all') {
                $tabIds = VentaCartListing::query()->where('store_id', $store)
                    ->whereIn('product_id', $linkedProductIds ?: [0])
                    ->where('live_status', VentaCartListingMirror::TABS[$tab][1])->pluck('product_id')->all();
                $query->whereIn('p.product_id', $tabIds ?: [0]);
            }

            return $query;
        };
        $tabCounts = function ($query) use ($store, $listed): array {
            $byStatus = VentaCartListing::query()->where('store_id', $store)
                ->whereIn('product_id', $listed(clone $query)->select('p.product_id'))
                ->whereNotNull('live_status')
                ->selectRaw('live_status, COUNT(DISTINCT product_id) as n')
                ->groupBy('live_status')
                ->pluck('n', 'live_status');
            $out = [];
            foreach (VentaCartListingMirror::TABS as $tabKey => [$label, $status]) {
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
        foreach (VentaCartProductGroup::query()->where('store_id', $store)->orderBy('name')->get(['id', 'name']) as $group) {
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

        $tabBase = $applyMenu(clone $query);
        $catalogTotal = (clone $tabBase)->count();
        $liveCounts = $tabCounts($tabBase);

        $applyMenu($query);
        $applyTab($query);

        $storeId = \App\Channels\Listings\ListingStore::id('ventacart');
        $sortMenu = \Extensions\VentaCart\Services\VentaCartListingSort::columns($storeId);
        $order = \App\Channels\Listings\ListingSort::chosen($request, 'ventacart.listings.' . $storeId);
        if ($request->filled('sort')) {
            $query->orderBy($sortColumns[$sort], $dir);
            if ($sortColumns[$sort] !== 'p.product_id') {
                $query->orderBy('p.product_id', 'asc');
            }
        } else {
            \Extensions\VentaCart\Services\VentaCartListingSort::join($query, $storeId);
            \App\Channels\Listings\ListingSort::apply($query, $order, $sortMenu);
        }
        $products = $query->paginate(50)->withQueryString();
        foreach ($products as $row) {
            $row->name = html_entity_decode((string) ($row->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $pageIds = $products->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $linksByProductId = VentaCartListingLink::where('store_id', $store)->whereIn('product_id', $pageIds ?: [0])
            ->whereNotNull('ventacart_product_id')->get()->keyBy(fn ($l) => (int) $l->product_id);

        $fillError = null;
        if ($setting->enabled && $linksByProductId->isNotEmpty() && $mirror->fillBlanks($linksByProductId, 10, $fillError) > 0) {
            $liveCounts = $tabCounts($tabBase);
            $liveCheckedAt = $mirror->checkedAt();
        }
        if ($fillError !== null) {
            session()->now('error', \App\Channels\Listings\BlankFillRefusal::sentence((string) $setting->store_name, $fillError));
        }

        $listingsByProductId = VentaCartListing::query()->where('store_id', $store)
            ->whereIn('product_id', $pageIds ?: [0])->get()->keyBy(fn ($l) => (int) $l->product_id);
        $rowTitles = \App\Channels\Listings\ListingContent::rowTitles($products, $listingsByProductId);
        $lastAttempts = VentaCartProductGroupProduct::query()
            ->whereIn('ventacart_product_group_id', $groupIds ?: [0])
            ->whereIn('product_id', $pageIds ?: [0])
            ->orderByDesc('last_pushed_at')->get()
            ->groupBy(fn ($p) => (int) $p->product_id)->map(fn ($rows) => $rows->first());

        return view('ext-ventacart::listings.index', [
            'setting' => $setting,
            'products' => $products,
            'rowTitles' => $rowTitles,
            'q' => $q, 'sort' => $sort, 'dir' => $dir,
            'order' => $order, 'orderVariations' => \App\Channels\Listings\ListingSort::options(array_keys($sortMenu)),
            'groupFilter' => $groupFilter, 'catalogStatus' => $catalogStatus, 'syncStatus' => $syncStatus,
            'allGroups' => VentaCartProductGroup::where('store_id', $store)->orderBy('name')->pluck('name', 'id'),
            'groupsByProductId' => $groupsByProductId,
            'variationRowsByProductId' => \App\Support\VariationRows::forListing($pageIds, 'ventacart', $store),
            'linksByProductId' => $linksByProductId,
            'listingsByProductId' => $listingsByProductId,
            'lastAttempts' => $lastAttempts,
            'listingStates' => $states->forProducts($pageIds),
            'rowErrors' => $states->errors($pageIds),
            'ventaCartTab' => $tab,
            'liveCounts' => $liveCounts,
            'liveCheckedAt' => $liveCheckedAt,
            'liveTabError' => $liveTabError,
            'troubleFilter' => $troubleFilter,
            'catalogTotal' => $catalogTotal,
            'failedFlag' => $failedFlag,
            'changeFlag' => $changeFlag,
            'statusMenu' => \App\Channels\Listings\StatusMenu::build([
                'url' => fn (array $params) => route('ext.ventacart.listings.index', array_merge(['store' => $store], $params)),
                'query' => $request->query(),
                'sync' => $syncStatus,
                'failed' => $failedFlag,
                'change' => $changeFlag,
                'counts' => $menuCounts,
                'group' => $groupFilter,
                'groups' => $groupRail,
                'newGroup' => route('ext.ventacart.product-groups.create', $store),
                'store' => (string) $setting->store_name,
            ]),
        ]);
    }

    private function readyByProduct(int $store, array $productIds): array
    {
        return array_map(fn (array $a) => (bool) $a['ready'], $this->readinessRich($store, $productIds));
    }

    private function gapsByProduct(int $store, array $productIds): array
    {
        return array_map(
            fn (array $a) => array_values(array_map(fn ($g) => (string) ($g['code'] ?? ''), (array) $a['gaps'])),
            $this->readinessRich($store, $productIds)
        );
    }

    private array $readinessRich = [];

    private function readinessRich(int $store, array $productIds): array
    {
        if (isset($this->readinessRich[$store])) {
            return $this->readinessRich[$store];
        }
        $ids = array_values(array_unique(array_map('intval', $productIds)));

        return $this->readinessRich[$store] = \App\Channels\Listings\ReadinessCache::resolve(
            'ventacart', $store, $ids,
            fn (array $x) => $this->ventaCartReadinessStamps($x, $store),
            fn (array $x) => $this->ventaCartReadinessCompute($x),
        );
    }

    private function ventaCartReadinessStamps(array $ids, int $store): array
    {
        $prod = DB::table('products')->whereIn('product_id', $ids)->pluck('date_modified', 'product_id');
        $listing = DB::table('ch_ventacart_listings')->where('store_id', $store)->whereIn('product_id', $ids)
            ->selectRaw('product_id, MAX(updated_at) as mu')->groupBy('product_id')->pluck('mu', 'product_id');
        $group = DB::table('ch_ventacart_product_group_products as gp')
            ->join('ch_ventacart_product_groups as g', 'g.id', '=', 'gp.ventacart_product_group_id')
            ->whereIn('gp.product_id', $ids)->where('g.store_id', $store)
            ->selectRaw('gp.product_id, MAX(g.updated_at) as mu')->groupBy('gp.product_id')->pluck('mu', 'product_id');

        $out = [];
        foreach ($ids as $pid) {
            $out[(int) $pid] = md5(($prod[$pid] ?? '') . '|' . ($listing[$pid] ?? '') . '|' . ($group[$pid] ?? ''));
        }

        return $out;
    }

    private function ventaCartReadinessCompute(array $ids): array
    {
        $readiness = \Extensions\VentaCart\Services\VentaCart\VentaCartListingReadiness::forProducts($ids);
        $out = [];
        foreach ($ids as $pid) {
            $gaps = array_values((array) ($readiness[(int) $pid]['gaps'] ?? []));
            $out[(int) $pid] = ['ready' => $gaps === [], 'gaps' => $gaps];
        }

        return $out;
    }

    public function catalogSearch(Request $request, int $store)
    {
        VentaCartStore::findOrFail($store);
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->query('q', ''));
        $showAll = $request->boolean('all');
        $limit = 25;
        $query = DB::table('products as p')
            ->leftJoin('product_descriptions as pd', fn ($j) => $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId))
            ->leftJoinSub(\Extensions\VentaCart\Services\VentaCart\VentaCartStoreProducts::query($store), 'os', 'os.product_id', '=', 'p.product_id')
            ->select('p.product_id', 'pd.name', 'p.image', 'p.sku', 'p.quantity', 'p.price', 'os.product_id as on_store_pid')
            ->where('p.status', 1);
        if ($q !== '') {
            $query->where(fn ($sub) => $sub->where('pd.name', 'like', '%' . $q . '%')->orWhere('p.sku', 'like', '%' . $q . '%'));
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
            'thumb' => trim((string) ($r->image ?? '')) !== '' ? \App\Services\Media\ImageCache::url((string) $r->image) : null,
            'on_store' => ! is_null($r->on_store_pid),
        ])->values();

        return response()->json(['total' => $total, 'shown' => $items->count(), 'limit' => $limit, 'items' => $items]);
    }

    public function addToStore(Request $request, int $store, int $productId)
    {
        VentaCartStore::findOrFail($store);
        abort_unless(DB::table('products')->where('product_id', $productId)->exists(), 404);
        $wasOn = \Extensions\VentaCart\Services\VentaCart\VentaCartStoreProducts::has($store, $productId);
        VentaCartListing::query()->firstOrCreate(['store_id' => $store, 'product_id' => $productId]);

        $groupId = (int) $request->input('group', 0);
        if ($groupId > 0) {
            \App\Channels\OneGroupRule::place('ch_ventacart_product_group_products', 'ventacart_product_group_id', 'ch_ventacart_product_groups', 'store_id', $store, $groupId, $productId);
        }
        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'product_id' => $productId, 'added' => ! $wasOn]);
        }

        return redirect()->back()->with('status', 'Added to this store. Configure and push it from Products whenever you are ready.');
    }

    public function addToStoreBulk(Request $request, int $store)
    {
        VentaCartStore::findOrFail($store);
        $data = $request->validate(['product_ids' => ['required', 'array'], 'product_ids.*' => ['integer']]);
        $valid = DB::table('products')->whereIn('product_id', array_map('intval', $data['product_ids']))->pluck('product_id')->all();
        $added = 0;
        foreach ($valid as $pid) {
            $wasOn = \Extensions\VentaCart\Services\VentaCart\VentaCartStoreProducts::has($store, (int) $pid);
            VentaCartListing::query()->firstOrCreate(['store_id' => $store, 'product_id' => (int) $pid]);
            $added += $wasOn ? 0 : 1;
        }

        return redirect()->back()->with('status', number_format($added) . ' ' . \Illuminate\Support\Str::plural('product', $added) . ' added to this store.');
    }

    public function removeFromStore(int $store, int $productId)
    {
        VentaCartStore::findOrFail($store);
        abort_unless(\Extensions\VentaCart\Services\VentaCart\VentaCartStoreProducts::has($store, $productId), 404);
        $ventaCartIds = VentaCartListingLink::query()->where('store_id', $store)->where('product_id', $productId)->pluck('ventacart_product_id')->filter()->unique();
        VentaCartProductGroupProduct::query()->where('product_id', $productId)
            ->whereIn('ventacart_product_group_id', VentaCartProductGroup::query()->where('store_id', $store)->select('id'))->delete();
        VentaCartListing::query()->where('store_id', $store)->where('product_id', $productId)->delete();
        VentaCartListingLink::query()->where('store_id', $store)->where('product_id', $productId)->delete();

        return redirect()->back()->with('status', "Removed product #{$productId} from this store." . ($ventaCartIds->isNotEmpty()
            ? ' It no longer syncs; the store product ' . $ventaCartIds->implode(', ') . ' stays up until you remove it there.'
            : ' It no longer syncs.'));
    }

    public function refreshStatus(int $store)
    {
        $setting = VentaCartStore::where('id', $store)->where('enabled', true)->first();
        if (! $setting) {
            return redirect()->back()->with('error', 'That VentaCart store is turned off, so it was not asked.');
        }

        $result = VentaCartListingMirror::for($setting)->refresh();
        if ($result['error'] !== null) {
            return redirect()->back()->with('error', $result['error'] . ' The statuses shown are from the last refresh.');
        }
        $checked = app(\Extensions\VentaCart\Services\VentaCart\VentaCartLinkCheck::class)->runNext($setting);

        $parts = [];
        foreach ($result['counts'] as $status => $n) {
            $parts[] = number_format($n) . ' ' . strtolower(VentaCartListingMirror::STATUS_MAP[$status][0] ?? $status);
        }

        return redirect()->back()->with('status', 'Refreshed from ' . $setting->store_name . ': ' . ($parts ? implode(', ', $parts) : 'no linked products') . '.'
            . ($checked ? ' ' . $checked['summary'] : ''));
    }

    public function pushStock(int $store, int $productId)
    {
        return $this->figures($store, [$productId], true, false, 'Stock');
    }

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
        $setting = VentaCartStore::where('id', $store)->where('enabled', true)->first();
        if (! $setting) {
            return redirect()->back()->with('error', 'That VentaCart store is turned off, so nothing was sent to it.');
        }
        if ($ids === []) {
            return redirect()->back()->with('error', 'No products selected.');
        }
        $r = \Extensions\VentaCart\Services\VentaCart\VentaCartStockPricePush::for($setting)->push($ids, $stock, $price);
        \Extensions\VentaCart\Services\VentaCart\VentaCartStockPricePush::recordOutcomes($store, $r['outcomes']);

        return redirect()->back()->with($r['failed'] > 0 ? ($r['ok'] > 0 ? 'warning' : 'error') : 'status',
            \Extensions\VentaCart\Services\VentaCart\VentaCartStockPricePush::sentence($r, $what, (string) $setting->store_name));
    }

    public function unlink(int $store, int $productId)
    {
        VentaCartStore::findOrFail($store);
        $removed = VentaCartListingLink::query()->where('store_id', $store)->where('product_id', $productId)->delete();
        VentaCartListing::query()->where('store_id', $store)->where('product_id', $productId)->update(['live_status' => null, 'live_checked_at' => null]);
        VentaCartProductGroupProduct::query()->where('product_id', $productId)
            ->whereIn('ventacart_product_group_id', VentaCartProductGroup::query()->where('store_id', $store)->select('id'))
            ->update(['sync_status' => 'pending', 'push_error' => null]);
        \Extensions\VentaCart\Services\VentaCart\VentaCartListingStates::for($store)->clearErrors([$productId]);

        return redirect()->back()->with('status', $removed > 0
            ? 'Unlinked. The store product stays up; Link ID or a push connects this product again.'
            : 'That product was not linked, so there was nothing to unlink.');
    }

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
        $setting = VentaCartStore::where('id', $store)->where('enabled', true)->first();
        if (! $setting) {
            return redirect()->back()->with('error', 'That VentaCart store is turned off, so nothing was sent to it.');
        }
        if ($ids === []) {
            return redirect()->back()->with('error', 'No products selected.');
        }
        $r = \Extensions\VentaCart\Services\VentaCart\VentaCartStoreDelete::for($setting)->delete($ids);

        return redirect()->back()->with($r['errors'] ? ($r['deleted'] > 0 ? 'warning' : 'error') : 'status',
            \Extensions\VentaCart\Services\VentaCart\VentaCartStoreDelete::sentence($r, (string) $setting->store_name));
    }

    public function bulkRemoveFromStore(Request $request, int $store)
    {
        VentaCartStore::findOrFail($store);
        $ids = $this->tickedIds($request);
        $n = 0;
        foreach ($ids as $pid) {
            if (! \Extensions\VentaCart\Services\VentaCart\VentaCartStoreProducts::has($store, $pid)) {
                continue;
            }
            VentaCartProductGroupProduct::query()->where('product_id', $pid)
                ->whereIn('ventacart_product_group_id', VentaCartProductGroup::query()->where('store_id', $store)->select('id'))->delete();
            VentaCartListing::query()->where('store_id', $store)->where('product_id', $pid)->delete();
            VentaCartListingLink::query()->where('store_id', $store)->where('product_id', $pid)->delete();
            $n++;
        }

        return redirect()->back()->with('status', "Removed {$n} " . ($n === 1 ? 'product' : 'products') . ' from this channel. Anything already on the store stays up until you delete it there.');
    }

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

        $setting = VentaCartStore::where('id', $store)->where('enabled', true)->first();
        if (! $setting) {
            return redirect()->back()->with('error', 'That VentaCart store is turned off, so nothing was changed on it.');
        }

        $ids = array_values(array_unique(array_map('intval', (array) $request->input('product_ids'))));
        $linked = VentaCartListingLink::where('store_id', $store)->whereIn('product_id', $ids)
            ->whereNotNull('ventacart_product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $notOnStore = count($ids) - count($linked);

        if ($linked === []) {
            return redirect()->back()->with('error', 'None of the selected products is on ' . $setting->store_name . ', so there is nothing to ' . ($active ? 'relist' : 'unlist') . '.');
        }

        $push = VentaCartProductPush::for($setting);
        $states = \Extensions\VentaCart\Services\VentaCart\VentaCartListingStates::for($store);
        $done = 0;
        $refused = [];
        foreach ($linked as $productId) {
            $result = $push->setActive($productId, $active);
            $states->recordOutcome($productId, $result['ok'] ? null : ($active ? 'Relist' : 'Unlist') . ' failed: ' . $result['message']);
            if (! $result['ok']) {
                $refused[] = $result['message'];
                continue;
            }
            $listing = VentaCartListing::query()->firstOrNew(['store_id' => $store, 'product_id' => $productId]);
            $listing->mirror($active ? VentaCartListing::STATUS_ACTIVE : VentaCartListing::STATUS_INACTIVE, $listing->live_price, $listing->live_quantity);
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
        $setting = VentaCartStore::findOrFail($store);
        $push = VentaCartProductPush::for($setting);
        $product = $push->product($productId);
        abort_unless((bool) $product, 404);

        $listing = VentaCartListing::query()->where('store_id', $store)->where('product_id', $productId)->first();
        $link = VentaCartListingLink::where('store_id', $store)->where('product_id', $productId)->first();

        $live = null;
        $liveError = null;
        if ($setting->enabled && VentaCartProductPush::skuOf($product) !== '') {
            $live = $push->read($productId);
            if ($live['state'] === 'unreachable') {
                $liveError = $live['message'];
                $live = null;
            } else {
                $mirror = $listing ?? new VentaCartListing(['store_id' => $store, 'product_id' => $productId]);
                $mirror->mirror($live['status'], $live['price'], $live['quantity']);
                $listing = $mirror;
                if ($live['state'] === 'found') {
                    $push->rememberHeld($productId, array_column($live['variants'], 'sku'));
                }

                if ($live['state'] === 'found' && ! $link && $live['ventacart_id']) {
                    if ($push->linkProductTo($productId, $live['ventacart_id'], $live['sku']) === null) {
                        $link = VentaCartListingLink::where('store_id', $store)->where('product_id', $productId)->first();
                    }
                }
            }
        }

        $groupBand = \App\Channels\Listings\ListingGroup::band([
            'pivot' => 'ch_ventacart_product_group_products', 'fk' => 'ventacart_product_group_id',
            'groups' => 'ch_ventacart_product_groups', 'storeFk' => 'store_id', 'storeId' => $store,
        ], $productId, 'ext.ventacart.product-groups.edit', ['store' => $store], 'group');
        $num = fn ($v) => $v !== null ? (float) $v : null;

        $shownCategoryId = VentaCartListing::categoryFor($listing instanceof VentaCartListing ? $listing : null);
        $markupPercent = $num($listing?->markup_percent);
        $markupFixed = $num($listing?->markup_fixed);
        $hasVariations = VentaCartListing::withVariations([$productId]) !== [];
        $pushPrice = VentaCartListing::ruleFor($listing)(VentaCartListing::startFor($listing, (float) $product->price, $hasVariations));

        return view('ext-ventacart::listings.edit', \App\Channels\Listings\ListingImages::cardData($productId, $listing->image_order ?? null, $listing ?? null, 'ventacart', \App\Channels\Listings\ListingStore::id('ventacart')) + [
            'readiness' => \Extensions\VentaCart\Services\VentaCart\VentaCartListingReadiness::forProducts([$productId])[$productId] ?? null,
            'setting' => $setting,
            'product' => $product,
            'sku' => VentaCartProductPush::skuOf($product),
            'listing' => $listing,
            'link' => $link,
            'live' => $live,
            'liveError' => $liveError,
            ...\App\Channels\Listings\ListingVariations::cardData(
                'ventacart', $store, $productId,
                ($live['state'] ?? '') === 'found' ? collect($live['variants'] ?? [])->pluck('sku')->all() : ($link ? null : [])
            ),
            'groupBand' => $groupBand,
            'storeCategories' => \Extensions\VentaCart\Models\VentaCartCategory::query()->where('store_id', $store)
                ->orderBy('name')->get(['ventacart_category_id', 'name']),
            'shownCategoryId' => $shownCategoryId,
            'markupPercent' => $markupPercent,
            'markupFixed' => $markupFixed,
            'hasVariations' => $hasVariations,
            'pushPrice' => $pushPrice,
            'storeId' => (int) $store,
            'descriptionTemplates' => \App\Models\DescriptionTemplate::forStore('ventacart', (int) $store),
        ]);
    }

    public function update(Request $request, int $store, int $productId)
    {
        $setting = VentaCartStore::findOrFail($store);
        abort_unless((bool) VentaCartProductPush::for($setting)->product($productId), 404);

        $data = $request->validate([
            'markup_percent' => 'nullable|numeric|min:0|max:1000',
            'markup_fixed' => 'nullable|numeric|min:0|max:1000000',
            'price' => 'nullable|numeric|min:0|max:99999999999',
            'weight' => 'nullable|numeric|min:0.001|max:99999.999',
            'package_length' => 'nullable|numeric|min:0.01|max:1000000',
            'package_width' => 'nullable|numeric|min:0.01|max:1000000',
            'package_height' => 'nullable|numeric|min:0.01|max:1000000',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:20000',
            'description_prefix_id' => 'nullable|integer',
            'watermark_template_id' => 'nullable|integer',
            'watermark_all_images' => 'nullable|boolean',
            'description_suffix_id' => 'nullable|integer',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'image_order' => 'nullable|string|max:20000',
            'ventacart_category_id' => 'nullable|integer|min:0',
            ...\App\Channels\Listings\ListingVariations::rules(),
            ...\App\Channels\Listings\VariationPrices::rules(),
        ]);

        $images = $request->has('image_order')
            ? ['image_order' => \App\Channels\Listings\ListingImages::submitted($productId, $data['image_order'] ?? null),
               'image_off' => \App\Channels\Listings\ListingImages::submittedOff($productId, $request->input('image_off'))]
            : [];

        $blank =fn ($v) => ($v === null || trim((string) $v) === '') ? null : $v;
        $rawCategory = $data['ventacart_category_id'] ?? null;
        $ownCategory = ($rawCategory === null || $rawCategory === '') ? null : (int) $rawCategory;
        if ($ownCategory && ! \Illuminate\Support\Facades\DB::table('ch_ventacart_categories')->where('store_id', $store)->where('ventacart_category_id', $ownCategory)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['ventacart_category_id' => 'Pick one of this store\'s categories.']);
        }

        $currentDescription = VentaCartListing::query()->where('store_id', $store)->where('product_id', $productId)->value('description');
        $words = [];
        if ($request->has('name')) {
            $words['name'] = \App\Channels\Listings\ListingContent::own($data['name'] ?? null);
        }
        if ($request->has('description')) {
            $words['description'] = \App\Channels\Listings\ListingContent::descriptionToSave($data['description'] ?? null, $currentDescription, $request->input('description_edited'));
        }

        VentaCartListing::query()->updateOrCreate(
            ['store_id' => $store, 'product_id' => $productId],
            $images + $words + ($request->has('ventacart_category_id') ? ['ventacart_category_id' => $ownCategory] : []) + [
                'markup_percent' => $blank($data['markup_percent'] ?? null),
                'markup_fixed' => $blank($data['markup_fixed'] ?? null),
                'price' => VentaCartListing::withVariations([$productId]) !== [] ? null : $blank($data['price'] ?? null),
                'weight' => $blank($data['weight'] ?? null),
                'package_length' => $blank($data['package_length'] ?? null),
                'package_width' => $blank($data['package_width'] ?? null),
                'package_height' => $blank($data['package_height'] ?? null),
                'description_prefix_id' => ((int) ($data['description_prefix_id'] ?? 0)) ?: null,
                ...\App\Channels\Listings\ListingImages::submittedWatermark($request, 'ventacart', \App\Channels\Listings\ListingStore::id('ventacart')),
                ...\App\Channels\Listings\ListingVideo::submitted($request),
                'description_suffix_id' => ((int) ($data['description_suffix_id'] ?? 0)) ?: null,
                'meta_title' => $blank($data['meta_title'] ?? null),
                'meta_description' => $blank($data['meta_description'] ?? null),
            ]
        );

        $savedRow = VentaCartListing::query()->where('store_id', $store)->where('product_id', $productId)->first();
        if ($savedRow && $savedRow->ventacart_category_id !== null && (int) $savedRow->ventacart_category_id === 0) {
            $savedRow->forceFill(['ventacart_category_id' => null])->save();
        }

        if (($sellHere = \App\Channels\Listings\ListingVariations::submitted($request)) !== null) {
            \App\Channels\Listings\ListingVariations::save('ventacart', $store, $productId, $sellHere);
        }
        if (($variationPrices = \App\Channels\Listings\VariationPrices::submitted($request)) !== null) {
            \App\Channels\Listings\VariationPrices::save('ventacart', (int) $store, $productId, $variationPrices);
        }

        $back = \App\Support\BackTo::safe($request->input('back'), '');

        if ($request->boolean('push_after')) {
            return $this->push($request, $store, $productId);
        }

        return redirect()->route('ext.ventacart.listings.edit', $back !== '' ? [$store, $productId, 'back' => $back] : [$store, $productId])
            ->with('status', 'Listing saved.');
    }

    public function push(Request $request, int $store, int $productId)
    {
        $setting = VentaCartStore::where('id', $store)->where('enabled', true)->first();
        if (! $setting) {
            return redirect()->back()->with('error', 'That VentaCart store is turned off, so nothing was sent to it. Enable it in its settings first.');
        }

        $answer = $this->pushOne($setting, $productId);
        if (! $answer['ok']) {
            return redirect()->back()->with('error', 'Push failed: ' . $answer['message']);
        }

        $back = \App\Support\BackTo::safe($request->input('back'), '');

        return ($back !== '' ? redirect()->to($back) : redirect()->route('ext.ventacart.listings.edit', [$store, $productId]))
            ->with($answer['tone'], $answer['message']);
    }

    private function pushOne(VentaCartStore $setting, int $productId): array
    {
        $store = (int) $setting->id;
        $listing = VentaCartListing::query()->firstOrNew(['store_id' => $store, 'product_id' => $productId]);

        $priceFor = $listing->hasPriceRule() ? fn (float $base) => $listing->priceFor($base) : null;

        $outcome = VentaCartProductPush::for($setting)->push(
            $productId,
            $listing->overrides(),
            $priceFor,
            VentaCartListing::categoryFor($listing)
        );

        $states = \Extensions\VentaCart\Services\VentaCart\VentaCartListingStates::for($store);
        if (! $outcome['ok']) {
            $states->recordOutcome($productId, 'Push failed: ' . (string) $outcome['message']);

            return ['ok' => false, 'message' => (string) $outcome['message'], 'tone' => 'error'];
        }
        $states->recordOutcome($productId, null);

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
            ! empty($payload['status']) ? VentaCartListing::STATUS_ACTIVE : VentaCartListing::STATUS_INACTIVE,
            isset($payload['price']) ? (float) $payload['price'] : null,
            isset($payload['quantity']) ? (int) $payload['quantity'] : null
        );

        ActivityLogger::log('pushed', 'VentaCartListing', (int) $listing->id,
            ucfirst($outcome['state']) . ' product #' . $productId . ' on ' . $setting->store_name,
            ['ventacart_id' => $outcome['ventacart_id'], 'settings' => $listing->last_push_settings]);

        $sentence = $outcome['message'] . ' ' . VentaCartProductPush::imagesSentence($outcome['images']);
        $tone = ($outcome['images']['sent'] > 0 && $outcome['images']['stored'] < $outcome['images']['sent']) ? 'warning' : 'status';

        return ['ok' => true, 'message' => trim($sentence), 'tone' => $tone];
    }

    public function bulkPush(Request $request, int $store)
    {
        $setting = VentaCartStore::where('id', $store)->where('enabled', true)->first();
        if (! $setting) {
            return redirect()->back()->with('error', 'That VentaCart store is turned off, so nothing was sent to it. Enable it in its settings first.');
        }

        $answer = \App\Channels\Push\BulkPushRun::over(
            (array) $request->input('product_ids', []),
            (string) $setting->store_name,
            fn (int $productId) => $this->pushOne($setting, $productId)
        );

        return redirect()->back()->with($answer['key'], $answer['message']);
    }

    public function toggle(Request $request, int $store, int $productId)
    {
        $request->validate(['action' => 'required|in:unlist,relist']);
        $active = $request->input('action') === 'relist';

        $setting = VentaCartStore::where('id', $store)->where('enabled', true)->first();
        if (! $setting) {
            return redirect()->back()->with('error', 'That VentaCart store is turned off, so nothing was changed on it.');
        }

        $link = VentaCartListingLink::where('store_id', $store)->where('product_id', $productId)->first();
        if (! $link || ! $link->ventacart_product_id) {
            return redirect()->back()->with('error', ($active ? 'Relist' : 'Unlist') . ' failed: this product is not on ' . $setting->store_name . '.');
        }

        $result = VentaCartProductPush::for($setting)->setActive($productId, $active);
        \Extensions\VentaCart\Services\VentaCart\VentaCartListingStates::for($store)->recordOutcome($productId,
            $result['ok'] ? null : ($active ? 'Relist' : 'Unlist') . ' failed: ' . $result['message']);
        if (! $result['ok']) {
            return redirect()->back()->with('error', ($active ? 'Relist' : 'Unlist') . ' failed: ' . $result['message']);
        }

        $listing = VentaCartListing::query()->firstOrNew(['store_id' => $store, 'product_id' => $productId]);
        $listing->mirror($active ? VentaCartListing::STATUS_ACTIVE : VentaCartListing::STATUS_INACTIVE, $listing->live_price, $listing->live_quantity);

        ActivityLogger::log($active ? 'relisted' : 'unlisted', 'VentaCartListing', (int) $listing->id,
            ($active ? 'Relisted' : 'Unlisted') . ' product #' . $productId . ' on ' . $setting->store_name);

        return redirect()->back()->with('status', $result['message']);
    }
}
