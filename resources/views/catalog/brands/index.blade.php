@extends('layouts.blotter')
@section('title', 'Brands')

@section('content')
@php
    $canManageBrands = auth()->user()?->hasPermission('manage_catalog/brand') ?? false;

    $hasFilters = $q !== '';
@endphp

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Brands</h1>
        <p class="x-page-sub">{{ number_format($brands->total()) }} {{ Str::plural('brand', $brands->total()) }}</p>
    </div>
    @if($canManageBrands)
    <x-ui.button variant="primary" :href="route('brands.create')">New brand</x-ui.button>
    @endif
</div>

<form method="GET" action="{{ route('brands.index') }}" class="x-filters" id="brands-filter">
    <x-ui.input type="search" name="q" value="{{ $q }}" placeholder="Search brands" class="x-filters__search" />
</form>

<div class="cat-page" x-data="{
        selected: [],
        ids: {{ \Illuminate\Support\Js::from($brands->pluck('brand_id')->map(fn ($v) => (string) $v)->values()) }}
    }">

@if($canManageBrands)
    <form id="brand-bulk-form" method="POST" action="{{ route('brands.bulk_delete') }}" x-ref="bulkForm">
        @csrf
    </form>

    <div class="cat-bulkbar" x-show="selected.length > 0" x-cloak>
        <span class="cat-bulkbar__count"><span class="x-num" x-text="selected.length"></span> selected</span>
        <div class="cat-bulkbar__actions">
            <x-ui.button type="button" variant="danger" size="sm"
                    data-confirm="Delete the selected brands? This cannot be undone."
                    data-confirm-submit="brand-bulk-form">Delete</x-ui.button>
        </div>
    </div>
@endif

@if($brands->isEmpty())
    <x-ui.empty title="{{ $hasFilters ? 'No brands match that search' : 'No brands yet' }}"
                description="{{ $hasFilters ? 'Try a different search term.' : 'Add your first brand to start organizing the catalog.' }}">
        @if($canManageBrands && !$hasFilters)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('brands.create')">New brand</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            @if($canManageBrands)
            <th scope="col" class="cat-col-check">
                <input type="checkbox" class="cat-check"
                       :checked="ids.length > 0 && selected.length === ids.length"
                       x-effect="$el.indeterminate = selected.length > 0 && selected.length < ids.length"
                       @change="selected = $event.target.checked ? ids.slice() : []"
                       aria-label="Select all brands on this page">
            </th>
            @endif
            <th scope="col" class="cat-col-id">ID</th>
            <th scope="col">Name</th>
            <th scope="col" class="cat-col-sort x-td-num">Sort</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($brands as $m)
        <tr>
            @if($canManageBrands)
            <td class="cat-col-check">
                <input type="checkbox" class="cat-check" name="ids[]" value="{{ (string) $m->brand_id }}" form="brand-bulk-form" x-model="selected">
            </td>
            @endif
            <td class="cat-col-id" data-label="ID"><span class="x-num">{{ $m->brand_id }}</span></td>
            <td data-label="Name">
                @if($canManageBrands)
                    <a class="x-row-link" href="{{ route('brands.edit', $m->brand_id) }}">{{ $m->name ?? '-' }}</a>
                @else
                    {{ $m->name ?? '-' }}
                @endif
            </td>
            <td class="cat-col-sort x-td-num" data-label="Sort"><span class="x-num">{{ (int) $m->sort_order }}</span></td>
            <td class="x-td-actions">
                @if($canManageBrands)
                <x-ui.menu label="Brand {{ $m->brand_id }} actions">
                    <a class="x-menu__item" href="{{ route('brands.edit', $m->brand_id) }}">Edit</a>
                    <div class="x-menu__sep"></div>
                    <button type="button" class="x-menu__item x-menu__item--danger"
                            data-confirm="Delete {{ $m->name }}? This cannot be undone."
                            data-confirm-submit="mfg-del-{{ $m->brand_id }}">Delete</button>
                    <form id="mfg-del-{{ $m->brand_id }}" method="POST" action="{{ route('brands.destroy', $m->brand_id) }}" class="x-sr">
                        @csrf
                        @method('DELETE')
                    </form>
                </x-ui.menu>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$brands" />
@endif
</div>
@endsection
