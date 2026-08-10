@extends('layouts.channel')
@section('breadcrumb', 'Categories')

@section('title', 'TikTok Shop Categories')

{{-- Leaves the .x-legacy content boundary, rebuilt on the token set and the
     x-ui components, the same exit Shopee's and Lazada's Categories pages
     took in Round C Tasks 1 and 2. --}}
@section('content')
@php
    // Read tier on this route is `view_tiktok|manage_tiktok`; the sync POST is
    // `manage_tiktok` only. Replaces a hardcoded `true`, which showed every
    // read-only operator a button the route would refuse.
    $canManageTiktok = auth()->user()?->hasPermission('manage_tiktok') ?? false;

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
{{-- One command bar, the same grammar the other channels' Categories pages
     use. It says what the button does rather than leaving the operator to
     guess: syncCategories() upserts the tree it just fetched into the cache. --}}
<div class="x-cmdbar">
    <form method="POST" action="{{ route('ext.tiktok.categories.sync') }}" class="x-cmdbar__row">
        @csrf
        <div class="x-cmdbar__actions">
            <x-ui.button type="submit" variant="primary">Fetch categories</x-ui.button>
            <x-ui.hint>Pulls TikTok Shop's category tree into the local cache. Product Groups pick their TikTok category from this list, so refresh it when TikTok adds one.</x-ui.hint>
        </div>
    </form>
</div>
@endif

{{-- One filter row. The old page had a card holding a labelled search box, a
     Search button and a Reset button of identical weight side by side; the row
     here keeps the button (a form with a text field and no submit does not
     submit on Enter per the HTML spec) and demotes Reset to the link it always
     was, since it is navigation and not an action. --}}
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
            <th class="cc-col-chanid">Category ID</th>
            <th>Name</th>
            <th class="cc-col-leaf">Can list here</th>
            <th class="cc-col-chanid">Parent ID</th>
            <th class="tt-col-perms">Selling permissions</th>
        </tr>
    </x-slot:head>

    @foreach($categories as $c)
        @php
            // Same normalisation the old view did inline: the column is a JSON
            // array on some rows and an already-cast array on others.
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
                {{-- TikTok calls this `is_leaf` and the old view headed it
                     "Leaf". What it means to the operator is whether a listing
                     can sit in this category at all, so that is what the
                     column says. --}}
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

</div>{{-- /.cc-page --}}
@endsection
