<?php

namespace App\Http\Controllers\Channels;

use App\Http\Controllers\Controller;
use App\Models\WatermarkTemplate;
use App\Services\ActivityLogger;
use App\Services\Media\Watermarker;
use App\Support\Catalog\ProductImages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * WATERMARK TEMPLATES FOR ONE STORE.
 *
 * A template is a named mark: which picture, where it sits, how big and how
 * strong. A listing of this store picks one, or borrows the one its product
 * group names, and the mark is stamped on the pictures at the moment they
 * are sent.
 *
 * This is the same object as a description template, and is kept the same
 * way. A mark carries a shop's words as much as its logo, COD accepted or
 * an authorised distributor line, so it belongs to the store that says
 * them and never travels to another.
 *
 * THE PICTURE IS CHOSEN FROM THE IMAGE LIBRARY, not uploaded into the
 * template: one logo, uploaded once, pointed at by every template that
 * wants it, in whichever store.
 *
 * One base for every channel; each extension subclasses it with two lines
 * so the permission catalogue keys the page on that channel
 * (shopee/watermark_template, lazada/watermark_template, and so on).
 */
abstract class WatermarkTemplateController extends Controller
{
    /** The channel this subclass serves: 'shopee', 'lazada', ... */
    abstract protected function integration(): string;

    /** The store the request is acting for, bound by the channel's own middleware. */
    protected function storeId(): int
    {
        $key = $this->integration() . '.route-store';
        if (app()->bound($key)) {
            return (int) app($key)->id;
        }

        // A channel whose middleware leaves the segment in place (Venta).
        return (int) (request()->route('store') ?? 0);
    }

    protected function canManage(): bool
    {
        return auth()->user()?->hasPermission('manage_' . $this->integration() . '/watermark_template') ?? false;
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $templates = WatermarkTemplate::ofStore($this->integration(), $this->storeId())
            ->when($q !== '', fn ($query) => $query->where('name', 'like', '%' . $q . '%'))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        /* The page is handed finished URLs rather than building them from a
         * channel name, so nothing on it is a dynamic route() call. */
        return view('channels.watermarks.index', [
            'templates' => $templates,
            'q' => $q,
            'channelName' => $this->channelName(),
            'indexUrl' => $this->url('index'),
            'createUrl' => $this->url('create'),
            'editUrl' => fn (int $id) => $this->url('edit', ['template' => $id]),
            'destroyUrl' => fn (int $id) => $this->url('destroy', ['template' => $id]),
            'canManage' => $this->canManage(),
        ]);
    }

    public function create()
    {
        return view('channels.watermarks.form', $this->formData(
            new WatermarkTemplate(['position' => 'bottom-right', 'size_percent' => 18, 'offset_x_percent' => 0, 'offset_y_percent' => 0, 'opacity' => 1]),
            'create',
            $this->url('store'),
        ));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $template = WatermarkTemplate::create($data + [
            'integration' => $this->integration(),
            'store_id' => $this->storeId(),
        ]);

        ActivityLogger::log('created', 'Watermark template', (int) $template->id, (string) $template->name);

        return redirect()->to($this->url('index'))
            ->with('status', 'Template saved. Choose it on a listing or a product group to use it.');
    }

    public function edit(int $template)
    {
        return view('channels.watermarks.form', $this->formData(
            $this->ownRow($template),
            'edit',
            $this->url('update', ['template' => $template]),
        ));
    }

    public function update(Request $request, int $template)
    {
        $row = $this->ownRow($template);
        $row->update($this->validated($request, $template));

        ActivityLogger::log('updated', 'Watermark template', (int) $row->id, (string) $row->name);

        return redirect()->to($this->url('index'))->with('status', 'Template saved.');
    }

    public function destroy(int $template)
    {
        $row = $this->ownRow($template);
        $name = (string) $row->name;

        /* Nothing is repaired for the listings that pointed here, and
           nothing needs to be: a listing naming a template that no longer
           exists simply stamps nothing, exactly as if it named none. The
           alternative - refusing to delete while anything uses it - makes
           a template impossible to retire without hunting every listing
           that ever chose it. */
        $row->delete();

        ActivityLogger::log('deleted', 'Watermark template', $template, $name);

        return redirect()->to($this->url('index'))
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
        /* Numbers here are CLAMPED, never refused. A preview is a question
           asked while somebody is still deciding, and refusing an
           out-of-range value answered with an error the picture could not
           show. The SAVE still validates strictly; this only has to draw
           something true. */
        $data = $request->validate([
            'image_path' => 'required|string|max:1000',
            'position' => ['nullable', Rule::in(Watermarker::POSITIONS)],
            'size_percent' => 'nullable|numeric',
            'offset_x_percent' => 'nullable|numeric',
            'offset_y_percent' => 'nullable|numeric',
            'transparency' => 'nullable|numeric',
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

        /* Through the disk, not public/storage: the symlink is a web
           server convenience, and a missing one would blank every preview
           while the files sit exactly where the library put them. */
        $disk = Storage::disk('public');

        /* A catalog with no usable photograph still gets a preview, drawn
           on a plain square. A fresh install has no products at all, and a
           preview that answers 404 there teaches the seller the mark is
           broken when it is only untried. */
        $sampleFile = $samplePath !== null ? $disk->path($samplePath) : $this->plainSquare();
        if ($sampleFile === null) {
            abort(404);
        }

        $bytes = app(Watermarker::class)->stamp(
            $sampleFile,
            $disk->path($mark[0]),
            (string) ($data['position'] ?? 'bottom-right'),
            max(1.0, min(100.0, (float) ($data['size_percent'] ?? 18))),
            WatermarkTemplate::opacityFor((float) ($data['transparency'] ?? 0)),
            (float) ($data['offset_x_percent'] ?? 0),
            (float) ($data['offset_y_percent'] ?? 0),
        );

        if ($bytes === null) {
            return response()->file($sampleFile);
        }

        return response($bytes, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'no-store']);
    }

    /** @return array<string, mixed> */
    private function formData(WatermarkTemplate $template, string $mode, string $action): array
    {
        return [
            'template' => $template,
            'mode' => $mode,
            'action' => $action,
            'indexUrl' => $this->url('index'),
            'previewUrl' => $this->url('preview'),
            'channelName' => $this->channelName(),
            'browseUrl' => route('products.images.browse'),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $ignoreId): array
    {
        abort_unless($this->canManage(), 403);

        $data = $request->validate([
            /* One name within this store. Another store keeping a mark of
               the same name is not a clash: it never sees this one. */
            'name' => ['required', 'string', 'max:120', Rule::unique('watermark_templates', 'name')
                ->where('integration', $this->integration())
                ->where('store_id', $this->storeId())
                ->ignore($ignoreId)],
            'image_path' => 'required|string|max:1000',
            'position' => ['required', Rule::in(Watermarker::POSITIONS)],
            'size_percent' => 'required|numeric|min:1|max:100',
            'offset_x_percent' => 'required|numeric|min:-50|max:50',
            'offset_y_percent' => 'required|numeric|min:-50|max:50',
            'transparency' => 'required|numeric|min:0|max:' . WatermarkTemplate::MAX_TRANSPARENCY,
        ]);

        /* Shown as transparency, stored as the opacity the stamper
           multiplies by. */
        $data['opacity'] = WatermarkTemplate::opacityFor((float) $data['transparency']);
        unset($data['transparency']);

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

        return $data;
    }

    /** A template of THIS store, or a 404. One store never edits another's. */
    private function ownRow(int $id): WatermarkTemplate
    {
        return WatermarkTemplate::ofStore($this->integration(), $this->storeId())->where('id', $id)->firstOrFail();
    }

    /** @param  array<string, mixed>  $params */
    private function url(string $action, array $params = []): string
    {
        return route('ext.' . $this->integration() . '.watermarks.' . $action, ['store' => $this->storeId()] + $params);
    }

    private function channelName(): string
    {
        return app(\App\Integrations\IntegrationRegistry::class)->resolveMarketplaceSourceLabel($this->integration())
            ?? \Illuminate\Support\Str::headline($this->integration());
    }

    /**
     * A photograph from the library to try a mark on.
     *
     * Several candidates, not one: a product's image column can name a file
     * that is no longer on disk, and one stale row on the newest product
     * used to leave the whole preview blank.
     */
    private function anyCatalogImage(): ?string
    {
        $pfx = (string) config('catalog.prefix');
        $disk = Storage::disk('public');

        $paths = \Illuminate\Support\Facades\DB::table($pfx . 'product')
            ->whereNotNull('image')->where('image', '<>', '')
            ->orderByDesc('product_id')->limit(50)->pluck('image');

        foreach ($paths as $path) {
            $path = ProductImages::acceptable([(string) $path])[0] ?? null;
            if ($path !== null && $disk->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * A plain light square to stamp when the catalog offers no photograph,
     * written where the stamper can read it. Deliberately not a stock
     * product picture: a mark judged against someone else's photo is the
     * preview that tells you nothing.
     */
    private function plainSquare(): ?string
    {
        $image = @imagecreatetruecolor(800, 800);
        if ($image === false) {
            return null;
        }
        imagefill($image, 0, 0, imagecolorallocate($image, 238, 238, 238));

        $file = tempnam(sys_get_temp_dir(), 'wm') . '.jpg';
        $ok = imagejpeg($image, $file, 90);

        return $ok ? $file : null;
    }
}
