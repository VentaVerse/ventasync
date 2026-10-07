@extends('layouts.blotter')
@section('title', 'Categories')

@section('content')
@php
    $canManageCategories = auth()->user()?->hasPermission('manage_catalog/category') ?? false;

    $hasFilters = $q !== '';
@endphp

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Categories</h1>
        <p class="x-page-sub">{{ number_format($categories->total()) }} {{ Str::plural('category', $categories->total()) }}</p>
    </div>
    @if($canManageCategories)
    <x-ui.button variant="primary" :href="route('categories.create')">New category</x-ui.button>
    @endif
</div>

<form method="GET" action="{{ route('categories.index') }}" class="x-filters" id="categories-filter">
    <x-ui.input type="search" name="q" value="{{ $q }}" placeholder="Search categories" class="x-filters__search" />
</form>

<div class="cat-page" x-data="{
        selected: [],
        ids: {{ \Illuminate\Support\Js::from($categories->pluck('category_id')->map(fn ($v) => (string) $v)->values()) }}
    }">

@if($canManageCategories)
    <form id="category-bulk-form" method="POST" action="{{ route('categories.bulk') }}" x-ref="bulkForm">
        @csrf
        <input type="hidden" name="action" x-ref="bulkAction" value="">
    </form>

    <div class="cat-bulkbar" x-show="selected.length > 0" x-cloak>
        <span class="cat-bulkbar__count"><span class="x-num" x-text="selected.length"></span> selected</span>
        <div class="cat-bulkbar__actions">
            <x-ui.button type="button" size="sm" @click="$refs.bulkAction.value = 'enable'; $refs.bulkForm.requestSubmit()">Enable</x-ui.button>
            <x-ui.button type="button" size="sm" @click="$refs.bulkAction.value = 'disable'; $refs.bulkForm.requestSubmit()">Disable</x-ui.button>
        </div>
    </div>
@endif

@if($categories->isEmpty())
    <x-ui.empty title="{{ $hasFilters ? 'No categories match that search' : 'No categories yet' }}"
                description="{{ $hasFilters ? 'Try a different search term.' : 'Add your first category to start organizing the catalog.' }}">
        @if($canManageCategories && !$hasFilters)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('categories.create')">New category</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            @if($canManageCategories)
            <th scope="col" class="cat-col-check">
                <input type="checkbox" class="cat-check"
                       :checked="ids.length > 0 && selected.length === ids.length"
                       x-effect="$el.indeterminate = selected.length > 0 && selected.length < ids.length"
                       @change="selected = $event.target.checked ? ids.slice() : []"
                       aria-label="Select all categories on this page">
            </th>
            @endif
            <th scope="col" class="cat-col-id">ID</th>
            <th scope="col">Name</th>
            <th scope="col">Parent</th>
            <th scope="col" class="cat-col-sort x-td-num">Sort</th>
            <th scope="col" class="cat-col-stat">Status</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($categories as $c)
        <tr>
            @if($canManageCategories)
            <td class="cat-col-check">
                <input type="checkbox" class="cat-check" name="ids[]" value="{{ (string) $c->category_id }}" form="category-bulk-form" x-model="selected">
            </td>
            @endif
            <td class="cat-col-id" data-label="ID"><span class="x-num">{{ $c->category_id }}</span></td>
            <td data-label="Name">
                @if($canManageCategories)
                    <a class="x-row-link" href="{{ route('categories.edit', $c->category_id) }}">{{ $c->name ?? '-' }}</a>
                @else
                    {{ $c->name ?? '-' }}
                @endif
            </td>
            <td data-label="Parent">{{ $c->parent_name ?? '-' }}</td>
            <td class="cat-col-sort x-td-num" data-label="Sort"><span class="x-num">{{ (int) $c->sort_order }}</span></td>
            <td class="cat-col-stat" data-label="Status">
                <x-ui.badge :tone="(int) $c->status === 1 ? 'success' : 'neutral'">{{ (int) $c->status === 1 ? 'Enabled' : 'Disabled' }}</x-ui.badge>
            </td>
            <td class="x-td-actions">
                @if($canManageCategories)
                <x-ui.menu label="Category {{ $c->category_id }} actions">
                    <a class="x-menu__item" href="{{ route('categories.edit', $c->category_id) }}">Edit</a>
                    <div class="x-menu__sep"></div>
                    <button type="button" class="x-menu__item x-menu__item--danger"
                            data-confirm="Delete {{ $c->name }}? This cannot be undone."
                            data-confirm-submit="cat-del-{{ $c->category_id }}">Delete</button>
                    <form id="cat-del-{{ $c->category_id }}" method="POST" action="{{ route('categories.destroy', $c->category_id) }}" class="x-sr">
                        @csrf
                        @method('DELETE')
                    </form>
                </x-ui.menu>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$categories" />
@endif
</div>
@endsection
