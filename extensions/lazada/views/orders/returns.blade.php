@extends('layouts.channel')
@section('breadcrumb', 'Return or Refund')

@section('content')
@php
    $canManageLazadaOrders = true;
@endphp
<div class="marketplace-lazada">
<div class="page-header">
    <h2>Lazada Return or Refund <span class="text-secondary text-sm">({{ $orders->total() }})</span></h2>
</div>

@if($canManageLazadaOrders)
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
@endif

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
    $erpMap = $erpMap ?? collect();
    $imgMap = $imgMap ?? collect();
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
                    <th style="min-width:300px;">Returned Products</th>
                    <th style="width:110px;">Qty Returned</th>
                    <th style="width:130px;">Refund</th>
                    <th style="width:150px;">Status</th>
                    <th style="width:150px;">Return Tracking</th>
                    <th style="width:130px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($orders as $order)
                    @php
                        $cur = $order->currency ?: \App\Support\Money::defaultSymbol();
                        $items = is_array($order->items) ? $order->items : [];
                        // Consolidate identical lines — Lazada emits one reverse line per unit.
                        $grouped = [];
                        foreach ($items as $__it) {
                            $key = (($__it['seller_sku_id'] ?? '') . '|' . ($__it['product']['product_sku'] ?? '')) ?: uniqid();
                            if (!isset($grouped[$key])) { $grouped[$key] = $__it; $grouped[$key]['_qty'] = 0; $grouped[$key]['_refund_sum'] = 0.0; }
                            $grouped[$key]['_qty']++;
                            $grouped[$key]['_refund_sum'] += (float) ($__it['refund_amount'] ?? 0);
                        }
                        $displayItems = array_values($grouped);
                        $lineCount = max(1, count($displayItems));
                        $erpId = $erpMap[$order->trade_order_id] ?? null;

                        // Lazada item-line amounts are in cents (x100).
                        $buyerId = $items[0]['buyer']['buyer_id'] ?? null;
                        $firstTracking = $items[0]['tracking_number'] ?? null;
                        $ofc = $items[0]['ofc_status'] ?? null;

                        $st = strtolower($order->reverse_status ?? '');
                        $badge = 'ret-badge-slate';
                        foreach ([
                            'initiated'=>'ret-badge-blue','in_progress'=>'ret-badge-amber','processing'=>'ret-badge-amber',
                            'dispute'=>'ret-badge-pink','approved'=>'ret-badge-green','refund'=>'ret-badge-green',
                            'success'=>'ret-badge-green','reject'=>'ret-badge-red','cancel'=>'ret-badge-red',
                        ] as $k=>$cls) { if (str_contains($st,$k)) { $badge=$cls; break; } }

                        $orderShowUrl = $order->trade_order_id
                            ? route('ext.lazada.orders.show', ['orderId' => $order->trade_order_id])
                            : null;
                    @endphp

                    {{-- Group header: ERP # / buyer / original order / reverse order --}}
                    <tr class="order-header-row">
                        <td colspan="6" style="padding:10px 12px 6px 12px;">
                            <div class="d-flex justify-between items-start gap-12">
                                <div class="text-xs text-secondary" style="line-height:1.35;">
                                    @if($erpId)
                                        <div style="font-weight:700; margin-bottom:2px;"><a href="{{ url('/sales/orders/'.$erpId) }}" style="color:var(--text-primary); text-decoration:none;">{{ $erpId }}</a></div>
                                    @endif
                                    @if($buyerId)<span>Buyer:</span> <span class="font-semibold" style="color:var(--text-primary);">#{{ $buyerId }}</span>@endif
                                    <span style="margin-left:8px;">Order:</span>
                                    @if($orderShowUrl)
                                        <a href="{{ $orderShowUrl }}" class="font-semibold" style="color:var(--accent);">{{ $order->trade_order_id }}</a>
                                    @else
                                        <span class="font-semibold" style="color:var(--text-primary);">—</span>
                                    @endif
                                    <span style="margin-left:8px;">Reverse Order:</span> <span class="font-semibold" style="color:var(--text-primary);">{{ $order->reverse_order_id }}</span>
                                    @if($order->reverse_type)<span style="margin-left:8px;">Type: {{ $order->reverse_type }}</span>@endif
                                </div>
                                <div class="text-xs text-secondary text-right" style="line-height:1.35;">
                                    @if($order->updated_at)<span>Updated:</span> <span class="font-semibold" style="color:var(--text-primary);">{{ $order->updated_at->format('M d, Y H:i') }}</span>@endif
                                </div>
                            </div>
                        </td>
                    </tr>

                    @foreach(($displayItems ?: [[]]) as $idx => $it)
                        @php
                            $pSku = (string)($it['product']['product_sku'] ?? '');
                            $sSku = (string)($it['seller_sku_id'] ?? '');
                            $qty = (int)($it['_qty'] ?? 1);
                            $itRefund = isset($it['_refund_sum']) ? ((float)$it['_refund_sum'] / 100) : null;
                            $itPrice = isset($it['item_unit_price']) ? ((float)$it['item_unit_price'] / 100) : null;
                            $img = (string)($imgMap[$sSku] ?? ($imgMap[$pSku] ?? ''));
                        @endphp
                        <tr>
                            <td style="vertical-align:top;">
                                <div class="d-flex gap-10">
                                    <div class="order-img-wrap">
                                        @if($img)<img class="order-img" src="{{ $img }}" alt="">@else<span class="text-xs text-muted">No image</span>@endif
                                    </div>
                                    <div style="min-width:0;">
                                        <div class="font-semibold" style="word-break:break-word;">{{ $pSku !== '' ? $pSku : ($sSku !== '' ? $sSku : '—') }}</div>
                                        @if($sSku !== '')
                                            <div class="text-xs" style="margin-top:3px;"><span class="text-secondary">Seller SKU:</span> <strong>{{ $sSku }}</strong></div>
                                        @endif
                                        @if(!empty($it['reason_text']))
                                            <div class="text-xs text-muted" style="margin-top:2px;">{{ \Illuminate\Support\Str::limit($it['reason_text'], 48) }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td style="vertical-align:top;">
                                <div class="font-bold">x{{ $qty }}</div>
                            </td>
                            <td style="vertical-align:top;">
                                @if($itRefund !== null)
                                    <strong style="color:var(--danger,#dc2626);">{{ $cur . number_format($itRefund, 2) }}</strong>
                                    @if($itPrice !== null)<div class="text-xs text-muted">@ {{ $cur . number_format($itPrice, 2) }}</div>@endif
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>

                            @if($idx === 0)
                                <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                                    <span class="ret-badge {{ $badge }}">{{ $order->reverse_status ?? '—' }}</span>
                                    <div class="text-xs text-muted" style="margin-top:6px;">{{ \Illuminate\Support\Str::limit($order->reason ?? '', 40) }}</div>
                                </td>
                                <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                                    @if($firstTracking)
                                        <div class="font-semibold text-sm" style="word-break:break-all;">{{ $firstTracking }}</div>
                                    @else
                                        <span class="text-muted text-xs">—</span>
                                    @endif
                                    @if($ofc)<div class="text-xs text-muted" style="margin-top:4px;">{{ str_replace('_',' ',$ofc) }}</div>@endif
                                </td>
                                <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                                    @if($orderShowUrl)
                                        <a href="{{ $orderShowUrl }}" class="btn small">View Order</a>
                                    @endif
                                    <div class="text-xs text-secondary" style="margin-top:6px;">Total: <strong>{{ $cur . number_format((float)$order->refund_amount, 2) }}</strong></div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
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
