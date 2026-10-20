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
        $groups = $this->groups()->orderBy('name')->get(['id', 'name']);
        $counts = DB::table($table)->whereIn($fk, $groups->pluck('id')->all() ?: [0])
            ->selectRaw($fk . ' as g, COUNT(*) as n')->groupBy($fk)->pluck('n', 'g');

        return view('channels.product-groups.index', [
            'channelName' => $this->channelName(),
            'groups' => $groups->map(fn ($g) => (object) [
                'id' => (int) $g->id,
                'name' => (string) $g->name,
                'count' => (int) ($counts[$g->id] ?? 0),
                'productsUrl' => $this->productsUrl((int) $g->id),
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

        return redirect($this->productsUrl((int) $group->id))->with('status', 'Product group created.');
    }

    public function update(Request $request)
    {
        abort_unless($this->canManageGroups(), 403);
        $group = $this->ownGroup();
        $data = $request->validate(['name' => 'required|string|max:128']);
        $group->update(['name' => trim($data['name'])]);
        ActivityLogger::log('updated', 'Product group', (int) $group->id, $group->name, ['channel' => $this->channel()]);

        return redirect($this->groupRoute('index'))->with('status', 'Product group saved.');
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
        return redirect($this->productsUrl((int) $this->ownGroup()->id));
    }

    private function groupForm(Model $group)
    {
        return view('channels.product-groups.form', [
            'channelName' => $this->channelName(),
            'group' => $group,
            'saveUrl' => $group->exists ? $this->groupRoute('update', (int) $group->id) : $this->groupRoute('store'),
            'backUrl' => $this->groupRoute('index'),
            'canManage' => $this->canManageGroups(),
            'storeName' => $this->storeName(),
        ]);
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

    private function productsUrl(int $groupId): string
    {
        $ch = $this->channel();

        return route('ext.' . $this->channel() . '.' . (in_array($ch, ['woocommerce', 'ventacart'], true) ? 'listings' : 'products') . '.index', ['store' => $this->groupStoreId(), 'group' => $groupId]);
    }

    private function groupRoute(string $action, ?int $groupId = null): string
    {
        $ch = $this->channel();
        $key = in_array($ch, ['woocommerce', 'ventacart'], true) ? 'group' : 'id';
        $params = ['store' => $this->groupStoreId()] + ($groupId !== null ? [$key => $groupId] : []);

        return route('ext.' . $this->channel() . '.product-groups.' . $action, $params);
    }
}
