@extends('layouts.channel')
@section('breadcrumb', 'Categories')

@section('title', 'Shopee Categories')

{{-- Leaves the .x-legacy content boundary, the same exit Orders, Returns and
     the order detail page already took: the page is rebuilt directly on the
     token set and the x-ui components, so it no longer needs the wrapper
     that re-pins twelve custom properties to their legacy values. --}}
@section('content')
@php
    // Read tier is `view_shopee|manage_shopee` (see the extension's
    // routes/web.php); the fetch POST is `manage_shopee` only. This flag
    // decides whether the page OFFERS the fetch, nothing more - the route
    // middleware is the real gate. It replaces a hardcoded `true`, which
    // showed every read-only operator a button that 403s.
    $canManageShopee = auth()->user()?->hasPermission('manage_shopee') ?? false;

    $q = (string) ($q ?? '');
    $hasSearch = $q !== '';
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Categories</h1>
        <p class="x-page-sub">
            {{ number_format($categories->total()) }} {{ $categories->total() === 1 ? 'category' : 'categories' }} cached from Shopee
        </p>
    </div>
</div>

@if($canManageShopee)
{{-- One command bar, the same grammar the Orders and Returns pages use for
     the controls that talk to the marketplace: every control is
     --control-h tall so they share one baseline, and the bar states plainly
     what the button does rather than leaving the operator to guess whether
     it replaces or merges. It replaces: the controller truncates
     shopee_categories inside a transaction and re-inserts the tree. --}}
<div class="x-cmdbar">
    <form method="POST" action="{{ route('ext.shopee.categories.fetch') }}" class="x-cmdbar__row">
        @csrf
        <p class="x-cmdbar__hint x-cmdbar__hint--inline">Pulls Shopee's whole category tree and replaces the cached copy. Product Groups map to categories from this list, so refresh it when Shopee adds one.</p>
        <div class="x-cmdbar__actions">
            <x-ui.button type="submit" variant="primary">Fetch categories</x-ui.button>
        </div>
    </form>
</div>
@endif

{{-- One filter row. The old page had a card holding a single search box, a
     Search button and a Reset button of identical weight side by side; the
     row here keeps the button (a form with a text field and no submit does
     not submit on Enter per the HTML spec) and demotes Reset to the link it
     always was, since it is navigation and not an action. --}}
<form method="GET" action="{{ route('ext.shopee.categories.index') }}" class="x-filters">
    <x-ui.input type="search" name="q" class="x-filters__search"
                value="{{ $q }}" placeholder="Category name or ID" aria-label="Search categories by name or ID" />
    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
    @if($hasSearch)
        <a class="x-filters__reset" href="{{ route('ext.shopee.categories.index') }}">Reset</a>
    @endif
</form>

@if($categories->count() === 0)
    <x-ui.empty title="No categories here"
                :description="$hasSearch
                    ? 'Nothing matches that name or ID. Clear the search to see the whole tree.'
                    : ($canManageShopee
                        ? 'Nothing has been cached yet. Click Fetch categories to pull the tree from Shopee.'
                        : 'Nothing has been cached yet. Someone with the manage tier needs to fetch the tree from Shopee.')" />
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th class="cc-col-chanid">Category ID</th>
            <th>Name</th>
            <th class="cc-col-leaf">Can list here</th>
            <th class="cc-col-chanid">Parent ID</th>
            <th class="cc-col-lvl x-td-num">Level</th>
            <th class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($categories as $c)
        @php
            $level = (int) $c->level;
            $attrUrl = route('ext.shopee.categories.attributes.show', $c->category_id);
        @endphp
        <tr>
            <td class="cc-col-chanid" data-label="Category ID"><span class="x-num">{{ $c->category_id }}</span></td>
            <td data-label="Name">
                {{-- Depth is drawn by indentation, not by repeating a dash
                     per level the way the old view did. The figure is still
                     printed in its own column for anyone who needs to count. --}}
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
            <td class="x-td-actions">
                <x-ui.menu label="Category {{ $c->category_id }} actions">
                    <a class="x-menu__item" href="{{ $attrUrl }}">View attributes</a>
                </x-ui.menu>
            </td>
        </tr>
    @endforeach
</x-ui.table>

<div class="x-pager">
    <span class="x-pager__count x-num">{{ number_format($categories->firstItem() ?? 0) }} to {{ number_format($categories->lastItem() ?? 0) }} of {{ number_format($categories->total()) }}</span>
    <div class="x-pager__nav">
        <x-ui.button size="sm" :href="$categories->previousPageUrl()" :disabled="!$categories->previousPageUrl()"><x-ui.icon name="chevron-left" size="14" /> Previous</x-ui.button>
        <x-ui.button size="sm" :href="$categories->nextPageUrl()" :disabled="!$categories->nextPageUrl()">Next <x-ui.icon name="chevron-right" size="14" /></x-ui.button>
    </div>
</div>
@endif

</div>{{-- /.cc-page --}}
@endsection
