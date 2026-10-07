<?php

namespace Extensions\lazada\Controllers;

use App\Http\Controllers\Controller;

use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaCategoryTemplate;
use Extensions\lazada\Models\LazadaCategory;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductAttribute;
use Extensions\lazada\Models\LazadaProductGroup;
use Extensions\lazada\Models\LazadaProductGroupAttribute;
use Extensions\lazada\Models\LazadaProductGroupProduct;
use Extensions\lazada\Models\LazadaProductVariant;
use Extensions\lazada\Models\LazadaSetting;
use App\Services\ActivityLogger;
use Extensions\lazada\Services\Lazada\LazadaAttributes;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Extensions\lazada\Services\Lazada\LazadaItemCache;
use Extensions\lazada\Services\Lazada\LazadaPushPayload;
use Extensions\lazada\Services\LazadaStockPushService;
use Extensions\lazada\Services\Lazada\LazadaLiveListing;
use Extensions\lazada\Services\Lazada\LazadaVariationSwitch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LazadaProductGroupController extends Controller
{
    use \App\Http\Controllers\Concerns\DrivesGroupSendRuns;

    protected function groupSendIntegration(): string
    {
        return 'lazada';
    }

    protected function groupSendStoreId(Request $request): int
    {
        return (int) LazadaSetting::defaultStore()?->id;
    }

    protected function groupSendMembers(int $groupId): array
    {
        $group = LazadaProductGroup::query()->where('lazada_setting_id', (int) LazadaSetting::defaultStore()?->id)->findOrFail($groupId);

        return $group->groupProducts()->whereIn('product_id', \Extensions\lazada\Models\LazadaProduct::query()->whereNotNull('product_id')->select('product_id'))->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
    }

    protected function groupSendChunk(int $groupId, array $productIds): array
    {
        return $this->sendProducts(LazadaProductGroup::query()->findOrFail($groupId), $productIds, app(LazadaClient::class));
    }


    private function attrs(): LazadaAttributes
    {
        return app(LazadaAttributes::class);
    }

    private function payload(): LazadaPushPayload
    {
        return app(LazadaPushPayload::class);
    }
    private function writeProductStatus(int $storeId, int $productId, array $attributes): void
    {
        \App\Support\ChannelProductStatus::write(
            'lazada_product_group_products',
            'lazada_product_group_id',
            LazadaProductGroupProduct::groupIdsOn($storeId),
            $productId,
            $attributes
        );

        $states = \Extensions\lazada\Services\Lazada\LazadaListingStates::on($storeId);
        if (($attributes['sync_status'] ?? null) === 'error') {
            $states->recordOutcome($productId, (string) ($attributes['push_error'] ?? ''));
        } elseif (($attributes['sync_status'] ?? null) === 'pushed') {
            $states->recordOutcome($productId, null);
        }
    }

    private function states(LazadaProductGroup $group): \Extensions\lazada\Services\Lazada\LazadaListingStates
    {
        return \Extensions\lazada\Services\Lazada\LazadaListingStates::on((int) $group->lazada_setting_id);
    }

    private function recordGroupFailure(LazadaProductGroup $group, ?LazadaProductGroupProduct $pivot, int $productId, string $message): void
    {
        if ($pivot) {
            $pivot->update(['sync_status' => 'error', 'push_error' => \Illuminate\Support\Str::limit($message, 477)]);
        }
        $this->states($group)->recordOutcome($productId, $message);
    }

    private function productsRedirect(int $id)
    {
        $fallback = route('ext.lazada.product-groups.products', $id);

        return redirect(\App\Support\BackTo::safe(request()->input('_return'), $fallback));
    }

    public function index()
    {
        $groups = LazadaProductGroup::query()->orderByDesc('id')->get();

        $lazCatIds = $groups->pluck('lazada_category_id')->filter()->unique()->values()->all();
        $lazadaCategoryNames = collect();
        if (!empty($lazCatIds)) {
            $lazadaCategoryNames = LazadaCategory::query()
                ->whereIn('category_id', $lazCatIds)
                ->pluck('name', 'category_id');
        }

        $productCounts = DB::table('lazada_product_group_products')
            ->selectRaw('lazada_product_group_id, COUNT(*) as cnt')
            ->groupBy('lazada_product_group_id')
            ->pluck('cnt', 'lazada_product_group_id');

        return view('ext-lazada::product-groups.index', [
            'groups' => $groups,
            'lazadaCategoryNames' => $lazadaCategoryNames,
            'productCounts' => $productCounts,
        ]);
    }

    public function create()
    {
        return $this->form(new LazadaProductGroup(), 'create');
    }

    public function edit(int $id)
    {
        $group = LazadaProductGroup::query()->findOrFail($id);
        return $this->form($group, 'edit');
    }

    private function form(LazadaProductGroup $group, string $mode)
    {
        $lazadaCategories = LazadaCategory::query()->orderBy('name')->limit(5000)->get();

        $template = null;
        $attributes = [];
        $saved = [];
        if ($group->lazada_category_id) {
            $template = LazadaCategoryTemplate::query()
                ->where('region', $this->region())
                ->where('primary_category_id', (int) $group->lazada_category_id)
                ->first();

            if ($template && $template->template_body) {
                $attributes = $this->attrs()->extractAttributes($template->template_body);
            }

            if ($group->exists) {
                $saved = LazadaProductGroupAttribute::query()
                    ->where('lazada_product_group_id', $group->id)
                    ->pluck('value', 'attribute_key')
                    ->toArray();
            }
        }

        $selectedBrandName = '';
        if ($group->brand_id) {
            $brand = \Extensions\lazada\Models\LazadaBrand::query()->where('brand_id', $group->brand_id)->first();
            if ($brand) $selectedBrandName = $brand->name;
        }

        return view('ext-lazada::product-groups.form', [
            'productCount' => $group->exists ? \Illuminate\Support\Facades\DB::table('lazada_product_group_products')->where('lazada_product_group_id', $group->id)->count() : 0,
            'group' => $group,
            'mode' => $mode,
            'lazadaCategories' => $lazadaCategories,
            'template' => $template,
            'attributes' => $attributes,
            'saved' => $saved,
            'selectedBrandName' => $selectedBrandName,
            'erpSourceFields' => LazadaAttributes::ERP_SOURCE_FIELDS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'lazada_category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'no_brand' => 'nullable',
            'markup_fixed' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0',
            'watermark_template_id' => 'nullable|integer',
        ]);

        $group = LazadaProductGroup::create([
            'name' => $data['name'],
            'lazada_category_id' => $data['lazada_category_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'brand_name_override' => !empty($request->input('no_brand')) ? 'No Brand' : null,
            'markup_fixed' => $data['markup_fixed'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null, 'watermark_template_id' => \App\Models\WatermarkTemplate::idOrNull($data['watermark_template_id'] ?? null, 'lazada', \App\Integrations\Listings\ListingStore::id('lazada')),
        ]);

        $this->saveGroupAttributes($group, (array) $request->input('attributes', []));

        ActivityLogger::log('created', 'Lazada Product group', $group->id, $group->name);

        return redirect(\App\Support\BackTo::safe(
            $request->input('_return'),
            route('ext.lazada.product-groups.products', $group->id)
        ))->with('status', 'Product group created. Add products to it from this store\'s list.');
    }

    public function update(Request $request, int $id)
    {
        $group = LazadaProductGroup::query()->findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'lazada_category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'no_brand' => 'nullable',
            'markup_fixed' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0',
            'watermark_template_id' => 'nullable|integer',
        ]);

        $group->update([
            'name' => $data['name'],
            'lazada_category_id' => $data['lazada_category_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'brand_name_override' => !empty($request->input('no_brand')) ? 'No Brand' : null,
            'markup_fixed' => $data['markup_fixed'] ?? null,
            'markup_percent' => $data['markup_percent'] ?? null, 'watermark_template_id' => \App\Models\WatermarkTemplate::idOrNull($data['watermark_template_id'] ?? null, 'lazada', \App\Integrations\Listings\ListingStore::id('lazada')),
        ]);

        $this->saveGroupAttributes($group, (array) $request->input('attributes', []));

        ActivityLogger::log('updated', 'Lazada Product group', $group->id, $group->name);

        return redirect()->route('ext.lazada.product-groups.edit', $group->id)
            ->with('status', 'Product group saved.');
    }

    public function destroy(int $id)
    {
        $group = LazadaProductGroup::query()->findOrFail($id);

        $linkedLpIds = $group->groupProducts()->pluck('lazada_product_id')->filter()->toArray();
        $memberIds = $group->groupProducts()->whereNotNull('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->all();

        $group->delete();
        $this->states($group)->clearErrors($memberIds);

        ActivityLogger::log('deleted', 'Lazada Product group', (int) $id, $group->name);

        $removed = 0;
        $kept = 0;
        if (!empty($linkedLpIds)) {
            $stillLinked = DB::table('lazada_product_group_products')
                ->whereIn('lazada_product_id', $linkedLpIds)
                ->pluck('lazada_product_id')
                ->unique()
                ->toArray();

            $orphanIds = array_diff($linkedLpIds, $stillLinked);

            if (!empty($orphanIds)) {
                $removed = LazadaProduct::query()
                    ->whereIn('id', $orphanIds)
                    ->whereNull('lazada_item_id')
                    ->delete();
            }
            $kept = count($stillLinked);
        }

        $parts = ['Product group "' . e($group->name) . '" deleted.'];
        if ($removed > 0) $parts[] = "{$removed} product(s) removed.";
        if ($kept > 0) $parts[] = "{$kept} product(s) still linked to other groups.";

        return redirect()->route('ext.lazada.product-groups.index')
            ->with('status', implode(' ', $parts));
    }

    public function products(Request $request, int $id)
    {
        $group = LazadaProductGroup::query()->findOrFail($id);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $q = trim((string) $request->input('q'));
        $syncStatus = (string) $request->input('sync_status', 'all');
        $erpStatus = (string) $request->input('erp_status', 'all');

        $query = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->join('lazada_product_group_products as gp', function ($j) use ($group) {
                $j->on('p.product_id', '=', 'gp.product_id')
                    ->where('gp.lazada_product_group_id', '=', $group->id);
            })
            ->leftJoin('lazada_products as lp', 'gp.lazada_product_id', '=', 'lp.id')
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->select(
                'p.product_id', 'pd.name', 'p.sku', 'p.model', 'p.price',
                'p.quantity', 'p.image', 'p.status',
                'm.name as manufacturer_name',
                'lp.lazada_item_id', 'lp.lazada_deleted_at',
                'lp.last_pushed_at as listing_last_pushed_at', 'lp.last_push_source',
                'p.date_modified',
                'gp.sync_status', 'gp.last_pushed_at', 'gp.push_error'
            );

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%")
                    ->orWhere('p.model', 'like', "%{$q}%")
                    ->orWhereIn('p.product_id', LazadaProduct::query()
                        ->forStore(LazadaSetting::defaultStore())
                        ->where('item_name', 'like', "%{$q}%")
                        ->select('product_id'));
            });
        }

        if ($erpStatus === 'enabled') {
            $query->where('p.status', 1);
        } elseif ($erpStatus === 'disabled') {
            $query->where('p.status', 0);
        }

        $states = $this->states($group);

        if ($syncStatus === 'pushed') {
            $query->whereNotNull('lp.lazada_item_id')->where('lp.lazada_item_id', '!=', '');
        } elseif ($syncStatus === 'pending') {
            $query->where(function ($w) {
                $w->whereNull('lp.lazada_item_id')->orWhere('lp.lazada_item_id', '');
            });
        } elseif ($syncStatus === 'error') {
            $query->whereIn('p.product_id', $states->erroredProductIds() ?: [0]);
        } elseif ($syncStatus === 'unlinked') {
            $query->where('gp.sync_status', 'unlinked');
        }

        $storeId = \App\Integrations\Listings\ListingStore::id('lazada');
        $sortMenu = ['added' => 'gp.id', 'pushed' => 'gp.last_pushed_at'] + \Extensions\lazada\Services\LazadaListingSort::columns($storeId);
        $order = \App\Integrations\Listings\ListingSort::chosen($request, 'lazada.group.' . $group->id);
        \Extensions\lazada\Services\LazadaListingSort::join($query, $storeId);
        \App\Integrations\Listings\ListingSort::apply($query, $order, $sortMenu);

        $products = $query->paginate(50)->appends($request->except('page'));

        $pivotProductIds = $products->pluck('product_id')->toArray();
        $pivotMap = LazadaProductGroupProduct::query()
            ->where('lazada_product_group_id', $group->id)
            ->whereIn('product_id', $pivotProductIds)
            ->get()
            ->keyBy('product_id');

        $optionRowsByProductId = \App\Support\VariationRows::forListing($pivotProductIds, 'lazada', (int) (\Extensions\lazada\Models\LazadaSetting::defaultStore()?->id ?? 0));

        $manualIds = $group->groupProducts()->whereNotNull('product_id')->pluck('product_id')->toArray();

        $rowTitles = \App\Integrations\Listings\ListingContent::rowTitles($products, LazadaProduct::query()
            ->forStore(LazadaSetting::defaultStore())
            ->whereIn('product_id', $pivotProductIds ?: [0])
            ->orderBy('id')
            ->get());

        return view('ext-lazada::product-groups.products', [
            'group' => $group,
            'products' => $products,
            'rowTitles' => $rowTitles,
            'pivotMap' => $pivotMap,
            'order' => $order,
            'orderOptions' => \App\Integrations\Listings\ListingSort::options(array_keys($sortMenu)),
            'listingStates' => app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class)
                ->forProducts(array_map('intval', $pivotProductIds)),
            'rowErrors' => $states->errors(array_map('intval', $pivotProductIds)),
            'optionRowsByProductId' => $optionRowsByProductId,
            'lazadaIdByProductId' => LazadaProduct::query()
                ->whereIn('product_id', $products->pluck('product_id')->all() ?: [0])
                ->pluck('id', 'product_id'),
            'manualIds' => $manualIds,
            'q' => $q,
            'syncStatus' => $syncStatus,
            'erpStatus' => $erpStatus,
        ]);
    }

    private function otherOwnerName(LazadaProductGroup $group, int $productId): ?string
    {
        return DB::table('lazada_product_group_products as pv')
            ->join('lazada_product_groups as g', 'g.id', '=', 'pv.lazada_product_group_id')
            ->where('pv.product_id', $productId)
            ->where('g.id', '!=', $group->id)
            ->where('g.lazada_setting_id', (int) $group->lazada_setting_id)
            ->value('g.name');
    }

    public function productSearch(Request $request, int $id)
    {
        $group = LazadaProductGroup::query()->findOrFail($id);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->query('q', ''));
        $showAll = $request->boolean('all');
        $limit = 25;

        $members = LazadaProductGroupProduct::query()
            ->where('lazada_product_group_id', $group->id)
            ->whereNotNull('product_id')
            ->select('product_id');

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
            ->select('p.product_id', 'pd.name', 'p.image', 'p.sku', 'p.model', 'p.quantity', 'p.price', 'lps.product_id as on_store_pid')
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

        $inGroup = $ids === [] ? collect() : LazadaProductGroupProduct::query()
            ->where('lazada_product_group_id', $group->id)
            ->whereIn('product_id', $ids)
            ->pluck('product_id')->map(fn ($v) => (int) $v)->flip();

        $owners = $ids === [] ? collect() : DB::table('lazada_product_group_products as pv')
            ->join('lazada_product_groups as g', 'g.id', '=', 'pv.lazada_product_group_id')
            ->whereIn('pv.product_id', $ids)
            ->where('g.id', '!=', $group->id)
            ->where('g.lazada_setting_id', (int) $group->lazada_setting_id)
            ->get(['pv.product_id', 'g.name'])
            ->keyBy('product_id');

        $images = app(\Extensions\lazada\Services\Lazada\LazadaImages::class);
        $items = $rows->map(function ($r) use ($inGroup, $owners, $images) {
            $pid = (int) $r->product_id;

            return [
                'id' => $pid,
                'name' => html_entity_decode((string) ($r->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Unnamed product',
                'sku' => (string) ($r->sku ?? ''),
                'quantity' => (int) ($r->quantity ?? 0),
                'price' => \App\Support\Money::base((float) ($r->price ?? 0)),
                'thumb' => $images->toDisplayImageUrl($r->image ?? null),
                'on_store' => $inGroup->has($pid),
                'elsewhere' => $owners->get($pid)?->name,
                'listed' => ! is_null($r->on_store_pid),
            ];
        })->values();

        return response()->json(['total' => $total, 'shown' => $items->count(), 'limit' => $limit, 'items' => $items]);
    }

    public function addProduct(Request $request, int $id, int $productId)
    {
        $group = LazadaProductGroup::query()->findOrFail($id);
        $inCatalogue = DB::table((string) config('catalog.prefix') . 'product')
            ->where('product_id', $productId)->where('status', 1)->exists();
        abort_unless($inCatalogue, 404);

        $listedNow = false;
        if (! LazadaProduct::query()->where('product_id', $productId)->exists()) {
            LazadaProduct::firstOrCreate(['product_id' => $productId]);
            $listedNow = true;
        }

        $already = $group->groupProducts()->where('product_id', $productId)->exists();
        $added = false;
        $movedFrom = null;

        if (! $already) {
            $owner = $this->otherOwnerName($group, $productId);
            $added = $this->addProductsToGroup($group, [$productId], $request->boolean('move') ? [$productId] : []) > 0;
            $movedFrom = $added ? $owner : null;
        }

        if ($request->wantsJson()) {
            if (! $already && ! $added) {
                return response()->json(['ok' => false, 'product_id' => $productId, 'message' => trim(\App\Integrations\OneGroupRule::heldClause($this->lastHeld))], 409);
            }

            return response()->json(['ok' => true, 'product_id' => $productId, 'added' => $added, 'moved_from' => $movedFrom, 'listed' => $listedNow]);
        }

        $status = $already
            ? "Product #{$productId} is already in this product group."
            : ($added
                ? "Product #{$productId} added to the product group." . ($movedFrom !== null ? " Moved here from '{$movedFrom}'." : '') . ($listedNow ? ' It is on this store now too.' : '')
                : trim(\App\Integrations\OneGroupRule::heldClause($this->lastHeld)));

        return $this->productsRedirect($group->id)->with($added ? 'status' : 'warning', $status);
    }

    public function unlinkProduct(int $groupId, int $productId)
    {
        $group = LazadaProductGroup::query()->findOrFail($groupId);

        $group->groupProducts()
            ->where('product_id', $productId)
            ->update(['sync_status' => 'unlinked']);
        $this->states($group)->clearErrors([$productId]);

        return $this->productsRedirect($group->id)
            ->with('status', 'Product unlinked from Lazada.');
    }

    public function syncId(int $groupId, int $productId)
    {
        $group = LazadaProductGroup::query()->findOrFail($groupId);
        $pivot = $group->groupProducts()->where('product_id', $productId)->first();
        if (!$pivot) {
            return redirect()->back()->with('error', 'Product not in this product group.');
        }

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return redirect()->back()->with('error', 'Missing Lazada credentials.');
        }

        $pfx = (string) config('catalog.prefix');
        $client = app(\Extensions\lazada\Services\Lazada\LazadaClient::class);

        $product = DB::table($pfx . 'product')->where('product_id', $productId)->first(['sku', 'model']);
        $skus = [];
        if ($product->sku && trim($product->sku) !== '') $skus[] = trim($product->sku);
        if ($product->model && trim($product->model) !== '' && !in_array(trim($product->model), $skus)) $skus[] = trim($product->model);

        $optSkus = DB::table($pfx . 'product_option_value')
            ->where('product_id', $productId)
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->pluck('sku')->map(fn($s) => trim($s))->unique()->toArray();
        $skus = array_unique(array_merge($skus, $optSkus));

        if (empty($skus)) {
            $this->states($group)->recordOutcome($productId, 'Product has no SKU to match against Lazada.');

            return redirect()->back()->with('error', 'Product has no SKU to match against Lazada.');
        }

        $lazadaProduct = LazadaProduct::find($pivot->lazada_product_id);
        if ($lazadaProduct && $lazadaProduct->lazada_item_id && !$lazadaProduct->unlinked_at) {
            app(LazadaItemCache::class)->refreshListing($lazadaProduct, $setting, $client, 'lazada.product.item.get.cache.group');
            if (! in_array($pivot->sync_status, ['error', 'failed'], true)) {
                $pivot->update(['sync_status' => 'synced']);
            }
            return redirect()->back()->with('status', "Lazada item ID {$lazadaProduct->lazada_item_id}: cache refreshed.");
        }

        $matchedItemId = null;
        foreach ($skus as $sku) {
            $apiPath = '/products/get';
            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
                'filter' => 'all',
                'search' => $sku,
                'limit' => '50',
                'offset' => '0',
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
            $result = $client->get((string) $setting->region, $apiPath, $params);

            if (!($result['ok'] ?? false)) continue;

            $products = $result['body']['data']['products'] ?? [];
            foreach ($products as $p) {
                $itemId = $p['item_id'] ?? ($p['itemId'] ?? null);
                if (!$itemId) continue;

                foreach ($p['skus'] ?? [] as $s) {
                    $sellerSku = $s['SellerSku'] ?? ($s['seller_sku'] ?? ($s['SellerSKU'] ?? null));
                    if ($sellerSku !== null && strcasecmp(trim((string) $sellerSku), $sku) === 0) {
                        $matchedItemId = (string) $itemId;
                        break 3;
                    }
                }
            }
        }

        if (!$matchedItemId) {
            $this->states($group)->recordOutcome($productId, 'No matching product found on Lazada for SKU(s): ' . implode(', ', $skus));

            return redirect()->back()->with('error', 'No matching product found on Lazada for SKU(s): ' . implode(', ', $skus));
        }

        if (!$lazadaProduct) {
            $lazadaProduct = LazadaProduct::find($pivot->lazada_product_id);
        }
        if ($lazadaProduct) {
            $lazadaProduct->update([
                'lazada_item_id' => $matchedItemId,
                'lazada_deleted_at' => null,
                'unlinked_at' => null,
            ]);

            app(LazadaItemCache::class)->refreshListing($lazadaProduct, $setting, $client, 'lazada.product.item.get.cache.group');
        }

        $pivot->update(['sync_status' => 'pushed']);
        $this->states($group)->recordOutcome($productId, null);

        return redirect()->back()->with('status', "Lazada item ID {$matchedItemId} synced for this product.");
    }


    public function linkProduct(int $groupId, int $productId)
    {
        $group = LazadaProductGroup::query()->findOrFail($groupId);
        $pivot = $group->groupProducts()->where('product_id', $productId)->first();

        if ($pivot) {
            $hasItemId = false;
            if ($pivot->lazada_product_id) {
                $hasItemId = LazadaProduct::query()
                    ->where('id', $pivot->lazada_product_id)
                    ->whereNotNull('lazada_item_id')
                    ->where('lazada_item_id', '!=', '')
                    ->exists();
            }

            $pivot->update(['sync_status' => $hasItemId ? 'synced' : 'pending']);
        }

        return $this->productsRedirect($group->id)
            ->with('status', 'Product re-linked.');
    }

    public function removeProduct(int $groupId, int $productId)
    {
        $group = LazadaProductGroup::query()->findOrFail($groupId);
        $group->groupProducts()->where('product_id', $productId)->delete();
        $this->states($group)->clearErrors([$productId]);

        return $this->productsRedirect($group->id)
            ->with('status', 'Product removed from the product group.');
    }

    public function moveProducts(Request $request)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])), fn ($v) => $v > 0)));
        $back = \App\Support\BackTo::safe($request->input('_return'), route('ext.lazada.products.index'));
        if ($ids === []) {
            return redirect($back)->with('warning', 'No products selected.');
        }
        $target = (string) $request->input('group', '');
        if ($target === 'none') {
            $removed = LazadaProductGroupProduct::query()->onStore()->whereIn('product_id', $ids)->delete();

            return redirect($back)->with('status', $removed . ' ' . \Illuminate\Support\Str::plural('product', $removed) . ' ungrouped.');
        }
        $group = LazadaProductGroup::query()->findOrFail((int) $target);
        $store = LazadaSetting::defaultStore();

        $already = $group->groupProducts()->whereIn('product_id', $ids)->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $wanted = array_values(array_diff($ids, $already));
        $claim = \App\Integrations\OneGroupRule::claim('lazada_product_group_products', 'lazada_product_group_id', 'lazada_product_groups', 'lazada_setting_id', $group->id, $wanted, $wanted);
        $moved = 0;
        foreach (array_merge($claim['free'], array_keys($claim['moved'])) as $pid) {
            $listingId = LazadaProduct::query()->forStore($store)->where('product_id', (int) $pid)->value('id')
                ?? LazadaProduct::create(['product_id' => (int) $pid])->id;
            LazadaProductGroupProduct::create([
                'lazada_product_group_id' => $group->id,
                'lazada_product_id' => $listingId,
                'product_id' => (int) $pid,
                'sync_status' => 'pending',
            ]);
            $moved++;
        }
        if ($moved === 0) {
            return redirect($back)->with('warning', 'Those products are already in ' . $group->name . '.');
        }

        return redirect($back)->with('status', $moved . ' ' . \Illuminate\Support\Str::plural('product', $moved) . ' moved to ' . $group->name . '.');
    }

    public function massRemove(Request $request, int $id)
    {
        $group = LazadaProductGroup::query()->findOrFail($id);
        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));

        if (!empty($ids)) {
            $group->groupProducts()->whereIn('product_id', $ids)->delete();
            $this->states($group)->clearErrors($ids);
        }

        return $this->productsRedirect($group->id)
            ->with('status', count($ids) . ' product(s) removed from the product group.');
    }

    public function push(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $r = $this->sendProducts($group, $productIds, $client);

        return $this->productsRedirect($id)->with($r['tone'], $r['summary']);
    }

    public function sendProducts(LazadaProductGroup $group, array $productIds, LazadaClient $client): array
    {
        if (!$group->lazada_category_id) {
            return ['tone' => 'error', 'summary' => 'This product group has no Lazada category configured. Set a category first.', 'failed' => 0, 'stop' => true];
        }

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return ['tone' => 'error', 'summary' => 'Missing Lazada settings.', 'failed' => 0, 'stop' => true];
        }

        $pfx = (string) config('catalog.prefix');

        $okCount = 0;
        $updatedCount = 0;
        $skipCount = 0;
        $errCount = 0;
        $errors = [];
        $notes = [];
        $picturesWarned = false;

        foreach ($productIds as $productId) {
            $pivot = LazadaProductGroupProduct::query()
                ->where('lazada_product_group_id', $group->id)
                ->where('product_id', $productId)
                ->first();

            $listing = null;
            $groupOverrides = [];
            if ($pivot && $pivot->lazada_product_id) {
                $listing = LazadaProduct::find($pivot->lazada_product_id);
            }

            $isAlreadyLinked = $listing && $listing->lazada_item_id && !$listing->lazada_deleted_at;
            if ($isAlreadyLinked) {
                $outcome = $this->updateLinked($group, $pivot, $listing, (int) $productId, $setting, $creds, $client);
                if ($outcome['ok']) {
                    $updatedCount++;
                    if (!empty($outcome['warning'])) $notes[] = "#{$productId}: " . $outcome['warning'];
                    if (!empty($outcome['pictures_left_out'])) $picturesWarned = true;
                } else {
                    $errors[] = "#{$productId}: " . $outcome['message'];
                    $errCount++;
                }
                usleep(300000);
                continue;
            }

            $gateOverrides = [
                'settings' => ['primary_category_id' => $group->lazada_category_id, 'markup_fixed' => $group->markup_fixed, 'markup_percent' => $group->markup_percent],
                'attributes' => LazadaProductGroupAttribute::where('lazada_product_group_id', $group->id)->pluck('value', 'attribute_key')->toArray(),
            ];
            $gateRow = $listing ?: (new LazadaProduct())->forceFill(['product_id' => $productId, 'primary_category_id' => $group->lazada_category_id, 'brand_name_override' => 'No Brand']);
            if ($group->lazada_category_id && !\Extensions\lazada\Models\LazadaCategoryTemplate::query()->where('region', (string) $setting->region)->where('primary_category_id', (int) $group->lazada_category_id)->whereNotNull('template_body')->exists()) {
                app(\Extensions\lazada\Controllers\LazadaProductController::class)->readCategoryTemplate((int) $group->lazada_category_id, $client);
            }
            $readiness = app(\Extensions\lazada\Services\Lazada\LazadaListingReadiness::class)->forListings(collect([$gateRow]), $gateOverrides)[(int) $gateRow->id] ?? ['ready' => false, 'missing' => []];
            if (!$readiness['ready']) {
                $refusal = \App\Integrations\Listings\CatalogGaps::refusal($readiness);
                $errors[] = "#{$productId}: {$refusal}";
                $this->recordGroupFailure($group, $pivot, (int) $productId, $refusal);
                $errCount++;
                continue;
            }

            if (!$listing) {
                $listing = LazadaProduct::create(['product_id' => $productId]);

                if ($pivot) {
                    $pivot->update(['lazada_product_id' => $listing->id]);
                } elseif (\App\Integrations\OneGroupRule::claim('lazada_product_group_products', 'lazada_product_group_id', 'lazada_product_groups', 'lazada_setting_id', $group->id, [(int) $productId])['free'] !== []) {
                    LazadaProductGroupProduct::create([
                        'lazada_product_group_id' => $group->id,
                        'product_id' => $productId,
                        'lazada_product_id' => $listing->id,
                        'sync_status' => 'pending',
                    ]);
                    $pivot = LazadaProductGroupProduct::query()
                        ->where('lazada_product_group_id', $group->id)
                        ->where('product_id', $productId)
                        ->first();
                }
            }

            $groupOverrides = [
                'settings' => [
                    'primary_category_id' => $group->lazada_category_id,
                    'markup_fixed'        => $group->markup_fixed,
                    'markup_percent'      => $group->markup_percent,
                ],
                'attributes' => LazadaProductGroupAttribute::where('lazada_product_group_id', $group->id)
                    ->pluck('value', 'attribute_key')->toArray(),
            ];

            try {
                [$productPayload, $preview] = $this->payload()->buildLazadaProductCreatePayload($listing, $setting, $client, $groupOverrides);
                $productPayload = app(\Extensions\lazada\Services\Lazada\LazadaImages::class)->ensureLazadaInlinkImages($productPayload, $setting, $client);
                [$productPayload, $picturesLeftOut] = app(\Extensions\lazada\Services\Lazada\LazadaImages::class)->ensureLazadaDescriptionImages($productPayload, $setting, $client);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $msg = implode('; ', array_map(fn($m) => implode(', ', $m), $e->errors()));
                $errors[] = "#{$productId}: {$msg}";
                $this->recordGroupFailure($group, $pivot, (int) $productId, $msg);
                $errCount++;
                continue;
            } catch (\Throwable $e) {
                $errors[] = "#{$productId}: " . $e->getMessage();
                $this->recordGroupFailure($group, $pivot, (int) $productId, $e->getMessage());
                $errCount++;
                continue;
            }

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
                'pack' => 'lazada.product.create.group',
                'method' => 'POST', 'api_path' => $apiPath,
                'auth_required' => true, 'request_params' => $params,
                'response_status' => (int) ($result['status'] ?? 0),
                'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
            ]);

            $e = $this->payload()->extractLazadaError($result);
            if ($e['ok']) {
                $body = $result['body'] ?? [];
                $data = $body['data'] ?? $body;
                $itemId = $data['item_id'] ?? ($data['ItemId'] ?? null);

                if ($itemId) {
                    $listing->lazada_item_id = (string) $itemId;
                    $listing->lazada_deleted_at = null;
                    try { $listing->save(); } catch (\Throwable $ex) {}
                }

                app(LazadaItemCache::class)->refreshListing($listing, $setting, $client, 'lazada.product.item.get.cache.group');

                $this->persistSyncStatus($listing, 'push', true);

                $listing->forceFill([
                    'last_pushed_at' => now(),
                    'last_push_source' => 'group:' . $group->name,
                    'last_push_settings' => [
                        'category_id' => (int) ($group->lazada_category_id ?? 0),
                        'markup_percent' => $group->markup_percent,
                        'markup_fixed' => $group->markup_fixed,
                    ],
                ])->save();
                if ($pivot) $pivot->update(['sync_status' => 'pushed']);
                $this->states($group)->recordOutcome((int) $productId, null);

                ActivityLogger::log('created', 'Lazada Product', $listing->id,
                    'Pushed ERP #' . $productId . ' to Lazada via product group');

                $pictureNote = \Extensions\lazada\Services\Lazada\LazadaImages::descriptionNote($picturesLeftOut);
                if ($pictureNote !== null) {
                    $notes[] = "#{$productId}: " . $pictureNote;
                    $picturesWarned = true;
                }

                $okCount++;
            } else {
                $reason = (string) ($e['message'] ?? 'Unknown error');
                $errors[] = "#{$productId}: " . $reason;
                $this->recordGroupFailure($group, $pivot, (int) $productId, $reason);
                $errCount++;
            }

            usleep(300000);
        }

        $summary = "Send: {$okCount} created";
        if ($updatedCount > 0) $summary .= ", {$updatedCount} already listed and updated in place";
        if ($skipCount > 0) $summary .= ", {$skipCount} skipped (disabled)";
        if ($errCount > 0) $summary .= ", {$errCount} failed";
        if (!empty($errors)) $summary .= '. Errors: ' . implode('; ', array_slice($errors, 0, 5));
        if (!empty($notes)) $summary .= '. ' . implode(' ', array_slice($notes, 0, 5));

        return ['tone' => ($okCount + $updatedCount) > 0 ? ($picturesWarned ? 'warning' : 'status') : 'error', 'summary' => $summary, 'failed' => min(count($productIds), $errCount), 'stop' => false];
    }

    public function updateProduct(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        if (!$group->lazada_category_id) {
            return $this->productsRedirect($id)->with('error', 'This product group has no Lazada category configured.');
        }

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }


        $okCount = 0;
        $skipCount = 0;
        $errCount = 0;
        $errors = [];
        $notes = [];
        $picturesWarned = false;

        foreach ($productIds as $productId) {
            $pivot = LazadaProductGroupProduct::query()
                ->where('lazada_product_group_id', $group->id)
                ->where('product_id', $productId)
                ->first();

            $listing = null;
            if ($pivot && $pivot->lazada_product_id) {
                $listing = LazadaProduct::find($pivot->lazada_product_id);
            }

            if (!$listing || !$listing->lazada_item_id || $listing->lazada_deleted_at) {
                $skipCount++;
                continue;
            }

            $outcome = $this->updateLinked($group, $pivot, $listing, (int) $productId, $setting, $creds, $client);
            if ($outcome['ok']) {
                $okCount++;
                if (!empty($outcome['warning'])) $notes[] = "#{$productId}: " . $outcome['warning'];
            } else {
                $errors[] = "#{$productId}: " . $outcome['message'];
                $errCount++;
            }

            usleep(300000);
        }

        $summary = "Update: {$okCount} updated";
        if ($skipCount > 0) $summary .= ", {$skipCount} skipped (not linked)";
        if ($errCount > 0) $summary .= ", {$errCount} failed";
        if (!empty($errors)) $summary .= '. Errors: ' . implode('; ', array_slice($errors, 0, 5));
        if (!empty($notes)) $summary .= '. ' . implode(' ', array_slice($notes, 0, 5));

        return $this->productsRedirect($id)->with($okCount > 0 ? ($picturesWarned ? 'warning' : 'status') : 'error', $summary);
    }

    private function updateLinked(LazadaProductGroup $group, ?LazadaProductGroupProduct $pivot, LazadaProduct $listing, int $productId, object $setting, array $creds, LazadaClient $client): array
    {
            $groupOverrides = [
                'settings' => [
                    'primary_category_id' => $group->lazada_category_id,
                    'markup_fixed'        => $group->markup_fixed,
                    'markup_percent'      => $group->markup_percent,
                ],
                'attributes' => LazadaProductGroupAttribute::where('lazada_product_group_id', $group->id)
                    ->pluck('value', 'attribute_key')->toArray(),
            ];

            $readiness = app(\Extensions\lazada\Services\Lazada\LazadaListingReadiness::class)->forListings(collect([$listing]), $groupOverrides)[(int) $listing->id] ?? ['ready' => false, 'missing' => []];
            if (!$readiness['ready']) {
                $refusal = \App\Integrations\Listings\CatalogGaps::refusal($readiness, 'update');
                $this->recordGroupFailure($group, $pivot, $productId, $refusal);

                return ['ok' => false, 'message' => $refusal];
            }

            try {
                [$productPayload, $preview] = $this->payload()->buildLazadaProductCreatePayload($listing, $setting, $client, $groupOverrides);
                $productPayload = app(\Extensions\lazada\Services\Lazada\LazadaImages::class)->ensureLazadaInlinkImages($productPayload, $setting, $client);
                [$productPayload, $picturesLeftOut] = app(\Extensions\lazada\Services\Lazada\LazadaImages::class)->ensureLazadaDescriptionImages($productPayload, $setting, $client);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $msg = implode('; ', array_map(fn($m) => implode(', ', $m), $e->errors()));
                $this->recordGroupFailure($group, $pivot, $productId, $msg);
                return ['ok' => false, 'message' => $msg];
            } catch (\Throwable $e) {
                $this->recordGroupFailure($group, $pivot, $productId, $e->getMessage());
                return ['ok' => false, 'message' => $e->getMessage()];
            }

            $productPayload['Request']['Product']['ItemId'] = (int) $listing->lazada_item_id;

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

            $apiPath = '/product/update';
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
                'pack' => 'lazada.product.update.group',
                'method' => 'POST', 'api_path' => $apiPath,
                'auth_required' => true, 'request_params' => $params,
                'response_status' => (int) ($result['status'] ?? 0),
                'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
            ]);

            $switched = app(LazadaVariationSwitch::class)->switchOff($setting, $creds, (string) $listing->lazada_item_id, $preview['variations_off'] ?? []);
            $switchRefusal = LazadaVariationSwitch::refusal($productId, $switched['refused']);

            $e = $this->payload()->extractLazadaError($result);
            if ($e['ok']) {
                $readBack = app(LazadaItemCache::class)->refreshListing($listing, $setting, $client, 'lazada.product.item.get.cache.group');
                $kept = LazadaVariationSwitch::line($this->payload()->renameReadBack($preview['variation_renames'] ?? [], $readBack), $switchRefusal);
                $switchNote = LazadaVariationSwitch::summary($productId, $switched['off'], $preview['variations_back'] ?? []);
                $this->persistSyncStatus($listing, 'push', true);

                $listing->forceFill([
                    'last_pushed_at' => now(),
                    'last_push_source' => 'group:' . $group->name,
                    'last_push_settings' => [
                        'category_id' => (int) ($group->lazada_category_id ?? 0),
                        'markup_percent' => $group->markup_percent,
                        'markup_fixed' => $group->markup_fixed,
                    ],
                ])->save();
                if ($pivot) $pivot->update(['sync_status' => 'pushed']);
                $this->states($group)->recordOutcome($productId, $kept);

                ActivityLogger::log('updated', 'Lazada Product', $listing->id,
                    'Updated ERP #' . $productId . ' on Lazada via product group');

                $pictureNote = \Extensions\lazada\Services\Lazada\LazadaImages::descriptionNote($picturesLeftOut);

                return $kept === null
                    ? ['ok' => true, 'message' => 'updated', 'warning' => LazadaVariationSwitch::line($switchNote, $pictureNote, $this->payload()->renameWarning($preview)), 'pictures_left_out' => $pictureNote !== null]
                    : ['ok' => false, 'message' => LazadaVariationSwitch::line($switchNote, $pictureNote, $kept)];
            }

            $reason = LazadaVariationSwitch::line((string) ($e['message'] ?? 'Unknown error'), $switchRefusal);
            $this->recordGroupFailure($group, $pivot, $productId, $reason);

            return ['ok' => false, 'message' => LazadaVariationSwitch::line(LazadaVariationSwitch::summary($productId, $switched['off'], []), $reason)];

    }

    public function checkAgainstLazada(Request $request, int $id, \Extensions\lazada\Services\Lazada\LazadaLinkCheck $check)
    {
        try { @set_time_limit(0); } catch (\Throwable $e) {}

        $group = LazadaProductGroup::findOrFail($id);
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['complete']) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }

        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            $productIds = $group->groupProducts()->pluck('product_id')->map('intval')->all();
        }
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'This product group has no products to check.');
        }

        $r = $check->run($setting, $creds, $productIds, $group);

        return $this->productsRedirect($id)->with($r['tone'], $r['summary']);
    }

    public function orphans(Request $request, int $id, LazadaLiveListing $live)
    {
        $group = LazadaProductGroup::findOrFail($id);
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['complete']) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }

        $index = $live->skuIndex($setting, $creds);
        $known = LazadaProduct::query()->whereNotNull('lazada_item_id')->where('lazada_item_id', '!=', '')
            ->pluck('lazada_item_id')->map(fn ($v) => (string) $v)->flip();

        $orphans = [];
        foreach ($index['items'] as $itemId => $skus) {
            if ($known->has((string) $itemId)) {
                continue;
            }
            sort($skus);
            $orphans[] = ['id' => (string) $itemId, 'skus' => $skus];
        }

        return view('ext-lazada::product-groups.orphans', [
            'group' => $group,
            'orphans' => $orphans,
            'scanned' => count($index['items']),
            'complete' => $index['complete'],
        ]);
    }

    public function pushPrices(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::findOrFail($id);
        $ids = $this->parseIds($request);
        if (empty($ids)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['complete']) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }

        $pfx = (string) config('catalog.prefix');
        $listings = LazadaProduct::query()->whereNotNull('product_id')->whereIn('product_id', $ids)->get();

        $results = app(\Extensions\lazada\Services\Lazada\LazadaStockPricePush::class)
            ->push('price', $setting, $creds, $listings, $pfx,
                \Extensions\lazada\Commands\LazadaPushPrice::priceRule($listings));

        foreach ($results['outcomes'] as $pid => $outcome) {
            LazadaProductGroupProduct::query()
                ->where('lazada_product_group_id', $group->id)->where('product_id', (int) $pid)
                ->update($outcome['ok']
                    ? ['sync_status' => 'pushed', 'push_error' => null]
                    : ['sync_status' => 'error', 'push_error' => mb_substr('Price push: ' . $outcome['error'], 0, 255)]);
        }

        return $this->productsRedirect($id)->with(
            \App\Integrations\Push\PushLedger::batchTone($results['ok'], $results['err']),
            "Sync Price: {$results['ok']} ok, {$results['err']} failed"
                . ($results['skipped'] > 0 ? ", {$results['skipped']} skipped (disabled)" : '') . '.'
                . \Extensions\lazada\Services\Lazada\LazadaStockPricePush::ledgerClause($results)
        );
    }

    public function pushStock(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::findOrFail($id);
        $ids = $this->parseIds($request);
        if (empty($ids)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['complete']) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }

        $pfx = (string) config('catalog.prefix');
        $listings = LazadaProduct::query()->whereNotNull('product_id')->whereIn('product_id', $ids)->get();

        $results = app(\Extensions\lazada\Services\Lazada\LazadaStockPricePush::class)
            ->push('stock', $setting, $creds, $listings, $pfx);

        foreach ($results['outcomes'] as $pid => $outcome) {
            LazadaProductGroupProduct::query()
                ->where('lazada_product_group_id', $group->id)->where('product_id', (int) $pid)
                ->update($outcome['ok']
                    ? ['sync_status' => 'pushed', 'push_error' => null]
                    : ['sync_status' => 'error', 'push_error' => mb_substr('Stock push: ' . $outcome['error'], 0, 255)]);
        }

        return $this->productsRedirect($id)->with(
            \App\Integrations\Push\PushLedger::batchTone($results['ok'], $results['err']),
            "Sync Qty: {$results['ok']} ok, {$results['err']} failed"
                . ($results['skipped'] > 0 ? ", {$results['skipped']} skipped (disabled)" : '') . '.'
                . \Extensions\lazada\Services\Lazada\LazadaStockPricePush::ledgerClause($results)
        );
    }

    public function deleteFromLazada(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::findOrFail($id);
        $productIds = $this->parseIds($request);
        if (empty($productIds)) {
            return $this->productsRedirect($id)->with('error', 'No products selected.');
        }

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret'] || !$creds['access_token']) {
            return $this->productsRedirect($id)->with('error', 'Missing Lazada settings.');
        }

        $pfx = (string) config('catalog.prefix');

        $pivotRows = LazadaProductGroupProduct::query()
            ->where('lazada_product_group_id', $group->id)
            ->whereIn('product_id', $productIds)
            ->whereNotNull('lazada_product_id')
            ->get(['product_id', 'lazada_product_id']);

        if ($pivotRows->isEmpty()) {
            return $this->productsRedirect($id)->with('error', 'No linked Lazada products found for the selected items.');
        }

        $okCount = 0;
        $errCount = 0;

        foreach ($pivotRows as $pivotRow) {
            $listing = LazadaProduct::find($pivotRow->lazada_product_id);
            if (!$listing || !$listing->lazada_item_id) {
                $errCount++;
                continue;
            }

            $product = DB::table($pfx . 'product as p')
                ->where('p.product_id', (int) $listing->product_id)
                ->first(['p.product_id', 'p.sku']);

            $mainSku = trim((string) ($product->sku ?? ''));

            $variantSkus = $this->payload()->getErpVariantStockByProductId((int) $listing->product_id);
            $sellerSkuList = [];
            foreach ($variantSkus as $v) {
                $s = trim((string) ($v['seller_sku'] ?? ''));
                if ($s !== '') $sellerSkuList[] = $s;
            }
            $sellerSkuList = array_values(array_unique($sellerSkuList));

            if (empty($sellerSkuList)) {
                if ($mainSku === '') { $errCount++; continue; }
                $sellerSkuList = [$mainSku];
            } elseif ($mainSku !== '' && !in_array($mainSku, $sellerSkuList, true)) {
                $sellerSkuList[] = $mainSku;
            }

            $skuIdList = [];
            $itemId = trim((string) ($listing->lazada_item_id ?? ''));
            if ($itemId !== '') {
                try {
                    $skuIdList = app(LazadaItemCache::class)->fetchSkuIdListByItemId(
                        $client,
                        (string) $setting->region,
                        (string) $creds['app_key'],
                        (string) $creds['app_secret'],
                        (string) $creds['access_token'],
                        $itemId
                    );
                } catch (\Throwable $ex) {}
            }

            $apiPath = '/product/remove';
            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
            ];

            if (empty($skuIdList)) {
                $errCount++;
                continue;
            }
            $params['sku_id_list'] = json_encode($skuIdList, JSON_UNESCAPED_SLASHES);
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);

            $result = $client->post((string) $setting->region, $apiPath, $params);

            $e = $this->payload()->extractLazadaError($result);
            if (!empty($e['code']) && (string) $e['code'] === '6') {
                $params['timestamp'] = (string) round(microtime(true) * 1000);
                $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
                $result = $client->post((string) $setting->region, $apiPath, $params);
                $e = $this->payload()->extractLazadaError($result);
            }

            LazadaApiLog::safeCreate([
                'pack' => 'lazada.product.remove.group',
                'method' => 'POST', 'api_path' => $apiPath,
                'auth_required' => true, 'request_params' => $params,
                'response_status' => (int) ($result['status'] ?? 0),
                'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
            ]);

            if (!empty($e['ok'])) {
                try {
                    $listing->lazada_item_id = null;
                    $listing->lazada_deleted_at = now();
                    $listing->save();
                } catch (\Throwable $ex) {}

                $this->writeProductStatus((int) $group->lazada_setting_id, (int) $pivotRow->product_id, ['sync_status' => 'pending']);
                $this->states($group)->clearErrors([(int) $pivotRow->product_id]);

                ActivityLogger::log('deleted', 'Lazada Product', $listing->id,
                    'Deleted from Lazada via product group, ERP #' . (int) $listing->product_id);

                $okCount++;
            } else {
                $errCount++;
            }
        }

        return $this->productsRedirect($id)
            ->with($okCount > 0 ? 'status' : 'error', "Delete from Lazada: {$okCount} deleted, {$errCount} failed.");
    }

    private function parseIds(Request $request): array
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids)) $ids = [];
        return array_values(array_unique(array_filter(array_map(fn($v) => (int) $v, $ids), fn($v) => $v > 0)));
    }


    private function persistSyncStatus(LazadaProduct $listing, string $action, bool $ok, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        $listing->last_synced_at = now();
        $listing->last_sync_action = $action;
        $listing->last_sync_ok = $ok;
        $listing->last_sync_error_code = $ok ? null : $errorCode;
        $listing->last_sync_error_message = $ok ? null : $errorMessage;
        try { $listing->save(); } catch (\Throwable $ex) {}
    }


    public function syncTemplate(Request $request, int $id, LazadaClient $client)
    {
        $group = LazadaProductGroup::query()->findOrFail($id);

        $request->validate(['lazada_category_id' => 'required|integer|min:1']);
        $lazadaCategoryId = (int) $request->input('lazada_category_id');
        $group->update(['lazada_category_id' => $lazadaCategoryId]);

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret']) {
            return redirect()->route('ext.lazada.product-groups.edit', $group->id)
                ->with('status', 'Missing Lazada settings.');
        }

        $apiPath = '/category/attributes/get';
        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'primary_category_id' => (string) $lazadaCategoryId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
        $result = $client->get((string) $setting->region, $apiPath, $params);

        LazadaCategoryTemplate::query()->updateOrCreate(
            ['region' => (string) $setting->region, 'primary_category_id' => $lazadaCategoryId],
            ['template_body' => $result['body'] ?? null, 'fetched_at' => now()]
        );

        return redirect()->route('ext.lazada.product-groups.edit', $group->id)
            ->with('status', 'Category template synced.');
    }

    private array $lastHeld = [];

    private function addProductsToGroup(LazadaProductGroup $group, array $productIds, array $moveIds = []): int
    {
        $existing = $group->groupProducts()->whereIn('product_id', $productIds)->pluck('product_id')->toArray();

        $claim = \App\Integrations\OneGroupRule::claim('lazada_product_group_products', 'lazada_product_group_id', 'lazada_product_groups', 'lazada_setting_id', $group->id, array_values(array_diff(array_map('intval', $productIds), $existing)), $moveIds);
        $this->lastHeld = $claim['held'];
        $productIds = array_merge($claim['free'], array_keys($claim['moved']), array_map('intval', $existing));
        $added = 0;

        $groupAttrs = LazadaProductGroupAttribute::query()
            ->where('lazada_product_group_id', $group->id)
            ->get();

        $existingLpMap = LazadaProduct::query()
            ->whereIn('product_id', $productIds)
            ->pluck('id', 'product_id');

        $pfx = (string) config('catalog.prefix');
        $newProductIds = array_values(array_diff($productIds, $existingLpMap->keys()->toArray()));
        $erpPrices = [];
        if (!empty($newProductIds)) {
            $erpPrices = DB::table($pfx . 'product')
                ->whereIn('product_id', $newProductIds)
                ->pluck('price', 'product_id')
                ->toArray();
        }

        foreach ($productIds as $pid) {
            if (in_array($pid, $existing)) continue;

            $lpId = $existingLpMap->get($pid);

            if (!$lpId) {
                $lpId = LazadaProduct::create(['product_id' => $pid])->id;
            }

            LazadaProductGroupProduct::create([
                'lazada_product_group_id' => $group->id,
                'lazada_product_id' => $lpId,
                'product_id' => $pid,
            ]);

            $added++;
        }

        return $added;
    }

    private function saveGroupAttributes(LazadaProductGroup $group, array $attrs): void
    {
        foreach ($attrs as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || strtolower($key) === 'brand') continue;

            if ($value === null || $value === '') {
                LazadaProductGroupAttribute::query()
                    ->where('lazada_product_group_id', $group->id)
                    ->where('attribute_key', $key)
                    ->delete();
            } else {
                LazadaProductGroupAttribute::query()->updateOrCreate(
                    ['lazada_product_group_id' => $group->id, 'attribute_key' => $key],
                    ['value' => (string) $value]
                );
            }
        }
    }

    public function refreshCategories(LazadaClient $client)
    {
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret']) {
            return response()->json(['ok' => false, 'message' => 'Missing Lazada settings.']);
        }

        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
        ];
        $apiPath = '/category/tree/get';
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
        $result = $client->get((string) $setting->region, $apiPath, $params);
        $body = $result['body'] ?? null;

        $nodes = [];
        if (is_array($body)) {
            if (isset($body['data']) && is_array($body['data'])) {
                $nodes = $body['data'];
            } elseif (isset($body['data']['data']) && is_array($body['data']['data'])) {
                $nodes = $body['data']['data'];
            }
        }

        if (!$result['ok'] || empty($nodes)) {
            $msg = \App\Support\MarketplaceAnswer::plain('Lazada', ['ok' => false, 'body' => is_array($body) ? $body : []]);
            return response()->json(['ok' => false, 'message' => 'Fetch failed: ' . $msg]);
        }

        $rows = [];
        $this->flattenCategoryTree($nodes, $rows, null, 0);
        if (empty($rows)) {
            return response()->json(['ok' => false, 'message' => 'No categories returned.']);
        }

        $i = 1;
        foreach ($rows as &$r) { $r['id'] = $i++; }
        unset($r);

        DB::transaction(function () use ($rows) {
            DB::table('lazada_categories')->delete();
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('lazada_categories')->insert($chunk);
            }
        });

        $categories = LazadaCategory::query()->orderBy('name')->limit(5000)->get(['category_id', 'name']);
        return response()->json(['ok' => true, 'count' => count($rows), 'categories' => $categories]);
    }

    private function flattenCategoryTree(array $nodes, array &$rows, ?int $parentId, int $level): void
    {
        foreach ($nodes as $n) {
            if (!is_array($n)) continue;
            $categoryId = isset($n['category_id']) ? (int) $n['category_id'] : null;
            $name = isset($n['name']) ? (string) $n['name'] : '';
            if (!$categoryId || $name === '') continue;
            $rows[] = [
                'category_id' => $categoryId,
                'name' => $name,
                'leaf' => (bool) ($n['leaf'] ?? false),
                'var' => array_key_exists('var', $n) ? (bool) $n['var'] : null,
                'parent_id' => $parentId,
                'level' => $level,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (!empty($n['children']) && is_array($n['children'])) {
                $this->flattenCategoryTree($n['children'], $rows, $categoryId, $level + 1);
            }
        }
    }

    public function fetchAttributesAjax(Request $request, LazadaClient $client)
    {
        $categoryId = (int) $request->input('lazada_category_id', 0);
        if ($categoryId <= 0) {
            return response()->json(['ok' => false, 'html' => '']);
        }

        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$setting->region || !$creds['app_key'] || !$creds['app_secret']) {
            return response()->json(['ok' => false, 'html' => '<div class="text-muted">Missing Lazada settings.</div>']);
        }

        $apiPath = '/category/attributes/get';
        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'primary_category_id' => (string) $categoryId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
        $result = $client->get((string) $setting->region, $apiPath, $params);

        $template = LazadaCategoryTemplate::query()->updateOrCreate(
            ['region' => (string) $setting->region, 'primary_category_id' => $categoryId],
            ['template_body' => $result['body'] ?? null, 'fetched_at' => now()]
        );

        $attributes = $this->attrs()->extractAttributes($template->template_body);
        $erpSourceFields = \Extensions\lazada\Controllers\LazadaAttributes::ERP_SOURCE_FIELDS;

        $html = view('ext-lazada::product-groups._attributes', [
            'attributes' => $attributes,
            'saved' => [],
            'erpSourceFields' => $erpSourceFields,
            'template' => $template,
        ])->render();

        return response()->json(['ok' => true, 'html' => $html, 'count' => count($attributes)]);
    }

    private function region(): string
    {
        $setting = LazadaSetting::defaultStore();
        return (string) ($setting->region ?? '');
    }


}
