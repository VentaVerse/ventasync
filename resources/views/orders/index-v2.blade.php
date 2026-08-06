@extends('layouts.shell')
@section('title', 'Orders')
@section('breadcrumb', 'Sales / Orders')

@section('content')
@php
    // Phase 4.5 view/manage gating: read-tier operators see the list and can
    // open detail pages; create/edit/delete are manage-tier only. Mirrors
    // orders/index.blade.php - hardcoded true until that gating lands.
    $canManageOrders = true;
@endphp
<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Orders</h1>
        <p class="x-page-sub">{{ number_format($orders->total()) }} across all channels</p>
    </div>
    @if($canManageOrders)
    <x-ui.button variant="primary" :href="route('orders.create')">New order</x-ui.button>
    @endif
</div>

<form method="GET" action="{{ route('orders.index') }}" class="x-filters" id="orders-filter">
    <x-ui.input type="search" name="q" value="{{ $q }}" placeholder="Search name, email, order ID" class="x-filters__search" />
    <select name="status" class="x-input x-filters__select" onchange="this.form.submit()">
        <option value="0">All statuses</option>
        @foreach($statuses as $s)
            <option value="{{ $s->order_status_id }}" @selected($statusId == $s->order_status_id)>{{ $s->name }}</option>
        @endforeach
    </select>
    <select name="source" class="x-input x-filters__select" onchange="this.form.submit()">
        <option value="">All sources</option>
        <option value="manual" @selected($source === 'manual')>Manual</option>
        @foreach($sourceOptions as $opt)
            <option value="{{ $opt['value'] }}" @selected($source === $opt['value'])>{{ $opt['label'] }}</option>
        @endforeach
    </select>
</form>

@if($orders->isEmpty())
    <x-ui.empty title="No orders match those filters"
                description="Try clearing the search or choosing a different status." />
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th class="x-col-id">Order</th>
            <th>Customer</th>
            <th class="x-col-chan">Channel</th>
            <th class="x-col-stat">Status</th>
            <th class="x-td-num x-col-total">Total</th>
            <th class="x-col-date">Date</th>
            <th class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($orders as $o)
        @php
            $src = trim((string) $o->marketplace_source);
            $chanKey = $src === '' ? '' : strtok($src, ':');
            $chanTone = $src === '' ? 'neutral' : $chanKey;
            $chanOpt = $src === '' ? null : ($sourceLabelsMap[$src] ?? null);
            $chanLabel = $src === '' ? 'Manual' : ($chanOpt['label'] ?? ucfirst($chanKey));
        @endphp
        <tr>
            <td class="x-col-id" data-label="Order">
                <a class="x-row-link x-num" href="{{ route('orders.show', $o->order_id) }}">{{ $o->order_id }}</a>
            </td>
            <td class="x-cell-strong" data-label="Customer">{{ trim($o->firstname . ' ' . $o->lastname) ?: 'Guest' }}</td>
            <td data-label="Channel"><x-ui.badge :tone="$chanTone">{{ $chanLabel }}</x-ui.badge></td>
            <td data-label="Status"><x-ui.badge tone="neutral">{{ $o->status->name ?? '-' }}</x-ui.badge></td>
            <td class="x-td-num" data-label="Total">
                <x-money :php="(float) $o->total" :foreign="$o->foreign_total" :foreign-code="$o->currency_code" />
            </td>
            <td class="x-num x-cell-muted" data-label="Date">{{ \Illuminate\Support\Carbon::parse($o->date_added)->format('Y-m-d H:i') }}</td>
            <td class="x-td-actions" data-label="Actions">
                <x-ui.menu label="Order {{ $o->order_id }} actions">
                    <a class="x-menu__item" href="{{ route('orders.show', $o->order_id) }}">View</a>
                    @if($canManageOrders)
                    <a class="x-menu__item" href="{{ route('orders.edit', $o->order_id) }}">Edit</a>
                    <div class="x-menu__sep"></div>
                    <button type="button" class="x-menu__item x-menu__item--danger"
                            data-confirm="Delete order {{ $o->order_id }}? This cannot be undone."
                            data-confirm-submit="ord-del-{{ $o->order_id }}">Delete</button>
                    <form id="ord-del-{{ $o->order_id }}" method="POST" action="{{ route('orders.destroy', $o->order_id) }}" class="x-sr">
                        @csrf
                        @method('DELETE')
                    </form>
                    @endif
                </x-ui.menu>
            </td>
        </tr>
    @endforeach
</x-ui.table>

<div class="x-pager">
    <span class="x-pager__count x-num">{{ number_format($orders->firstItem() ?? 0) }} to {{ number_format($orders->lastItem() ?? 0) }} of {{ number_format($orders->total()) }}</span>
    <div class="x-pager__nav">
        <x-ui.button size="sm" :href="$orders->previousPageUrl()" :disabled="!$orders->previousPageUrl()"><x-ui.icon name="chevron-left" size="14" /> Previous</x-ui.button>
        <x-ui.button size="sm" :href="$orders->nextPageUrl()" :disabled="!$orders->nextPageUrl()">Next <x-ui.icon name="chevron-right" size="14" /></x-ui.button>
    </div>
</div>
@endif
@endsection
