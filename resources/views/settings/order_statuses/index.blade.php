@extends('layouts.blotter')
@section('title', 'Order statuses')
@section('breadcrumb', 'Order statuses')

@section('content')
@php
    $canManageOrderStatuses = auth()->user()?->hasPermission('manage_settings/order_status') ?? false;

    $hasFilters = ($q ?? '') !== '';
@endphp

<div class="x-list-head">
    <div>
        @include('partials.back-to-settings')
        <h1 class="x-page-title">Order statuses</h1>
        <p class="x-page-sub">{{ number_format($statuses->total()) }} {{ Str::plural('status', $statuses->total()) }} an order can move through. Each marketplace maps its own statuses onto these in that channel's settings.</p>
    </div>
    @if($canManageOrderStatuses)
        <x-ui.button variant="primary" :href="route('order_statuses.create')">New status</x-ui.button>
    @endif
</div>

@include('partials.flash')

<form method="GET" action="{{ route('order_statuses.index') }}" class="x-filters">
    <label class="x-sr" for="st-os-q">Search order statuses</label>
    <x-ui.input type="search" id="st-os-q" name="q" value="{{ $q ?? '' }}"
                placeholder="Search order statuses" class="x-filters__search" />
</form>

@if($statuses->isEmpty())
    <x-ui.empty
        title="{{ $hasFilters ? 'No statuses match that search' : 'No order statuses yet' }}"
        description="{{ $hasFilters ? 'Try a different name.' : 'Add the statuses your orders move through, and say which ones take stock and which ones count as revenue.' }}">
        @if($canManageOrderStatuses && !$hasFilters)
            <x-slot:action>
                <x-ui.button variant="primary" :href="route('order_statuses.create')">New status</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
<div class="st-os-page" x-data="{
        selected: [],
        ids: {{ \Illuminate\Support\Js::from($statuses->pluck('order_status_id')->map(fn ($v) => (string) $v)->values()) }}
    }">

@if($canManageOrderStatuses)
    <form id="st-os-bulk-form" method="POST" action="{{ route('order_statuses.bulk') }}">@csrf</form>

    <div class="st-bulkbar" x-show="selected.length > 0" x-cloak>
        <span class="st-bulkbar__count"><span class="x-num" x-text="selected.length"></span> selected</span>
        <div class="st-bulkbar__actions">
            <x-ui.button type="button" variant="danger" size="sm"
                         data-confirm="Delete the selected order statuses? Any order still sitting on one of them keeps the number but loses its name. This cannot be undone."
                         data-confirm-submit="st-os-bulk-form">Delete selected</x-ui.button>
        </div>
    </div>
@endif

<x-ui.table>
    <x-slot:head>
        <tr>
            @if($canManageOrderStatuses)
            <th scope="col" class="st-col-check">
                <input type="checkbox" class="st-check"
                       :checked="ids.length > 0 && selected.length === ids.length"
                       x-effect="$el.indeterminate = selected.length > 0 && selected.length < ids.length"
                       @change="selected = $event.target.checked ? ids.slice() : []"
                       aria-label="Select every order status on this page">
            </th>
            @endif
            <th scope="col" class="st-col-id">ID</th>
            <th scope="col">Name</th>
            <th scope="col" class="st-col-flag">Subtract stock</th>
            <th scope="col" class="st-col-flag">Counts as revenue</th>
            <th scope="col" class="bl-col-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($statuses as $s)
        <tr>
            @if($canManageOrderStatuses)
            <td class="st-col-check">
                <input type="checkbox" class="st-check" name="ids[]" value="{{ (string) $s->order_status_id }}"
                       form="st-os-bulk-form" x-model="selected"
                       aria-label="Select {{ $s->name }}">
            </td>
            @endif
            <td class="st-col-id" data-label="ID"><span class="x-num">{{ $s->order_status_id }}</span></td>
            <td data-label="Name">
                @if($canManageOrderStatuses)
                    <a class="x-row-link" href="{{ route('order_statuses.edit', $s->order_status_id) }}">{{ $s->name }}</a>
                @else
                    {{ $s->name }}
                @endif
            </td>
            <td class="st-col-flag" data-label="Subtract stock">
                <x-ui.badge :tone="$s->subtract_stock ? 'success' : 'neutral'">{{ $s->subtract_stock ? 'Yes' : 'No' }}</x-ui.badge>
            </td>
            <td class="st-col-flag" data-label="Counts as revenue">
                <x-ui.badge :tone="$s->add_revenue ? 'success' : 'neutral'">{{ $s->add_revenue ? 'Yes' : 'No' }}</x-ui.badge>
            </td>
            <td class="bl-col-actions">
                @if($canManageOrderStatuses)
                <div class="bl-rowactions">
                    <a class="x-btn x-btn--sm" href="{{ route('order_statuses.edit', $s->order_status_id) }}">Edit</a>
                    <button type="button" class="x-btn x-btn--sm x-btn--danger"
                            data-confirm="Delete {{ $s->name }}? This cannot be undone."
                            data-confirm-submit="st-os-del-{{ $s->order_status_id }}">Delete</button>
                </div>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>

@if($canManageOrderStatuses)
    @foreach($statuses as $s)
        <form id="st-os-del-{{ $s->order_status_id }}" method="POST" action="{{ route('order_statuses.destroy', $s->order_status_id) }}" class="x-sr">
            @csrf
            @method('DELETE')
        </form>
    @endforeach
@endif

<x-ui.pager :paginator="$statuses" />
</div>
@endif
@endsection
