@extends('layouts.channel')
@section('breadcrumb', 'Categories')

@section('title', 'Lazada Categories')

@section('content')
@php
    $canManageLazada = auth()->user()?->hasPermission('manage_lazada/category') ?? false;

    $q = (string) ($q ?? '');
    $hasSearch = $q !== '';
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Categories</h1>
        <p class="x-page-sub">
            {{ number_format($categories->total()) }} {{ $categories->total() === 1 ? 'category' : 'categories' }} cached from Lazada
        </p>
    </div>
</div>

@if($canManageLazada)
<div class="x-cmdbar">
    <form method="POST" action="{{ route('ext.lazada.categories.fetch') }}" class="x-cmdbar__row">
        @csrf
        <div class="x-cmdbar__actions">
            <x-ui.button type="submit" variant="primary">Fetch categories</x-ui.button>
            <x-ui.hint>Pulls Lazada's whole category tree and replaces the cached copy. Presets and listings map to categories from this list, so refresh it when Lazada adds one.</x-ui.hint>
        </div>
    </form>
</div>
@endif

<form method="GET" action="{{ route('ext.lazada.categories.index') }}" class="x-filters">
    <x-ui.input type="search" name="q" class="x-filters__search"
                value="{{ $q }}" placeholder="Category name or ID" aria-label="Search categories by name or ID" />
    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
    @if($hasSearch)
        <a class="x-filters__reset" href="{{ route('ext.lazada.categories.index') }}">Reset</a>
    @endif
</form>

@if($categories->count() === 0)
    <x-ui.empty title="No categories here"
                :description="$hasSearch
                    ? 'Nothing matches that name or ID. Clear the search to see the whole tree.'
                    : ($canManageLazada
                        ? 'Nothing has been cached yet. Click Fetch categories to pull the tree from Lazada.'
                        : 'Nothing has been cached yet. Someone with the manage tier needs to fetch the tree from Lazada.')" />
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th scope="col" class="cc-col-chanid">Category ID</th>
            <th scope="col">Name</th>
            <th scope="col" class="cc-col-leaf">Can list here</th>
            <th scope="col" class="cc-col-chanid">Parent ID</th>
            <th scope="col" class="cc-col-lvl x-td-num">Level</th>
            <th scope="col" class="lz-col-var">Has variations</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($categories as $c)
        @php
            $level = (int) $c->level;
            $attrUrl = route('ext.lazada.categories.attributes.show', $c->category_id);
        @endphp
        <tr>
            <td class="cc-col-chanid" data-label="Category ID"><span class="x-num">{{ $c->category_id }}</span></td>
            <td data-label="Name">
                <span class="cc-tree" data-level="{{ min($level, 5) }}">
                    <a class="x-row-link" href="{{ $attrUrl }}">{{ $c->name }}</a>
                </span>
            </td>
            <td class="cc-col-leaf" data-label="Can list here">
                @if($c->leaf)
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
            <td class="cc-col-lvl x-td-num" data-label="Level"><span class="x-num">{{ $level }}</span></td>
            <td class="lz-col-var" data-label="Has variations">
                @if(is_null($c->var))
                    <span class="x-cell-muted">Not stated</span>
                @elseif($c->var)
                    <span class="x-cell-strong">Yes</span>
                @else
                    <span class="x-cell-muted">No</span>
                @endif
            </td>
            <td class="x-td-actions">
                <x-ui.menu label="Category {{ $c->category_id }} actions">
                    <a class="x-menu__item" href="{{ $attrUrl }}">View attributes</a>
                </x-ui.menu>
            </td>
        </tr>
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$categories" />
@endif

</div>
@endsection
