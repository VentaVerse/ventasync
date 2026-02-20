@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Shopee / Orders')

@section('content')
<div class="page-header">
    <h2>Shopee Orders <span class="text-secondary text-sm">({{ $orders->total() }})</span></h2>
</div>

@if($last_result)
    <div class="alert {{ $last_result['ok'] ? 'success' : 'danger' }}">
        <strong>{{ $last_result['ok'] ? 'OK' : 'Error' }}</strong>
        @if(!empty($last_result['message']))
            <div style="margin-top:6px;">{{ $last_result['message'] }}</div>
        @endif
    </div>
@endif

<div class="card mb-16">
    <form method="POST" action="{{ route('shopee.orders.fetch') }}" id="formFetchShopeeOrders" class="d-flex gap-12 flex-wrap items-center">
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
            <label class="text-xs text-secondary" style="display:flex; align-items:center; gap:4px; cursor:pointer;">
                <input type="checkbox" name="no_stock" value="1"> Skip stock adjust
            </label>
        </div>
        <div class="d-flex gap-8">
            <button type="submit" class="btn" id="btnFetchOrders">Fetch Orders</button>
            <button type="submit" class="btn secondary" id="btnUpdateOrders" formaction="{{ route('shopee.orders.update_statuses') }}">Update Orders</button>
        </div>
        <div class="text-secondary text-xs" style="flex:1;">
            Leave <strong>From/To</strong> empty for <strong>automatic sync</strong> (maximum <strong>14 days</strong> lookback).
        </div>
        <div style="margin-left:auto;">
            <button type="button" class="btn danger" id="btnResetShopeeOrders">Reset Orders</button>
        </div>
    </form>
</div>

@php
    $tabs = $tabs ?? [];
    $active_tab = $active_tab ?? 'ALL';
    $tab_counts = $tab_counts ?? [];
    $pending_sub_counts = $pending_sub_counts ?? [];
@endphp

<div class="tabs mb-12">
    @foreach($tabs as $key => $label)
        @php
            $isActive = strtoupper((string)$active_tab) === strtoupper((string)$key);
            $count = $tab_counts[$key] ?? null;
            $qs = array_merge(request()->query(), ['tab' => $key]);
            $isPending = ($key === 'PENDING');
        @endphp
        <a href="{{ route('shopee.orders.index', $qs) }}" class="tab {{ $isActive ? 'active' : '' }}">
            <span>{{ $label }}</span>
            @if($isPending && $count !== null && $count > 0)
                <span style="margin-left:6px; color:#dc2626; font-weight:700; font-size:15px;">{{ $count }}</span>
            @endif
        </a>
    @endforeach
</div>

{{-- Fetch Returns card for RETURN tab --}}
@if(strtoupper((string)($active_tab ?? 'ALL')) === 'RETURN')
    <div class="card mb-12" style="padding:12px 16px;">
        <div class="d-flex items-center justify-between gap-10">
            <div>
                <div class="font-semibold">Return / Refund Tracking</div>
                <div class="text-xs text-secondary" style="margin-top:4px;">Fetch return details from Shopee API to see reasons, refund amounts, and negotiation history.</div>
            </div>
            <form method="POST" action="{{ route('shopee.orders.fetch_returns') }}" id="formFetchReturns">
                @csrf
                <button type="submit" class="btn" id="btnFetchReturns">Fetch Returns</button>
            </form>
        </div>
    </div>
@endif

{{-- Shopee workflow: PENDING has 2 sub steps (To Pack / To Handover) --}}
@if(strtoupper((string)($active_tab ?? 'ALL')) === 'PENDING')
    @php
        $ps = $pending_subtab ?? request()->query('pending_sub', 'to_pack');
        $pendingNav = [
            'to_pack' => 'To Pack',
            'to_handover' => 'To Handover',
        ];
    @endphp
    <div class="card mb-12" style="padding:12px 16px;">
        <div class="d-flex items-center justify-between gap-10" style="margin-bottom:8px;">
            <div class="font-semibold">Pending workflow</div>
            <div class="text-xs text-secondary">Choose the step you are working on</div>
        </div>

        <div class="d-flex gap-10 flex-wrap">
            @foreach($pendingNav as $k => $lbl)
                @php
                    $isActive = ($ps === $k) || ($ps === '' && $k === 'to_pack');
                    $qs = array_merge(request()->query(), ['tab' => 'PENDING', 'pending_sub' => $k]);
                    $count = $pending_sub_counts[$k] ?? null;
                @endphp
                <a href="{{ route('shopee.orders.index', $qs) }}" class="tab {{ $isActive ? 'active' : '' }}">
                    <span>{{ $lbl }}</span>
                    @if($count !== null && $count > 0)
                        <span style="margin-left:6px; color:#dc2626; font-weight:700; font-size:15px;">{{ $count }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    </div>
@endif

<div class="card mb-12" style="padding:12px 16px;">
    <form method="GET" action="{{ route('shopee.orders.index') }}" class="d-flex gap-10 flex-wrap items-center">
        <input type="hidden" name="tab" value="{{ $active_tab ?? request()->query('tab', 'ALL') }}">
        <input type="hidden" name="per_page" value="{{ (int)($per_page ?? request()->query('per_page', 10)) }}">

        <div>
            <label class="text-xs text-secondary">Order Number</label>
            <input type="text" name="order_number" value="{{ old('order_number', $filters['order_number'] ?? '') }}" placeholder="Order SN" class="input" style="min-width:220px;">
        </div>

        <div>
            <label class="text-xs text-secondary">Buyer Name</label>
            <input type="text" name="buyer_name" value="{{ old('buyer_name', $filters['buyer_name'] ?? '') }}" placeholder="Buyer" class="input" style="min-width:220px;">
        </div>

        <div class="d-flex gap-8">
            <button type="submit" class="btn secondary">Search</button>
            <a href="{{ route('shopee.orders.index', ['tab' => ($active_tab ?? 'ALL'), 'per_page' => (int)($per_page ?? 10)]) }}" class="btn secondary">Reset</a>
        </div>
    </form>
</div>

<div class="card mb-12" style="padding:12px 16px;">
    <form method="GET" action="{{ route('shopee.orders.index') }}" class="d-flex gap-10 items-center">
        <input type="hidden" name="tab" value="{{ $active_tab ?? request()->query('tab', 'ALL') }}">
        <input type="hidden" name="order_number" value="{{ $filters['order_number'] ?? '' }}">
        <input type="hidden" name="buyer_name" value="{{ $filters['buyer_name'] ?? '' }}">
        <div>
            <label class="text-xs text-secondary">Orders per page</label>
            <select name="per_page" onchange="this.form.submit()" class="input" style="width:auto;">
                <option value="10" {{ (int)($per_page ?? 10)===10 ? 'selected' : '' }}>10</option>
                <option value="20" {{ (int)($per_page ?? 10)===20 ? 'selected' : '' }}>20</option>
                <option value="50" {{ (int)($per_page ?? 10)===50 ? 'selected' : '' }}>50</option>
            </select>
        </div>
        <noscript><button type="submit" class="btn secondary">Apply</button></noscript>
    </form>
</div>

<div class="card">
    <div class="font-bold" style="margin-bottom:12px;">
        Orders ({{ method_exists($orders,'total') ? $orders->total() : $orders->count() }})
    </div>

    @if($orders->count() === 0)
        <div class="text-secondary" style="padding:12px 0;">No orders loaded yet.</div>
    @else
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th style="min-width:360px;">Products</th>
                    <th style="width:110px;">Quantity</th>
                    <th style="width:140px;">Product Price</th>
                    <th>Amount</th>
                    <th>Shipping</th>
                    <th>Status</th>
                    <th style="width:280px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($orders as $o)
                    @php
                        $raw = $o->raw ?? [];

                        $orderSn = $o->order_sn ?? ($raw['order_sn'] ?? '');
                        $status = $o->status ?? ($raw['order_status'] ?? '');
                        $statusStr = is_string($status) ? $status : '';
                        $statusNorm = strtoupper(trim($statusStr));

                        $isUnpaid = ($statusNorm === 'UNPAID');
                        $isCancelled = in_array($statusNorm, ['CANCELLED', 'IN_CANCEL'], true);
                        $isToPack = ($statusNorm === 'READY_TO_SHIP');
                        $isToHandover = ($statusNorm === 'PROCESSED');
                        $isShipped = ($statusNorm === 'SHIPPED');

                        $created = $o->order_created_at ? $o->order_created_at->format('Y-m-d H:i') : null;
                        $buyer = (string)($raw['buyer_username'] ?? ($raw['buyer_user_name'] ?? ''));

                        $items = [];
                        if (method_exists($o, 'products') && $o->relationLoaded('products')) {
                            $items = $o->products ? $o->products->toArray() : [];
                        }
                        if (empty($items)) {
                            $items = $raw['item_list'] ?? [];
                            if (!is_array($items)) $items = [];
                        }

                        // Group identical products
                        $grouped = [];
                        foreach ($items as $row) {
                            $rowRaw = $row['raw'] ?? $row;
                            $gName = $row['name'] ?? $rowRaw['item_name'] ?? '';
                            $gSku = $row['sku'] ?? $rowRaw['item_sku'] ?? $rowRaw['model_sku'] ?? '';
                            $gVariation = $row['variation'] ?? $rowRaw['model_name'] ?? '';
                            $gQty = max(1, (int)($row['quantity'] ?? $rowRaw['model_quantity_purchased'] ?? $rowRaw['quantity'] ?? 1));
                            $gImg = $row['image'] ?? $rowRaw['image_info']['image_url'] ?? '';

                            $key = mb_strtolower(trim((string)$gSku)) . '|' . mb_strtolower(trim((string)$gName)) . '|' . mb_strtolower(trim((string)$gVariation));
                            if (!isset($grouped[$key])) {
                                $grouped[$key] = [
                                    'raw' => $rowRaw,
                                    'name' => $gName,
                                    'sku' => $gSku,
                                    'variation' => $gVariation,
                                    'quantity' => $gQty,
                                    'image' => $gImg,
                                ];
                            } else {
                                $grouped[$key]['quantity'] += $gQty;
                            }
                        }
                        $groupedItems = array_values($grouped);
                        $itemCount = count($groupedItems);

                        $totalAmount = $raw['total_amount'] ?? $raw['escrow_amount'] ?? null;
                        $currency = $raw['currency'] ?? '₱';
                        $paymentMethod = $raw['payment_method'] ?? null;
                        $courier = $raw['shipping_carrier'] ?? $raw['checkout_shipping_carrier'] ?? null;
                        if (is_array($courier)) $courier = implode(', ', $courier);
                        $trackingNo = $raw['tracking_no'] ?? $raw['tracking_number'] ?? null;
                    @endphp

                    @if($orderSn)
                        <tr>
                            <td colspan="7" style="padding:10px 12px 6px 12px;">
                                <div class="d-flex justify-between items-start gap-12">
                                    <div class="text-xs text-secondary" style="line-height:1.35;">
                                        <span>Buyer:</span> <span class="font-semibold" style="color:var(--text-primary);">{{ $buyer !== '' ? $buyer : '—' }}</span>
                                        @if($itemCount > 0)
                                            <span style="margin-left:8px;">({{ $itemCount }} {{ $itemCount === 1 ? 'item' : 'items' }})</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-secondary text-right" style="line-height:1.35;">
                                        <span>Order SN:</span>
                                        <a href="{{ route('shopee.orders.show', ['orderSn' => $orderSn]) }}" target="_blank" rel="noopener" class="font-semibold" style="color:var(--text-primary); text-decoration:underline; text-underline-offset:2px;">
                                            {{ $orderSn }}
                                        </a>
                                        @if($created)
                                            <span style="margin-left:8px;">Create Time:</span> <span class="font-semibold" style="color:var(--text-primary);">{{ $created }}</span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif

                    @php
                        $displayItems = !empty($groupedItems) ? $groupedItems : $items;
                        if (!is_array($displayItems)) { $displayItems = []; }
                        if (empty($displayItems)) { $displayItems = [ [] ]; }
                        $lineCount = count($displayItems);
                    @endphp

                    @foreach($displayItems as $idx => $it)
                    <tr>
                        <td style="vertical-align:top;">
                            @php
                                $itRaw = $it['raw'] ?? $it;
                                $name = (string)($it['name'] ?? $itRaw['item_name'] ?? '');
                                $sku = (string)($it['sku'] ?? $itRaw['item_sku'] ?? $itRaw['model_sku'] ?? '');
                                $variation = (string)($it['variation'] ?? $itRaw['model_name'] ?? '');
                                $img = (string)($it['image'] ?? $itRaw['image_info']['image_url'] ?? '');
                            @endphp

                            <div class="d-flex gap-10">
                                <div style="width:44px; height:44px; border-radius:8px; overflow:hidden; background:var(--surface-alt); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                    @if($img)
                                        <img src="{{ $img }}" alt="" style="width:44px; height:44px; object-fit:cover;">
                                    @else
                                        <span class="text-xs text-muted">No image</span>
                                    @endif
                                </div>
                                <div style="min-width:0;">
                                    <div class="font-semibold" style="word-break:break-word;">{{ $name !== '' ? $name : '—' }}</div>
                                    <div class="text-xs" style="margin-top:3px;">
                                        <span class="text-secondary">SKU:</span> <strong>{{ $sku !== '' ? $sku : '—' }}</strong>
                                    </div>
                                    @if($variation !== '')
                                        <div class="text-xs text-secondary" style="margin-top:3px;">{{ $variation }}</div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td style="vertical-align:top;">
                            @php
                                $qty = max(1, (int)($it['quantity'] ?? 1));
                            @endphp
                            <div class="font-bold">x{{ $qty }}</div>
                        </td>
                        <td style="vertical-align:top;">
                            @php
                                $itRaw = $it['raw'] ?? $it;
                                $unitPrice = $itRaw['model_discounted_price'] ?? $itRaw['model_original_price'] ?? $it['price'] ?? null;
                            @endphp
                            <div class="font-bold">
                                @if($unitPrice !== null && $unitPrice !== '')
                                    {{ is_numeric($unitPrice) ? $currency . number_format((float)$unitPrice, 2) : $unitPrice }}
                                @else
                                    —
                                @endif
                            </div>
                        </td>

                        @if($idx === 0)
                        <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                            <div class="font-bold">
                                @if($totalAmount !== null && $totalAmount !== '')
                                    {{ is_numeric($totalAmount) ? $currency . number_format((float)$totalAmount, 2) : $totalAmount }}
                                @else
                                    —
                                @endif
                            </div>
                            @if($paymentMethod)
                                <div style="margin-top:6px;">
                                    <span class="badge badge-gray">{{ $paymentMethod }}</span>
                                </div>
                            @endif
                        </td>
                        <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                            <div class="font-semibold">{{ $courier ?: '—' }}</div>
                            @if($trackingNo)
                                <div class="text-xs text-secondary" style="margin-top:4px;">Tracking: <span style="color:var(--text-primary);">{{ $trackingNo }}</span></div>
                            @endif
                        </td>
                        <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                            <div class="font-bold">{{ $statusStr !== '' ? $statusStr : '—' }}</div>
                            @if(strtoupper((string)($active_tab ?? 'ALL')) === 'RETURN' && !empty($returnsByOrder[$orderSn]))
                                @foreach($returnsByOrder[$orderSn] as $ret)
                                    <div style="margin-top:8px; padding:6px 8px; background:var(--surface-alt); border-radius:6px; font-size:12px;">
                                        <div><span class="text-secondary">Return:</span> <strong>{{ $ret->status }}</strong></div>
                                        @if($ret->reason)
                                            <div style="margin-top:2px;"><span class="text-secondary">Reason:</span> {{ $ret->reason }}</div>
                                        @endif
                                        @if($ret->refund_amount > 0)
                                            <div style="margin-top:2px;"><span class="text-secondary">Refund:</span> <strong style="color:#dc2626;">{{ ($ret->currency ?: '₱') . number_format((float)$ret->refund_amount, 2) }}</strong></div>
                                        @endif
                                    </div>
                                @endforeach
                            @endif
                        </td>
                        <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                            @if(!$orderSn)
                                <span class="text-muted">N/A</span>
                            @elseif($isUnpaid || $isCancelled)
                                <span class="text-muted">—</span>
                            @else
                                <div class="d-flex gap-8 flex-wrap items-center">
                                    @if($isToPack)
                                        <button type="button" class="btn small btnArrangeShipment" data-order-sn="{{ $orderSn }}">Arrange Shipment</button>
                                    @endif

                                    @if($isToHandover || !empty($savedAwbs[$orderSn]))
                                        <a href="{{ route('shopee.orders.awb', ['orderSn' => $orderSn]) }}" target="_blank" class="btn small">Print AWB</a>
                                    @endif

                                    @if($isToHandover || $isShipped)
                                        <button type="button"
                                                class="btnShopeeTracking btn small secondary"
                                                data-url="{{ route('shopee.orders.tracking', ['orderSn' => $orderSn]) }}">
                                            Tracking #
                                        </button>
                                    @endif
                                </div>
                            @endif
                        </td>
                        @endif
                    </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
        </div>
        <div style="padding:12px 0 0;">{{ $orders->links() }}</div>
    @endif
</div>

<form method="POST" action="{{ route('shopee.orders.reset') }}" id="formResetShopeeOrders" class="hidden">
    @csrf
    <input type="hidden" name="confirm" id="resetConfirmInput" value="">
    <input type="hidden" name="password" id="resetPasswordInput" value="">
</form>

{{-- Ship method modal --}}
<div id="spShipModal" class="modal-backdrop">
    <div class="modal" style="max-width:400px;">
        <div class="modal-header">
            <h3>Arrange Shipment</h3>
            <button type="button" id="btnCloseShip" class="modal-close">&times;</button>
        </div>
        <div style="margin-top:12px;">
            <div class="text-secondary text-sm">Order: <strong id="spShipOrderSn"></strong></div>
            <div class="text-sm" style="margin-top:10px;">How will this order be shipped?</div>
        </div>
        <form method="POST" id="formShipOrder">
            @csrf
            <input type="hidden" name="shipping_type" id="shipTypeInput" value="">
            <div class="d-flex gap-10" style="margin-top:16px;">
                <button type="button" class="btn btnShipChoice" data-type="pickup" style="flex:1;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                    Pickup
                </button>
                <button type="button" class="btn secondary btnShipChoice" data-type="dropoff" style="flex:1;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    Drop Off
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Loading overlay --}}
<div id="spLoadingOverlay" class="modal-backdrop">
    <div class="modal" style="max-width:420px;">
        <div class="d-flex items-center gap-12">
            <div aria-hidden="true" style="width:18px; height:18px; border:3px solid #cbd5e1; border-top-color:var(--accent); border-radius:50%; animation:spSpin 0.8s linear infinite;"></div>
            <div>
                <div id="spLoadingTitle" class="font-bold">Fetching orders…</div>
                <div class="text-xs text-secondary" style="margin-top:2px;">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

{{-- Tracking modal --}}
<div id="spTrackingModal" class="modal-backdrop">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <h3>Tracking Number</h3>
            <button type="button" id="btnCloseTracking" class="modal-close">&times;</button>
        </div>
        <div id="spTrackingBody" class="mt-12">
            <div class="text-secondary">Loading…</div>
        </div>
        <div class="d-flex gap-10 justify-end mt-16">
            <button type="button" id="btnTrackingClose2" class="btn secondary">Close</button>
        </div>
    </div>
</div>

<style>
@keyframes spSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    @if(session('shopee_awb_open'))
        window.open("{{ route('shopee.orders.awb', ['orderSn' => session('shopee_awb_open')]) }}", '_blank');
    @endif

    var btn = document.getElementById('btnResetShopeeOrders');
    var form = document.getElementById('formResetShopeeOrders');
    var overlay = document.getElementById('spLoadingOverlay');
    var fetchForm = document.getElementById('formFetchShopeeOrders');

    if (btn && form) {
        btn.addEventListener('click', function () {
            var pw = prompt('Enter your account password to confirm resetting all Shopee orders:');
            if (pw && pw.trim() !== '') {
                document.getElementById('resetConfirmInput').value = 'RESET';
                document.getElementById('resetPasswordInput').value = pw;
                if (overlay) overlay.style.display = 'flex';
                document.getElementById('spLoadingTitle').textContent = 'Resetting orders…';
                form.submit();
            }
        });
    }

    if (fetchForm && overlay) {
        fetchForm.addEventListener('submit', function () {
            setTimeout(function () {
                overlay.style.display = 'flex';
                document.getElementById('spLoadingTitle').textContent = 'Processing…';
            }, 50);
        });
    }

    var fetchReturnsForm = document.getElementById('formFetchReturns');
    if (fetchReturnsForm && overlay) {
        fetchReturnsForm.addEventListener('submit', function () {
            setTimeout(function () {
                overlay.style.display = 'flex';
                document.getElementById('spLoadingTitle').textContent = 'Fetching returns…';
            }, 50);
        });
    }

    // Ship method modal
    var shipModal = document.getElementById('spShipModal');
    var shipOrderSnEl = document.getElementById('spShipOrderSn');
    var shipForm = document.getElementById('formShipOrder');
    var shipTypeInput = document.getElementById('shipTypeInput');

    document.querySelectorAll('.btnArrangeShipment').forEach(function (el) {
        el.addEventListener('click', function () {
            var orderSn = el.getAttribute('data-order-sn');
            shipOrderSnEl.textContent = orderSn;
            shipForm.action = '/shopee/orders/' + encodeURIComponent(orderSn) + '/ship';
            shipModal.style.display = 'flex';
        });
    });

    document.querySelectorAll('.btnShipChoice').forEach(function (el) {
        el.addEventListener('click', function () {
            shipTypeInput.value = el.getAttribute('data-type');
            if (overlay) {
                overlay.style.display = 'flex';
                document.getElementById('spLoadingTitle').textContent = 'Arranging shipment…';
            }
            shipModal.style.display = 'none';
            shipForm.submit();
        });
    });

    var closeShip = function () { if (shipModal) shipModal.style.display = 'none'; };
    var btnCloseShip = document.getElementById('btnCloseShip');
    if (btnCloseShip) btnCloseShip.addEventListener('click', closeShip);
    if (shipModal) shipModal.addEventListener('click', function (e) { if (e.target === shipModal) closeShip(); });

    // Tracking modal
    var trackingModal = document.getElementById('spTrackingModal');
    var trackingBody = document.getElementById('spTrackingBody');

    document.querySelectorAll('.btnShopeeTracking').forEach(function (el) {
        el.addEventListener('click', function () {
            var url = el.getAttribute('data-url');
            if (!url) return;
            trackingBody.innerHTML = '<div class="text-secondary">Loading…</div>';
            trackingModal.style.display = 'flex';

            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.tracking_number) {
                        trackingBody.innerHTML = '<div class="font-bold" style="font-size:18px;">' + data.tracking_number + '</div>';
                    } else {
                        trackingBody.innerHTML = '<pre class="pre">' + JSON.stringify(data, null, 2) + '</pre>';
                    }
                })
                .catch(function (err) {
                    trackingBody.innerHTML = '<div class="text-danger">Error: ' + err.message + '</div>';
                });
        });
    });

    var closeTracking = function () { trackingModal.style.display = 'none'; };
    var btnCloseTracking = document.getElementById('btnCloseTracking');
    var btnTrackingClose2 = document.getElementById('btnTrackingClose2');
    if (btnCloseTracking) btnCloseTracking.addEventListener('click', closeTracking);
    if (btnTrackingClose2) btnTrackingClose2.addEventListener('click', closeTracking);
    if (trackingModal) trackingModal.addEventListener('click', function (e) { if (e.target === trackingModal) closeTracking(); });
});
</script>
@endsection
