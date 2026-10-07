@extends('layouts.channel')
@section('breadcrumb', 'Brands')

@section('title', 'Lazada Brands')

@section('content')
@php
    $canManageLazada = auth()->user()?->hasPermission('manage_lazada/brand') ?? false;

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
<div class="x-cmdbar">
    <form method="POST" action="{{ route('ext.lazada.brands.fetch') }}" class="x-cmdbar__row">
        @csrf
        <div class="x-cmdbar__actions">
            <x-ui.button type="submit" variant="primary">Fetch brands</x-ui.button>
            <x-ui.hint>Reads every brand Lazada offers for the region set on the Settings page and caches it here. Presets and listings pick their brand from this list, so refresh it when a brand you need is missing.</x-ui.hint>
        </div>
    </form>
</div>
@endif

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
            <th scope="col" class="cc-col-chanid">Brand ID</th>
            <th scope="col">Name</th>
            <th scope="col" class="lz-col-region">Region</th>
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

<x-ui.pager :paginator="$brands" />
@endif

</div>
@endsection
