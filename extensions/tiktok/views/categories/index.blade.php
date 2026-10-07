@extends('layouts.channel')
@section('breadcrumb', 'Categories')

@section('title', 'TikTok Shop Categories')

@section('content')
@php
    $canManageTiktok = auth()->user()?->hasPermission('manage_tiktok/product_group') ?? false;

    $q = (string) ($q ?? '');
    $hasSearch = $q !== '';
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Categories</h1>
        <p class="x-page-sub">
            {{ number_format($categories->total()) }} {{ $categories->total() === 1 ? 'category' : 'categories' }} cached from TikTok Shop
        </p>
    </div>
</div>

@if($canManageTiktok)
<div class="x-cmdbar">
    <form method="POST" action="{{ route('ext.tiktok.categories.sync') }}" class="x-cmdbar__row">
        @csrf
        <div class="x-cmdbar__actions">
            <x-ui.button type="submit" variant="primary">Fetch categories</x-ui.button>
            <x-ui.hint>Pulls TikTok Shop's category tree into the local cache. Presets pick their TikTok category from this list, so refresh it when TikTok adds one.</x-ui.hint>
        </div>
    </form>
</div>
@endif

<form method="GET" action="{{ route('ext.tiktok.categories.index') }}" class="x-filters">
    <x-ui.input type="search" name="q" class="x-filters__search"
                value="{{ $q }}" placeholder="Category name or ID" aria-label="Search categories by name or ID" />
    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
    @if($hasSearch)
        <a class="x-filters__reset" href="{{ route('ext.tiktok.categories.index') }}">Reset</a>
    @endif
</form>

@if($categories->count() === 0)
    <x-ui.empty title="No categories here"
                :description="$hasSearch
                    ? 'Nothing matches that name or ID. Clear the search to see the whole tree.'
                    : ($canManageTiktok
                        ? 'Nothing has been cached yet. Click Fetch categories to pull the tree from TikTok Shop.'
                        : 'Nothing has been cached yet. Someone with the manage tier needs to fetch the tree from TikTok Shop.')" />
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th scope="col" class="cc-col-chanid">Category ID</th>
            <th scope="col">Name</th>
            <th scope="col" class="cc-col-leaf">Can list here</th>
            <th scope="col" class="cc-col-chanid">Parent ID</th>
            <th scope="col" class="tt-col-perms">Selling permissions</th>
        </tr>
    </x-slot:head>

    @foreach($categories as $c)
        @php
            $perms = is_array($c->permission_statuses)
                ? $c->permission_statuses
                : (json_decode((string) $c->permission_statuses, true) ?: []);
            $perms = array_values(array_filter(array_map(
                fn ($p) => is_scalar($p) ? trim((string) $p) : '',
                $perms
            ), fn ($p) => $p !== ''));
        @endphp
        <tr>
            <td class="cc-col-chanid" data-label="Category ID"><span class="x-num">{{ $c->id }}</span></td>
            <td data-label="Name">{{ $c->name }}</td>
            <td class="cc-col-leaf" data-label="Can list here">
                @if($c->is_leaf)
                    <x-ui.badge tone="success">Yes</x-ui.badge>
                @else
                    <span class="x-cell-muted">No</span>
                @endif
            </td>
            <td class="cc-col-chanid" data-label="Parent ID">
                @if($c->parent_id)
                    <span class="x-num">{{ $c->parent_id }}</span>
                @else
                    <span class="x-cell-muted">Top level</span>
                @endif
            </td>
            <td class="tt-col-perms" data-label="Selling permissions">
                @if(count($perms) === 0)
                    <span class="x-cell-muted">None stated</span>
                @else
                    <span class="cc-sub cc-sub--mono">{{ implode(', ', $perms) }}</span>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$categories" />
@endif

</div>
@endsection
