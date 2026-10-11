<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Brand;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDescription;
use App\Models\Catalog\ProductToCategory;
use App\Models\Catalog\ProductImage;
use App\Rules\UniqueSku;
use App\Services\ActivityLogger;
use App\Services\StockHistoryLogger;
use App\Support\Catalog\DescriptionHtml;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;


class ProductController extends Controller
{
    private function isSafeLocalReturnUrl(string $url): bool
    {
        if ($url === '' || $url[0] !== '/') {
            return false;
        }

        if (str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
            return false;
        }

        return true;
    }

    private function totalVariationsQuantity($variationTypes): ?int
    {
        if (!is_array($variationTypes) || empty($variationTypes)) return null;

        $sum = 0;
        $hasAnyValue = false;

        foreach ($variationTypes as $variationType) {
            if (!is_array($variationType)) continue;
            $values = $variationType['values'] ?? [];
            if (!is_array($values)) continue;
            foreach ($values as $v) {
                if (!is_array($v)) continue;
                if (empty($v['variation_value_id'])) continue;
                $hasAnyValue = true;
                $sum += (int) ($v['quantity'] ?? 0);
            }
        }

        return $hasAnyValue ? $sum : null;
    }

    private function totalAbsoluteVariationsQuantity(Request $request): ?int
    {
        return $this->variationWriter()->totalQuantity($request->input());
    }

    private function resolveBrandId(?int $brandId, ?string $brandName): int
    {
        return app(\App\Services\Catalog\ProductCreator::class)->resolveBrandId($brandId, $brandName);
    }

    private function resolveCategoryId(?int $categoryId, ?string $categoryName): int
    {
        return app(\App\Services\Catalog\ProductCreator::class)->resolveCategoryId($categoryId, $categoryName);
    }

private function isWordStartMatch(string $haystack, string $term): bool
{
    $h = mb_strtolower($haystack, 'UTF-8');
    $t = mb_strtolower(trim($term), 'UTF-8');
    if ($t === '') return false;

    return (bool) preg_match('/(^|[^a-z0-9])' . preg_quote($t, '/') . '/i', $h);
}

    

    private function saveProductVideo(int $productId, Request $request): void
    {
        if (! $request->has('video_path')) {
            return;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        $row = \App\Models\ProductVideo::query()->where('product_id', $productId)->first();
        $path = trim((string) $request->input('video_path', ''));

        if ($path === '') {
            if ($row) {
                \App\Services\Media\ProductVideoFile::forget((string) $row->path);
                $row->delete();
            }

            return;
        }

        if (! str_starts_with($path, \App\Services\Media\ProductVideoFile::DIR . '/') || str_contains($path, '..')) {
            return;
        }

        $settled = \App\Services\Media\ProductVideoFile::settle($path, $productId);
        if (! $disk->exists($settled)) {
            return;
        }
        if ($row && (string) $row->path !== $settled) {
            \App\Services\Media\ProductVideoFile::forget((string) $row->path);
        }

        \App\Models\ProductVideo::query()->updateOrCreate(['product_id' => $productId], [
            'path' => $settled,
            'original_name' => (string) ($request->input('video_name') ?: basename($settled)),
            'bytes' => (int) $disk->size($settled),
            'duration_ms' => \App\Services\Media\ProductVideoFile::durationMs($disk->path($settled)),
            'ai_generated' => $request->boolean('video_ai'),
        ]);
    }

    private function imagesFromRequest(?string $json): array
    {
        $json = trim((string) ($json ?? ''));
        if ($json === '') return [];

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) return [];

        $out = [];
        foreach ($decoded as $item) {
            if (is_array($item) && isset($item['path'])) {
                $path = (string) $item['path'];
            } else {
                $path = (string) $item;
            }

            $path = trim($path);
            if ($path === '') continue;

            if (str_contains($path, '..')) continue;

            $isCatalog = Str::startsWith($path, 'catalog/');
            $isTemp = Str::startsWith($path, 'tmp/product-images/');
            if (!$isCatalog && !$isTemp) continue;

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) continue;

            $out[] = $path;
        }

        $uniq = [];
        foreach ($out as $p) {
            if (!in_array($p, $uniq, true)) $uniq[] = $p;
        }

        return $uniq;
    }

    private function imagePathsFromItems(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            if (is_array($it) && isset($it['path'])) {
                $out[] = (string) $it['path'];
            } elseif (is_string($it)) {
                $out[] = (string) $it;
            }
        }
        return $out;
    }

    private function saveProductImages(int $productId, array $paths): void
    {
        app(\App\Services\Catalog\ProductCreator::class)->saveImages($productId, $paths);
    }

public function index(Request $request)
{
    $langId = (int) config('catalog.default_language_id');
$q = trim((string) $request->get('q', ''));

    $sort = (string) $request->get('sort', 'product_id');
    $dir = strtolower((string) $request->get('dir', $request->filled('sort') ? 'asc' : 'desc')) === 'desc' ? 'desc' : 'asc';

    $allowedSort = [
        'product_id'   => 'p.product_id',
        'name'         => 'pd.name',
        'quantity'     => 'p.quantity',
        'price'        => 'p.price',
        'status'       => 'p.status',
        'updated'      => 'p.date_modified',
    ];

    if ($request->filled('sort_by') && preg_match('/^([a-z_]+):(asc|desc)$/', (string) $request->get('sort_by'), $m)
        && array_key_exists($m[1], $allowedSort)) {
        [, $sort, $dir] = $m;
    }

    if (!array_key_exists($sort, $allowedSort)) {
        $sort = 'product_id';
    }

    $optQtySub = DB::table('product_variation_values as pov')
        ->select('pov.product_id', DB::raw('SUM(pov.quantity) as variations_quantity'))
        ->groupBy('pov.product_id');

    $query = DB::table('products as p')
        ->leftJoin('product_descriptions as pd', function ($j) use ($langId) {
            $j->on('p.product_id', '=', 'pd.product_id')
              ->where('pd.language_id', '=', $langId);
        })
        ->leftJoinSub($optQtySub, 'povsum', function ($j) {
            $j->on('p.product_id', '=', 'povsum.product_id');
        })
        ->select(
            'p.product_id',
            'pd.name as name',
            'p.sku',
            'p.image',
            'p.quantity',
            DB::raw('COALESCE(povsum.variations_quantity, 0) as variations_quantity'),
            'p.price',
            'p.status'
        );

    $status = (string) $request->input('status', '');
    if ($status === '0' || $status === '1') {
        $query->where('p.status', (int) $status);
    }

if ($q !== '') {
    $nameIds = DB::table('product_descriptions as pd')
        ->where('pd.language_id', '=', $langId)
        ->where('pd.name', 'like', "%{$q}%")
        ->limit(800)
        ->get(['pd.product_id','pd.name']);

    $matched = [];
    foreach ($nameIds as $r) {
        if ($this->isWordStartMatch((string)$r->name, $q)) {
            $matched[] = (int)$r->product_id;
        }
    }

    $skuMatched = DB::table('products')
        ->where(function ($w) use ($q) {
            $w->where('sku', 'like', "%{$q}%")
              ->orWhere('model', 'like', "%{$q}%");
        })
        ->limit(800)
        ->pluck('product_id')
        ->map(fn($v) => (int) $v)
        ->toArray();

    $optSkuMatched = DB::table('product_variation_values')
        ->where('sku', 'like', "%{$q}%")
        ->limit(800)
        ->pluck('product_id')
        ->map(fn($v) => (int) $v)
        ->toArray();

    $matched = array_values(array_unique(array_merge($matched, $skuMatched, $optSkuMatched)));

    if (count($matched) === 0) {
        $query->whereRaw('1=0');
    } else {
        $query->whereIn('p.product_id', array_slice($matched, 0, 800));
    }
}

    $query->orderBy($allowedSort[$sort], $dir);

    $products = $query->paginate(50)->withQueryString();

    foreach ($products as $row) {
        if (isset($row->name)) $row->name = html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    $pageIds = $products->pluck('product_id')->map(fn($v) => (int)$v)->all();
    $variationRowsByProduct = \App\Support\VariationRows::forProducts($pageIds);
    foreach ($products as $row) {
        $row->variation_rows = ($variationRowsByProduct->get((int) $row->product_id) ?? collect())->all();
    }

    $channelNote = app(\App\Services\Catalog\ProductDeleter::class)->notes($pageIds);

    return view('catalog.products.index', compact('products', 'q', 'sort', 'dir', 'status', 'channelNote'));
}


    public function create()
    {
        if ($full = \App\Plans\Quota::refusal('products')) {
            return redirect()->route('products.index')->with('error', $full);
        }

        $productImages = [];
        $langId = (int) config('catalog.default_language_id');

        $existingVariationTypes = collect();
        $existingCombinations = [];

        $currencies = \App\Models\Currency::where('status', 1)->orderBy('code')->get();

        return view('catalog.products.create', compact('existingVariationTypes', 'existingCombinations', 'currencies'));
    }


    
    private function brandSlugFromId(int $brandId): string
    {
        $brandId = (int) $brandId;
        if ($brandId <= 0) {
            return '_no_brand_';
        }

        $m = \App\Models\Catalog\Brand::query()
            ->where('brand_id', $brandId)
            ->first(['name']);
        if (!$m) {
            return '_no_brand_';
        }

        $slug = Str::slug((string) $m->name);
        return $slug !== '' ? $slug : '_no_brand_';
    }

    private function uniqueTargetPath(string $dir, string $filename): string
    {
        $dir = trim($dir, '/');
        $filename = trim(basename($filename));
        $disk = Storage::disk('public');

        $base = pathinfo($filename, PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $candidate = $dir . '/' . $base . '.' . $ext;
        if (!$disk->exists($candidate)) return $candidate;

        $i = 1;
        while (true) {
            $candidate = $dir . '/' . $base . '_' . $i . '.' . $ext;
            if (!$disk->exists($candidate)) return $candidate;
            $i++;
            if ($i > 9999) abort(500, 'Unable to generate unique image filename.');
        }
    }

    private function resolveBrandDir(int $brandId): string
    {
        return app(\App\Services\Catalog\ProductCreator::class)->imageFolder($brandId);
    }

    private function finalizeTempImages(string $token, string $brandSlug, int $productId, array $items): array
    {
        $disk = Storage::disk('public');
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $token);
        $tmpBase = 'tmp/product-images/' . ($token ?: 'default');
        $mid = (int) DB::table('products')->where('product_id', $productId)->value('brand_id');
        $finalDir = $this->resolveBrandDir($mid);
        $disk->makeDirectory($finalDir);

        $out = [];
        foreach ($items as $it) {
            $path = (string) ($it['path'] ?? '');
            if ($path === '') continue;

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) continue;

            if (Str::startsWith($path, $tmpBase . '/') && $disk->exists($path)) {
                $origName = (string) ($it['name'] ?? basename($path));
                $target = $this->uniqueTargetPath($finalDir, $origName);
                $disk->move($path, $target);
                $out[] = ['path' => $target];
            } else {
                $out[] = ['path' => $path];
            }
        }

        if ($token && $disk->exists($tmpBase)) {
            $disk->deleteDirectory($tmpBase);
        }

        return $out;
    }

    
    private function normalizeLegacyImages(string $slug, int $productId, array $paths): array
    {
        $disk = Storage::disk('public');

        $targetDir = 'catalog/_products_' . date('Y');
        $targetPrefix = $targetDir . '/';

        $disk->makeDirectory($targetDir);

        $out = [];
        foreach ($paths as $p) {
            $p = trim((string) $p);
            if ($p === '') continue;

            if (!Str::startsWith($p, 'catalog/')) {
                $out[] = $p;
                continue;
            }

            if (Str::startsWith($p, $targetPrefix)) {
                $out[] = $p;
                continue;
            }

            if (!$disk->exists($p)) {
                $out[] = $p;
                continue;
            }

            $dest = $this->uniqueTargetPath($targetDir, basename($p));
            $disk->move($p, $dest);
            $out[] = $dest;
        }

        $uniq = [];
        foreach ($out as $p) {
            if (!in_array($p, $uniq, true)) $uniq[] = $p;
        }
        return $uniq;
    }

    private function moveProductImageFolder(string $oldSlug, string $newSlug, int $productId): void
    {
        $disk = Storage::disk('public');
        $oldDir = 'catalog/' . $oldSlug . '/' . $productId;
        $newDir = 'catalog/' . $newSlug . '/' . $productId;

        if ($oldSlug === '' || $newSlug === '' || $oldSlug === $newSlug) return;
        if (!$disk->exists($oldDir)) return;

        $disk->makeDirectory('catalog/' . $newSlug);
        $disk->makeDirectory($newDir);

        foreach ($disk->allFiles($oldDir) as $file) {
            $rel = substr($file, strlen($oldDir) + 1);
            $target = $newDir . '/' . $rel;
            $disk->makeDirectory(dirname($target));
            if ($disk->exists($target)) {
                $target = $this->uniqueTargetPath(dirname($target), basename($target));
            }
            $disk->move($file, $target);
        }

        $disk->deleteDirectory($oldDir);
    }


    public function store(Request $request)
    {
        if ($full = \App\Plans\Quota::refusal('products')) {
            return redirect()->route('products.index')->with('error', $full);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'model' => 'nullable|string|max:64',
            'sku' => ['required', 'string', 'max:64', new UniqueSku()],
            'price' => 'required|numeric|gt:0',
            'cost_amount' => 'nullable|numeric',
            'cost_percentage' => 'nullable|numeric',
            'cost_additional' => 'nullable|numeric',
            'quantity' => 'nullable|integer',
            'weight' => 'required|numeric|gt:0',
            'length' => 'required|numeric|gt:0',
            'width'  => 'required|numeric|gt:0',
            'height' => 'required|numeric|gt:0',
            'status' => 'required|in:0,1',
            'brand_id' => 'nullable|integer',
            'brand_name' => 'nullable|string|max:255',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|min:1',
            'category_id' => 'nullable|integer',
            'category_name' => 'nullable|string|max:1024',
            'description' => ['nullable', 'string'],
            'images_json' => ['nullable', 'string'],
            'video_path'  => ['nullable', 'string', 'max:512'],
            'video_name'  => ['nullable', 'string', 'max:255'],
            'video_ai'    => ['nullable', 'boolean'],

            'variation_types' => 'array',
            'variation_types.*.variation_type_id' => 'required|integer|min:1',
            'variation_types.*.required' => 'nullable|in:0,1',
            'variation_types.*.values' => 'array',
            'variation_types.*.values.*.variation_value_id' => 'required|integer|min:1',
            'variation_types.*.values.*.sku' => 'nullable|string|max:64',
            'variation_types.*.values.*.quantity' => 'nullable|integer',
            'variation_types.*.values.*.subtract' => 'nullable|in:0,1',
            'variation_types.*.values.*.price' => 'nullable|numeric',
            'variation_types.*.values.*.price_prefix' => 'nullable|in:+,-',
            'variation_types.*.values.*.weight' => 'nullable|numeric',
            'variation_types.*.values.*.weight_prefix' => 'nullable|in:+,-',
            'variation_types.*.values.*.cost_amount' => 'nullable|numeric',
            'variation_types.*.values.*.cost_percentage' => 'nullable|numeric',
            'variation_types.*.values.*.cost_additional' => 'nullable|numeric',
        ], [
            'sku.required' => 'A SKU is needed. Imports, stock pushes and link repair recognize this product by it.',
            'price.required' => 'A price above zero is needed - a listing pushed without one goes live at zero.',
            'price.gt' => 'The price must be more than zero - a listing pushed at zero sells at zero.',
            'weight.required' => 'A package weight is needed.',
            'weight.gt' => 'The package weight must be more than zero.',
            'length.required' => 'The package size (L×W×H) is needed - the channels refuse a push without it.',
            'width.required' => 'The package size (L×W×H) is needed - the channels refuse a push without it.',
            'height.required' => 'The package size (L×W×H) is needed - the channels refuse a push without it.',
            'length.gt' => 'Each package dimension must be more than zero.',
            'width.gt' => 'Each package dimension must be more than zero.',
            'height.gt' => 'Each package dimension must be more than zero.',
        ]);

        $resolvedBrandId = $this->resolveBrandId(
            (int) $request->input('brand_id', 0),
            (string) $request->input('brand_name', '')
        );

        $categoryIds = array_filter(array_map('intval', $request->input('category_ids', [])));
        if (empty($categoryIds)) {
            $fallback = $this->resolveCategoryId(
                (int) $request->input('category_id', 0),
                (string) $request->input('category_name', '')
            );
            if ($fallback > 0) $categoryIds = [$fallback];
        }

        $forcedQty = $request->has('_variations_format')
            ? $this->totalAbsoluteVariationsQuantity($request)
            : $this->totalVariationsQuantity($request->input('variation_types', []));

        if ($request->has('_variations_format')) {
            $this->validateVariationSkus(0, $request);
        }

        app(\App\Services\Catalog\ProductCreator::class)->create(
            [
                'name' => (string) $request->name,
                'sku' => (string) ($request->sku ?? ''),
                'model' => $request->input('model'),
                'quantity' => $forcedQty !== null ? (int) $forcedQty : (int) $request->quantity,
                'reorder_level' => (int) ($request->reorder_level ?? 0),
                'weight' => (float) ($request->input('weight', 0) ?? 0),
                'length' => (float) ($request->input('length', 0) ?? 0),
                'width' => (float) ($request->input('width', 0) ?? 0),
                'height' => (float) ($request->input('height', 0) ?? 0),
                'status' => (int) $request->input('status', 1),
                'price' => (float) $request->price,
                'cost_amount' => (float) ($request->cost_amount ?? 0),
                'cost_percentage' => (float) ($request->cost_percentage ?? 0),
                'cost_additional' => (float) ($request->cost_additional ?? 0),
                'brand_id' => (int) ($resolvedBrandId ?? 0),
                'category_ids' => $categoryIds,
                'description' => (string) $request->input('description', ''),
            ],
            variations: function (int $id) use ($request) {
                if ($request->has('_variations_format')) {
                    $this->saveAbsoluteVariations($id, $request);
                } else {
                    $this->saveProductVariationTypes($id, $request->input('variation_types', []));
                }
            },
            images: function (int $id) use ($request): array {
                $paths = $this->imagesFromRequest($request->input('images_json'));
                $token = (string) $request->input('pim_token', '');
                if ($token !== '' && ! empty($paths)) {
                    $items = array_map(fn ($pth) => ['path' => $pth], $paths);
                    $paths = array_map(fn ($it) => $it['path'], $this->finalizeTempImages($token, '', $id, $items));
                }

                $this->saveProductVideo($id, $request);

                return $paths;
            },
        );

        return redirect()->route('products.index')
            ->with('status','Product created.');
    }

    public function salesHistory($id)
    {
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table('products as p')
            ->leftJoin('product_descriptions as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->where('p.product_id', (int) $id)
            ->select('p.product_id', 'pd.name', 'p.sku')
            ->first();

        abort_if(!$product, 404);

        $product->name = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $sales = DB::table('order_products as op')
            ->join('orders as o', 'o.order_id', '=', 'op.order_id')
            ->where('op.product_id', (int) $id)
            ->where('o.order_status_id', '>', 0)
            ->select(
                'op.order_product_id',
                'o.order_id',
                'o.date_added',
                'o.channel',
                'op.name',
                'op.quantity',
                'op.price',
                'op.total',
                'op.cost'
            )
            ->orderByDesc('o.date_added')
            ->paginate(50)
            ->withQueryString();

        $opIds = $sales->pluck('order_product_id')->all();
        $variationsByOpId = [];
        if (!empty($opIds)) {
            $lineVariations = DB::table('order_variations')
                ->whereIn('order_product_id', $opIds)
                ->get(['order_product_id', 'name', 'value']);
            foreach ($lineVariations as $variation) {
                $variationsByOpId[(int) $variation->order_product_id][] = $variation;
            }
        }

        foreach ($sales as $s) {
            $s->variations = $variationsByOpId[(int) $s->order_product_id] ?? [];
            $cost = (float) ($s->cost ?? 0) * (int) $s->quantity;
            $revenue = (float) $s->total;
            $profit = $revenue - $cost;
            $s->line_cost = $cost;
            $s->profit = $profit;
            $s->margin = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0;
            $s->markup = $cost > 0 ? round(($profit / $cost) * 100, 1) : 0;
        }

        $sourceLabelsMap = [];
        foreach (app(\App\Channels\ChannelRegistry::class)->availableChannelOptions() as $opt) {
            $sourceLabelsMap[$opt['value']] = $opt;
        }

        return view('catalog.products.sales', compact('product', 'sales', 'sourceLabelsMap'));
    }

    public function stockHistory($id)
    {
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table('products as p')
            ->leftJoin('product_descriptions as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->where('p.product_id', (int) $id)
            ->select('p.product_id', 'pd.name', 'p.sku', 'p.quantity')
            ->first();

        abort_if(!$product, 404);

        $product->name = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $variationValueNames = DB::table('product_variation_values as pov')
            ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')
                    ->where('ovd.language_id', '=', $langId);
            })
            ->where('pov.product_id', (int) $id)
            ->pluck('ovd.name', 'pov.product_variation_value_id')
            ->all();

        $history = DB::table('stock_history')
            ->where('product_id', (int) $id)
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        $totalAdded = DB::table('stock_history')
            ->where('product_id', (int) $id)
            ->where('quantity_change', '>', 0)
            ->sum('quantity_change');

        $totalDeducted = DB::table('stock_history')
            ->where('product_id', (int) $id)
            ->where('quantity_change', '<', 0)
            ->sum('quantity_change');

        return view('catalog.products.stock_history', compact('product', 'history', 'variationValueNames', 'totalAdded', 'totalDeducted'));
    }

    public function edit($id)
    {
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table('products as p')
            ->leftJoin('product_descriptions as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                  ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin('brands as m', 'p.brand_id', '=', 'm.brand_id')
            ->select('p.*', 'pd.name as name', 'pd.description as description', 'm.name as brand_name')
            ->where('p.product_id', (int) $id)
            ->first();

        abort_if(!$product, 404);

        $product->name = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $product->model = html_entity_decode($product->model ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $product->sku = html_entity_decode($product->sku ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Sanitize on display too: older rows may hold hostile HTML.
        $product->description = DescriptionHtml::store($product->description ?? '');


        $currentCategoryIds = DB::table('product_categories')
            ->where('product_id', (int) $id)
            ->pluck('category_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $currentCategories = [];
        if (!empty($currentCategoryIds)) {
            $currentCategories = DB::table('category_descriptions')
                ->whereIn('category_id', $currentCategoryIds)
                ->where('language_id', $langId)
                ->get(['category_id', 'name'])
                ->map(fn ($c) => ['id' => (int) $c->category_id, 'name' => $c->name])
                ->all();
        }

        $currentCategoryId = $currentCategoryIds[0] ?? 0;
        $categoryName = $currentCategories[0]['name'] ?? null;

        $brandName = $product->brand_name ?? null;

        $existingVariationTypes = collect();

            $existingVariationTypes = DB::table('product_variation_types as po')
                ->join('variation_types as o', 'po.variation_type_id', '=', 'o.variation_type_id')
                ->join('variation_type_descriptions as od', function ($j) use ($langId) {
                    $j->on('po.variation_type_id', '=', 'od.variation_type_id')
                      ->where('od.language_id', '=', $langId);
                })
                ->where('po.product_id', (int) $id)
                ->orderBy('o.sort_order')
                ->orderBy('od.name')
                ->get([
                    'po.product_variation_type_id',
                    'po.variation_type_id',
                    'po.required',
                    'po.value',
                    'o.type',
                    'od.name',
                ]);

            $poIds = $existingVariationTypes->pluck('product_variation_type_id')->map(fn ($v) => (int)$v)->all();

            $existingValuesByPoId = [];
            if (!empty($poIds)) {
                $rows = DB::table('product_variation_values as pov')
                    ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                        $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')
                          ->where('ovd.language_id', '=', $langId);
                    })
                    ->whereIn('pov.product_variation_type_id', $poIds)
                    ->orderBy('pov.product_variation_value_id')
                    ->get([
                        'pov.product_variation_value_id',
                        'pov.product_variation_type_id',
                        'pov.variation_value_id',
                        'ovd.name as variation_value_name',
                        'pov.sku',
                        'pov.quantity',
                        'pov.subtract',
                        'pov.price_prefix',
                        'pov.price',
                        'pov.absolute_price',
                        'pov.absolute_cost',
                        'pov.cost_additional',
                        'pov.weight_prefix',
                        'pov.weight',
                        'pov.cost',
                        'pov.cost_prefix',
                    ]);

                foreach ($rows as $r) {
                    $r->variation_value_name = html_entity_decode((string)$r->variation_value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $existingValuesByPoId[(int)$r->product_variation_type_id][] = [
                        'product_variation_value_id' => (int)$r->product_variation_value_id,
                        'variation_value_id' => (int)$r->variation_value_id,
                        'variation_value_name' => $r->variation_value_name,
                        'name' => $r->variation_value_name,
                        'quantity' => (int)$r->quantity,
                        'subtract' => (int)($r->subtract ?? 1),
                        'price_prefix' => (string)$r->price_prefix,
                        'price' => (string)$r->price,
                        'absolute_price' => (float)($r->absolute_price ?? 0),
                        'absolute_cost' => (float)($r->absolute_cost ?? 0),
                        'cost_additional' => (float)($r->cost_additional ?? 0),
                        'weight_prefix' => (string)($r->weight_prefix ?? '+'),
                        'weight' => (string)($r->weight ?? 0),
                        'sku' => (string)($r->sku ?? ''),
                        'cost' => (string)($r->cost ?? 0),
                        'cost_prefix' => (string)($r->cost_prefix ?? '+'),
                    ];
                }
            }

            $imageByPovId = [];
            $statusByPovId = [];
            $costAmountByPovId = [];
            $costAddByPovId = [];
            $comboImages = DB::table('product_variations as c')
                ->join('product_variation_parts as cv', 'cv.product_variation_id', '=', 'c.id')
                ->where('c.product_id', (int) $id)
                ->get(['cv.product_variation_value_id', 'c.image', 'c.status', 'c.cost_amount', 'c.cost_additional']);

            foreach ($comboImages as $row) {
                $statusByPovId[(int) $row->product_variation_value_id] ??= (int) ($row->status ?? 1);
                $costAmountByPovId[(int) $row->product_variation_value_id] ??= (float) ($row->cost_amount ?? 0);
                $costAddByPovId[(int) $row->product_variation_value_id] ??= (float) ($row->cost_additional ?? 0);

                if (trim((string) ($row->image ?? '')) === '') {
                    continue;
                }

                $povId = (int) $row->product_variation_value_id;
                $imageByPovId[$povId] = isset($imageByPovId[$povId])
                    ? $imageByPovId[$povId]
                    : (string) $row->image;
            }

            foreach ($existingValuesByPoId as $poId => $values) {
                foreach ($values as $i => $value) {
                    $povId = (int) $value['product_variation_value_id'];
                    $existingValuesByPoId[$poId][$i]['image'] = $imageByPovId[$povId] ?? '';
                    $existingValuesByPoId[$poId][$i]['status'] = $statusByPovId[$povId] ?? 1;
                    $existingValuesByPoId[$poId][$i]['cost_amount'] = $costAmountByPovId[$povId]
                        ?? (float) $value['absolute_cost'];
                    $existingValuesByPoId[$poId][$i]['cost_additional'] = $costAddByPovId[$povId]
                        ?? (float) ($value['cost_additional'] ?? 0);
                }
            }

            foreach ($existingVariationTypes as $o) {
                $o->name = html_entity_decode((string)$o->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $o->variation_type_name = $o->name;
                $o->values = $existingValuesByPoId[(int)$o->product_variation_type_id] ?? [];
            }

            $existingCombinations = [];
            if ($existingVariationTypes->count() > 1) {
                $combos = DB::table('product_variations as c')
                    ->where('c.product_id', (int) $id)
                    ->orderBy('c.sort_order')
                    ->get();

                foreach ($combos as $combo) {
                    $pivotRows = DB::table('product_variation_parts as cv')
                        ->join('product_variation_values as pov', 'cv.product_variation_value_id', '=', 'pov.product_variation_value_id')
                        ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                            $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')
                              ->where('ovd.language_id', '=', $langId);
                        })
                        ->join('product_variation_types as po', 'pov.product_variation_type_id', '=', 'po.product_variation_type_id')
                        ->where('cv.product_variation_id', $combo->id)
                        ->orderBy('po.product_variation_type_id')
                        ->get(['ovd.name', 'po.product_variation_type_id']);

                    $opt1Name = $pivotRows->first()->name ?? '';
                    $opt2Name = $pivotRows->count() > 1 ? $pivotRows->get(1)->name : '';

                    $existingCombinations[] = [
                        'id' => (int) $combo->id,
                        'opt1_name' => html_entity_decode($opt1Name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        'opt2_name' => html_entity_decode($opt2Name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        'sku' => $combo->sku,
                        'status' => (int) ($combo->status ?? 1),
                        'image' => (string) ($combo->image ?? ''),
                        'quantity' => (int) $combo->quantity,
                        'absolute_price' => (float) $combo->absolute_price,
                        'absolute_cost' => (float) $combo->absolute_cost,
                        'cost_amount' => (float) ($combo->cost_amount ?? 0),
                        'cost_additional' => (float) ($combo->cost_additional ?? 0),
                    ];
                }
            }

        $productImages = [];
        if (!empty($product->image)) {
            $productImages[] = (string) $product->image;
        }
        $additionalImgs = DB::table('product_images')
            ->where('product_id', (int) $id)
            ->orderBy('sort_order')
            ->pluck('image')
            ->toArray();

        foreach ($additionalImgs as $img) {
            $img = (string) $img;
            if ($img !== '' && !in_array($img, $productImages, true)) {
                $productImages[] = $img;
            }
        }

        $returnUrl = preg_replace('#^https?://[^/]+#', '', url()->previous()) ?: '';
        if (!str_contains($returnUrl, '/products') || str_contains($returnUrl, '/products/' . $id)) {
            $returnUrl = route('products.index');
        }

        $currencies = \App\Models\Currency::where('status', 1)->orderBy('code')->get();

        $deleter = app(\App\Services\Catalog\ProductDeleter::class);
        $channelNote = $deleter->note($deleter->presence([(int) $product->product_id])[(int) $product->product_id] ?? []);

        $productVideo = \App\Models\ProductVideo::query()->where('product_id', (int) $id)->first()?->summary();

        return view('catalog.products.edit', compact('product', 'currentCategoryId', 'categoryName', 'currentCategories', 'brandName', 'existingVariationTypes', 'existingCombinations', 'productImages', 'productVideo', 'returnUrl', 'currencies', 'channelNote'));
    }

    public function update(Request $request, $id)
    {
        $pfxProduct = 'products';

        $request->validate([
            'name' => 'required|string|max:255',
            'model' => 'nullable|string|max:64',
            'sku' => ['required', 'string', 'max:64', new UniqueSku((int) $id)],
            'price' => 'required|numeric|gt:0',
            'cost_amount' => 'nullable|numeric',
            'cost_percentage' => 'nullable|numeric',
            'cost_additional' => 'nullable|numeric',
            'quantity' => 'nullable|integer',
            'weight' => 'required|numeric|gt:0',
            'length' => 'required|numeric|gt:0',
            'width'  => 'required|numeric|gt:0',
            'height' => 'required|numeric|gt:0',
            'status' => 'required|in:0,1',
            'brand_id' => 'nullable|integer',
            'brand_name' => 'nullable|string|max:255',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|min:1',
            'category_id' => 'nullable|integer',
            'category_name' => 'nullable|string|max:1024',
            'description' => ['nullable', 'string'],
            'images_json' => ['nullable', 'string'],
            'video_path'  => ['nullable', 'string', 'max:512'],
            'video_name'  => ['nullable', 'string', 'max:255'],
            'video_ai'    => ['nullable', 'boolean'],

            'variation_types' => 'array',
            'variation_types.*.variation_type_id' => 'required|integer|min:1',
            'variation_types.*.required' => 'nullable|in:0,1',
            'variation_types.*.values' => 'array',
            'variation_types.*.values.*.variation_value_id' => 'required|integer|min:1',
            'variation_types.*.values.*.sku' => 'nullable|string|max:64',
            'variation_types.*.values.*.quantity' => 'nullable|integer',
            'variation_types.*.values.*.subtract' => 'nullable|in:0,1',
            'variation_types.*.values.*.price' => 'nullable|numeric',
            'variation_types.*.values.*.price_prefix' => 'nullable|in:+,-',
            'variation_types.*.values.*.weight' => 'nullable|numeric',
            'variation_types.*.values.*.weight_prefix' => 'nullable|in:+,-',
            'variation_types.*.values.*.cost_amount' => 'nullable|numeric',
            'variation_types.*.values.*.cost_percentage' => 'nullable|numeric',
            'variation_types.*.values.*.cost_additional' => 'nullable|numeric',
        ], [
            'sku.required' => 'A SKU is needed. Imports, stock pushes and link repair recognize this product by it.',
            'price.required' => 'A price above zero is needed - a listing pushed without one goes live at zero.',
            'price.gt' => 'The price must be more than zero - a listing pushed at zero sells at zero.',
            'weight.required' => 'A package weight is needed.',
            'weight.gt' => 'The package weight must be more than zero.',
            'length.required' => 'The package size (L×W×H) is needed - the channels refuse a push without it.',
            'width.required' => 'The package size (L×W×H) is needed - the channels refuse a push without it.',
            'height.required' => 'The package size (L×W×H) is needed - the channels refuse a push without it.',
            'length.gt' => 'Each package dimension must be more than zero.',
            'width.gt' => 'Each package dimension must be more than zero.',
            'height.gt' => 'Each package dimension must be more than zero.',
        ]);

        if ($request->has('_variations_format')) {
            $this->validateVariationSkus((int) $id, $request);
        }

        $langId = (int) config('catalog.default_language_id');

        $resolvedBrandId = $this->resolveBrandId($request->brand_id, $request->brand_name);

        $categoryIds = array_filter(array_map('intval', $request->input('category_ids', [])));
        if (empty($categoryIds)) {
            $fallback = $this->resolveCategoryId($request->category_id, $request->category_name);
            if ($fallback > 0) $categoryIds = [$fallback];
        }

        $forcedQty = $request->has('_variations_format')
            ? $this->totalAbsoluteVariationsQuantity($request)
            : $this->totalVariationsQuantity($request->input('variation_types', []));

        $skuChanges = ['product_sku' => null, 'variation_skus' => []];
        $originalAttrs = null;
        $updatedAttrs = null;
        $variationsBefore = \App\Services\VariationForgetService::snapshot((int) $id);
        DB::transaction(function () use ($request, $id, $langId, $resolvedBrandId, $categoryIds, $forcedQty, &$skuChanges, &$originalAttrs, &$updatedAttrs) {
            $p = Product::where('product_id', (int) $id)->firstOrFail();
            $originalAttrs = $p->getAttributes();

            $oldBrandId = (int) ($p->brand_id ?? 0);

            $oldProductSku = trim((string) $p->sku);

            // A blank model stays blank; do not fall back to the SKU, empty is a real answer.
            if ($request->has('model')) {
                $p->model = trim((string) ($request->input('model') ?? ''));
            }
            $p->sku = $request->sku ?? '';

            $newProductSku = trim((string) $p->sku);
            if ($oldProductSku !== '' && $newProductSku !== '' && $oldProductSku !== $newProductSku) {
                $skuChanges['product_sku'] = ['old' => $oldProductSku, 'new' => $newProductSku];
            }
            $p->quantity = $forcedQty !== null ? (int) $forcedQty : (int) $request->quantity;
            $p->reorder_level = (int) ($request->reorder_level ?? 0);
            $p->weight = (float) ($request->input('weight', $p->weight ?? 0) ?? 0);
            $p->length = (float) ($request->input('length', $p->length ?? 0) ?? 0);
            $p->width  = (float) ($request->input('width',  $p->width  ?? 0) ?? 0);
            $p->height = (float) ($request->input('height', $p->height ?? 0) ?? 0);
            $p->brand_id = (int) ($resolvedBrandId ?? 0);
            $p->price = (float) $request->price;
            $p->cost_amount = (float) ($request->cost_amount ?? 0);
            $p->cost_percentage = (float) ($request->cost_percentage ?? 0);
            $p->cost_additional = (float) ($request->cost_additional ?? 0);
            $p->cost = $p->cost_amount + ($p->cost_percentage / 100 * $p->price) + $p->cost_additional;
            $p->status = (int) $request->status;
            
            
            $p->date_modified = now();
            if ($p->date_available === '0000-00-00') {
                $p->date_available = now()->toDateString();
            }

            $p->save();
            $updatedAttrs = $p->getAttributes();

            $oldPrefix = null;
            $newPrefix = null;







            $d = DB::table('product_descriptions')
                ->where('product_id', (int) $id)
                ->where('language_id', (int) $langId)
                ->first();

            DB::table('product_descriptions')->updateOrInsert(
                [
                    'product_id' => (int) $id,
                    'language_id' => (int) $langId,
                ],
                [
                    'name' => (string) $request->name,
                    'description' => DescriptionHtml::store((string) $request->input('description', '')),
                    'meta_title' => (string) $request->name,
                    'meta_description' => (string) ($d->meta_description ?? ''),
                    'meta_keyword' => (string) ($d->meta_keyword ?? ''),
                    'tag' => (string) ($d->tag ?? ''),
                ]
            );

            DB::table('product_categories')->where('product_id', (int) $id)->delete();
            foreach ($categoryIds as $catId) {
                ProductToCategory::create([
                    'product_id' => (int) $id,
                    'category_id' => $catId,
                ]);
            }

            if ($request->has('_variations_format')) {
                $this->saveAbsoluteVariations((int) $id, $request, $skuChanges);
            } else {
                $variationTypes = $request->input('variation_types', []);
                $this->saveProductVariationTypes((int) $id, $variationTypes, $skuChanges);
            }
            if ($request->has('images_json')) {
                $paths = $this->imagesFromRequest($request->input('images_json'));
                $token = (string) $request->input('pim_token', '');
                if ($token !== '' && !empty($paths)) {
                    $items = array_map(fn($pth) => ['path' => $pth], $paths);
                    $finalized = $this->finalizeTempImages($token, '', (int) $id, $items);
                    $paths = array_map(fn($it) => $it['path'], $finalized);
                }
                $this->saveProductImages((int) $id, $paths);
            }
            $this->saveProductVideo((int) $id, $request);
        });

        $status = 'Saved';
        if (!empty($skuChanges['product_sku']) || !empty($skuChanges['variation_skus'])) {
            $syncMessages = (new \App\Services\SkuSyncService())->syncSkuChanges((int) $id, $skuChanges);
            if (!empty($syncMessages)) {
                $status .= ' | SKU synced: ' . implode(' | ', $syncMessages);
            }
        }
        $forgotten = (new \App\Services\VariationForgetService())->reconcile((int) $id, $variationsBefore);
        if ($forgotten !== []) {
            $status .= ' | Forgotten on every channel: ' . implode(', ', $forgotten);
        }
        $changes = $originalAttrs && $updatedAttrs
            ? ActivityLogger::diff($originalAttrs, $updatedAttrs, ['sku', 'price', 'quantity', 'status', 'model', 'brand_id'])
            : null;
        ActivityLogger::log('updated', 'Product', (int) $id, $request->name . ' (SKU: ' . ($request->sku ?? '') . ')', $changes);

        if ($originalAttrs && $updatedAttrs) {
            $oldQty = (int) ($originalAttrs['quantity'] ?? 0);
            $newQty = (int) ($updatedAttrs['quantity'] ?? 0);
            if ($oldQty !== $newQty) {
                StockHistoryLogger::log(
                    productId: (int) $id,
                    variationValueId: null,
                    orderId: null,
                    type: 'set',
                    qtyBefore: $oldQty,
                    qtyAfter: $newQty,
                    source: 'manual',
                    note: "Manual edit, qty $oldQty to $newQty",
                );
            }
        }

        if (class_exists(\Extensions\Warehousing\Services\WarehouseStockService::class)) {
            $defaultWh = \Extensions\Warehousing\Services\WarehouseStockService::getDefaultWarehouse();
            if ($defaultWh && isset($updatedAttrs['quantity'])) {
                $inv = \Extensions\Warehousing\Services\WarehouseStockService::getOrCreateInventory($defaultWh->id, (int) $id, 0);
                $inv->update(['quantity' => (int) $updatedAttrs['quantity']]);
            }
        }

        $returnUrl = $request->input('_return');
        if (is_string($returnUrl) && $this->isSafeLocalReturnUrl($returnUrl)) {
            return redirect($returnUrl)->with('status', $status);
        }

        return redirect()->route('products.index')->with('status', $status);
    }

    public function destroy($id)
    {
        $deleter = app(\App\Services\Catalog\ProductDeleter::class);
        $flash = $deleter->flash($deleter->delete([(int) $id]), 1);

        return redirect()->route('products.index')->with($flash['tone'], $flash['text']);
    }

    public function checkSkus(Request $request)
    {
        $skus = array_filter(array_map('trim', (array) $request->input('skus', [])));
        $excludeId = (int) $request->input('exclude_product_id', 0);

        $taken = [];
        foreach ($skus as $sku) {
            if ($sku === '') continue;
            $key = strtolower($sku);
            if (isset($taken[$key])) continue;

            $pq = DB::table('products')->where('sku', $sku);
            if ($excludeId) $pq->where('product_id', '!=', $excludeId);
            if ($pq->exists()) { $taken[$key] = 'product'; continue; }

            $oq = DB::table('product_variation_values')->where('sku', $sku);
            if ($excludeId) $oq->where('product_id', '!=', $excludeId);
            if ($oq->exists()) { $taken[$key] = 'variation_value'; continue; }
        }

        return response()->json(['taken' => (object) $taken]);
    }

    public function bulkAction(Request $request)
    {
        $action = (string) $request->input('action', '');
        $ids = $request->input('ids', []);

        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));

        if (empty($ids)) {
            return redirect()->route('products.index')->with('status', 'No items selected.');
        }

        if (!in_array($action, ['delete', 'enable', 'disable'], true)) {
            return redirect()->route('products.index')->with('status', 'Invalid action.');
        }

        if ($action === 'delete') {
            $deleter = app(\App\Services\Catalog\ProductDeleter::class);
            $flash = $deleter->flash($deleter->delete($ids), count($ids));

            return redirect()->route('products.index')->with($flash['tone'], $flash['text']);
        }

        $newStatus = $action === 'enable' ? 1 : 0;
        DB::table('products')->whereIn('product_id', $ids)->update(['status' => $newStatus]);

        return redirect()->route('products.index')->with('status', $newStatus === 1 ? 'Enabled selected products.' : 'Disabled selected products.');
    }

    

    private function variationWriter(): \App\Services\Catalog\VariationWriter
    {
        return app(\App\Services\Catalog\VariationWriter::class);
    }

    private function validateVariationSkus(int $productId, Request $request): void
    {
        $this->variationWriter()->validateSkus($productId, $request->input());
    }

    public function saveAbsoluteVariations(int $productId, Request $request, array &$skuChanges = []): void
    {
        $this->variationWriter()->save($productId, $request->input(), $skuChanges);
    }

    private function saveProductVariationTypes(int $productId, array $variationTypes, array &$skuChanges = []): void
    {

        $variationTypes = array_values(array_filter($variationTypes ?? [], fn ($o) => is_array($o)));

        $parentSku = strtolower(trim((string) DB::table('products')->where('product_id', $productId)->value('sku')));
        $skuRule = new UniqueSku($productId);
        $errors = [];
        $seenSkus = [];
        if ($parentSku !== '') {
            $seenSkus[$parentSku] = true;
        }
        foreach ($variationTypes as $oi => $variationType) {
            foreach (($variationType['values'] ?? []) as $vi => $v) {
                $sku = trim((string) ($v['sku'] ?? ''));
                if ($sku === '') continue;
                $skuLower = strtolower($sku);
                if ($skuLower === $parentSku) {
                    $errors[] = "The SKU \"{$sku}\" is already used as this product's parent SKU.";
                    continue;
                }
                if (isset($seenSkus[$skuLower])) {
                    $errors[] = "Duplicate SKU \"{$sku}\" within this product's variations.";
                    continue;
                }
                $seenSkus[$skuLower] = true;
                $skuRule->validate("variation_types.{$oi}.values.{$vi}.sku", $sku, function (string $msg) use (&$errors) {
                    $errors[] = $msg;
                });
            }
        }
        if (!empty($errors)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['variation_sku' => $errors]);
        }

        $existingPo = DB::table('product_variation_types')
            ->where('product_id', $productId)
            ->get()
            ->keyBy('variation_type_id');

        $existingPov = DB::table('product_variation_values')
            ->where('product_id', $productId)
            ->get()
            ->keyBy(fn ($r) => $r->variation_type_id . ':' . $r->variation_value_id);

        if (empty($variationTypes)) {
            if ($existingPov->isNotEmpty()) {
                DB::table('product_variation_values')->where('product_id', $productId)->delete();
            }
            if ($existingPo->isNotEmpty()) {
                DB::table('product_variation_types')->where('product_id', $productId)->delete();
            }
            return;
        }

        $supportedTypes = ['select', 'radio', 'checkbox', 'image'];

        $variationTypeIds = collect($variationTypes)->pluck('variation_type_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        $types = DB::table('variation_types')
            ->whereIn('variation_type_id', $variationTypeIds)
            ->pluck('type', 'variation_type_id');

        foreach ($variationTypeIds as $oid) {
            if (!isset($types[$oid])) {
                abort(422, 'Invalid variation_type_id: '.$oid);
            }
            if (!in_array((string) $types[$oid], $supportedTypes, true)) {
                abort(422, 'Unsupported variation type for variation_type_id '.$oid);
            }
        }

        $seenPoVariationTypeIds = [];
        $seenPovKeys = [];

        foreach ($variationTypes as $variationType) {
            $variationTypeId = (int) ($variationType['variation_type_id'] ?? 0);
            if ($variationTypeId < 1) {
                continue;
            }

            $required = (int) ($variationType['required'] ?? 0);
            $seenPoVariationTypeIds[] = $variationTypeId;

            if (isset($existingPo[$variationTypeId])) {
                $productVariationTypeId = (int) $existingPo[$variationTypeId]->product_variation_type_id;
                DB::table('product_variation_types')
                    ->where('product_variation_type_id', $productVariationTypeId)
                    ->update(['required' => $required]);
            } else {
                $productVariationTypeId = DB::table('product_variation_types')->insertGetId([
                    'product_id' => $productId,
                    'variation_type_id' => $variationTypeId,
                    'value' => '',
                    'required' => $required,
                ]);
            }

            $vals = $variationType['values'] ?? [];
            if (!is_array($vals)) {
                $vals = [];
            }

            foreach ($vals as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $ovId = (int) ($v['variation_value_id'] ?? 0);
                if ($ovId < 1) {
                    continue;
                }

                $key = $variationTypeId . ':' . $ovId;
                $seenPovKeys[] = $key;

                $optPrice   = (float) ($v['price'] ?? 0);
                $optCost    = (float) ($v['cost'] ?? 0);
                $costPrefix = (string) ($v['cost_prefix'] ?? '+');

                $data = [
                    'sku' => (string) ($v['sku'] ?? ''),
                    'quantity' => (int) ($v['quantity'] ?? 0),
                    'subtract' => (int) ($v['subtract'] ?? 1),
                    'price' => $optPrice,
                    'price_prefix' => (string) ($v['price_prefix'] ?? '+'),
                    'weight' => (float) ($v['weight'] ?? 0),
                    'weight_prefix' => (string) ($v['weight_prefix'] ?? '+'),
                    'cost' => $optCost,
                    'cost_prefix' => $costPrefix,
                    'cost_amount' => 0,
                    'cost_percentage' => 0,
                    'cost_additional' => 0,
                ];

                if (isset($existingPov[$key])) {
                    $oldOvQty = (int) ($existingPov[$key]->quantity ?? 0);
                    $newOvQty = $data['quantity'];
                    if ($oldOvQty !== $newOvQty) {
                        StockHistoryLogger::log(
                            productId: $productId,
                            variationValueId: (int) $existingPov[$key]->product_variation_value_id,
                            orderId: null,
                            type: 'set',
                            qtyBefore: $oldOvQty,
                            qtyAfter: $newOvQty,
                            source: 'manual',
                            note: "Manual edit (variation), qty $oldOvQty to $newOvQty",
                        );
                    }

                    $oldSku = trim((string) ($existingPov[$key]->sku ?? ''));
                    $newSku = $data['sku'];
                    if ($oldSku !== '' && $newSku !== $oldSku) {
                        DB::table('ch_shopee_listing_links')
                            ->where('product_id', $productId)
                            ->where('sku', $oldSku)
                            ->update(['sku' => $newSku]);

                        DB::table('ch_lazada_listing_variations')
                            ->whereIn('lazada_listing_id', function ($q) use ($productId) {
                                $q->select('id')->from('ch_lazada_listings')->where('product_id', $productId);
                            })
                            ->where('seller_sku', $oldSku)
                            ->update(['seller_sku' => $newSku]);

                        $povId = (int) $existingPov[$key]->product_variation_value_id;
                        $skuChanges['variation_skus'][$povId] = ['old' => $oldSku, 'new' => $newSku];
                    }

                    DB::table('product_variation_values')
                        ->where('product_variation_value_id', $existingPov[$key]->product_variation_value_id)
                        ->update(array_merge($data, ['product_variation_type_id' => $productVariationTypeId]));
                } else {
                    DB::table('product_variation_values')->insert(array_merge($data, [
                        'product_variation_type_id' => $productVariationTypeId,
                        'product_id' => $productId,
                        'variation_type_id' => $variationTypeId,
                        'variation_value_id' => $ovId,
                        'points' => 0,
                        'points_prefix' => '+',
                    ]));
                }
            }
        }

        $deletedPovIds = [];
        foreach ($existingPov as $key => $row) {
            if (!in_array($key, $seenPovKeys, true)) {
                $deletedPovIds[] = (int) $row->product_variation_value_id;
                DB::table('product_variation_values')->where('product_variation_value_id', $row->product_variation_value_id)->delete();
            }
        }

        foreach ($existingPo as $optId => $row) {
            if (!in_array((int) $optId, $seenPoVariationTypeIds, true)) {
                $groupPovIds = DB::table('product_variation_values')
                    ->where('product_variation_type_id', $row->product_variation_type_id)
                    ->pluck('product_variation_value_id')
                    ->map(fn ($v) => (int) $v)
                    ->all();
                $deletedPovIds = array_merge($deletedPovIds, $groupPovIds);

                DB::table('product_variation_values')->where('product_variation_type_id', $row->product_variation_type_id)->delete();
                DB::table('product_variation_types')->where('product_variation_type_id', $row->product_variation_type_id)->delete();
            }
        }

        if (!empty($deletedPovIds)) {
            DB::table('ch_lazada_listing_variations')
                ->whereIn('product_variation_value_id', $deletedPovIds)
                ->update(['product_variation_value_id' => null]);
        }
    }


}