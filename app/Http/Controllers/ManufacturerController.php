<?php

namespace App\Http\Controllers;

use App\Models\Catalog\Manufacturer;
use App\Models\Catalog\ManufacturerDescription;
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
        'description' => 'nullable|string',
    ]);

    $m = Manufacturer::create([
        'name' => $request->name,
        'image' => null,
        'sort_order' => (int) ($request->sort_order ?? 0),
    ]);

    $languageId = (int) config('catalog.default_language_id');

    ManufacturerDescription::updateOrCreate(
        ['manufacturer_id' => (int) $m->manufacturer_id, 'language_id' => $languageId],
        ['description' => $request->description ?? '']
    );

    return redirect()->route('manufacturers.index')->with('status','Saved');
}


    public function edit($id)
{
    $manufacturer = Manufacturer::where('manufacturer_id', (int) $id)->firstOrFail();
    $languageId = (int) config('catalog.default_language_id');

    $desc = ManufacturerDescription::where('manufacturer_id', (int) $id)
        ->where('language_id', $languageId)
        ->value('description');

    $manufacturer_description = $desc ?? '';

    return view('manufacturers.edit', compact('manufacturer', 'manufacturer_description'));
}


    public function update(Request $request, $id)
{
    $request->validate([
        'name' => 'required|string|max:64',
        'sort_order' => 'nullable|integer',
        'description' => 'nullable|string',
    ]);

    $m = Manufacturer::where('manufacturer_id', (int) $id)->firstOrFail();
    $m->name = $request->name;
    $m->sort_order = (int) ($request->sort_order ?? 0);
    $m->save();

    $languageId = (int) config('catalog.default_language_id');

    ManufacturerDescription::updateOrCreate(
        ['manufacturer_id' => (int) $id, 'language_id' => $languageId],
        ['description' => $request->description ?? '']
    );

    return redirect()->route('manufacturers.index')->with('status','Saved');
}


    public function destroy($id)
    {
        Manufacturer::where('manufacturer_id', (int) $id)->delete();
        return redirect()->route('manufacturers.index')->with('status','Saved');
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
