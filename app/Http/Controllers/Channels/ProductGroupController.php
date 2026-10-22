<?php

namespace App\Http\Controllers\Channels;

use App\Channels\Listings\ListingStore;
use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

abstract class ProductGroupController extends Controller
{
    abstract protected function channel(): string;

    abstract protected function groupModel(): string;

    abstract protected function membership(): array;

    protected function groupStoreId(): int
    {
        return ListingStore::id($this->channel());
    }

    protected function canManageGroups(): bool
    {
        return auth()->user()?->hasPermission('manage_' . $this->channel() . '/product_group') ?? false;
    }

    public function index()
    {
        [$table, $fk] = $this->membership();
        $groups = $this->groups()->orderBy('name')->get(['id', 'name', 'listing_template_id']);
        $counts = DB::table($table)->whereIn($fk, $groups->pluck('id')->all() ?: [0])
            ->selectRaw($fk . ' as g, COUNT(*) as n')->groupBy($fk)->pluck('n', 'g');
        $templateNames = $this->templates()->pluck('name', 'id');

        return view('channels.product-groups.index', [
            'channelName' => $this->channelName(),
            'groups' => $groups->map(fn ($g) => (object) [
                'id' => (int) $g->id,
                'name' => (string) $g->name,
                'count' => (int) ($counts[$g->id] ?? 0),
                'template' => $g->listing_template_id ? (string) ($templateNames[$g->listing_template_id] ?? '') : '',
                'editUrl' => $this->groupRoute('edit', (int) $g->id),
                'destroyUrl' => $this->groupRoute('destroy', (int) $g->id),
            ]),
            'createUrl' => $this->groupRoute('create'),
            'canManage' => $this->canManageGroups(),
            'storeName' => $this->storeName(),
        ]);
    }

    public function create()
    {
        $class = $this->groupModel();

        return $this->groupForm(new $class());
    }

    public function edit()
    {
        return $this->groupForm($this->ownGroup());
    }

    public function store(Request $request)
    {
        abort_unless($this->canManageGroups(), 403);
        $data = $request->validate(['name' => 'required|string|max:128']);
        $class = $this->groupModel();
        $group = $class::create(['store_id' => $this->groupStoreId(), 'name' => trim($data['name'])]);
        ActivityLogger::log('created', 'Product group', (int) $group->id, $group->name, ['channel' => $this->channel()]);

        return redirect($this->groupRoute('edit', (int) $group->id))->with('status', 'Product group created.');
    }

    public function update(Request $request)
    {
        abort_unless($this->canManageGroups(), 403);
        $group = $this->ownGroup();
        $data = $request->validate([
            'name' => 'required|string|max:128',
            'listing_template_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::in($this->templates()->pluck('id')->all())],
        ]);
        $group->forceFill(['name' => trim($data['name']), 'listing_template_id' => $data['listing_template_id'] ?? null])->save();
        ActivityLogger::log('updated', 'Product group', (int) $group->id, $group->name, ['channel' => $this->channel()]);

        return redirect($this->groupRoute('edit', (int) $group->id))->with('status', 'Product group saved.');
    }

    public function destroy()
    {
        abort_unless($this->canManageGroups(), 403);
        $group = $this->ownGroup();
        [$table, $fk] = $this->membership();
        DB::transaction(function () use ($group, $table, $fk) {
            DB::table($table)->where($fk, $group->id)->delete();
            $group->delete();
        });
        ActivityLogger::log('deleted', 'Product group', (int) $group->id, $group->name, ['channel' => $this->channel()]);

        return redirect($this->groupRoute('index'))->with('status', 'Product group deleted.');
    }

    public function products()
    {
        return redirect($this->groupRoute('edit', (int) $this->ownGroup()->id));
    }

    public function productSearch(Request $request)
    {
        $group = $this->ownGroup();
        [$table, $fk] = $this->membership();
        $langId = (int) config('catalog.default_language_id');
        $q = trim((string) $request->query('q', ''));
        $limit = 25;
        $storeGroups = $this->groups()->pluck('name', 'id');

        $query = DB::table('products as p')
            ->leftJoin('product_descriptions as pd', fn ($j) => $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId))
            ->where('p.status', 1)
            ->select('p.product_id', 'pd.name', 'p.image', 'p.sku', 'p.quantity', 'p.price');
        if ($q !== '') {
            $query->where(fn ($w) => $w->where('pd.name', 'like', '%' . $q . '%')->orWhere('p.sku', 'like', '%' . $q . '%'));
        }
        if (! $request->boolean('all')) {
            $query->whereNotIn('p.product_id', DB::table($table)->where($fk, $group->id)->select('product_id'));
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('pd.name')->orderBy('p.product_id')->limit($limit)->get();
        $ids = $rows->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $membership = DB::table($table)->whereIn('product_id', $ids ?: [0])->whereIn($fk, $storeGroups->keys()->all() ?: [0])->pluck($fk, 'product_id');
        $listed = DB::table('ch_' . $this->channel() . '_listings')->where('store_id', $this->groupStoreId())->whereIn('product_id', $ids ?: [0])->pluck('product_id')->flip();

        $items = $rows->map(function ($r) use ($group, $membership, $storeGroups, $listed) {
            $in = $membership[$r->product_id] ?? null;

            return [
                'id' => (int) $r->product_id,
                'name' => html_entity_decode((string) ($r->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Unnamed product',
                'sku' => (string) ($r->sku ?? ''),
                'quantity' => (int) ($r->quantity ?? 0),
                'price' => \App\Support\Money::base((float) ($r->price ?? 0)),
                'thumb' => trim((string) $r->image) !== '' ? \App\Services\Media\ImageCache::url((string) $r->image) : null,
                'on_store' => $in !== null && (int) $in === (int) $group->id,
                'elsewhere' => $in !== null && (int) $in !== (int) $group->id ? (string) ($storeGroups[$in] ?? '') : null,
                'listed' => isset($listed[$r->product_id]),
            ];
        })->values();

        return response()->json(['total' => $total, 'shown' => $items->count(), 'limit' => $limit, 'items' => $items]);
    }

    public function removeProducts(Request $request)
    {
        abort_unless($this->canManageGroups(), 403);
        $group = $this->ownGroup();
        [$table, $fk] = $this->membership();
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('ids', [])), fn ($v) => $v > 0));
        $removed = $ids === [] ? 0 : DB::table($table)->where($fk, $group->id)->whereIn('product_id', $ids)->delete();

        return redirect($this->groupRoute('edit', (int) $group->id))->with($removed > 0 ? 'status' : 'warning', $removed > 0
            ? $removed . ' ' . \Illuminate\Support\Str::plural('product', $removed) . ' removed from ' . $group->name . '.'
            : 'No products removed.');
    }

    private function groupForm(Model $group)
    {
        $ch = $this->channel();
        $members = collect();
        if ($group->exists) {
            [$table, $fk] = $this->membership();
            $langId = (int) config('catalog.default_language_id');
            $members = DB::table($table . ' as gp')
                ->join('products as p', 'p.product_id', '=', 'gp.product_id')
                ->leftJoin('product_descriptions as pd', fn ($j) => $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId))
                ->where('gp.' . $fk, $group->id)
                ->orderBy('pd.name')->orderBy('p.product_id')
                ->get(['p.product_id', 'pd.name', 'p.sku', 'p.image', 'p.quantity'])
                ->map(fn ($r) => (object) [
                    'id' => (int) $r->product_id,
                    'name' => html_entity_decode((string) ($r->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Unnamed product',
                    'sku' => (string) ($r->sku ?? ''),
                    'quantity' => (int) $r->quantity,
                    'thumb' => trim((string) $r->image) !== '' ? \App\Services\Media\ImageCache::url((string) $r->image) : null,
                    'url' => $this->listingUrl((int) $r->product_id),
                ]);
        }

        return view('channels.product-groups.form', [
            'channelName' => $this->channelName(),
            'group' => $group,
            'saveUrl' => $group->exists ? $this->groupRoute('update', (int) $group->id) : $this->groupRoute('store'),
            'backUrl' => $this->groupRoute('index'),
            'canManage' => $this->canManageGroups(),
            'canApply' => auth()->user()?->hasPermission('manage_' . $ch . '/listing_template') ?? false,
            'storeName' => $this->storeName(),
            'templates' => $this->templates()->orderBy('name')->pluck('name', 'id'),
            'templateUrls' => (auth()->user()?->hasPermission('view_' . $ch . '/listing_template') ?? false)
                ? $this->templates()->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => route('ext.' . $ch . '.listing-templates.edit', ['store' => $this->groupStoreId(), 'template' => $id])])->all()
                : [],
            'templatesUrl' => route('ext.' . $ch . '.listing-templates.index', ['store' => $this->groupStoreId()]),
            'previewUrl' => route('ext.' . $ch . '.listing-templates.preview', ['store' => $this->groupStoreId()]),
            'members' => $members,
            'removeUrl' => $group->exists ? $this->groupRoute('remove', (int) $group->id) : null,
            'searchUrl' => $group->exists ? $this->groupRoute('search', (int) $group->id) : null,
            'addUrl' => route('ext.' . $ch . '.products.add_to_store', ['store' => $this->groupStoreId(), 'productId' => '__PID__']),
        ]);
    }

    private function templates()
    {
        return DB::table('ch_' . $this->channel() . '_listing_templates')->where('store_id', $this->groupStoreId());
    }

    private function listingUrl(int $productId): ?string
    {
        foreach (['listings.edit', 'products.edit'] as $name) {
            if (\Illuminate\Support\Facades\Route::has('ext.' . $this->channel() . '.' . $name)) {
                return route('ext.' . $this->channel() . '.' . $name, ['store' => $this->groupStoreId(), 'productId' => $productId]);
            }
        }

        return null;
    }

    private function groups()
    {
        $class = $this->groupModel();

        return $class::query()->withoutGlobalScopes()->where('store_id', $this->groupStoreId());
    }

    private function ownGroup(): Model
    {
        $id = (int) (request()->route('id') ?? request()->route('group'));

        return $this->groups()->whereKey($id)->firstOrFail();
    }

    private function storeName(): string
    {
        return (string) DB::table('ch_' . $this->channel() . '_stores')->where('id', $this->groupStoreId())->value('store_name');
    }

    private function channelName(): string
    {
        return app(\App\Channels\ChannelRegistry::class)->resolveChannelLabel($this->channel()) ?? \Illuminate\Support\Str::headline($this->channel());
    }

    private function groupRoute(string $action, ?int $groupId = null): string
    {
        $ch = $this->channel();
        $key = in_array($ch, ['woocommerce', 'ventacart'], true) ? 'group' : 'id';
        $params = ['store' => $this->groupStoreId()] + ($groupId !== null ? [$key => $groupId] : []);

        return route('ext.' . $this->channel() . '.product-groups.' . $action, $params);
    }
}
