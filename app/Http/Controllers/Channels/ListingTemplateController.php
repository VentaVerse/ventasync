<?php

namespace App\Http\Controllers\Channels;

use App\Channels\Listings\ListingStore;
use App\Http\Controllers\Controller;
use App\Models\WatermarkTemplate;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

abstract class ListingTemplateController extends Controller
{
    abstract protected function channel(): string;

    abstract protected function model(): string;

    abstract protected function fieldRules(): array;

    abstract protected function fieldValues(Request $request, array $data): array;

    abstract protected function fieldsView(): string;

    protected function fieldsData(Model $template): array
    {
        return [];
    }

    protected function saveAttributes(Model $template, array $attributes): void
    {
    }

    protected function storeId(): int
    {
        return ListingStore::id($this->channel());
    }

    protected function canManage(): bool
    {
        return auth()->user()?->hasPermission('manage_' . $this->channel() . '/listing_template') ?? false;
    }

    public function index()
    {
        $first = $this->query()->orderBy('name')->first();

        return $first
            ? redirect($this->to('edit', ['template' => $first->id]))
            : redirect($this->to('create'));
    }

    public function create()
    {
        $class = $this->model();

        return $this->form(new $class());
    }

    public function edit()
    {
        return $this->form($this->own($this->templateId()));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $class = $this->model();
        $template = $class::create(['store_id' => $this->storeId()] + $this->values($request, $data));
        $this->saveAttributes($template, (array) $request->input('attributes', []));
        ActivityLogger::log('created', 'Listing template', $template->id, $template->name);

        return redirect($this->to('edit', ['template' => $template->id]))->with('status', 'Listing template created.');
    }

    public function update(Request $request)
    {
        $row = $this->own($this->templateId());
        $data = $this->validated($request, $row);
        $row->update($this->values($request, $data));
        $this->saveAttributes($row, (array) $request->input('attributes', []));
        ActivityLogger::log('updated', 'Listing template', $row->id, $row->name);

        return redirect($this->to('edit', ['template' => $row->id]))->with('status', 'Listing template saved.');
    }

    public function destroy()
    {
        $row = $this->own($this->templateId());
        abort_unless($this->canManage(), 403);
        $name = (string) $row->name;
        $row->delete();
        ActivityLogger::log('deleted', 'Listing template', (int) $row->id, $name);

        return redirect($this->to('index'))->with('status', 'Listing template deleted.');
    }

    private function form(Model $template)
    {
        $ch = $this->channel();

        return view('channels.listing-templates.edit', [
            'channel' => $ch,
            'channelName' => app(\App\Channels\ChannelRegistry::class)->resolveChannelLabel($ch) ?? \Illuminate\Support\Str::headline($ch),
            'template' => $template,
            'templates' => $this->query()->orderBy('name')->get(['id', 'name'])->map(fn ($t) => (object) [
                'id' => (int) $t->id, 'name' => (string) $t->name, 'url' => $this->to('edit', ['template' => $t->id]),
            ]),
            'saveUrl' => $template->exists ? $this->to('update', ['template' => $template->id]) : $this->to('store'),
            'createUrl' => $this->to('create'),
            'destroyUrl' => $template->exists ? $this->to('destroy', ['template' => $template->id]) : null,
            'fieldsView' => $this->fieldsView(),
            'fields' => $this->fieldsData($template),
            'canManage' => $this->canManage(),
            'storeId' => $this->storeId(),
            'productsUrl' => route('ext.' . $this->channel() . '.' . (in_array($ch, ['woocommerce', 'ventacart'], true) ? 'listings' : 'products') . '.index', ['store' => $this->storeId()]),
        ]);
    }

    private function validated(Request $request, ?Model $row): array
    {
        abort_unless($this->canManage(), 403);
        $table = (new ($this->model()))->getTable();

        return $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique($table, 'name')->where('store_id', $this->storeId())->ignore($row?->id)],
            'markup_percent' => 'nullable|numeric|min:0|max:1000',
            'markup_fixed' => 'nullable|numeric|min:0|max:9999999',
            'watermark_template_id' => 'nullable|integer',
        ] + $this->fieldRules());
    }

    private function values(Request $request, array $data): array
    {
        return [
            'name' => trim($data['name']),
            'markup_percent' => $data['markup_percent'] ?? null,
            'markup_fixed' => $data['markup_fixed'] ?? null,
            'watermark_template_id' => WatermarkTemplate::idOrNull($data['watermark_template_id'] ?? null, $this->channel(), $this->storeId()),
        ] + $this->fieldValues($request, $data);
    }

    private function query()
    {
        $class = $this->model();

        return $class::query()->where('store_id', $this->storeId());
    }

    private function templateId(): int
    {
        return (int) request()->route('template');
    }

    private function own(int $id): Model
    {
        return $this->query()->whereKey($id)->firstOrFail();
    }

    private function to(string $action, array $params = []): string
    {
        return route('ext.' . $this->channel() . '.listing-templates.' . $action, ['store' => $this->storeId()] + $params);
    }
}
