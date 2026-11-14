@extends('layouts.channel')
@section('breadcrumb', 'Categories')
@section('title', 'Categories, ' . $storeName)

@section('content')
<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Categories</h1>
        <p class="x-page-sub">
            {{ number_format($total) }} {{ $total === 1 ? 'category' : 'categories' }} cached from {{ $storeName }}@if($fetchedAt) · fetched {{ $fetchedAt->timezone(config('app.timezone'))->format('j M Y, g:i A') }}@endif
        </p>
    </div>
</div>

@if($canManage)
<div class="x-cmdbar">
    <form method="POST" action="{{ $fetchUrl }}" class="x-cmdbar__row">
        @csrf
        <div class="x-cmdbar__actions">
            <x-ui.button type="submit" variant="primary">Fetch categories</x-ui.button>
            <x-ui.hint>Pulls {{ $storeName }}'s whole category tree and replaces the cached copy. Fetch again when the store adds a category.</x-ui.hint>
        </div>
    </form>
</div>
@endif

<form method="GET" action="{{ $indexUrl }}" class="x-filters">
    <x-ui.input type="search" name="q" class="x-filters__search"
                value="{{ $q }}" placeholder="Category name or ID" aria-label="Search categories by name or ID" />
    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
    @if($q !== '')
        <a class="x-filters__reset" href="{{ $indexUrl }}">Reset</a>
    @endif
</form>

@if($categories->count() === 0)
    <x-ui.empty title="No categories here"
                :description="$q !== ''
                    ? 'Nothing matches that name or ID. Clear the search to see the whole tree.'
                    : ($canManage
                        ? 'Nothing has been cached yet. Click Fetch categories to pull the tree from ' . $storeName . '.'
                        : 'Nothing has been cached yet. Someone with the manage tier needs to fetch the tree from ' . $storeName . '.')" />
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th scope="col" class="cc-col-chanid">Category ID</th>
            <th scope="col">Name</th>
            @if($leafOnly)
                <th scope="col" class="cc-col-leaf">Can list here</th>
            @endif
            <th scope="col" class="cc-col-chanid">Parent ID</th>
            <th scope="col" class="cc-col-lvl x-td-num">Level</th>
        </tr>
    </x-slot:head>

    @foreach($categories as $c)
        <tr>
            <td class="cc-col-chanid" data-label="Category ID"><span class="x-num">{{ $c->id }}</span></td>
            <td data-label="Name">{{ $c->path }}</td>
            @if($leafOnly)
                <td class="cc-col-leaf" data-label="Can list here">
                    @if($c->leaf)
                        <x-ui.badge tone="success">Yes</x-ui.badge>
                    @else
                        <span class="x-cell-muted">No</span>
                    @endif
                </td>
            @endif
            <td class="cc-col-chanid" data-label="Parent ID">
                @if($c->parent_id)
                    <span class="x-num">{{ $c->parent_id }}</span>
                @else
                    <span class="x-cell-muted">Top level</span>
                @endif
            </td>
            <td class="cc-col-lvl x-td-num" data-label="Level"><span class="x-num">{{ $c->level }}</span></td>
        </tr>
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$categories" />
@endif

</div>
@endsection
