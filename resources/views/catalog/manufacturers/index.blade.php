@extends('layouts.blotter')
@section('title', 'Manufacturers')

@section('content')
@php
    $canManageManufacturers = auth()->user()?->hasPermission('manage_catalog/manufacturer') ?? false;

    $hasFilters = $q !== '';
@endphp

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Manufacturers</h1>
        <p class="x-page-sub">{{ number_format($manufacturers->total()) }} {{ Str::plural('manufacturer', $manufacturers->total()) }}</p>
    </div>
    @if($canManageManufacturers)
    <x-ui.button variant="primary" :href="route('manufacturers.create')">New manufacturer</x-ui.button>
    @endif
</div>

<form method="GET" action="{{ route('manufacturers.index') }}" class="x-filters" id="manufacturers-filter">
    <x-ui.input type="search" name="q" value="{{ $q }}" placeholder="Search manufacturers" class="x-filters__search" />
</form>

<div class="cat-page" x-data="{
        selected: [],
        ids: {{ \Illuminate\Support\Js::from($manufacturers->pluck('manufacturer_id')->map(fn ($v) => (string) $v)->values()) }}
    }">

@if($canManageManufacturers)
    <form id="manufacturer-bulk-form" method="POST" action="{{ route('manufacturers.bulk_delete') }}" x-ref="bulkForm">
        @csrf
    </form>

    <div class="cat-bulkbar" x-show="selected.length > 0" x-cloak>
        <span class="cat-bulkbar__count"><span class="x-num" x-text="selected.length"></span> selected</span>
        <div class="cat-bulkbar__actions">
            <x-ui.button type="button" variant="danger" size="sm"
                    data-confirm="Delete the selected manufacturers? This cannot be undone."
                    data-confirm-submit="manufacturer-bulk-form">Delete</x-ui.button>
        </div>
    </div>
@endif

@if($manufacturers->isEmpty())
    <x-ui.empty title="{{ $hasFilters ? 'No manufacturers match that search' : 'No manufacturers yet' }}"
                description="{{ $hasFilters ? 'Try a different search term.' : 'Add your first manufacturer to start organizing the catalog.' }}">
        @if($canManageManufacturers && !$hasFilters)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('manufacturers.create')">New manufacturer</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            @if($canManageManufacturers)
            <th scope="col" class="cat-col-check">
                <input type="checkbox" class="cat-check"
                       :checked="ids.length > 0 && selected.length === ids.length"
                       x-effect="$el.indeterminate = selected.length > 0 && selected.length < ids.length"
                       @change="selected = $event.target.checked ? ids.slice() : []"
                       aria-label="Select all manufacturers on this page">
            </th>
            @endif
            <th scope="col" class="cat-col-id">ID</th>
            <th scope="col">Name</th>
            <th scope="col" class="cat-col-sort x-td-num">Sort</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($manufacturers as $m)
        <tr>
            @if($canManageManufacturers)
            <td class="cat-col-check">
                <input type="checkbox" class="cat-check" name="ids[]" value="{{ (string) $m->manufacturer_id }}" form="manufacturer-bulk-form" x-model="selected">
            </td>
            @endif
            <td class="cat-col-id" data-label="ID"><span class="x-num">{{ $m->manufacturer_id }}</span></td>
            <td data-label="Name">
                @if($canManageManufacturers)
                    <a class="x-row-link" href="{{ route('manufacturers.edit', $m->manufacturer_id) }}">{{ $m->name ?? '-' }}</a>
                @else
                    {{ $m->name ?? '-' }}
                @endif
            </td>
            <td class="cat-col-sort x-td-num" data-label="Sort"><span class="x-num">{{ (int) $m->sort_order }}</span></td>
            <td class="x-td-actions">
                @if($canManageManufacturers)
                <x-ui.menu label="Manufacturer {{ $m->manufacturer_id }} actions">
                    <a class="x-menu__item" href="{{ route('manufacturers.edit', $m->manufacturer_id) }}">Edit</a>
                    <div class="x-menu__sep"></div>
                    <button type="button" class="x-menu__item x-menu__item--danger"
                            data-confirm="Delete {{ $m->name }}? This cannot be undone."
                            data-confirm-submit="mfg-del-{{ $m->manufacturer_id }}">Delete</button>
                    <form id="mfg-del-{{ $m->manufacturer_id }}" method="POST" action="{{ route('manufacturers.destroy', $m->manufacturer_id) }}" class="x-sr">
                        @csrf
                        @method('DELETE')
                    </form>
                </x-ui.menu>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$manufacturers" />
@endif
</div>
@endsection
