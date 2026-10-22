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
    use Concerns\RefreshesListingLists;

    abstract protected function channel(): string;

    abstract protected function model(): string;

    abstract protected function fieldRules(): array;

    abstract protected function fieldValues(Request $request, array $data): array;

    abstract protected function applier(): \App\Channels\Listings\Templates\TemplateFields;

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
        $rows = $this->query()->orderBy('name')->get(['id', 'name', 'markup_percent', 'markup_fixed']);
        $usage = \App\Channels\Listings\TemplateUsage::listingTemplate($this->channel(), $this->storeId(), $rows->pluck('id')->all());

        return view('channels.listing-templates.index', [
            'channelName' => $this->channelName(),
            'templates' => $rows->map(fn ($t) => (object) [
                'id' => (int) $t->id,
                'name' => (string) $t->name,
                'price_rule' => $this->priceRule($t),
                'used_by' => \App\Channels\Listings\TemplateUsage::sentence($usage[(int) $t->id] ?? []),
                'edit_url' => $this->to('edit', ['template' => $t->id]),
                'destroy_url' => $this->to('destroy', ['template' => $t->id]),
            ]),
            'createUrl' => $this->to('create'),
            'canManage' => $this->canManage(),
        ]);
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

        return redirect($this->to('index'))->with('status', 'Listing template created.');
    }

    public function update(Request $request)
    {
        $row = $this->own($this->templateId());
        $data = $this->validated($request, $row);
        $row->update($this->values($request, $data));
        $this->saveAttributes($row, (array) $request->input('attributes', []));
        ActivityLogger::log('updated', 'Listing template', $row->id, $row->name);

        return redirect($this->to('index'))->with('status', 'Listing template saved.');
    }

    public function destroy()
    {
        $row = $this->own($this->templateId());
        abort_unless($this->canManage(), 403);
        $name = (string) $row->name;
        $usedBy = \App\Channels\Listings\TemplateUsage::sentence(\App\Channels\Listings\TemplateUsage::listingTemplate($this->channel(), $this->storeId(), [(int) $row->id])[(int) $row->id] ?? []);
        if ($usedBy !== '') {
            return redirect($this->to('index'))->with('error', 'Not deleted. "' . $name . '" is used by ' . $usedBy . '.');
        }
        $row->delete();
        ActivityLogger::log('deleted', 'Listing template', (int) $row->id, $name);

        return redirect($this->to('index'))->with('status', 'Listing template deleted.');
    }

    public function preview(Request $request)
    {
        abort_unless($this->canManage(), 403);
        $productIds = $this->productIds($request);
        $templates = $this->query()->orderBy('name')->get();
        if ($productIds === [] || $templates->isEmpty()) {
            return redirect($this->productsUrl())->with('error', $productIds === [] ? 'No products selected.' : 'Create a listing template first.');
        }
        $template = $templates->firstWhere('id', (int) $request->input('template_id')) ?? $templates->first();

        return view('channels.listing-templates.apply', [
            'channelName' => $this->channelName(),
            'template' => $template,
            'templates' => $templates,
            'productIds' => $productIds,
            'rows' => app(\App\Channels\Listings\Templates\TemplateApply::class)->preview($this->applier(), $template, $productIds),
            'previewUrl' => $this->to('preview'),
            'applyUrl' => $this->to('apply'),
            'productsUrl' => $this->backTo($request),
            'backLabel' => $request->filled('_return') ? 'Product group' : 'Products',
            'returnTo' => $request->filled('_return') ? $this->backTo($request) : null,
        ]);
    }

    public function apply(Request $request)
    {
        abort_unless($this->canManage(), 403);
        $productIds = $this->productIds($request);
        $template = $this->own((int) $request->input('template_id'));
        $replace = [];
        foreach ((array) $request->input('replace', []) as $key => $choices) {
            foreach ((array) $choices as $pid => $choice) {
                if ($choice === 'replace') {
                    $replace[(string) $key][] = (int) $pid;
                }
            }
        }
        $changes = $productIds === [] ? 0 : app(\App\Channels\Listings\Templates\TemplateApply::class)->apply($this->applier(), $template, $productIds, $replace, $this->channel());

        return redirect($this->backTo($request))->with('status', $changes === 0
            ? 'Nothing to change.'
            : $changes . ' ' . ($changes === 1 ? 'change' : 'changes') . ' applied from ' . $template->name . '.');
    }

    private function backTo(Request $request): string
    {
        return \App\Support\BackTo::safe($request->input('_return'), $this->productsUrl());
    }

    private function usedBy(int $templateId)
    {
        $ch = $this->channel();
        $key = in_array($ch, ['woocommerce', 'ventacart'], true) ? 'group' : 'id';

        return \Illuminate\Support\Facades\DB::table('ch_' . $ch . '_product_groups')
            ->where('store_id', $this->storeId())->where('listing_template_id', $templateId)
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn ($g) => (object) [
                'name' => (string) $g->name,
                'url' => route('ext.' . $ch . '.product-groups.edit', ['store' => $this->storeId(), $key => $g->id]),
            ]);
    }

    private function productIds(Request $request): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('product_ids', [])), fn ($v) => $v > 0)));

        return $ids === [] ? [] : \Illuminate\Support\Facades\DB::table('products')->whereIn('product_id', array_slice($ids, 0, 500))->pluck('product_id')->map(fn ($v) => (int) $v)->all();
    }

    private function channelName(): string
    {
        return app(\App\Channels\ChannelRegistry::class)->resolveChannelLabel($this->channel()) ?? \Illuminate\Support\Str::headline($this->channel());
    }

    private function productsUrl(): string
    {
        $ch = $this->channel();

        return route('ext.' . $this->channel() . '.' . (in_array($ch, ['woocommerce', 'ventacart'], true) ? 'listings' : 'products') . '.index', ['store' => $this->storeId()]);
    }

    private function form(Model $template)
    {
        $ch = $this->channel();

        return view('channels.listing-templates.edit', [
            'channel' => $ch,
            'channelName' => $this->channelName(),
            'template' => $template,
            'usedBy' => $template->exists ? $this->usedBy((int) $template->id) : collect(),
            'saveUrl' => $template->exists ? $this->to('update', ['template' => $template->id]) : $this->to('store'),
            'indexUrl' => $this->to('index'),
            'fields' => $this->fieldsData($template),
            'canManage' => $this->canManage(),
            'storeId' => $this->storeId(),
        ]);
    }

    private function priceRule(Model $t): string
    {
        $parts = [];
        if ((float) $t->markup_percent > 0) {
            $parts[] = '+' . rtrim(rtrim(number_format((float) $t->markup_percent, 2), '0'), '.') . '%';
        }
        if ((float) $t->markup_fixed > 0) {
            $parts[] = '+' . number_format((float) $t->markup_fixed, 2);
        }

        return $parts === [] ? 'None' : implode(' and ', $parts);
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
