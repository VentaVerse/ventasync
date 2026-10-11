<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Brand;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class BrandController extends Controller
{

private function isWordStartMatch(string $haystack, string $term): bool
{
    $h = mb_strtolower($haystack, 'UTF-8');
    $t = mb_strtolower(trim($term), 'UTF-8');
    if ($t === '') return false;

    return (bool) preg_match('/(^|[^a-z0-9])' . preg_quote($t, '/') . '/i', $h);
}

    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $brands = Brand::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where('name', 'like', '%' . $q . '%');
            })
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        foreach ($brands as $row) {
    if (isset($row->name)) $row->name = html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

return view('catalog.brands.index', compact('brands', 'q'));
    }

    public function create()
    {
        return view('catalog.brands.create');
    }

    public function store(Request $request)
{
    $request->validate([
        'name' => 'required|string|max:64',
        'sort_order' => 'nullable|integer',
    ]);

    $m = Brand::create([
        'name' => $request->name,
        'image' => null,
        'sort_order' => (int) ($request->sort_order ?? 0),
    ]);

    ActivityLogger::log('created', 'Brand', (int) $m->brand_id, $request->name);

    return redirect()->route('brands.index')->with('status','Saved');
}


    public function edit($id)
{
    $brand = Brand::where('brand_id', (int) $id)->firstOrFail();

    $cameFrom = \App\Support\BackTo::capture();
    $returnUrl = str_contains($cameFrom, '/brands') && !str_contains($cameFrom, '/brands/' . $id) ? $cameFrom : null;

    return view('catalog.brands.edit', compact('brand', 'returnUrl'));
}


    public function update(Request $request, $id)
{
    $request->validate([
        'name' => 'required|string|max:64',
        'sort_order' => 'nullable|integer',
    ]);

    $m = Brand::where('brand_id', (int) $id)->firstOrFail();
    $original = $m->getAttributes();
    $m->name = $request->name;
    $m->sort_order = (int) ($request->sort_order ?? 0);
    $m->save();

    $changes = ActivityLogger::diff($original, $m->getAttributes(), ['name', 'sort_order']);
    ActivityLogger::log('updated', 'Brand', (int) $id, $request->name, $changes);

    return \App\Support\BackTo::follow($request->input('_return'), 'brands.index')->with('status', 'Saved');
}


    public function destroy($id)
    {
        $name = Brand::where('brand_id', (int) $id)->value('name') ?? '#' . $id;
        Brand::where('brand_id', (int) $id)->delete();
        ActivityLogger::log('deleted', 'Brand', (int) $id, $name);
        return redirect()->route('brands.index')->with('status','Saved');
    }

    public function bulkDestroy(Request $request)
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));

        if (empty($ids)) {
            return redirect()->route('brands.index')->with('status', 'No items selected.');
        }

        Brand::whereIn('brand_id', $ids)->delete();

        ActivityLogger::log('deleted', 'Brand', null, count($ids) . ' brands');

        return redirect()->route('brands.index')->with('status', 'Deleted selected brands.');
    }

    public function storeInline(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:64',
            'sort_order' => 'nullable|integer',
        ]);

        $name = trim($data['name']);

        $existing = Brand::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name, 'UTF-8')])
            ->first(['brand_id', 'name']);

        if ($existing) {
            return response()->json([
                'ok' => true,
                'id' => (int) $existing->brand_id,
                'name' => (string) $existing->name,
                'created' => false,
            ]);
        }

        $m = Brand::create([
            'name' => $name,
            'image' => null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        ActivityLogger::log('created', 'Brand', (int) $m->brand_id, $name);

        return response()->json([
            'ok' => true,
            'id' => (int) $m->brand_id,
            'name' => (string) $m->name,
            'created' => true,
        ], 201);
    }

    public function lookup(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $rows = Brand::query()
            ->when($q !== '', fn($query) => $query->where('name', 'like', '%' . $q . '%'))
            ->orderBy('name')
            ->limit(20)
            ->get(['brand_id', 'name']);

        return response()->json($rows);
    }
}
