@extends('layouts.app')

@section('title', 'Lazada Categories')

@section('content')
    <div class="page-head">
        <div>
            <h1>Lazada Category List</h1>
            <div class="muted">Fetch and cache Lazada's category tree locally for fast search and mapping.</div>
        </div>
        <div class="page-actions" style="display:flex; gap:8px; align-items:center;">
            <a class="btn" href="{{ route('lazada.index') }}">Back to Lazada</a>

            <form method="POST" action="{{ route('lazada.categories.fetch') }}" style="display:inline;">
                @csrf
                <button class="btn" type="submit">Fetch Categories from Lazada</button>
            </form>
        </div>
    </div>

    <div class="card" style="margin-bottom:12px;">
        <div class="card-body">
            <form method="GET" action="{{ route('lazada.categories.index') }}" style="display:flex; gap:10px; align-items:center;">
                <div style="flex:1;">
                    <label class="muted" style="display:block; font-size:12px; margin-bottom:6px;">Search</label>
                    <input type="text" name="q" value="{{ $q }}" placeholder="Search category name or ID" style="width:100%;" />
                </div>
                <div style="display:flex; gap:8px; align-items:end; padding-top:18px;">
                    <button class="btn" type="submit">Search</button>
                    <a class="btn" href="{{ route('lazada.categories.index') }}">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <table class="table">
                <thead>
                <tr>
                    <th>Category ID</th>
                    <th>Name</th>
                    <th>Leaf</th>
                    <th>Parent ID</th>
                    <th>Level</th>
                    <th>Var</th>
                    <th style="width:160px;">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($categories as $c)
                    <tr>
                        <td>{{ $c->category_id }}</td>
                        <td>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <span class="muted" style="font-size:12px;">{{ str_repeat('—', (int)$c->level) }}</span>
                                <span>{{ $c->name }}</span>
                            </div>
                        </td>
                        <td>{{ $c->leaf ? 'Yes' : 'No' }}</td>
                        <td>{{ $c->parent_id ?? '-' }}</td>
                        <td>{{ $c->level }}</td>
                        <td>{{ is_null($c->var) ? '-' : ($c->var ? 'Yes' : 'No') }}</td>
                        <td>
                            <a class="btn btn-sm" href="{{ route('lazada.categories.attributes.show', $c->category_id) }}">View Attributes</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="muted">No categories cached yet. Click <strong>Fetch Categories from Lazada</strong>.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px;">
                <div class="muted" style="font-size:12px;">
                    Showing {{ $categories->firstItem() ?? 0 }} to {{ $categories->lastItem() ?? 0 }} of {{ $categories->total() }}
                </div>
                <div>
                    {{ $categories->links('vendor.pagination.simple') }}
                </div>
            </div>
        </div>
    </div>
@endsection
