<?php

namespace App\Http\Controllers;

use App\Models\Catalog\Manufacturer;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDescription;
use App\Models\Catalog\ProductToCategory;
use App\Models\Catalog\ProductImage;
use App\Rules\UniqueSku;
use App\Services\ActivityLogger;
use App\Services\StockHistoryLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;


class ProductController extends Controller
{
    /**
     * When options exist, OpenCart typically treats option quantities as the source of truth.
     * We force product.quantity to be the sum of option value quantities.
     */
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
        $values = $request->input('values', []);
        if (is_array($values) && !empty($values)) {
            return array_sum(array_map(fn($v) => (int) ($v['quantity'] ?? 0), $values));
        }

        $combos = $request->input('combinations', []);
        if (is_array($combos) && !empty($combos)) {
            return array_sum(array_map(fn($c) => (int) ($c['quantity'] ?? 0), $combos));
        }

        return null;
    }

    private function resolveManufacturerId(?int $manufacturerId, ?string $manufacturerName): int
    {
        $manufacturerId = (int) ($manufacturerId ?? 0);
        if ($manufacturerId > 0) return $manufacturerId;

        $name = trim((string) ($manufacturerName ?? ''));
        if ($name === '') return 0;

        $row = Manufacturer::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name, 'UTF-8')])
            ->first(['manufacturer_id']);

        return $row ? (int) $row->manufacturer_id : 0;
    }

    private function resolveCategoryId(?int $categoryId, ?string $categoryName): int
    {
        $categoryId = (int) ($categoryId ?? 0);
        if ($categoryId > 0) return $categoryId;

        $name = trim((string) ($categoryName ?? ''));
        if ($name === '') return 0;

        $p = config('catalog.prefix');
        $lang = (int) config('catalog.default_language_id');
        $table = $p . 'category_description';

        // If user typed a path like "A > B > C", match on the last segment first, then full name
        $last = trim(preg_split('/\s*>\s*/', $name)[-1] ?? $name);

        $row = \Illuminate\Support\Facades\DB::table($table)
            ->where('language_id', $lang)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($last, 'UTF-8')])
            ->first(['category_id']);

        return $row ? (int) $row->category_id : 0;
    }

private function isWordStartMatch(string $haystack, string $term): bool
{
    $h = mb_strtolower($haystack, 'UTF-8');
    $t = mb_strtolower(trim($term), 'UTF-8');
    if ($t === '') return false;

    return (bool) preg_match('/(^|[^a-z0-9])' . preg_quote($t, '/') . '/i', $h);
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

        // de-dup while preserving order
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
        $pfx = config('catalog.prefix');

        // Update main image
        $main = $paths[0] ?? '';
        DB::table($pfx.'product')->where('product_id', (int)$productId)->update([
            'image' => $main,
        ]);

        // Rebuild additional images (OpenCart behavior: exclude main)
        DB::table($pfx.'product_image')->where('product_id', (int)$productId)->delete();

        $additional = array_slice($paths, 1);
        $sort = 0;
        foreach ($additional as $img) {
            DB::table($pfx.'product_image')->insert([
                'product_id' => (int)$productId,
                'image' => $img,
                'sort_order' => $sort,
            ]);
            $sort++;
        }
    }

public function index(Request $request)
{
    $p = config('catalog.prefix');
    $langId = (int) config('catalog.default_language_id');

    

        $pfx = config('catalog.prefix');
$q = trim((string) $request->get('q', ''));

    // Sorting
    $sort = (string) $request->get('sort', 'product_id');
    $dir = strtolower((string) $request->get('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

    $allowedSort = [
        'product_id'   => 'p.product_id',
        'name'         => 'pd.name',
        'quantity'     => 'p.quantity',
        'price'        => 'p.price',
        'status'       => 'p.status',
    ];

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

        // Status filter
    $status = (string) $request->input('status', '');
    if ($status === '0' || $status === '1') {
        $query->where('p.status', (int) $status);
    }

if ($q !== '') {
    // Search by name (word-start match), SKU, model, or option SKU
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

    // SKU / model match on core product
    $skuMatched = DB::table($p.'product')
        ->where(function ($w) use ($q) {
            $w->where('sku', 'like', "%{$q}%")
              ->orWhere('model', 'like', "%{$q}%");
        })
        ->limit(800)
        ->pluck('product_id')
        ->map(fn($v) => (int) $v)
        ->toArray();

    // Option SKU match (product_option_value.sku)
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

    // Default: Product ID ASC
    $query->orderBy($allowedSort[$sort], $dir);

    $products = $query->paginate(50)->withQueryString();

    foreach ($products as $row) {
        if (isset($row->name)) $row->name = html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // Fetch combination rows for products on this page (for list display)
    $pageIds = $products->pluck('product_id')->map(fn($v) => (int)$v)->all();
    $optionRowsByProduct = [];
    if (!empty($pageIds)) {
        $comboRows = DB::table('product_option_combinations as c')
            ->whereIn('c.product_id', $pageIds)
            ->orderBy('c.product_id')
            ->orderBy('c.sort_order')
            ->get(['c.id', 'c.product_id', 'c.sku', 'c.quantity', 'c.absolute_price']);

        // Load pivot → option value names + option group names for each combo
        $comboIds = $comboRows->pluck('id')->all();
        $pivotData = []; // combo_id → [{value_name, option_name}]
        if (!empty($comboIds)) {
            $pivots = DB::table('product_option_combination_values as cv')
                ->join($p.'product_option_value as pov', 'cv.product_option_value_id', '=', 'pov.product_option_value_id')
                ->join($p.'option_value_description as ovd', function ($j) use ($langId) {
                    $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                      ->where('ovd.language_id', '=', $langId);
                })
                ->join($p.'option_description as od', function ($j) use ($langId) {
                    $j->on('pov.option_id', '=', 'od.option_id')
                      ->where('od.language_id', '=', $langId);
                })
                ->join($p.'product_option as po', 'pov.product_option_id', '=', 'po.product_option_id')
                ->whereIn('cv.combination_id', $comboIds)
                ->orderBy('po.product_option_id')
                ->get(['cv.combination_id', 'ovd.name as value_name', 'od.name as option_name']);

            foreach ($pivots as $pv) {
                $pivotData[(int) $pv->combination_id][] = [
                    'value_name' => html_entity_decode((string) $pv->value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'option_name' => html_entity_decode((string) $pv->option_name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ];
            }
        }

        foreach ($comboRows as $c) {
            $pid = (int) $c->product_id;
            $entries = $pivotData[(int) $c->id] ?? [];
            $valueNames = array_map(fn($e) => $e['value_name'], $entries);
            $optionNames = array_unique(array_map(fn($e) => $e['option_name'], $entries));
            $c->option_value_name = implode(' + ', $valueNames);
            $c->option_name = implode(' / ', $optionNames);
            $c->option_image = null;
            $optionRowsByProduct[$pid] ??= [];
            $optionRowsByProduct[$pid][] = $c;
        }

        foreach ($products as $row) {
            $pid = (int) $row->product_id;
            $row->option_rows = $optionRowsByProduct[$pid] ?? [];
        }
    } else {
        foreach ($products as $row) {
            $row->option_rows = [];
        }
    }
    return view('products.index', compact('products', 'q', 'sort', 'dir', 'status'));
}


    public function create()
    {

        $productImages = [];
$pfx = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $existingOptions = collect();
        $existingCombinations = [];

        $currencies = \App\Models\Currency::where('status', 1)->orderBy('code')->get();

        return view('products.create', compact('existingOptions', 'existingCombinations', 'currencies'));
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
        $disk = Storage::disk('public');

        if ($manufacturerId > 0) {
            $m = Manufacturer::query()->where('manufacturer_id', $manufacturerId)->first(['name']);
            if ($m && $m->name) {
                $name = (string) $m->name;
                $slug = Str::slug($name);

                // Check exact name, then slug, then case-insensitive scan
                if ($disk->exists('catalog/' . $name)) return 'catalog/' . $name;
                if ($slug !== '' && $disk->exists('catalog/' . $slug)) return 'catalog/' . $slug;

                $nameLower = mb_strtolower($name);
                $slugLower = mb_strtolower($slug);
                foreach ($disk->directories('catalog') as $dir) {
                    $folder = basename($dir);
                    $folderLower = mb_strtolower($folder);
                    if ($folderLower === $nameLower || $folderLower === $slugLower) {
                        return 'catalog/' . $folder;
                    }
                }

                // No existing folder — create from original name
                return 'catalog/' . $name;
            }
        }

        return 'catalog/_no_manufacturer_';
    }

    private function finalizeTempImages(string $token, string $manufacturerSlug, int $productId, array $items): array
    {
        $disk = Storage::disk('public');
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $token);
        $tmpBase = 'tmp/product-images/' . ($token ?: 'default');

        // Resolve manufacturer folder from the product's manufacturer_id
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

            // Only normalize local catalog paths (not temp, not remote)
            if (!Str::startsWith($p, 'catalog/')) {
                $out[] = $p;
                continue;
            }

            // Already in correct folder
            if (Str::startsWith($p, $targetPrefix)) {
                $out[] = $p;
                continue;
            }

            // If file doesn't exist, keep path (avoid breaking)
            if (!$disk->exists($p)) {
                $out[] = $p;
                continue;
            }

            $dest = $this->uniqueTargetPath($targetDir, basename($p));
            $disk->move($p, $dest);
            $out[] = $dest;
        }

        // de-dup
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
        $pfx = config('catalog.prefix');
        $pfxProduct = $pfx . 'product';

        $request->validate([
            'name' => 'required|string|max:255',
            'model' => 'required|string|max:64',
            'sku' => ['required', 'string', 'max:64', new UniqueSku()],
            'price' => 'required|numeric',
            'cost_amount' => 'nullable|numeric',
            'cost_percentage' => 'nullable|numeric',
            'cost_additional' => 'nullable|numeric',
            'quantity' => 'required|integer',
            // Physical attributes (needed for marketplace payloads e.g. Lazada)
            'weight' => 'nullable|numeric',
            'length' => 'nullable|numeric',
            'width'  => 'nullable|numeric',
            'height' => 'nullable|numeric',
            'status' => 'required|in:0,1',
            'manufacturer_id' => 'nullable|integer',
            'manufacturer_name' => 'nullable|string|max:255',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|min:1',
            'category_id' => 'nullable|integer',
            'category_name' => 'nullable|string|max:1024',
            'description' => 'nullable|string',
            'images_json' => 'nullable|string',

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
        ]);

        $langId = (int) config('catalog.default_language_id');

        $resolvedManufacturerId = $this->resolveManufacturerId(
            (int) $request->input('manufacturer_id', 0),
            (string) $request->input('manufacturer_name', '')
        );

        // Multi-category: prefer category_ids[], fall back to single category_id
        $categoryIds = array_filter(array_map('intval', $request->input('category_ids', [])));
        if (empty($categoryIds)) {
            $fallback = $this->resolveCategoryId(
                (int) $request->input('category_id', 0),
                (string) $request->input('category_name', '')
            );
            if ($fallback > 0) $categoryIds = [$fallback];
        }

        // If options exist, quantity must be forced from option quantities (server-side enforcement).
        $forcedQty = $request->has('_options_format')
            ? $this->totalAbsoluteOptionsQuantity($request)
            : $this->totalOptionsQuantity($request->input('options', []));

        $productId = DB::transaction(function () use ($request, $langId, $resolvedManufacturerId, $categoryIds, $pfx, $forcedQty) {
            $p = new Product();

            $p->model = $request->model ?? ($request->sku ?? '');
            $p->sku = $request->sku ?? '';
            $p->upc = '';
            $p->ean = '';
            $p->jan = '';
            $p->isbn = '';
            $p->mpn = '';
            $p->location = '';

            $p->quantity = $forcedQty !== null ? (int) $forcedQty : (int) $request->quantity;
            $p->reorder_level = (int) ($request->reorder_level ?? 0);
            $p->weight = (float) ($request->input('weight', 0) ?? 0);
            $p->length = (float) ($request->input('length', 0) ?? 0);
            $p->width  = (float) ($request->input('width', 0) ?? 0);
            $p->height = (float) ($request->input('height', 0) ?? 0);
            $p->stock_status_id = 0;
            $p->image = null;
            $p->manufacturer_id = (int) ($resolvedManufacturerId ?? 0);
            $p->shipping = 1;
            $p->status = (int) $request->input('status', 1);

            $p->price = (float) $request->price;
            $p->cost_amount = (float) ($request->cost_amount ?? 0);
            $p->cost_percentage = (float) ($request->cost_percentage ?? 0);
            $p->cost_additional = (float) ($request->cost_additional ?? 0);
            $p->cost = $p->cost_amount + ($p->cost_percentage / 100 * $p->price) + $p->cost_additional;
            $p->points = 0;
            $p->tax_class_id = 0;

            // Ensure strict-mode safe date_available (OpenCart sets it automatically)
            $da = $p->date_available;
            if (!$da || $da === '0000-00-00') {
                $p->date_available = now()->toDateString();
            }

            $p->save();

                        DB::table($pfx.'product_description')->insert([
                'product_id' => (int) $p->product_id,
                'language_id' => (int) $langId,
                'name' => (string) $request->name,
                'description' => (string) ($request->description ?? ''),
                'meta_title' => (string) $request->name,
                'meta_description' => '',
                'meta_keyword' => '',
                'tag' => '',
            ]);
            foreach ($categoryIds as $catId) {
                ProductToCategory::create([
                    'product_id' => $p->product_id,
                    'category_id' => $catId,
                ]);
            }

            // Save product options
            if ($request->has('_options_format')) {
                $this->saveAbsoluteOptions((int) $p->product_id, $request);
            } else {
                $options = $request->input('options', []);
                $this->saveProductOptions((int) $p->product_id, $options);
            }

            // Save product images (main + additional)
            if ($request->has('images_json')) {
                $paths = $this->imagesFromRequest($request->input('images_json'));
                $token = (string) $request->input('pim_token', '');
                if ($token !== '' && !empty($paths)) {
                    $items = array_map(fn($pth) => ['path' => $pth], $paths);
                    $finalized = $this->finalizeTempImages($token, '', (int) $p->product_id, $items);
                    $paths = array_map(fn($it) => $it['path'], $finalized);
                }
                $this->saveProductImages((int) $p->product_id, $paths);
            }

            return (int) $p->product_id;
        });

        ActivityLogger::log('created', 'Product', $productId, $request->name . ' (SKU: ' . ($request->sku ?? '') . ')');

        // Log initial stock
        $finalQty = (int) DB::table(config('catalog.prefix') . 'product')->where('product_id', $productId)->value('quantity');
        if ($finalQty > 0) {
            StockHistoryLogger::log(
                productId: $productId,
                optionValueId: null,
                orderId: null,
                type: 'set',
                qtyBefore: 0,
                qtyAfter: $finalQty,
                source: 'manual',
                note: "Product created — initial stock $finalQty",
            );
        }

        return redirect()->route('products.index')
            ->with('status','Product created.');
    }

    public function salesHistory($id)
    {
        $pfx = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        // Get product name
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

        // Get all order_product rows for this product
        $sales = DB::table($pfx . 'order_product as op')
            ->join($pfx . 'order as o', 'o.order_id', '=', 'op.order_id')
            ->where('op.product_id', (int) $id)
            ->where('o.order_status_id', '>', 0)
            ->select(
                'op.order_product_id',
                'o.order_id',
                'o.date_added',
                'op.name',
                'op.quantity',
                'op.price',
                'op.total',
                'op.cost'
            )
            ->orderByDesc('o.date_added')
            ->paginate(50)
            ->withQueryString();

        // Get options for these order products
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

        // Compute profitability for each row
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

        return view('products.sales', compact('product', 'sales'));
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

        // Get option value names for display
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

        // Summaries
        $totalAdded = DB::table('stock_history')
            ->where('product_id', (int) $id)
            ->where('quantity_change', '>', 0)
            ->sum('quantity_change');

        $totalDeducted = DB::table('stock_history')
            ->where('product_id', (int) $id)
            ->where('quantity_change', '<', 0)
            ->sum('quantity_change');

        return view('products.stock_history', compact('product', 'history', 'optionValueNames', 'totalAdded', 'totalDeducted'));
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

        // Decode HTML entities stored in DB (e.g. &amp;)
        $product->name = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $product->model = html_entity_decode($product->model ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $product->sku = html_entity_decode($product->sku ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');


        // Load all assigned categories for this product
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

        // Backward compat: single category for old references
        $currentCategoryId = $currentCategoryIds[0] ?? 0;
        $categoryName = $currentCategories[0]['name'] ?? null;

        $manufacturerName = $product->manufacturer_name ?? null;

        $existingOptions = collect();

            // Existing product options + values
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
                        'weight_prefix' => (string)($r->weight_prefix ?? '+'),
                        'weight' => (string)($r->weight ?? 0),
                        'sku' => (string)($r->sku ?? ''),
                        'cost' => (string)($r->cost ?? 0),
                        'cost_prefix' => (string)($r->cost_prefix ?? '+'),
                    ];
                }
            }

            foreach ($existingOptions as $o) {
                $o->name = html_entity_decode((string)$o->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $o->option_name = $o->name;
                $o->values = $existingValuesByPoId[(int)$o->product_option_id] ?? [];
            }

            // Load combinations for 2-option products
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
                        'quantity' => (int) $combo->quantity,
                        'absolute_price' => (float) $combo->absolute_price,
                        'absolute_cost' => (float) $combo->absolute_cost,
                    ];
                }
            }

        // Images (main + additional)
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

        $returnUrl = url()->previous();
        // Only keep return URL if it's a products list page (not the edit page itself)
        if (!str_contains($returnUrl, '/products') || str_contains($returnUrl, '/products/' . $id)) {
            $returnUrl = route('products.index');
        }

        $currencies = \App\Models\Currency::where('status', 1)->orderBy('code')->get();

        return view('products.edit', compact('product', 'currentCategoryId', 'categoryName', 'currentCategories', 'manufacturerName', 'existingOptions', 'existingCombinations', 'productImages', 'returnUrl', 'currencies'));
    }

    public function update(Request $request, $id)
    {
        $pfxProduct = config('catalog.prefix') . 'product';

        $request->validate([
            'name' => 'required|string|max:255',
            'model' => 'required|string|max:64',
            'sku' => ['required', 'string', 'max:64', new UniqueSku((int) $id)],
            'price' => 'required|numeric',
            'cost_amount' => 'nullable|numeric',
            'cost_percentage' => 'nullable|numeric',
            'cost_additional' => 'nullable|numeric',
            'quantity' => 'required|integer',
            // Physical attributes (needed for marketplace payloads e.g. Lazada)
            'weight' => 'nullable|numeric',
            'length' => 'nullable|numeric',
            'width'  => 'nullable|numeric',
            'height' => 'nullable|numeric',
            'status' => 'required|in:0,1',
            'manufacturer_id' => 'nullable|integer',
            'manufacturer_name' => 'nullable|string|max:255',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|min:1',
            'category_id' => 'nullable|integer',
            'category_name' => 'nullable|string|max:1024',
            'description' => 'nullable|string',
            'images_json' => 'nullable|string',

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
        ]);

        $langId = (int) config('catalog.default_language_id');

        $resolvedManufacturerId = $this->resolveManufacturerId($request->manufacturer_id, $request->manufacturer_name);

        // Multi-category: prefer category_ids[], fall back to single category_id
        $categoryIds = array_filter(array_map('intval', $request->input('category_ids', [])));
        if (empty($categoryIds)) {
            $fallback = $this->resolveCategoryId($request->category_id, $request->category_name);
            if ($fallback > 0) $categoryIds = [$fallback];
        }

        $pfx = config('catalog.prefix');

        // If options exist, quantity must be forced from option quantities (server-side enforcement).
        $forcedQty = $request->has('_options_format')
            ? $this->totalAbsoluteOptionsQuantity($request)
            : $this->totalOptionsQuantity($request->input('options', []));

        $skuChanges = ['product_sku' => null, 'option_skus' => []];
        $originalAttrs = null;
        $updatedAttrs = null;

        DB::transaction(function () use ($request, $id, $langId, $pfx, $resolvedManufacturerId, $categoryIds, $forcedQty, &$skuChanges, &$originalAttrs, &$updatedAttrs) {
            /** @var Product $p */
            $p = Product::where('product_id', (int) $id)->firstOrFail();
            $originalAttrs = $p->getAttributes();

            $oldManufacturerId = (int) ($p->manufacturer_id ?? 0);

            // Detect product-level SKU change
            $oldProductSku = trim((string) $p->sku);

            $p->model = $request->model ?? ($request->sku ?? '');
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
// strict-mode safe
            if ($p->date_available === '0000-00-00') {
                $p->date_available = now()->toDateString();
            }

            $p->save();
            $updatedAttrs = $p->getAttributes();

            // Manufacturer no longer affects image folders (Option B: product-based folders)
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
                    'description' => (string) ($request->description ?? ''),
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

            // Save product options
            if ($request->has('_options_format')) {
                $this->saveAbsoluteOptions((int) $id, $request, $skuChanges);
            } else {
                $options = $request->input('options', []);
                $this->saveProductOptions((int) $id, $options, $skuChanges);
            }
            // Save product images (main + additional)
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
        });

        // Sync SKU changes to connected platforms (after transaction commits)
        $status = 'Saved';
        if (!empty($skuChanges['product_sku']) || !empty($skuChanges['option_skus'])) {
            $syncMessages = (new \App\Services\SkuSyncService())->syncSkuChanges((int) $id, $skuChanges);
            if (!empty($syncMessages)) {
                $status .= ' | SKU synced: ' . implode(' | ', $syncMessages);
            }
        }

        $changes = $originalAttrs && $updatedAttrs
            ? ActivityLogger::diff($originalAttrs, $updatedAttrs, ['sku', 'price', 'quantity', 'status', 'model', 'manufacturer_id'])
            : null;
        ActivityLogger::log('updated', 'Product', (int) $id, $request->name . ' (SKU: ' . ($request->sku ?? '') . ')', $changes);

        // Log stock change if product quantity changed
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
                    note: "Manual edit — qty $oldQty → $newQty",
                );
            }
        }

        // Sync warehouse inventory for default warehouse
        if (class_exists(\Extensions\warehousing\Services\WarehouseStockService::class)) {
            $defaultWh = \Extensions\warehousing\Services\WarehouseStockService::getDefaultWarehouse();
            if ($defaultWh && isset($updatedAttrs['quantity'])) {
                $inv = \Extensions\warehousing\Services\WarehouseStockService::getOrCreateInventory($defaultWh->id, (int) $id, 0);
                $inv->update(['quantity' => (int) $updatedAttrs['quantity']]);
            }
        }

        $returnUrl = $request->input('_return');
        if ($returnUrl && str_starts_with($returnUrl, url('/'))) {
            return redirect($returnUrl)->with('status', $status);
        }

        return redirect()->route('products.index')->with('status', $status);
    }

    public function destroy($id)
    {
        $pfx = config('catalog.prefix');

        $name = DB::table($pfx.'product_description')->where('product_id', (int) $id)->value('name');

        DB::transaction(function () use ($id, $pfx) {
            DB::table($pfx.'product_to_category')->where('product_id', (int) $id)->delete();
            DB::table($pfx.'product_description')->where('product_id', (int) $id)->delete();
            DB::table($pfx.'product')->where('product_id', (int) $id)->delete();

            // Clean up warehouse inventory
            if (class_exists(\Extensions\warehousing\Models\WarehouseInventory::class)) {
                \Extensions\warehousing\Models\WarehouseInventory::where('product_id', (int) $id)->delete();
            }
        });

        ActivityLogger::log('deleted', 'Product', (int) $id, $name);

        return redirect()->route('products.index')->with('status','Saved');
    }

    /**
     * AJAX: check if SKUs are already used by another product.
     * POST { skus: ['ABC','DEF'], exclude_product_id: 123 }
     * Returns { taken: { 'abc': 'product' | 'option_value' } }
     */
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
            DB::transaction(function () use ($ids, $pfx) {
                DB::table($pfx.'product_to_category')->whereIn('product_id', $ids)->delete();
                DB::table($pfx.'product_description')->whereIn('product_id', $ids)->delete();
                DB::table($pfx.'product')->whereIn('product_id', $ids)->delete();

                // Clean up warehouse inventory
                if (class_exists(\Extensions\warehousing\Models\WarehouseInventory::class)) {
                    \Extensions\warehousing\Models\WarehouseInventory::whereIn('product_id', $ids)->delete();
                }
            });

            return redirect()->route('products.index')->with('status', 'Deleted selected products.');
        }

        $newStatus = $action === 'enable' ? 1 : 0;
        DB::table($pfx.'product')->whereIn('product_id', $ids)->update(['status' => $newStatus]);

        return redirect()->route('products.index')->with('status', $newStatus === 1 ? 'Enabled selected products.' : 'Disabled selected products.');
    }

    

// ---- Absolute-pricing options (new UI) ----

    private function findOrCreateOption(string $name): int
    {
        $pfx = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $existing = DB::table($pfx . 'option_description')
            ->where('language_id', $langId)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        if ($existing) return (int) $existing->option_id;

        $optionId = DB::table($pfx . 'option')->insertGetId([
            'type' => 'select',
            'sort_order' => 0,
        ]);

        DB::table($pfx . 'option_description')->insert([
            'option_id' => $optionId,
            'language_id' => $langId,
            'name' => $name,
        ]);

        return (int) $optionId;
    }

    private function findOrCreateOptionValue(int $optionId, string $name): int
    {
        $pfx = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $existing = DB::table($pfx . 'option_value_description')
            ->where('option_id', $optionId)
            ->where('language_id', $langId)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        if ($existing) return (int) $existing->option_value_id;

        $optionValueId = DB::table($pfx . 'option_value')->insertGetId([
            'option_id' => $optionId,
            'image' => '',
            'sort_order' => 0,
        ]);

        DB::table($pfx . 'option_value_description')->insert([
            'option_value_id' => $optionValueId,
            'option_id' => $optionId,
            'language_id' => $langId,
            'name' => $name,
        ]);

        return (int) $optionValueId;
    }

    private function ensureProductOption(int $productId, int $optionId): int
    {
        $pfx = config('catalog.prefix');

        $existing = DB::table($pfx . 'product_option')
            ->where('product_id', $productId)
            ->where('option_id', $optionId)
            ->first();

        if ($existing) return (int) $existing->product_option_id;

        return DB::table($pfx . 'product_option')->insertGetId([
            'product_id' => $productId,
            'option_id' => $optionId,
            'value' => '',
            'required' => 1,
        ]);
    }

    private function clearProductOptions(int $productId): void
    {
        $pfx = config('catalog.prefix');

        DB::table('product_option_combination_values')
            ->whereIn('combination_id', function ($q) use ($productId) {
                $q->select('id')->from('product_option_combinations')->where('product_id', $productId);
            })->delete();
        DB::table('product_option_combinations')->where('product_id', $productId)->delete();

        $deletedPovIds = DB::table($pfx . 'product_option_value')
            ->where('product_id', $productId)
            ->pluck('product_option_value_id')
            ->map(fn($v) => (int) $v)
            ->all();

        DB::table($pfx . 'product_option_value')->where('product_id', $productId)->delete();
        DB::table($pfx . 'product_option')->where('product_id', $productId)->delete();

        if (!empty($deletedPovIds)) {
            DB::table('lazada_product_variants')
                ->whereIn('product_option_value_id', $deletedPovIds)
                ->update(['product_option_value_id' => null]);
        }
    }

    private function saveAbsoluteOptions(int $productId, Request $request, array &$skuChanges = []): void
    {
        $optionName = trim($request->input('option_name', ''));
        $option1Name = trim($request->input('option1_name', ''));

        if ($optionName === '' && $option1Name === '') {
            $this->clearProductOptions($productId);
            return;
        }

        // Validate SKU uniqueness (cross-product + intra-form + vs parent SKU)
        $skuRule = new UniqueSku($productId);
        $errors = [];
        $seenSkus = [];
        $parentSku = strtolower(trim((string) $request->input('sku', '')));
        if ($parentSku !== '') {
            $seenSkus[$parentSku] = true;
        }

        $valueSources = $request->input('values', []);
        $comboSources = $request->input('combinations', []);

        foreach ((is_array($valueSources) ? $valueSources : []) as $v) {
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
            $skuRule->validate('sku', $sku, function (string $msg) use (&$errors) {
                $errors[] = $msg;
            });
        }
        foreach ((is_array($comboSources) ? $comboSources : []) as $c) {
            $sku = trim((string) ($c['sku'] ?? ''));
            if ($sku === '') continue;
            $skuLower = strtolower($sku);
            if ($skuLower === $parentSku) {
                $errors[] = "The SKU \"{$sku}\" is already used as this product's parent SKU.";
                continue;
            }
            if (isset($seenSkus[$skuLower])) {
                $errors[] = "Duplicate SKU \"{$sku}\" within this product's combinations.";
                continue;
            }
            $seenSkus[$skuLower] = true;
            $skuRule->validate('sku', $sku, function (string $msg) use (&$errors) {
                $errors[] = $msg;
            });
        }

        if (!empty($errors)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['option_sku' => $errors]);
        }

        if ($option1Name !== '') {
            $this->saveAbsoluteTwoOptions($productId, $request, $skuChanges);
        } else {
            $this->saveAbsoluteOneOption($productId, $request, $skuChanges);
        }
    }

    private function saveAbsoluteOneOption(int $productId, Request $request, array &$skuChanges = []): void
    {
        $pfx = config('catalog.prefix');
        $optionName = trim($request->input('option_name', ''));
        $values = $request->input('values', []);
        if (!is_array($values)) $values = [];

        $optionId = $this->findOrCreateOption($optionName);

        // Remove product_options for other option_ids (user switched option)
        $stalePos = DB::table($pfx . 'product_option')
            ->where('product_id', $productId)
            ->where('option_id', '!=', $optionId)
            ->pluck('product_option_id');

        if ($stalePos->isNotEmpty()) {
            $stalePovIds = DB::table($pfx . 'product_option_value')
                ->whereIn('product_option_id', $stalePos)
                ->pluck('product_option_value_id')
                ->map(fn($v) => (int) $v)->all();

            DB::table($pfx . 'product_option_value')->whereIn('product_option_id', $stalePos)->delete();
            DB::table($pfx . 'product_option')->whereIn('product_option_id', $stalePos)->delete();

            if (!empty($stalePovIds)) {
                DB::table('lazada_product_variants')
                    ->whereIn('product_option_value_id', $stalePovIds)
                    ->update(['product_option_value_id' => null]);
            }
        }

        $productOptionId = $this->ensureProductOption($productId, $optionId);

        // Index existing POVs
        $existingPovs = DB::table($pfx . 'product_option_value')
            ->where('product_option_id', $productOptionId)
            ->get()
            ->keyBy('option_value_id');

        $seenOptionValueIds = [];

        foreach ($values as $v) {
            if (!is_array($v)) continue;
            $valueName = trim($v['name'] ?? '');
            if ($valueName === '') continue;

            $optionValueId = !empty($v['option_value_id'])
                ? (int) $v['option_value_id']
                : $this->findOrCreateOptionValue($optionId, $valueName);

            $seenOptionValueIds[] = $optionValueId;

            $data = [
                'sku' => trim((string) ($v['sku'] ?? '')),
                'quantity' => (int) ($v['quantity'] ?? 0),
                'subtract' => 1,
                'price' => 0,
                'price_prefix' => '+',
                'absolute_price' => (float) ($v['absolute_price'] ?? 0),
                'weight' => 0,
                'weight_prefix' => '+',
                'cost' => 0,
                'cost_prefix' => '+',
                'cost_amount' => 0,
                'cost_percentage' => 0,
                'cost_additional' => 0,
                'absolute_cost' => (float) ($v['absolute_cost'] ?? 0),
            ];

            if (isset($existingPovs[$optionValueId])) {
                $old = $existingPovs[$optionValueId];

                $oldSku = trim((string) ($old->sku ?? ''));
                if ($oldSku !== '' && $data['sku'] !== $oldSku) {
                    $skuChanges['option_skus'][(int) $old->product_option_value_id] = [
                        'old' => $oldSku, 'new' => $data['sku']
                    ];
                }

                if ((int) ($old->quantity ?? 0) !== $data['quantity']) {
                    StockHistoryLogger::log(
                        productId: $productId,
                        optionValueId: (int) $old->product_option_value_id,
                        orderId: null,
                        type: 'set',
                        qtyBefore: (int) $old->quantity,
                        qtyAfter: $data['quantity'],
                        source: 'manual',
                        note: "Manual edit (option) — qty {$old->quantity} → {$data['quantity']}",
                    );
                }

                DB::table($pfx . 'product_option_value')
                    ->where('product_option_value_id', $old->product_option_value_id)
                    ->update(array_merge($data, ['product_option_id' => $productOptionId]));
            } else {
                DB::table($pfx . 'product_option_value')->insert(array_merge($data, [
                    'product_option_id' => $productOptionId,
                    'product_id' => $productId,
                    'option_id' => $optionId,
                    'option_value_id' => $optionValueId,
                    'points' => 0,
                    'points_prefix' => '+',
                ]));
            }
        }

        // Remove old values not in new list
        $deletedPovIds = [];
        foreach ($existingPovs as $ovId => $row) {
            if (!in_array((int) $ovId, $seenOptionValueIds, true)) {
                $deletedPovIds[] = (int) $row->product_option_value_id;
                DB::table($pfx . 'product_option_value')
                    ->where('product_option_value_id', $row->product_option_value_id)
                    ->delete();
            }
        }
        if (!empty($deletedPovIds)) {
            DB::table('lazada_product_variants')
                ->whereIn('product_option_value_id', $deletedPovIds)
                ->update(['product_option_value_id' => null]);
        }

        // Rebuild combinations (1 combo per value)
        DB::table('product_option_combination_values')
            ->whereIn('combination_id', function ($q) use ($productId) {
                $q->select('id')->from('product_option_combinations')->where('product_id', $productId);
            })->delete();
        DB::table('product_option_combinations')->where('product_id', $productId)->delete();

        $now = now();
        $sort = 0;
        $povRows = DB::table($pfx . 'product_option_value')
            ->where('product_option_id', $productOptionId)
            ->get();

        foreach ($povRows as $pov) {
            $comboId = DB::table('product_option_combinations')->insertGetId([
                'product_id' => $productId,
                'sku' => $pov->sku ?? '',
                'quantity' => (int) $pov->quantity,
                'absolute_price' => (float) ($pov->absolute_price ?? 0),
                'absolute_cost' => (float) ($pov->absolute_cost ?? 0),
                'subtract' => 1,
                'sort_order' => $sort++,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('product_option_combination_values')->insert([
                'combination_id' => $comboId,
                'product_option_value_id' => (int) $pov->product_option_value_id,
            ]);
        }
    }

    private function saveAbsoluteTwoOptions(int $productId, Request $request, array &$skuChanges = []): void
    {
        $pfx = config('catalog.prefix');
        $opt1Name = trim($request->input('option1_name', ''));
        $opt2Name = trim($request->input('option2_name', ''));
        $opt1ValueNames = $request->input('option1_values', []);
        $opt2ValueNames = $request->input('option2_values', []);
        $combinations = $request->input('combinations', []);

        if (!is_array($opt1ValueNames)) $opt1ValueNames = [];
        if (!is_array($opt2ValueNames)) $opt2ValueNames = [];
        if (!is_array($combinations)) $combinations = [];

        $optionId1 = $this->findOrCreateOption($opt1Name);
        $optionId2 = $this->findOrCreateOption($opt2Name);

        // Remove product_options for other option_ids
        $keepOptionIds = [$optionId1, $optionId2];
        $stalePos = DB::table($pfx . 'product_option')
            ->where('product_id', $productId)
            ->whereNotIn('option_id', $keepOptionIds)
            ->pluck('product_option_id');

        if ($stalePos->isNotEmpty()) {
            $stalePovIds = DB::table($pfx . 'product_option_value')
                ->whereIn('product_option_id', $stalePos)
                ->pluck('product_option_value_id')
                ->map(fn($v) => (int) $v)->all();

            DB::table($pfx . 'product_option_value')->whereIn('product_option_id', $stalePos)->delete();
            DB::table($pfx . 'product_option')->whereIn('product_option_id', $stalePos)->delete();

            if (!empty($stalePovIds)) {
                DB::table('lazada_product_variants')
                    ->whereIn('product_option_value_id', $stalePovIds)
                    ->update(['product_option_value_id' => null]);
            }
        }

        $poId1 = $this->ensureProductOption($productId, $optionId1);
        $poId2 = $this->ensureProductOption($productId, $optionId2);

        // Sync POV rows for each option (structural — pricing lives in combinations)
        $opt1PovMap = $this->syncOptionValues($productId, $optionId1, $poId1, $opt1ValueNames);
        $opt2PovMap = $this->syncOptionValues($productId, $optionId2, $poId2, $opt2ValueNames);

        // Rebuild combinations
        DB::table('product_option_combination_values')
            ->whereIn('combination_id', function ($q) use ($productId) {
                $q->select('id')->from('product_option_combinations')->where('product_id', $productId);
            })->delete();
        DB::table('product_option_combinations')->where('product_id', $productId)->delete();

        $now = now();
        $sort = 0;

        foreach ($combinations as $combo) {
            if (!is_array($combo)) continue;
            $v1Name = trim($combo['opt1'] ?? '');
            $v2Name = trim($combo['opt2'] ?? '');
            if ($v1Name === '' || $v2Name === '') continue;

            $pov1Id = $opt1PovMap[strtolower($v1Name)] ?? null;
            $pov2Id = $opt2PovMap[strtolower($v2Name)] ?? null;
            if (!$pov1Id || !$pov2Id) continue;

            $comboId = DB::table('product_option_combinations')->insertGetId([
                'product_id' => $productId,
                'sku' => trim((string) ($combo['sku'] ?? '')),
                'quantity' => (int) ($combo['quantity'] ?? 0),
                'absolute_price' => (float) ($combo['absolute_price'] ?? 0),
                'absolute_cost' => (float) ($combo['absolute_cost'] ?? 0),
                'subtract' => 1,
                'sort_order' => $sort++,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('product_option_combination_values')->insert([
                ['combination_id' => $comboId, 'product_option_value_id' => $pov1Id],
                ['combination_id' => $comboId, 'product_option_value_id' => $pov2Id],
            ]);
        }
    }

    /**
     * Sync product_option_value rows for one option. Returns map: lowercase_name => pov_id
     */
    private function syncOptionValues(int $productId, int $optionId, int $productOptionId, array $valueNames): array
    {
        $pfx = config('catalog.prefix');
        $map = [];

        $existingPovs = DB::table($pfx . 'product_option_value')
            ->where('product_option_id', $productOptionId)
            ->get()
            ->keyBy('option_value_id');

        $seenOptionValueIds = [];

        foreach ($valueNames as $name) {
            $name = trim($name);
            if ($name === '') continue;

            $optionValueId = $this->findOrCreateOptionValue($optionId, $name);
            $seenOptionValueIds[] = $optionValueId;

            if (!isset($existingPovs[$optionValueId])) {
                $povId = DB::table($pfx . 'product_option_value')->insertGetId([
                    'product_option_id' => $productOptionId,
                    'product_id' => $productId,
                    'option_id' => $optionId,
                    'option_value_id' => $optionValueId,
                    'sku' => '',
                    'quantity' => 0,
                    'subtract' => 1,
                    'price' => 0,
                    'price_prefix' => '+',
                    'absolute_price' => 0,
                    'weight' => 0,
                    'weight_prefix' => '+',
                    'cost' => 0,
                    'cost_prefix' => '+',
                    'cost_amount' => 0,
                    'cost_percentage' => 0,
                    'cost_additional' => 0,
                    'absolute_cost' => 0,
                    'points' => 0,
                    'points_prefix' => '+',
                ]);
                $map[strtolower($name)] = $povId;
            } else {
                $map[strtolower($name)] = (int) $existingPovs[$optionValueId]->product_option_value_id;
            }
        }

        // Remove old values not in new list
        foreach ($existingPovs as $ovId => $row) {
            if (!in_array((int) $ovId, $seenOptionValueIds, true)) {
                DB::table($pfx . 'product_option_value')
                    ->where('product_option_value_id', $row->product_option_value_id)
                    ->delete();
            }
        }

        return $map;
    }

    /**
     * Persist product options in OpenCart tables (product_option / product_option_value).
     * Uses upsert strategy to preserve product_option_value_id across edits,
     * since downstream systems (Lazada/Shopee variant mappings) reference these IDs.
     */
    private function saveProductOptions(int $productId, array $options, array &$skuChanges = []): void
    {
        $p = config('catalog.prefix');

        // Normalize
        $options = array_values(array_filter($options ?? [], fn ($o) => is_array($o)));

        // Validate SKU uniqueness (cross-product + intra-form + vs parent SKU)
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

        // Index existing product_option rows by option_id
        $existingPo = DB::table($p.'product_option')
            ->where('product_id', $productId)
            ->get()
            ->keyBy('option_id');

        // Index existing product_option_value rows by "option_id:option_value_id"
        $existingPov = DB::table($p.'product_option_value')
            ->where('product_id', $productId)
            ->get()
            ->keyBy(fn ($r) => $r->option_id . ':' . $r->option_value_id);

        if (empty($options)) {
            // Remove all if no options provided
            if ($existingPov->isNotEmpty()) {
                DB::table($p.'product_option_value')->where('product_id', $productId)->delete();
            }
            if ($existingPo->isNotEmpty()) {
                DB::table($p.'product_option')->where('product_id', $productId)->delete();
            }
            return;
        }

        // Guard supported option types (same as current UI)
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
                    // Log option value quantity change
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
                            note: "Manual edit (option) — qty $oldOvQty → $newOvQty",
                        );
                    }

                    // Track SKU changes for marketplace link updates
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

                        // Record for platform API sync after transaction
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

        // Remove option values no longer in the incoming list
        $deletedPovIds = [];
        foreach ($existingPov as $key => $row) {
            if (!in_array($key, $seenPovKeys, true)) {
                $deletedPovIds[] = (int) $row->product_option_value_id;
                DB::table($p.'product_option_value')->where('product_option_value_id', $row->product_option_value_id)->delete();
            }
        }

        // Remove product options no longer in the incoming list
        foreach ($existingPo as $optId => $row) {
            if (!in_array((int) $optId, $seenPoOptionIds, true)) {
                // Collect IDs of option values being removed with the option group
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

        // Clean stale downstream references for deleted option values
        if (!empty($deletedPovIds)) {
            DB::table('lazada_product_variants')
                ->whereIn('product_option_value_id', $deletedPovIds)
                ->update(['product_option_value_id' => null]);
        }
    }


}