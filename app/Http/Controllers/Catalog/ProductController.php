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
    /**
     * Require a site-relative path so `_return` cannot send a user off-site
     * (open redirect). Only a single leading "/" is accepted; "//evil.example"
     * and "/\evil.example" are both browser-interpretable as absolute URLs
     * (protocol-relative) and must be rejected too.
     */
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
        app(\App\Services\Catalog\ProductCreator::class)->saveImages($productId, $paths);
    }

public function index(Request $request)
{
    $p = config('catalog.prefix');
    $langId = (int) config('catalog.default_language_id');

    

        $pfx = config('catalog.prefix');
$q = trim((string) $request->get('q', ''));

    // Sorting. Two carriers, one truth: the column headers set sort/dir
    // (their historic wire), and the filter bar's sort select sends the
    // pair as one sort_by value - "name:asc" - because a field dropdown
    // plus a separate direction toggle is two controls for one decision.
    // The select exists for the phone - this table scrolls sideways
    // there, so the header you want to click is usually off-screen
    // (the owner, 2026-09-04) - and for the combos no header offers, like
    // recently updated. Newest first is the default: the products you
    // just made are the ones you came to work on.
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

    // Variation rows for the products on this page, shared with every
    // marketplace list that shows them (App\Support\VariationRows).
    $pageIds = $products->pluck('product_id')->map(fn($v) => (int)$v)->all();
    $optionRowsByProduct = \App\Support\VariationRows::forProducts($pageIds);
    foreach ($products as $row) {
        $row->option_rows = ($optionRowsByProduct->get((int) $row->product_id) ?? collect())->all();
    }

    // What a delete would also remove, per row, for the confirm sheet.
    $channelNote = app(\App\Services\Catalog\ProductDeleter::class)->notes($pageIds);

    return view('catalog.products.index', compact('products', 'q', 'sort', 'dir', 'status', 'channelNote'));
}


    public function create()
    {

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
        $request->validate([
            /* WHAT A PRODUCT NEEDS TO EXIST is what the channels need to
             * LIST it (the owner, settled across 2026-09-03/04, reversing the
             * 2026-08-21 minimalism): a name, a SKU, a real description, a
             * price above zero, a parcel and at least one photo. Every one
             * of these is something Shopee, Lazada or TikTok refuses a
             * listing without - or, for the SKU, something this system's
             * own matching (imports, stock pushes, link repair) is blind
             * without, and Lazada's SellerSku is mandatory besides.
             *
             * What is NOT required, and why:
             *
             *   model     retired from the form entirely - no channel has
             *             the concept (Shopee's model_sku is a variation
             *             SKU). The column stays for OpenCart's native
             *             model and legacy rows; API callers may still
             *             send it.
             *   quantity  on a product with variations it is not even
             *             editable - it is the sum of the rows below.
             */
            'name' => 'required|string|max:255',
            'model' => 'nullable|string|max:64',
            'sku' => ['required', 'string', 'max:64', new UniqueSku()],
            'price' => 'required|numeric|gt:0',
            'cost_amount' => 'nullable|numeric',
            'cost_percentage' => 'nullable|numeric',
            'cost_additional' => 'nullable|numeric',
            'quantity' => 'nullable|integer',
            // Physical attributes (needed for marketplace payloads e.g. Lazada)
            /* The parcel is required at birth (#198, the owner): Shopee, Lazada
             * and TikTok all refuse a push without it, and #191's preflight
             * only catches the gap later. Since 2026-09-04 update() enforces
             * the same rule, so a legacy zero gets filled the next time
             * anyone touches the product. */
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
            /* Optional by decision (the owner, 2026-09-06), a deliberate exception
             * to error prevention: the 80-character floor of 2026-09-04 held
             * every shop to a marketplace it might not use, and named it. A
             * marketplace with a minimum of its own says so when the listing
             * is pushed; nothing in the catalog CRUD points at a channel. */
            'description' => ['nullable', 'string'],
            'images_json' => ['nullable', 'string'],

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

        /* BEFORE ANYTHING WRITES (#185): same gate update() runs. A create
         * has no id to exclude, so 0 excludes nothing. */
        if ($request->has('_options_format')) {
            $this->validateOptionSkus(0, $request);
        }

        /* The product is made by ProductCreator, the one the API uses too.
         * The form hands in what only it has: its variations, and the
         * pictures it uploaded, moved into the manufacturer's folder once
         * the product has an id. */
        app(\App\Services\Catalog\ProductCreator::class)->create(
            [
                'name' => (string) $request->name,
                'sku' => (string) ($request->sku ?? ''),
                // A blank model stays blank; the channel mapping supplies one where a channel needs it.
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
                // Which channel sold it. The global order list has always
                // shown this; the per-product history did not, so a table of
                // forty sales across four marketplaces could not say which
                // one any of them came from.
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

        /* The same registry-driven map the Orders index and the dashboard
           use, so core carries no marketplace-specific knowledge and a new
           channel appears here the day it registers itself. */
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

        // Decode HTML entities stored in DB (e.g. &amp;)
        $product->name = html_entity_decode($product->name ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $product->model = html_entity_decode($product->model ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $product->sku = html_entity_decode($product->sku ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Render-boundary guard: rows saved before this sanitiser existed
        // (or written directly to the DB) may still carry hostile HTML.
        // Sanitising here neutralises them on display without rewriting
        // the database. See App\Support\HtmlSanitizer's docblock.
        $product->description = DescriptionHtml::store($product->description ?? '');


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

            /* The variation image lives on the COMBINATION, because that is
             * the sellable unit. A single-axis product builds its grid from
             * product_option_value rows, so the image has to be carried
             * across by the join between them - one hop, one query for the
             * whole product rather than one per row. */
            $imageByPovId = [];
            $statusByPovId = [];
            /* And the cost AMOUNT rides the same hop, for the same reason.
             * product_option_value has a cost_amount column that no code has
             * ever written to - every row in this catalog holds 0 - so
             * reading it would blank the column on every edit. The
             * combination is where the number actually lives. */
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

                // A two-axis combination joins two values and its image
                // belongs to neither of them alone, so only single-value
                // combinations contribute here. groupBy would hide that.
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
                    // The row's own additional, since 2026-08-27. The
                    // combination is written from the form; the value row
                    // holds the same figure and stands in when there is none.
                    $existingValuesByPoId[$poId][$i]['cost_additional'] = $costAddByPovId[$povId]
                        ?? (float) ($value['cost_additional'] ?? 0);
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

        /* The way back (#189): the list URL the operator came from, search
         * and filters intact - made RELATIVE, because update()'s safety
         * check (rightly) accepts only local paths, and an absolute URL
         * here meant every save fell back to the bare index. That one
         * mismatch was the whole bug the owner reported. */
        $returnUrl = preg_replace('#^https?://[^/]+#', '', url()->previous()) ?: '';
        // Only keep return URL if it's a products list page (not the edit page itself)
        if (!str_contains($returnUrl, '/products') || str_contains($returnUrl, '/products/' . $id)) {
            $returnUrl = route('products.index');
        }

        $currencies = \App\Models\Currency::where('status', 1)->orderBy('code')->get();

        // What a delete would also remove, for the confirm sheet.
        $deleter = app(\App\Services\Catalog\ProductDeleter::class);
        $channelNote = $deleter->note($deleter->presence([(int) $product->product_id])[(int) $product->product_id] ?? []);

        return view('catalog.products.edit', compact('product', 'currentCategoryId', 'categoryName', 'currentCategories', 'manufacturerName', 'existingOptions', 'existingCombinations', 'productImages', 'returnUrl', 'currencies', 'channelNote'));
    }

    public function update(Request $request, $id)
    {
        $pfxProduct = config('catalog.prefix') . 'product';

        $request->validate([
            /* WHAT A PRODUCT NEEDS TO EXIST is what the channels need to
             * LIST it (the owner, settled across 2026-09-03/04, reversing the
             * 2026-08-21 minimalism): a name, a SKU, a real description, a
             * price above zero, a parcel and at least one photo. Every one
             * of these is something Shopee, Lazada or TikTok refuses a
             * listing without - or, for the SKU, something this system's
             * own matching (imports, stock pushes, link repair) is blind
             * without, and Lazada's SellerSku is mandatory besides.
             *
             * What is NOT required, and why:
             *
             *   model     retired from the form entirely - no channel has
             *             the concept (Shopee's model_sku is a variation
             *             SKU). The column stays for OpenCart's native
             *             model and legacy rows; API callers may still
             *             send it.
             *   quantity  on a product with variations it is not even
             *             editable - it is the sum of the rows below.
             */
            'name' => 'required|string|max:255',
            'model' => 'nullable|string|max:64',
            'sku' => ['required', 'string', 'max:64', new UniqueSku((int) $id)],
            'price' => 'required|numeric|gt:0',
            'cost_amount' => 'nullable|numeric',
            'cost_percentage' => 'nullable|numeric',
            'cost_additional' => 'nullable|numeric',
            'quantity' => 'nullable|integer',
            /* The parcel is required on EVERY save now, not only at birth
             * (the owner, 2026-09-04, reversing #198's edit carve-out): the
             * goal is that a push can never fail on a missing parcel, and
             * an edit is exactly the moment the owner is looking at the
             * product. Legacy rows with zeros get filled the next time
             * anyone touches them. */
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
            /* Optional by decision (the owner, 2026-09-06), a deliberate exception
             * to error prevention: the 80-character floor of 2026-09-04 held
             * every shop to a marketplace it might not use, and named it. A
             * marketplace with a minimum of its own says so when the listing
             * is pushed; nothing in the catalog CRUD points at a channel. */
            'description' => ['nullable', 'string'],
            'images_json' => ['nullable', 'string'],

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

        /* BEFORE ANYTHING WRITES (#185): the option-SKU gate used to fire
         * from inside the save, after the product row and categories were
         * already rewritten - a refused SKU left a half-saved product
         * behind the error. Refuse first, write after. */
        if ($request->has('_options_format')) {
            $this->validateOptionSkus((int) $id, $request);
        }

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
        // The variation SKUs before the save; the ones the save removes are
        // forgotten on every channel after it commits (VariationForgetService).
        $variationsBefore = \App\Services\VariationForgetService::snapshot((int) $id);
        DB::transaction(function () use ($request, $id, $langId, $pfx, $resolvedManufacturerId, $categoryIds, $forcedQty, &$skuChanges, &$originalAttrs, &$updatedAttrs) {
            /** @var Product $p */
            $p = Product::where('product_id', (int) $id)->firstOrFail();
            $originalAttrs = $p->getAttributes();

            $oldManufacturerId = (int) ($p->manufacturer_id ?? 0);

            // Detect product-level SKU change
            $oldProductSku = trim((string) $p->sku);

            /* A BLANK MODEL STAYS BLANK. It used to fall back to the SKU,
             * and Laravel's ConvertEmptyStringsToNull makes that fire on an
             * ordinary empty box rather than only on an absent field - so
             * clearing the model silently refilled it with the SKU and the
             * next reader could not tell an intended model from a copy.
             *
             * The field only exists because Lazada and Shopee have one, and
             * the channel mapping supplies it there. Empty is a real answer
             * here, so it is recorded as one. */
            /* The form retired the field (2026-09-04); only a caller that
             * actually sends model may change it, and an absent key leaves
             * a legacy value alone rather than blanking it. */
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
                    // Sanitised before persistence: see the store() note
                    // above and App\Support\HtmlSanitizer's docblock.
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
        $forgotten = (new \App\Services\VariationForgetService())->reconcile((int) $id, $variationsBefore);
        if ($forgotten !== []) {
            $status .= ' | Forgotten on every channel: ' . implode(', ', $forgotten);
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
                    note: "Manual edit, qty $oldQty to $newQty",
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
        if (is_string($returnUrl) && $this->isSafeLocalReturnUrl($returnUrl)) {
            return redirect($returnUrl)->with('status', $status);
        }

        return redirect()->route('products.index')->with('status', $status);
    }

    public function destroy($id)
    {
        /* The catalog's own act (the owner, 2026-09-07): the product and every
         * channel's local rows go together; no marketplace is called and
         * nothing can refuse. See App\Services\Catalog\ProductDeleter. */
        $deleter = app(\App\Services\Catalog\ProductDeleter::class);
        $flash = $deleter->flash($deleter->delete([(int) $id]), 1);

        return redirect()->route('products.index')->with($flash['tone'], $flash['text']);
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
            // Channels first, catalog last, per product (the owner, 2026-09-05).
            $deleter = app(\App\Services\Catalog\ProductDeleter::class);
            $flash = $deleter->flash($deleter->delete($ids), count($ids));

            return redirect()->route('products.index')->with($flash['tone'], $flash['text']);
        }

        $newStatus = $action === 'enable' ? 1 : 0;
        DB::table($pfx.'product')->whereIn('product_id', $ids)->update(['status' => $newStatus]);

        return redirect()->route('products.index')->with('status', $newStatus === 1 ? 'Enabled selected products.' : 'Disabled selected products.');
    }

    

// ---- Absolute-pricing variations, saved by App\Services\Catalog\VariationWriter ----

    private function variationWriter(): \App\Services\Catalog\VariationWriter
    {
        return app(\App\Services\Catalog\VariationWriter::class);
    }

    /** Every typed variation SKU is checked before anything writes. */
    private function validateOptionSkus(int $productId, Request $request): void
    {
        $this->variationWriter()->validateSkus($productId, $request->input());
    }

    public function saveAbsoluteOptions(int $productId, Request $request, array &$skuChanges = []): void
    {
        $this->variationWriter()->save($productId, $request->input(), $skuChanges);
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
                            note: "Manual edit (variation), qty $oldOvQty to $newOvQty",
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