<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\WatermarkTemplate;
use App\Services\ActivityLogger;
use App\Services\Media\Watermarker;
use App\Support\Catalog\ProductImages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * WATERMARK TEMPLATES, IN THE CATALOG (VENT2-128).
 *
 * the owner, 2026-09-10: "This is part of Catalog. so inside catalog. Make it
 * a template CRUD. Then for every listing, and every group, they can
 * choose the template."
 *
 * A template is a named mark. It is defined here once, and every listing
 * and every product group picks from the same list, so a logo changed here
 * changes everywhere it is used - which is the whole point of a template
 * over five copies of five settings.
 *
 * THE PICTURE IS CHOSEN FROM THE IMAGE LIBRARY, not uploaded into the
 * template (the owner's own call between the two): one logo, uploaded once,
 * pointed at by every template that wants it.
 */
class WatermarkTemplateController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $templates = WatermarkTemplate::query()
            ->when($q !== '', fn ($query) => $query->where('name', 'like', '%' . $q . '%'))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('catalog.watermarks.index', compact('templates', 'q'));
    }

    public function create()
    {
        return view('catalog.watermarks.form', [
            'template' => new WatermarkTemplate(['position' => 'bottom-right', 'size_percent' => 18, 'margin_percent' => 3, 'opacity' => 1, 'stamp_main' => false]),
            'mode' => 'create',
            'browseUrl' => route('products.images.browse'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $template = WatermarkTemplate::create($data);

        ActivityLogger::log('created', 'Watermark template', (int) $template->id, (string) $template->name);

        return redirect()->route('watermarks.index')
            ->with('status', 'Template saved. Choose it on a listing or a product group to use it.');
    }

    public function edit(int $id)
    {
        return view('catalog.watermarks.form', [
            'template' => WatermarkTemplate::findOrFail($id),
            'mode' => 'edit',
            'browseUrl' => route('products.images.browse'),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $template = WatermarkTemplate::findOrFail($id);
        $template->update($this->validated($request, $id));

        ActivityLogger::log('updated', 'Watermark template', (int) $template->id, (string) $template->name);

        return redirect()->route('watermarks.index')->with('status', 'Template saved.');
    }

    public function destroy(int $id)
    {
        $template = WatermarkTemplate::findOrFail($id);
        $name = (string) $template->name;

        /* Nothing is repaired for the listings that pointed here, and
           nothing needs to be: a listing naming a template that no longer
           exists simply stamps nothing, exactly as if it named none. The
           alternative - refusing to delete while anything uses it - makes
           a template impossible to retire without hunting every listing
           that ever chose it. */
        $template->delete();

        ActivityLogger::log('deleted', 'Watermark template', $id, $name);

        return redirect()->route('watermarks.index')
            ->with('status', 'Template deleted. Anything that used it now goes up unmarked.');
    }

    /**
     * A preview of this mark on a real photograph, so the seller sees what
     * the buyer will see before a single product goes up. Answers the
     * stamped bytes, or the plain picture when the mark cannot be used -
     * the same rule the push follows.
     */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'image_path' => 'required|string|max:1000',
            'position' => ['nullable', Rule::in(Watermarker::POSITIONS)],
            'size_percent' => 'nullable|numeric|min:1|max:100',
            'margin_percent' => 'nullable|numeric|min:0|max:40',
            'opacity' => 'nullable|numeric|min:0|max:1',
            'sample' => 'nullable|string|max:1000',
        ]);

        $mark = ProductImages::acceptable([$data['image_path']]);
        if ($mark === []) {
            abort(404);
        }

        /* A photograph from the library to try it on: whichever the caller
           named, else any catalog image. A preview of a mark on nothing
           would show the mark, which is not the question being asked. */
        $sample = ProductImages::acceptable([(string) ($data['sample'] ?? '')]);
        $samplePath = $sample[0] ?? $this->anyCatalogImage();
        if ($samplePath === null) {
            abort(404);
        }

        $bytes = app(Watermarker::class)->stamp(
            public_path('storage/' . $samplePath),
            public_path('storage/' . $mark[0]),
            (string) ($data['position'] ?? 'bottom-right'),
            (float) ($data['size_percent'] ?? 18),
            (float) ($data['opacity'] ?? 1),
            (float) ($data['margin_percent'] ?? 3),
        );

        if ($bytes === null) {
            return response()->file(public_path('storage/' . $samplePath));
        }

        return response($bytes, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'no-store']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $ignoreId): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('watermark_templates', 'name')->ignore($ignoreId)],
            'image_path' => 'required|string|max:1000',
            'position' => ['required', Rule::in(Watermarker::POSITIONS)],
            'size_percent' => 'required|numeric|min:1|max:100',
            'margin_percent' => 'required|numeric|min:0|max:40',
            'opacity' => 'required|numeric|min:0|max:1',
            'stamp_main' => 'nullable|boolean',
        ]);

        /* The mark has to be a file the library actually holds: this path
           is read at push time, on every picture of every product that
           uses it, and a hidden input is client input like any other. */
        $accepted = ProductImages::acceptable([$data['image_path']]);
        if ($accepted === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'image_path' => 'Pick the mark from the image library.',
            ]);
        }
        $data['image_path'] = $accepted[0];
        $data['stamp_main'] = $request->boolean('stamp_main');

        return $data;
    }

    /** Any picture in the library, to try a mark on. */
    private function anyCatalogImage(): ?string
    {
        $pfx = (string) config('catalog.prefix');
        $path = \Illuminate\Support\Facades\DB::table($pfx . 'product')
            ->whereNotNull('image')->where('image', '<>', '')->orderByDesc('product_id')->value('image');

        return $path && Storage::disk('public')->exists($path) ? $path : null;
    }
}
