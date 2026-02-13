<?php

namespace App\Http\Controllers;

use App\Models\Catalog\Manufacturer;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDescription;
use App\Models\Catalog\ProductToCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class ProductController extends Controller
{
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

    public function index(Request $request)
{
    $p = config('catalog.prefix');
    $langId = (int) config('catalog.default_language_id');

    
$q = trim((string) $request->get('q', ''));

    // Sorting
    $sort = (string) $request->get('sort', 'product_id');
    $dir = strtolower((string) $request->get('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

    $allowedSort = [
        'product_id'   => 'p.product_id',
        'name'         => 'pd.name',
        'manufacturer' => 'm.name',
        'model'        => 'p.model',
        'sku'          => 'p.sku',
        'price'        => 'p.price',
        'quantity'     => 'p.quantity',
        'status'       => 'p.status',
    ];

    if (!array_key_exists($sort, $allowedSort)) {
        $sort = 'product_id';
    }

    $query = DB::table($p.'product as p')
        ->leftJoin($p.'product_description as pd', function ($j) use ($langId) {
            $j->on('p.product_id', '=', 'pd.product_id')
              ->where('pd.language_id', '=', $langId);
        })
        ->leftJoin($p.'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
        ->select(
            'p.product_id',
            'pd.name as name',
            'p.model',
            'p.sku',
            'p.quantity',
            'p.price',
            'p.status',
            'm.name as manufacturer'
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
    if (isset($row->manufacturer)) $row->manufacturer = html_entity_decode($row->manufacturer, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (isset($row->model)) $row->model = html_entity_decode($row->model, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (isset($row->sku)) $row->sku = html_entity_decode($row->sku, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

return view('products.index', compact('products', 'q', 'sort', 'dir'));
}

    public function create()
    {
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


    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'model' => 'required|string|max:64',
            'sku' => 'nullable|string|max:64',
            'price' => 'required|numeric',
            'quantity' => 'required|integer',
            'status' => 'required|in:0,1',
            'manufacturer_id' => 'nullable|integer',
            'manufacturer_name' => 'nullable|string|max:255',
            'category_id' => 'nullable|integer',
            'category_name' => 'nullable|string|max:1024',
            'description' => 'nullable|string',

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

        $productId = DB::transaction(function () use ($request, $langId, $resolvedManufacturerId, $resolvedCategoryId) {
            $p = new Product();

            $p->model = $request->model ?? ($request->sku ?? '');
            $p->sku = $request->sku ?? '';
            $p->upc = '';
            $p->ean = '';
            $p->jan = '';
            $p->isbn = '';
            $p->mpn = '';
            $p->location = '';

            $p->quantity = (int) $request->quantity;
            $p->stock_status_id = 0;
            $p->image = null;
            $p->manufacturer_id = (int) ($resolvedManufacturerId ?? 0);
            $p->shipping = 1;

            $p->price = (float) $request->price;
            $p->points = 0;
            $p->tax_class_id = 0;

            // Ensure strict-mode safe date_available (OpenCart sets it automatically)
            $da = $p->date_available;
            if (!$da || $da === '0000-00-00') {
                $p->date_available = now()->toDateString();
            }

            $p->save();

            $d = new ProductDescription();
            $d->product_id = $p->product_id;
            $d->language_id = $langId;
            $d->name = $request->name;
            $d->description = $request->description ?? '';
            $d->meta_title = $request->name;
            $d->meta_description = '';
            $d->meta_keyword = '';
            $d->tag = '';
            $d->save();

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

            return (int) $p->product_id;
        });

        return redirect()->route('products.edit', ['id' => $productId])
            ->with('status','Saved');
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
                    ];
                }
            }

            foreach ($existingOptions as $o) {
                $o->name = html_entity_decode((string)$o->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $o->values = $existingValuesByPoId[(int)$o->product_option_id] ?? [];
            }
        }

        return view('products.edit', compact('product', 'currentCategoryId', 'categoryName', 'manufacturerName', 'allOptions', 'existingOptions'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'model' => 'required|string|max:64',
            'sku' => 'nullable|string|max:64',
            'price' => 'required|numeric',
            'quantity' => 'required|integer',
            'status' => 'required|in:0,1',
            'manufacturer_id' => 'nullable|integer',
            'manufacturer_name' => 'nullable|string|max:255',
            'category_id' => 'nullable|integer',
            'category_name' => 'nullable|string|max:1024',
            'description' => 'nullable|string',

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
        ]);

        $langId = (int) config('catalog.default_language_id');
        
        $resolvedManufacturerId = $this->resolveManufacturerId($request->manufacturer_id, $request->manufacturer_name);
        $resolvedCategoryId = $this->resolveCategoryId($request->category_id, $request->category_name);

        $pfx = config('catalog.prefix');

        DB::transaction(function () use ($request, $id, $langId, $pfx, $resolvedManufacturerId, $resolvedCategoryId) {
            /** @var Product $p */
            $p = Product::where('product_id', (int) $id)->firstOrFail();

            $p->model = $request->model ?? ($request->sku ?? '');
            $p->sku = $request->sku ?? '';
            $p->quantity = (int) $request->quantity;
            $p->manufacturer_id = (int) ($resolvedManufacturerId ?? 0);
            $p->price = (float) $request->price;
            $p->status = (int) $request->status;
            
            
            $p->date_modified = now();
$p->date_added = now();
            $p->date_modified = now();
$p->date_modified = now();

            // strict-mode safe
            if ($p->date_available === '0000-00-00') {
                $p->date_available = now()->toDateString();
            }

            $p->save();

            $d = ProductDescription::where('product_id', (int) $id)
                ->where('language_id', $langId)
                ->first();

            if (!$d) {
                $d = new ProductDescription();
                $d->product_id = (int) $id;
                $d->language_id = $langId;
                $d->meta_description = '';
                $d->meta_keyword = '';
                $d->tag = '';
            }

            $d->name = $request->name;
            $d->description = $request->description ?? '';
            $d->meta_title = $request->name;
            $d->save();

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
            $this->saveProductOptions((int) $id, $options);
        });

        return redirect()->route('products.index')->with('status','Saved');
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
     * Safe to call from Add/Edit; replace strategy (idempotent).
     */
    private function saveProductOptions(int $productId, array $options): void
    {
        $p = config('catalog.prefix');

        // Normalize
        $options = array_values(array_filter($options ?? [], fn ($o) => is_array($o)));

        // Replace strategy: clear existing first
        $existingPoIds = DB::table($p.'product_option')
            ->where('product_id', $productId)
            ->pluck('product_option_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        if (!empty($existingPoIds)) {
            DB::table($p.'product_option_value')->whereIn('product_option_id', $existingPoIds)->delete();
        }
        DB::table($p.'product_option')->where('product_id', $productId)->delete();

        if (empty($options)) {
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

        foreach ($options as $opt) {
            $optionId = (int) ($opt['option_id'] ?? 0);
            if ($optionId < 1) {
                continue;
            }

            $required = (int) ($opt['required'] ?? 0);

            $productOptionId = DB::table($p.'product_option')->insertGetId([
                'product_id' => $productId,
                'option_id' => $optionId,
                'value' => '', // not used for select/radio/checkbox/image
                'required' => $required,
            ]);

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


                $row = [
                    'product_option_id' => (int) $productOptionId,
                    'product_id' => $productId,
                    'option_id' => $optionId,
                    'option_value_id' => $ovId,
                    'sku' => (string) ($v['sku'] ?? ''),
                    'quantity' => (int) ($v['quantity'] ?? 0),
                    'subtract' => (int) ($v['subtract'] ?? 1),
                    'price' => (float) ($v['price'] ?? 0),
                    'price_prefix' => (string) ($v['price_prefix'] ?? '+'),
                    // Points kept in DB schema, but not used in UI
                    'points' => 0,
                    'points_prefix' => '+',
                    'weight' => (float) ($v['weight'] ?? 0),
                    'weight_prefix' => (string) ($v['weight_prefix'] ?? '+'),
                ];

                DB::table($p.'product_option_value')->insert($row);
            }
        }
    }


}