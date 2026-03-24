@extends('layouts.app')
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
            @if($created) <div class="meta-item"><span class="meta-label">Created:</span> {{ $created }}</div> @endif
            @if($updated) <div class="meta-item"><span class="meta-label">Updated:</span> {{ $updated }}</div> @endif
        </div>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('ext.tiktok.orders.index') }}" class="btn secondary">← Back to Orders</a>
    </div>
</div>

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
                <a href="{{ route('ext.tiktok.orders.awb', $order->id) }}" target="_blank" class="btn secondary">AWB PDF</a>
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

<div class="card mt-16">
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

<div class="card mt-16">
    <h3 class="section-title mt-0">Raw Payload (debug)</h3>
    <details>
        <summary style="cursor:pointer;" class="font-bold">Show raw JSON</summary>
        <pre class="pre" style="margin-top:10px; white-space:pre-wrap; word-break:break-word;">{{ json_encode($raw, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
    </details>
</div>
@endsection
