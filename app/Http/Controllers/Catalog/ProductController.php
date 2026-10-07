<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Manufacturer;
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

    private function totalOptionsQuantity($options): ?int
    {
        if (!is_array($options) || empty($options)) return null;

        $sum = 0;
        $hasAnyValue = false;

        foreach ($options as $opt) {
            if (!is_array($opt)) continue;
            $values = $opt['values'] ?? [];
            if (!is_array($values)) continue;
            foreach ($values as $v) {
                if (!is_array($v)) continue;
                if (empty($v['option_value_id'])) continue;
                $hasAnyValue = true;
                $sum += (int) ($v['quantity'] ?? 0);
            }
        }

        return $hasAnyValue ? $sum : null;
    }

    private function totalAbsoluteOptionsQuantity(Request $request): ?int
    {
        return $this->variationWriter()->totalQuantity($request->input());
    }

    private function resolveManufacturerId(?int $manufacturerId, ?string $manufacturerName): int
    {
        return app(\App\Services\Catalog\ProductCreator::class)->resolveManufacturerId($manufacturerId, $manufacturerName);
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
    $p = config('catalog.prefix');
    $langId = (int) config('catalog.default_language_id');

    

        $pfx = config('catalog.prefix');
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

    $optQtySub = DB::table($p.'product_option_value as pov')
        ->select('pov.product_id', DB::raw('SUM(pov.quantity) as options_quantity'))
        ->groupBy('pov.product_id');

    $query = DB::table($p.'product as p')
        ->leftJoin($p.'product_description as pd', function ($j) use ($langId) {
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
            DB::raw('COALESCE(povsum.options_quantity, 0) as options_quantity'),
            'p.price',
            'p.status'
        );

    $status = (string) $request->input('status', '');
    if ($status === '0' || $status === '1') {
        $query->where('p.status', (int) $status);
    }

if ($q !== '') {
    $nameIds = DB::table($p.'product_description as pd')
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

    $skuMatched = DB::table($p.'product')
        ->where(function ($w) use ($q) {
            $w->where('sku', 'like', "%{$q}%")
              ->orWhere('model', 'like', "%{$q}%");
        })
        ->limit(800)
        ->pluck('product_id')
        ->map(fn($v) => (int) $v)
        ->toArray();

    $optSkuMatched = DB::table($p.'product_option_value')
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
    $optionRowsByProduct = \App\Support\VariationRows::forProducts($pageIds);
    foreach ($products as $row) {
        $row->option_rows = ($optionRowsByProduct->get((int) $row->product_id) ?? collect())->all();
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
$pfx = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $existingOptions = collect();
        $existingCombinations = [];

        $currencies = \App\Models\Currency::where('status', 1)->orderBy('code')->get();

        return view('catalog.products.create', compact('existingOptions', 'existingCombinations', 'currencies'));
    }


    
    private function manufacturerSlugFromId(int $manufacturerId): string
    {
        $manufacturerId = (int) $manufacturerId;
        if ($manufacturerId <= 0) {
            return '_no_manufacturer_';
        }

        $m = \App\Models\Catalog\Manufacturer::query()
            ->where('manufacturer_id', $manufacturerId)
            ->first(['name']);
        if (!$m) {
            return '_no_manufacturer_';
        }

        $slug = Str::slug((string) $m->name);
        return $slug !== '' ? $slug : '_no_manufacturer_';
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

    private function resolveManufacturerDir(int $manufacturerId): string
    {
        return app(\App\Services\Catalog\ProductCreator::class)->imageFolder($manufacturerId);
    }

    private function finalizeTempImages(string $token, string $manufacturerSlug, int $productId, array $items): array
    {
        $disk = Storage::disk('public');
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $token);
        $tmpBase = 'tmp/product-images/' . ($token ?: 'default');

        $pfx = config('catalog.prefix');
        $mid = (int) DB::table($pfx . 'product')->where('product_id', $productId)->value('manufacturer_id');
        $finalDir = $this->resolveManufacturerDir($mid);
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
            'manufacturer_id' => 'nullable|integer',
            'manufacturer_name' => 'nullable|string|max:255',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|min:1',
            'category_id' => 'nullable|integer',
            'category_name' => 'nullable|string|max:1024',
            'description' => ['nullable', 'string'],
            'images_json' => ['nullable', 'string'],
            'video_path'  => ['nullable', 'string', 'max:512'],
            'video_name'  => ['nullable', 'string', 'max:255'],
            'video_ai'    => ['nullable', 'boolean'],

            'options' => 'array',
            'options.*.option_id' => 'required|integer|min:1',
            'options.*.required' => 'nullable|in:0,1',
            'options.*.values' => 'array',
            'options.*.values.*.option_value_id' => 'required|integer|min:1',
            'options.*.values.*.sku' => 'nullable|string|max:64',
            'options.*.values.*.quantity' => 'nullable|integer',
            'options.*.values.*.subtract' => 'nullable|in:0,1',
            'options.*.values.*.price' => 'nullable|numeric',
            'options.*.values.*.price_prefix' => 'nullable|in:+,-',
            'options.*.values.*.weight' => 'nullable|numeric',
            'options.*.values.*.weight_prefix' => 'nullable|in:+,-',
            'options.*.values.*.cost_amount' => 'nullable|numeric',
            'options.*.values.*.cost_percentage' => 'nullable|numeric',
            'options.*.values.*.cost_additional' => 'nullable|numeric',
        ], [
            'sku.required' => 'A SKU is needed. Imports, stock pushes and link repair recognise this product by it.',
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

        $resolvedManufacturerId = $this->resolveManufacturerId(
            (int) $request->input('manufacturer_id', 0),
            (string) $request->input('manufacturer_name', '')
        );

        $categoryIds = array_filter(array_map('intval', $request->input('category_ids', [])));
        if (empty($categoryIds)) {
            $fallback = $this->resolveCategoryId(
                (int) $request->input('category_id', 0),
                (string) $request->input('category_name', '')
            );
            if ($fallback > 0) $categoryIds = [$fallback];
        }

        $forcedQty = $request->has('_options_format')
            ? $this->totalAbsoluteOptionsQuantity($request)
            : $this->totalOptionsQuantity($request->input('options', []));

        if ($request->has('_options_format')) {
            $this->validateOptionSkus(0, $request);
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
                'manufacturer_id' => (int) ($resolvedManufacturerId ?? 0),
                'category_ids' => $categoryIds,
                'description' => (string) $request->input('description', ''),
            ],
            variations: function (int $id) use ($request) {
                if ($request->has('_options_format')) {
                    $this->saveAbsoluteOptions($id, $request);
                } else {
                    $this->saveProductOptions($id, $request->input('options', []));
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
        $pfx = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->where('p.product_id', (int) $id)
            ->select('p.product_id', 'pd.name', 'p.sku')
            ->first();

        abort_if(!$product, 404);

        $product->name = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $sales = DB::table($pfx . 'order_product as op')
            ->join($pfx . 'order as o', 'o.order_id', '=', 'op.order_id')
            ->where('op.product_id', (int) $id)
            ->where('o.order_status_id', '>', 0)
            ->select(
                'op.order_product_id',
                'o.order_id',
                'o.date_added',
                'o.marketplace_source',
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
        $optionsByOpId = [];
        if (!empty($opIds)) {
            $options = DB::table($pfx . 'order_option')
                ->whereIn('order_product_id', $opIds)
                ->get(['order_product_id', 'name', 'value']);
            foreach ($options as $opt) {
                $optionsByOpId[(int) $opt->order_product_id][] = $opt;
            }
        }

        foreach ($sales as $s) {
            $s->options = $optionsByOpId[(int) $s->order_product_id] ?? [];
            $cost = (float) ($s->cost ?? 0) * (int) $s->quantity;
            $revenue = (float) $s->total;
            $profit = $revenue - $cost;
            $s->line_cost = $cost;
            $s->profit = $profit;
            $s->margin = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0;
            $s->markup = $cost > 0 ? round(($profit / $cost) * 100, 1) : 0;
        }

        $sourceLabelsMap = [];
        foreach (app(\App\Integrations\IntegrationRegistry::class)->availableMarketplaceSourceOptions() as $opt) {
            $sourceLabelsMap[$opt['value']] = $opt;
        }

        return view('catalog.products.sales', compact('product', 'sales', 'sourceLabelsMap'));
    }

    public function stockHistory($id)
    {
        $pfx = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->where('p.product_id', (int) $id)
            ->select('p.product_id', 'pd.name', 'p.sku', 'p.quantity')
            ->first();

        abort_if(!$product, 404);

        $product->name = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $optionValueNames = DB::table($pfx . 'product_option_value as pov')
            ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                    ->where('ovd.language_id', '=', $langId);
            })
            ->where('pov.product_id', (int) $id)
            ->pluck('ovd.name', 'pov.product_option_value_id')
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

        return view('catalog.products.stock_history', compact('product', 'history', 'optionValueNames', 'totalAdded', 'totalDeducted'));
    }

    public function edit($id)
    {
        $pfx = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table($pfx.'product as p')
            ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                  ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx.'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->select('p.*', 'pd.name as name', 'pd.description as description', 'm.name as manufacturer_name')
            ->where('p.product_id', (int) $id)
            ->first();

        abort_if(!$product, 404);

        $product->name = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $product->model = html_entity_decode($product->model ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $product->sku = html_entity_decode($product->sku ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Sanitize on display too: older rows may hold hostile HTML.
        $product->description = DescriptionHtml::store($product->description ?? '');


        $currentCategoryIds = DB::table($pfx.'product_to_category')
            ->where('product_id', (int) $id)
            ->pluck('category_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $currentCategories = [];
        if (!empty($currentCategoryIds)) {
            $currentCategories = DB::table($pfx.'category_description')
                ->whereIn('category_id', $currentCategoryIds)
                ->where('language_id', $langId)
                ->get(['category_id', 'name'])
                ->map(fn ($c) => ['id' => (int) $c->category_id, 'name' => $c->name])
                ->all();
        }

        $currentCategoryId = $currentCategoryIds[0] ?? 0;
        $categoryName = $currentCategories[0]['name'] ?? null;

        $manufacturerName = $product->manufacturer_name ?? null;

        $existingOptions = collect();

            $existingOptions = DB::table($pfx.'product_option as po')
                ->join($pfx.'option as o', 'po.option_id', '=', 'o.option_id')
                ->join($pfx.'option_description as od', function ($j) use ($langId) {
                    $j->on('po.option_id', '=', 'od.option_id')
                      ->where('od.language_id', '=', $langId);
                })
                ->where('po.product_id', (int) $id)
                ->orderBy('o.sort_order')
                ->orderBy('od.name')
                ->get([
                    'po.product_option_id',
                    'po.option_id',
                    'po.required',
                    'po.value',
                    'o.type',
                    'od.name',
                ]);

            $poIds = $existingOptions->pluck('product_option_id')->map(fn ($v) => (int)$v)->all();

            $existingValuesByPoId = [];
            if (!empty($poIds)) {
                $rows = DB::table($pfx.'product_option_value as pov')
                    ->join($pfx.'option_value_description as ovd', function ($j) use ($langId) {
                        $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                          ->where('ovd.language_id', '=', $langId);
                    })
                    ->whereIn('pov.product_option_id', $poIds)
                    ->orderBy('pov.product_option_value_id')
                    ->get([
                        'pov.product_option_value_id',
                        'pov.product_option_id',
                        'pov.option_value_id',
                        'ovd.name as option_value_name',
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
                    $r->option_value_name = html_entity_decode((string)$r->option_value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $existingValuesByPoId[(int)$r->product_option_id][] = [
                        'product_option_value_id' => (int)$r->product_option_value_id,
                        'option_value_id' => (int)$r->option_value_id,
                        'option_value_name' => $r->option_value_name,
                        'name' => $r->option_value_name,
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
            $comboImages = DB::table('product_option_combinations as c')
                ->join('product_option_combination_values as cv', 'cv.combination_id', '=', 'c.id')
                ->where('c.product_id', (int) $id)
                ->get(['cv.product_option_value_id', 'c.image', 'c.status', 'c.cost_amount', 'c.cost_additional']);

            foreach ($comboImages as $row) {
                $statusByPovId[(int) $row->product_option_value_id] ??= (int) ($row->status ?? 1);
                $costAmountByPovId[(int) $row->product_option_value_id] ??= (float) ($row->cost_amount ?? 0);
                $costAddByPovId[(int) $row->product_option_value_id] ??= (float) ($row->cost_additional ?? 0);

                if (trim((string) ($row->image ?? '')) === '') {
                    continue;
                }

                $povId = (int) $row->product_option_value_id;
                $imageByPovId[$povId] = isset($imageByPovId[$povId])
                    ? $imageByPovId[$povId]
                    : (string) $row->image;
            }

            foreach ($existingValuesByPoId as $poId => $values) {
                foreach ($values as $i => $value) {
                    $povId = (int) $value['product_option_value_id'];
                    $existingValuesByPoId[$poId][$i]['image'] = $imageByPovId[$povId] ?? '';
                    $existingValuesByPoId[$poId][$i]['status'] = $statusByPovId[$povId] ?? 1;
                    $existingValuesByPoId[$poId][$i]['cost_amount'] = $costAmountByPovId[$povId]
                        ?? (float) $value['absolute_cost'];
                    $existingValuesByPoId[$poId][$i]['cost_additional'] = $costAddByPovId[$povId]
                        ?? (float) ($value['cost_additional'] ?? 0);
                }
            }

            foreach ($existingOptions as $o) {
                $o->name = html_entity_decode((string)$o->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $o->option_name = $o->name;
                $o->values = $existingValuesByPoId[(int)$o->product_option_id] ?? [];
            }

            $existingCombinations = [];
            if ($existingOptions->count() > 1) {
                $combos = DB::table('product_option_combinations as c')
                    ->where('c.product_id', (int) $id)
                    ->orderBy('c.sort_order')
                    ->get();

                foreach ($combos as $combo) {
                    $pivotRows = DB::table('product_option_combination_values as cv')
                        ->join($pfx.'product_option_value as pov', 'cv.product_option_value_id', '=', 'pov.product_option_value_id')
                        ->join($pfx.'option_value_description as ovd', function ($j) use ($langId) {
                            $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                              ->where('ovd.language_id', '=', $langId);
                        })
                        ->join($pfx.'product_option as po', 'pov.product_option_id', '=', 'po.product_option_id')
                        ->where('cv.combination_id', $combo->id)
                        ->orderBy('po.product_option_id')
                        ->get(['ovd.name', 'po.product_option_id']);

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
        $additionalImgs = DB::table($pfx.'product_image')
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

        return view('catalog.products.edit', compact('product', 'currentCategoryId', 'categoryName', 'currentCategories', 'manufacturerName', 'existingOptions', 'existingCombinations', 'productImages', 'productVideo', 'returnUrl', 'currencies', 'channelNote'));
    }

    public function update(Request $request, $id)
    {
        $pfxProduct = config('catalog.prefix') . 'product';

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
            'manufacturer_id' => 'nullable|integer',
            'manufacturer_name' => 'nullable|string|max:255',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|min:1',
            'category_id' => 'nullable|integer',
            'category_name' => 'nullable|string|max:1024',
            'description' => ['nullable', 'string'],
            'images_json' => ['nullable', 'string'],
            'video_path'  => ['nullable', 'string', 'max:512'],
            'video_name'  => ['nullable', 'string', 'max:255'],
            'video_ai'    => ['nullable', 'boolean'],

            'options' => 'array',
            'options.*.option_id' => 'required|integer|min:1',
            'options.*.required' => 'nullable|in:0,1',
            'options.*.values' => 'array',
            'options.*.values.*.option_value_id' => 'required|integer|min:1',
            'options.*.values.*.sku' => 'nullable|string|max:64',
            'options.*.values.*.quantity' => 'nullable|integer',
            'options.*.values.*.subtract' => 'nullable|in:0,1',
            'options.*.values.*.price' => 'nullable|numeric',
            'options.*.values.*.price_prefix' => 'nullable|in:+,-',
            'options.*.values.*.weight' => 'nullable|numeric',
            'options.*.values.*.weight_prefix' => 'nullable|in:+,-',
            'options.*.values.*.cost_amount' => 'nullable|numeric',
            'options.*.values.*.cost_percentage' => 'nullable|numeric',
            'options.*.values.*.cost_additional' => 'nullable|numeric',
        ], [
            'sku.required' => 'A SKU is needed. Imports, stock pushes and link repair recognise this product by it.',
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

        if ($request->has('_options_format')) {
            $this->validateOptionSkus((int) $id, $request);
        }

        $langId = (int) config('catalog.default_language_id');

        $resolvedManufacturerId = $this->resolveManufacturerId($request->manufacturer_id, $request->manufacturer_name);

        $categoryIds = array_filter(array_map('intval', $request->input('category_ids', [])));
        if (empty($categoryIds)) {
            $fallback = $this->resolveCategoryId($request->category_id, $request->category_name);
            if ($fallback > 0) $categoryIds = [$fallback];
        }

        $pfx = config('catalog.prefix');

        $forcedQty = $request->has('_options_format')
            ? $this->totalAbsoluteOptionsQuantity($request)
            : $this->totalOptionsQuantity($request->input('options', []));

        $skuChanges = ['product_sku' => null, 'option_skus' => []];
        $originalAttrs = null;
        $updatedAttrs = null;
        $variationsBefore = \App\Services\VariationForgetService::snapshot((int) $id);
        DB::transaction(function () use ($request, $id, $langId, $pfx, $resolvedManufacturerId, $categoryIds, $forcedQty, &$skuChanges, &$originalAttrs, &$updatedAttrs) {
            $p = Product::where('product_id', (int) $id)->firstOrFail();
            $originalAttrs = $p->getAttributes();

            $oldManufacturerId = (int) ($p->manufacturer_id ?? 0);

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
            $p->manufacturer_id = (int) ($resolvedManufacturerId ?? 0);
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







            $d = DB::table($pfx.'product_description')
                ->where('product_id', (int) $id)
                ->where('language_id', (int) $langId)
                ->first();

            DB::table($pfx.'product_description')->updateOrInsert(
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

            DB::table($pfx.'product_to_category')->where('product_id', (int) $id)->delete();
            foreach ($categoryIds as $catId) {
                ProductToCategory::create([
                    'product_id' => (int) $id,
                    'category_id' => $catId,
                ]);
            }

            if ($request->has('_options_format')) {
                $this->saveAbsoluteOptions((int) $id, $request, $skuChanges);
            } else {
                $options = $request->input('options', []);
                $this->saveProductOptions((int) $id, $options, $skuChanges);
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
        if (!empty($skuChanges['product_sku']) || !empty($skuChanges['option_skus'])) {
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
            ? ActivityLogger::diff($originalAttrs, $updatedAttrs, ['sku', 'price', 'quantity', 'status', 'model', 'manufacturer_id'])
            : null;
        ActivityLogger::log('updated', 'Product', (int) $id, $request->name . ' (SKU: ' . ($request->sku ?? '') . ')', $changes);

        if ($originalAttrs && $updatedAttrs) {
            $oldQty = (int) ($originalAttrs['quantity'] ?? 0);
            $newQty = (int) ($updatedAttrs['quantity'] ?? 0);
            if ($oldQty !== $newQty) {
                StockHistoryLogger::log(
                    productId: (int) $id,
                    optionValueId: null,
                    orderId: null,
                    type: 'set',
                    qtyBefore: $oldQty,
                    qtyAfter: $newQty,
                    source: 'manual',
                    note: "Manual edit, qty $oldQty to $newQty",
                );
            }
        }

        if (class_exists(\Extensions\warehousing\Services\WarehouseStockService::class)) {
            $defaultWh = \Extensions\warehousing\Services\WarehouseStockService::getDefaultWarehouse();
            if ($defaultWh && isset($updatedAttrs['quantity'])) {
                $inv = \Extensions\warehousing\Services\WarehouseStockService::getOrCreateInventory($defaultWh->id, (int) $id, 0);
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
        $p = (string) config('catalog.prefix');

        $taken = [];
        foreach ($skus as $sku) {
            if ($sku === '') continue;
            $key = strtolower($sku);
            if (isset($taken[$key])) continue;

            $pq = DB::table($p . 'product')->where('sku', $sku);
            if ($excludeId) $pq->where('product_id', '!=', $excludeId);
            if ($pq->exists()) { $taken[$key] = 'product'; continue; }

            $oq = DB::table($p . 'product_option_value')->where('sku', $sku);
            if ($excludeId) $oq->where('product_id', '!=', $excludeId);
            if ($oq->exists()) { $taken[$key] = 'option_value'; continue; }
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

        $pfx = config('catalog.prefix');

        if ($action === 'delete') {
            $deleter = app(\App\Services\Catalog\ProductDeleter::class);
            $flash = $deleter->flash($deleter->delete($ids), count($ids));

            return redirect()->route('products.index')->with($flash['tone'], $flash['text']);
        }

        $newStatus = $action === 'enable' ? 1 : 0;
        DB::table($pfx.'product')->whereIn('product_id', $ids)->update(['status' => $newStatus]);

        return redirect()->route('products.index')->with('status', $newStatus === 1 ? 'Enabled selected products.' : 'Disabled selected products.');
    }

    

    private function variationWriter(): \App\Services\Catalog\VariationWriter
    {
        return app(\App\Services\Catalog\VariationWriter::class);
    }

    private function validateOptionSkus(int $productId, Request $request): void
    {
        $this->variationWriter()->validateSkus($productId, $request->input());
    }

    public function saveAbsoluteOptions(int $productId, Request $request, array &$skuChanges = []): void
    {
        $this->variationWriter()->save($productId, $request->input(), $skuChanges);
    }

    private function saveProductOptions(int $productId, array $options, array &$skuChanges = []): void
    {
        $p = config('catalog.prefix');

        $options = array_values(array_filter($options ?? [], fn ($o) => is_array($o)));

        $parentSku = strtolower(trim((string) DB::table($p . 'product')->where('product_id', $productId)->value('sku')));
        $skuRule = new UniqueSku($productId);
        $errors = [];
        $seenSkus = [];
        if ($parentSku !== '') {
            $seenSkus[$parentSku] = true;
        }
        foreach ($options as $oi => $opt) {
            foreach (($opt['values'] ?? []) as $vi => $v) {
                $sku = trim((string) ($v['sku'] ?? ''));
                if ($sku === '') continue;
                $skuLower = strtolower($sku);
                if ($skuLower === $parentSku) {
                    $errors[] = "The SKU \"{$sku}\" is already used as this product's parent SKU.";
                    continue;
                }
                if (isset($seenSkus[$skuLower])) {
                    $errors[] = "Duplicate SKU \"{$sku}\" within this product's options.";
                    continue;
                }
                $seenSkus[$skuLower] = true;
                $skuRule->validate("options.{$oi}.values.{$vi}.sku", $sku, function (string $msg) use (&$errors) {
                    $errors[] = $msg;
                });
            }
        }
        if (!empty($errors)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['option_sku' => $errors]);
        }

        $existingPo = DB::table($p.'product_option')
            ->where('product_id', $productId)
            ->get()
            ->keyBy('option_id');

        $existingPov = DB::table($p.'product_option_value')
            ->where('product_id', $productId)
            ->get()
            ->keyBy(fn ($r) => $r->option_id . ':' . $r->option_value_id);

        if (empty($options)) {
            if ($existingPov->isNotEmpty()) {
                DB::table($p.'product_option_value')->where('product_id', $productId)->delete();
            }
            if ($existingPo->isNotEmpty()) {
                DB::table($p.'product_option')->where('product_id', $productId)->delete();
            }
            return;
        }

        $supportedTypes = ['select', 'radio', 'checkbox', 'image'];

        $optionIds = collect($options)->pluck('option_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        $types = DB::table($p.'option')
            ->whereIn('option_id', $optionIds)
            ->pluck('type', 'option_id');

        foreach ($optionIds as $oid) {
            if (!isset($types[$oid])) {
                abort(422, 'Invalid option_id: '.$oid);
            }
            if (!in_array((string) $types[$oid], $supportedTypes, true)) {
                abort(422, 'Unsupported option type for option_id '.$oid);
            }
        }

        $seenPoOptionIds = [];
        $seenPovKeys = [];

        foreach ($options as $opt) {
            $optionId = (int) ($opt['option_id'] ?? 0);
            if ($optionId < 1) {
                continue;
            }

            $required = (int) ($opt['required'] ?? 0);
            $seenPoOptionIds[] = $optionId;

            if (isset($existingPo[$optionId])) {
                $productOptionId = (int) $existingPo[$optionId]->product_option_id;
                DB::table($p.'product_option')
                    ->where('product_option_id', $productOptionId)
                    ->update(['required' => $required]);
            } else {
                $productOptionId = DB::table($p.'product_option')->insertGetId([
                    'product_id' => $productId,
                    'option_id' => $optionId,
                    'value' => '',
                    'required' => $required,
                ]);
            }

            $vals = $opt['values'] ?? [];
            if (!is_array($vals)) {
                $vals = [];
            }

            foreach ($vals as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $ovId = (int) ($v['option_value_id'] ?? 0);
                if ($ovId < 1) {
                    continue;
                }

                $key = $optionId . ':' . $ovId;
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
                            optionValueId: (int) $existingPov[$key]->product_option_value_id,
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
                        DB::table('shopee_product_links')
                            ->where('product_id', $productId)
                            ->where('sku', $oldSku)
                            ->update(['sku' => $newSku]);

                        DB::table('lazada_product_variants')
                            ->whereIn('lazada_product_id', function ($q) use ($productId) {
                                $q->select('id')->from('lazada_products')->where('product_id', $productId);
                            })
                            ->where('seller_sku', $oldSku)
                            ->update(['seller_sku' => $newSku]);

                        $povId = (int) $existingPov[$key]->product_option_value_id;
                        $skuChanges['option_skus'][$povId] = ['old' => $oldSku, 'new' => $newSku];
                    }

                    DB::table($p.'product_option_value')
                        ->where('product_option_value_id', $existingPov[$key]->product_option_value_id)
                        ->update(array_merge($data, ['product_option_id' => $productOptionId]));
                } else {
                    DB::table($p.'product_option_value')->insert(array_merge($data, [
                        'product_option_id' => $productOptionId,
                        'product_id' => $productId,
                        'option_id' => $optionId,
                        'option_value_id' => $ovId,
                        'points' => 0,
                        'points_prefix' => '+',
                    ]));
                }
            }
        }

        $deletedPovIds = [];
        foreach ($existingPov as $key => $row) {
            if (!in_array($key, $seenPovKeys, true)) {
                $deletedPovIds[] = (int) $row->product_option_value_id;
                DB::table($p.'product_option_value')->where('product_option_value_id', $row->product_option_value_id)->delete();
            }
        }

        foreach ($existingPo as $optId => $row) {
            if (!in_array((int) $optId, $seenPoOptionIds, true)) {
                $groupPovIds = DB::table($p.'product_option_value')
                    ->where('product_option_id', $row->product_option_id)
                    ->pluck('product_option_value_id')
                    ->map(fn ($v) => (int) $v)
                    ->all();
                $deletedPovIds = array_merge($deletedPovIds, $groupPovIds);

                DB::table($p.'product_option_value')->where('product_option_id', $row->product_option_id)->delete();
                DB::table($p.'product_option')->where('product_option_id', $row->product_option_id)->delete();
            }
        }

        if (!empty($deletedPovIds)) {
            DB::table('lazada_product_variants')
                ->whereIn('product_option_value_id', $deletedPovIds)
                ->update(['product_option_value_id' => null]);
        }
    }


}