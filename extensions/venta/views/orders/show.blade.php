@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Venta / ' . $setting->store_name . ' / Orders / Detail')

@section('content')
@php
    $raw = is_array($order->raw ?? null) ? $order->raw : [];
    $status = $order->status ?? '';
    $statusLabel = ucwords(str_replace('_', ' ', $status));

    $created = $order->order_created_at ? $order->order_created_at->format('Y-m-d H:i') : null;
    $updated = $order->order_updated_at ? $order->order_updated_at->format('Y-m-d H:i') : null;

    $customerName = (string)($order->customer_name ?? '');
    $customerEmail = (string)($order->customer_email ?? '');

    $address = $order->shipping_address ?? [];
    $addrName = (string)($address['name'] ?? $address['full_name'] ?? $customerName);
    $addrPhone = (string)($address['phone'] ?? $address['phone_number'] ?? '');
    $addrLine = (string)($address['address'] ?? $address['full_address'] ?? $address['address_1'] ?? '');
    $addrCity = (string)($address['city'] ?? '');
    $addrState = (string)($address['state'] ?? $address['province'] ?? '');
    $addrZip = (string)($address['zip'] ?? $address['zipcode'] ?? $address['postal_code'] ?? '');
    $addrCountry = (string)($address['country'] ?? '');

    $shipping = (string)($order->shipping_method ?? '');
    $trackingNo = (string)($order->tracking_number ?? '');
    $payment = (string)($order->payment_method ?? '');

    $items = $order->products ?? collect();

    $badgeClass = match(strtolower($status)) {
        'completed', 'delivered' => 'badge-green',
        'cancelled', 'refunded' => 'badge-red',
        'pending', 'unpaid' => 'badge-yellow',
        'processing', 'in_transit', 'shipped' => 'badge-blue',
        default => '',
    };
@endphp

<div class="page-header">
    <div>
        <div class="d-flex gap-10 items-center flex-wrap">
            <h2 style="margin:0;">Venta Order</h2>
            <span class="text-secondary text-xs">Order #</span>
            <span class="font-bold">{{ $order->venta_order_number ?: $order->venta_order_id }}</span>
            @if($status !== '')
                <span class="badge {{ $badgeClass }}" style="margin-left:6px;">{{ $statusLabel }}</span>
            @endif
        </div>
        <div class="meta-bar" style="margin-top:8px; margin-bottom:0; padding:8px 0; background:transparent; border:none;">
            @if($created) <div class="meta-item"><span class="meta-label">Created:</span> {{ $created }}</div> @endif
            @if($updated) <div class="meta-item"><span class="meta-label">Updated:</span> {{ $updated }}</div> @endif
            @if($order->catalog_order_id)
                <div class="meta-item"><span class="meta-label">ERP Order:</span> <a href="{{ route('orders.show', $order->catalog_order_id) }}">#{{ $order->catalog_order_id }}</a></div>
            @endif
        </div>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('ext.venta.orders.index', $setting->id) }}" class="btn secondary">← Back to Orders</a>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-section">
        <h4>Customer & Shipping</h4>
        <div class="detail-body">
            <div><span class="text-secondary">Customer:</span> <strong>{{ $customerName !== '' ? $customerName : '—' }}</strong></div>
            @if($customerEmail !== '')
                <div><span class="text-secondary">Email:</span> <strong>{{ $customerEmail }}</strong></div>
            @endif
            <div style="margin-top:8px;"><span class="text-secondary">Receiver:</span> <strong>{{ $addrName !== '' ? $addrName : '—' }}</strong></div>
            <div><span class="text-secondary">Phone:</span> <strong>{{ $addrPhone !== '' ? $addrPhone : '—' }}</strong></div>
            <div style="margin-top:10px;">
                <div class="text-secondary text-xs font-bold">Address</div>
                <div style="margin-top:4px;">
                    {{ $addrLine !== '' ? $addrLine : '—' }}
                    @if($addrCity !== '' || $addrState !== '' || $addrZip !== '')
                        <br>{{ trim($addrCity . ' ' . $addrState) }} {{ $addrZip }} {{ $addrCountry }}
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="detail-section">
        <h4>Logistics & Payment</h4>
        <div class="detail-body">
            <div><span class="text-secondary">Shipping Method:</span> <strong>{{ $shipping !== '' ? $shipping : '—' }}</strong></div>
            <div><span class="text-secondary">Tracking #:</span> <strong>{{ $trackingNo !== '' ? $trackingNo : '—' }}</strong></div>
            <div style="margin-top:10px;"><span class="text-secondary">Payment Method:</span> <strong>{{ $payment !== '' ? $payment : '—' }}</strong></div>
            <div><span class="text-secondary">Total:</span> <strong>{{ number_format((float)$order->total, 2) }}</strong></div>
        </div>
    </div>
</div>

<div class="card">
    <h3 class="section-title mt-0">Items</h3>
    @if($items->count() === 0)
        <div class="text-secondary">No items synced yet.</div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="width:130px;">SKU</th>
                        <th style="width:70px;">Qty</th>
                        <th style="width:100px;">Unit Price</th>
                        <th style="width:100px;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $it)
                        @php
                            $itName = (string)($it->name ?? '');
                            $itSku = (string)($it->sku ?? '');
                            $itVariant = (string)($it->variant_label ?? '');
                            $variantNorm = mb_strtolower(trim($itVariant));
                            if (in_array($variantNorm, ['', 'blank', 'null', 'n/a', 'na', 'none', '-', '--'], true)) {
                                $itVariant = '';
                            }
                            $itQty = (int)($it->quantity ?? 1);
                            $itPrice = (float)($it->price ?? 0);
                            $itTotal = (float)($it->total ?? 0);
                        @endphp
                        <tr>
                            <td>
                                <div class="font-bold">{{ $itName !== '' ? $itName : '—' }}</div>
                                @if($itVariant !== '')
                                    <div class="order-option-wrap">
                                        <span class="order-option-label">Option</span>
                                        <span class="order-option-pill">{{ $itVariant }}</span>
                                    </div>
                                @endif
                            </td>
                            <td>{{ $itSku !== '' ? $itSku : '—' }}</td>
                            <td>{{ $itQty }}</td>
                            <td>{{ $itPrice > 0 ? number_format($itPrice, 2) : '—' }}</td>
                            <td class="font-bold">{{ $itTotal > 0 ? number_format($itTotal, 2) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="card mt-16">
    <h3 class="section-title mt-0">Order Summary</h3>
    <table class="table" style="max-width:400px;">
        <tbody>
            <tr><td class="text-secondary">Total</td><td class="text-right font-bold">{{ number_format((float)$order->total, 2) }}</td></tr>
            <tr><td class="text-secondary">Status</td><td class="text-right"><span class="badge {{ $badgeClass }}">{{ $statusLabel }}</span></td></tr>
            @if($order->catalog_order_id)
                <tr><td class="text-secondary">ERP Order</td><td class="text-right"><a href="{{ route('orders.show', $order->catalog_order_id) }}">#{{ $order->catalog_order_id }}</a></td></tr>
            @endif
        </tbody>
    </table>
</div>

@if(!empty($raw))
<div class="card mt-16">
    <h3 class="section-title mt-0">Raw Payload (debug)</h3>
    <details>
        <summary style="cursor:pointer;" class="font-bold">Show raw JSON</summary>
        <pre class="pre" style="margin-top:10px; white-space:pre-wrap; word-break:break-word;">{{ json_encode($raw, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
    </details>
</div>
@endif
@endsection
