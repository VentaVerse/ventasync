@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Lazada / Return or Refund')

@section('content')
<div class="marketplace-lazada">
<div class="page-header">
    <h2>Lazada Return or Refund <span class="text-secondary text-sm">({{ $orders->total() }})</span></h2>
</div>

<div class="card mb-16">
    <form method="POST" action="{{ route('ext.lazada.orders.fetch_returns') }}" class="d-flex gap-12 flex-wrap items-center">
        @csrf
        <div>
            <label class="text-xs text-secondary">From</label>
            <input type="date" name="date_from" value="{{ old('date_from', request('date_from')) }}" class="input" style="width:auto;">
        </div>
        <div>
            <label class="text-xs text-secondary">To</label>
            <input type="date" name="date_to" value="{{ old('date_to', request('date_to')) }}" class="input" style="width:auto;">
        </div>
        <div style="align-self:flex-end;">
            <button type="submit" class="btn">Fetch Returns</button>
        </div>
        <div class="text-secondary text-xs" style="flex:1;">
            Leave <strong>From/To</strong> empty to fetch <strong>all available</strong> returns. Use dates for a specific range.
        </div>
    </form>
</div>

@if($last_result)
    <div class="alert {{ $last_result['ok'] ? 'success' : 'danger' }}">
        <strong>{{ $last_result['ok'] ? 'OK' : 'Error' }}</strong>
        @if(!empty($last_result['message']))
            <div style="margin-top:6px;">{{ $last_result['message'] }}</div>
        @endif
    </div>
@endif

{{-- Order tabs (mirrors orders page, with Return/Refund/Cancel active) --}}
<div class="tabs mb-12">
    <a href="{{ route('ext.lazada.orders.index', ['tab' => 'ALL']) }}" class="tab">All</a>
    <a href="{{ route('ext.lazada.orders.index', ['tab' => 'UNPAID']) }}" class="tab">Unpaid</a>
    <a href="{{ route('ext.lazada.orders.index', ['tab' => 'TO_SHIP']) }}" class="tab">To Ship</a>
    <a href="{{ route('ext.lazada.orders.index', ['tab' => 'SHIPPING']) }}" class="tab">Shipping</a>
    <a href="{{ route('ext.lazada.orders.index', ['tab' => 'DELIVERED']) }}" class="tab">Delivered</a>
    <a href="{{ route('ext.lazada.orders.index', ['tab' => 'CANCELLATION']) }}" class="tab">Cancellation</a>
    <a href="{{ route('ext.lazada.orders.index', ['tab' => 'FAILED_DELIVERY']) }}" class="tab">Failed Delivery</a>
    <a href="{{ route('ext.lazada.orders.returns') }}" class="tab active">Return/Refund/Cancel</a>
</div>

@php
    $tabs = $tabs ?? [];
    $active_tab = $active_tab ?? 'ALL';
    $tab_counts = $tab_counts ?? [];
@endphp

{{-- Status tabs --}}
<div class="tabs mb-12">
    @foreach($tabs as $key => $label)
        @php
            $isActive = strtoupper((string)$active_tab) === strtoupper((string)$key);
            $count = $tab_counts[$key] ?? null;
            $qs = array_merge(request()->query(), ['tab' => $key]);
            unset($qs['page']);
        @endphp
        <a href="{{ route('ext.lazada.orders.returns', $qs) }}" class="tab {{ $isActive ? 'active' : '' }}">
            <span>{{ $label }}</span>
            @if($count !== null && $count > 0)
                <span style="margin-left:6px; display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 6px; border-radius:999px; font-weight:700; font-size:12px; {{ $isActive ? 'background:#fff; color:#0F146D;' : 'background:#0F146D; color:#fff;' }}">{{ $count }}</span>
            @endif
        </a>
    @endforeach
</div>

<div class="card mb-12" style="padding:12px 16px;">
    <form method="GET" action="{{ route('ext.lazada.orders.returns') }}" class="d-flex gap-10 flex-wrap items-center">
        <input type="hidden" name="per_page" value="{{ (int)($per_page ?? 10) }}">
        <input type="hidden" name="tab" value="{{ $active_tab }}">
        <div>
            <label class="text-xs text-secondary">Reverse Order ID</label>
            <input type="text" name="reverse_order_id" value="{{ $filters['reverse_order_id'] ?? '' }}" placeholder="Reverse Order ID" class="input" style="min-width:200px;">
        </div>
        <div>
            <label class="text-xs text-secondary">Original Order ID</label>
            <input type="text" name="trade_order_id" value="{{ $filters['trade_order_id'] ?? '' }}" placeholder="Original Order ID" class="input" style="min-width:200px;">
        </div>
        <div style="align-self:flex-end;">
            <button type="submit" class="btn">Search</button>
        </div>
        <div style="align-self:flex-end;">
            <a href="{{ route('ext.lazada.orders.returns', ['per_page' => (int)($per_page ?? 10), 'tab' => $active_tab]) }}" class="btn secondary">Reset</a>
        </div>
    </form>
</div>

<div class="card">
    @if($orders->count() === 0)
        <div style="padding:40px 20px; text-align:center;">
            <div class="text-secondary">No return/refund orders found.</div>
            @if(($filters['reverse_order_id'] ?? '') !== '' || ($filters['trade_order_id'] ?? '') !== '')
                <div class="text-xs text-muted" style="margin-top:8px;">Try adjusting your search filters.</div>
            @else
                <div class="text-xs text-muted" style="margin-top:8px;">Click "Fetch Returns" to pull data from Lazada.</div>
            @endif
        </div>
    @else
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Reverse Order ID</th>
                    <th>Original Order</th>
                    <th>Status</th>
                    <th>Type</th>
                    <th>Reason</th>
                    <th>Refund Amount</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
                @foreach($orders as $order)
                    <tr>
                        <td>
                            <span class="font-semibold">{{ $order->reverse_order_id }}</span>
                        </td>
                        <td>
                            @if($order->trade_order_id)
                                <a href="{{ route('ext.lazada.orders.show', ['orderId' => $order->trade_order_id]) }}" style="color:var(--accent); text-decoration:none;">
                                    {{ $order->trade_order_id }}
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @php
                                $statusColors = [
                                    'initiated' => 'background:#dbeafe; color:#1e40af;',
                                    'in_progress' => 'background:#fef3c7; color:#92400e;',
                                    'in progress' => 'background:#fef3c7; color:#92400e;',
                                    'processing' => 'background:#fef3c7; color:#92400e;',
                                    'approved' => 'background:#dcfce7; color:#166534;',
                                    'dispute' => 'background:#fce7f3; color:#9d174d;',
                                    'refund_paid' => 'background:#dcfce7; color:#166534;',
                                    'refund_issued' => 'background:#dcfce7; color:#166534;',
                                    'refunded' => 'background:#dcfce7; color:#166534;',
                                    'closed' => 'background:#f1f5f9; color:#475569;',
                                    'completed' => 'background:#f1f5f9; color:#475569;',
                                    'rejected' => 'background:#fee2e2; color:#991b1b;',
                                    'cancelled' => 'background:#fee2e2; color:#991b1b;',
                                ];
                                $st = strtolower($order->reverse_status ?? '');
                                $style = '';
                                foreach ($statusColors as $key => $val) {
                                    if (str_contains($st, $key)) { $style = $val; break; }
                                }
                            @endphp
                            <span class="badge" style="{{ $style }}">{{ $order->reverse_status ?? '—' }}</span>
                        </td>
                        <td>{{ $order->reverse_type ?? '—' }}</td>
                        <td>
                            <span class="text-sm">{{ \Illuminate\Support\Str::limit($order->reason ?? '—', 50) }}</span>
                        </td>
                        <td>
                            @if($order->refund_amount !== null)
                                <strong style="color:#dc2626;">{{ ($order->currency ?: '₱') . number_format((float)$order->refund_amount, 2) }}</strong>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            <span class="text-xs text-secondary">{{ $order->updated_at ? $order->updated_at->format('M d, Y H:i') : '—' }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        <div style="padding:12px 0 0;">{{ $orders->onEachSide(1)->links('vendor.pagination.simple') }}</div>
    @endif
</div>

{{-- Per-page selector --}}
<form method="GET" action="{{ route('ext.lazada.orders.returns') }}" id="formPerPage" style="margin-top:12px;">
    <input type="hidden" name="reverse_order_id" value="{{ $filters['reverse_order_id'] ?? '' }}">
    <input type="hidden" name="trade_order_id" value="{{ $filters['trade_order_id'] ?? '' }}">
    <input type="hidden" name="tab" value="{{ $active_tab }}">
    <div class="d-flex items-center gap-8">
        <label class="text-xs text-secondary">Per page:</label>
        <select name="per_page" class="input" style="width:auto; height:32px; font-size:13px;" onchange="this.form.submit()">
            @foreach([10,20,50] as $pp)
                <option value="{{ $pp }}" {{ (int)$per_page === $pp ? 'selected' : '' }}>{{ $pp }}</option>
            @endforeach
        </select>
    </div>
</form>
</div>{{-- /.marketplace-lazada --}}
@endsection
