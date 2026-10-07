<?php

namespace App\Http\Controllers\Channels;

use App\Http\Controllers\Controller;
use App\Models\DescriptionTemplate;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

abstract class DescriptionTemplateController extends Controller
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
        return auth()->user()?->hasPermission('manage_' . $this->integration() . '/description_template') ?? false;
    }

    public function index()
    {
        $ch = $this->integration();
        $store = $this->storeId();

        return view('channels.description-templates.index', [
            'richText' => $ch !== 'shopee',
            'channelName' => app(\App\Integrations\IntegrationRegistry::class)->resolveMarketplaceSourceLabel($ch)
                ?? \Illuminate\Support\Str::headline($ch),
            'createUrl' => route('ext.' . $ch . '.description-templates.store', ['store' => $store]),
            'templates' => DescriptionTemplate::forStore($ch, $store)->map(fn ($t) => (object) [
                'id' => (int) $t->id,
                'name' => (string) $t->name,
                'body' => (string) $t->body,
                'update_url' => route('ext.' . $ch . '.description-templates.update', ['store' => $store, 'template' => $t->id]),
                'destroy_url' => route('ext.' . $ch . '.description-templates.destroy', ['store' => $store, 'template' => $t->id]),
            ]),
            'canManage' => $this->canManage(),
            'browseUrl' => $ch !== 'shopee' && (auth()->user()?->hasPermission('view_catalog/product_image') ?? false)
                ? route('products.images.browse')
                : null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $template = DescriptionTemplate::create([
            'integration' => $this->integration(),
            'store_id' => $this->storeId(),
            'name' => $data['name'],
            'body' => $this->body($data['body'] ?? null),
        ]);
        ActivityLogger::log('created', 'Description Template', $template->id, $template->name);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'id' => (int) $template->id,
                'name' => (string) $template->name,
                'body' => (string) $template->body,
            ]);
        }

        return $this->backToIndex('Template created.');
    }

    public function update(Request $request, int $template)
    {
        $row = $this->ownRow($template);
        $data = $this->validated($request);
        $row->update(['name' => $data['name'], 'body' => $this->body($data['body'] ?? null)]);
        ActivityLogger::log('updated', 'Description Template', $row->id, $row->name);

        return $this->backToIndex('Template saved.');
    }

    public function destroy(int $template)
    {
        $row = $this->ownRow($template);
        $name = (string) $row->name;
        $row->delete();
        ActivityLogger::log('deleted', 'Description Template', $template, $name);

        return $this->backToIndex('Template "' . e($name) . '" deleted.');
    }

    private function validated(Request $request): array
    {
        abort_unless($this->canManage(), 403);

        return $request->validate([
            'name' => 'required|string|max:128',
            'body' => 'nullable|string|max:20000',
        ]);
    }

    private function body(?string $body): string
    {
        if ($this->integration() === 'shopee') {
            return \App\Support\Catalog\DescriptionText::of($body);
        }

        return \App\Support\Catalog\DescriptionText::isBlank($body) ? '' : trim(\App\Support\Catalog\DescriptionHtml::store($body));
    }

    private function ownRow(int $id): DescriptionTemplate
    {
        return DescriptionTemplate::query()
            ->where('id', $id)
            ->where('integration', $this->integration())
            ->where('store_id', $this->storeId())
            ->firstOrFail();
    }

    private function backToIndex(string $status)
    {
        return redirect()
            ->route('ext.' . $this->integration() . '.description-templates.index', ['store' => $this->storeId()])
            ->with('status', $status);
    }
}
