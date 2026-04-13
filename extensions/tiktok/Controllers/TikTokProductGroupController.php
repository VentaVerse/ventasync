<?php

namespace Extensions\tiktok\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokCategory;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TikTokProductGroupController extends Controller
{
    // ── Helpers ──────────────────────────────────────────────────

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

    private function squareImage(string $filePath): ?string
    {
        $info = @getimagesize($filePath);
        if (!$info) return null;

        [$w, $h, $type] = $info;
        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($filePath),
            IMAGETYPE_PNG  => @imagecreatefrompng($filePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) : null,
            default => null,
        };
        if (!$src) return null;

        // Already square (within 2px tolerance)
        if (abs($w - $h) <= 2) {
            $data = file_get_contents($filePath);
            imagedestroy($src);
            return $data;
        }

        $size = max($w, $h);
        $canvas = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);

        $x = (int) (($size - $w) / 2);
        $y = (int) (($size - $h) / 2);
        imagecopy($canvas, $src, $x, $y, 0, 0, $w, $h);
        imagedestroy($src);

        ob_start();
        imagejpeg($canvas, null, 90);
        $data = ob_get_clean();
        imagedestroy($canvas);

        return $data ?: null;
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

    // ── Category Sync ───────────────────────────────────────────

    public function syncCategories()
    {
        $c = $this->creds();
        $client = new TikTokClient();

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

        return view('ext-tiktok::product-groups.form', compact(
            'group', 'mode', 'tiktokCategories', 'catalogCategories', 'manufacturers', 'groupProducts'
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

        // Auto-fill: detect products already synced to TikTok via other groups
        if (!empty($productIds)) {
            $missingPivots = TikTokProductGroupProduct::query()
                ->where('tiktok_product_group_id', $group->id)
                ->whereIn('product_id', $productIds)
                ->whereNull('tiktok_product_id')
                ->where('sync_status', '!=', 'unlinked')
                ->get();

            if ($missingPivots->isNotEmpty()) {
                $missingProductIds = $missingPivots->pluck('product_id')->toArray();

                $existingTikTokIds = DB::table('tiktok_product_group_products')
                    ->where('tiktok_product_group_id', '!=', $group->id)
                    ->whereIn('product_id', $missingProductIds)
                    ->whereNotNull('tiktok_product_id')
                    ->pluck('tiktok_product_id', 'product_id');

                foreach ($missingPivots as $pivot) {
                    $ttId = $existingTikTokIds->get($pivot->product_id);
                    if ($ttId) {
                        $pivot->update([
                            'tiktok_product_id' => $ttId,
                            'sync_status' => 'synced',
                        ]);
                    }
                }
            }
        }

        $emptyPaginator = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 50);

        if (empty($productIds)) {
            return view('ext-tiktok::product-groups.products', [
                'group'                => $group,
                'products'             => $emptyPaginator,
                'pivotMap'             => collect(),
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

        // Filter by sync status
        if ($syncStatus === 'pushed') {
            $productIds = array_values(array_filter($productIds, fn($pid) => in_array($pivotMap->get($pid)->sync_status ?? '', ['pushed', 'synced'])));
        } elseif ($syncStatus === 'pending') {
            $productIds = array_values(array_filter($productIds, fn($pid) => !in_array($pivotMap->get($pid)->sync_status ?? 'pending', ['pushed', 'synced', 'unlinked'])));
        } elseif ($syncStatus === 'error') {
            $productIds = array_values(array_filter($productIds, fn($pid) => ($pivotMap->get($pid)->sync_status ?? '') === 'error'));
        }

        if (empty($productIds)) {
            return view('ext-tiktok::product-groups.products', [
                'group'                => $group,
                'products'             => $emptyPaginator,
                'pivotMap'             => $pivotMap,
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
            ->select('p.product_id', 'pd.name', 'p.model', 'p.sku', 'p.price', 'p.quantity', 'p.status', 'p.image', 'm.name as manufacturer_name');

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

        $manualIds = $group->groupProducts()->pluck('product_id')->toArray();

        // Preload option rows for display
        $pageIds = $products->pluck('product_id')->all();
        $optionRowsByProductId = collect();
        if (!empty($pageIds)) {
            $optionRows = DB::table($pfx . 'product_option_value as pov')
                ->join($pfx . 'product_option as po', 'pov.product_option_id', '=', 'po.product_option_id')
                ->leftJoin($pfx . 'option_description as od', function ($j) use ($langId) {
                    $j->on('po.option_id', '=', 'od.option_id')
                        ->where('od.language_id', '=', $langId);
                })
                ->leftJoin($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                    $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                        ->where('ovd.language_id', '=', $langId);
                })
                ->whereIn('pov.product_id', $pageIds)
                ->orderBy('pov.product_id')
                ->orderBy('pov.product_option_id')
                ->orderBy('pov.product_option_value_id')
                ->get([
                    'pov.product_id',
                    'od.name as option_name',
                    'ovd.name as option_value_name',
                    'pov.sku as option_sku',
                    'pov.quantity as option_quantity',
                    'pov.absolute_price as option_absolute_price',
                ]);

            $optionRowsByProductId = $optionRows->groupBy(fn($r) => (int) $r->product_id);
        }

        return view('ext-tiktok::product-groups.products', [
            'group'                => $group,
            'products'             => $products,
            'pivotMap'             => $pivotMap,
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

        TikTokProductGroupProduct::where('tiktok_product_group_id', $group->id)
            ->where('product_id', $product)
            ->update(['sync_status' => 'unlinked']);

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
        $client = new TikTokClient();

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
        $client = new TikTokClient();

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
            // Clear tiktok_product_id and reset sync status for successfully deleted
            TikTokProductGroupProduct::where('tiktok_product_group_id', $group->id)
                ->whereIn('product_id', $ids)
                ->update(['tiktok_product_id' => null, 'sync_status' => 'pending', 'push_error' => null]);
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

        if (strlen($q) < 2 && !$categoryId && !$manufacturerId) {
            return response()->json(['items' => []]);
        }

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

    public function push(Request $request, int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $c = $this->creds();
        $client = new TikTokClient();
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

        // Load ERP product data
        $productIds = $pivotRows->pluck('product_id')->toArray();
        $erpProducts = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->whereIn('p.product_id', $productIds)
            ->select('p.product_id', 'pd.name', 'pd.description', 'p.model', 'p.sku', 'p.price', 'p.quantity', 'p.image', 'p.weight', 'p.length', 'p.width', 'p.height')
            ->get()
            ->keyBy('product_id');

        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($pivotRows as $pivot) {
            // Skip already-linked products — use "Update on TikTok" for those
            if ($pivot->tiktok_product_id) {
                $skipped++;
                continue;
            }

            $erp = $erpProducts->get($pivot->product_id);
            if (!$erp) {
                $pivot->update(['sync_status' => 'error', 'push_error' => 'ERP product not found']);
                $failed++;
                continue;
            }

            // Upload images (main + additional from product_image table)
            $imageUris = [];
            // Image upload endpoint does NOT accept shop_cipher

            $allImages = [];
            $mainImg = trim((string) ($erp->image ?? ''));
            if ($mainImg !== '') {
                $allImages[] = $mainImg;
            }
            $additionalImages = DB::table($pfx . 'product_image')
                ->where('product_id', $erp->product_id)
                ->orderBy('sort_order')
                ->pluck('image')
                ->toArray();
            $allImages = array_merge($allImages, $additionalImages);

            // TikTok allows max 9 main images
            $allImages = array_slice($allImages, 0, 9);

            foreach ($allImages as $imgPath) {
                $imgPath = trim((string) $imgPath);
                if ($imgPath === '') continue;
                $fullPath = public_path('storage/' . ltrim($imgPath, '/'));
                if (!file_exists($fullPath)) continue;

                $imageData = $this->squareImage($fullPath) ?: file_get_contents($fullPath);
                $imgResult = $client->uploadImage($c['app_key'], $c['app_secret'], $c['token'], $imageData);
                $this->logApi('POST', '/product/202309/images/upload', $imgResult);
                if ($imgResult['ok'] ?? false) {
                    $uri = $imgResult['body']['data']['uri'] ?? null;
                    if ($uri) {
                        $imageUris[] = $uri;
                    }
                }
            }

            $price = $group->applyMarkup((float) $erp->price);
            $title = $erp->name ?: ('Product ' . $erp->product_id);
            $rawDesc = (string) ($erp->description ?? '');
            // Convert block/break tags to newlines before stripping HTML
            $rawDesc = preg_replace('/<br\s*\/?>/i', "\n", $rawDesc);
            $rawDesc = preg_replace('/<\/p>\s*/i', "\n\n", $rawDesc);
            $desc = trim(strip_tags($rawDesc));
            if (strlen($desc) < 1) {
                $desc = $title;
            }
            // TikTok description max 10,000 characters
            if (mb_strlen($desc) > 10000) {
                $desc = mb_substr($desc, 0, 10000);
            }
            // Convert kg to grams — TikTok strips decimals so 0.3 kg would become 3 kg
            $weightGrams = max(1, (int) round((float) ($erp->weight ?? 0) * 1000));

            // Pad title to meet TikTok's 25-char minimum
            if (mb_strlen($title) < 25) {
                $title = str_pad($title, 25, ' — Product from ERP');
            }

            // Build SKUs — check for product options (variants)
            $optionValues = $this->getProductOptionValues($pfx, $erp->product_id);
            $skus = [];

            if (!empty($optionValues)) {
                // Multi-variant: one SKU per option value, custom attribute name from OpenCart option
                $optionName = $optionValues[0]->option_name;
                foreach ($optionValues as $ov) {
                    $ovPrice = $this->resolveOptionPrice((float) $erp->price, $ov);
                    $ovPrice = $group->applyMarkup($ovPrice);
                    $skus[] = [
                        'seller_sku' => $ov->sku ?: ($erp->sku . '-' . $ov->option_value_id),
                        'sales_attributes' => [
                            ['name' => $optionName, 'value_name' => $ov->value_name],
                        ],
                        'price' => [
                            'amount'   => (string) round($ovPrice),
                            'currency' => 'PHP',
                        ],
                        'inventory' => [
                            array_filter([
                                'warehouse_id' => $c['warehouse_id'] ?: null,
                                'quantity' => max(0, (int) $ov->quantity),
                            ]),
                        ],
                    ];
                }
            } else {
                // Single-variant product (no options)
                $skus[] = [
                    'seller_sku' => $erp->sku ?: $erp->model ?: (string) $erp->product_id,
                    'price' => [
                        'amount'   => (string) round($price),
                        'currency' => 'PHP',
                    ],
                    'inventory' => [
                        array_filter([
                            'warehouse_id' => $c['warehouse_id'] ?: null,
                            'quantity' => max(0, (int) $erp->quantity),
                        ]),
                    ],
                ];
            }

            $payload = [
                'title'            => $title,
                'description'      => $desc,
                'category_id'      => $group->tiktok_category_id,
                'category_version' => 'v2',
                'package_weight' => [
                    'unit'  => 'GRAM',
                    'value' => (string) $weightGrams,
                ],
                'skus'             => $skus,
            ];

            // Package dimensions — integers only (TikTok strips decimals), min 1cm each
            $length = max(1, (int) round((float) ($erp->length ?? 0)));
            $width  = max(1, (int) round((float) ($erp->width ?? 0)));
            $height = max(1, (int) round((float) ($erp->height ?? 0)));
            $payload['package_dimensions'] = [
                'unit'   => 'CENTIMETER',
                'length' => (string) $length,
                'width'  => (string) $width,
                'height' => (string) $height,
            ];

            if (!empty($imageUris)) {
                $payload['main_images'] = array_map(fn($uri) => ['uri' => $uri], $imageUris);
            } else {
                // TikTok requires main_images for new products
                $pivot->update(['sync_status' => 'error', 'push_error' => 'No product image — TikTok requires at least one main image.']);
                $failed++;
                continue;
            }

            $result = $client->createProduct($c['app_key'], $c['app_secret'], $c['token'], $payload, $c['shop_cipher']);
            $this->logApi('POST', '/product/202309/products', $result);

            $apiCode = (int) ($result['body']['code'] ?? -1);
            $apiOk = ($result['ok'] ?? false) && $apiCode === 0;

            if ($apiOk) {
                $ttProductId = $result['body']['data']['product_id'] ?? null;
                $returnedSkus = $result['body']['data']['skus'] ?? [];

                // Store SKU IDs — JSON keyed by option_value_id for multi-variant, plain string for single
                if (!empty($optionValues) && count($returnedSkus) > 0) {
                    $skuMap = [];
                    foreach ($optionValues as $i => $ov) {
                        $skuMap[$ov->option_value_id] = $returnedSkus[$i]['id'] ?? null;
                    }
                    $ttSkuId = json_encode($skuMap);
                } else {
                    $ttSkuId = $returnedSkus[0]['id'] ?? null;
                }

                $pivot->update([
                    'tiktok_product_id' => $ttProductId,
                    'tiktok_sku_id'     => $ttSkuId,
                    'sync_status'       => 'pushed',
                    'last_pushed_at'    => now(),
                    'push_error'        => null,
                ]);
                $created++;
            } else {
                $errMsg = $result['body']['message'] ?? json_encode($result['body'] ?? 'Unknown error');
                $pivot->update([
                    'sync_status' => 'error',
                    'push_error'  => $errMsg,
                ]);
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
        $client = new TikTokClient();
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

        $productIds = $pivotRows->pluck('product_id')->toArray();
        $erpProducts = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->whereIn('p.product_id', $productIds)
            ->select('p.product_id', 'pd.name', 'pd.description', 'p.model', 'p.sku', 'p.price', 'p.quantity', 'p.image', 'p.weight', 'p.length', 'p.width', 'p.height')
            ->get()
            ->keyBy('product_id');

        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($pivotRows as $pivot) {
            // Only process products that are already linked
            if (!$pivot->tiktok_product_id) {
                $skipped++;
                continue;
            }

            $erp = $erpProducts->get($pivot->product_id);
            if (!$erp) {
                $pivot->update(['sync_status' => 'error', 'push_error' => 'ERP product not found']);
                $failed++;
                continue;
            }

            // Upload images
            $imageUris = [];
            $allImages = [];
            $mainImg = trim((string) ($erp->image ?? ''));
            if ($mainImg !== '') {
                $allImages[] = $mainImg;
            }
            $additionalImages = DB::table($pfx . 'product_image')
                ->where('product_id', $erp->product_id)
                ->orderBy('sort_order')
                ->pluck('image')
                ->toArray();
            $allImages = array_merge($allImages, $additionalImages);
            $allImages = array_slice($allImages, 0, 9);

            foreach ($allImages as $imgPath) {
                $imgPath = trim((string) $imgPath);
                if ($imgPath === '') continue;
                $fullPath = public_path('storage/' . ltrim($imgPath, '/'));
                if (!file_exists($fullPath)) continue;

                $imageData = $this->squareImage($fullPath) ?: file_get_contents($fullPath);
                $imgResult = $client->uploadImage($c['app_key'], $c['app_secret'], $c['token'], $imageData);
                $this->logApi('POST', '/product/202309/images/upload', $imgResult);
                if ($imgResult['ok'] ?? false) {
                    $uri = $imgResult['body']['data']['uri'] ?? null;
                    if ($uri) {
                        $imageUris[] = $uri;
                    }
                }
            }

            $price = $group->applyMarkup((float) $erp->price);
            $title = $erp->name ?: ('Product ' . $erp->product_id);
            $rawDesc = (string) ($erp->description ?? '');
            $rawDesc = preg_replace('/<br\s*\/?>/i', "\n", $rawDesc);
            $rawDesc = preg_replace('/<\/p>\s*/i', "\n\n", $rawDesc);
            $desc = trim(strip_tags($rawDesc));
            if (strlen($desc) < 1) {
                $desc = $title;
            }
            if (mb_strlen($desc) > 10000) {
                $desc = mb_substr($desc, 0, 10000);
            }

            $weightGrams = max(1, (int) round((float) ($erp->weight ?? 0) * 1000));

            if (mb_strlen($title) < 25) {
                $title = str_pad($title, 25, ' — Product from ERP');
            }

            // Build SKUs with TikTok SKU IDs injected for update
            $optionValues = $this->getProductOptionValues($pfx, $erp->product_id);
            $skus = [];

            // Parse existing SKU IDs from pivot
            $existingSkuIds = [];
            if ($pivot->tiktok_sku_id) {
                $decoded = json_decode($pivot->tiktok_sku_id, true);
                if (is_array($decoded)) {
                    $existingSkuIds = $decoded; // keyed by option_value_id
                }
            }

            if (!empty($optionValues)) {
                $optionName = $optionValues[0]->option_name;
                foreach ($optionValues as $ov) {
                    $ovPrice = $this->resolveOptionPrice((float) $erp->price, $ov);
                    $ovPrice = $group->applyMarkup($ovPrice);
                    $sku = [
                        'seller_sku' => $ov->sku ?: ($erp->sku . '-' . $ov->option_value_id),
                        'sales_attributes' => [
                            ['name' => $optionName, 'value_name' => $ov->value_name],
                        ],
                        'price' => [
                            'amount'   => (string) round($ovPrice),
                            'currency' => 'PHP',
                        ],
                        'inventory' => [
                            array_filter([
                                'warehouse_id' => $c['warehouse_id'] ?: null,
                                'quantity' => max(0, (int) $ov->quantity),
                            ]),
                        ],
                    ];
                    // Inject TikTok SKU ID for update
                    if (isset($existingSkuIds[$ov->option_value_id])) {
                        $sku['id'] = (string) $existingSkuIds[$ov->option_value_id];
                    }
                    $skus[] = $sku;
                }
            } else {
                $sku = [
                    'seller_sku' => $erp->sku ?: $erp->model ?: (string) $erp->product_id,
                    'price' => [
                        'amount'   => (string) round($price),
                        'currency' => 'PHP',
                    ],
                    'inventory' => [
                        array_filter([
                            'warehouse_id' => $c['warehouse_id'] ?: null,
                            'quantity' => max(0, (int) $erp->quantity),
                        ]),
                    ],
                ];
                // Inject TikTok SKU ID for single-variant update
                if ($pivot->tiktok_sku_id && !is_array(json_decode($pivot->tiktok_sku_id, true))) {
                    $sku['id'] = (string) $pivot->tiktok_sku_id;
                }
                $skus[] = $sku;
            }

            $payload = [
                'title'            => $title,
                'description'      => $desc,
                'category_id'      => $group->tiktok_category_id,
                'category_version' => 'v2',
                'package_weight' => [
                    'unit'  => 'GRAM',
                    'value' => (string) $weightGrams,
                ],
                'skus'             => $skus,
            ];

            $length = max(1, (int) round((float) ($erp->length ?? 0)));
            $width  = max(1, (int) round((float) ($erp->width ?? 0)));
            $height = max(1, (int) round((float) ($erp->height ?? 0)));
            $payload['package_dimensions'] = [
                'unit'   => 'CENTIMETER',
                'length' => (string) $length,
                'width'  => (string) $width,
                'height' => (string) $height,
            ];

            if (!empty($imageUris)) {
                $payload['main_images'] = array_map(fn($uri) => ['uri' => $uri], $imageUris);
            }

            $result = $client->editProduct($c['app_key'], $c['app_secret'], $c['token'], $pivot->tiktok_product_id, $payload, $c['shop_cipher']);
            $this->logApi('PUT', '/product/202309/products/' . $pivot->tiktok_product_id, $result);

            $apiCode = (int) ($result['body']['code'] ?? -1);
            $apiOk = ($result['ok'] ?? false) && $apiCode === 0;

            if ($apiOk) {
                $pivot->update([
                    'sync_status'    => 'pushed',
                    'last_pushed_at' => now(),
                    'push_error'     => null,
                ]);
                $updated++;
            } else {
                $errMsg = $result['body']['message'] ?? json_encode($result['body'] ?? 'Unknown error');
                $pivot->update([
                    'sync_status' => 'error',
                    'push_error'  => $errMsg,
                ]);
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
        $group = TikTokProductGroup::findOrFail($id);
        $c = $this->creds();
        $client = new TikTokClient();
        $pfx = (string) config('catalog.prefix');

        $query = $group->groupProducts()->whereNotNull('tiktok_product_id');
        $specificIds = $request->input('ids');
        if (!empty($specificIds)) {
            $query->whereIn('product_id', array_map('intval', (array) $specificIds));
        }
        $pivotRows = $query->get();

        if ($pivotRows->isEmpty()) {
            return $this->productsRedirect($id)
                ->with('status', 'No pushed products to update prices for.');
        }

        $erpPrices = DB::table($pfx . 'product')
            ->whereIn('product_id', $pivotRows->pluck('product_id'))
            ->pluck('price', 'product_id');

        $ok = 0;
        $fail = 0;

        foreach ($pivotRows as $pivot) {
            $basePrice = (float) ($erpPrices->get($pivot->product_id) ?? 0);

            // Check if multi-variant (JSON SKU IDs)
            $skuIdRaw = $pivot->tiktok_sku_id;
            $skuMap = $this->parseSkuIds($skuIdRaw);

            if ($skuMap === null) {
                // Single-variant — fetch SKU ID from API if needed
                if (!$skuIdRaw) {
                    $detail = $client->getProduct($c['app_key'], $c['app_secret'], $c['token'], $pivot->tiktok_product_id, $c['shop_cipher']);
                    $skuIdRaw = $detail['body']['data']['skus'][0]['id'] ?? null;
                    if ($skuIdRaw) {
                        $pivot->update(['tiktok_sku_id' => $skuIdRaw]);
                    }
                }
                if (!$skuIdRaw) { $fail++; continue; }

                $price = $group->applyMarkup($basePrice);
                $skuPayload = [['id' => $skuIdRaw, 'price' => ['amount' => (string) round($price), 'currency' => 'PHP']]];
            } else {
                // Multi-variant — build price update for each option value
                $optionValues = collect($this->getProductOptionValues($pfx, $pivot->product_id))->keyBy('option_value_id');
                $skuPayload = [];
                foreach ($skuMap as $ovId => $ttSkuId) {
                    if (!$ttSkuId) continue;
                    $ov = $optionValues->get($ovId);
                    $ovPrice = $ov ? $group->applyMarkup($this->resolveOptionPrice($basePrice, $ov)) : $group->applyMarkup($basePrice);
                    $skuPayload[] = ['id' => $ttSkuId, 'price' => ['amount' => (string) round($ovPrice), 'currency' => 'PHP']];
                }
                if (empty($skuPayload)) { $fail++; continue; }
            }

            $result = $client->updatePrice(
                $c['app_key'], $c['app_secret'], $c['token'],
                $pivot->tiktok_product_id,
                $skuPayload,
                $c['shop_cipher']
            );
            $this->logApi('POST', '/product/202309/products/' . $pivot->tiktok_product_id . '/prices/update', $result);

            $apiOk = ($result['ok'] ?? false) && (int) ($result['body']['code'] ?? -1) === 0;
            $apiOk ? $ok++ : $fail++;
        }

        return $this->productsRedirect($id)
            ->with('status', "Prices pushed — OK: {$ok}, Failed: {$fail}");
    }

    // ── Push Stock ──────────────────────────────────────────────

    public function pushStock(Request $request, int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $c = $this->creds();
        $client = new TikTokClient();
        $pfx = (string) config('catalog.prefix');

        $query = $group->groupProducts()->whereNotNull('tiktok_product_id');
        $specificIds = $request->input('ids');
        if (!empty($specificIds)) {
            $query->whereIn('product_id', array_map('intval', (array) $specificIds));
        }
        $pivotRows = $query->get();

        if ($pivotRows->isEmpty()) {
            return $this->productsRedirect($id)
                ->with('status', 'No pushed products to update stock for.');
        }

        $erpQty = DB::table($pfx . 'product')
            ->whereIn('product_id', $pivotRows->pluck('product_id'))
            ->pluck('quantity', 'product_id');

        $ok = 0;
        $fail = 0;

        foreach ($pivotRows as $pivot) {
            // Check if multi-variant (JSON SKU IDs)
            $skuIdRaw = $pivot->tiktok_sku_id;
            $skuMap = $this->parseSkuIds($skuIdRaw);

            if ($skuMap === null) {
                // Single-variant
                if (!$skuIdRaw) {
                    $detail = $client->getProduct($c['app_key'], $c['app_secret'], $c['token'], $pivot->tiktok_product_id, $c['shop_cipher']);
                    $skuIdRaw = $detail['body']['data']['skus'][0]['id'] ?? null;
                    if ($skuIdRaw) {
                        $pivot->update(['tiktok_sku_id' => $skuIdRaw]);
                    }
                }
                if (!$skuIdRaw) { $fail++; continue; }

                $qty = max(0, (int) ($erpQty->get($pivot->product_id) ?? 0));
                $inv = ['quantity' => $qty];
                if ($c['warehouse_id']) $inv['warehouse_id'] = $c['warehouse_id'];
                $skuPayload = [['id' => $skuIdRaw, 'inventory' => [$inv]]];
            } else {
                // Multi-variant — build inventory for each option value
                $optionValues = collect($this->getProductOptionValues($pfx, $pivot->product_id))->keyBy('option_value_id');
                $skuPayload = [];
                foreach ($skuMap as $ovId => $ttSkuId) {
                    if (!$ttSkuId) continue;
                    $ov = $optionValues->get($ovId);
                    $qty = $ov ? max(0, (int) $ov->quantity) : 0;
                    $inv = ['quantity' => $qty];
                    if ($c['warehouse_id']) $inv['warehouse_id'] = $c['warehouse_id'];
                    $skuPayload[] = ['id' => $ttSkuId, 'inventory' => [$inv]];
                }
                if (empty($skuPayload)) { $fail++; continue; }
            }

            $result = $client->updateInventory(
                $c['app_key'], $c['app_secret'], $c['token'],
                $pivot->tiktok_product_id,
                $skuPayload,
                $c['shop_cipher']
            );
            $this->logApi('POST', '/product/202309/products/' . $pivot->tiktok_product_id . '/inventory/update', $result);

            $apiOk = ($result['ok'] ?? false) && (int) ($result['body']['code'] ?? -1) === 0;
            $apiOk ? $ok++ : $fail++;
        }

        $c['setting']->update(['last_stock_push_at' => now()]);

        return $this->productsRedirect($id)
            ->with('status', "Stock pushed — OK: {$ok}, Failed: {$fail}");
    }

    // ── Variant Helpers ──────────────────────────────────────────

    /**
     * Get product option values for variant-type options (select, radio).
     * Returns option values with their names, SKUs, quantities, and prices.
     */
    private function getProductOptionValues(string $pfx, int $productId): array
    {
        return DB::table($pfx . 'product_option_value as pov')
            ->join($pfx . 'product_option as po', 'po.product_option_id', '=', 'pov.product_option_id')
            ->join($pfx . 'option as o', 'o.option_id', '=', 'po.option_id')
            ->join($pfx . 'option_description as od', function ($j) {
                $j->on('od.option_id', '=', 'o.option_id')
                    ->where('od.language_id', '=', 1);
            })
            ->join($pfx . 'option_value_description as ovd', function ($j) {
                $j->on('ovd.option_value_id', '=', 'pov.option_value_id')
                    ->where('ovd.language_id', '=', 1);
            })
            ->where('pov.product_id', $productId)
            ->whereIn('o.type', ['select', 'radio'])
            ->orderBy('po.product_option_id')
            ->orderBy('pov.product_option_value_id')
            ->select(
                'pov.product_option_value_id',
                'pov.option_value_id',
                'pov.sku',
                'pov.quantity',
                'pov.price',
                'pov.price_prefix',
                'pov.absolute_price',
                'ovd.name as value_name',
                'od.name as option_name'
            )
            ->get()
            ->toArray();
    }

    /**
     * Resolve the effective price for an option value.
     */
    private function resolveOptionPrice(float $basePrice, object $ov): float
    {
        $absPrice = (float) ($ov->absolute_price ?? 0);
        if ($absPrice > 0) {
            return $absPrice;
        }

        $modifier = (float) ($ov->price ?? 0);
        if ($ov->price_prefix === '-') {
            return max(0, $basePrice - $modifier);
        }
        return $basePrice + $modifier;
    }

    /**
     * Parse tiktok_sku_id value. Returns assoc array (option_value_id => sku_id)
     * for multi-variant products, or null for single-variant.
     */
    private function parseSkuIds(?string $skuIdRaw): ?array
    {
        if (!$skuIdRaw || $skuIdRaw[0] !== '{') {
            return null;
        }
        $decoded = json_decode($skuIdRaw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
