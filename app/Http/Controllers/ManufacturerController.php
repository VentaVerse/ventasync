<?php

namespace App\Http\Controllers;

use App\Models\Catalog\Manufacturer;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class ManufacturerController extends Controller
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

        $manufacturers = Manufacturer::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where('name', 'like', '%' . $q . '%');
            })
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        foreach ($manufacturers as $row) {
    if (isset($row->name)) $row->name = html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

return view('manufacturers.index', compact('manufacturers', 'q'));
    }

    public function create()
    {
        return view('manufacturers.create');
    }

    public function store(Request $request)
{
    $request->validate([
        'name' => 'required|string|max:64',
        'sort_order' => 'nullable|integer',
    ]);

    $m = Manufacturer::create([
        'name' => $request->name,
        'image' => null,
        'sort_order' => (int) ($request->sort_order ?? 0),
    ]);

    ActivityLogger::log('created', 'Manufacturer', (int) $m->manufacturer_id, $request->name);

    return redirect()->route('manufacturers.index')->with('status','Saved');
}


    public function edit($id)
{
    $manufacturer = Manufacturer::where('manufacturer_id', (int) $id)->firstOrFail();

    return view('manufacturers.edit', compact('manufacturer'));
}


    public function update(Request $request, $id)
{
    $request->validate([
        'name' => 'required|string|max:64',
        'sort_order' => 'nullable|integer',
    ]);

    $m = Manufacturer::where('manufacturer_id', (int) $id)->firstOrFail();
    $original = $m->getAttributes();
    $m->name = $request->name;
    $m->sort_order = (int) ($request->sort_order ?? 0);
    $m->save();

    $changes = ActivityLogger::diff($original, $m->getAttributes(), ['name', 'sort_order']);
    ActivityLogger::log('updated', 'Manufacturer', (int) $id, $request->name, $changes);

    return redirect()->route('manufacturers.index')->with('status','Saved');
}


    public function destroy($id)
    {
        $name = Manufacturer::where('manufacturer_id', (int) $id)->value('name') ?? '#' . $id;
        Manufacturer::where('manufacturer_id', (int) $id)->delete();
        ActivityLogger::log('deleted', 'Manufacturer', (int) $id, $name);
        return redirect()->route('manufacturers.index')->with('status','Saved');
    }

    public function bulkDestroy(Request $request)
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));

        if (empty($ids)) {
            return redirect()->route('manufacturers.index')->with('status', 'No items selected.');
        }

        Manufacturer::whereIn('manufacturer_id', $ids)->delete();

        ActivityLogger::log('deleted', 'Manufacturer', null, count($ids) . ' manufacturers');

        return redirect()->route('manufacturers.index')->with('status', 'Deleted selected manufacturers.');
    }

    public function storeInline(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:64',
            'sort_order' => 'nullable|integer',
        ]);

        $name = trim($data['name']);

        $existing = Manufacturer::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name, 'UTF-8')])
            ->first(['manufacturer_id', 'name']);

        if ($existing) {
            return response()->json([
                'ok' => true,
                'id' => (int) $existing->manufacturer_id,
                'name' => (string) $existing->name,
                'created' => false,
            ]);
        }

        $m = Manufacturer::create([
            'name' => $name,
            'image' => null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        ActivityLogger::log('created', 'Manufacturer', (int) $m->manufacturer_id, $name);

        return response()->json([
            'ok' => true,
            'id' => (int) $m->manufacturer_id,
            'name' => (string) $m->name,
            'created' => true,
        ], 201);
    }

    public function lookup(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $rows = Manufacturer::query()
            ->when($q !== '', fn($query) => $query->where('name', 'like', '%' . $q . '%'))
            ->orderBy('name')
            ->limit(20)
            ->get(['manufacturer_id', 'name']);

        return response()->json($rows);
    }
}
