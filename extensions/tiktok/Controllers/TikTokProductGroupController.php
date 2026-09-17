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
use Extensions\tiktok\Services\TikTokStoreProducts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TikTokProductGroupController extends Controller
{
    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Write a product's status to every group that holds it on one store.
     *
     * The status is stored once per group, so one product could read
     * differently on two pages: a stale error sends the operator chasing a
     * problem that is fixed, and a stale success hides a real failure behind a
     * green badge somewhere else. A store's groups refer to the same shop and
     * the same remote product; another store's groups do not, so they are
     * left alone.
     */
    private function writeProductStatus(int $storeId, int $productId, array $attributes): void
    {
        \App\Support\ChannelProductStatus::write(
            'tiktok_product_group_products',
            'tiktok_product_group_id',
            TikTokProductGroupProduct::groupIdsOn($storeId),
            $productId,
            $attributes
        );

        // The listing's error line follows: a failure writes it, a success
        // clears it, and a cut link takes it away.
        $states = $this->states($storeId);
        match ($attributes['sync_status'] ?? null) {
            'error' => $states->recordOutcome($productId, (string) ($attributes['push_error'] ?? '')),
            'pushed' => $states->recordOutcome($productId, null),
            'unlinked', 'pending' => $states->clearErrors([$productId]),
            default => null,
        };
    }

    /** The listing-state engine, pinned to one store. */
    private function states(int $storeId): \Extensions\tiktok\Services\TikTok\TikTokListingStates
    {
        return app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class)->forStore($storeId);
    }

    private function productsRedirect(int $id)
    {
        // Only a local path is followed: '//host' and '/\\host' would leave the site.
        $fallback = route('ext.tiktok.product-groups.products', $id);

        return redirect(\App\Support\BackTo::safe(request()->input('_return'), $fallback));
    }

    private function creds(): array
    {
        $s = TikTokSetting::defaultStore();
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

    /** Pivot-only since the cleanup (point 2, the owner 2026-09-03). */
    private function getMatchingProductIds(TikTokProductGroup $group): array
    {
        return $group->exists
            ? $group->groupProducts()->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all()
            : [];
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

        // Product counts per group
        $productCounts = [];
        foreach ($groups as $g) {
            $productCounts[$g->id] = count($this->getMatchingProductIds($g));
        }

        return view('ext-tiktok::product-groups.index', compact(
            'groups', 'ttCategoryNames', 'productCounts'
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
        // TikTok leaf categories for dropdown
        $tiktokCategories = TikTokCategory::where('is_leaf', true)->orderBy('name')->get(['id', 'name']);

        /* No product list here since #201 (the owner, 2026-09-05): this form is
         * the group's own settings only. Products join and leave the group
         * on its products page, through the Add products panel over THIS
         * STORE's list - never a whole-catalogue dual list. */

        // The category's attribute sheet (cached; read once when the page
        // opens with a category that was never read) and the group's answers.
        $attributesService = app(TikTokAttributes::class);
        $template = null;
        if (!empty($group->tiktok_category_id)) {
            $template = $attributesService->ensureTemplate((string) $group->tiktok_category_id, app(TikTokClient::class), $this->creds());
        }
        $attributeRows = $attributesService->rows($template);
        $savedAttributes = $group->exists ? $group->attributes()->pluck('value', 'attribute_key')->all() : [];

        $productCount = $group->exists
            ? \Illuminate\Support\Facades\DB::table('tiktok_product_group_products')->where('tiktok_product_group_id', $group->id)->count()
            : 0;

        return view('ext-tiktok::product-groups.form', compact(
            'group', 'mode', 'tiktokCategories',
            'template', 'attributeRows', 'savedAttributes', 'productCount'
        ));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'tiktok_category_id' => 'required|string|exists:tiktok_categories,id',
            'markup_percent'     => 'nullable|numeric|min:0',
            'watermark_template_id' => 'nullable|integer',
            'markup_fixed'       => 'nullable|numeric|min:0',
        ]);

        $group = TikTokProductGroup::create([
            'name'               => $data['name'],
            'tiktok_category_id' => $data['tiktok_category_id'],
            'markup_percent'     => $data['markup_percent'] ?? null, 'watermark_template_id' => \App\Models\WatermarkTemplate::idOrNull($data['watermark_template_id'] ?? null),
            'markup_fixed'       => $data['markup_fixed'] ?? null,
        ]);

        $this->saveGroupAttributes($group, (array) $request->input('attributes', []));

        ActivityLogger::log('created', 'TikTok Product Group', $group->id, $group->name);

        // The next thing to do with a new group is put products in it, and
        // that happens on its products page (#201), so land there.
        /* Back to the page that sent you here, the store's list or the
           groups list, and the new group's own page when nothing did. */
        return redirect(\App\Support\BackTo::safe(
            $request->input('_return'),
            route('ext.tiktok.product-groups.products', $group->id)
        ))->with('status', 'Product group created. Add products to it from this store\'s list.');
    }

    public function update(Request $request, int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);

        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'tiktok_category_id' => 'required|string|exists:tiktok_categories,id',
            'markup_percent'     => 'nullable|numeric|min:0',
            'watermark_template_id' => 'nullable|integer',
            'markup_fixed'       => 'nullable|numeric|min:0',
        ]);

        $group->update([
            'name'                 => $data['name'],
            'tiktok_category_id'   => $data['tiktok_category_id'],
            'markup_percent'       => $data['markup_percent'] ?? null, 'watermark_template_id' => \App\Models\WatermarkTemplate::idOrNull($data['watermark_template_id'] ?? null),
            'markup_fixed'         => $data['markup_fixed'] ?? null,
        ]);

        // Membership is not this form's business since #201: a stray
        // product_ids[] in the request changes nothing.
        $this->saveGroupAttributes($group, (array) $request->input('attributes', []));

        ActivityLogger::log('updated', 'TikTok Product Group', $group->id, $group->name);

        return redirect()->route('ext.tiktok.product-groups.edit', $group->id)
            ->with('status', 'Product group saved.');
    }

    public function destroy(int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $name = $group->name;
        $this->states((int) $group->tiktok_setting_id)->clearErrors($group->groupProducts()->pluck('product_id')->all());
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
                'rowTitles'            => [],
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
         * Listed/Not listed are the product's fact across this store's
         * groups; 'error' is the product's error line on this store. */
        if ($syncStatus === 'pushed' || $syncStatus === 'pending') {
            $listedIds = DB::table('tiktok_product_group_products')
                ->whereIn('tiktok_product_group_id', TikTokProductGroupProduct::groupIdsOn((int) $group->tiktok_setting_id))
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
            $productIds = array_values(array_intersect($productIds, $this->states((int) $group->tiktok_setting_id)->erroredProductIds()));
        } elseif ($syncStatus === 'unlinked') {
            $productIds = array_values(array_filter($productIds, fn($pid) => ($pivotMap->get($pid)->sync_status ?? '') === 'unlinked'));
        }

        if (empty($productIds)) {
            return view('ext-tiktok::product-groups.products', [
                'group'                => $group,
                'products'             => $emptyPaginator,
                'rowTitles'            => [],
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
            $query->where(function ($w) use ($q, $group) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%")
                    ->orWhere('p.model', 'like', "%{$q}%")
                    // The row shows this store's own title, so the search finds it by that too.
                    ->orWhereIn('p.product_id', TikTokListing::query()
                        ->forStore((int) $group->tiktok_setting_id)
                        ->where('title', 'like', "%{$q}%")
                        ->select('product_id'));
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
            ->where('tg.tiktok_setting_id', $group->tiktok_setting_id)
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
        $optionRowsByProductId = \App\Support\VariationRows::forListing($pageIds, 'tiktok', (int) (\Extensions\tiktok\Models\TikTokSetting::defaultStore()?->id ?? 0));

        // Each row is named by this store's own title, the catalog's name when it has none.
        $rowTitles = \App\Integrations\Listings\ListingContent::rowTitles($products, TikTokListing::query()
            ->forStore((int) $group->tiktok_setting_id)
            ->whereIn('product_id', $pageIds ?: [0])
            ->get());

        return view('ext-tiktok::product-groups.products', [
            'group'                => $group,
            'products'             => $products,
            'rowTitles'            => $rowTitles,
            'pivotMap'             => $pivotMap,
            'truthByProductId'     => $truthByProductId,
            'manualIds'            => $manualIds,
            'optionRowsByProductId' => $optionRowsByProductId,
            /* ONE state per row (#188): the same engine the listings page
             * renders, so badges and menus agree across surfaces. */
            'listingStates'        => app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class)
                ->forProducts(array_map('intval', $pageIds)),
            // The Last sync column (the owner, 2026-09-05), one answer shared with the Listings page.
            'lastSync'             => app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class)
                ->errors(array_map('intval', $pageIds)),
            'q'                    => $q,
            'syncStatus'           => $syncStatus,
            'erpStatus'            => $erpStatus,
        ]);
    }

    /**
     * The group page's Add products panel search (#201, Draft C - the owner
     * 2026-09-05, the Shopee shape): THIS STORE's products, never the whole
     * catalogue - a group only holds what the store carries, so a product
     * the store does not have is not offered here; it is added on Listings
     * first. Each row is flagged with whether it is already in this group,
     * or owned by another group under the one-group rule (named, so the
     * panel offers Move here rather than a refusal after the press). Capped
     * like the Listings panel; defaults to what is NOT yet in this group.
     */
    public function productSearch(Request $request, int $id)
    {
        $group = TikTokProductGroup::query()->findOrFail($id);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->query('q', ''));
        $showAll = $request->boolean('all');
        $limit = 25;

        $members = TikTokProductGroupProduct::query()
            ->where('tiktok_product_group_id', $group->id)
            ->select('product_id');

        $query = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            // The main catalogue, each row flagged with whether this store
            // carries it (the owner, 2026-09-05, option 2): a fresh store has
            // nothing listed yet, and a group must not be a dead end there.
            // Adding a product the store does not carry lists it as it joins.
            ->leftJoinSub(TikTokStoreProducts::query(), 'os', 'os.product_id', '=', 'p.product_id')
            ->select('p.product_id', 'pd.name', 'p.image', 'p.sku', 'p.model', 'p.quantity', 'p.price', 'os.product_id as on_store_pid')
            ->where('p.status', 1);

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.model', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%');
            });
        }
        if (! $showAll) {
            $query->whereNotIn('p.product_id', $members);
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('pd.name')->orderBy('p.product_id')->limit($limit)->get();
        $ids = $rows->pluck('product_id')->map(fn ($v) => (int) $v)->all();

        $inGroup = $ids === [] ? collect() : TikTokProductGroupProduct::query()
            ->where('tiktok_product_group_id', $group->id)
            ->whereIn('product_id', $ids)
            ->pluck('product_id')->map(fn ($v) => (int) $v)->flip();

        $owners = $ids === [] ? collect() : DB::table('tiktok_product_group_products as pv')
            ->join('tiktok_product_groups as g', 'g.id', '=', 'pv.tiktok_product_group_id')
            ->whereIn('pv.product_id', $ids)
            ->where('g.id', '!=', $group->id)
            ->where('g.tiktok_setting_id', $group->tiktok_setting_id)
            ->get(['pv.product_id', 'g.name'])
            ->keyBy('product_id');

        $items = $rows->map(function ($r) use ($inGroup, $owners) {
            $pid = (int) $r->product_id;
            $image = trim((string) ($r->image ?? ''));

            return [
                'id' => $pid,
                'name' => html_entity_decode((string) ($r->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Unnamed product',
                'sku' => (string) ($r->sku ?? ''),
                'quantity' => (int) ($r->quantity ?? 0),
                'price' => \App\Support\Money::base((float) ($r->price ?? 0)),
                'thumb' => $image !== '' ? asset('storage/' . ltrim($image, '/')) : null,
                // The panel's "already in" flag: in this group.
                'on_store' => $inGroup->has($pid),
                'elsewhere' => $owners->get($pid)?->name,
                // Whether this store carries it yet; adding lists it if not.
                'listed' => ! is_null($r->on_store_pid),
            ];
        })->values();

        return response()->json(['total' => $total, 'shown' => $items->count(), 'limit' => $limit, 'items' => $items]);
    }

    /**
     * Add one of this store's products to the group, in place (#201, Draft
     * C). Refuses a product the store does not carry: the group is a subset
     * of the store's list, never a door into the catalogue. The one-group
     * rule holds - a product owned by another group is held unless the panel
     * said Move here (`move`), in which case it moves.
     */
    public function addProduct(Request $request, int $id, int $productId)
    {
        $group = TikTokProductGroup::query()->findOrFail($id);
        $inCatalogue = DB::table((string) config('catalog.prefix') . 'product')
            ->where('product_id', $productId)->where('status', 1)->exists();
        abort_unless($inCatalogue, 404);

        // Option 2 (the owner, 2026-09-05): a product this store does not carry
        // yet is listed on it as it joins, the same blank listing record the
        // Listings page's Add products creates.
        $listedNow = false;
        if (! TikTokStoreProducts::has($productId)) {
            \Extensions\tiktok\Models\TikTokListing::firstOrCreate(['product_id' => $productId]);
            $listedNow = true;
        }

        $already = $group->groupProducts()->where('product_id', $productId)->exists();
        $added = false;
        $movedFrom = null;
        $held = [];

        if (! $already) {
            $claim = \App\Integrations\OneGroupRule::claim(
                'tiktok_product_group_products', 'tiktok_product_group_id', 'tiktok_product_groups', 'tiktok_setting_id',
                $group->id, [$productId], $request->boolean('move') ? [$productId] : []
            );
            foreach (array_merge($claim['free'], array_keys($claim['moved'])) as $pid) {
                TikTokProductGroupProduct::create([
                    'tiktok_product_group_id' => $group->id,
                    'product_id' => (int) $pid,
                    'sync_status' => 'pending',
                ]);
                $added = true;
            }
            $movedFrom = $claim['moved'][$productId] ?? null;
            $held = $claim['held'];
        }

        if ($request->wantsJson()) {
            if (! $already && ! $added) {
                // Held by another group and no Move here: say so, do nothing.
                return response()->json(['ok' => false, 'product_id' => $productId, 'message' => trim(\App\Integrations\OneGroupRule::heldClause($held))], 409);
            }

            return response()->json(['ok' => true, 'product_id' => $productId, 'added' => $added, 'moved_from' => $movedFrom, 'listed' => $listedNow]);
        }

        $status = $already
            ? "Product #{$productId} is already in this product group."
            : ($added
                ? "Product #{$productId} added to the product group." . ($movedFrom !== null ? " Moved here from '{$movedFrom}'." : '') . ($listedNow ? ' It is on this store now too.' : '')
                : trim(\App\Integrations\OneGroupRule::heldClause($held)));

        return $this->productsRedirect($group->id)->with($added ? 'status' : 'warning', $status);
    }

    public function unlinkProduct(int $id, int $product)
    {
        $group = TikTokProductGroup::findOrFail($id);

        /* The LISTING row lets go too (#184 D): it kept its link before,
         * so the product still read "Listed" and offered Update and Delete
         * against a link the operator had just cut. Same writes as the
         * row-menu unlink, mirror included. */
        TikTokListing::query()->where('product_id', $product)->update(['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'live_status' => null, 'live_checked_at' => null]);
        $this->writeProductStatus((int) $group->tiktok_setting_id, $product, ['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'sync_status' => 'unlinked']);

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
            return redirect()->back()->with('error', 'Product not in this product group.');
        }

        $raw = TikTokSetting::defaultStore();
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
            $this->states((int) $group->tiktok_setting_id)->recordOutcome($product, 'No matching product found on TikTok Shop for SKU(s): ' . implode(', ', $skus));

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
                // Try to find from this store's other groups
                $ttId = DB::table('tiktok_product_group_products')
                    ->where('tiktok_product_group_id', '!=', $group->id)
                    ->whereIn('tiktok_product_group_id', TikTokProductGroupProduct::groupIdsOn((int) $group->tiktok_setting_id))
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
        $this->states((int) $group->tiktok_setting_id)->clearErrors([$product]);

        return $this->productsRedirect($id)
            ->with('status', 'Product removed from the product group.');
    }

    /**
     * Move products from the store's Listings page: into a group, from one
     * group to another, or out of every group. A product belongs to one group
     * on a store, so choosing a group moves it there rather than holding it
     * back; the choice on the picker is the operator's "Move here".
     */
    public function moveProducts(Request $request)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])), fn ($v) => $v > 0)));
        $back = \App\Support\BackTo::safe($request->input('_return'), route('ext.tiktok.products.index'));
        if ($ids === []) {
            return redirect($back)->with('warning', 'No products selected.');
        }
        $target = (string) $request->input('group', '');
        if ($target === 'none') {
            $removed = TikTokProductGroupProduct::query()->onStore()->whereIn('product_id', $ids)->delete();

            return redirect($back)->with('status', $removed . ' ' . \Illuminate\Support\Str::plural('product', $removed) . ' ungrouped.');
        }
        $group = TikTokProductGroup::query()->findOrFail((int) $target);

        $already = TikTokProductGroupProduct::where('tiktok_product_group_id', $group->id)->whereIn('product_id', $ids)->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $wanted = array_values(array_diff($ids, $already));
        $claim = \App\Integrations\OneGroupRule::claim('tiktok_product_group_products', 'tiktok_product_group_id', 'tiktok_product_groups', 'tiktok_setting_id', $group->id, $wanted, $wanted);
        $moved = 0;
        foreach (array_merge($claim['free'], array_keys($claim['moved'])) as $pid) {
            TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => (int) $pid, 'sync_status' => 'pending']);
            $moved++;
        }
        if ($moved === 0) {
            return redirect($back)->with('warning', 'Those products are already in ' . $group->name . '.');
        }

        return redirect($back)->with('status', $moved . ' ' . \Illuminate\Support\Str::plural('product', $moved) . ' moved to ' . $group->name . '.');
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
        $this->states((int) $group->tiktok_setting_id)->clearErrors($ids);

        return $this->productsRedirect($id)
            ->with('status', $deleted . ' product(s) removed from the product group.');
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
        $productsByTt = $pivots->groupBy(fn ($p) => (string) $p->tiktok_product_id)
            ->map(fn ($rows) => $rows->pluck('product_id')->map(fn ($v) => (int) $v)->all())->all();
        $states = $this->states((int) $group->tiktok_setting_id);
        $deleted = 0;
        $deletedIds = [];
        $errors = [];

        // TikTok limits delete to 20 product IDs per request
        foreach (array_chunk($ttIds, 20) as $chunk) {
            $result = $client->deleteProducts($c['app_key'], $c['app_secret'], $c['token'], $chunk, $c['shop_cipher']);
            $this->logApi('DELETE', '/product/202309/products', $result);

            $chunkIds = array_merge(...array_map(fn ($tt) => $productsByTt[(string) $tt] ?? [], $chunk));
            $apiCode = (int) ($result['body']['code'] ?? -1);
            if (($result['ok'] ?? false) && $apiCode === 0) {
                $deleted += count($chunk);
                $deletedIds = array_merge($deletedIds, $chunkIds);
            } else {
                $message = (string) ($result['body']['message'] ?? 'Unknown error');
                $errors[] = $message;
                foreach ($chunkIds as $failedId) {
                    $states->recordOutcome($failedId, 'Delete: ' . $message);
                }
            }
        }

        if ($deletedIds !== []) {
            // Clear the link everywhere it lives (#184 D): the pivots AND
            // the listing row, which used to keep pointing at a product
            // deleted at TikTok - so it still read "Listed" and offered
            // Update against a dead id. Mirror voided with it.
            TikTokListing::query()->whereIn('product_id', $deletedIds)
                ->update(['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'live_status' => null, 'live_checked_at' => null]);
            foreach (array_unique($deletedIds) as $unlinkedId) {
                $this->writeProductStatus((int) $group->tiktok_setting_id, (int) $unlinkedId, [
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
    /**
     * The store listing's own title and description for one product, in the
     * shape TikTokProductPush takes (2026-09-07). A group push used to carry
     * neither, so the builder fell back to the catalog and the listing's own
     * words, saved and shown on its page, were never sent.
     *
     * @return array{title: ?string, description: ?string}
     */
    private function ownWords(int $productId): array
    {
        $own = TikTokListing::query()->where('product_id', $productId)->first()?->contentOverrides() ?? [];
        $filled = fn (?string $v) => ($v !== null && trim($v) !== '') ? $v : null;

        return ['title' => $filled($own['title'] ?? null), 'description' => $filled($own['description'] ?? null)];
    }

    private function groupPushOptions(TikTokProductGroup $group, array $c): array
    {
        return ['warehouse_id' => $c['warehouse_id'] ?: null];
    }

    /**
     * What one product sends from a group push: the listing's own category,
     * brand, attribute answers and price rule, with the group filling only
     * what the listing leaves blank. The same values the listing page's own
     * push sends, so a product goes up the same whichever page sent it.
     */
    private function listingOptions(int $productId, TikTokProductGroup $group, array $c, TikTokClient $client): array
    {
        $inherit = app(\Extensions\tiktok\Services\TikTok\TikTokInheritedSettings::class);
        $own = TikTokListing::query()->where('product_id', $productId)->first();
        $listing = $inherit->fill(
            $own ?? (new TikTokListing())->forceFill(['product_id' => $productId]),
            $inherit->forProducts([$productId])[$productId] ?? null
        )['listing'];
        $categoryId = trim((string) ($listing->tiktok_category_id ?? '')) !== ''
            ? (string) $listing->tiktok_category_id
            : (string) $group->tiktok_category_id;
        $service = app(TikTokAttributes::class);
        $template = $service->ensureTemplate($categoryId, $client, $c);

        return [
            'category_id' => $categoryId,
            'brand_id' => ($listing->brand_id ?? null) ?: null,
            'attributes' => $service->payload($service->rows($template), (array) ($listing->attribute_values ?? [])),
            'priceFor' => \Extensions\tiktok\Services\TikTok\TikTokInheritedSettings::priceRule($own, $group),
        ];
    }

    /**
     * Check every product in this group against TikTok Shop (parity #156):
     * one walk of the shop, nothing sent. A listed product whose TikTok
     * product is gone loses its link and says so; an unlisted product
     * whose seller SKU already sits on the shop is linked to it.
     */
    /**
     * Ask TikTok Shop about this group's products and make the ERP
     * agree. The sweep itself lives in TikTokLinkCheck, shared with the
     * workbench (Phase F, #184); this entry point only supplies the
     * group's members as the default scope.
     */
    public function checkAgainstTikTok(Request $request, int $id, \Extensions\tiktok\Services\TikTok\TikTokLinkCheck $check)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $c = $this->creds();
        $productIds = array_filter(array_map('intval', (array) $request->input('ids', [])));
        if (empty($productIds)) {
            $productIds = $group->groupProducts()->pluck('product_id')->map('intval')->all();
        }
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'This product group has no products to check.');
        }

        $r = $check->run($c, $productIds);

        return $this->productsRedirect($id)->with($r['tone'], $r['summary']);
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
            ->merge(TikTokProductGroupProduct::query()->onStore((int) $group->tiktok_setting_id)->whereNotNull('tiktok_product_id')->pluck('tiktok_product_id')->map(fn ($v) => (string) $v))
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
            // A product in another of this store's groups stays there and is not pushed here.
            $claim = \App\Integrations\OneGroupRule::claim(
                'tiktok_product_group_products', 'tiktok_product_group_id', 'tiktok_product_groups', 'tiktok_setting_id',
                $group->id, $missingIds
            );
            foreach ($claim['free'] as $pid) {
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

        /* THE GATE (the owner, 2026-09-06): the readiness the Ready column
         * shows is the one this push refuses through, and the attribute
         * answers it judged (the listing's own over the group's) are the
         * ones sent. */
        // A category never read is read now, so readiness can judge its sheet.
        app(TikTokAttributes::class)->ensureTemplate((string) $group->tiktok_category_id, $client, $c);
        $readinessAll = app(\Extensions\tiktok\Services\TikTok\TikTokListingReadiness::class)->forProducts($pivotRows->pluck('product_id')->map(fn ($v) => (int) $v)->all());

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $warnings = [];

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
                $this->states((int) $group->tiktok_setting_id)->recordOutcome((int) $pivot->product_id, 'ERP product not found');
                $failed++;
                continue;
            }

            $readiness = $readinessAll[(int) $pivot->product_id] ?? ['ready' => false, 'missing' => []];
            if (!$readiness['ready']) {
                $refusal = \App\Integrations\Listings\CatalogGaps::refusal($readiness);
                $pivot->update(['sync_status' => 'error', 'push_error' => $refusal]);
                $this->states((int) $group->tiktok_setting_id)->recordOutcome((int) $pivot->product_id, $refusal);
                $failed++;
                continue;
            }
            // The listing's own values over the group's, and its own words.
            $outcome = $pusher->create($erp, array_merge($opts, $this->listingOptions((int) $erp->product_id, $group, $c, $client), $this->ownWords((int) $erp->product_id)), $c, $client);
            if ($outcome['ok']) {
                $pivot->update([
                    'tiktok_product_id' => $outcome['product_id'],
                    'tiktok_sku_id'     => $outcome['sku_ids'],
                    'sync_status'       => 'pushed',
                    'last_pushed_at'    => now(),
                    'push_error'        => null,
                ]);
                TikTokListing::recordPush((int) $pivot->product_id, $outcome['product_id'], $outcome['sku_ids'], 'group:' . $group->name);
                $this->states((int) $group->tiktok_setting_id)->recordOutcome((int) $pivot->product_id, null);
                $created++;
                if (!empty($outcome['pictures'])) {
                    $warnings[] = '#' . $pivot->product_id . ': ' . $outcome['pictures'];
                }
            } else {
                $pivot->update(['sync_status' => 'error', 'push_error' => $outcome['message']]);
                $this->states((int) $group->tiktok_setting_id)->recordOutcome((int) $pivot->product_id, (string) $outcome['message']);
                $failed++;
            }
        }

        $summary = "Push: {$created} created";
        if ($skipped > 0) $summary .= ", {$skipped} skipped (already linked)";
        if ($failed > 0) $summary .= ", {$failed} failed";
        if ($warnings) {
            $summary .= '. ' . implode(' ', array_slice($warnings, 0, 3)) . (count($warnings) > 3 ? ' (and ' . (count($warnings) - 3) . ' more)' : '');
        }
        return $this->productsRedirect($id)->with($created === 0 ? 'error' : ($warnings ? 'warning' : 'status'), $summary);
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
        $warnings = [];

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
                $this->states((int) $group->tiktok_setting_id)->recordOutcome((int) $pivot->product_id, 'ERP product not found');
                $failed++;
                continue;
            }

            $existingRaw = $pivot->tiktok_sku_id ?: ($listing?->tiktok_sku_id);
            $outcome = $pusher->edit($erp, (string) $ttId, $existingRaw, array_merge($opts, $this->listingOptions((int) $erp->product_id, $group, $c, $client), $this->ownWords((int) $erp->product_id)), $c, $client);
            if ($outcome['ok']) {
                $pivot->update(array_filter([
                    'tiktok_product_id' => $ttId,
                    'sync_status'    => 'pushed',
                    'last_pushed_at' => now(),
                    'push_error'     => null,
                    'tiktok_sku_id'  => $outcome['sku_ids'],
                ], fn ($v, $k) => $k !== 'tiktok_sku_id' || $v !== null, ARRAY_FILTER_USE_BOTH));
                TikTokListing::recordPush((int) $pivot->product_id, (string) $ttId, $outcome['sku_ids'] ?? $existingRaw, 'group:' . $group->name, isset($outcome['sku_ids']));
                $this->states((int) $group->tiktok_setting_id)->recordOutcome((int) $pivot->product_id, null);
                $updated++;
                if (!empty($outcome['warning'])) {
                    $warnings[] = '#' . $pivot->product_id . ': ' . $outcome['warning'];
                }
            } else {
                $pivot->update(['sync_status' => 'error', 'push_error' => $outcome['message']]);
                $this->states((int) $group->tiktok_setting_id)->recordOutcome((int) $pivot->product_id, (string) $outcome['message']);
                $failed++;
            }
        }

        $summary = "Update: {$updated} updated";
        if ($skipped > 0) $summary .= ", {$skipped} skipped (not linked)";
        if ($failed > 0) $summary .= ", {$failed} failed";
        if ($warnings) {
            $summary .= '. ' . implode(' ', array_slice($warnings, 0, 3)) . (count($warnings) > 3 ? ' (and ' . (count($warnings) - 3) . ' more)' : '');
        }
        $tone = $updated === 0 ? 'error' : ($failed > 0 || $warnings ? 'warning' : 'status');

        return $this->productsRedirect($id)->with($tone, $summary);
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

        /* The shared sender (Phase F, #184): the skus[0].id hack becomes a
         * real map repair, skips are named, per-SKU refusals are read, and
         * a failed batch can no longer flash green. */
        $results = app(\Extensions\tiktok\Services\TikTok\TikTokStockPricePush::class)->push(
            $what === 'price' ? 'price' : 'stock', $c, $pivotRows, $pfx,
            \Extensions\tiktok\Commands\TikTokPushPrice::priceRule($pivotRows)
        );
        $ok = $results['ok'];
        $fail = $results['err'];

        /* The verdicts land on the pivots this page reads - a stock or
         * price failure used to leave zero durable trace anywhere. */
        foreach ($results['outcomes'] as $pid => $outcome) {
            $this->writeProductStatus((int) $group->tiktok_setting_id, (int) $pid, $outcome['ok']
                ? ['sync_status' => 'pushed', 'push_error' => null]
                : ['sync_status' => 'error', 'push_error' => mb_substr('' . ($what === 'price' ? 'Price push: ' : 'Stock push: ') . $outcome['error'], 0, 255)]);
        }

        if ($what === 'stock') {
            $c['setting']->update(['last_stock_push_at' => now()]);
        }

        return $this->productsRedirect($id)->with(
            \App\Integrations\Push\PushLedger::batchTone($ok, $fail),
            ($what === 'price' ? 'Prices' : 'Stock') . " pushed: {$ok} ok, {$fail} failed."
                . \Extensions\tiktok\Services\TikTok\TikTokStockPricePush::ledgerClause($results)
        );
    }

}
