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

        $productCount = count($this->getMatchingProductIds($group));

        return view('ext-tiktok::product-groups.form', compact(
            'group', 'mode', 'tiktokCategories', 'catalogCategories', 'manufacturers', 'productCount'
        ));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                   => 'required|string|max:255',
            'tiktok_category_id'     => 'required|string|exists:tiktok_categories,id',
            'catalog_category_ids'   => 'nullable|array',
            'catalog_category_ids.*' => 'integer|min:1',
            'manufacturer_ids'       => 'nullable|array',
            'manufacturer_ids.*'     => 'integer|min:1',
            'markup_percent'         => 'nullable|numeric|min:0',
            'markup_fixed'           => 'nullable|numeric|min:0',
        ]);

        $group = TikTokProductGroup::create([
            'name'                 => $data['name'],
            'tiktok_category_id'   => $data['tiktok_category_id'],
            'catalog_category_ids' => !empty($data['catalog_category_ids']) ? array_values(array_map('intval', $data['catalog_category_ids'])) : null,
            'manufacturer_ids'     => !empty($data['manufacturer_ids']) ? array_values(array_map('intval', $data['manufacturer_ids'])) : null,
            'markup_percent'       => $data['markup_percent'] ?? null,
            'markup_fixed'         => $data['markup_fixed'] ?? null,
        ]);

        ActivityLogger::log('created', 'TikTok Product Group', $group->id, $group->name);

        return redirect()->route('ext.tiktok.product-groups.edit', $group->id)
            ->with('status', 'Product group created.');
    }

    public function update(Request $request, int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);

        $data = $request->validate([
            'name'                   => 'required|string|max:255',
            'tiktok_category_id'     => 'required|string|exists:tiktok_categories,id',
            'catalog_category_ids'   => 'nullable|array',
            'catalog_category_ids.*' => 'integer|min:1',
            'manufacturer_ids'       => 'nullable|array',
            'manufacturer_ids.*'     => 'integer|min:1',
            'markup_percent'         => 'nullable|numeric|min:0',
            'markup_fixed'           => 'nullable|numeric|min:0',
        ]);

        $group->update([
            'name'                 => $data['name'],
            'tiktok_category_id'   => $data['tiktok_category_id'],
            'catalog_category_ids' => !empty($data['catalog_category_ids']) ? array_values(array_map('intval', $data['catalog_category_ids'])) : null,
            'manufacturer_ids'     => !empty($data['manufacturer_ids']) ? array_values(array_map('intval', $data['manufacturer_ids'])) : null,
            'markup_percent'       => $data['markup_percent'] ?? null,
            'markup_fixed'         => $data['markup_fixed'] ?? null,
        ]);

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

        if (empty($productIds)) {
            return view('ext-tiktok::product-groups.products', [
                'group'     => $group,
                'products'  => new \Illuminate\Pagination\LengthAwarePaginator([], 0, 50),
                'pivotMap'  => collect(),
                'manualIds' => [],
            ]);
        }

        $q = trim((string) $request->input('q'));

        $query = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->whereIn('p.product_id', $productIds)
            ->select('p.product_id', 'pd.name', 'p.model', 'p.sku', 'p.price', 'p.quantity', 'p.status', 'p.image');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%")
                    ->orWhere('p.model', 'like', "%{$q}%");
            });
        }

        $products = $query->orderBy('p.product_id', 'desc')->paginate(50)->withQueryString();

        // Pivot data for sync status display
        $pivotMap = $group->groupProducts()
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        $manualIds = $group->groupProducts()->pluck('product_id')->toArray();

        return view('ext-tiktok::product-groups.products', [
            'group'     => $group,
            'products'  => $products,
            'pivotMap'  => $pivotMap,
            'manualIds' => $manualIds,
            'q'         => $q,
        ]);
    }

    public function addProducts(Request $request, int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $ids = array_map('intval', (array) $request->input('ids', []));

        if (empty($ids)) {
            return redirect()->route('ext.tiktok.product-groups.products', $id)
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

        return redirect()->route('ext.tiktok.product-groups.products', $id)
            ->with('status', "{$added} product(s) added.");
    }

    public function removeProduct(int $id, int $product)
    {
        $group = TikTokProductGroup::findOrFail($id);

        TikTokProductGroupProduct::where('tiktok_product_group_id', $group->id)
            ->where('product_id', $product)
            ->delete();

        return redirect()->route('ext.tiktok.product-groups.products', $id)
            ->with('status', 'Product removed from group.');
    }

    public function massRemove(Request $request, int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));

        if (empty($ids)) {
            return redirect()->route('ext.tiktok.product-groups.products', $id)
                ->with('status', 'No products selected.');
        }

        $deleted = TikTokProductGroupProduct::where('tiktok_product_group_id', $group->id)
            ->whereIn('product_id', $ids)
            ->delete();

        return redirect()->route('ext.tiktok.product-groups.products', $id)
            ->with('status', $deleted . ' product(s) removed from group.');
    }

    public function deleteFromTikTok(Request $request, int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));

        if (empty($ids)) {
            return redirect()->route('ext.tiktok.product-groups.products', $id)
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
            return redirect()->route('ext.tiktok.product-groups.products', $id)
                ->with('status', 'No pushed products found among selected.');
        }

        $ttIds = $pivots->pluck('tiktok_product_id')->toArray();
        $result = $client->deleteProducts($c['app_key'], $c['app_secret'], $c['token'], $ttIds, $c['shop_cipher']);
        $this->logApi('DELETE', '/product/202309/products', $result);

        $apiCode = (int) ($result['body']['code'] ?? -1);
        $apiOk = ($result['ok'] ?? false) && $apiCode === 0;

        if ($apiOk) {
            // Clear tiktok_product_id and reset sync status
            TikTokProductGroupProduct::where('tiktok_product_group_id', $group->id)
                ->whereIn('product_id', $ids)
                ->update(['tiktok_product_id' => null, 'sync_status' => 'pending', 'push_error' => null]);

            return redirect()->route('ext.tiktok.product-groups.products', $id)
                ->with('status', count($ttIds) . ' product(s) deleted from TikTok Shop.');
        }

        $msg = $result['body']['message'] ?? 'Unknown error';
        return redirect()->route('ext.tiktok.product-groups.products', $id)
            ->with('status', 'TikTok delete failed: ' . $msg);
    }

    public function searchProducts(Request $request)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->input('q'));

        if (strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        $rows = DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->where(function ($w) use ($q) {
                $w->where('pd.name', 'like', "%{$q}%")
                    ->orWhere('p.sku', 'like', "%{$q}%")
                    ->orWhere('p.model', 'like', "%{$q}%");
            })
            ->select('p.product_id', 'pd.name', 'p.sku', 'p.price')
            ->orderBy('p.product_id', 'desc')
            ->limit(50)
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
            return redirect()->route('ext.tiktok.product-groups.products', $id)
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
        $updated = 0;
        $failed = 0;

        foreach ($pivotRows as $pivot) {
            $erp = $erpProducts->get($pivot->product_id);
            if (!$erp) {
                $pivot->update(['sync_status' => 'error', 'push_error' => 'ERP product not found']);
                $failed++;
                continue;
            }

            // Upload image
            $imageUris = [];
            $imgPath = trim((string) ($erp->image ?? ''));
            if ($imgPath !== '') {
                $fullPath = public_path('storage/' . ltrim($imgPath, '/'));
                if (file_exists($fullPath)) {
                    $imageData = $this->squareImage($fullPath) ?: file_get_contents($fullPath);
                    $imgCipher = $c['sandbox'] ? null : $c['shop_cipher'];
                    $imgResult = $client->uploadImage($c['app_key'], $c['app_secret'], $c['token'], $imageData, $imgCipher);
                    $this->logApi('POST', '/product/202309/images/upload', $imgResult);
                    if ($imgResult['ok'] ?? false) {
                        $uri = $imgResult['body']['data']['uri'] ?? null;
                        if ($uri) {
                            $imageUris[] = $uri;
                        }
                    }
                }
            }

            $price = $group->applyMarkup((float) $erp->price);
            $title = $erp->name ?: ('Product ' . $erp->product_id);
            $desc = trim(strip_tags((string) ($erp->description ?? '')));
            if (strlen($desc) < 1) {
                $desc = $title;
            }
            $isUpdate = (bool) $pivot->tiktok_product_id;

            $weight = max(0.01, (float) ($erp->weight ?? 0));

            // Pad title to meet TikTok's 25-char minimum
            if (mb_strlen($title) < 25) {
                $title = str_pad($title, 25, ' — Product from ERP');
            }

            $payload = [
                'title'            => $title,
                'description'      => $desc,
                'category_id'      => $group->tiktok_category_id,
                'category_version' => 'v2',
                'package_weight' => [
                    'unit'  => 'KILOGRAM',
                    'value' => (string) $weight,
                ],
                'skus'           => [
                    [
                        'seller_sku' => $erp->sku ?: $erp->model ?: (string) $erp->product_id,
                        'price'      => [
                            'amount'   => (string) round($price),
                            'currency' => 'PHP',
                        ],
                        'inventory'  => [
                            array_filter([
                                'warehouse_id' => $c['warehouse_id'] ?: null,
                                'quantity' => max(0, (int) $erp->quantity),
                            ]),
                        ],
                    ],
                ],
            ];

            // Add package dimensions if available
            $length = (float) ($erp->length ?? 0);
            $width  = (float) ($erp->width ?? 0);
            $height = (float) ($erp->height ?? 0);
            if ($length > 0 && $width > 0 && $height > 0) {
                $payload['package_dimensions'] = [
                    'unit'   => 'CENTIMETER',
                    'length' => (string) $length,
                    'width'  => (string) $width,
                    'height' => (string) $height,
                ];
            }

            if (!empty($imageUris)) {
                $payload['main_images'] = array_map(fn($uri) => ['uri' => $uri], $imageUris);
            } elseif (!$isUpdate) {
                // TikTok requires main_images for new products
                $pivot->update(['sync_status' => 'error', 'push_error' => 'No product image — TikTok requires at least one main image.']);
                $failed++;
                continue;
            }

            if ($isUpdate) {
                $result = $client->editProduct($c['app_key'], $c['app_secret'], $c['token'], $pivot->tiktok_product_id, $payload, $c['shop_cipher']);
                $this->logApi('PUT', '/product/202309/products/' . $pivot->tiktok_product_id, $result);
            } else {
                $result = $client->createProduct($c['app_key'], $c['app_secret'], $c['token'], $payload, $c['shop_cipher']);
                $this->logApi('POST', '/product/202309/products', $result);
            }

            $apiCode = (int) ($result['body']['code'] ?? -1);
            $apiOk = ($result['ok'] ?? false) && $apiCode === 0;

            if ($apiOk) {
                $ttProductId = $result['body']['data']['product_id'] ?? $pivot->tiktok_product_id;
                $pivot->update([
                    'tiktok_product_id' => $ttProductId,
                    'sync_status'       => 'pushed',
                    'last_pushed_at'    => now(),
                    'push_error'        => null,
                ]);
                $isUpdate ? $updated++ : $created++;
            } else {
                $errMsg = $result['body']['message'] ?? json_encode($result['body'] ?? 'Unknown error');
                $pivot->update([
                    'sync_status' => 'error',
                    'push_error'  => $errMsg,
                ]);
                $failed++;
            }
        }

        return redirect()->route('ext.tiktok.product-groups.products', $id)
            ->with('status', "Push complete — Created: {$created}, Updated: {$updated}, Failed: {$failed}");
    }

    // ── Push Prices ─────────────────────────────────────────────

    public function pushPrices(int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $c = $this->creds();
        $client = new TikTokClient();
        $pfx = (string) config('catalog.prefix');

        $pivotRows = $group->groupProducts()->whereNotNull('tiktok_product_id')->get();

        if ($pivotRows->isEmpty()) {
            return redirect()->route('ext.tiktok.product-groups.products', $id)
                ->with('status', 'No pushed products to update prices for.');
        }

        $erpPrices = DB::table($pfx . 'product')
            ->whereIn('product_id', $pivotRows->pluck('product_id'))
            ->pluck('price', 'product_id');

        $ok = 0;
        $fail = 0;

        foreach ($pivotRows as $pivot) {
            $basePrice = (float) ($erpPrices->get($pivot->product_id) ?? 0);
            $price = $group->applyMarkup($basePrice);

            // Price/stock updates need shop_cipher
            $result = $client->updatePrice(
                $c['app_key'], $c['app_secret'], $c['token'],
                $pivot->tiktok_product_id,
                [['price' => ['amount' => (string) round($price), 'currency' => 'PHP']]],
                $c['shop_cipher']
            );
            $this->logApi('POST', '/product/202309/products/' . $pivot->tiktok_product_id . '/prices/update', $result);

            ($result['ok'] ?? false) ? $ok++ : $fail++;
        }

        return redirect()->route('ext.tiktok.product-groups.products', $id)
            ->with('status', "Prices pushed — OK: {$ok}, Failed: {$fail}");
    }

    // ── Push Stock ──────────────────────────────────────────────

    public function pushStock(int $id)
    {
        $group = TikTokProductGroup::findOrFail($id);
        $c = $this->creds();
        $client = new TikTokClient();
        $pfx = (string) config('catalog.prefix');

        $pivotRows = $group->groupProducts()->whereNotNull('tiktok_product_id')->get();

        if ($pivotRows->isEmpty()) {
            return redirect()->route('ext.tiktok.product-groups.products', $id)
                ->with('status', 'No pushed products to update stock for.');
        }

        $erpQty = DB::table($pfx . 'product')
            ->whereIn('product_id', $pivotRows->pluck('product_id'))
            ->pluck('quantity', 'product_id');

        $ok = 0;
        $fail = 0;

        foreach ($pivotRows as $pivot) {
            $qty = max(0, (int) ($erpQty->get($pivot->product_id) ?? 0));

            // Price/stock updates need shop_cipher
            $result = $client->updateInventory(
                $c['app_key'], $c['app_secret'], $c['token'],
                $pivot->tiktok_product_id,
                [['inventory' => [['quantity' => $qty]]]],
                $c['shop_cipher']
            );
            $this->logApi('POST', '/product/202309/products/' . $pivot->tiktok_product_id . '/inventory/update', $result);

            ($result['ok'] ?? false) ? $ok++ : $fail++;
        }

        return redirect()->route('ext.tiktok.product-groups.products', $id)
            ->with('status', "Stock pushed — OK: {$ok}, Failed: {$fail}");
    }
}
