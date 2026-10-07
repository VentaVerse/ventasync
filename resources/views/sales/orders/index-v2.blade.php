@extends('layouts.blotter')
@section('title', 'Orders')

@section('content')
@php
    $canManageOrders = true;
@endphp
<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Orders</h1>
        <p class="x-page-sub">{{ number_format($orders->total()) }} {{ $orders->total() === 1 ? 'order' : 'orders' }}{{ request()->hasAny(['status', 'source', 'q', 'search']) ? ' match' : '' }}</p>
    </div>
    @if($canManageOrders)
    <x-ui.button variant="primary" :href="route('orders.create')">New order</x-ui.button>
    @endif
</div>

<form method="GET" action="{{ route('orders.index') }}" class="x-filters" id="orders-filter">
    <x-ui.input type="search" name="q" value="{{ $q }}" placeholder="Order no, buyer, product or tracking" class="x-filters__search" />
    <div class="x-select-wrap x-filters__select">
        <select name="status" class="x-input" data-autosubmit>
            <option value="0">All statuses</option>
            @foreach($statuses as $s)
                <option value="{{ $s->order_status_id }}" @selected($statusId == $s->order_status_id)>{{ $s->name }}</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>
    <div class="x-select-wrap x-filters__select">
        <select name="source" class="x-input" data-autosubmit>
            <option value="">All sources</option>
            <option value="manual" @selected($source === 'manual')>Manual</option>
            @foreach($sourceOptions as $opt)
                <option value="{{ $opt['value'] }}" @selected($source === $opt['value'])>{{ $opt['label'] }}</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>
    <input type="hidden" name="sort" value="{{ $sort }}">
    <input type="hidden" name="dir" value="{{ $dir }}">
</form>

@if($orders->isEmpty())
    <x-ui.empty title="No orders match those filters"
                description="Try clearing the search or choosing a different status." />
@else
@php
    $sortUrl = fn (string $col) => route('orders.index', array_merge(
        request()->except('sort', 'dir'),
        ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc']
    ));
@endphp
<x-ui.table class="so-table">
    <x-slot:head>
        <tr>
            <th scope="col" class="x-col-id">
                <a class="x-th-sort" href="{{ $sortUrl('order_id') }}">
                    Order
                    @if($sort === 'order_id')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col">
                <a class="x-th-sort" href="{{ $sortUrl('firstname') }}">
                    Customer
                    @if($sort === 'firstname')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="x-col-chan">Channel</th>
            <th scope="col" class="x-col-stat">Status</th>
            <th scope="col" class="x-td-num x-col-total">
                <a class="x-th-sort x-th-sort--num" href="{{ $sortUrl('total') }}">
                    Total
                    @if($sort === 'total')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="x-col-date">
                <a class="x-th-sort" href="{{ $sortUrl('date_added') }}">
                    Date
                    @if($sort === 'date_added')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($orders as $o)
        @php
            $src = trim((string) $o->marketplace_source);
            $chanKey = $src === '' ? '' : strtok($src, ':');
            $chanTone = $src === '' ? 'neutral' : $chanKey;
            $chanOpt = $src === '' ? null : ($sourceLabelsMap[$src] ?? null);
            $chanWord = $chanOpt['channel'] ?? ($chanOpt['label'] ?? ucfirst((string) $chanKey));
            $chanLabel = $src === '' ? 'Manual'
                : (trim((string) ($o->store_name ?? '')) !== ''
                    ? \App\Support\StoreLabel::of($chanWord, (string) $o->store_name)
                    : ($chanOpt['label'] ?? $chanWord));
        @endphp
        <tr>
            <td class="x-col-id" data-label="Order">
                <a class="x-row-link x-num" href="{{ route('orders.show', $o->order_id) }}">{{ $o->order_id }}</a>
            </td>
            <td class="x-cell-strong" data-label="Customer">{{ trim($o->firstname . ' ' . $o->lastname) ?: 'Guest' }}</td>
            <td data-label="Channel"><x-ui.badge :tone="$chanTone" :dot="false">{{ $chanLabel }}</x-ui.badge></td>
            <td data-label="Status"><x-ui.badge :tone="\App\Support\OrderStatusTone::for($o->status->name ?? null)">{{ $o->status->name ?? '-' }}</x-ui.badge></td>
            <td class="x-td-num x-col-total" data-label="Total">
                <x-money :php="(float) $o->total" :foreign="$o->foreign_total" :foreign-code="$o->currency_code" />
            </td>
            <td class="x-num x-cell-muted" data-label="Date">{{ \Illuminate\Support\Carbon::parse($o->date_added)->format('Y-m-d H:i') }}</td>
            <td class="x-td-actions">
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

<x-ui.pager :paginator="$orders" />
@endif
@endsection
