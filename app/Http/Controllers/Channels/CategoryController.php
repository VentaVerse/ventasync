<?php

namespace App\Http\Controllers\Channels;

use App\Channels\Contracts\ListingLists;
use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class CategoryController extends Controller
{
    abstract protected function channel(): string;

    abstract protected function categoryModel(): string;

    abstract protected function idColumn(): string;

    abstract protected function listingLists(): ListingLists;

    protected function leafOnly(): bool
    {
        return false;
    }

    protected function storeId(): int
    {
        return \App\Channels\Listings\ListingStore::id($this->channel());
    }

    protected function canManage(): bool
    {
        return auth()->user()?->hasPermission('manage_' . $this->channel() . '/category') ?? false;
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $model = $this->categoryModel();
        $id = $this->idColumn();
        $all = $model::query()->withoutGlobalScopes()->where('store_id', $this->storeId())
            ->get(array_values(array_filter([$id, 'parent_id', 'name', $this->leafOnly() ? 'leaf' : null])));

        $byId = $all->keyBy($id);
        $parents = $all->pluck('parent_id')->filter()->map(fn ($v) => (int) $v)->flip();
        $rows = $all->map(function ($c) use ($byId, $id, $parents) {
            $path = [(string) $c->name];
            $seen = [(int) $c->{$id} => true];
            for ($p = (int) $c->parent_id; $p > 0 && isset($byId[$p]) && ! isset($seen[$p]); $p = (int) $byId[$p]->parent_id) {
                $seen[$p] = true;
                array_unshift($path, (string) $byId[$p]->name);
            }

            return (object) [
                'id' => (int) $c->{$id},
                'name' => (string) $c->name,
                'path' => implode(' › ', $path),
                'parent_id' => (int) $c->parent_id ?: null,
                'level' => count($path),
                'leaf' => $this->leafOnly() ? (bool) $c->leaf : ! isset($parents[(int) $c->{$id}]),
            ];
        });
        if ($q !== '') {
            $rows = $rows->filter(fn ($r) => mb_stripos($r->path, $q) !== false || (ctype_digit($q) && $r->id === (int) $q));
        }
        $rows = $rows->sortBy(fn ($r) => mb_strtolower($r->path))->values();
        $page = max(1, (int) $request->query('page', 1));
        $categories = new LengthAwarePaginator($rows->forPage($page, 50)->values(), $rows->count(), 50, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('channels.categories.index', [
            'categories' => $categories,
            'total' => $all->count(),
            'fetchedAt' => ($at = $this->store()?->last_category_sync_at) ? \Illuminate\Support\Carbon::parse($at) : null,
            'q' => $q,
            'storeName' => $this->storeName(),
            'leafOnly' => $this->leafOnly(),
            'canManage' => $this->canManage(),
            'indexUrl' => route('ext.' . $this->channel() . '.categories.index', ['store' => $this->storeId()]),
            'fetchUrl' => route('ext.' . $this->channel() . '.categories.fetch', ['store' => $this->storeId()]),
        ]);
    }

    public function fetch()
    {
        abort_unless($this->canManage(), 403);
        $out = $this->listingLists()->refresh('categories');
        if (! empty($out['ok'])) {
            ActivityLogger::log('updated', 'Categories fetched', $this->storeId(), $this->storeName(), ['channel' => $this->channel(), 'count' => (int) ($out['count'] ?? 0)]);
        }

        return redirect()->route('ext.' . $this->channel() . '.categories.index', ['store' => $this->storeId()])
            ->with(! empty($out['ok']) ? 'status' : 'error', (string) ($out['message'] ?? 'The categories could not be fetched.'));
    }

    private function store(): ?object
    {
        return \Illuminate\Support\Facades\DB::table('ch_' . $this->channel() . '_stores')->where('id', $this->storeId())->first(['store_name', 'last_category_sync_at']);
    }

    private function storeName(): string
    {
        return (string) ($this->store()?->store_name ?? '');
    }
}
