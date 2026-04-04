@extends('layouts.app')
@section('title', 'TikTok Order Detail')
@section('breadcrumb', 'Marketplace / TikTok Shop / Orders / Detail')

@section('content')
@php
    $raw = is_array($order->raw ?? null) ? $order->raw : [];
    $status = $order->status ?? '';
    $statusLabel = str_replace('_', ' ', $status);
    $orderId = $order->order_id ?? '';

    $created = $order->order_created_at ? $order->order_created_at->format('Y-m-d H:i') : null;
    $updated = $order->order_updated_at ? $order->order_updated_at->format('Y-m-d H:i') : null;

    $buyer = (string)($raw['buyer_name'] ?? $raw['recipient_address']['name'] ?? $order->buyer_name ?? '');
    $phone = (string)($raw['recipient_address']['phone_number'] ?? $raw['recipient_address']['phone'] ?? '');
    $receiverName = (string)($raw['recipient_address']['name'] ?? $raw['recipient_address']['full_name'] ?? $buyer);
    $address = (string)($raw['recipient_address']['full_address'] ?? '');
    $district = (string)($raw['recipient_address']['district_info'] ?? [])['district_name'] ?? '';
    $city = (string)($raw['recipient_address']['city'] ?? '');
    $state = (string)($raw['recipient_address']['state'] ?? '');
    $zipcode = (string)($raw['recipient_address']['zipcode'] ?? $raw['recipient_address']['postal_code'] ?? '');
    $region = (string)($raw['recipient_address']['region_code'] ?? $raw['recipient_address']['region'] ?? '');

    $shipping = (string)($raw['shipping_provider'] ?? $raw['shipping_provider_name'] ?? '');
    $trackingNo = (string)($raw['tracking_number'] ?? '');
    if (!$shipping && !empty($raw['line_items'])) {
        $firstLi = $raw['line_items'][0] ?? [];
        $shipping = $firstLi['shipping_provider_name'] ?? '';
        $trackingNo = $firstLi['tracking_number'] ?? $trackingNo;
    }

    $payment = $raw['payment'] ?? [];
    $totalAmount = $payment['total_amount'] ?? $payment['product_total_amount'] ?? null;
    $shippingFee = $payment['shipping_fee'] ?? null;
    $sellerDiscount = $payment['seller_discount'] ?? $payment['seller_discount_total'] ?? null;
    $platformDiscount = $payment['platform_discount'] ?? $payment['platform_discount_total'] ?? null;
    $currency = $payment['currency'] ?? '₱';

    $items = $order->products ?? collect();

    $badgeClass = match($status) {
        'COMPLETED', 'DELIVERED' => 'badge-green',
        'CANCELLED' => 'badge-red',
        'UNPAID' => 'badge-yellow',
        'IN_TRANSIT' => 'badge-blue',
        default => '',
    };
@endphp

<div class="page-header">
    <div>
        <div class="d-flex gap-10 items-center flex-wrap">
            <h2 style="margin:0;">TikTok Order</h2>
            <span class="text-secondary text-xs">Order #</span>
            <span class="font-bold">{{ $orderId }}</span>
            @if($status !== '')
                <span class="badge {{ $badgeClass }}" style="margin-left:6px;">{{ $statusLabel }}</span>
            @endif
        </div>
        <div class="meta-bar" style="margin-top:8px; margin-bottom:0; padding:8px 0; background:transparent; border:none;">
            @if($created) <div class="meta-item"><span class="meta-label">Create Time:</span> {{ $created }}</div> @endif
            @if($updated) <div class="meta-item"><span class="meta-label">Update Time:</span> {{ $updated }}</div> @endif
        </div>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('ext.tiktok.orders.index') }}" class="btn secondary">← Back to Orders</a>
        <a href="{{ route('ext.tiktok.orders.show', ['id' => $order->id, 'refresh' => 1]) }}" class="btn secondary">Refresh from TikTok</a>
    </div>
</div>

@if($api_error ?? false)
    <div class="alert warning">
        <strong>Note</strong>
        <div style="margin-top:6px;">Could not refresh from TikTok API. Showing cached data.</div>
    </div>
@endif

<div class="detail-grid">
    <div class="detail-section">
        <h4>Customer & Shipping</h4>
        <div class="detail-body">
            <div><span class="text-secondary">Buyer:</span> <strong>{{ $buyer !== '' ? $buyer : '—' }}</strong></div>
            <div style="margin-top:8px;"><span class="text-secondary">Receiver:</span> <strong>{{ $receiverName !== '' ? $receiverName : '—' }}</strong></div>
            <div><span class="text-secondary">Phone:</span> <strong>{{ $phone !== '' ? $phone : '—' }}</strong></div>
            <div style="margin-top:10px;">
                <div class="text-secondary text-xs font-bold">Address</div>
                <div style="margin-top:4px;">
                    {{ $address !== '' ? $address : '—' }}
                    @if($city !== '' || $state !== '' || $zipcode !== '')
                        <br>{{ trim($city . ' ' . $state) }} {{ $zipcode }} {{ $region }}
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="detail-section">
        <h4>Logistics</h4>
        <div class="detail-body">
            <div><span class="text-secondary">Courier:</span> <strong>{{ $shipping !== '' ? $shipping : '—' }}</strong></div>
            <div><span class="text-secondary">Tracking #:</span> <strong>{{ $trackingNo !== '' ? $trackingNo : '—' }}</strong></div>
            @if(in_array($order->status, ['AWAITING_COLLECTION', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED']))
            <div class="d-flex gap-10 flex-wrap mt-12">
                <a href="{{ route('ext.tiktok.orders.awb', $order->id) }}" target="_blank" class="btn" style="background:#1e1e1e; border-color:#1e1e1e; color:#fff;">AWB PDF</a>
                <button type="button" class="btn" style="background:#1e1e1e; border-color:#1e1e1e; color:#fff;" id="btnShowTracking" data-url="{{ route('ext.tiktok.orders.tracking', $order->id) }}">Tracking</button>
            </div>
            @endif
        </div>
    </div>
</div>

<div class="card">
    <h3 class="section-title mt-0">Items</h3>
    @php
        // Consolidate duplicate line items by SKU + name + variation
        $consolidated = [];
        foreach ($items as $it) {
            $cName = (string)($it->name ?? '');
            $cSku = (string)($it->sku ?? '');
            $cVariation = (string)($it->variation ?? '');
            $cVarNorm = mb_strtolower(trim($cVariation));
            if (in_array($cVarNorm, ['', 'blank', 'null', 'n/a', 'na', 'none', '-', '--', 'default'], true)) {
                $cVariation = '';
            }
            $cQty = (int)($it->quantity ?? 1);
            if ($cQty < 1) $cQty = 1;
            $key = mb_strtolower(trim($cSku)) . '|' . mb_strtolower(trim($cName)) . '|' . mb_strtolower(trim($cVariation));
            if (!isset($consolidated[$key])) {
                $consolidated[$key] = [
                    'image' => (string)($it->image ?? ''),
                    'name' => $cName,
                    'sku' => $cSku,
                    'variation' => $cVariation,
                    'quantity' => $cQty,
                    'item_price' => (float)($it->item_price ?? 0),
                    'sale_price' => (float)($it->sale_price ?? 0),
                ];
            } else {
                $consolidated[$key]['quantity'] += $cQty;
                if (empty($consolidated[$key]['image']) && !empty($it->image)) {
                    $consolidated[$key]['image'] = (string)$it->image;
                }
            }
        }
    @endphp
    @if(empty($consolidated))
        <div class="text-secondary">No items synced yet.</div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:90px;">Image</th>
                        <th>Product</th>
                        <th style="width:130px;">SKU</th>
                        <th style="width:70px;">Qty</th>
                        <th style="width:100px;">Unit Price</th>
                        <th style="width:100px;">Sale Price</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($consolidated as $ci)
                        @php
                            $img = $ci['image'];
                            $name = $ci['name'];
                            $sku = $ci['sku'];
                            $variation = $ci['variation'];
                            $qty = $ci['quantity'];
                            $itemPrice = $ci['item_price'];
                            $salePrice = $ci['sale_price'];
                        @endphp
                        <tr>
                            <td>
                                @if($img !== '')
                                    <img src="{{ $img }}" alt="" style="width:64px; height:64px; object-fit:cover; border-radius:4px;" onerror="this.style.display='none'">
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <div class="font-bold">{{ $name !== '' ? $name : '—' }}</div>
                                @if($variation !== '')
                                    <div class="order-option-wrap">
                                        <span class="order-option-label tiktok-label">Option</span>
                                        <span class="order-option-pill">{{ $variation }}</span>
                                    </div>
                                @endif
                            </td>
                            <td>{{ $sku !== '' ? $sku : '—' }}</td>
                            <td>{{ $qty }}</td>
                            <td>{{ $itemPrice > 0 ? $currency . number_format($itemPrice, 2) : '—' }}</td>
                            <td class="font-bold">{{ $salePrice > 0 ? $currency . number_format($salePrice, 2) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="d-flex gap-16 flex-wrap mt-16" style="align-items:flex-start;">
    <div class="card" style="flex:1; min-width:300px; margin:0;">
        <h3 class="section-title mt-0">Payment Summary</h3>
        <table class="table" style="max-width:400px;">
            <tbody>
                @if($totalAmount)
                    <tr><td class="text-secondary">Total Amount</td><td class="text-right font-bold">{{ $currency }}{{ number_format((float)$totalAmount, 2) }}</td></tr>
                @endif
                @if($shippingFee)
                    <tr><td class="text-secondary">Shipping Fee</td><td class="text-right">{{ $currency }}{{ number_format((float)$shippingFee, 2) }}</td></tr>
                @endif
                @if($sellerDiscount)
                    <tr><td class="text-secondary">Seller Discount</td><td class="text-right" style="color:#dc2626;">-{{ $currency }}{{ number_format(abs((float)$sellerDiscount), 2) }}</td></tr>
                @endif
                @if($platformDiscount)
                    <tr><td class="text-secondary">Platform Discount</td><td class="text-right" style="color:#dc2626;">-{{ $currency }}{{ number_format(abs((float)$platformDiscount), 2) }}</td></tr>
                @endif
            </tbody>
        </table>
    </div>

    @php
        $fees = is_array($order->fees ?? null) ? $order->fees : [];
        $fCommission   = abs((float) ($fees['commission'] ?? 0));
        $fTxnFee       = abs((float) ($fees['transaction_fee'] ?? 0));
        $fShipping     = abs((float) ($fees['shipping_fee'] ?? 0));
        $fPlatformDisc = abs((float) ($fees['platform_discount'] ?? 0));
        $fRevenue      = (float) ($fees['revenue'] ?? 0);
        $fSettlement   = (float) ($fees['settlement_amount'] ?? 0);
        $fTotalFees    = $fCommission + $fTxnFee + $fShipping;
        $hasFees       = $fTotalFees > 0 || $fSettlement > 0;
    @endphp
    <div class="card" style="flex:1; min-width:300px; margin:0;">
        <h3 class="section-title mt-0">Fees & Settlement</h3>
        @if($hasFees)
            <table class="table" style="max-width:400px;">
                <tbody>
                    @if($fRevenue > 0)
                        <tr><td class="text-secondary">Revenue</td><td class="text-right font-bold">{{ $currency }}{{ number_format($fRevenue, 2) }}</td></tr>
                    @endif
                    @if($fCommission > 0)
                        <tr><td class="text-secondary">Commission</td><td class="text-right" style="color:#dc2626;">-{{ $currency }}{{ number_format($fCommission, 2) }}</td></tr>
                    @endif
                    @if($fTxnFee > 0)
                        <tr><td class="text-secondary">Transaction Fee</td><td class="text-right" style="color:#dc2626;">-{{ $currency }}{{ number_format($fTxnFee, 2) }}</td></tr>
                    @endif
                    @if($fShipping > 0)
                        <tr><td class="text-secondary">Shipping Fee</td><td class="text-right" style="color:#dc2626;">-{{ $currency }}{{ number_format($fShipping, 2) }}</td></tr>
                    @endif
                    @if($fPlatformDisc > 0)
                        <tr><td class="text-secondary">Platform Discount</td><td class="text-right" style="color:#dc2626;">-{{ $currency }}{{ number_format($fPlatformDisc, 2) }}</td></tr>
                    @endif
                    @if($fTotalFees > 0)
                        <tr style="border-top:1px dashed var(--border);">
                            <td class="text-secondary font-bold">Total Fees</td>
                            <td class="text-right font-bold" style="color:#dc2626;">-{{ $currency }}{{ number_format($fTotalFees, 2) }}</td>
                        </tr>
                    @endif
                    @if($fSettlement > 0)
                        <tr style="border-top:2px solid var(--border);">
                            <td class="font-bold">Settlement</td>
                            <td class="text-right font-bold" style="color:var(--success);">{{ $currency }}{{ number_format($fSettlement, 2) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
            @if($order->payout_status)
                <div style="margin-top:10px;">
                    <span class="badge {{ $order->payout_status === 'Paid' ? 'badge-green' : 'badge-yellow' }}">{{ $order->payout_status }}</span>
                    @if($order->paid_at)
                        <span class="text-xs text-secondary" style="margin-left:6px;">{{ $order->paid_at->format('M d, Y') }}</span>
                    @endif
                </div>
            @endif
        @else
            <div class="text-secondary">Fees not yet available. Settlement data appears after TikTok completes the order settlement cycle.</div>
        @endif
    </div>
</div>

<div class="card mt-16">
    <h3 class="section-title mt-0">Raw Payload (debug)</h3>
    <details>
        <summary style="cursor:pointer;" class="font-bold">Show raw JSON</summary>
        <pre class="pre" style="margin-top:10px; white-space:pre-wrap; word-break:break-word;">{{ json_encode($raw, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
    </details>
</div>
{{-- Tracking modal --}}
<div id="ttTrackingModal" class="modal-backdrop">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <h3>Tracking</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('ttTrackingModal').classList.remove('active')">&times;</button>
        </div>
        <div id="ttTrackingBody" class="mt-12">
            <div class="text-secondary">Loading...</div>
        </div>
    </div>
</div>

<script>
(function() {
    var btn = document.getElementById('btnShowTracking');
    if (!btn) return;
    var modal = document.getElementById('ttTrackingModal');
    var body = document.getElementById('ttTrackingBody');

    modal.addEventListener('click', function(e) { if (e.target === modal) modal.classList.remove('active'); });

    btn.addEventListener('click', function() {
        body.textContent = 'Loading...';
        modal.classList.add('active');
        fetch(btn.dataset.url, {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}})
            .then(function(r) { return r.json(); })
            .then(function(data) {
                body.textContent = '';
                if (!data.ok) {
                    var err = document.createElement('div');
                    err.className = 'text-danger';
                    err.textContent = (data.body || {}).message || 'Error loading tracking info.';
                    body.appendChild(err);
                    return;
                }
                var apiBody = data.body || {};
                var trackData = apiBody.data || apiBody;
                var trackNum = data.tracking_number || trackData.tracking_number || '';
                var provider = data.shipping_carrier || trackData.shipping_provider || '';

                if (trackNum) {
                    var tnDiv = document.createElement('div');
                    tnDiv.className = 'mb-8';
                    var tnLabel = document.createElement('strong');
                    tnLabel.textContent = 'Tracking: ';
                    var tnValue = document.createElement('span');
                    tnValue.textContent = trackNum;
                    tnDiv.appendChild(tnLabel);
                    tnDiv.appendChild(tnValue);
                    body.appendChild(tnDiv);
                }
                if (provider) {
                    var scDiv = document.createElement('div');
                    scDiv.className = 'mb-8';
                    var scLabel = document.createElement('strong');
                    scLabel.textContent = 'Courier: ';
                    var scValue = document.createElement('span');
                    scValue.textContent = provider;
                    scDiv.appendChild(scLabel);
                    scDiv.appendChild(scValue);
                    body.appendChild(scDiv);
                }

                var events = trackData.tracking || trackData.tracking_info || [];

                if (!Array.isArray(events) || events.length === 0) {
                    if (!trackNum) {
                        var empty = document.createElement('div');
                        empty.className = 'text-secondary';
                        empty.textContent = 'No tracking info available yet.';
                        body.appendChild(empty);
                    }
                    return;
                }

                events.forEach(function(ev) {
                    var row = document.createElement('div');
                    row.style.cssText = 'margin-bottom:10px; padding-left:12px; border-left:2px solid var(--accent);';
                    var time = document.createElement('div');
                    time.className = 'text-xs text-secondary';
                    var ts = ev.update_time_millis || ev.update_time || null;
                    if (ts && typeof ts === 'number') {
                        var dt = new Date(ts > 1e12 ? ts : ts * 1000);
                        time.textContent = dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0') + '-' + String(dt.getDate()).padStart(2,'0') + ' ' + String(dt.getHours()).padStart(2,'0') + ':' + String(dt.getMinutes()).padStart(2,'0');
                    } else {
                        time.textContent = ev.update_time_text || '';
                    }
                    var desc = document.createElement('div');
                    desc.className = 'text-sm';
                    desc.textContent = ev.description || '';
                    row.appendChild(time);
                    row.appendChild(desc);
                    body.appendChild(row);
                });
            })
            .catch(function(err) {
                body.textContent = '';
                var errEl = document.createElement('div');
                errEl.className = 'text-danger';
                errEl.textContent = 'Failed to load tracking info.';
                body.appendChild(errEl);
            });
    });
})();
</script>
@endsection
