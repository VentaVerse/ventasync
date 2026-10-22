<?php

namespace App\Http\Controllers\Channels;

use App\Http\Controllers\Controller;
use App\Models\DescriptionTemplate;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

abstract class DescriptionTemplateController extends Controller
{
    abstract protected function channel(): string;

    protected function storeId(): int
    {
        $key = $this->channel() . '.route-store';
        if (app()->bound($key)) {
            return (int) app($key)->id;
        }

        return (int) (request()->route('store') ?? 0);
    }

    protected function canManage(): bool
    {
        return auth()->user()?->hasPermission('manage_' . $this->channel() . '/description_template') ?? false;
    }

    public function index()
    {
        $ch = $this->channel();
        $store = $this->storeId();
        $rows = DescriptionTemplate::forStore($ch, $store);
        $usage = \App\Channels\Listings\TemplateUsage::description($ch, $store, $rows->pluck('id')->all());

        return view('channels.description-templates.index', [
            'richText' => $ch !== 'shopee',
            'channelName' => app(\App\Channels\ChannelRegistry::class)->resolveChannelLabel($ch)
                ?? \Illuminate\Support\Str::headline($ch),
            'createUrl' => route('ext.' . $ch . '.description-templates.store', ['store' => $store]),
            'templates' => $rows->map(fn ($t) => (object) [
                'id' => (int) $t->id,
                'name' => (string) $t->name,
                'body' => (string) $t->body,
                'used_by' => \App\Channels\Listings\TemplateUsage::sentence($usage[(int) $t->id] ?? []),
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
            'channel' => $this->channel(),
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
        abort_unless($this->canManage(), 403);
        $row = $this->ownRow($template);
        $name = (string) $row->name;
        $usedBy = \App\Channels\Listings\TemplateUsage::sentence(\App\Channels\Listings\TemplateUsage::description($this->channel(), $this->storeId(), [$template])[$template] ?? []);
        if ($usedBy !== '') {
            return redirect()
                ->route('ext.' . $this->channel() . '.description-templates.index', ['store' => $this->storeId()])
                ->with('error', 'Not deleted. "' . $name . '" is used by ' . $usedBy . '.');
        }
        $row->delete();
        ActivityLogger::log('deleted', 'Description Template', $template, $name);

        return $this->backToIndex('Template "' . $name . '" deleted.');
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
        if ($this->channel() === 'shopee') {
            return \App\Support\Catalog\DescriptionText::of($body);
        }

        return \App\Support\Catalog\DescriptionText::isBlank($body) ? '' : trim(\App\Support\Catalog\DescriptionHtml::store($body));
    }

    private function ownRow(int $id): DescriptionTemplate
    {
        return DescriptionTemplate::query()
            ->where('id', $id)
            ->where('channel', $this->channel())
            ->where('store_id', $this->storeId())
            ->firstOrFail();
    }

    private function backToIndex(string $status)
    {
        return redirect()
            ->route('ext.' . $this->channel() . '.description-templates.index', ['store' => $this->storeId()])
            ->with('status', $status);
    }
}
