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

@php
    $fees = is_array($order->fees ?? null) ? $order->fees : [];
    $hasFees = !empty($fees) && !empty(array_filter($fees, fn($v) => !is_array($v) && $v != 0));

    $lSubtotal = (float)($fees['subtotal'] ?? 0);
    $lPaidTotal = (float)($fees['paid_total'] ?? 0);
    $lShipping = (float)($fees['shipping'] ?? 0);
    $lVoucherSeller = (float)($fees['voucher_seller'] ?? 0);
    $lVoucherPlatform = (float)($fees['voucher_platform'] ?? 0);
    $lShipDiscountSeller = (float)($fees['shipping_discount_seller'] ?? 0);
    $lShipDiscountPlatform = (float)($fees['shipping_discount_platform'] ?? 0);
    $lWalletCredits = (float)($fees['wallet_credits'] ?? 0);
    $lShippingServiceCost = (float)($fees['shipping_service_cost'] ?? 0);
    $lCommission = (float)($fees['commission'] ?? 0);
    $lPaymentFee = (float)($fees['payment_fee'] ?? 0);
    $lOrderPrice = (float)($fees['order_price'] ?? 0);
    $lOrderVoucher = (float)($fees['order_voucher'] ?? 0);
    $lOtherFees = $fees['other_fees'] ?? [];

    // Estimate net: paid - commission - payment fee - shipping service cost
    $lNet = $lPaidTotal ?: $lSubtotal;
    if ($lCommission) $lNet += $lCommission; // commission is typically negative
    if ($lPaymentFee) $lNet += $lPaymentFee;
    if ($lShippingServiceCost) $lNet -= $lShippingServiceCost;
@endphp

@if($hasFees)
<div class="card mt-16">
    <h3 class="section-title mt-0">Fees & Revenue Breakdown</h3>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px;">
        <div>
            <table class="table" style="width:100%;">
                <tbody>
                    @if($lSubtotal)
                    <tr><td class="text-secondary">Subtotal (Item Prices)</td><td class="text-right font-bold">{{ number_format($lSubtotal, 2) }}</td></tr>
                    @endif
                    @if($lPaidTotal && $lPaidTotal != $lSubtotal)
                    <tr><td class="text-secondary">Paid Total</td><td class="text-right font-bold">{{ number_format($lPaidTotal, 2) }}</td></tr>
                    @endif
                    @if($lShipping)
                    <tr><td class="text-secondary">Shipping</td><td class="text-right">{{ number_format($lShipping, 2) }}</td></tr>
                    @endif
                    @if($lVoucherSeller)
                    <tr><td class="text-secondary">Voucher (Seller)</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($lVoucherSeller), 2) }}</td></tr>
                    @endif
                    @if($lVoucherPlatform)
                    <tr><td class="text-secondary">Voucher (Platform)</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($lVoucherPlatform), 2) }}</td></tr>
                    @endif
                    @if($lWalletCredits)
                    <tr><td class="text-secondary">Wallet Credits</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($lWalletCredits), 2) }}</td></tr>
                    @endif
                    @if($lOrderVoucher && !$lVoucherSeller && !$lVoucherPlatform)
                    <tr><td class="text-secondary">Order Voucher</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($lOrderVoucher), 2) }}</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
        <div>
            <table class="table" style="width:100%;">
                <tbody>
                    @if($lCommission)
                    <tr><td class="text-secondary">Commission</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($lCommission), 2) }}</td></tr>
                    @endif
                    @if($lPaymentFee)
                    <tr><td class="text-secondary">Payment Fee</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($lPaymentFee), 2) }}</td></tr>
                    @endif
                    @if($lShippingServiceCost)
                    <tr><td class="text-secondary">Shipping Service Cost</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($lShippingServiceCost), 2) }}</td></tr>
                    @endif
                    @if($lShipDiscountSeller)
                    <tr><td class="text-secondary">Shipping Discount (Seller)</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($lShipDiscountSeller), 2) }}</td></tr>
                    @endif
                    @if($lShipDiscountPlatform)
                    <tr><td class="text-secondary">Shipping Discount (Platform)</td><td class="text-right">{{ number_format(abs($lShipDiscountPlatform), 2) }}</td></tr>
                    @endif
                    @if(is_array($lOtherFees))
                        @foreach($lOtherFees as $feeName => $feeAmt)
                            @if($feeAmt != 0)
                            <tr><td class="text-secondary">{{ ucwords(str_replace('_', ' ', $feeName)) }}</td><td class="text-right" style="{{ $feeAmt < 0 ? 'color:#dc2626;' : '' }}">{{ $feeAmt < 0 ? '-' : '' }}{{ number_format(abs($feeAmt), 2) }}</td></tr>
                            @endif
                        @endforeach
                    @endif
                    @if($lNet && ($lCommission || $lPaymentFee || $lShippingServiceCost))
                    <tr style="border-top:2px solid var(--border);">
                        <td class="font-bold">Estimated Net</td>
                        <td class="text-right font-bold" style="font-size:1.1em; color:#16a34a;">{{ number_format($lNet, 2) }}</td>
                    </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
    @if(!$lCommission && !$lPaymentFee)
        <div class="text-secondary text-xs mt-12" style="padding:0 4px;">Commission and payment fee data may not be available until the order is settled by Lazada.</div>
    @endif
</div>
@endif

<div class="card mt-16">
    <h3 class="section-title mt-0">Raw Payload (debug)</h3>
    <details>
        <summary style="cursor:pointer;" class="font-bold">Show raw JSON</summary>
        <pre class="pre" style="margin-top:10px; white-space:pre-wrap; word-break:break-word;">{{ json_encode(['order' => $raw, 'detail' => $detail], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
    </details>
</div>
@endsection
