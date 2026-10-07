<?php

namespace Extensions\ventacart\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\ventacart\Models\VentaCartBrand;
use Extensions\ventacart\Models\VentaCartCategory;
use Extensions\ventacart\Models\VentaCartProductGroup;
use Extensions\ventacart\Models\VentaCartProductGroupProduct;
use Extensions\ventacart\Models\VentaCartProductLink;
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Services\VentaCart\VentaCartClient;
use Extensions\ventacart\Services\VentaCart\VentaCartProductPush;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VentaCartProductGroupController extends Controller
{
    use \App\Http\Controllers\Concerns\DrivesGroupSendRuns;

    protected function groupSendIntegration(): string
    {
        return 'ventacart';
    }

    protected function groupSendStoreId(Request $request): int
    {
        return (int) $request->route('store');
    }

    protected function groupSendMembers(int $groupId): array
    {
        $group = VentaCartProductGroup::query()->where('ventacart_setting_id', (int) request()->route('store'))->findOrFail($groupId);

        return $group->products()->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
    }

    protected function groupSendChunk(int $groupId, array $productIds): array
    {
        $store = (int) request()->route('store');
        $setting = $this->storeForWrite_($store);
        if (! $setting) {
            return ['tone' => 'error', 'summary' => 'That VentaCart store is turned off, so nothing was sent to it. Enable it in its settings first.', 'failed' => 0, 'stop' => true];
        }

        return $this->sendProducts($setting, VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail($groupId), $productIds);
    }

    public function navRedirect()
    {
        $store = VentaCartSetting::where('enabled', true)->first();
        if (!$store) {
            return redirect()->route('ext.ventacart.index')->with('error', 'No enabled VentaCart store. Add one first.');
        }
        return redirect()->route('ext.ventacart.product-groups.index', $store->id);
    }

    private function pushFailureText(array $result, string $sku): string
    {
        return VentaCartProductPush::failureText($result, $sku);
    }

    private function collectImageVerdict(array $result, int $pid, int &$sent, int &$stored, array &$reasons): void
    {
        $verdict = VentaCartProductPush::imageVerdict($result);
        $sent += $verdict['sent'];
        $stored += $verdict['stored'];
        foreach ($verdict['reasons'] as $reason => $count) {
            $reasons[$reason] = ($reasons[$reason] ?? 0) + $count;
        }
    }

    private function writeProductStatus(VentaCartProductGroup $g, int $productId, array $attributes): void
    {
        \App\Support\ChannelProductStatus::write(
            'ventacart_product_group_products',
            'ventacart_product_group_id',
            VentaCartProductGroup::where('ventacart_setting_id', $g->ventacart_setting_id)->pluck('id')->all(),
            $productId,
            $attributes
        );
    }

    private function candidateSkus(string $pfx, int $productId): array
    {
        return VentaCartProductPush::for(new VentaCartSetting())->candidateSkus($productId);
    }

    private function reconcileLink(VentaCartClient $client, int $store, int $productId, string $pfx): array
    {
        return (new VentaCartProductPush($this->store_($store), $client))->reconcileLink($productId);
    }

    // A stale link held by another product blocks this write; report it, never take the link over.
    private function linkProductTo(int $store, int $productId, $ventaCartProductId, ?string $sku): ?string
    {
        return VentaCartProductPush::for($this->store_($store))->linkProductTo($productId, $ventaCartProductId, $sku);
    }

    private function store_(int $storeId): VentaCartSetting
    {
        return VentaCartSetting::findOrFail($storeId);
    }

    private function storeForWrite_(int $storeId): ?VentaCartSetting
    {
        $setting = VentaCartSetting::findOrFail($storeId);

        return $setting->enabled ? $setting : null;
    }

    private function productsRedirect(int $store, int $group)
    {
        $fallback = route('ext.ventacart.product-groups.products', [$store, $group]);

        return redirect(\App\Support\BackTo::safe(request()->input('_return'), $fallback));
    }

    public function index(int $store)
    {
        $setting = $this->store_($store);

        $groups = VentaCartProductGroup::where('ventacart_setting_id', $store)->orderByDesc('id')->get();

        $productCounts = [];
        foreach ($groups as $g) {
            $productCounts[$g->id] = $g->products()->count();
        }

        return view('ext-ventacart::product-groups.index', [
            'setting'       => $setting,
            'groups'        => $groups,
            'productCounts' => $productCounts,
        ]);
    }

    public function create(int $store)
    {
        $setting = $this->store_($store);
        return $this->form($setting, new VentaCartProductGroup(['ventacart_setting_id' => $store]), 'create');
    }

    public function edit(int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail($group);
        return $this->form($setting, $g, 'edit');
    }

    private function form(VentaCartSetting $setting, VentaCartProductGroup $group, string $mode)
    {
        return view('ext-ventacart::product-groups.form', [
            'setting' => $setting,
            'group' => $group,
            'mode' => $mode,
            'storeCategories' => VentaCartCategory::query()->where('ventacart_setting_id', $setting->id)
                ->orderBy('name')->get(['ventacart_category_id', 'name', 'parent_id']),
            'productCount' => $group->exists ? $group->products()->count() : 0,
        ]);
    }

    public function store(Request $request, int $store)
    {
        $this->store_($store);

        $data = $request->validate($this->rules($store));

        $group = VentaCartProductGroup::create([
            'ventacart_setting_id'     => $store,
            'name'                 => $data['name'],
            'ventacart_category_id'    => $data['ventacart_category_id'] ?? null,
            'ventacart_brand_id'       => $data['ventacart_brand_id'] ?? null,
            'markup_percent'       => $data['markup_percent'] ?? 0, 'watermark_template_id' => \App\Models\WatermarkTemplate::idOrNull($data['watermark_template_id'] ?? null, 'ventacart', \App\Integrations\Listings\ListingStore::id('ventacart')),
            'markup_fixed'         => $data['markup_fixed'] ?? 0,
        ]);

        ActivityLogger::log('created', 'VentaCart Product Group', $group->id, $group->name);

        return redirect(\App\Support\BackTo::safe(
            $request->input('_return'),
            route('ext.ventacart.product-groups.products', [$store, $group->id])
        ))->with('status', 'Product group created. Add products to it here.');
    }

    private function rules(int $store): array
    {
        return [
            'name'              => 'required|string|max:255',
            'ventacart_category_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('ventacart_categories', 'ventacart_category_id')->where('ventacart_setting_id', $store)],
            'ventacart_brand_id'    => 'nullable|integer',
            'markup_percent'    => 'nullable|numeric|min:0',
            'watermark_template_id' => 'nullable|integer',
            'markup_fixed'      => 'nullable|numeric|min:0',
        ];
    }

    public function update(Request $request, int $store, int $group)
    {
        $this->store_($store);
        $g = VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail($group);

        $data = $request->validate($this->rules($store));

        $g->update([
            'name'                 => $data['name'],
            'ventacart_category_id'    => $data['ventacart_category_id'] ?? null,
            'ventacart_brand_id'       => $data['ventacart_brand_id'] ?? $g->ventacart_brand_id,
            'markup_percent'       => $data['markup_percent'] ?? $g->markup_percent, 'watermark_template_id' => \App\Models\WatermarkTemplate::idOrNull($data['watermark_template_id'] ?? null, 'ventacart', \App\Integrations\Listings\ListingStore::id('ventacart')),
            'markup_fixed'         => $data['markup_fixed'] ?? $g->markup_fixed,
        ]);

        ActivityLogger::log('updated', 'VentaCart Product Group', $g->id, $g->name);

        return redirect()->route('ext.ventacart.product-groups.edit', [$store, $g->id])
            ->with('status', 'Product group saved.');
    }

    public function destroy(int $store, int $group)
    {
        $this->store_($store);
        $g = VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail($group);
        $name = $g->name;
        $members = $g->products()->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $g->delete();
        \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store)->clearErrors($members);

        ActivityLogger::log('deleted', 'VentaCart Product Group', (int) $group, $name);

        return redirect()->route('ext.ventacart.product-groups.index', $store)
            ->with('status', 'Product group "' . e($name) . '" deleted.');
    }

    public function products(Request $request, int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail($group);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productIds = $this->getMatchingProductIds($g);

        if (empty($productIds)) {
            return view('ext-ventacart::product-groups.products', [
                'setting'              => $setting,
                'group'                => $g,
                'products'             => new \Illuminate\Pagination\LengthAwarePaginator([], 0, 50),
                'rowTitles'            => [],
                'optionRowsByProductId' => collect(),
                'manualIds'            => [],
            ]);
        }

        $q = trim((string) $request->input('q'));
        $syncStatus = (string) $request->input('sync_status', 'all');
        $erpStatus = (string) $request->input('erp_status', 'all');

        $query = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->leftJoin('ventacart_product_links as vpl', function ($j) use ($store) {
                $j->on('p.product_id', '=', 'vpl.product_id')
                    ->where('vpl.ventacart_setting_id', '=', $store);
            })
            ->leftJoin('ventacart_product_group_products as vpgp', function ($j) use ($g) {
                $j->on('p.product_id', '=', 'vpgp.product_id')
                    ->where('vpgp.ventacart_product_group_id', '=', $g->id);
            })
            ->whereIn('p.product_id', $productIds)
            ->select(
                'p.product_id',
                'pd.name',
                'p.model',
                'p.sku',
                'p.price',
                'p.quantity',
                'p.status',
                'p.image',
                'm.name as manufacturer_name',
                'vpl.ventacart_product_id',
                'vpl.sku as ventacart_sku',
                'vpgp.sync_status',
                'vpgp.last_pushed_at',
                'vpgp.last_confirmed_at',
                'vpgp.push_error'
            );

        if ($q !== '') {
            $query->where(function ($w) use ($q, $store) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%")
                    ->orWhere('p.model', 'like', "%{$q}%")
                    ->orWhereIn('p.product_id', \Extensions\ventacart\Models\VentaCartListing::query()
                        ->where('ventacart_setting_id', $store)
                        ->where('name', 'like', "%{$q}%")
                        ->select('product_id'));
            });
        }

        if ($erpStatus === 'enabled') {
            $query->where('p.status', 1);
        } elseif ($erpStatus === 'disabled') {
            $query->where('p.status', 0);
        }

        if ($syncStatus === 'pushed') {
            $query->whereNotNull('vpl.ventacart_product_id')
                ->whereNotIn('vpgp.sync_status', ['unlinked', 'error', 'failed']);
        } elseif ($syncStatus === 'pending') {
            $query->whereNull('vpl.ventacart_product_id')
                ->where(function ($w) {
                    $w->whereNotIn('vpgp.sync_status', ['error', 'failed', 'unlinked'])
                        ->orWhereNull('vpgp.sync_status');
                });
        } elseif ($syncStatus === 'error') {
            $query->whereIn('p.product_id', \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store)->erroredProductIds() ?: [0]);
        }

        $storeId = \App\Integrations\Listings\ListingStore::id('ventacart');
        $sortMenu = ['added' => 'vpgp.id', 'pushed' => 'vpgp.last_pushed_at'] + \Extensions\ventacart\Services\VentaCartListingSort::columns($storeId);
        $order = \App\Integrations\Listings\ListingSort::chosen($request, 'ventacart.group.' . $g->id);
        \Extensions\ventacart\Services\VentaCartListingSort::join($query, $storeId);
        \App\Integrations\Listings\ListingSort::apply($query, $order, $sortMenu);

        $products = $query->paginate(50)->withQueryString();

        $paginatedIds = $products->pluck('product_id')->toArray();
        $optionRowsByProductId = \App\Support\VariationRows::forListing($paginatedIds, 'ventacart', $store);

        $manualIds = $g->products()->pluck('product_id')->toArray();

        $rowTitles = \App\Integrations\Listings\ListingContent::rowTitles($products, \Extensions\ventacart\Models\VentaCartListing::query()
            ->where('ventacart_setting_id', $store)
            ->whereIn('product_id', $paginatedIds ?: [0])
            ->get());

        return view('ext-ventacart::product-groups.products', [
            'setting'              => $setting,
            'group'                => $g,
            'products'             => $products,
            'rowTitles'            => $rowTitles,
            'optionRowsByProductId' => $optionRowsByProductId,
            'order' => $order,
            'orderOptions' => \App\Integrations\Listings\ListingSort::options(array_keys($sortMenu)),
            'listingStates'        => \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for((int) $setting->id)->forProducts(array_map('intval', $paginatedIds)),
            'rowErrors'            => \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for((int) $setting->id)->errors(array_map('intval', $paginatedIds)),
            'manualIds'            => $manualIds,
            'q'                    => $q,
            'syncStatus'           => $syncStatus,
            'erpStatus'            => $erpStatus,
        ]);
    }

    public function syncId(int $store, int $group, int $product)
    {
        $setting = $this->storeForWrite_($store);

        if (! $setting) {
            return redirect()->back()->with('error',
                'That VentaCart store is turned off, so nothing was sent to it. Enable it in its settings first.');
        }
        $g = VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail($group);

        $productIds = $this->getMatchingProductIds($g);
        if (!in_array($product, $productIds)) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'Product not in this product group.');
        }

        $pfx = (string) config('catalog.prefix');
        $client = new VentaCartClient($setting);

        $prod = DB::table($pfx . 'product')->where('product_id', $product)->first(['sku', 'model']);
        if (!$prod) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'Product not found in catalog.');
        }

        $skus = [];
        if ($prod->sku && trim($prod->sku) !== '') {
            $skus[] = trim($prod->sku);
        }
        if ($prod->model && trim($prod->model) !== '' && !in_array(trim($prod->model), $skus)) {
            $skus[] = trim($prod->model);
        }

        $optSkus = DB::table($pfx . 'product_option_value')
            ->where('product_id', $product)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->pluck('sku')
            ->map(fn($s) => trim($s))
            ->unique()
            ->toArray();
        $skus = array_unique(array_merge($skus, $optSkus));

        if (empty($skus)) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'Product has no SKU to match against VentaCart.');
        }

        $matchedVentaId = null;
        $matchedSku = null;

        foreach ($skus as $sku) {
            $result = $client->getProducts(perPage: 50, sku: $sku);

            if (!($result['ok'] ?? false)) {
                continue;
            }

            $ventaCartProducts = $result['body']['data'] ?? $result['body'] ?? [];
            foreach ($ventaCartProducts as $vp) {
                $vpId = $vp['id'] ?? null;
                $vpSku = $vp['sku'] ?? null;

                if ($vpId && $vpSku !== null && strcasecmp(trim((string) $vpSku), $sku) === 0) {
                    $matchedVentaId = (int) $vpId;
                    $matchedSku = $sku;
                    break 2;
                }

                foreach ($vp['variants'] ?? [] as $variant) {
                    $varSku = $variant['sku'] ?? null;
                    if ($varSku !== null && strcasecmp(trim((string) $varSku), $sku) === 0) {
                        $matchedVentaId = (int) $vpId;
                        $matchedSku = $sku;
                        break 3;
                    }
                }
            }
        }

        if (!$matchedVentaId) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'No matching product found on VentaCart for SKU(s): ' . implode(', ', $skus));
        }

        if ($clash = $this->linkProductTo($store, $product, $matchedVentaId, $matchedSku)) {
            return $this->productsRedirect($store, $group)->with('error', $clash);
        }

        $this->writeProductStatus($g, $product, [
                'sync_status' => 'synced',
                'ventacart_sku'   => $matchedSku,
                'push_error'  => null,
            ]);
        \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store)->recordOutcome($product, null);

        return $this->productsRedirect($store, $group)
            ->with('status', "VentaCart product ID {$matchedVentaId} synced for SKU: {$matchedSku}");
    }

    public function orphans(Request $request, int $store, int $group)
    {
        $setting = $this->store_($store);
        $g = VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail($group);

        if (! $setting->enabled) {
            return $this->productsRedirect($store, $group)->with('error',
                'That VentaCart store is turned off, so its products could not be listed.');
        }

        $storeName = $setting->store_name ?: 'Unnamed store';
        $client = new VentaCartClient($setting);

        $known = VentaCartProductLink::where('ventacart_setting_id', $store)
            ->whereNotNull('ventacart_product_id')
            ->pluck('ventacart_product_id')
            ->map('intval')
            ->flip();

        $orphans = [];
        $scanned = 0;
        $page = 1;
        $maxPages = 20;
        $perPage = 100;
        $truncated = false;
        $error = null;

        while ($page <= $maxPages) {
            $result = $client->get('products', ['per_page' => $perPage, 'page' => $page]);

            if (! ($result['ok'] ?? false)) {
                $status = (int) ($result['status'] ?? 0);
                $error = $status === 0
                    ? $storeName . ' could not be reached, so the list below may be incomplete.'
                    : $storeName . ' answered ' . $status . ', so the list below may be incomplete.';
                break;
            }

            $body = $result['body'] ?? [];
            $rows = $body['data'] ?? (is_array($body) ? $body : []);

            if (! is_array($rows) || $rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $scanned++;
                $id = (int) ($row['id'] ?? 0);

                if ($id <= 0 || $known->has($id)) {
                    continue;
                }

                $orphans[] = [
                    'id' => $id,
                    'sku' => (string) ($row['sku'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                ];
            }

            if (count($rows) < $perPage) {
                break;
            }

            if ($page === $maxPages) {
                $truncated = true;
            }

            $page++;
        }

        return view('ext-ventacart::product-groups.orphans', [
            'setting' => $setting,
            'group' => $g,
            'storeName' => $storeName,
            'orphans' => $orphans,
            'scanned' => $scanned,
            'truncated' => $truncated,
            'error' => $error,
        ]);
    }

    public function checkAgainstVenta(Request $request, int $store, int $group)
    {
        $setting = $this->storeForWrite_($store);

        if (! $setting) {
            return redirect()->back()->with('error',
                'That VentaCart store is turned off, so nothing could be checked. Enable it in its settings first.');
        }

        $g = VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail($group);

        $ids = array_values(array_unique(array_map('intval', array_filter((array) $request->input('ids', [])))));

        if (empty($ids)) {
            $ids = DB::table('ventacart_product_group_products')
                ->where('ventacart_product_group_id', $group)
                ->pluck('product_id')->map('intval')->all();
        }

        if (empty($ids)) {
            return $this->productsRedirect($store, $group)->with('error', 'This product group has no products to check.');
        }

        $r = app(\Extensions\ventacart\Services\VentaCart\VentaCartLinkCheck::class)->run($setting, $ids);

        return $this->productsRedirect($store, $group)->with($r['tone'], $r['summary']);
    }

    public function pushProducts(Request $request, int $store, int $group)
    {

        $setting = $this->storeForWrite_($store);

        if (! $setting) {
            return redirect()->back()->with('error',
                'That VentaCart store is turned off, so nothing was sent to it. Enable it in its settings first.');
        }
        $g = VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail($group);

        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));
        if (empty($ids)) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'No products selected.');
        }

        $r = $this->sendProducts($setting, $g, $ids);

        return $this->productsRedirect($store, $group)->with($r['tone'], $r['summary']);
    }

    public function sendProducts(VentaCartSetting $setting, VentaCartProductGroup $g, array $ids): array
    {
        $store = (int) $setting->id;
        $group = (int) $g->id;
        $client = new VentaCartClient($setting);
        $push = VentaCartProductPush::for($setting);
        $states = \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store);
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $created = 0;
        $updated = 0;
        $verdicts = [];

        $imagesSent = 0;
        $imagesStored = 0;
        $imageRejections = [];
        $failed = 0;
        $errors = [];

        foreach ($ids as $pid) {
            $prod = DB::table($pfx . 'product as p')
                ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                    $j->on('p.product_id', '=', 'pd.product_id')
                        ->where('pd.language_id', '=', $langId);
                })
                ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
                ->where('p.product_id', $pid)
                ->first([
                    'p.product_id', 'pd.name', 'pd.description', 'p.model', 'p.sku',
                    'p.price', 'p.cost', 'p.quantity', 'p.status', 'p.image',
                    'p.weight', 'p.length', 'p.width', 'p.height',
                    'm.name as brand_name',
                ]);

            if (!$prod) {
                $failed++;
                continue;
            }

            $readiness = \Extensions\ventacart\Services\VentaCart\VentaCartListingReadiness::forProducts([$pid])[$pid] ?? ['ready' => false, 'missing' => []];
            if (!$readiness['ready']) {
                $failed++;
                $refusal = \App\Integrations\Listings\CatalogGaps::refusal($readiness);
                $errors[] = "Product #{$pid}: {$refusal}";
                $this->writeProductStatus($g, $pid, ['sync_status' => 'error', 'push_error' => \Illuminate\Support\Str::limit($refusal, 480)]);
                $states->recordOutcome($pid, $refusal);
                continue;
            }
            $sku = $prod->sku ?: $prod->model;

            $ownWords = \App\Integrations\Listings\ListingContent::of(
                \Extensions\ventacart\Models\VentaCartListing::query()->where('ventacart_setting_id', $store)->where('product_id', $pid)->first(),
                (string) ($prod->name ?? ''),
                (string) ($prod->description ?? '')
            );

            $listing = \Extensions\ventacart\Models\VentaCartListing::query()
                ->where('ventacart_setting_id', $store)->where('product_id', $pid)->first();

            $priceFor = match (true) {
                $listing && $listing->hasPriceRule() => fn (float $base) => $listing->priceFor($base),
                $g->markup_percent !== null || $g->markup_fixed !== null
                    => fn (float $base) => round($base + ($base * (float) ($g->markup_percent ?? 0) / 100) + (float) ($g->markup_fixed ?? 0), 2),
                default => null,
            };

            $data = $push->payload(
                $prod,
                array_filter([
                    'name' => $ownWords['title'], 'description' => $ownWords['description'],
                    'meta_title' => $listing?->meta_title, 'meta_description' => $listing?->meta_description,
                ], fn ($v) => $v !== null && trim((string) $v) !== ''),
                $priceFor,
                \Extensions\ventacart\Models\VentaCartListing::categoryFor($listing, $g)
            );

            $verdict = $this->reconcileLink($client, $store, $pid, $pfx);
            $verdicts[$verdict['state']] = ($verdicts[$verdict['state']] ?? 0) + 1;

            if ($verdict['state'] === 'blocked' || $verdict['state'] === 'unreachable') {
                $failed++;
                $errors[] = "#{$pid}: {$verdict['message']}";
                $this->writeProductStatus($g, $pid, [
                    'sync_status' => 'error',
                    'push_error' => $verdict['message'],
                    'last_pushed_at' => now(),
                ]);
                $states->recordOutcome($pid, (string) $verdict['message']);
                continue;
            }

            $this->writeProductStatus($g, $pid, ['last_confirmed_at' => now()]);

            $existingLink = VentaCartProductLink::where('ventacart_setting_id', $store)
                ->where('product_id', $pid)
                ->first();

            if ($existingLink && $existingLink->ventacart_product_id) {
                $result = $client->updateProduct($sku, $push->withCatalogNames($prod, $data));
                if ($result['ok']) {
                    $updated++;
                    $this->collectImageVerdict($result, $pid, $imagesSent, $imagesStored, $imageRejections);
                    $this->writeProductStatus($g, $pid, [
                            'sync_status'  => 'synced',
                            'last_pushed_at' => now(),
                            'push_error'   => null,
                            'ventacart_sku'    => $sku,
                        ]);
                    $states->recordOutcome($pid, null);
                } else {
                    $failed++;
                    $errMsg = json_encode($result['body']);
                    $errors[] = "Product #{$pid}: {$errMsg}";
                    $this->writeProductStatus($g, $pid, ['sync_status' => 'failed', 'push_error' => $errMsg]);
                    $states->recordOutcome($pid, (string) $errMsg);
                }
            } else {
                $result = $client->createProduct($data);
                if ($result['ok']) {
                    $created++;
                    $this->collectImageVerdict($result, $pid, $imagesSent, $imagesStored, $imageRejections);
                    $ventaCartProductId = $result['body']['id'] ?? $result['body']['data']['id'] ?? 0;

                    if ($clash = $this->linkProductTo($store, $pid, $ventaCartProductId, $sku)) {
                        $failed++;
                        $created--;
                        $errors[] = "#{$pid}: created on VentaCart as {$ventaCartProductId}, but {$clash}";
                        $states->recordOutcome($pid, "Created on VentaCart as {$ventaCartProductId}, but {$clash}");
                        continue;
                    }

                    $this->writeProductStatus($g, $pid, [
                            'sync_status'  => 'synced',
                            'last_pushed_at' => now(),
                            'push_error'   => null,
                            'ventacart_sku'    => $sku,
                        ]);
                    $states->recordOutcome($pid, null);
                } else {
                    $failed++;
                    $errMsg = $this->pushFailureText($result, $sku);
                    $errors[] = "Product #{$pid}: {$errMsg}";
                    $this->writeProductStatus($g, $pid, ['sync_status' => 'failed', 'push_error' => Str::limit($errMsg, 480)]);
                    $states->recordOutcome($pid, $errMsg);
                }
            }
        }

        if ($created > 0 || $updated > 0) {
            $setting->update(['last_product_sync_at' => now()]);
        }

        $msg = "Push complete. Created: {$created}, Updated: {$updated}, Failed: {$failed}";

        $notes = [];
        if (! empty($verdicts['lost'])) {
            $notes[] = $verdicts['lost'] . ' had been removed on VentaCart';
        }
        if (! empty($verdicts['adopted'])) {
            $notes[] = $verdicts['adopted'] . ' already existed on VentaCart and ' .
                ($verdicts['adopted'] === 1 ? 'was linked' : 'were linked');
        }
        if (! empty($verdicts['repointed'])) {
            $notes[] = $verdicts['repointed'] . ' had been linked to the wrong VentaCart product';
        }
        if (! empty($verdicts['unreachable'])) {
            $notes[] = $verdicts['unreachable'] . ' could not be checked because VentaCart did not answer';
        }
        if ($notes) {
            $msg .= '. ' . ucfirst(implode('; ', $notes));
        }

        if ($imagesSent > 0 && $imagesStored < $imagesSent) {
            $rejected = $imagesSent - $imagesStored;
            $msg .= ". {$rejected} of {$imagesSent} images were rejected by VentaCart";

            arsort($imageRejections);
            $lines = [];
            foreach (array_slice($imageRejections, 0, 2, true) as $reason => $count) {
                $lines[] = "{$count}x {$reason}";
            }
            $msg .= ': ' . implode('; ', $lines);

            if (count($imageRejections) > 2) {
                $msg .= ' (and ' . (count($imageRejections) - 2) . ' other reasons)';
            }
        } elseif ($imagesSent > 0) {
            $msg .= ". {$imagesStored} images stored";
        }

        if (! empty($errors)) {
            Log::warning('VentaCart pushProducts errors', ['store' => $store, 'group' => $group, 'errors' => $errors]);

            $msg .= '. ' . implode('; ', array_slice($errors, 0, 3));

            if (count($errors) > 3) {
                $msg .= ' (and ' . (count($errors) - 3) . ' more, shown against their rows)';
            }
        }

        $lostImages = $imagesSent > 0 && $imagesStored < $imagesSent;

        $tone = match (true) {
            $failed > 0 && ($created + $updated) === 0 => 'error',
            $failed > 0 || $lostImages => 'warning',
            default => 'status',
        };

        return ['tone' => $tone, 'summary' => $msg, 'failed' => min(count($ids), $failed), 'stop' => false];
    }

    public function pushStock(Request $request, int $store, int $group)
    {
        $setting = $this->storeForWrite_($store);

        if (! $setting) {
            return redirect()->back()->with('error',
                'That VentaCart store is turned off, so nothing was sent to it. Enable it in its settings first.');
        }
        $g = VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail($group);

        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));
        if (empty($ids)) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'No products selected.');
        }

        return $this->pushFigures($setting, $store, $group, $ids, true, false, 'Stock');
    }

    private function pushFigures(VentaCartSetting $setting, int $store, int $group, array $ids, bool $stock, bool $price, string $what)
    {
        if (! VentaCartProductLink::where('ventacart_setting_id', $store)->whereIn('product_id', $ids)->exists()) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'No linked VentaCart products found for the selected items.');
        }

        $r = \Extensions\ventacart\Services\VentaCart\VentaCartStockPricePush::for($setting)->push($ids, $stock, $price);
        \Extensions\ventacart\Services\VentaCart\VentaCartStockPricePush::recordOutcomes($store, $r['outcomes']);

        return $this->productsRedirect($store, $group)->with($r['failed'] > 0 ? ($r['ok'] > 0 ? 'warning' : 'error') : 'status',
            \Extensions\ventacart\Services\VentaCart\VentaCartStockPricePush::sentence($r, $what, (string) $setting->store_name));
    }

    public function pushPrices(Request $request, int $store, int $group)
    {
        $setting = $this->storeForWrite_($store);

        if (! $setting) {
            return redirect()->back()->with('error',
                'That VentaCart store is turned off, so nothing was sent to it. Enable it in its settings first.');
        }
        $g = VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail($group);

        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));
        if (empty($ids)) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'No products selected.');
        }

        return $this->pushFigures($setting, $store, $group, $ids, false, true, 'Price');
    }

    public function unlinkProduct(int $store, int $group, int $product)
    {
        $this->store_($store);
        $g = VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail($group);

        $removed = VentaCartProductLink::where('ventacart_setting_id', $store)
            ->where('product_id', $product)
            ->delete();

        $this->writeProductStatus($g, $product, [
            'sync_status' => 'pending',
            'push_error' => null,
        ]);
        \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store)->clearErrors([$product]);

        return $this->productsRedirect($store, $group)->with(
            'status',
            $removed > 0
                ? 'Unlinked. This product is no longer connected to anything on VentaCart; sending it again will re-match or create it.'
                : 'That product was not linked to VentaCart, so there was nothing to unlink.'
        );
    }

    private function deleteManyFromVenta($setting, $g, int $store, int $group, array $productIds)
    {
        $client = new VentaCartClient($setting);
        $deleted = 0;
        $skipped = 0;
        $errors = [];

        $links = VentaCartProductLink::where('ventacart_setting_id', $store)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        foreach ($productIds as $pid) {
            $link = $links->get($pid);

            if (! $link || ! $link->sku) {
                $skipped++;
                continue;
            }

            $result = $client->delete("products/{$link->sku}");

            if ($result['ok']) {
                $link->delete();

                $this->writeProductStatus($g, $pid, ['sync_status' => 'pending', 'push_error' => null, 'last_pushed_at' => null]);
                \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store)->clearErrors([$pid]);

                $deleted++;
                continue;
            }

            $message = $result['body']['error'] ?? 'Unknown error';
            $errors[] = "#{$pid}: {$message}";

            $this->writeProductStatus($g, $pid, [
                    'sync_status' => 'error',
                    'push_error'  => Str::limit('Delete from VentaCart failed. ' . $message, 480),
                ]);
            \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store)->recordOutcome($pid, 'Delete from VentaCart failed. ' . $message);

            usleep(150000);
        }

        $msg = "Delete from VentaCart: {$deleted} deleted";
        if ($skipped > 0) {
            $msg .= ", {$skipped} skipped (not linked)";
        }
        if ($errors) {
            $msg .= ', ' . count($errors) . ' failed. ' . implode('; ', array_slice($errors, 0, 3));
            if (count($errors) > 3) {
                $msg .= ' (and ' . (count($errors) - 3) . ' more, shown against their rows)';
            }
        }

        $tone = empty($errors) ? ($deleted > 0 ? 'status' : 'warning') : ($deleted > 0 ? 'warning' : 'error');

        return $this->productsRedirect($store, $group)->with($tone, $msg . '.');
    }

    public function deleteFromVenta(Request $request, int $store, int $group, ?int $product = null)
    {
        $setting = $this->storeForWrite_($store);

        if (! $setting) {
            return redirect()->back()->with('error',
                'That VentaCart store is turned off, so nothing was sent to it. Enable it in its settings first.');
        }
        $g = VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail($group);

        $productIds = $product !== null
            ? [$product]
            : array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])))));

        if (empty($productIds)) {
            return $this->productsRedirect($store, $group)->with('error', 'No products selected.');
        }

        if (count($productIds) > 1) {
            return $this->deleteManyFromVenta($setting, $g, $store, $group, $productIds);
        }

        $product = $productIds[0];

        $link = VentaCartProductLink::where('ventacart_setting_id', $store)
            ->where('product_id', $product)
            ->first();

        if (!$link || !$link->sku) {
            return $this->productsRedirect($store, $group)
                ->with('error', 'Product not linked to VentaCart, nothing to delete.');
        }

        $client = new VentaCartClient($setting);
        $result = $client->delete("products/{$link->sku}");

        if ($result['ok']) {
            $link->delete();

            $this->writeProductStatus($g, $product, ['sync_status' => 'pending', 'push_error' => null, 'last_pushed_at' => null]);
            \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store)->clearErrors([$product]);

            return $this->productsRedirect($store, $group)
                ->with('status', 'Product deleted from VentaCart store.');
        }

        $error = $result['body']['error'] ?? 'Unknown error';
        \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store)->recordOutcome($product, 'Delete from VentaCart failed. ' . $error);
        return $this->productsRedirect($store, $group)
            ->with('error', 'Failed to delete from VentaCart: ' . $error);
    }

    public function linkProduct(int $store, int $group, int $product)
    {
        $this->store_($store);
        $g = VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail($group);

        $pivot = VentaCartProductGroupProduct::where('ventacart_product_group_id', $g->id)
            ->where('product_id', $product)
            ->first();

        if ($pivot) {
            $hasLink = VentaCartProductLink::where('ventacart_setting_id', $store)
                ->where('product_id', $product)
                ->whereNotNull('ventacart_product_id')
                ->where('ventacart_product_id', '!=', 0)
                ->exists();

            $pivot->update(['sync_status' => $hasLink ? 'synced' : 'pending']);
            \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store)->recordOutcome($product, null);
        }

        return $this->productsRedirect($store, $group)
            ->with('status', 'Product re-linked.');
    }

    public function moveProducts(Request $request, int $store)
    {
        $this->store_($store);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])), fn ($v) => $v > 0)));
        $back = \App\Support\BackTo::safe($request->input('_return'), route('ext.ventacart.listings.index', $store));
        if ($ids === []) {
            return redirect($back)->with('warning', 'No products selected.');
        }
        $target = (string) $request->input('group', '');
        if ($target === 'none') {
            $removed = VentaCartProductGroupProduct::query()
                ->whereIn('ventacart_product_group_id', VentaCartProductGroup::where('ventacart_setting_id', $store)->select('id'))
                ->whereIn('product_id', $ids)->delete();

            return redirect($back)->with('status', $removed . ' ' . \Illuminate\Support\Str::plural('product', $removed) . ' ungrouped.');
        }
        $group = VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail((int) $target);

        $already = $group->products()->whereIn('product_id', $ids)->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $wanted = array_values(array_diff($ids, $already));
        $claim = \App\Integrations\OneGroupRule::claim('ventacart_product_group_products', 'ventacart_product_group_id', 'ventacart_product_groups', 'ventacart_setting_id', $group->id, $wanted, $wanted);
        $moved = 0;
        foreach (array_merge($claim['free'], array_keys($claim['moved'])) as $pid) {
            VentaCartProductGroupProduct::create(['ventacart_product_group_id' => $group->id, 'product_id' => (int) $pid]);
            $moved++;
        }
        if ($moved === 0) {
            return redirect($back)->with('warning', 'Those products are already in ' . $group->name . '.');
        }

        return redirect($back)->with('status', $moved . ' ' . \Illuminate\Support\Str::plural('product', $moved) . ' moved to ' . $group->name . '.');
    }

    public function massRemove(Request $request, int $store, int $group)
    {
        $this->store_($store);
        $g = VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail($group);

        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));

        if (!empty($ids)) {
            $g->products()->whereIn('product_id', $ids)->delete();
            \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store)->clearErrors($ids);
        }

        return $this->productsRedirect($store, $group)
            ->with('status', count($ids) . ' product(s) removed from the product group.');
    }

    public function addProducts(Request $request, int $store, int $group)
    {
        $this->store_($store);
        $g = VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail($group);

        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return $this->productsRedirect($store, $group)
                ->with('status', 'No products selected.');
        }

        $ids = array_map('intval', (array) $ids);
        $existing = $g->products()->pluck('product_id')->toArray();
        $ids = array_values(array_diff($ids, $existing));

        $claim = \App\Integrations\OneGroupRule::claim('ventacart_product_group_products', 'ventacart_product_group_id', 'ventacart_product_groups', 'ventacart_setting_id', $g->id, $ids, array_map('intval', (array) $request->input('move_ids', [])));

        $added = 0;
        foreach (array_merge($claim['free'], array_keys($claim['moved'])) as $pid) {
            VentaCartProductGroupProduct::create([
                'ventacart_product_group_id' => $g->id,
                'product_id'             => $pid,
            ]);
            $added++;
        }

        return $this->productsRedirect($store, $group)
            ->with($added > 0 ? 'status' : 'warning', "{$added} product(s) added to product group."
                . \App\Integrations\OneGroupRule::heldClause($claim['held']));
    }

    public function removeProduct(int $store, int $group, int $product)
    {
        $this->store_($store);
        $g = VentaCartProductGroup::where('ventacart_setting_id', $store)->findOrFail($group);

        VentaCartProductGroupProduct::where('ventacart_product_group_id', $g->id)
            ->where('product_id', $product)
            ->delete();
        \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store)->clearErrors([$product]);

        return $this->productsRedirect($store, $group)
            ->with('status', 'Product removed from product group.');
    }

    public function searchProducts(Request $request, int $store)
    {
        $this->store_($store);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->input('q'));
        $categoryId = (int) $request->input('category_id', 0);
        $manufacturerId = (int) $request->input('manufacturer_id', 0);

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

        $owners = DB::table('ventacart_product_group_products as pv')
            ->join('ventacart_product_groups as g', 'g.id', '=', 'pv.ventacart_product_group_id')
            ->whereIn('pv.product_id', $rows->pluck('product_id')->all() ?: [0])
            ->where('g.ventacart_setting_id', $store)
            ->get(['pv.product_id', 'g.id as group_id', 'g.name'])
            ->keyBy('product_id');
        $rows = $rows->map(function ($r) use ($owners) {
            $own = $owners->get($r->product_id);
            $r->group_id = $own->group_id ?? null;
            $r->group_name = $own->name ?? null;

            return $r;
        });

        return response()->json(['items' => $rows->values()]);
    }

    private function getMatchingProductIds(VentaCartProductGroup $group): array
    {
        return $group->exists
            ? $group->products()->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all()
            : [];
    }

    private function getProductImages(VentaCartSetting $setting, int $productId): array
    {
        return VentaCartProductPush::for($setting)->images($productId);
    }

    private function imageUrl(string $path): string
    {
        return VentaCartProductPush::imageUrl($path);
    }
}
