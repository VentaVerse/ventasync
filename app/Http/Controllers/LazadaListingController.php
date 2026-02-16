<?php

namespace App\Http\Controllers;

use App\Models\LazadaCategoryTemplate;
use App\Models\LazadaCategory;
use App\Models\LazadaBrand;
use App\Models\LazadaApiLog;
use App\Models\LazadaImageLink;
use App\Models\LazadaListing;
use App\Models\LazadaListingAttribute;
use App\Models\LazadaListingVariant;
use App\Models\LazadaSetting;
use App\Services\Lazada\LazadaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LazadaListingController extends Controller
{
    /**
     * Lazada attributes that must always come from the ERP product core fields.
     * Users should edit these in the core Product Edit page, not in Lazada Listing mapping.
     */
    private function isLockedProductAttributeKey(string $key): bool
    {
        $k = strtolower(trim($key));
        if ($k === '') return false;

        // Common Lazada PH keys for product core information / physical data.
        $locked = [
            'name',
            'model',
            'description',
            'short_description',
            'package_weight',
            'package_length',
            'package_width',
            'package_height',
        ];
        return in_array($k, $locked, true);
    }

    /**
     * Apply ERP-sourced values to locked Lazada attributes.
     */
    private function applyLockedAttributeValues(array $templateAttrs, ?object $productRow, array $attrs): array
    {
        if (!$productRow) {
            return $attrs;
        }

        $suggested = $this->suggestForAttributes($templateAttrs, $productRow);

        foreach ($templateAttrs as $a) {
            $key = (string)($a['key'] ?? '');
            if ($key === '') continue;

            if ($this->isLockedProductAttributeKey($key) && array_key_exists($key, $suggested)) {
                $attrs[$key] = $suggested[$key];
            }
        }

        return $attrs;
    }

    private function isSkuLevelRequiredKey(string $key): bool
    {
        $k = strtolower(trim($key));
        return in_array($k, ['sellersku', 'seller_sku', 'price', 'quantity'], true);
    }

    private function computeVariantPrice(float $basePrice, float $delta, ?string $prefix): float
    {
        $prefix = trim((string)$prefix);
        if ($prefix === '-') {
            return $basePrice - $delta;
        }
        if ($prefix === '=') {
            return $delta;
        }
        // Default '+'
        return $basePrice + $delta;
    }

    /**
     * Convert an ERP product image path into an absolute URL that Lazada can fetch.
     *
     * - If value is already an absolute URL, keep it.
     * - Otherwise, assume the stored path is already relative to the public web root
     *   (e.g. "catalog/...").
     */
    private function toPublicImageUrl(?string $imagePath): ?string
    {
        $imagePath = trim((string)($imagePath ?? ''));
        if ($imagePath === '') {
            return null;
        }

        // Basic safety: never generate URLs that include path traversal.
        if (str_contains($imagePath, '..')) {
            return null;
        }

        // If the DB already stores a full URL, keep it — except for localhost/loopback URLs.
        // Some imports (or older code) may have saved http://localhost/... which will break Lazada.
        // In that case, rewrite the host to the configured APP_URL host (or request host) while preserving the path.
        if (Str::startsWith($imagePath, ['http://', 'https://'])) {
            $host = strtolower((string) parse_url($imagePath, PHP_URL_HOST));
            if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
                $base = $this->imageBaseUrl();
                $path = (string) parse_url($imagePath, PHP_URL_PATH);
                $path = ltrim($path, '/');

                if ($path === '' || str_contains($path, '..')) {
                    return null;
                }

                return $base . '/' . $path;
            }

            return $imagePath;
        }

        $base = $this->imageBaseUrl();
        $path = ltrim($imagePath, '/');
        // Most ERP/OpenCart-style schemas store paths like "catalog/...". Those are typically
        // served directly by the web server from the public directory.
        return $base . '/' . $path;
    }

    /**
     * Returns the base URL used for building publicly reachable image URLs.
     *
     * We prefer config('app.url') (APP_URL), but if that is missing or somehow set to localhost,
     * we fall back to the current HTTP host.
     */
    private function imageBaseUrl(): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $host = strtolower((string) (parse_url($base, PHP_URL_HOST) ?? ''));

        if ($base === '' || $host === '' || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
            try {
                $base = rtrim(request()->getSchemeAndHttpHost(), '/');
            } catch (\Throwable $e) {
                // Ignore and keep whatever we have.
            }
        }

        return $base;
    }

    /**
     * Build a unique ordered list of product images (main + additional) as public URLs.
     */
    
    /**
     * Normalize an image URL/path into a publicly reachable URL based on APP_URL.
     * This is a last-resort guard to prevent localhost/loopback URLs from leaking into payloads.
     */
    private function normalizeImageUrl(string $urlOrPath): ?string
    {
        $urlOrPath = trim($urlOrPath);
        if ($urlOrPath === '') return null;

        // If it's a full URL, rewrite localhost/loopback hosts to APP_URL host.
        if (Str::startsWith($urlOrPath, ['http://', 'https://'])) {
            $host = strtolower((string) parse_url($urlOrPath, PHP_URL_HOST));
            if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
                $base = $this->imageBaseUrl();
                $path = (string) parse_url($urlOrPath, PHP_URL_PATH);
                $path = ltrim($path, '/');
                if ($path === '' || str_contains($path, '..')) return null;
                return $base . '/' . $path;
            }
            return $urlOrPath;
        }

        // Relative path
        return $this->toPublicImageUrl($urlOrPath);
    }

private function getProductImageUrls(int $productId, ?object $productRow): array
    {
        $pfx = (string) config('catalog.prefix');

        $images = [];
        $main = $this->normalizeImageUrl((string)($productRow?->image ?? ''));
        if ($main) {
            $images[] = $main;
        }

        $additional = DB::table($pfx.'product_image')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->pluck('image')
            ->toArray();

        foreach ($additional as $img) {
            $url = $this->normalizeImageUrl((string) $img);
            if ($url && !in_array($url, $images, true)) {
                $images[] = $url;
            }
        }

        return $images;
    }
    /**
     * Suggest Lazada attribute values based on existing ERP product fields.
     * This is a convenience for Phase 2 mapping UI (no publishing yet).
     */
    private function suggestForAttributes(array $attributes, ?object $productRow): array
    {
        if (!$productRow) {
            return [];
        }

        $out = [];

        $weight = $productRow->weight ?? null;
        $length = $productRow->length ?? null;
        $width  = $productRow->width ?? null;
        $height = $productRow->height ?? null;

        foreach ($attributes as $a) {
            $key = strtolower((string)($a['key'] ?? ''));
            $name = strtolower((string)($a['name'] ?? ''));
            $hay = $key.' '.$name;

            // Common Lazada PH physical fields.
            if (($weight !== null) && (str_contains($hay, 'weight') || str_contains($hay, 'package_weight'))) {
                $out[(string)$a['key']] = (string)$weight;
                continue;
            }
            if (($length !== null) && (str_contains($hay, 'length') || str_contains($hay, 'package_length'))) {
                $out[(string)$a['key']] = (string)$length;
                continue;
            }
            if (($width !== null) && (str_contains($hay, 'width') || str_contains($hay, 'package_width'))) {
                $out[(string)$a['key']] = (string)$width;
                continue;
            }
            if (($height !== null) && (str_contains($hay, 'height') || str_contains($hay, 'package_height'))) {
                $out[(string)$a['key']] = (string)$height;
                continue;
            }

            // Fallback suggestions (only if present in product row)
            if (str_contains($hay, 'model') && !empty($productRow->model)) {
                $out[(string)$a['key']] = (string)$productRow->model;
                continue;
            }
        }

        return $out;
    }

    private function normalizeName(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/i', ' ', $s) ?? $s;
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
        return $s;
    }

    private function suggestBrandFromManufacturer(?string $manufacturerName): array
    {
        $manufacturerName = trim((string)$manufacturerName);
        if ($manufacturerName === '') {
            return ['brand_id' => null, 'brand_name' => null];
        }

        $region = $this->region();
        $needle = $this->normalizeName($manufacturerName);

        // Exact normalized match first
        $all = LazadaBrand::query()
            ->where('region', $region)
            ->select(['brand_id', 'name'])
            ->get();

        $best = null;
        foreach ($all as $b) {
            $bn = $this->normalizeName((string)$b->name);
            if ($bn === $needle) {
                $best = $b;
                break;
            }
        }

        // Loose contains match (manufacturer contains brand or vice versa)
        if (!$best) {
            foreach ($all as $b) {
                $bn = $this->normalizeName((string)$b->name);
                if ($bn === '') continue;
                if (str_contains($needle, $bn) || str_contains($bn, $needle)) {
                    $best = $b;
                    break;
                }
            }
        }

        if ($best) {
            return ['brand_id' => (int)$best->brand_id, 'brand_name' => (string)$best->name];
        }

        // Common fallback: "No Brand"
        foreach ($all as $b) {
            if ($this->normalizeName((string)$b->name) === 'no brand') {
                return ['brand_id' => (int)$b->brand_id, 'brand_name' => (string)$b->name];
            }
        }

        return ['brand_id' => null, 'brand_name' => null];
    }
    public function index()
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $listings = LazadaListing::query()
            ->orderByDesc('id')
            ->get();

        // Attach a small amount of product info for display
        $productIds = $listings->pluck('product_id')->filter()->unique()->values()->all();
        $productsById = collect();
        if (!empty($productIds)) {
            $rows = DB::table($pfx.'product as p')
                ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                    $j->on('p.product_id', '=', 'pd.product_id')
                        ->where('pd.language_id', '=', $langId);
                })
                ->whereIn('p.product_id', $productIds)
                ->get(['p.product_id', 'p.sku', 'p.model', 'pd.name']);

            $productsById = $rows->keyBy('product_id');
        }

        return view('lazada.listings.index', [
            'listings' => $listings,
            'productsById' => $productsById,
        ]);
    }

    public function create(Request $request)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $products = DB::table($pfx.'product as p')
            ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->orderByDesc('p.product_id')
            ->limit(200)
            ->get(['p.product_id', 'p.sku', 'p.model', 'pd.name']);

        // Cached Lazada categories for dropdown selection
        $categories = LazadaCategory::query()
            ->orderBy('name')
            ->limit(5000)
            ->get(['category_id', 'name']);

        // Brand search is handled by AJAX autocomplete; no need to preload all brands.

        return view('lazada.listings.form', [
            'mode' => 'create',
            'listing' => new LazadaListing([
                'product_id' => $request->input('product_id'),
            ]),
            'products' => $products,
            'categories' => $categories,
            'template' => null,
            'attributes' => [],
            'saved' => [],
            'suggested' => [],
            'brands' => collect(),
            'brandSuggestion' => ['brand_id' => null, 'brand_name' => null],
            'selectedBrandName' => '',
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|min:1',
            'primary_category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'no_brand' => 'nullable|boolean',
        ]);

        $noBrand = (bool)($data['no_brand'] ?? false);
        if ($noBrand) {
            $data['brand_id'] = null;
        }

        $listing = LazadaListing::query()->create([
            'product_id' => (int)$data['product_id'],
            'primary_category_id' => $data['primary_category_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            // We don't allow arbitrary brand override. We only keep a marker for "No Brand".
            'brand_name_override' => $noBrand ? 'No Brand' : null,
        ]);

        return redirect()->route('lazada.listings.edit', $listing->id);
    }

    public function edit(int $id)
    {
        $listing = LazadaListing::query()->findOrFail($id);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $products = DB::table($pfx.'product as p')
            ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->orderByDesc('p.product_id')
            ->limit(200)
            ->get(['p.product_id', 'p.sku', 'p.model', 'pd.name']);

        // Cached Lazada categories for dropdown selection
        $categories = LazadaCategory::query()
            ->orderBy('name')
            ->limit(5000)
            ->get(['category_id', 'name']);

        $template = null;
        $attributes = [];
        $selectedBrandName = '';
        if (!empty($listing->brand_id)) {
            $b = LazadaBrand::query()
                ->where('region', $this->region())
                ->where('brand_id', (int)$listing->brand_id)
                ->first();
            $selectedBrandName = $b ? (string)$b->name : '';
        }
        if ($listing->primary_category_id) {
            $template = LazadaCategoryTemplate::query()
                ->where('region', $this->region())
                ->where('primary_category_id', (int)$listing->primary_category_id)
                ->first();

            if ($template && $template->template_body) {
                $attributes = $this->extractAttributes($template->template_body);
                // Brand is handled by the top-level Brand selector (listing.brand_id); do not render as a mappable attribute.
                $attributes = array_values(array_filter($attributes, function ($a) {
                    $k = strtolower(trim((string)($a['key'] ?? '')));
                    return $k !== 'brand';
                }));
            }
        }

        $saved = LazadaListingAttribute::query()
            ->where('lazada_listing_id', $listing->id)
            ->pluck('value', 'attribute_key')
            ->toArray();

        // Variants: use OpenCart-style product_option_value rows
        $variants = DB::table($pfx.'product_option_value as pov')
            ->leftJoin($pfx.'option_description as od', function ($j) use ($langId) {
                $j->on('pov.option_id', '=', 'od.option_id')
                    ->where('od.language_id', '=', $langId);
            })
            ->leftJoin($pfx.'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                    ->where('ovd.language_id', '=', $langId);
            })
            ->where('pov.product_id', (int)$listing->product_id)
            ->orderBy('pov.product_option_value_id')
            ->get([
                'pov.product_option_value_id',
                'pov.option_id',
                'pov.option_value_id',
                'pov.sku',
                'pov.quantity',
                'pov.price',
                'pov.price_prefix',
                'od.name as option_name',
                'ovd.name as option_value_name',
            ]);

        $variantMap = LazadaListingVariant::query()
            ->where('lazada_listing_id', $listing->id)
            ->get()
            ->keyBy(function ($v) {
                return $v->product_option_value_id === null ? 'base' : (string)$v->product_option_value_id;
            });

        // Base product info for payload preview
        $productRow = DB::table($pfx.'product as p')
            ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx.'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->where('p.product_id', (int)$listing->product_id)
            ->first(['p.product_id','p.sku','p.model','p.image','p.price','p.quantity','p.weight','p.length','p.width','p.height','pd.name','pd.description','m.name as manufacturer_name']);

        $suggested = $this->suggestForAttributes($attributes, $productRow);

        // Keys that must be sourced from core product fields (not editable here).
        $lockedKeys = [];
        foreach ($attributes as $a) {
            $k = (string)($a['key'] ?? '');
            if ($k !== '' && $this->isLockedProductAttributeKey($k) && array_key_exists($k, $suggested)) {
                $lockedKeys[$k] = true;
            }
        }

        // Brand suggestions
        $brandSuggestion = $this->suggestBrandFromManufacturer($productRow->manufacturer_name ?? null);
        if (!$listing->brand_id && $brandSuggestion['brand_id']) {
            // Do not auto-save; just suggest in UI
        }

        return view('lazada.listings.form', [
            'mode' => 'edit',
            'listing' => $listing,
            'products' => $products,
            'categories' => $categories,
            'template' => $template,
            'attributes' => $attributes,
            'saved' => $saved,
            'variants' => $variants,
            'variantMap' => $variantMap,
            'productRow' => $productRow,
            'suggested' => $suggested,
            'lockedKeys' => $lockedKeys,
            'brands' => collect(),
            'brandSuggestion' => $brandSuggestion,
            'selectedBrandName' => $selectedBrandName,
            'payloadPreview' => session('payload_preview_json'),
            'pushRequest' => session('push_request_json'),
            'pushResponse' => session('push_response_json'),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $listing = LazadaListing::query()->findOrFail($id);

        $data = $request->validate([
            'product_id' => 'required|integer|min:1',
            'primary_category_id' => 'nullable|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'no_brand' => 'nullable|boolean',
        ]);

        $noBrand = (bool)($data['no_brand'] ?? false);
        if ($noBrand) {
            $data['brand_id'] = null;
        }

        $listing->product_id = (int)$data['product_id'];
        $listing->primary_category_id = $data['primary_category_id'] ?? null;
        $listing->brand_id = $data['brand_id'] ?? null;
        // Marker only (no custom override allowed)
        $listing->brand_name_override = $noBrand ? 'No Brand' : null;
        $listing->save();

        return redirect()->route('lazada.listings.edit', $listing->id)->with('status', 'Listing updated.');
    }

    public function syncBrands(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaListing::query()->findOrFail($id);

        $req = $request->validate([
            'max_pages' => 'nullable|integer|min:1|max:200',
            'page_size' => 'nullable|integer|min:1|max:200',
            // Backward compatible with older UI/explorer inputs
            'page_no' => 'nullable|integer|min:1|max:100000',
            'startRow' => 'nullable|integer|min:0|max:100000000',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ]);

        $maxPages = (int)($req['max_pages'] ?? 20);
        // Prefer Lazada-native key if supplied
        $pageSize = (int)($req['pageSize'] ?? ($req['page_size'] ?? 200));

        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('lazada.listings.edit', $listing->id)
                ->with('status', 'Missing Lazada settings (region/app key/app secret).');
        }

        $apiPath = '/category/brands/query';
        $region = (string)$setting->region;
        $totalSaved = 0;

        // Lazada Open Platform GetBrandByPages requires startRow + pageSize (NOT page_no/page_size)
        // startRow is zero-based row offset.
        $explicitStartRow = isset($req['startRow']) ? (int)$req['startRow'] : null;
        $explicitPageNo = isset($req['page_no']) ? (int)$req['page_no'] : null;

        // If a startRow was explicitly provided, we will fetch starting from there (single page unless max_pages > 1).
        // Otherwise we page from 0 using max_pages.
        $startPageNo = $explicitPageNo && $explicitPageNo > 0 ? $explicitPageNo : 1;
        for ($pageNo = $startPageNo; $pageNo <= $maxPages; $pageNo++) {
            $timestamp = (string)round(microtime(true) * 1000);
            $startRow = $explicitStartRow !== null
                ? (int)($explicitStartRow + (($pageNo - $startPageNo) * $pageSize))
                : (int)(($pageNo - 1) * $pageSize);
            $params = [
                'app_key' => (string)$setting->app_key,
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'startRow' => (string)$startRow,
                'pageSize' => (string)$pageSize,
                // Optional: if you want localized brand names, add languageCode (e.g. en_PH)
                // 'languageCode' => 'en_PH',
            ];
            $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);
            $result = $client->get($region, $apiPath, $params);

			LazadaApiLog::safeCreate([
                'pack' => 'lazada.brands.query',
                'method' => 'GET',
                'api_path' => $apiPath,
                'auth_required' => false,
                'request_params' => $params,
                'response_status' => (int)($result['status'] ?? 0),
                'ok' => (bool)($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
			]);

			if (!(bool)($result['ok'] ?? false)) {
                break;
            }

            $brands = $this->extractBrandRows($result['body'] ?? null);
            if (empty($brands)) {
                break;
            }

            DB::transaction(function () use ($brands, $region, &$totalSaved) {
                foreach ($brands as $b) {
                    $brandId = (int)($b['brand_id'] ?? $b['id'] ?? 0);
                    $name = (string)($b['name'] ?? $b['brand_name'] ?? '');
                    $name = trim($name);
                    if ($brandId <= 0 || $name === '') {
                        continue;
                    }

                    LazadaBrand::query()->updateOrCreate(
                        ['region' => $region, 'brand_id' => $brandId],
                        ['name' => $name, 'raw' => is_array($b) ? $b : null]
                    );
                    $totalSaved++;
                }
            });
        }

        return redirect()->route('lazada.listings.edit', $listing->id)
            ->with('status', 'Brands synced. Saved/updated: ' . $totalSaved);
    }

    /**
     * Sync Lazada brands without requiring a listing record (useful on Create Listing).
     * This keeps brand cache up to date for dropdown usage.
     */
    public function syncBrandsGlobal(Request $request, LazadaClient $client)
    {
        $req = $request->validate([
            'max_pages' => 'nullable|integer|min:1|max:200',
            'page_size' => 'nullable|integer|min:1|max:200',
            // Backward compatible with older UI/explorer inputs
            'page_no' => 'nullable|integer|min:1|max:100000',
            'startRow' => 'nullable|integer|min:0|max:100000000',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ]);

        $maxPages = (int)($req['max_pages'] ?? 20);
        $pageSize = (int)($req['pageSize'] ?? ($req['page_size'] ?? 200));

        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return back()->with('status', 'Missing Lazada settings (region/app key/app secret).');
        }

        $apiPath = '/category/brands/query';
        $region = (string)$setting->region;
        $totalSaved = 0;

        $explicitStartRow = isset($req['startRow']) ? (int)$req['startRow'] : null;
        $explicitPageNo = isset($req['page_no']) ? (int)$req['page_no'] : null;
        $startPageNo = $explicitPageNo && $explicitPageNo > 0 ? $explicitPageNo : 1;

        for ($pageNo = $startPageNo; $pageNo <= $maxPages; $pageNo++) {
            $timestamp = (string)round(microtime(true) * 1000);
            $startRow = $explicitStartRow !== null
                ? (int)($explicitStartRow + (($pageNo - $startPageNo) * $pageSize))
                : (int)(($pageNo - 1) * $pageSize);

            $params = [
                'app_key' => (string)$setting->app_key,
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'startRow' => (string)$startRow,
                'pageSize' => (string)$pageSize,
            ];

            $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);
            $result = $client->get($region, $apiPath, $params);

            LazadaApiLog::safeCreate([
                'pack' => 'lazada.brands.query',
                'method' => 'GET',
                'api_path' => $apiPath,
                'auth_required' => false,
                'request_params' => $params,
                'response_status' => (int)($result['status'] ?? 0),
                'ok' => (bool)($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result,
                'user_id' => auth()->id(),
            ]);

            if (!(bool)($result['ok'] ?? false)) {
                break;
            }

            $brands = $this->extractBrandRows($result['body'] ?? null);
            if (empty($brands)) {
                break;
            }

            DB::transaction(function () use ($brands, $region, &$totalSaved) {
                foreach ($brands as $b) {
                    $brandId = (int)($b['brand_id'] ?? $b['id'] ?? 0);
                    $name = (string)($b['name'] ?? $b['brand_name'] ?? '');
                    $name = trim($name);
                    if ($brandId <= 0 || $name === '') {
                        continue;
                    }

                    LazadaBrand::query()->updateOrCreate(
                        ['region' => $region, 'brand_id' => $brandId],
                        ['name' => $name, 'raw' => is_array($b) ? $b : null]
                    );
                    $totalSaved++;
                }
            });
        }

        return back()->with('status', 'Brands synced. Saved/updated: ' . $totalSaved);
    }

    private function extractBrandRows($body): array
    {
        if (!is_array($body)) {
            return [];
        }

        // Lazada responses vary by endpoint/version. We support common shapes and also
        // do a recursive search for the first list that looks like brand rows.
        $data = $body['data'] ?? $body;

        // Fast paths for known keys
        $candidates = [
            $data['brands'] ?? null,
            $data['brand_list'] ?? null,
            $data['brandList'] ?? null,
            $data['module']['brands'] ?? null,
            $data['result']['brands'] ?? null,
            $data['items'] ?? null,
        ];

        foreach ($candidates as $cand) {
            if (is_array($cand) && array_is_list($cand)) {
                return $cand;
            }
        }

        $found = $this->findBrandRowListRecursive($data);
        return $found ?? [];
    }

    private function findBrandRowListRecursive($node): ?array
    {
        if (!is_array($node)) {
            return null;
        }

        if (array_is_list($node) && !empty($node) && is_array($node[0])) {
            $first = $node[0];
            $hasId = array_key_exists('brand_id', $first) || array_key_exists('id', $first);
            $hasName = array_key_exists('name', $first) || array_key_exists('brand_name', $first);
            if ($hasId && $hasName) {
                return $node;
            }
        }

        foreach ($node as $v) {
            if (is_array($v)) {
                $r = $this->findBrandRowListRecursive($v);
                if ($r !== null) {
                    return $r;
                }
            }
        }

        return null;
    }


    private function findBrandListRecursive($node): ?array
    {
        if (!is_array($node)) {
            return null;
        }

        // If this is a list, check if its elements look like brand rows.
        if (array_is_list($node) && !empty($node)) {
            $first = $node[0];
            if (is_array($first)) {
                $hasId = array_key_exists('brand_id', $first) || array_key_exists('brandId', $first) || array_key_exists('id', $first);
                $hasName = array_key_exists('name', $first) || array_key_exists('brand_name', $first) || array_key_exists('brandName', $first);
                if ($hasId && $hasName) {
                    return $node;
                }
            }
        }

        // Otherwise, traverse children.
        foreach ($node as $v) {
            if (is_array($v)) {
                $res = $this->findBrandListRecursive($v);
                if ($res !== null) {
                    return $res;
                }
            }
        }
        return null;
    }

    public function syncTemplate(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaListing::query()->findOrFail($id);

        $request->validate([
            'primary_category_id' => 'required|integer|min:1',
        ]);

        $primaryCategoryId = (int)$request->input('primary_category_id');
        $listing->primary_category_id = $primaryCategoryId;
        $listing->save();

        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('lazada.listings.edit', $listing->id)->with('status', 'Missing Lazada settings.');
        }

        $apiPath = '/category/attributes/get';
        $timestamp = (string)round(microtime(true) * 1000);
        $params = [
            'app_key' => (string)$setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'primary_category_id' => (string)$primaryCategoryId,
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);
        $result = $client->get((string)$setting->region, $apiPath, $params);

        LazadaCategoryTemplate::query()->updateOrCreate(
            [
                'region' => (string)$setting->region,
                'primary_category_id' => $primaryCategoryId,
            ],
            [
                'template_body' => $result['body'] ?? null,
                'fetched_at' => now(),
            ]
        );

        return redirect()->route('lazada.listings.edit', $listing->id)->with('status', 'Category template synced.');
    }

    public function saveAttributes(Request $request, int $id)
    {
        $listing = LazadaListing::query()->findOrFail($id);

        $data = $request->validate([
            'attributes' => 'array',
            'attributes.*' => 'nullable|string|max:2000',
        ]);

        $attrs = (array)($data['attributes'] ?? []);

        // Brand is handled by the top-level Brand selector (listing.brand_id); ignore any submitted "brand" attribute.
        if (array_key_exists('brand', $attrs)) {
            unset($attrs['brand']);
        }

        // Force locked attributes (product core fields) to always use ERP values.
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $productRow = DB::table($pfx.'product as p')
            ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx.'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->where('p.product_id', (int)$listing->product_id)
            ->first(['p.product_id','p.sku','p.model','p.image','p.price','p.quantity','p.weight','p.length','p.width','p.height','pd.name','pd.description','m.name as manufacturer_name']);

        // Images are mandatory for Lazada product creation.
        // Lazada expects Product.Images.Image to be an array (main image first).
        // NOTE: use the ERP product row (includes p.image) to compute public image URLs.
        $imageUrls = $this->getProductImageUrls((int)$listing->product_id, $productRow);
        $imageUrls = array_values(array_filter(array_map(fn($u) => $this->normalizeImageUrl((string)$u), $imageUrls)));
        if (empty($imageUrls)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'images' => 'At least 1 product image is required. Please upload a main image (and optional additional images) in the core ERP Product page first.'
            ]);
        }

        // Validate mandatory (required) attributes from the cached category template.
        $requiredKeys = [];
        $requiredNames = [];
        if ($listing->primary_category_id) {
            $template = LazadaCategoryTemplate::query()
                ->where('region', $this->region())
                ->where('primary_category_id', (int)$listing->primary_category_id)
                ->first();

            if ($template && $template->template_body) {
                $templateAttrs = $this->extractAttributes($template->template_body);

                // Apply ERP-sourced values for locked keys (non-editable in Lazada Listing UI).
                $suggestedLocked = $this->suggestForAttributes($templateAttrs, $productRow);
                foreach ($templateAttrs as $a) {
                    $k = (string)($a['key'] ?? '');
                    if ($k !== '' && $this->isLockedProductAttributeKey($k) && array_key_exists($k, $suggestedLocked)) {
                        $attrs[$k] = (string)$suggestedLocked[$k];
                    }
                }

                foreach ($templateAttrs as $a) {
                    if (!empty($a['required'])) {
                        $k = (string)($a['key'] ?? '');
                        if ($k !== '') {
                            $requiredKeys[] = $k;
                            $requiredNames[$k] = (string)($a['name'] ?? $k);
                        }
                    }
                }
            }
        }

        if (!empty($requiredKeys)) {
            // Build a merged map: existing saved values + incoming form values
            $existing = LazadaListingAttribute::query()
                ->where('lazada_listing_id', $listing->id)
                ->pluck('value', 'attribute_key')
                ->toArray();

            $merged = $existing;
            foreach ($attrs as $k => $v) {
                $k = trim((string)$k);
                if ($k === '') continue;
                $merged[$k] = ($v === '' ? null : $v);
            }

            $errs = [];
            foreach ($requiredKeys as $k) {
                $keyLower = strtolower(trim((string)$k));
                if ($keyLower === 'brand') {
                    // Brand is handled by the top-level Brand selector (listing.brand_id).
                    continue;
                }
                if ($this->isSkuLevelRequiredKey($k)) {
                    // Lazada marks some SKU-level fields as mandatory in templates (e.g. SellerSku, price).
                    // Those are handled in the Variant Mapping section / payload builder.
                    continue;
                }
                $val = $merged[$k] ?? null;
                if ($val === null || trim((string)$val) === '') {
                    $label = $requiredNames[$k] ?? $k;
                    $errs['attributes.'.$k] = $label . ' is mandatory.';
                }
            }

            if (!empty($errs)) {
                return redirect()->route('lazada.listings.edit', $listing->id)
                    ->withErrors($errs)
                    ->withInput();
            }
        }

        DB::transaction(function () use ($listing, $attrs) {
            foreach ($attrs as $key => $value) {
                $key = trim((string)$key);
                if ($key === '') {
                    continue;
                }
                LazadaListingAttribute::query()->updateOrCreate(
                    [
                        'lazada_listing_id' => $listing->id,
                        'attribute_key' => $key,
                    ],
                    [
                        'value' => $value === '' ? null : $value,
                    ]
                );
            }
        });

        return redirect()->route('lazada.listings.edit', $listing->id)->with('status', 'Attributes saved.');
    }

    public function saveVariants(Request $request, int $id)
    {
        $listing = LazadaListing::query()->findOrFail($id);

        $data = $request->validate([
            'variants' => 'array',
            'variants.*.seller_sku' => 'nullable|string|max:64',
            'variants.*.price' => 'nullable|numeric',
            'variants.*.quantity' => 'nullable|integer',
        ]);

        $rows = (array)($data['variants'] ?? []);

        DB::transaction(function () use ($listing, $rows) {
            foreach ($rows as $povId => $row) {
                $povId = (string)$povId;
                if ($povId === '' || !is_array($row)) {
                    continue;
                }
                $idVal = ctype_digit($povId) ? (int)$povId : null;
                LazadaListingVariant::query()->updateOrCreate(
                    [
                        'lazada_listing_id' => $listing->id,
                        'product_option_value_id' => $idVal,
                    ],
                    [
                        'seller_sku' => isset($row['seller_sku']) && $row['seller_sku'] !== '' ? (string)$row['seller_sku'] : null,
                        'price' => isset($row['price']) && $row['price'] !== '' ? (float)$row['price'] : null,
                        'quantity' => isset($row['quantity']) && $row['quantity'] !== '' ? (int)$row['quantity'] : null,
                    ]
                );
            }
        });

        return redirect()->route('lazada.listings.edit', $listing->id)->with('status', 'Variant mapping saved.');
    }

    public function buildPayload(Request $request, int $id)
    {
        $listing = LazadaListing::query()->findOrFail($id);

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table($pfx.'product as p')
            ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx.'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->where('p.product_id', (int)$listing->product_id)
            ->first(['p.product_id','p.sku','p.model','p.image','p.price','p.quantity','p.weight','p.length','p.width','p.height','pd.name','pd.description','m.name as manufacturer_name']);

        // Images are mandatory for Lazada product creation.
        // Lazada expects Request.Product.Images.Image to be an array (main image first).
        $imageUrls = $this->getProductImageUrls((int)$listing->product_id, $product);
        $imageUrls = array_values(array_filter(array_map(fn($u) => $this->normalizeImageUrl((string)$u), $imageUrls)));
        if (empty($imageUrls)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'images' => 'At least 1 product image is required. Please upload a main image (and optional additional images) in the core ERP Product page first.'
            ]);
        }

        $attrs = LazadaListingAttribute::query()
            ->where('lazada_listing_id', $listing->id)
            ->pluck('value', 'attribute_key')
            ->toArray();

        // Ensure Brand exists in attributes before validating required template keys.
        // Brand is handled by the top-level selector (listing.brand_id / No Brand button),
        // but Lazada category templates often mark "brand" as required.
        $brandId = (int)($listing->brand_id ?? 0);
        $brandOverride = trim((string)($listing->brand_name_override ?? ''));
        $isNoBrand = $brandOverride !== '' && strtolower($brandOverride) === 'no brand';

        if ($isNoBrand) {
            $attrs['brand'] = 'No Brand';
            unset($attrs['brand_id']);
        } elseif ($brandId > 0) {
            $brandName = LazadaBrand::query()
                ->where('region', $this->region())
                ->where('brand_id', $brandId)
                ->value('name');
            $attrs['brand'] = $brandName ? (string)$brandName : (string)$brandId;
            $attrs['brand_id'] = $brandId;
        } else {
            // Default for payload build/preview so it won't block testing.
            $attrs['brand'] = 'No Brand';
            unset($attrs['brand_id']);
        }

        // Validate mandatory attributes before building payload
        $requiredKeys = [];
        $requiredNames = [];
        if ($listing->primary_category_id) {
            $template = LazadaCategoryTemplate::query()
                ->where('region', $this->region())
                ->where('primary_category_id', (int)$listing->primary_category_id)
                ->first();
            if ($template && $template->template_body) {
                $templateAttrs = $this->extractAttributes($template->template_body);

                // Force ERP-sourced values for locked keys.
                $suggestedLocked = $this->suggestForAttributes($templateAttrs, $product);
                foreach ($templateAttrs as $a) {
                    $k = (string)($a['key'] ?? '');
                    if ($k !== '' && $this->isLockedProductAttributeKey($k) && array_key_exists($k, $suggestedLocked)) {
                        $attrs[$k] = (string)$suggestedLocked[$k];
                    }
                }

                foreach ($templateAttrs as $a) {
                    if (!empty($a['required'])) {
                        $k = (string)($a['key'] ?? '');
                        if ($k !== '') {
                            $requiredKeys[] = $k;
                            $requiredNames[$k] = (string)($a['name'] ?? $k);
                        }
                    }
                }
            }
        }
        if (!empty($requiredKeys)) {
            $errs = [];
            foreach ($requiredKeys as $k) {
                if ($this->isSkuLevelRequiredKey($k)) {
                    continue;
                }
                $val = $attrs[$k] ?? null;
                if ($val === null || trim((string)$val) === '') {
                    $label = $requiredNames[$k] ?? $k;
                    $errs['attributes.'.$k] = $label . ' is mandatory.';
                }
            }
            if (!empty($errs)) {
                return redirect()->route('lazada.listings.edit', $listing->id)
                    ->withErrors($errs);
            }
        }

        $povRows = DB::table($pfx.'product_option_value')
            ->where('product_id', (int)$listing->product_id)
            ->orderBy('product_option_value_id')
            ->get(['product_option_value_id','sku','quantity','price','price_prefix']);

        $map = LazadaListingVariant::query()
            ->where('lazada_listing_id', $listing->id)
            ->get()
            ->keyBy(function ($v) {
                return $v->product_option_value_id === null ? 'base' : (string)$v->product_option_value_id;
            });

        // Lazada requires a non-empty SellerSku. In OpenCart data, `sku` can be an empty string,
        // so treat empty strings as missing and fallback to model.
        $fallbackBaseSku = null;
        if ($product) {
            $fallbackBaseSku = trim((string)($product->sku ?? ''));
            if ($fallbackBaseSku === '') {
                $fallbackBaseSku = trim((string)($product->model ?? ''));
            }
        }

        $skus = [];
        $basePrice = (float)($product->price ?? 0);
        $usedSellerSkus = [];

        // Lazada treats empty string SellerSku as missing. Prefer ERP SKU, else fallback to ERP model.
        $fallbackBaseSku = null;
        if ($product) {
            $fallbackBaseSku = trim((string)($product->sku ?? ''));
            if ($fallbackBaseSku === '') {
                $fallbackBaseSku = trim((string)($product->model ?? ''));
            }
            if ($fallbackBaseSku === '') {
                $fallbackBaseSku = null;
            }
        }
        if ($povRows->count() > 0) {
            foreach ($povRows as $r) {
                $key = (string)$r->product_option_value_id;
                $m = $map->get($key);

                // SellerSku mapping rule:
                // 1) Listing variant override
                // 2) ERP product.sku (single source of truth)
                // 3) ERP product.model
                $candidate = trim((string)($m && $m->seller_sku ? $m->seller_sku : ''));
                if ($candidate === '') {
                    $candidate = $fallbackBaseSku;
                }
                // Ensure uniqueness across variant rows
                if ($candidate !== null && $candidate !== '') {
                    if (isset($usedSellerSkus[$candidate])) {
                        $candidate = $candidate . '-' . (int)$r->product_option_value_id;
                    }
                    $usedSellerSkus[$candidate] = true;
                }

                // OpenCart product_option_value.price is a delta using price_prefix.
                // Lazada needs the final absolute price.
                $erpPrice = $this->computeVariantPrice($basePrice, (float)$r->price, (string)($r->price_prefix ?? '+'));
                $finalPrice = $m && $m->price !== null ? (float)$m->price : (float)$erpPrice;
                $skus[] = [
                    'product_option_value_id' => (int)$r->product_option_value_id,
                    'seller_sku' => $candidate,
                    'price' => $finalPrice,
                    'quantity' => $m && $m->quantity !== null ? (int)$m->quantity : (int)$r->quantity,
                    'price_prefix' => (string)($r->price_prefix ?? '+'),
                ];
            }
        } else {
            // No variants in ERP; show a single base SKU in preview.
            $skus[] = [
                'product_option_value_id' => null,
                'seller_sku' => $fallbackBaseSku,
                'price' => (float)($product->price ?? 0),
                'quantity' => (int)($product->quantity ?? 0),
                'price_prefix' => '+',
            ];
        }

        // Validate SKU-level mandatory fields (SellerSku, price) if Lazada template requires them.
        if (!empty($requiredKeys)) {
            $skuErrs = [];
            $needsSellerSku = false;
            $needsPrice = false;
            foreach ($requiredKeys as $k) {
                $lk = strtolower((string)$k);
                if ($lk === 'sellersku' || $lk === 'seller_sku') $needsSellerSku = true;
                if ($lk === 'price') $needsPrice = true;
            }
            if ($needsSellerSku) {
                foreach ($skus as $i => $s) {
                    if (empty($s['seller_sku'])) {
                        $skuErrs['variants'] = 'SellerSku is mandatory. Please set SKU in your ERP variants or fill Variant Mapping overrides.';
                        break;
                    }
                }
            }
            if ($needsPrice) {
                foreach ($skus as $i => $s) {
                    if (!isset($s['price']) || $s['price'] === null || (float)$s['price'] <= 0) {
                        $skuErrs['variants'] = 'Price is mandatory. Please set product price in ERP (base price) and/or option price adjustments, or fill Variant Mapping overrides.';
                        break;
                    }
                }
            }
            if (!empty($skuErrs)) {
                return redirect()->route('lazada.listings.edit', $listing->id)
                    ->withErrors($skuErrs);
            }
        }

        // Brand resolution (preview only)
        $brandSuggestion = $this->suggestBrandFromManufacturer($product?->manufacturer_name ?? null);
        $effectiveBrandId = $listing->brand_id ?: ($brandSuggestion['brand_id'] ?? null);
        $effectiveBrandName = $listing->brand_name_override;
        if ($effectiveBrandName === null || $effectiveBrandName === '') {
            // If we found a Lazada brand match, leave name null (ID is enough).
            // Otherwise, use manufacturer name as the text override.
            $effectiveBrandName = ($brandSuggestion['brand_id'] ?? null) ? null : (string)($product?->manufacturer_name ?? '');
        }

        $payload = [
            'region' => $this->region(),
            'product_id' => $listing->product_id,
            'primary_category_id' => $listing->primary_category_id,
            'brand_id' => $listing->brand_id,
            'brand_name_override' => $listing->brand_name_override,
            'effective_brand' => [
                'brand_id' => $effectiveBrandId,
                'brand_name' => $effectiveBrandName,
                'source' => $listing->brand_id ? 'listing.brand_id' : ($brandSuggestion['brand_id'] ? 'manufacturer->lazada_brand_match' : 'manufacturer_name'),
            ],
            'product' => $product ? [
                'sku' => $product->sku ?? null,
                'model' => $product->model ?? null,
                'name' => $product->name ?? null,
                'description' => $product->description ?? null,
                'manufacturer' => $product->manufacturer_name ?? null,
                'weight' => $product->weight ?? null,
                'dimensions' => [
                    'length' => $product->length ?? null,
                    'width' => $product->width ?? null,
                    'height' => $product->height ?? null,
                ],
            ] : null,
            'attributes' => $attrs,
            'skus' => $skus,
        ];

        return redirect()->route('lazada.listings.edit', $listing->id)
            ->with('status', 'Payload built (preview only).')
            ->with('payload_preview_json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function pushSample(Request $request, int $id, LazadaClient $client)
    {
        $listing = LazadaListing::query()->findOrFail($id);

        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return redirect()->route('lazada.listings.edit', $listing->id)
                ->with('status', 'Missing Lazada settings (region/app key/app secret/access token).');
        }

        if (empty($listing->primary_category_id)) {
            return redirect()->route('lazada.listings.edit', $listing->id)
                ->withErrors(['primary_category_id' => 'Primary Category is required before pushing.']);
        }

        // Build a Lazada-ready product payload (best-effort). We'll learn from QC errors and iterate.
        try {
            [$productPayload, $preview] = $this->buildLazadaProductCreatePayload($listing);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->route('lazada.listings.edit', $listing->id)
                ->withErrors($e->errors())
                ->withInput();
        }

        // Lazada QC rejects non-Lazada image URLs (outer links).
        // Convert ERP/public image URLs to Lazada inlinks before pushing.
        try {
            $productPayload = $this->ensureLazadaInlinkImages($productPayload, $setting, $client);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->route('lazada.listings.edit', $listing->id)
                ->withErrors($e->errors())
                ->withInput();
        }

        // Keep preview aligned with the final payload we are about to send.
        $preview['images'] = data_get($productPayload, 'Request.Product.Images.Image', []);
        $preview['payload'] = $productPayload;

        // Lazada product create expects a JSON string payload.
        $apiPath = '/product/create';
        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => (string) $setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => (string) $setting->access_token,
            'payload' => json_encode($productPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $params['sign'] = $client->sign($apiPath, $params, (string) $setting->app_secret);

        $result = $client->post((string) $setting->region, $apiPath, $params);

        LazadaApiLog::safeCreate([
            'pack' => 'lazada.product.create',
            'method' => 'POST',
            'api_path' => $apiPath,
            'auth_required' => true,
            'request_params' => $params,
            'response_status' => (int)($result['status'] ?? 0),
            'ok' => (bool)($result['ok'] ?? false),
            'response_body' => $result['body'] ?? $result,
            'user_id' => auth()->id(),
        ]);

        return redirect()->route('lazada.listings.edit', $listing->id)
            ->with('status', 'Sample push sent to Lazada. Review the response below (QC errors will appear there).')
            ->with('push_request_json', json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->with('push_response_json', json_encode($result['body'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Lazada requires "inlink" images for product create/update.
     * If we send non-Lazada URLs, QC returns BIZ_CHECK_EXIST_OUTER_MAIN_IMAGE.
     */
    private function ensureLazadaInlinkImages(array $productPayload, $setting, LazadaClient $client): array
    {
        $images = data_get($productPayload, 'Request.Product.Images.Image', []);
        if (!is_array($images) || empty($images)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'images' => 'At least 1 product image is required. (Product.Images.Image is empty.)'
            ]);
        }

        $images = array_values(array_filter($images, fn($v) => is_string($v) && trim($v) !== ''));
        if (empty($images)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'images' => 'At least 1 product image is required. (No valid image URL found.)'
            ]);
        }

        // Lazada migration APIs support up to 8 images per call; keep it predictable.
        $images = array_slice($images, 0, 8);

        // Reject localhost/private URLs: Lazada cannot fetch them.
        foreach ($images as $u) {
            $host = strtolower((string) parse_url($u, PHP_URL_HOST));
            if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'images' => 'Your image URLs are using a local host (e.g., localhost). Lazada cannot access these. Please set APP_URL to your public domain and ensure the images are publicly reachable.'
                ]);
            }
        }

        $region = (string) $setting->region;
        $inlinks = $this->migrateImagesToLazadaInlinks(
            $region,
            $images,
            (string) $setting->app_key,
            (string) $setting->app_secret,
            (string) $setting->access_token,
            $client
        );

        data_set($productPayload, 'Request.Product.Images.Image', $inlinks);

        // SKU-level main image: first inlink.
        $first = $inlinks[0] ?? null;
        if ($first) {
            $sku0 = data_get($productPayload, 'Request.Product.Skus.Sku.0');
            if (is_array($sku0)) {
                $sku0['Images'] = ['Image' => [$first]];
                data_set($productPayload, 'Request.Product.Skus.Sku.0', $sku0);
            }
        }

        return $productPayload;
    }

    /**
     * Convert external/public image URLs to Lazada inlinks.
     *
     * IMPORTANT: We use /image/migrate (single-image) only.
     * Lazada's product APIs do not allow non-Lazada URLs in Images, and /image/migrate
     * returns the inlink immediately (data.image.url). This avoids the batch polling
     * complexity (/images/migrate + /image/response/get) that caused repeated format/
     * method errors during testing.
     */
    private function migrateImagesToLazadaInlinks(string $region, array $imageUrls, string $appKey, string $appSecret, string $accessToken, LazadaClient $client): array
    {
    $imageUrls = array_values(array_filter(array_map(fn($u) => trim((string)$u), $imageUrls), fn($u) => $u !== ''));
    $imageUrls = array_slice($imageUrls, 0, 8);

    // Serve from cache first.
    $out = [];
    $needs = [];
    foreach ($imageUrls as $url) {
        $hash = hash('sha256', $url);
        $cached = LazadaImageLink::query()
            ->where('region', $region)
            ->where('original_hash', $hash)
            ->value('lazada_url');
        if ($cached) {
            $out[$url] = $cached;
        } else {
            $needs[] = $url;
        }
    }

    if (!empty($needs)) {
        foreach ($needs as $originalUrl) {
            $newUrl = $this->migrateSingleImageXml($region, $originalUrl, $appKey, $appSecret, $accessToken, $client);
            if (!$newUrl) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'images' => 'Failed to migrate image to Lazada inlink. Please ensure the image URL is public and reachable.'
                ]);
            }

            $hash = hash('sha256', $originalUrl);
            LazadaImageLink::query()->updateOrCreate(
                ['region' => $region, 'original_hash' => $hash],
                ['original_url' => $originalUrl, 'lazada_url' => $newUrl]
            );
            $out[$originalUrl] = $newUrl;
        }
    }

    // Return in the same order as input.
    $ordered = [];
    foreach ($imageUrls as $u) {
        if (isset($out[$u])) $ordered[] = $out[$u];
    }

    if (empty($ordered)) {
        throw \Illuminate\Validation\ValidationException::withMessages([
            'images' => 'Unable to prepare Lazada image links. No images were migrated.'
        ]);
    }

    return $ordered;
    }

    /**
     * Migrate a single image using /image/migrate with the proven XML payload.
     * Returns Lazada inlink (data.image.url) or null on failure.
     */
    private function migrateSingleImageXml(string $region, string $url, string $appKey, string $appSecret, string $accessToken, LazadaClient $client): ?string
    {
        $apiPath = '/image/migrate';

        // Lazada API Explorer + SDK example format
        $xml = '<?xml version="1.0" encoding="UTF-8" ?>'
            . '<Request>'
            . '<Image><Url>' . htmlspecialchars($url, ENT_XML1) . '</Url></Image>'
            . '</Request>';

        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => $appKey,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'payload' => $xml,
        ];

        $params['sign'] = $client->sign($apiPath, $params, $appSecret);
        $resp = $client->post($region, $apiPath, $params);
        $body = $resp['body'] ?? [];

        $inlink = data_get($body, 'data.image.url') ?? data_get($body, 'data.image.Url');
        return (is_string($inlink) && $inlink !== '') ? $inlink : null;
    }

private function tryMigrateBatchImagesXml(string $region, array $urls, string $appKey, string $appSecret, string $accessToken, LazadaClient $client): array
{
    $urls = array_values(array_slice(array_filter(array_map(fn($u) => trim((string)$u), $urls), fn($u) => $u !== ''), 0, 8));
    if (empty($urls)) return [];

    $apiPath = '/images/migrate';

    // Prepare candidate request formats.
    $candidates = [];

    // A) XML payload under "payload"
    $xml = '<?xml version="1.0" encoding="UTF-8" ?>'
        . '<Request><Images>'
        . implode('', array_map(fn($u) => '<Url>' . htmlspecialchars($u, ENT_XML1) . '</Url>', $urls))
        . '</Images></Request>';
    $candidates[] = ['payload' => $xml];

    // B) JSON payload under "payload"
    $json = json_encode(['Request' => ['Images' => ['Url' => $urls]]], JSON_UNESCAPED_SLASHES);
    if (is_string($json)) {
        $candidates[] = ['payload' => $json];
    }

    // C) Direct param list (some gateways)
    $candidates[] = ['urls' => json_encode($urls, JSON_UNESCAPED_SLASHES)];

    foreach ($candidates as $extra) {
        $timestamp = (string) round(microtime(true) * 1000);
        $params = array_merge([
            'app_key' => $appKey,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
        ], $extra);
        $params['sign'] = $client->sign($apiPath, $params, $appSecret);

        $resp = $client->post($region, $apiPath, $params);
        $body = $resp['body'] ?? [];

        $batchId = data_get($body, 'batch_id')
            ?? data_get($body, 'data.batch_id')
            ?? data_get($body, 'data.batchId')
            ?? data_get($body, 'request_id')
            ?? data_get($body, 'data.request_id')
            ?? data_get($body, 'data.requestId');

        if (!$batchId) {
            continue;
        }

        usleep(650000);

        $apiPath2 = '/image/response/get';

        // Lazada docs often show GET, but in production some gateways are strict.
        // Try POST (form) first, then GET as fallback.
        foreach (['batch_id', 'request_id'] as $idKey) {
            $timestamp2 = (string) round(microtime(true) * 1000);
            $params2 = [
                'app_key' => $appKey,
                'sign_method' => 'sha256',
                'timestamp' => $timestamp2,
                'access_token' => $accessToken,
                $idKey => (string) $batchId,
            ];
            $params2['sign'] = $client->sign($apiPath2, $params2, $appSecret);

            $resp2 = $client->post($region, $apiPath2, $params2);
            $body2 = $resp2['body'] ?? [];

            $code2 = is_array($body2) ? (string)($body2['code'] ?? '') : '';
            if (!($resp2['ok'] ?? false) || ($code2 !== '' && $code2 !== '0')) {
                $params2['sign'] = $client->sign($apiPath2, $params2, $appSecret);
                $resp2 = $client->get($region, $apiPath2, $params2);
                $body2 = $resp2['body'] ?? [];
            }

            $items = data_get($body2, 'data.images')
                ?? data_get($body2, 'data.image')
                ?? data_get($body2, 'data')
                ?? data_get($body2, 'result')
                ?? [];

            if (isset($items['images']) && is_array($items['images'])) $items = $items['images'];
            if (isset($items['Images']) && is_array($items['Images'])) $items = $items['Images'];
            if (isset($items['image']) && is_array($items['image'])) $items = $items['image'];
            if (isset($items['Image']) && is_array($items['Image'])) $items = $items['Image'];

            $migrated = [];
            if (is_array($items)) {
                foreach ($items as $it) {
                    if (is_string($it)) {
                        $migrated[] = $it;
                        continue;
                    }
                    if (!is_array($it)) continue;

                    $migrated[] = $it['url']
                        ?? $it['Url']
                        ?? ($it['image']['url'] ?? null)
                        ?? ($it['image']['Url'] ?? null)
                        ?? $it['image']
                        ?? $it['image_url']
                        ?? null;
                }
            }

            $migrated = array_values(array_filter($migrated, fn($v) => is_string($v) && $v !== ''));

            if (count($migrated) === count($urls)) {
                $map = [];
                foreach ($urls as $i => $orig) {
                    $map[$orig] = $migrated[$i] ?? null;
                }
                return $map;
            }
        }
    }

    return array_fill_keys($urls, null);
}

private function buildLazadaProductCreatePayload(LazadaListing $listing): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table($pfx.'product as p')
            ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx.'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->where('p.product_id', (int)$listing->product_id)
            ->first(['p.product_id','p.sku','p.model','p.image','p.price','p.quantity','p.weight','p.length','p.width','p.height','pd.name','pd.description','m.name as manufacturer_name']);

        // Images are mandatory for Lazada product creation.
        // Lazada checks Product.Images.Image (and sometimes Sku.Images.Image) for main image.
        $imageUrls = $this->getProductImageUrls((int)$listing->product_id, $product);
        $imageUrls = array_values(array_filter(array_map(fn($u) => $this->normalizeImageUrl((string)$u), $imageUrls)));
        if (empty($imageUrls)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'images' => 'At least 1 product image is required. Please upload a main image (and optional additional images) in the core ERP Product page first.'
            ]);
        }

        $attrs = LazadaListingAttribute::query()
            ->where('lazada_listing_id', $listing->id)
            ->pluck('value', 'attribute_key')
            ->toArray();

        // Internal/legacy key - do not send to Lazada as it does not satisfy QC.
        unset($attrs['__images__']);

        // Ensure Brand attributes are present.
        // Lazada examples show using brand name ("No Brand") and/or brand_id for registered brands.
        // We keep this logic defensive for sandbox testing where brand libraries may be incomplete.
        $brandOverride = trim((string)($listing->brand_name_override ?? ''));
        $brandId = (int)($listing->brand_id ?? 0);

        $isNoBrand = $brandOverride !== '' && strtolower($brandOverride) === 'no brand';

        if ($isNoBrand) {
            $attrs['brand'] = 'No Brand';
            unset($attrs['brand_id']);
        } elseif ($brandId > 0) {
            $brandName = LazadaBrand::query()
                ->where('region', $this->region())
                ->where('brand_id', $brandId)
                ->value('name');

            // Provide both brand (name) + brand_id to satisfy "brand is mandatory" checks while remaining compatible.
            $attrs['brand'] = $brandName ? (string)$brandName : (string)$brandId;
            $attrs['brand_id'] = $brandId;
        } else {
            // For payload preview and smoother sandbox testing, default to Lazada's "No Brand"
            // when no brand_id is selected and the No-Brand marker isn't set.
            // Lazada commonly treats "No Brand" as a valid brand value in Seller Center.
            $attrs['brand'] = 'No Brand';
            unset($attrs['brand_id']);
        }

        // Base SKU row (no variants) OR per-option-value rows.
        $povRows = DB::table($pfx.'product_option_value')
            ->where('product_id', (int)$listing->product_id)
            ->orderBy('product_option_value_id')
            ->get(['product_option_value_id','sku','quantity','price','price_prefix']);

        $map = LazadaListingVariant::query()
            ->where('lazada_listing_id', $listing->id)
            ->get()
            ->keyBy(function ($v) {
                return $v->product_option_value_id === null ? 'base' : (string)$v->product_option_value_id;
            });

        // Lazada requires a non-empty SellerSku. In OpenCart schema, p.sku can be an empty string.
        // Fall back to model if sku is blank.
        $fallbackBaseSku = null;
        if ($product) {
            $fallbackBaseSku = trim((string)($product->sku ?? ''));
            if ($fallbackBaseSku === '') {
                $fallbackBaseSku = trim((string)($product->model ?? ''));
            }
            if ($fallbackBaseSku === '') {
                $fallbackBaseSku = null;
            }
        }

        $skus = [];
        $basePrice = (float)($product->price ?? 0);
        $usedSellerSkus = [];
        if ($povRows->count() > 0) {
            foreach ($povRows as $r) {
                $key = (string)$r->product_option_value_id;
                $m = $map->get($key);
                // SellerSku mapping rule:
                // 1) Listing variant override
                // 2) ERP product.sku (single source of truth)
                // 3) ERP product.model
                $candidate = trim((string)($m && $m->seller_sku ? $m->seller_sku : ''));
                if ($candidate === '') {
                    $candidate = $fallbackBaseSku;
                }
                // Ensure uniqueness across variant rows
                if ($candidate !== null && $candidate !== '') {
                    if (isset($usedSellerSkus[$candidate])) {
                        $candidate = $candidate . '-' . (int)$r->product_option_value_id;
                    }
                    $usedSellerSkus[$candidate] = true;
                }

                // OpenCart product_option_value.price is a delta using price_prefix.
                // Lazada needs the final absolute price.
                $erpPrice = $this->computeVariantPrice($basePrice, (float)$r->price, (string)($r->price_prefix ?? '+'));
                $finalPrice = $m && $m->price !== null ? (float)$m->price : (float)$erpPrice;
                $skus[] = [
                    'SellerSku' => $candidate,
                    'price' => $finalPrice,
                    'quantity' => $m && $m->quantity !== null ? (int)$m->quantity : (int)$r->quantity,
                ];
            }
        } else {
            // No variants in ERP; create a single base SKU.
            $skus[] = [
                'SellerSku' => $fallbackBaseSku,
                'price' => (float)($product->price ?? 0),
                'quantity' => (int)($product->quantity ?? 0),
            ];
        }

        // Remove SKU-level keys from Attributes to avoid mixing SKU fields into Attributes.
        foreach (array_keys($attrs) as $k) {
            if ($this->isSkuLevelRequiredKey($k)) {
                unset($attrs[$k]);
            }
        }

        // Local mandatory checks (attribute + sku level) before calling Lazada.
        $requiredKeys = [];
        $requiredNames = [];
        $template = LazadaCategoryTemplate::query()
            ->where('region', $this->region())
            ->where('primary_category_id', (int)$listing->primary_category_id)
            ->first();
        if ($template && $template->template_body) {
            $templateAttrs = $this->extractAttributes($template->template_body);

            // Force ERP-sourced values for locked keys (core product fields).
            $suggestedLocked = $this->suggestForAttributes($templateAttrs, $product);
            foreach ($templateAttrs as $a) {
                $k = (string)($a['key'] ?? '');
                if ($k !== '' && $this->isLockedProductAttributeKey($k) && array_key_exists($k, $suggestedLocked)) {
                    $attrs[$k] = (string)$suggestedLocked[$k];
                }
            }

            foreach ($templateAttrs as $a) {
                if (!empty($a['required'])) {
                    $k = (string)($a['key'] ?? '');
                    if ($k !== '') {
                        $requiredKeys[] = $k;
                        $requiredNames[$k] = (string)($a['name'] ?? $k);
                    }
                }
            }
        }

        $errs = [];
        foreach ($requiredKeys as $k) {
            if ($this->isSkuLevelRequiredKey($k)) {
                continue;
            }
            $val = $attrs[$k] ?? null;
            if ($val === null || trim((string)$val) === '') {
                $label = $requiredNames[$k] ?? $k;
                $errs['attributes.'.$k] = $label . ' is mandatory.';
            }
        }
        if (!empty($errs)) {
            throw \Illuminate\Validation\ValidationException::withMessages($errs);
        }

        // SKU-level mandatory
        $needsSellerSku = false;
        $needsPrice = false;
        foreach ($requiredKeys as $k) {
            $lk = strtolower((string)$k);
            if ($lk === 'sellersku' || $lk === 'seller_sku') $needsSellerSku = true;
            if ($lk === 'price') $needsPrice = true;
        }
        if ($needsSellerSku) {
            foreach ($skus as $s) {
                if (empty($s['SellerSku'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'variants' => 'SellerSku is mandatory. Please set SKU in ERP or in Variant Mapping overrides.'
                    ]);
                }
            }
        }
        if ($needsPrice) {
            foreach ($skus as $s) {
                if (!isset($s['price']) || (float)$s['price'] <= 0) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'variants' => 'Price is mandatory. Please set product price in ERP (base price) and/or option price adjustments, or fill Variant Mapping overrides.'
                    ]);
                }
            }
        }

        // Lazada payload (best-effort). We'll adjust based on QC responses.
        $payload = [
            'Request' => [
                'Product' => [
                    'PrimaryCategory' => (int)$listing->primary_category_id,
                    'Images' => [
                        'Image' => $imageUrls,
                    ],
                    'Attributes' => array_merge([
                        'name' => $product->name ?? null,
                        'description' => $product->description ?? null,
                    ], $attrs),
                    'Skus' => [
                        'Sku' => $skus,
                    ],
                ],
            ],
        ];

        // Some categories validate the main image at SKU level. Apply the first image as the main SKU image.
        if (!empty($imageUrls)) {
            foreach ($payload['Request']['Product']['Skus']['Sku'] as $idx => $row) {
                if (!isset($payload['Request']['Product']['Skus']['Sku'][$idx]['Images'])) {
                    $payload['Request']['Product']['Skus']['Sku'][$idx]['Images'] = ['Image' => [$imageUrls[0]]];
                }
            }
        }

        $preview = [
            'listing_id' => $listing->id,
            'primary_category_id' => $listing->primary_category_id,
            'attributes' => $attrs,
            'skus' => $skus,
            'images' => $imageUrls,
            'payload' => $payload,
        ];

        return [$payload, $preview];
    }

    private function region(): string
    {
        $setting = LazadaSetting::query()->first();
        return (string)($setting->region ?? '');
    }

    private function decryptSetting(?LazadaSetting $setting): ?LazadaSetting
    {
        if (!$setting) {
            return null;
        }
        $copy = $setting->replicate();
        foreach (['app_secret', 'access_token', 'refresh_token', 'auth_code'] as $field) {
            try {
                if ($copy->{$field}) {
                    $copy->{$field} = decrypt($copy->{$field});
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return $copy;
    }

    /**
     * Best-effort extraction of attribute definitions from Lazada category template response.
     * The response shapes vary; we keep this tolerant.
     */
    private function extractAttributes($body): array
    {
        if (!is_array($body)) {
            return [];
        }

        $data = $body['data'] ?? $body;
        if (is_array($data) && isset($data['attributes']) && is_array($data['attributes'])) {
            return $this->normalizeAttributes($data['attributes']);
        }
        if (is_array($data)) {
            // Some responses return an array directly
            if (array_is_list($data)) {
                return $this->normalizeAttributes($data);
            }
        }
        return [];
    }

    private function normalizeAttributes(array $raw): array
    {
        $out = [];
        foreach ($raw as $a) {
            if (!is_array($a)) {
                continue;
            }
            $name = (string)($a['name'] ?? $a['attribute_name'] ?? '');
            $id = $a['id'] ?? $a['attribute_id'] ?? null;
            $required = (bool)($a['is_mandatory'] ?? $a['isMandatory'] ?? $a['mandatory'] ?? $a['is_required'] ?? $a['required'] ?? false);
            $inputType = (string)($a['input_type'] ?? $a['type'] ?? $a['inputType'] ?? 'text');
            $options = $a['options'] ?? $a['option_values'] ?? $a['values'] ?? null;

            $key = $name !== '' ? $name : (is_scalar($id) ? (string)$id : '');
            if ($key === '') {
                continue;
            }

            $out[] = [
                'key' => $key,
                'name' => $name !== '' ? $name : $key,
                'required' => $required,
                'input_type' => $inputType,
                'options' => is_array($options) ? $options : [],
            ];
        }
        return $out;
    }
}