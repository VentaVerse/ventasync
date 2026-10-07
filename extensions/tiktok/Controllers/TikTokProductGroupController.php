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
    use \App\Http\Controllers\Concerns\DrivesGroupSendRuns;

    protected function groupSendIntegration(): string
    {
        return 'tiktok';
    }

    protected function groupSendStoreId(Request $request): int
    {
        return (int) TikTokSetting::defaultStore()?->id;
    }

    protected function groupSendMembers(int $groupId): array
    {
        $group = TikTokProductGroup::query()->where('tiktok_setting_id', (int) TikTokSetting::defaultStore()?->id)->findOrFail($groupId);

        return $group->groupProducts()->whereIn('product_id', \Extensions\tiktok\Services\TikTokStoreProducts::query())->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
    }

    protected function groupSendChunk(int $groupId, array $productIds): array
    {
        return $this->sendProducts(TikTokProductGroup::query()->findOrFail($groupId), $productIds, app(TikTokClient::class));
    }

    private function writeProductStatus(int $storeId, int $productId, array $attributes): void
    {
        \App\Support\ChannelProductStatus::write(
            'tiktok_product_group_products',
            'tiktok_product_group_id',
            TikTokProductGroupProduct::groupIdsOn($storeId),
            $productId,
            $attributes
        );

        $states = $this->states($storeId);
        match ($attributes['sync_status'] ?? null) {
            'error' => $states->recordOutcome($productId, (string) ($attributes['push_error'] ?? '')),
            'pushed' => $states->recordOutcome($productId, null),
            'unlinked', 'pending' => $states->clearErrors([$productId]),
            default => null,
        };
    }

    private function states(int $storeId): \Extensions\tiktok\Services\TikTok\TikTokListingStates
    {
        return app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class)->forStore($storeId);
    }

    private function productsRedirect(int $id)
    {
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

    public function index()
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $groups = TikTokProductGroup::orderByDesc('id')->get();

        $ttCatIds = $groups->pluck('tiktok_category_id')->filter()->unique()->values()->all();
        $ttCategoryNames = collect();
        if (!empty($ttCatIds)) {
            $ttCategoryNames = TikTokCategory::whereIn('id', $ttCatIds)->pluck('name', 'id');
        }

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
        $tiktokCategories = TikTokCategory::where('is_leaf', true)->orderBy('name')->get(['id', 'name']);

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
            'markup_percent'     => $data['markup_percent'] ?? null, 'watermark_template_id' => \App\Models\WatermarkTemplate::idOrNull($data['watermark_template_id'] ?? null, 'tiktok', \App\Integrations\Listings\ListingStore::id('tiktok')),
            'markup_fixed'       => $data['markup_fixed'] ?? null,
        ]);

        $this->saveGroupAttributes($group, (array) $request->input('attributes', []));

        ActivityLogger::log('created', 'TikTok Product Group', $group->id, $group->name);

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
            'markup_percent'       => $data['markup_percent'] ?? null, 'watermark_template_id' => \App\Models\WatermarkTemplate::idOrNull($data['watermark_template_id'] ?? null, 'tiktok', \App\Integrations\Listings\ListingStore::id('tiktok')),
            'markup_fixed'         => $data['markup_fixed'] ?? null,
        ]);

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
        $group->delete();

        ActivityLogger::log('deleted', 'TikTok Product Group', $id, $name);

        return redirect()->route('ext.tiktok.product-groups.index')
            ->with('status', 'Product group "' . e($name) . '" deleted.');
    }

    public function products(Request $request, int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productIds = $this->getMatchingProductIds($group);

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

        $pivotMap = $group->groupProducts()
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

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

        $storeId = \App\Integrations\Listings\ListingStore::id('tiktok');
        $query->leftJoin('tiktok_product_group_products as gp', fn ($j) => $j->on('gp.product_id', '=', 'p.product_id')->where('gp.tiktok_product_group_id', '=', $group->id));
        $sortMenu = ['added' => 'gp.id', 'pushed' => 'gp.last_pushed_at'] + \Extensions\tiktok\Services\TikTokListingSort::columns($storeId);
        $order = \App\Integrations\Listings\ListingSort::chosen($request, 'tiktok.group.' . $group->id);
        \Extensions\tiktok\Services\TikTokListingSort::join($query, $storeId);
        \App\Integrations\Listings\ListingSort::apply($query, $order, $sortMenu);

        $products = $query->paginate(50)->withQueryString();

        $truthByProductId = DB::table('tiktok_product_group_products as tp')
            ->join('tiktok_product_groups as tg', 'tg.id', '=', 'tp.tiktok_product_group_id')
            ->where('tg.tiktok_setting_id', $group->tiktok_setting_id)
            ->whereIn('tp.product_id', $products->pluck('product_id')->all() ?: [0])
            ->whereNotNull('tp.tiktok_product_id')
            ->orderByDesc('tp.last_pushed_at')
            ->get(['tp.product_id', 'tp.tiktok_product_id', 'tp.last_pushed_at', 'tg.name as group_name'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->first());
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

        $pageIds = $products->pluck('product_id')->all();
        $optionRowsByProductId = \App\Support\VariationRows::forListing($pageIds, 'tiktok', (int) (\Extensions\tiktok\Models\TikTokSetting::defaultStore()?->id ?? 0));

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
            'order' => $order,
            'orderOptions' => \App\Integrations\Listings\ListingSort::options(array_keys($sortMenu)),
            'listingStates'        => app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class)
                ->forProducts(array_map('intval', $pageIds)),
            'rowErrors'            => app(\Extensions\tiktok\Services\TikTok\TikTokListingStates::class)
                ->errors(array_map('intval', $pageIds)),
            'q'                    => $q,
            'syncStatus'           => $syncStatus,
            'erpStatus'            => $erpStatus,
        ]);
    }

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
                'thumb' => $image !== '' ? \App\Services\Media\ImageCache::url($image) : null,
                'on_store' => $inGroup->has($pid),
                'elsewhere' => $owners->get($pid)?->name,
                'listed' => ! is_null($r->on_store_pid),
            ];
        })->values();

        return response()->json(['total' => $total, 'shown' => $items->count(), 'limit' => $limit, 'items' => $items]);
    }

    public function addProduct(Request $request, int $id, int $productId)
    {
        $group = TikTokProductGroup::query()->findOrFail($id);
        $inCatalogue = DB::table((string) config('catalog.prefix') . 'product')
            ->where('product_id', $productId)->where('status', 1)->exists();
        abort_unless($inCatalogue, 404);

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

        TikTokListing::query()->where('product_id', $product)->update(['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'live_status' => null, 'live_checked_at' => null]);
        $this->writeProductStatus((int) $group->tiktok_setting_id, $product, ['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'sync_status' => 'unlinked']);

        return $this->productsRedirect($id)
            ->with('status', 'Product unlinked from TikTok.');
    }

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

        $updateData = ['tiktok_product_id' => $matchedProductId, 'sync_status' => 'pushed'];
        if ($matchedSkuId) {
            $updateData['tiktok_sku_id'] = $matchedSkuId;
        }
        $pivot->update($updateData);

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

        $specificIds = $request->input('ids');
        if (!empty($specificIds)) {
            $intIds = array_map('intval', (array) $specificIds);
            $existingIds = $group->groupProducts()->whereIn('product_id', $intIds)->pluck('product_id')->toArray();
            $missingIds = array_diff($intIds, $existingIds);
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

        $r = $this->sendRows($group, $pivotRows, $c, $client);

        return $this->productsRedirect($id)->with($r['tone'], $r['summary']);
    }

    public function sendProducts(TikTokProductGroup $group, array $productIds, TikTokClient $client): array
    {
        $pivotRows = $group->groupProducts()->whereIn('product_id', $productIds)->get();
        if ($pivotRows->isEmpty()) {
            return ['tone' => 'status', 'summary' => 'No products to push.', 'failed' => 0, 'stop' => false];
        }

        return $this->sendRows($group, $pivotRows, $this->creds(), $client);
    }

    private function sendRows(TikTokProductGroup $group, $pivotRows, array $c, TikTokClient $client): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $erpProducts = $this->erpProducts($pfx, $langId, $pivotRows->pluck('product_id')->all());
        $opts = $this->groupPushOptions($group, $c);
        $pusher = app(TikTokProductPush::class);

        app(TikTokAttributes::class)->ensureTemplate((string) $group->tiktok_category_id, $client, $c);
        $readinessAll = app(\Extensions\tiktok\Services\TikTok\TikTokListingReadiness::class)->forProducts($pivotRows->pluck('product_id')->map(fn ($v) => (int) $v)->all());

        $created = 0;
        $updated = 0;
        $failed = 0;
        $warnings = [];

        foreach ($pivotRows as $pivot) {
            $erp = $erpProducts->get($pivot->product_id);
            if (!$erp) {
                $pivot->update(['sync_status' => 'error', 'push_error' => 'ERP product not found']);
                $this->states((int) $group->tiktok_setting_id)->recordOutcome((int) $pivot->product_id, 'ERP product not found');
                $failed++;
                continue;
            }

            $listing = TikTokListing::query()->where('product_id', $pivot->product_id)->first();
            $known = $pivot->tiktok_product_id ?: $listing?->tiktok_product_id;
            if ($known) {
                $outcome = $this->editLive($group, $pivot, $listing, (string) $known, $erp, $opts, $c, $client, $pusher);
                if ($outcome['ok']) {
                    $updated++;
                    if (!empty($outcome['warning'])) {
                        $warnings[] = '#' . $pivot->product_id . ': ' . $outcome['warning'];
                    }
                } else {
                    $failed++;
                }
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

        $summary = "Push: {$created} created, {$updated} updated";
        if ($failed > 0) $summary .= ", {$failed} failed";
        if ($warnings) {
            $summary .= '. ' . implode(' ', array_slice($warnings, 0, 3)) . (count($warnings) > 3 ? ' (and ' . (count($warnings) - 3) . ' more)' : '');
        }
        return ['tone' => $created + $updated === 0 ? 'error' : ($failed > 0 || $warnings ? 'warning' : 'status'), 'summary' => $summary, 'failed' => $failed, 'stop' => false];
    }

    private function editLive(TikTokProductGroup $group, TikTokProductGroupProduct $pivot, ?TikTokListing $listing, string $ttId, object $erp, array $opts, array $c, TikTokClient $client, TikTokProductPush $pusher): array
    {
        $existingRaw = $pivot->tiktok_sku_id ?: ($listing?->tiktok_sku_id);
        $outcome = $pusher->edit($erp, $ttId, $existingRaw, array_merge($opts, $this->listingOptions((int) $erp->product_id, $group, $c, $client), $this->ownWords((int) $erp->product_id)), $c, $client);
        if (! $outcome['ok']) {
            $pivot->update(['sync_status' => 'error', 'push_error' => $outcome['message']]);
            $this->states((int) $group->tiktok_setting_id)->recordOutcome((int) $pivot->product_id, (string) $outcome['message']);

            return ['ok' => false, 'message' => (string) $outcome['message']];
        }
        $pivot->update(array_filter([
            'tiktok_product_id' => $ttId,
            'sync_status'    => 'pushed',
            'last_pushed_at' => now(),
            'push_error'     => null,
            'tiktok_sku_id'  => $outcome['sku_ids'],
        ], fn ($v, $k) => $k !== 'tiktok_sku_id' || $v !== null, ARRAY_FILTER_USE_BOTH));
        TikTokListing::recordPush((int) $pivot->product_id, $ttId, $outcome['sku_ids'] ?? $existingRaw, 'group:' . $group->name, isset($outcome['sku_ids']));
        $this->states((int) $group->tiktok_setting_id)->recordOutcome((int) $pivot->product_id, null);

        return ['ok' => true, 'warning' => $outcome['warning'] ?? null];
    }

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

            $outcome = $this->editLive($group, $pivot, $listing, (string) $ttId, $erp, $opts, $c, $client, $pusher);
            if ($outcome['ok']) {
                $updated++;
                if (!empty($outcome['warning'])) {
                    $warnings[] = '#' . $pivot->product_id . ': ' . $outcome['warning'];
                }
            } else {
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

    public function pushPrices(Request $request, int $id)
    {
        return $this->pushFigures($request, $id, 'price');
    }

    public function pushStock(Request $request, int $id)
    {
        return $this->pushFigures($request, $id, 'stock');
    }

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

        $results = app(\Extensions\tiktok\Services\TikTok\TikTokStockPricePush::class)->push(
            $what === 'price' ? 'price' : 'stock', $c, $pivotRows, $pfx,
            \Extensions\tiktok\Commands\TikTokPushPrice::priceRule($pivotRows)
        );
        $ok = $results['ok'];
        $fail = $results['err'];

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
