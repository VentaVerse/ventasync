@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Shopee / Return or Refund')

@section('content')
<div class="marketplace-shopee">
<div class="page-header">
    <h2>Shopee Return or Refund <span class="text-secondary text-sm">({{ $returns->total() }})</span></h2>
</div>

<div class="card mb-16">
    <form method="POST" action="{{ route('ext.shopee.orders.fetch_returns') }}" class="d-flex gap-12 flex-wrap items-center">
        @csrf
        <div style="align-self:flex-end;">
            <button type="submit" class="btn">Fetch Returns</button>
        </div>
        <div class="text-secondary text-xs" style="flex:1;">
            Fetch return/refund records from Shopee API.
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
    <a href="{{ route('ext.shopee.orders.index', ['tab' => 'ALL']) }}" class="tab">All</a>
    <a href="{{ route('ext.shopee.orders.index', ['tab' => 'UNPAID']) }}" class="tab">Unpaid</a>
    <a href="{{ route('ext.shopee.orders.index', ['tab' => 'PENDING']) }}" class="tab">To Ship</a>
    <a href="{{ route('ext.shopee.orders.index', ['tab' => 'SHIPPING']) }}" class="tab">Shipping</a>
    <a href="{{ route('ext.shopee.orders.index', ['tab' => 'DELIVERED_COMPLETED']) }}" class="tab">Delivered</a>
    <a href="{{ route('ext.shopee.orders.index', ['tab' => 'CANCELLED']) }}" class="tab">Cancelled</a>
    <a href="{{ route('ext.shopee.orders.index', ['tab' => 'FAILED_DELIVERY']) }}" class="tab">Failed Delivery</a>
    <a href="{{ route('ext.shopee.orders.returns') }}" class="tab active">Return/Refund/Cancel</a>
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
        <a href="{{ route('ext.shopee.orders.returns', $qs) }}" class="tab {{ $isActive ? 'active' : '' }}">
            <span>{{ $label }}</span>
            @if($count !== null && $count > 0)
                <span style="margin-left:6px; display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 6px; border-radius:999px; font-weight:700; font-size:12px; {{ $isActive ? 'background:#fff; color:#EE4D2D;' : 'background:#EE4D2D; color:#fff;' }}">{{ $count }}</span>
            @endif
        </a>
    @endforeach
</div>

<div class="card mb-12" style="padding:12px 16px;">
    <form method="GET" action="{{ route('ext.shopee.orders.returns') }}" class="d-flex gap-10 flex-wrap items-center">
        <input type="hidden" name="per_page" value="{{ (int)($per_page ?? 10) }}">
        <input type="hidden" name="tab" value="{{ $active_tab }}">
        <div>
            <label class="text-xs text-secondary">Return SN</label>
            <input type="text" name="return_sn" value="{{ $filters['return_sn'] ?? '' }}" placeholder="Return SN" class="input" style="min-width:200px;">
        </div>
        <div>
            <label class="text-xs text-secondary">Order SN</label>
            <input type="text" name="order_sn" value="{{ $filters['order_sn'] ?? '' }}" placeholder="Order SN" class="input" style="min-width:200px;">
        </div>
        <div style="align-self:flex-end;">
            <button type="submit" class="btn">Search</button>
        </div>
        <div style="align-self:flex-end;">
            <a href="{{ route('ext.shopee.orders.returns', ['per_page' => (int)($per_page ?? 10), 'tab' => $active_tab]) }}" class="btn secondary">Reset</a>
        </div>
    </form>
</div>

<div class="card">
    @if($returns->count() === 0)
        <div style="padding:40px 20px; text-align:center;">
            <div class="text-secondary">No return/refund records found.</div>
            @if(($filters['return_sn'] ?? '') !== '' || ($filters['order_sn'] ?? '') !== '')
                <div class="text-xs text-muted" style="margin-top:8px;">Try adjusting your search filters.</div>
            @else
                <div class="text-xs text-muted" style="margin-top:8px;">Click "Fetch Returns" to pull data from Shopee.</div>
            @endif
        </div>
    @else
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Return SN</th>
                    <th>Order SN</th>
                    <th>Status</th>
                    <th>Reason</th>
                    <th>Refund Amount</th>
                    <th>Created</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
                @foreach($returns as $ret)
                    <tr>
                        <td>
                            <span class="font-semibold">{{ $ret->return_sn }}</span>
                        </td>
                        <td>
                            @if($ret->order_sn)
                                <a href="{{ route('ext.shopee.orders.show', ['orderSn' => $ret->order_sn]) }}" style="color:var(--accent); text-decoration:none;">
                                    {{ $ret->order_sn }}
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @php
                                $statusColors = [
                                    'requested'    => 'background:#dbeafe; color:#1e40af;',
                                    'processing'   => 'background:#fef3c7; color:#92400e;',
                                    'accepted'     => 'background:#fef3c7; color:#92400e;',
                                    'judging'      => 'background:#fce7f3; color:#9d174d;',
                                    'seller_dispute' => 'background:#fce7f3; color:#9d174d;',
                                    'refund_paid'  => 'background:#dcfce7; color:#166534;',
                                    'seller_compensation' => 'background:#dcfce7; color:#166534;',
                                    'closed'       => 'background:#f1f5f9; color:#475569;',
                                    'cancelled'    => 'background:#fee2e2; color:#991b1b;',
                                ];
                                $st = strtolower($ret->status ?? '');
                                $style = '';
                                foreach ($statusColors as $key => $val) {
                                    if (str_contains($st, $key)) { $style = $val; break; }
                                }
                            @endphp
                            <span class="badge" style="{{ $style }}">{{ $ret->status ?? '—' }}</span>
                        </td>
                        <td>
                            <span class="text-sm">{{ \Illuminate\Support\Str::limit($ret->reason ?? ($ret->reason_text ?? '—'), 50) }}</span>
                        </td>
                        <td>
                            @if($ret->refund_amount !== null && (float)$ret->refund_amount > 0)
                                <strong style="color:#dc2626;">{{ ($ret->currency ?: '₱') . number_format((float)$ret->refund_amount, 2) }}</strong>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            <span class="text-xs text-secondary">{{ $ret->return_created_at ? \Carbon\Carbon::parse($ret->return_created_at)->format('M d, Y H:i') : '—' }}</span>
                        </td>
                        <td>
                            <span class="text-xs text-secondary">{{ $ret->return_updated_at ? \Carbon\Carbon::parse($ret->return_updated_at)->format('M d, Y H:i') : '—' }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        <div style="padding:12px 0 0;">{{ $returns->onEachSide(1)->links('vendor.pagination.simple') }}</div>
    @endif
</div>

{{-- Per-page selector --}}
<form method="GET" action="{{ route('ext.shopee.orders.returns') }}" id="formPerPage" style="margin-top:12px;">
    <input type="hidden" name="return_sn" value="{{ $filters['return_sn'] ?? '' }}">
    <input type="hidden" name="order_sn" value="{{ $filters['order_sn'] ?? '' }}">
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
</div>{{-- /.marketplace-shopee --}}
@endsection
