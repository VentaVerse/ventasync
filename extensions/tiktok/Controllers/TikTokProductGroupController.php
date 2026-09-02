<?php

namespace Extensions\tiktok\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokCategory;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokProductGroupAttribute;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Extensions\tiktok\Services\TikTok\TikTokStockPushService;
use Extensions\tiktok\Services\TikTok\TikTokVariationPush;
use Extensions\tiktok\Services\TikTok\TikTokAttributes;
use Extensions\tiktok\Services\TikTok\TikTokProductPush;
use Extensions\tiktok\Services\TikTok\TikTokLiveListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TikTokProductGroupController extends Controller
{
    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Write a product's status to EVERY group that holds it.
     *
     * The status was stored once per group, so one product could read
     * differently on two pages: a stale error sends the operator chasing a
     * problem that is fixed, and a stale success hides a real failure behind a
     * green badge somewhere else. TikTok is single-store, so every group
     * refers to the same shop and the same remote product.
     */
    private function writeProductStatus(int $productId, array $attributes): void
    {
        \App\Support\ChannelProductStatus::write(
            'tiktok_product_group_products',
            'tiktok_product_group_id',
            null,
            $productId,
            $attributes
        );
    }

    private function productsRedirect(int $id)
    {
        $return = request()->input('_return');
        if ($return && str_starts_with($return, '/')) {
            return redirect($return);
        }
        return redirect()->route('ext.tiktok.product-groups.products', $id);
    }

    private function creds(): array
    {
        $s = TikTokSetting::first();
        if (!$s) {
            abort(404, 'TikTok settings not configured.');
        }
        $d = $s->decrypted();
        $sandbox = $s->mode === 'sandbox';

        return [
            'setting'      => $s,
            'sandbox'      => $sandbox,
            'app_key'      => $sandbox ? ($d->sandbox_app_key ?? '') : ($d->app_key ?? ''),
            'app_secret'   => $sandbox ? ($d->sandbox_app_secret ?? '') : ($d->app_secret ?? ''),
            'token'        => $sandbox ? ($d->sandbox_access_token ?? '') : ($d->access_token ?? ''),
            'shop_cipher'  => $sandbox ? ($s->sandbox_shop_cipher ?? '') : ($s->shop_cipher ?? ''),
            'warehouse_id' => $sandbox ? ($s->sandbox_warehouse_id ?? '') : ($s->warehouse_id ?? ''),
        ];
    }

    private function getMatchingProductIds(TikTokProductGroup $group): array
    {
        $pfx = (string) config('catalog.prefix');
        $catIds = $group->catalog_category_ids ?? [];
        $mfgIds = $group->manufacturer_ids ?? [];

        $filterIds = [];

        if (!empty($catIds) || !empty($mfgIds)) {
            $query = DB::table($pfx . 'product as p')->select('p.product_id');

            if (!empty($catIds)) {
                $query->join($pfx . 'product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                    ->whereIn('ptc.category_id', $catIds);
            }
            if (!empty($mfgIds)) {
                $query->whereIn('p.manufacturer_id', $mfgIds);
            }

            $filterIds = $query->distinct()->pluck('p.product_id')->toArray();
        }

        // Manual products
        $manualIds = [];
        if ($group->exists) {
            $manualIds = $group->groupProducts()->pluck('product_id')->toArray();
        }

        return array_values(array_unique(array_merge($filterIds, $manualIds)));
    }

    private function logApi(string $method, string $path, array $result): void
    {
        TikTokApiLog::safeCreate([
            'pack'            => 'product-sync',
            'method'          => $method,
            'api_path'        => $path,
            'auth_required'   => true,
            'request_params'  => [],
            'response_status' => $result['status'] ?? 0,
            'ok'              => $result['ok'] ?? false,
            'response_body'   => $result['body'] ?? [],
            'user_id'         => auth()->id(),
        ]);
    }

    // ── Categories: list view ───────────────────────────────────

    public function indexCategories(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $query = TikTokCategory::query()->orderBy('name');
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', '%' . $q . '%')
                    ->orWhere('id', 'like', '%' . $q . '%');
            });
        }
        $categories = $query->paginate(50)->withQueryString();
        return view('ext-tiktok::categories.index', compact('categories', 'q'));
    }

    // ── Category Sync ───────────────────────────────────────────

    public function syncCategories()
    {
        $c = $this->creds();
        $client = app(TikTokClient::class);

        $result = $client->getCategories($c['app_key'], $c['app_secret'], $c['token'], $c['shop_cipher']);
        $this->logApi('GET', '/product/202309/categories', $result);

        if (!($result['ok'] ?? false)) {
            return back()->with('status', 'Failed to fetch categories: ' . json_encode($result['body']['message'] ?? 'unknown error'));
        }

        $categories = $result['body']['data']['categories'] ?? [];
        if (empty($categories)) {
            return back()->with('status', 'No categories returned from TikTok.');
        }

        $now = now();
        foreach ($categories as $cat) {
            TikTokCategory::updateOrCreate(
                ['id' => (string) $cat['id']],
                [
                    'parent_id'           => isset($cat['parent_id']) && $cat['parent_id'] !== '0' ? (string) $cat['parent_id'] : null,
                    'name'                => $cat['local_name'] ?? $cat['name'] ?? '',
                    'is_leaf'             => (bool) ($cat['is_leaf'] ?? false),
                    'permission_statuses' => $cat['permission_statuses'] ?? null,
                    'synced_at'           => $now,
                ]
            );
        }

        if (request()->expectsJson()) {
            $cats = TikTokCategory::where('is_leaf', true)->orderBy('name')->get(['id', 'name']);
            return response()->json(['ok' => true, 'count' => count($categories), 'categories' => $cats]);
        }

        return back()->with('status', count($categories) . ' categories synced.');
    }

    // ── CRUD ────────────────────────────────────────────────────

    public function index()
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $groups = TikTokProductGroup::orderByDesc('id')->get();

        // Category names from TikTok cache
        $ttCatIds = $groups->pluck('tiktok_category_id')->filter()->unique()->values()->all();
        $ttCategoryNames = collect();
        if (!empty($ttCatIds)) {
            $ttCategoryNames = TikTokCategory::whereIn('id', $ttCatIds)->pluck('name', 'id');
        }

        // ERP category names
        $catIds = $groups->pluck('catalog_category_ids')->filter()->flatten()->unique()->values()->all();
        $categoryNames = collect();
        if (!empty($catIds)) {
            $categoryNames = DB::table($pfx . 'category_description')
                ->whereIn('category_id', $catIds)
                ->where('language_id', $langId)
                ->pluck('name', 'category_id');
        }

        // Manufacturer names
        $mfgIds = $groups->pluck('manufacturer_ids')->filter()->flatten()->unique()->values()->all();
        $manufacturerNames = collect();
        if (!empty($mfgIds)) {
            $manufacturerNames = DB::table($pfx . 'manufacturer')
                ->whereIn('manufacturer_id', $mfgIds)
                ->pluck('name', 'manufacturer_id');
        }

        // Product counts per group
        $productCounts = [];
        foreach ($groups as $g) {
            $productCounts[$g->id] = count($this->getMatchingProductIds($g));
        }

        return view('ext-tiktok::product-groups.index', compact(
            'groups', 'ttCategoryNames', 'categoryNames', 'manufacturerNames', 'productCounts'
        ));
    }

    public function create()
    {
        return $this->form(new TikTokProductGroup(), 'create');
    }

    public function edit(int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        return $this->form($group, 'edit');
    }

    private function form(TikTokProductGroup $group, string $mode)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        // TikTok leaf categories for dropdown
        $tiktokCategories = TikTokCategory::where('is_leaf', true)->orderBy('name')->get(['id', 'name']);

        $catalogCategories = DB::table($pfx . 'category_description')
            ->where('language_id', $langId)
            ->orderBy('name')
            ->get(['category_id', 'name']);

        $manufacturers = DB::table($pfx . 'manufacturer')
            ->orderBy('name')
            ->get(['manufacturer_id', 'name']);

        // Load current group products for the dual-listbox
        // On validation failure, restore from old('product_ids') so selections aren't lost
        $oldProductIds = old('product_ids');
        $groupProducts = collect();

        if (!empty($oldProductIds)) {
            $productIds = array_filter(array_map('intval', (array) $oldProductIds));
        } elseif ($group->exists) {
            $productIds = $group->groupProducts()->pluck('product_id')->toArray();
        } else {
            $productIds = [];
        }

        if (!empty($productIds)) {
            $groupProducts = DB::table($pfx . 'product as p')
                ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                    $j->on('p.product_id', '=', 'pd.product_id')
                        ->where('pd.language_id', '=', $langId);
                })
                ->whereIn('p.product_id', $productIds)
                ->select('p.product_id', 'pd.name', 'p.sku', 'p.model', 'p.quantity')
                ->orderBy('pd.name')
                ->get();
        }

        // The category's attribute sheet (cached; read once when the page
        // opens with a category that was never read) and the group's answers.
        $attributesService = app(TikTokAttributes::class);
        $template = null;
        if (!empty($group->tiktok_category_id)) {
            $template = $attributesService->ensureTemplate((string) $group->tiktok_category_id, app(TikTokClient::class), $this->creds());
        }
        $attributeRows = $attributesService->rows($template);
        $savedAttributes = $group->exists ? $group->attributes()->pluck('value', 'attribute_key')->all() : [];

        return view('ext-tiktok::product-groups.form', compact(
            'group', 'mode', 'tiktokCategories', 'catalogCategories', 'manufacturers', 'groupProducts',
            'template', 'attributeRows', 'savedAttributes'
        ));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'tiktok_category_id' => 'required|string|exists:tiktok_categories,id',
            'markup_percent'     => 'nullable|numeric|min:0',
            'markup_fixed'       => 'nullable|numeric|min:0',
        ]);

        $group = TikTokProductGroup::create([
            'name'               => $data['name'],
            'tiktok_category_id' => $data['tiktok_category_id'],
            'markup_percent'     => $data['markup_percent'] ?? null,
            'markup_fixed'       => $data['markup_fixed'] ?? null,
        ]);

        // Add products from dual-listbox
        $productIds = array_filter(array_map('intval', (array) $request->input('product_ids', [])));
        foreach ($productIds as $pid) {
            TikTokProductGroupProduct::create([
                'tiktok_product_group_id' => $group->id,
                'product_id'              => $pid,
                'sync_status'             => 'pending',
            ]);
        }

        $this->saveGroupAttributes($group, (array) $request->input('attributes', []));

        ActivityLogger::log('created', 'TikTok Product Group', $group->id, $group->name);

        return redirect()->route('ext.tiktok.product-groups.edit', $group->id)
            ->with('status', 'Product group created with ' . count($productIds) . ' product(s).');
    }

    public function update(Request $request, int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);

        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'tiktok_category_id' => 'required|string|exists:tiktok_categories,id',
            'markup_percent'     => 'nullable|numeric|min:0',
            'markup_fixed'       => 'nullable|numeric|min:0',
        ]);

        $group->update([
            'name'                 => $data['name'],
            'tiktok_category_id'   => $data['tiktok_category_id'],
            'catalog_category_ids' => null,
            'manufacturer_ids'     => null,
            'markup_percent'       => $data['markup_percent'] ?? null,
            'markup_fixed'         => $data['markup_fixed'] ?? null,
        ]);

        // Sync products from dual-listbox
        $submittedIds = array_filter(array_map('intval', (array) $request->input('product_ids', [])));
        $existingPivots = $group->groupProducts()->get()->keyBy('product_id');
        $existingIds = $existingPivots->keys()->map(fn($k) => (int) $k)->toArray();

        // Add new products
        foreach (array_diff($submittedIds, $existingIds) as $pid) {
            TikTokProductGroupProduct::create([
                'tiktok_product_group_id' => $group->id,
                'product_id'              => $pid,
                'sync_status'             => 'pending',
            ]);
        }

        // Remove products no longer in the list
        $toRemove = array_diff($existingIds, $submittedIds);
        if (!empty($toRemove)) {
            $group->groupProducts()->whereIn('product_id', $toRemove)->delete();
        }

        $this->saveGroupAttributes($group, (array) $request->input('attributes', []));

        ActivityLogger::log('updated', 'TikTok Product Group', $group->id, $group->name);

        return redirect()->route('ext.tiktok.product-groups.edit', $group->id)
            ->with('status', 'Product group saved.');
    }

    public function destroy(int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $name = $group->name;
        $group->delete(); // cascade deletes pivot rows

        ActivityLogger::log('deleted', 'TikTok Product Group', $id, $name);

        return redirect()->route('ext.tiktok.product-groups.index')
            ->with('status', 'Product group "' . e($name) . '" deleted.');
    }

    // ── Product Assignment ──────────────────────────────────────

    public function products(Request $request, int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productIds = $this->getMatchingProductIds($group);

        /* The GET-time back-fill that used to live here - copying TikTok
         * ids between groups' pivot rows while rendering - is GONE
         * (one-truth port, 2026-08-25). The page now derives the product's
         * truth ACROSS groups directly, so a read once again writes
         * nothing, for any tier. */
        $emptyPaginator = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 50);

        if (empty($productIds)) {
            return view('ext-tiktok::product-groups.products', [
                'group'                => $group,
                'products'             => $emptyPaginator,
                'pivotMap'             => collect(),
                'truthByProductId'     => collect(),
                'manualIds'            => [],
                'optionRowsByProductId' => collect(),
                'q'                    => '',
                'syncStatus'           => 'all',
                'erpStatus'            => 'all',
            ]);
        }

        $q = trim((string) $request->input('q'));
        $syncStatus = $request->input('sync_status', 'all');
        $erpStatus = $request->input('erp_status', 'all');

        // Pivot data for sync status display + filtering
        $pivotMap = $group->groupProducts()
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        /* The filter answers what the column shows (one-truth port):
         * Listed/Not listed are the product's fact across ALL groups;
         * 'error' stays per-group - this group's own last attempt. */
        if ($syncStatus === 'pushed' || $syncStatus === 'pending') {
            $listedIds = DB::table('tiktok_product_group_products')
                ->whereIn('product_id', $productIds)
                ->whereNotNull('tiktok_product_id')
                ->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->flip();
            $productIds = array_values(array_filter(
                $productIds,
                fn ($pid) => $syncStatus === 'pushed'
                    ? $listedIds->has((int) $pid)
                    : !$listedIds->has((int) $pid)
            ));
        } elseif ($syncStatus === 'error') {
            $productIds = array_values(array_filter($productIds, fn($pid) => ($pivotMap->get($pid)->sync_status ?? '') === 'error'));
        } elseif ($syncStatus === 'unlinked') {
            $productIds = array_values(array_filter($productIds, fn($pid) => ($pivotMap->get($pid)->sync_status ?? '') === 'unlinked'));
        }

        if (empty($productIds)) {
            return view('ext-tiktok::product-groups.products', [
                'group'                => $group,
                'products'             => $emptyPaginator,
                'pivotMap'             => $pivotMap,
                'truthByProductId'     => collect(),
                'manualIds'            => $group->groupProducts()->pluck('product_id')->toArray(),
                'optionRowsByProductId' => collect(),
                'q'                    => $q,
                'syncStatus'           => $syncStatus,
                'erpStatus'            => $erpStatus,
            ]);
        }

        $query = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->whereIn('p.product_id', $productIds)
            ->select('p.product_id', 'pd.name', 'p.model', 'p.sku', 'p.price', 'p.quantity', 'p.status', 'p.image', 'p.date_modified', 'm.name as manufacturer_name');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%")
                    ->orWhere('p.model', 'like', "%{$q}%");
            });
        }

        if ($erpStatus === 'enabled') {
            $query->where('p.status', 1);
        } elseif ($erpStatus === 'disabled') {
            $query->where('p.status', 0);
        }

        $products = $query->orderBy('p.product_id', 'desc')->paginate(50)->withQueryString();

        /* PRODUCT truth, derived ACROSS groups (the one-truth port,
         * 2026-08-25). TikTok has no listings table - the pivot rows are
         * the only record - so whether a product is ON TikTok is answered
         * by ANY group's pivot holding its id, latest push first. Nine
         * groups can no longer tell nine stories about one product, and
         * the provenance names which group's push made it true. */
        $truthByProductId = DB::table('tiktok_product_group_products as tp')
            ->join('tiktok_product_groups as tg', 'tg.id', '=', 'tp.tiktok_product_group_id')
            ->whereIn('tp.product_id', $products->pluck('product_id')->all() ?: [0])
            ->whereNotNull('tp.tiktok_product_id')
            ->orderByDesc('tp.last_pushed_at')
            ->get(['tp.product_id', 'tp.tiktok_product_id', 'tp.last_pushed_at', 'tg.name as group_name'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->first());
        // The listing record is the truth once a push has written it
        // (#156); the pivots answer for products pushed before that.
        foreach (TikTokListing::query()->whereIn('product_id', $products->pluck('product_id')->all() ?: [0])->whereNotNull('tiktok_product_id')->get() as $l) {
            $truthByProductId[(int) $l->product_id] = (object) [
                'product_id' => (int) $l->product_id,
                'tiktok_product_id' => $l->tiktok_product_id,
                'last_pushed_at' => $l->last_pushed_at,
                'group_name' => str_starts_with((string) $l->last_push_source, 'group:')
                    ? substr((string) $l->last_push_source, 6)
                    : ($truthByProductId[(int) $l->product_id]->group_name ?? null),
            ];
        }

        $manualIds = $group->groupProducts()->pluck('product_id')->toArray();

        // Variation rows: the shared combinations-first query the core
        // product list uses (App\Support\VariationRows).
        $pageIds = $products->pluck('product_id')->all();
        $optionRowsByProductId = \App\Support\VariationRows::forProducts($pageIds);

        return view('ext-tiktok::product-groups.products', [
            'group'                => $group,
            'products'             => $products,
            'pivotMap'             => $pivotMap,
            'truthByProductId'     => $truthByProductId,
            'manualIds'            => $manualIds,
            'optionRowsByProductId' => $optionRowsByProductId,
            'q'                    => $q,
            'syncStatus'           => $syncStatus,
            'erpStatus'            => $erpStatus,
        ]);
    }

    public function addProducts(Request $request, int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $ids = array_map('intval', (array) $request->input('ids', []));

        if (empty($ids)) {
            return $this->productsRedirect($id)
                ->with('status', 'No products selected.');
        }

        $existing = $group->groupProducts()->pluck('product_id')->toArray();
        $added = 0;

        foreach ($ids as $pid) {
            if (in_array($pid, $existing)) continue;
            TikTokProductGroupProduct::create([
                'tiktok_product_group_id' => $group->id,
                'product_id'              => $pid,
                'sync_status'             => 'pending',
            ]);
            $added++;
        }

        return $this->productsRedirect($id)
            ->with('status', "{$added} product(s) added.");
    }

    public function unlinkProduct(int $id, int $product)
    {
        $group = TikTokProductGroup::findOrFail($id);

        /* The LISTING row lets go too (#184 D): it kept its link before,
         * so the product still read "Listed" and offered Update and Delete
         * against a link the operator had just cut. Same writes as the
         * row-menu unlink, mirror included. */
        TikTokListing::query()->where('product_id', $product)->update(['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'live_status' => null, 'live_checked_at' => null]);
        $this->writeProductStatus($product, ['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'sync_status' => 'unlinked']);

        return $this->productsRedirect($id)
            ->with('status', 'Product unlinked from TikTok.');
    }

    /**
     * Sync TikTok product ID for a single product by matching seller_sku via search API.
     */
    public function syncId(int $id, int $product)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $pivot = $group->groupProducts()->where('product_id', $product)->first();
        if (!$pivot) {
            return redirect()->back()->with('error', 'Product not in this group.');
        }

        $raw = TikTokSetting::query()->first();
        if (!$raw) {
            return redirect()->back()->with('error', 'TikTok settings not configured.');
        }
        $s = $raw->decrypted();
        $sandbox = $raw->mode === 'sandbox';
        $appKey = $sandbox ? ($s->sandbox_app_key ?? '') : ($s->app_key ?? '');
        $appSecret = $sandbox ? ($s->sandbox_app_secret ?? '') : ($s->app_secret ?? '');
        $token = $sandbox ? ($s->sandbox_access_token ?? '') : ($s->access_token ?? '');
        $shopCipher = $sandbox ? ($raw->sandbox_shop_cipher ?? '') : ($raw->shop_cipher ?? '');

        if (!$appKey || !$appSecret || !$token) {
            return redirect()->back()->with('error', 'Missing TikTok credentials.');
        }

        $pfx = (string) config('catalog.prefix');
        $client = app(TikTokClient::class);

        // Collect all SKUs for this product
        $productRow = DB::table($pfx . 'product')->where('product_id', $product)->first(['sku', 'model']);
        $skus = [];
        if ($productRow->sku && trim($productRow->sku) !== '') $skus[] = strtolower(trim($productRow->sku));
        if ($productRow->model && trim($productRow->model) !== '') $skus[] = strtolower(trim($productRow->model));

        $optSkus = DB::table($pfx . 'product_option_value')
            ->where('product_id', $product)
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->pluck('sku')->map(fn($s) => strtolower(trim($s)))->unique()->toArray();
        $skus = array_unique(array_merge($skus, $optSkus));

        if (empty($skus)) {
            return redirect()->back()->with('error', 'Product has no SKU to match against TikTok.');
        }

        // Search all TikTok products (paginated)
        $matchedProductId = null;
        $matchedSkuId = null;
        $pageToken = null;

        for ($page = 0; $page < 30; $page++) {
            $result = $client->searchProducts($appKey, $appSecret, $token, 50, $pageToken, $shopCipher);

            if (!($result['ok'] ?? false)) break;

            $products = $result['body']['data']['products'] ?? [];
            if (empty($products)) break;

            foreach ($products as $p) {
                $ttProductId = $p['id'] ?? null;
                if (!$ttProductId) continue;

                foreach ($p['skus'] ?? [] as $sku) {
                    $sellerSku = strtolower(trim($sku['seller_sku'] ?? ''));
                    if ($sellerSku !== '' && in_array($sellerSku, $skus)) {
                        $matchedProductId = (string) $ttProductId;
                        $matchedSkuId = $sku['id'] ?? null;
                        break 3;
                    }
                }
            }

            $pageToken = $result['body']['data']['next_page_token'] ?? null;
            if (!$pageToken) break;
            usleep(300000);
        }

        if (!$matchedProductId) {
            return redirect()->back()->with('error', 'No matching product found on TikTok for SKU(s): ' . implode(', ', $skus));
        }

        // Update pivot with matched TikTok product ID
        $updateData = ['tiktok_product_id' => $matchedProductId, 'sync_status' => 'pushed'];
        if ($matchedSkuId) {
            $updateData['tiktok_sku_id'] = $matchedSkuId;
        }
        $pivot->update($updateData);

        // The LISTING row learns the match too (#184 D) - it is the truth
        // source every state read prefers - with the mirror honestly blank
        // until the page filler or the sweep asks TikTok.
        TikTokListing::query()->updateOrCreate(['product_id' => (int) $pivot->product_id], [
            'tiktok_product_id' => (string) $matchedProductId,
            'tiktok_sku_id' => $matchedSkuId ?: null,
            'live_status' => null, 'live_checked_at' => null,
        ]);

        return redirect()->back()->with('status', "TikTok product ID {$matchedProductId} synced.");
    }

    public function linkProduct(int $id, int $product)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $pivot = TikTokProductGroupProduct::where('tiktok_product_group_id', $group->id)
            ->where('product_id', $product)
            ->first();

        if ($pivot) {
            $status = 'pending';
            if ($pivot->tiktok_product_id) {
                $status = 'synced';
            } else {
                // Try to find from other groups
                $ttId = DB::table('tiktok_product_group_products')
                    ->where('tiktok_product_group_id', '!=', $group->id)
                    ->where('product_id', $product)
                    ->whereNotNull('tiktok_product_id')
                    ->value('tiktok_product_id');

                if ($ttId) {
                    $pivot->tiktok_product_id = $ttId;
                    $status = 'synced';
                }
            }
            $pivot->sync_status = $status;
            $pivot->save();
        }

        return $this->productsRedirect($id)
            ->with('status', 'Product re-linked.');
    }

    public function removeProduct(int $id, int $product)
    {
        $group = TikTokProductGroup::findOrFail($id);

        TikTokProductGroupProduct::where('tiktok_product_group_id', $group->id)
            ->where('product_id', $product)
            ->delete();

        return $this->productsRedirect($id)
            ->with('status', 'Product removed from group.');
    }

    public function massRemove(Request $request, int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));

        if (empty($ids)) {
            return $this->productsRedirect($id)
                ->with('status', 'No products selected.');
        }

        $deleted = TikTokProductGroupProduct::where('tiktok_product_group_id', $group->id)
            ->whereIn('product_id', $ids)
            ->delete();

        return $this->productsRedirect($id)
            ->with('status', $deleted . ' product(s) removed from group.');
    }

    public function deleteFromTikTok(Request $request, int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));

        if (empty($ids)) {
            return $this->productsRedirect($id)
                ->with('status', 'No products selected.');
        }

        $c = $this->creds();
        $client = app(TikTokClient::class);

        // Collect TikTok product IDs for selected products
        $pivots = TikTokProductGroupProduct::where('tiktok_product_group_id', $group->id)
            ->whereIn('product_id', $ids)
            ->whereNotNull('tiktok_product_id')
            ->get();

        if ($pivots->isEmpty()) {
            return $this->productsRedirect($id)
                ->with('status', 'No pushed products found among selected.');
        }

        $ttIds = $pivots->pluck('tiktok_product_id')->toArray();
        $deleted = 0;
        $errors = [];

        // TikTok limits delete to 20 product IDs per request
        foreach (array_chunk($ttIds, 20) as $chunk) {
            $result = $client->deleteProducts($c['app_key'], $c['app_secret'], $c['token'], $chunk, $c['shop_cipher']);
            $this->logApi('DELETE', '/product/202309/products', $result);

            $apiCode = (int) ($result['body']['code'] ?? -1);
            if (($result['ok'] ?? false) && $apiCode === 0) {
                $deleted += count($chunk);
            } else {
                $errors[] = $result['body']['message'] ?? 'Unknown error';
            }
        }

        if ($deleted > 0) {
            // Clear the link everywhere it lives (#184 D): the pivots AND
            // the listing row, which used to keep pointing at a product
            // deleted at TikTok - so it still read "Listed" and offered
            // Update against a dead id. Mirror voided with it.
            TikTokListing::query()->whereIn('product_id', $ids)
                ->update(['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'live_status' => null, 'live_checked_at' => null]);
            foreach ($ids as $unlinkedId) {
                $this->writeProductStatus((int) $unlinkedId, [
                    'tiktok_product_id' => null,
                    'sync_status' => 'pending',
                    'push_error' => null,
                ]);
            }
        }

        if (!empty($errors)) {
            return $this->productsRedirect($id)
                ->with('error', 'TikTok delete failed: ' . implode('; ', $errors));
        }

        return $this->productsRedirect($id)
            ->with('status', $deleted . ' product(s) deleted from TikTok Shop.');
    }

    public function searchProducts(Request $request)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->input('q'));
        $categoryId = (int) $request->input('category_id', 0);
        $manufacturerId = (int) $request->input('manufacturer_id', 0);

        // Empty filters return the latest 100 products (the LIMIT below) so
        // operators can browse everything without first picking a category
        // or typing a search term.

        $query = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->select('p.product_id', 'pd.name', 'p.sku', 'p.model', 'p.price', 'p.quantity');

        if ($categoryId > 0) {
            $query->join($pfx . 'product_to_category as ptc', 'p.product_id', '=', 'ptc.product_id')
                  ->where('ptc.category_id', $categoryId);
        }

        if ($manufacturerId > 0) {
            $query->where('p.manufacturer_id', $manufacturerId);
        }

        if (strlen($q) >= 2) {
            $query->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%")
                    ->orWhere('p.model', 'like', "%{$q}%");
            });
        }

        $rows = $query->distinct()
            ->orderBy('p.product_id', 'desc')
            ->limit(100)
            ->get();

        return response()->json(['items' => $rows]);
    }

    // ── Push Products ───────────────────────────────────────────

    /** The catalog rows a push reads, keyed by product id. */
    private function erpProducts(string $pfx, int $langId, array $productIds)
    {
        return DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->whereIn('p.product_id', $productIds ?: [0])
            ->select('p.product_id', 'pd.name', 'pd.description', 'p.model', 'p.sku', 'p.price', 'p.quantity', 'p.image', 'p.weight', 'p.length', 'p.width', 'p.height')
            ->get()
            ->keyBy('product_id');
    }

    /** The group's answers, replaced whole: blanks drop the row. */
    private function saveGroupAttributes(TikTokProductGroup $group, array $answers): void
    {
        $group->attributes()->delete();
        foreach ($answers as $key => $value) {
            $key = trim((string) $key);
            $value = trim((string) $value);
            if ($key === '' || $value === '') {
                continue;
            }
            TikTokProductGroupAttribute::create([
                'tiktok_product_group_id' => $group->id,
                'attribute_key' => $key,
                'value' => $value,
            ]);
        }
    }

    /**
     * The attribute sheet for a category, read from TikTok and cached,
     * rendered for the group form (and the listing page) when the
     * category changes. Same JSON contract as Shopee's: {ok, html}.
     */
    public function fetchAttributesAjax(Request $request)
    {
        $categoryId = trim((string) $request->input('tiktok_category_id', ''));
        if ($categoryId === '') {
            return response()->json(['ok' => false, 'html' => '']);
        }
        $service = app(TikTokAttributes::class);
        $template = $service->refreshTemplate($categoryId, app(TikTokClient::class), $this->creds());
        $rows = $service->rows($template);
        $saved = (array) $request->input('saved', []);

        return response()->json([
            'ok' => $template !== null,
            'html' => view('ext-tiktok::product-groups._attributes', [
                'rows' => $rows, 'saved' => $saved, 'template' => $template, 'canManage' => true,
            ])->render(),
        ]);
    }

    /** The push settings a group gives every product in it. */
    private function groupPushOptions(TikTokProductGroup $group, array $c): array
    {
        $service = app(TikTokAttributes::class);
        $template = $service->ensureTemplate((string) $group->tiktok_category_id, app(TikTokClient::class), $c);

        return [
            'category_id' => (string) $group->tiktok_category_id,
            'brand_id' => null,
            'attributes' => $service->payload($service->rows($template), $group->attributes()->pluck('value', 'attribute_key')->all()),
            'priceFor' => fn (float $base) => $group->applyMarkup($base),
            'warehouse_id' => $c['warehouse_id'] ?: null,
        ];
    }

    /**
     * Check every product in this group against TikTok Shop (parity #156):
     * one walk of the shop, nothing sent. A listed product whose TikTok
     * product is gone loses its link and says so; an unlisted product
     * whose seller SKU already sits on the shop is linked to it.
     */
    public function checkAgainstTikTok(Request $request, int $id, TikTokLiveListing $live)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $c = $this->creds();
        $productIds = array_filter(array_map('intval', (array) $request->input('ids', [])));
        if (empty($productIds)) {
            $productIds = $group->groupProducts()->pluck('product_id')->map('intval')->all();
        }
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'This group has no products to check.');
        }

        $shop = $live->shop($c);
        if ($shop['products'] === null) {
            return $this->productsRedirect($id)->with('error', 'Check against TikTok Shop: ' . $shop['error']);
        }
        $bySku = [];
        foreach ($shop['products'] as $ttId => $p) {
            foreach ($p['skus'] as $sku) {
                $bySku[strtolower($sku)] ??= (string) $ttId;
            }
        }

        $pfx = (string) config('catalog.prefix');
        $skusByProduct = [];
        foreach (DB::table($pfx . 'product')->whereIn('product_id', $productIds)->get(['product_id', 'sku', 'model']) as $p) {
            foreach ([$p->sku, $p->model] as $v) {
                if (trim((string) $v) !== '') $skusByProduct[(int) $p->product_id][] = strtolower(trim((string) $v));
            }
        }
        foreach (DB::table($pfx . 'product_option_value')->whereIn('product_id', $productIds)->whereNotNull('sku')->where('sku', '!=', '')->get(['product_id', 'sku']) as $r) {
            $skusByProduct[(int) $r->product_id][] = strtolower(trim((string) $r->sku));
        }
        foreach (DB::table('product_option_combinations')->whereIn('product_id', $productIds)->whereNotNull('sku')->where('sku', '!=', '')->get(['product_id', 'sku']) as $r) {
            $skusByProduct[(int) $r->product_id][] = strtolower(trim((string) $r->sku));
        }

        $verdicts = ['confirmed' => 0, 'linked' => 0, 'lost' => 0, 'not_on_tiktok' => 0];
        $problems = [];
        foreach ($productIds as $productId) {
            $listing = TikTokListing::query()->where('product_id', $productId)->first();
            $pivot = $group->groupProducts()->where('product_id', $productId)->first();
            $known = $listing?->tiktok_product_id ?: ($pivot?->tiktok_product_id);
            $hit = null;
            foreach ($skusByProduct[$productId] ?? [] as $sku) {
                if (isset($bySku[$sku])) {
                    $hit = $bySku[$sku];
                    break;
                }
            }

            if ($known) {
                if (isset($shop['products'][(string) $known])) {
                    TikTokListing::query()->updateOrCreate(['product_id' => $productId], ['tiktok_product_id' => (string) $known, 'last_checked_at' => now()]);
                    $this->writeProductStatus((int) $productId, ['tiktok_product_id' => (string) $known, 'sync_status' => 'pushed', 'push_error' => null]);
                    $verdicts['confirmed']++;
                } elseif ($hit !== null) {
                    // A fresh link voids the mirror: whatever status the row
                    // wore belonged to the previous link (#184 D).
                    TikTokListing::query()->updateOrCreate(['product_id' => $productId], ['tiktok_product_id' => $hit, 'tiktok_sku_id' => null, 'live_status' => null, 'live_checked_at' => null, 'last_checked_at' => now()]);
                    $this->writeProductStatus((int) $productId, ['tiktok_product_id' => $hit, 'tiktok_sku_id' => null, 'sync_status' => 'pushed', 'push_error' => null]);
                    $verdicts['linked']++;
                } else {
                    TikTokListing::query()->where('product_id', $productId)->update(['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'live_status' => null, 'live_checked_at' => null, 'last_checked_at' => now()]);
                    $this->writeProductStatus((int) $productId, ['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'sync_status' => 'error', 'push_error' => 'Not found on TikTok Shop; the link was dropped.']);
                    $problems[] = "#{$productId}: not found on TikTok Shop; the link was dropped";
                    $verdicts['lost']++;
                }
                continue;
            }

            if ($hit !== null) {
                // No last_push_source here: a check is not a push, and a
                // source with no timestamp read as provenance from nowhere.
                // Never pushed from the ERP means drift honestly unknowable.
                TikTokListing::query()->updateOrCreate(['product_id' => $productId], ['tiktok_product_id' => $hit, 'live_status' => null, 'live_checked_at' => null, 'last_checked_at' => now()]);
                $this->writeProductStatus((int) $productId, ['tiktok_product_id' => $hit, 'sync_status' => 'pushed', 'push_error' => null]);
                $verdicts['linked']++;
            } else {
                $verdicts['not_on_tiktok']++;
            }
        }

        $parts = [];
        if ($verdicts['confirmed']) $parts[] = "{$verdicts['confirmed']} confirmed on TikTok Shop";
        if ($verdicts['linked']) $parts[] = "{$verdicts['linked']} linked to the product TikTok Shop holds";
        if ($verdicts['lost']) $parts[] = "{$verdicts['lost']} no longer on TikTok Shop";
        if ($verdicts['not_on_tiktok']) $parts[] = "{$verdicts['not_on_tiktok']} not on TikTok Shop";
        $summary = 'Checked ' . count($productIds) . ' against TikTok Shop: ' . (implode(', ', $parts) ?: 'nothing to report') . '.';
        if ($problems) $summary .= ' ' . implode('; ', array_slice($problems, 0, 5)) . '.';

        return $this->productsRedirect($id)->with($verdicts['lost'] > 0 ? 'error' : 'status', $summary);
    }

    /** What TikTok Shop holds that no catalog product is linked to. Read-only. */
    public function orphans(Request $request, int $id, TikTokLiveListing $live)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $c = $this->creds();
        $shop = $live->shop($c);
        if ($shop['products'] === null) {
            return $this->productsRedirect($id)->with('error', 'Unlinked on TikTok Shop: ' . $shop['error']);
        }
        $known = TikTokListing::query()->whereNotNull('tiktok_product_id')->pluck('tiktok_product_id')->map(fn ($v) => (string) $v)
            ->merge(TikTokProductGroupProduct::query()->whereNotNull('tiktok_product_id')->pluck('tiktok_product_id')->map(fn ($v) => (string) $v))
            ->unique()->flip();

        $orphans = [];
        foreach ($shop['products'] as $ttId => $p) {
            if ($known->has((string) $ttId)) {
                continue;
            }
            $skus = $p['skus'];
            sort($skus);
            $orphans[] = ['id' => (string) $ttId, 'title' => $p['title'], 'status' => $p['status'], 'skus' => $skus];
        }

        return view('ext-tiktok::product-groups.orphans', [
            'group' => $group,
            'orphans' => $orphans,
            'scanned' => count($shop['products']),
            'complete' => count($shop['products']) < 1000,
        ]);
    }

    public function push(Request $request, int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $c = $this->creds();
        $client = app(TikTokClient::class);
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');


        // Get pivot rows to push (pending or error, unless specific IDs provided)
        $specificIds = $request->input('ids');
        if (!empty($specificIds)) {
            $intIds = array_map('intval', (array) $specificIds);
            // Ensure pivot rows exist for filter-matched products
            $existingIds = $group->groupProducts()->whereIn('product_id', $intIds)->pluck('product_id')->toArray();
            $missingIds = array_diff($intIds, $existingIds);
            foreach ($missingIds as $pid) {
                TikTokProductGroupProduct::create([
                    'tiktok_product_group_id' => $group->id,
                    'product_id' => $pid,
                    'sync_status' => 'pending',
                ]);
            }
            $pivotRows = $group->groupProducts()->whereIn('product_id', $intIds)->get();
        } else {
            $pivotRows = $group->groupProducts()->whereIn('sync_status', ['pending', 'error'])->get();
        }

        if ($pivotRows->isEmpty()) {
            return $this->productsRedirect($id)
                ->with('status', 'No products to push.');
        }

        $erpProducts = $this->erpProducts($pfx, $langId, $pivotRows->pluck('product_id')->all());
        $opts = $this->groupPushOptions($group, $c);
        $pusher = app(TikTokProductPush::class);

        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($pivotRows as $pivot) {
            // Already on TikTok (by this group or any other, or by the
            // listing page): Update is the verb for those.
            $known = $pivot->tiktok_product_id ?: TikTokListing::query()->where('product_id', $pivot->product_id)->value('tiktok_product_id');
            if ($known) {
                if (!$pivot->tiktok_product_id) {
                    $pivot->update(['tiktok_product_id' => $known, 'sync_status' => 'pushed']);
                }
                $skipped++;
                continue;
            }

            $erp = $erpProducts->get($pivot->product_id);
            if (!$erp) {
                $pivot->update(['sync_status' => 'error', 'push_error' => 'ERP product not found']);
                $failed++;
                continue;
            }

            $outcome = $pusher->create($erp, $opts, $c, $client);
            if ($outcome['ok']) {
                $pivot->update([
                    'tiktok_product_id' => $outcome['product_id'],
                    'tiktok_sku_id'     => $outcome['sku_ids'],
                    'sync_status'       => 'pushed',
                    'last_pushed_at'    => now(),
                    'push_error'        => null,
                ]);
                TikTokListing::recordPush((int) $pivot->product_id, $outcome['product_id'], $outcome['sku_ids'], 'group:' . $group->name);
                $created++;
            } else {
                $pivot->update(['sync_status' => 'error', 'push_error' => $outcome['message']]);
                $failed++;
            }
        }

        $summary = "Push: {$created} created";
        if ($skipped > 0) $summary .= ", {$skipped} skipped (already linked)";
        if ($failed > 0) $summary .= ", {$failed} failed";
        return $this->productsRedirect($id)->with($created > 0 ? 'status' : 'error', $summary);
    }

    // ── Update Product ──────────────────────────────────────────

    /**
     * Update existing TikTok listings with latest ERP data.
     * Injects TikTok SKU IDs into the payload so edits actually apply.
     */
    public function updateProduct(Request $request, int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $c = $this->creds();
        $client = app(TikTokClient::class);
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');


        $specificIds = $request->input('ids');
        if (empty($specificIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $intIds = array_map('intval', (array) $specificIds);
        $pivotRows = $group->groupProducts()->whereIn('product_id', $intIds)->get();

        if ($pivotRows->isEmpty()) {
            return $this->productsRedirect($id)->with('error', 'No products found.');
        }

        $erpProducts = $this->erpProducts($pfx, $langId, $pivotRows->pluck('product_id')->all());
        $opts = $this->groupPushOptions($group, $c);
        $pusher = app(TikTokProductPush::class);

        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($pivotRows as $pivot) {
            $listing = TikTokListing::query()->where('product_id', $pivot->product_id)->first();
            $ttId = $pivot->tiktok_product_id ?: ($listing?->tiktok_product_id);
            if (!$ttId) {
                $skipped++;
                continue;
            }
            $erp = $erpProducts->get($pivot->product_id);
            if (!$erp) {
                $pivot->update(['sync_status' => 'error', 'push_error' => 'ERP product not found']);
                $failed++;
                continue;
            }

            $existingRaw = $pivot->tiktok_sku_id ?: ($listing?->tiktok_sku_id);
            $outcome = $pusher->edit($erp, (string) $ttId, $existingRaw, $opts, $c, $client);
            if ($outcome['ok']) {
                $pivot->update(array_filter([
                    'tiktok_product_id' => $ttId,
                    'sync_status'    => 'pushed',
                    'last_pushed_at' => now(),
                    'push_error'     => null,
                    'tiktok_sku_id'  => $outcome['sku_ids'],
                ], fn ($v, $k) => $k !== 'tiktok_sku_id' || $v !== null, ARRAY_FILTER_USE_BOTH));
                TikTokListing::recordPush((int) $pivot->product_id, (string) $ttId, $outcome['sku_ids'] ?? $existingRaw, 'group:' . $group->name);
                $updated++;
            } else {
                $pivot->update(['sync_status' => 'error', 'push_error' => $outcome['message']]);
                $failed++;
            }
        }

        $summary = "Update: {$updated} updated";
        if ($skipped > 0) $summary .= ", {$skipped} skipped (not linked)";
        if ($failed > 0) $summary .= ", {$failed} failed";
        return $this->productsRedirect($id)->with($updated > 0 ? 'status' : 'error', $summary);
    }

    // ── Push Prices ─────────────────────────────────────────────

    public function pushPrices(Request $request, int $id)
    {
        return $this->pushFigures($request, $id, 'price');
    }

    // ── Push Stock ──────────────────────────────────────────────

    public function pushStock(Request $request, int $id)
    {
        return $this->pushFigures($request, $id, 'stock');
    }

    /**
     * Push stock or price for the group's linked products through the ONE
     * resolver the cron uses (TikTokStockPushService): combinations first,
     * then option values, then the bare product; legacy sku maps keyed by
     * option_value_id are understood until the next push rewrites them.
     */
    private function pushFigures(Request $request, int $id, string $what)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $c = $this->creds();
        $client = app(TikTokClient::class);
        $pfx = (string) config('catalog.prefix');

        $query = $group->groupProducts()->whereNotNull('tiktok_product_id');
        $specificIds = $request->input('ids');
        if (!empty($specificIds)) {
            $query->whereIn('product_id', array_map('intval', (array) $specificIds));
        }
        $pivotRows = $query->get();

        if ($pivotRows->isEmpty()) {
            return $this->productsRedirect($id)
                ->with('status', 'No pushed products to update ' . $what . ' for.');
        }

        // A bare product pushed before ids were stored: ask TikTok once.
        foreach ($pivotRows as $pivot) {
            if (!$pivot->tiktok_sku_id) {
                $detail = $client->getProduct($c['app_key'], $c['app_secret'], $c['token'], $pivot->tiktok_product_id, $c['shop_cipher']);
                $found = $detail['body']['data']['skus'][0]['id'] ?? null;
                if ($found) {
                    $pivot->update(['tiktok_sku_id' => (string) $found]);
                    $pivot->tiktok_sku_id = (string) $found;
                }
            }
        }

        $resolved = app(TikTokStockPushService::class)->resolve(
            $pivotRows, $pfx, fn (float $base, object $pivot) => $group->applyMarkup($base)
        );

        $ok = 0;
        $fail = 0;
        foreach ($resolved as $row) {
            $pivot = $row['pivot'];
            $skuPayload = [];
            foreach ($row['skus'] as $sku) {
                $skuPayload[] = $what === 'price'
                    ? ['id' => $sku['id'], 'price' => ['amount' => (string) round($sku['price']), 'currency' => 'PHP']]
                    : ['id' => $sku['id'], 'inventory' => [array_filter([
                        'warehouse_id' => $c['warehouse_id'] ?: null,
                        'quantity' => $sku['quantity'],
                    ], fn ($v) => $v !== null)]];
            }
            if (empty($skuPayload)) {
                $fail++;
                continue;
            }

            if ($what === 'price') {
                $result = $client->updatePrice($c['app_key'], $c['app_secret'], $c['token'], $pivot->tiktok_product_id, $skuPayload, $c['shop_cipher']);
                $this->logApi('POST', '/product/202309/products/' . $pivot->tiktok_product_id . '/prices/update', $result);
            } else {
                $result = $client->updateInventory($c['app_key'], $c['app_secret'], $c['token'], $pivot->tiktok_product_id, $skuPayload, $c['shop_cipher']);
                $this->logApi('POST', '/product/202309/products/' . $pivot->tiktok_product_id . '/inventory/update', $result);
            }

            $apiOk = ($result['ok'] ?? false) && (int) ($result['body']['code'] ?? -1) === 0;
            $apiOk ? $ok++ : $fail++;
        }

        if ($what === 'stock') {
            $c['setting']->update(['last_stock_push_at' => now()]);
        }

        return $this->productsRedirect($id)
            ->with('status', ($what === 'price' ? 'Prices' : 'Stock') . " pushed: {$ok} ok, {$fail} failed.");
    }

}
