@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Lazada / Orders / Detail')

@section('content')
@php
    $raw = is_array($order->raw ?? null) ? $order->raw : [];
    $detail = is_array($detail ?? null) ? $detail : [];

    $status = $order->status ?? ($detail['statuses'] ?? $detail['status'] ?? $raw['statuses'] ?? $raw['status'] ?? '');
    if (is_array($status)) { $status = implode(', ', $status); }
    $status = is_string($status) ? $status : '';

    $created = $order->order_created_at ? $order->order_created_at->format('Y-m-d H:i') : ($detail['created_at'] ?? $raw['created_at'] ?? $raw['createdAt'] ?? $raw['created_time'] ?? null);
    $updated = $order->order_updated_at ? $order->order_updated_at->format('Y-m-d H:i') : ($detail['updated_at'] ?? $raw['updated_at'] ?? $raw['updatedAt'] ?? $raw['update_time'] ?? null);

    $buyer = (string)($detail['customer_name'] ?? $detail['customer_first_name'] ?? $detail['buyer_name'] ?? $raw['customer_name'] ?? $raw['customer_first_name'] ?? $raw['buyer_name'] ?? '');

    $receiverName = (string)($detail['address_shipping']['first_name'] ?? $detail['address_shipping']['customer_name'] ?? $detail['receiver_name'] ?? $detail['shipping_address']['name'] ?? '');
    if ($receiverName === '') { $receiverName = $buyer; }

    $phone = (string)($detail['address_shipping']['phone'] ?? $detail['address_shipping']['phone2'] ?? $detail['receiver_phone'] ?? $detail['shipping_address']['phone'] ?? '');
    $address1 = (string)($detail['address_shipping']['address1'] ?? $detail['address_shipping']['address'] ?? $detail['shipping_address']['address1'] ?? $detail['shipping_address']['address'] ?? '');
    $address2 = (string)($detail['address_shipping']['address2'] ?? $detail['shipping_address']['address2'] ?? '');
    $city = (string)($detail['address_shipping']['city'] ?? $detail['shipping_address']['city'] ?? '');
    $province = (string)($detail['address_shipping']['province'] ?? $detail['shipping_address']['province'] ?? $detail['address_shipping']['state'] ?? $detail['shipping_address']['state'] ?? '');
    $postcode = (string)($detail['address_shipping']['post_code'] ?? $detail['address_shipping']['postcode'] ?? $detail['shipping_address']['post_code'] ?? $detail['shipping_address']['postcode'] ?? '');
    $country = (string)($detail['address_shipping']['country'] ?? $detail['shipping_address']['country'] ?? '');

    $courier = $detail['shipping_provider'] ?? $detail['shipping_provider_type'] ?? $detail['shipping_provider_name'] ?? $raw['shipping_provider'] ?? $raw['shipping_provider_type'] ?? $raw['shipping_provider_name'] ?? null;
    if (is_array($courier)) $courier = implode(', ', $courier);
    $courier = is_string($courier) ? trim($courier) : '';

    $tracking = $detail['tracking_code'] ?? $detail['tracking_number'] ?? $raw['tracking_code'] ?? $raw['tracking_number'] ?? null;
    if (is_array($tracking)) $tracking = implode(', ', $tracking);
    $tracking = is_string($tracking) ? trim($tracking) : '';

    $items = $order->products ?? collect();
@endphp

<div class="page-header">
    <div>
        <div class="d-flex gap-10 items-center flex-wrap">
            <h2 style="margin:0;">Lazada Order</h2>
            <span class="text-secondary text-xs">Order #</span>
            <span class="font-bold">{{ $order->order_id }}</span>
            @if($status !== '')
                <span class="badge badge-default" style="margin-left:6px;">{{ $status }}</span>
            @endif
        </div>
        <div class="meta-bar" style="margin-top:8px; margin-bottom:0; padding:8px 0; background:transparent; border:none;">
            @if($created) <div class="meta-item"><span class="meta-label">Create Time:</span> {{ $created }}</div> @endif
            @if($updated) <div class="meta-item"><span class="meta-label">Update Time:</span> {{ $updated }}</div> @endif
        </div>
    </div>

    <div class="page-header-actions">
        <a href="{{ route('lazada.orders.index') }}" class="btn secondary">← Back to Orders</a>
        <a href="{{ route('lazada.orders.show', ['orderId' => $order->order_id, 'refresh' => 1]) }}" class="btn">Refresh from Lazada</a>
    </div>
</div>

@if($api_error)
    <div class="alert danger">
        <strong>Error</strong>
        <div style="margin-top:6px;">Unable to refresh order details from Lazada. Showing cached data.</div>
    </div>
@endif

@if($api_items_synced)
    <div class="alert success">
        <strong>OK</strong>
        <div style="margin-top:6px;">Order items refreshed.</div>
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
                    {{ $address1 !== '' ? $address1 : '—' }}
                    @if($address2 !== '')<br>{{ $address2 }}@endif
                    @if($city !== '' || $province !== '' || $postcode !== '' || $country !== '')
                        <br>{{ trim($city . ' ' . $province) }} {{ $postcode }} {{ $country }}
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="detail-section">
        <h4>Logistics</h4>
        <div class="detail-body">
            <div><span class="text-secondary">Courier:</span> <strong>{{ $courier !== '' ? $courier : '—' }}</strong></div>
            <div><span class="text-secondary">Tracking #:</span> <strong>{{ $tracking !== '' ? $tracking : '—' }}</strong></div>
            <div class="d-flex gap-10 flex-wrap mt-12">
                <a href="{{ route('lazada.orders.logistics_trace', ['orderId' => $order->order_id]) }}" target="_blank" rel="noopener" class="btn secondary">View Logistics Trace</a>
                <a href="{{ route('lazada.orders.awb', ['orderId' => $order->order_id]) }}" target="_blank" rel="noopener" class="btn">AWB PDF</a>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <h3 class="section-title mt-0">Items</h3>
    @if($items->count() === 0)
        <div class="text-secondary">No items cached yet. Click <strong>Refresh from Lazada</strong>.</div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:90px;">Image</th>
                        <th>Product</th>
                        <th style="width:130px;">SKU</th>
                        <th style="width:100px;">Qty</th>
                        <th style="width:190px;">Order Item ID</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $it)
                        @php
                            $ir = is_array($it->raw ?? null) ? $it->raw : [];
                            $img = (string)($it->image ?? ($ir['product_main_image'] ?? $ir['image'] ?? ''));
                            $name = (string)($it->name ?? ($ir['name'] ?? $ir['product_name'] ?? ''));
                            $sku = (string)($it->sku ?? ($ir['seller_sku'] ?? $ir['SellerSku'] ?? $ir['Sku'] ?? ''));
                            $variation = (string)($it->variation ?? ($ir['variation'] ?? ''));
                            $qty = (int)($it->quantity ?? ($ir['quantity'] ?? 1));
                            if ($qty < 1) $qty = 1;
                            $orderItemId = (string)($it->order_item_id ?? ($ir['order_item_id'] ?? $ir['orderItemId'] ?? ''));
                        @endphp
                        <tr>
                            <td>
                                @if($img !== '')
                                    <img src="{{ $img }}" alt="" class="thumb" style="width:64px; height:64px;" onerror="this.style.display='none'">
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <div class="font-bold">{{ $name !== '' ? $name : '—' }}</div>
                                @if($variation !== '')
                                    <div class="text-secondary text-xs" style="margin-top:4px;">{{ $variation }}</div>
                                @endif
                            </td>
                            <td>{{ $sku !== '' ? $sku : '—' }}</td>
                            <td>{{ $qty }}</td>
                            <td>{{ $orderItemId !== '' ? $orderItemId : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="card mt-16">
    <h3 class="section-title mt-0">Raw Payload (debug)</h3>
    <details>
        <summary style="cursor:pointer;" class="font-bold">Show raw JSON</summary>
        <pre class="pre" style="margin-top:10px; white-space:pre-wrap; word-break:break-word;">{{ json_encode(['order' => $raw, 'detail' => $detail], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
    </details>
</div>
@endsection
