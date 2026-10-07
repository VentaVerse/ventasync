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

abstract class WatermarkTemplateController extends Controller
{
    abstract protected function integration(): string;

    protected function storeId(): int
    {
        $key = $this->integration() . '.route-store';
        if (app()->bound($key)) {
            return (int) app($key)->id;
        }

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

        $row->delete();

        ActivityLogger::log('deleted', 'Watermark template', $template, $name);

        return redirect()->to($this->url('index'))
            ->with('status', 'Template deleted. Anything that used it now goes up unmarked.');
    }

    public function preview(Request $request)
    {
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

        $sample = ProductImages::acceptable([(string) ($data['sample'] ?? '')]);
        $samplePath = $sample[0] ?? $this->anyCatalogImage();

        $disk = Storage::disk('public');

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

    private function validated(Request $request, ?int $ignoreId): array
    {
        abort_unless($this->canManage(), 403);

        $data = $request->validate([
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

        $data['opacity'] = WatermarkTemplate::opacityFor((float) $data['transparency']);
        unset($data['transparency']);

        $accepted = ProductImages::acceptable([$data['image_path']]);
        if ($accepted === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'image_path' => 'Pick the mark from the image library.',
            ]);
        }
        $data['image_path'] = $accepted[0];

        return $data;
    }

    private function ownRow(int $id): WatermarkTemplate
    {
        return WatermarkTemplate::ofStore($this->integration(), $this->storeId())->where('id', $id)->firstOrFail();
    }

    private function url(string $action, array $params = []): string
    {
        return route('ext.' . $this->integration() . '.watermarks.' . $action, ['store' => $this->storeId()] + $params);
    }

    private function channelName(): string
    {
        return app(\App\Integrations\IntegrationRegistry::class)->resolveMarketplaceSourceLabel($this->integration())
            ?? \Illuminate\Support\Str::headline($this->integration());
    }

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
