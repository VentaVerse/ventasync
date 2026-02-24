<?php

namespace App\Http\Controllers;

use App\Models\Catalog\Manufacturer;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDescription;
use App\Models\Catalog\ProductToCategory;
use App\Models\Catalog\ProductImage;
use App\Rules\UniqueSku;
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
                $q = (int) ($v['quantity'] ?? 0);
                if ($q < 0) $q = 0;
                $sum += $q;
            }
        }

        return $hasAnyValue ? $sum : null;
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

            // Accept only catalog/* and jpg/png
            if (!Str::startsWith($path, 'catalog/')) continue;
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) continue;

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
            'p.image',
            'p.quantity',
            DB::raw('COALESCE(povsum.options_quantity, 0) as options_quantity'),
            'p.price',
            'p.status'
        );

    if ($q !== '') {
    // Search ONLY by product name (word-start match on any word)
    $ids = DB::table($p.'product_description as pd')
        ->where('pd.language_id', '=', $langId)
        ->where('pd.name', 'like', "%{$q}%")
        ->limit(800)
        ->get(['pd.product_id','pd.name']);

    $matched = [];
    foreach ($ids as $r) {
        if ($this->isWordStartMatch((string)$r->name, $q)) {
            $matched[] = (int)$r->product_id;
        }
    }

    if (count($matched) === 0) {
        // Force empty result
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

    // Fetch option value rows for products on this page (for list display)
    $pageIds = $products->pluck('product_id')->map(fn($v) => (int)$v)->all();
    $optionRowsByProduct = [];
    if (!empty($pageIds)) {
        $optRows = DB::table($p.'product_option_value as pov')
            ->join($p.'option_description as od', function ($j) use ($langId) {
                $j->on('pov.option_id', '=', 'od.option_id')
                  ->where('od.language_id', '=', $langId);
            })
            ->join($p.'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                  ->where('ovd.language_id', '=', $langId);
            })
            ->leftJoin($p.'option_value as ov', 'pov.option_value_id', '=', 'ov.option_value_id')
            ->whereIn('pov.product_id', $pageIds)
            ->orderBy('pov.product_id')
            ->orderBy('od.name')
            ->orderBy('ovd.name')
            ->get([
                'pov.product_id',
                'od.name as option_name',
                'ovd.name as option_value_name',
                'ov.image as option_image',
                'pov.quantity',
                'pov.price',
                'pov.price_prefix',
            ]);

        foreach ($optRows as $r) {
            $pid = (int) $r->product_id;
            $r->option_name = html_entity_decode((string)$r->option_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $r->option_value_name = html_entity_decode((string)$r->option_value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $optionRowsByProduct[$pid] ??= [];
            $optionRowsByProduct[$pid][] = $r;
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
    return view('products.index', compact('products', 'q', 'sort', 'dir'));
}


    public function create()
    {
        
        $productImages = [];
$pfx = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        // Option master list (needed for client-side tab switching like OpenCart)
        $allOptions = DB::table($pfx.'option as o')
            ->join($pfx.'option_description as od', function ($j) use ($langId) {
                $j->on('o.option_id', '=', 'od.option_id')
                  ->where('od.language_id', '=', $langId);
            })
            ->orderBy('od.name')
            ->get(['o.option_id', 'o.type', 'od.name']);

        foreach ($allOptions as $o) {
            $o->name = html_entity_decode((string)$o->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $existingOptions = collect();

        return view('products.create', compact('allOptions', 'existingOptions'));
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

    private function finalizeTempImages(string $token, string $manufacturerSlug, int $productId, array $items): array
    {
        $disk = Storage::disk('public');
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $token);
        $tmpBase = 'tmp/product-images/' . ($token ?: 'default');

        $finalDir = 'catalog/_products_' . date('Y');
        $disk->makeDirectory($finalDir);

        $out = [];
        foreach ($items as $it) {
            $path = (string) ($it['path'] ?? '');
            if ($path === '') continue;

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png'], true)) continue;

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
        $pfxProduct = config('catalog.prefix') . 'product';

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
            'options.*.values.*.quantity' => 'nullable|integer|min:0',
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

        $resolvedCategoryId = $this->resolveCategoryId(
            (int) $request->input('category_id', 0),
            (string) $request->input('category_name', '')
        );

        // If options exist, quantity must be forced from option quantities (server-side enforcement).
        $forcedQty = $this->totalOptionsQuantity($request->input('options', []));

        $productId = DB::transaction(function () use ($request, $langId, $resolvedManufacturerId, $resolvedCategoryId, $pfx, $forcedQty) {
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
            $p->weight = (float) ($request->input('weight', 0) ?? 0);
            $p->length = (float) ($request->input('length', 0) ?? 0);
            $p->width  = (float) ($request->input('width', 0) ?? 0);
            $p->height = (float) ($request->input('height', 0) ?? 0);
            $p->stock_status_id = 0;
            $p->image = null;
            $p->manufacturer_id = (int) ($resolvedManufacturerId ?? 0);
            $p->shipping = 1;

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
$catId = (int) ($resolvedCategoryId ?? 0);
            if ($catId > 0) {
                ProductToCategory::create([
                    'product_id' => $p->product_id,
                    'category_id' => $catId,
                ]);
            }

            // Save product options (OpenCart-like: options can be set during Add/Edit)
            $options = $request->input('options', []);
            $this->saveProductOptions((int) $p->product_id, $options);

            // Save product images (main + additional)
            if ($request->has('images_json')) {
                $items = $this->imagesFromRequest($request->input('images_json'));
                $paths = $this->imagePathsFromItems($items);
                $this->saveProductImages((int) $id, $paths);
            }

            return (int) $p->product_id;
        });

        return redirect()->route('products.index')
            ->with('status','Product created.');
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


        $currentCategoryId = DB::table($pfx.'product_to_category')
            ->where('product_id', (int) $id)
            ->value('category_id');

        // For display in the category search input
        $categoryName = null;
        if ($currentCategoryId) {
            $categoryName = DB::table($pfx.'category_description')
                ->where('category_id', (int) $currentCategoryId)
                ->where('language_id', $langId)
                ->value('name');
        }

        $manufacturerName = $product->manufacturer_name ?? null;

        $allOptions = [];
        $existingOptions = collect();

        if (true) {
            // Option master list
            $allOptions = DB::table($pfx.'option as o')
                ->join($pfx.'option_description as od', function ($j) use ($langId) {
                    $j->on('o.option_id', '=', 'od.option_id')
                      ->where('od.language_id', '=', $langId);
                })
                ->orderBy('od.name')
                ->get(['o.option_id', 'o.type', 'od.name']);

            foreach ($allOptions as $o) {
                $o->name = html_entity_decode((string)$o->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

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
                        'pov.product_option_id',
                        'pov.option_value_id',
                        'ovd.name as option_value_name',
                        'pov.sku',
                        'pov.quantity',
                        'pov.subtract',
                        'pov.price_prefix',
                        'pov.price',
                        'pov.weight_prefix',
                        'pov.weight',
                        'pov.cost_amount',
                        'pov.cost_percentage',
                        'pov.cost_additional',
                    ]);

                foreach ($rows as $r) {
                    $r->option_value_name = html_entity_decode((string)$r->option_value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $existingValuesByPoId[(int)$r->product_option_id][] = [
                        'option_value_id' => (int)$r->option_value_id,
                        'name' => $r->option_value_name,
                        'quantity' => (int)$r->quantity,
                        'subtract' => (int)($r->subtract ?? 1),
                        'price_prefix' => (string)$r->price_prefix,
                        'price' => (string)$r->price,
                        'weight_prefix' => (string)($r->weight_prefix ?? '+'),
                        'weight' => (string)($r->weight ?? 0),
                        'sku' => (string)($r->sku ?? ''),
                        'cost_amount' => (string)($r->cost_amount ?? 0),
                        'cost_percentage' => (string)($r->cost_percentage ?? 0),
                        'cost_additional' => (string)($r->cost_additional ?? 0),
                    ];
                }
            }

            foreach ($existingOptions as $o) {
                $o->name = html_entity_decode((string)$o->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $o->values = $existingValuesByPoId[(int)$o->product_option_id] ?? [];
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

return view('products.edit', compact('product', 'currentCategoryId', 'categoryName', 'manufacturerName', 'allOptions', 'existingOptions', 'productImages', 'returnUrl'));
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
            'options.*.values.*.quantity' => 'nullable|integer|min:0',
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
        $resolvedCategoryId = $this->resolveCategoryId($request->category_id, $request->category_name);

        $pfx = config('catalog.prefix');

        // If options exist, quantity must be forced from option quantities (server-side enforcement).
        $forcedQty = $this->totalOptionsQuantity($request->input('options', []));

        $skuChanges = ['product_sku' => null, 'option_skus' => []];

        DB::transaction(function () use ($request, $id, $langId, $pfx, $resolvedManufacturerId, $resolvedCategoryId, $forcedQty, &$skuChanges) {
            /** @var Product $p */
            $p = Product::where('product_id', (int) $id)->firstOrFail();

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

            $catId = (int) ($resolvedCategoryId ?? 0);
            if ($catId > 0) {
                ProductToCategory::create([
                    'product_id' => (int) $id,
                    'category_id' => $catId,
                ]);
            }

            // Save product options (OpenCart-like: options can be set during Add/Edit)
            $options = $request->input('options', []);
            $this->saveProductOptions((int) $id, $options, $skuChanges);
            // Save product images (main + additional)
            if ($request->has('images_json')) {
                $items = $this->imagesFromRequest($request->input('images_json'));
                $paths = $this->imagePathsFromItems($items);
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

        $returnUrl = $request->input('_return');
        if ($returnUrl && str_starts_with($returnUrl, url('/'))) {
            return redirect($returnUrl)->with('status', $status);
        }

        return redirect()->route('products.index')->with('status', $status);
    }

    public function destroy($id)
    {
        $pfx = config('catalog.prefix');

        DB::transaction(function () use ($id, $pfx) {
            DB::table($pfx.'product_to_category')->where('product_id', (int) $id)->delete();
            DB::table($pfx.'product_description')->where('product_id', (int) $id)->delete();
            DB::table($pfx.'product')->where('product_id', (int) $id)->delete();
        });

        return redirect()->route('products.index')->with('status','Saved');
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
            });

            return redirect()->route('products.index')->with('status', 'Deleted selected products.');
        }

        $newStatus = $action === 'enable' ? 1 : 0;
        DB::table($pfx.'product')->whereIn('product_id', $ids)->update(['status' => $newStatus]);

        return redirect()->route('products.index')->with('status', $newStatus === 1 ? 'Enabled selected products.' : 'Disabled selected products.');
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

        // Validate SKU uniqueness across product.sku and product_option_value.sku
        $skuRule = new UniqueSku($productId);
        $errors = [];
        foreach ($options as $oi => $opt) {
            foreach (($opt['values'] ?? []) as $vi => $v) {
                $sku = trim((string) ($v['sku'] ?? ''));
                if ($sku === '') continue;
                $skuRule->validate("options.{$oi}.values.{$vi}.sku", $sku, function (string $msg) use (&$errors) {
                    $errors[] = $msg;
                });
            }
        }
        if (!empty($errors)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['sku' => $errors]);
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

                $costAmount = (float) ($v['cost_amount'] ?? 0);
                $costPct    = (float) ($v['cost_percentage'] ?? 0);
                $costAdd    = (float) ($v['cost_additional'] ?? 0);
                $optPrice   = (float) ($v['price'] ?? 0);
                $costTotal  = $costAmount + ($costPct / 100 * $optPrice) + $costAdd;

                $data = [
                    'sku' => (string) ($v['sku'] ?? ''),
                    'quantity' => (int) ($v['quantity'] ?? 0),
                    'subtract' => (int) ($v['subtract'] ?? 1),
                    'price' => $optPrice,
                    'price_prefix' => (string) ($v['price_prefix'] ?? '+'),
                    'weight' => (float) ($v['weight'] ?? 0),
                    'weight_prefix' => (string) ($v['weight_prefix'] ?? '+'),
                    'cost_amount' => $costAmount,
                    'cost_percentage' => $costPct,
                    'cost_additional' => $costAdd,
                    'cost' => $costTotal,
                    'cost_prefix' => '+',
                ];

                if (isset($existingPov[$key])) {
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