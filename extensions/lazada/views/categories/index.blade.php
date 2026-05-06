@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Lazada / Categories')

@section('title', 'Lazada Categories')

@section('content')
    <div class="page-header">
        <div>
            <h2>Lazada Category List</h2>
            <div class="text-muted text-sm">Fetch and cache Lazada's category tree locally for fast search and mapping.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('ext.lazada.index') }}">Back to Lazada</a>

            <form method="POST" action="{{ route('ext.lazada.categories.fetch') }}" style="display:inline;">
                @csrf
                <button class="btn" type="submit">Fetch Categories from Lazada</button>
            </form>
        </div>
    </div>

    <div class="card mb-12">
        <form method="GET" action="{{ route('ext.lazada.categories.index') }}" class="d-flex gap-10 items-center">
            <div style="flex:1;">
                <label class="text-xs text-muted">Search</label>
                <input type="text" name="q" value="{{ $q }}" placeholder="Search category name or ID" class="input" />
            </div>
            <div class="d-flex gap-8 items-center" style="padding-top:18px;">
                <button class="btn" type="submit">Search</button>
                <a class="btn" href="{{ route('ext.lazada.categories.index') }}">Reset</a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="table-wrap">
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
                            <div class="d-flex gap-8 items-center">
                                <span class="text-muted text-xs">{{ str_repeat('—', (int)$c->level) }}</span>
                                <span>{{ $c->name }}</span>
                            </div>
                        </td>
                        <td>{{ $c->leaf ? 'Yes' : 'No' }}</td>
                        <td>{{ $c->parent_id ?? '-' }}</td>
                        <td>{{ $c->level }}</td>
                        <td>{{ is_null($c->var) ? '-' : ($c->var ? 'Yes' : 'No') }}</td>
                        <td>
                            <a class="btn btn-sm" href="{{ route('ext.lazada.categories.attributes.show', $c->category_id) }}">View Attributes</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-muted">No categories cached yet. Click <strong>Fetch Categories from Lazada</strong>.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-between items-center mt-12">
            <div class="text-muted text-xs">
                Showing {{ $categories->firstItem() ?? 0 }} to {{ $categories->lastItem() ?? 0 }} of {{ $categories->total() }}
            </div>
            <div>
                {{ $categories->links('vendor.pagination.simple') }}
            </div>
        </div>
    </div>
@endsection
