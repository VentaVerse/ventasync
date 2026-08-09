@extends('layouts.blotter')
@section('title', 'Orders')

@section('content')
@php
    // Phase 4.5 view/manage gating: read-tier operators see the list and can
    // open detail pages; create/edit/delete are manage-tier only. Inherited
    // from the legacy orders/index.blade.php this page replaced (deleted in
    // Round F Task 4) - hardcoded true until that gating lands.
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
    {{-- I5: without these, changing a filter drops whatever column the list
         was sorted by - the filter form only carries q/status/source, so a
         submit silently reset sort/dir to the controller's defaults. --}}
    <input type="hidden" name="sort" value="{{ $sort }}">
    <input type="hidden" name="dir" value="{{ $dir }}">
</form>

@if($orders->isEmpty())
    <x-ui.empty title="No orders match those filters"
                description="Try clearing the search or choosing a different status." />
@else
@php
    // I5: restores the sort links the old list had (order_id, firstname,
    // total, date_added - the same $sortable set OrderController already
    // validates against and the same $sort/$dir it already computes and
    // passes here; the controller is untouched). request()->except keeps
    // every other query param (q, status, source, page) intact so sorting
    // never discards an active filter, carrying over the sort-link
    // construction from the legacy orders/index.blade.php this page replaced.
    $sortUrl = fn (string $col) => route('orders.index', array_merge(
        request()->except('sort', 'dir'),
        ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc']
    ));
@endphp
<x-ui.table>
    <x-slot:head>
        <tr>
            <th class="x-col-id">
                <a class="x-th-sort" href="{{ $sortUrl('order_id') }}">
                    Order
                    @if($sort === 'order_id')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th>
                <a class="x-th-sort" href="{{ $sortUrl('firstname') }}">
                    Customer
                    @if($sort === 'firstname')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th class="x-col-chan">Channel</th>
            <th class="x-col-stat">Status</th>
            <th class="x-td-num x-col-total">
                <a class="x-th-sort x-th-sort--num" href="{{ $sortUrl('total') }}">
                    Total
                    @if($sort === 'total')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th class="x-col-date">
                <a class="x-th-sort" href="{{ $sortUrl('date_added') }}">
                    Date
                    @if($sort === 'date_added')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
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
            {{-- Channel and Status both used dot-plus-text, so a brand hue like
                 venta green or shopee orange could sit one column away from
                 the semantically identical success green or warning amber and
                 read as the same signal. Rather than keep re-tuning hues that
                 will collide again the next time a brand's real colour lands
                 near a status band (shopee's orange already borders warning),
                 Channel drops the dot and lets the brand-tinted text carry
                 identity on its own. Status keeps the dot, since it is the
                 field a reader scans a long list for and the dot is what
                 makes that scan possible from a distance. Presence-of-dot is
                 now the category signal: dot means "this is a state", no dot
                 means "this is a place it came from" - true regardless of
                 which two hues happen to land close together. --}}
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

<div class="x-pager">
    <span class="x-pager__count x-num">{{ number_format($orders->firstItem() ?? 0) }} to {{ number_format($orders->lastItem() ?? 0) }} of {{ number_format($orders->total()) }}</span>
    <div class="x-pager__nav">
        <x-ui.button size="sm" :href="$orders->previousPageUrl()" :disabled="!$orders->previousPageUrl()"><x-ui.icon name="chevron-left" size="14" /> Previous</x-ui.button>
        <x-ui.button size="sm" :href="$orders->nextPageUrl()" :disabled="!$orders->nextPageUrl()">Next <x-ui.icon name="chevron-right" size="14" /></x-ui.button>
    </div>
</div>
@endif
@endsection
