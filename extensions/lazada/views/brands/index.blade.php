@extends('layouts.channel')
@section('breadcrumb', 'Brands')

@section('title', 'Lazada Brands')

{{-- Leaves the .x-legacy content boundary, rebuilt on the token set and the
     x-ui components, the same exit Shopee's four catalogue interiors took in
     Round C Task 1. Brands is the surface Lazada carries where Shopee carries
     Logistics, so it lands on the same cc- grammar. --}}
@section('content-class', '')

@section('content')
@php
    // Read tier is `view_lazada|manage_lazada` (extensions/lazada/routes/web.php);
    // the fetch POST is `manage_lazada` only. This flag decides whether the
    // page OFFERS the fetch, nothing more - the route middleware is the real
    // gate. It replaces a hardcoded `true`, which showed every read-only
    // operator a button the route would refuse.
    $canManageLazada = auth()->user()?->hasPermission('manage_lazada') ?? false;

    $q = (string) ($q ?? '');
    $hasSearch = $q !== '';
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Brands</h1>
        <p class="x-page-sub">
            {{ number_format($brands->total()) }} {{ $brands->total() === 1 ? 'brand' : 'brands' }} cached from Lazada
        </p>
    </div>
</div>

@if($canManageLazada)
{{-- One command bar, the grammar the Orders and Returns pages already use for
     controls that talk to the marketplace. The bar states what the button does
     rather than leaving the operator to guess whether it merges or replaces:
     the controller pages through Lazada's whole brand list and upserts by
     brand id and region. --}}
<div class="x-cmdbar">
    <form method="POST" action="{{ route('ext.lazada.brands.fetch') }}" class="x-cmdbar__row">
        @csrf
        <p class="x-cmdbar__hint x-cmdbar__hint--inline">Reads every brand Lazada offers for the region set on the Settings page and caches it here. Product Groups and listings pick their brand from this list, so refresh it when a brand you need is missing.</p>
        <div class="x-cmdbar__actions">
            <x-ui.button type="submit" variant="primary">Fetch brands</x-ui.button>
        </div>
    </form>
</div>
@endif

{{-- One filter row. The old page put the search field, its Search button and a
     Fetch All Brands button of identical weight on one line inside a card, so
     a search and a call to Lazada read as the same kind of act. The machine
     action now sits in the bar above; this row is only the search. --}}
<form method="GET" action="{{ route('ext.lazada.brands.index') }}" class="x-filters">
    <x-ui.input type="search" name="q" class="x-filters__search"
                value="{{ $q }}" placeholder="Brand name or ID" aria-label="Search brands by name or ID" />
    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
    @if($hasSearch)
        <a class="x-filters__reset" href="{{ route('ext.lazada.brands.index') }}">Clear</a>
    @endif
</form>

@if($brands->count() === 0)
    <x-ui.empty title="No brands here"
                :description="$hasSearch
                    ? 'Nothing matches that name or ID. Clear the search to see the whole list.'
                    : ($canManageLazada
                        ? 'Nothing has been cached yet. Click Fetch brands to read the list from Lazada.'
                        : 'Nothing has been cached yet. Someone with the manage tier needs to read the list from Lazada.')" />
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th class="cc-col-chanid">Brand ID</th>
            <th>Name</th>
            <th class="lz-col-region">Region</th>
        </tr>
    </x-slot:head>

    @foreach($brands as $b)
        <tr>
            <td class="cc-col-chanid" data-label="Brand ID"><span class="x-num">{{ (int) $b->brand_id }}</span></td>
            <td data-label="Name"><span class="x-cell-strong">{{ $b->name }}</span></td>
            <td class="lz-col-region" data-label="Region">
                @if($b->region)
                    <span class="x-num">{{ strtoupper($b->region) }}</span>
                @else
                    <span class="x-cell-muted">Not set</span>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>

{{-- Previous and Next, not a numbered strip. Lazada's brand list runs to tens
     of thousands of rows for some regions and page 884 is not a destination
     anyone navigates to; the search box above is how you find a brand. --}}
<div class="x-pager">
    <span class="x-pager__count x-num">{{ number_format($brands->firstItem() ?? 0) }} to {{ number_format($brands->lastItem() ?? 0) }} of {{ number_format($brands->total()) }}</span>
    <div class="x-pager__nav">
        <x-ui.button size="sm" :href="$brands->previousPageUrl()" :disabled="!$brands->previousPageUrl()"><x-ui.icon name="chevron-left" size="14" /> Previous</x-ui.button>
        <x-ui.button size="sm" :href="$brands->nextPageUrl()" :disabled="!$brands->nextPageUrl()">Next <x-ui.icon name="chevron-right" size="14" /></x-ui.button>
    </div>
</div>
@endif

</div>{{-- /.cc-page --}}
@endsection
