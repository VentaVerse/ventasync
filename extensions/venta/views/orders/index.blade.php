@extends('layouts.app')
@section('title', $setting->store_name . ' Orders')
@section('breadcrumb', 'Marketplace / Venta / ' . $setting->store_name . ' / Orders')

@section('content')
@include('integrations.partials._tab_strip', ['activeTabId' => 'venta:' . $setting->id])
<div class="page-header">
    <div>
        <h2>Venta Orders <span class="text-secondary text-sm">({{ $orders->total() }})</span></h2>
        <div class="text-muted text-sm">{{ $setting->store_name }}</div>
    </div>
</div>

{{-- Fetch / Pull Orders --}}
<div class="card mb-16">
    <form method="POST" action="{{ route('ext.venta.orders.fetch', $setting->id) }}" class="d-flex gap-12 flex-wrap items-center">
        @csrf
        <div>
            <label class="text-xs text-secondary">From</label>
            <input type="date" name="date_from" value="{{ old('date_from', now()->subDays(15)->format('Y-m-d')) }}" class="input" style="width:auto;">
        </div>
        <div>
            <label class="text-xs text-secondary">To</label>
            <input type="date" name="date_to" value="{{ old('date_to', now()->format('Y-m-d')) }}" class="input" style="width:auto;">
        </div>
        <div class="d-flex gap-8">
            <button type="submit" class="btn">Fetch Orders</button>
        </div>
        <div class="text-secondary text-xs" style="flex:1;">
            <strong>From/To</strong> required. Pulls orders within the selected date range from Venta.
        </div>
    </form>
</div>

@php
    $currentStatus = request('status', '');
    $allCount = $statusCounts->sum();
    $q = request('q', '');
    $hasFilters = $q !== '' || $currentStatus !== '';
@endphp

{{-- Status filter tabs --}}
<div class="card">
    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px;">
        <a href="{{ route('ext.venta.orders.index', $setting->id) }}"
           class="badge {{ $currentStatus === '' ? 'badge-blue' : '' }}" style="text-decoration:none; cursor:pointer;">
            All ({{ $allCount }})
        </a>
        @foreach($statusCounts as $status => $count)
            <a href="{{ route('ext.venta.orders.index', ['store' => $setting->id, 'status' => $status]) }}"
               class="badge {{ $currentStatus === $status ? 'badge-blue' : '' }}" style="text-decoration:none; cursor:pointer;">
                {{ ucwords(str_replace('_', ' ', $status)) }} ({{ $count }})
            </a>
        @endforeach
    </div>

    <form id="venta-bulk-delete-form" method="POST" action="{{ route('ext.venta.orders.bulk_delete', $setting->id) }}" data-confirm="Delete selected Venta orders?" style="display:none; padding:8px 16px; margin-bottom:12px; background:var(--surface-alt); border:1px solid var(--border); border-radius:var(--radius-md); align-items:center; gap:10px;">
        @csrf
        <span class="text-sm font-bold"><span id="venta-bulk-count">0</span> selected</span>
        <button type="submit" class="btn small danger">Delete Selected</button>
    </form>

    {{-- Search --}}
    <form method="GET" action="{{ route('ext.venta.orders.index', $setting->id) }}">
        @if($currentStatus)
            <input type="hidden" name="status" value="{{ $currentStatus }}">
        @endif
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:16px;">
            <div style="position:relative; flex:1; max-width:400px;">
                <svg style="position:absolute; left:12px; top:50%; transform:translateY(-50%); width:16px; height:16px; color:var(--text-muted); pointer-events:none;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input class="input" name="q" value="{{ $q }}" placeholder="Search customer, order #, tracking..." style="padding-left:36px;">
            </div>
            <button class="btn" type="submit">Search</button>
            @if($hasFilters)
                <a href="{{ route('ext.venta.orders.index', $setting->id) }}" class="btn secondary" title="Clear all filters">Clear</a>
            @endif
        </div>
    </form>

    @php
        $sortUrl = function (string $col) use ($setting, $sort, $dir) {
            $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
            return route('ext.venta.orders.index', array_merge(request()->except('sort', 'dir'), [
                'store' => $setting->id,
                'sort'  => $col,
                'dir'   => $newDir,
            ]));
        };
        $arrow = function (string $col) use ($sort, $dir) {
            if ($sort !== $col) return '';
            return $dir === 'asc' ? '&#9650;' : '&#9660;';
        };
    @endphp
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th style="width:36px;"><input type="checkbox" id="venta-select-all"></th>
                <th style="width:80px;"><a href="{{ $sortUrl('catalog_order_id') }}">ERP # {!! $arrow('catalog_order_id') !!}</a></th>
                <th style="width:100px;"><a href="{{ $sortUrl('venta_order_number') }}">Venta # {!! $arrow('venta_order_number') !!}</a></th>
                <th><a href="{{ $sortUrl('customer_name') }}">Customer {!! $arrow('customer_name') !!}</a></th>
                <th><a href="{{ $sortUrl('status') }}">Status {!! $arrow('status') !!}</a></th>
                <th style="width:140px;"><a href="{{ $sortUrl('payment_method') }}">Payment {!! $arrow('payment_method') !!}</a></th>
                <th class="text-right" style="width:100px;"><a href="{{ $sortUrl('total') }}">Total {!! $arrow('total') !!}</a></th>
                <th style="width:130px;"><a href="{{ $sortUrl('tracking_number') }}">Tracking {!! $arrow('tracking_number') !!}</a></th>
                <th style="width:160px;"><a href="{{ $sortUrl('order_created_at') }}">Date {!! $arrow('order_created_at') !!}</a></th>
                <th style="width:80px;">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse($orders as $o)
                @php
                    $statusLabel = ucwords(str_replace('_', ' ', $o->status ?? ''));
                    $badgeClass = match(strtolower($o->status ?? '')) {
                        'completed', 'delivered' => 'badge-green',
                        'cancelled', 'refunded' => 'badge-red',
                        'pending', 'unpaid' => 'badge-yellow',
                        'processing', 'in_transit', 'shipped' => 'badge-blue',
                        default => '',
                    };
                @endphp
                <tr>
                    <td><input type="checkbox" class="venta-order-check" name="ids[]" value="{{ $o->id }}" form="venta-bulk-delete-form"></td>
                    <td>@if($o->catalog_order_id)<a href="{{ route('orders.show', $o->catalog_order_id) }}">#{{ $o->catalog_order_id }}</a>@else <span class="text-muted">—</span> @endif</td>
                    <td class="font-bold">{{ $o->venta_order_number ?: $o->venta_order_id }}</td>
                    <td>{{ $o->customer_name ?: '—' }}</td>
                    <td><span class="badge {{ $badgeClass }}">{{ $statusLabel ?: '—' }}</span></td>
                    <td>{{ $o->payment_method ?: '—' }}</td>
                    <td class="text-right">{{ number_format((float)$o->total, 2) }}</td>
                    <td>{{ $o->tracking_number ?: '—' }}</td>
                    <td class="text-nowrap">{{ $o->order_created_at ? $o->order_created_at->format('M d, Y H:i') : '—' }}</td>
                    <td>
                        <div class="d-flex gap-6 items-center">
                            @if($o->catalog_order_id)
                                <a class="btn small" href="{{ route('orders.show', $o->catalog_order_id) }}">View</a>
                            @endif
                            <form method="POST" action="{{ route('ext.venta.orders.destroy', [$setting->id, $o->id]) }}" data-confirm="Delete this Venta order?" style="margin:0;">
                                @csrf @method('DELETE')
                                <button class="btn danger small" type="submit">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="text-center text-muted" style="padding:24px;">No orders found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-16">
        {{ $orders->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>
@push('scripts')
<script>
(function() {
    var selectAll = document.getElementById('venta-select-all');
    var bulkBar = document.getElementById('venta-bulk-delete-form');
    var bulkCount = document.getElementById('venta-bulk-count');
    function checks() { return Array.from(document.querySelectorAll('.venta-order-check')); }

    function update() {
        var boxes = checks();
        var checked = boxes.filter(function(b) { return b.checked; }).length;
        bulkBar.style.display = checked > 0 ? 'flex' : 'none';
        bulkCount.textContent = checked;
        if (selectAll) {
            selectAll.checked = checked === boxes.length && boxes.length > 0;
            selectAll.indeterminate = checked > 0 && checked < boxes.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checks().forEach(function(b) { b.checked = selectAll.checked; });
            update();
        });
    }
    checks().forEach(function(b) { b.addEventListener('change', update); });
    update();
})();
</script>
@endpush
@endsection
