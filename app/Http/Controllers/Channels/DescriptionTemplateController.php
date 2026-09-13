<?php

namespace App\Http\Controllers\Channels;

use App\Http\Controllers\Controller;
use App\Models\DescriptionTemplate;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

/**
 * Description templates for one store (the owner, 2026-09-07): a named block of
 * the store's own words that a listing puts above or below the product's
 * description. Written once here, pointed at from the listing, changed in
 * one place for every listing that uses it.
 *
 * One base for every channel; each extension subclasses it with two lines so
 * the permission catalogue keys the page on that channel (shopee/description_
 * template, lazada/description_template, and so on) rather than on one
 * cross-channel key an operator with a single channel should not hold.
 */
abstract class DescriptionTemplateController extends Controller
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
        return auth()->user()?->hasPermission('manage_' . $this->integration() . '/description_template') ?? false;
    }

    public function index()
    {
        /* The page is handed finished URLs rather than building them from a
         * channel name, so nothing on it is a dynamic route() call. */
        $ch = $this->integration();
        $store = $this->storeId();

        return view('channels.description-templates.index', [
            // The browser tab reads "Shopee Description Templates", as its
            // Catalog siblings read "Shopee Product Groups".
            // Shopee descriptions are plain text, so its templates use a plain field.
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

        // The + on a listing page posts here and expects the new row back, so
        // the dropdown can take it without a page load. The body is the
        // stored, cleaned one, which the description preview shows.
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

        /* A listing still pointing at it simply carries nothing: bodyOf()
         * answers with an empty string for an id that no longer resolves,
         * so no push ever sends another template's words. */
        return $this->backToIndex('Template "' . e($name) . '" deleted.');
    }

    /** @return array{name: string, body: ?string} */
    private function validated(Request $request): array
    {
        abort_unless($this->canManage(), 403);

        return $request->validate([
            'name' => 'required|string|max:128',
            'body' => 'nullable|string|max:20000',
        ]);
    }

    /**
     * Normalises a template body: sanitised HTML, or plain text for Shopee.
     * An empty editor value is stored as an empty string.
     */
    private function body(?string $body): string
    {
        if ($this->integration() === 'shopee') {
            return \App\Support\Catalog\DescriptionText::of($body);
        }

        return \App\Support\Catalog\DescriptionText::isBlank($body) ? '' : trim(\App\Support\Catalog\DescriptionHtml::store($body));
    }

    /** A template of THIS store, or a 404. One store never edits another's. */
    private function ownRow(int $id): DescriptionTemplate
    {
        return DescriptionTemplate::query()
            ->where('id', $id)
            ->where('integration', $this->integration())
            ->where('store_id', $this->storeId())
            ->firstOrFail();
    }

    /** The one place a return trip to this page is built. */
    private function backToIndex(string $status)
    {
        return redirect()
            ->route('ext.' . $this->integration() . '.description-templates.index', ['store' => $this->storeId()])
            ->with('status', $status);
    }
}
