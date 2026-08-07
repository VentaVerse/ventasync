@extends('layouts.channel')
@section('breadcrumb', 'Categories')

@section('title', 'TikTok Shop Categories')

@section('content')
@php
    $canManageTiktok = true;
@endphp
    <div class="page-header">
        <div>
            <h2>TikTok Shop Category List</h2>
            <div class="text-muted text-sm">Fetch and cache TikTok's category tree locally for fast search and mapping.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('integrations.index') }}">&larr; Back to Integrations</a>

            @if($canManageTiktok)
            <form method="POST" action="{{ route('ext.tiktok.categories.sync') }}" style="display:inline;">
                @csrf
                <button class="btn" type="submit">Fetch Categories from TikTok</button>
            </form>
            @endif
        </div>
    </div>

    @if(session('status'))
        <div class="alert info mb-12">{{ session('status') }}</div>
    @endif

    <div class="card mb-12">
        <form method="GET" action="{{ route('ext.tiktok.categories.index') }}" class="d-flex gap-10 items-center">
            <div style="flex:1;">
                <label class="text-xs text-muted">Search</label>
                <input type="text" name="q" value="{{ $q }}" placeholder="Search category name or ID" class="input" />
            </div>
            <div class="d-flex gap-8 items-center" style="padding-top:18px;">
                <button class="btn" type="submit">Search</button>
                <a class="btn" href="{{ route('ext.tiktok.categories.index') }}">Reset</a>
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
                    <th>Permissions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($categories as $c)
                    <tr>
                        <td><code style="font-size:11.5px;">{{ $c->id }}</code></td>
                        <td>{{ $c->name }}</td>
                        <td>{{ $c->is_leaf ? 'Yes' : 'No' }}</td>
                        <td>{{ $c->parent_id ?? '-' }}</td>
                        <td>
                            @php
                                $perms = is_array($c->permission_statuses) ? $c->permission_statuses : (json_decode((string) $c->permission_statuses, true) ?: []);
                            @endphp
                            <span class="text-muted text-xs">{{ implode(', ', $perms) }}</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-muted">No categories cached yet. Click <strong>Fetch Categories from TikTok</strong>.</td>
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
