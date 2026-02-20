@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Shopee / Orders / Detail')

@section('content')
@php
    $raw = is_array($order->raw ?? null) ? $order->raw : [];

    $status = $order->status ?? ($raw['order_status'] ?? '');
    $statusStr = is_string($status) ? $status : '';

    $created = $order->order_created_at ? $order->order_created_at->format('Y-m-d H:i') : null;
    $updated = $order->order_updated_at ? $order->order_updated_at->format('Y-m-d H:i') : null;

    $buyer = (string)($raw['buyer_username'] ?? ($raw['buyer_user_name'] ?? ''));

    $addr = $raw['recipient_address'] ?? $raw['shipping_address'] ?? [];
    if (!is_array($addr)) $addr = [];

    $receiverName = (string)($addr['name'] ?? $buyer);
    $phone = (string)($addr['phone'] ?? '');
    $address1 = (string)($addr['full_address'] ?? ($addr['address1'] ?? ''));
    $city = (string)($addr['city'] ?? ($addr['town'] ?? ''));
    $state = (string)($addr['state'] ?? ($addr['region'] ?? ''));
    $zipcode = (string)($addr['zipcode'] ?? ($addr['zip_code'] ?? ''));
    $country = (string)($addr['country'] ?? '');

    $courier = (string)($raw['shipping_carrier'] ?? ($raw['checkout_shipping_carrier'] ?? ''));
    $tracking = (string)($raw['tracking_no'] ?? ($raw['tracking_number'] ?? ''));

    $items = $order->products ?? collect();

    $statusNorm = strtoupper(trim($statusStr));
    $isToPack = ($statusNorm === 'READY_TO_SHIP');

    $safeOrderSn = preg_replace('/[^A-Za-z0-9_-]/', '', $order->order_sn ?? '');
    $hasAwbFile = $safeOrderSn !== '' && file_exists(storage_path('app/shopee-awb/' . $safeOrderSn . '.pdf'));
@endphp

<div class="page-header">
    <div>
        <div class="d-flex gap-10 items-center flex-wrap">
            <h2 style="margin:0;">Shopee Order</h2>
            <span class="text-secondary text-xs">Order SN</span>
            <span class="font-bold">{{ $order->order_sn }}</span>
            @if($statusStr !== '')
                <span class="badge badge-default" style="margin-left:6px;">{{ $statusStr }}</span>
            @endif
        </div>
        <div class="meta-bar" style="margin-top:8px; margin-bottom:0; padding:8px 0; background:transparent; border:none;">
            @if($created) <div class="meta-item"><span class="meta-label">Create Time:</span> {{ $created }}</div> @endif
            @if($updated) <div class="meta-item"><span class="meta-label">Update Time:</span> {{ $updated }}</div> @endif
        </div>
    </div>

    <div class="page-header-actions">
        <a href="{{ route('shopee.orders.index') }}" class="btn secondary">← Back to Orders</a>
        <a href="{{ route('shopee.orders.show', ['orderSn' => $order->order_sn, 'refresh' => 1]) }}" class="btn">Refresh from Shopee</a>
        @if($isToPack)
            <form method="POST" action="{{ route('shopee.orders.ship', ['orderSn' => $order->order_sn]) }}" onsubmit="return confirm('Ship this order now?');" style="display:inline;">
                @csrf
                <button type="submit" class="btn" style="background:#16a34a; border-color:#16a34a; color:#fff;">Ship Order</button>
            </form>
        @endif
    </div>
</div>

@if($api_error)
    <div class="alert warning">
        <strong>Note</strong>
        <div style="margin-top:6px;">Could not refresh from Shopee API — this is normal for older/completed orders. Showing cached data.</div>
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
                    @if($city !== '' || $state !== '' || $zipcode !== '' || $country !== '')
                        <br>{{ trim($city . ' ' . $state) }} {{ $zipcode }} {{ $country }}
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
            @if($hasAwbFile || $statusNorm === 'PROCESSED')
                <div class="mt-12">
                    <a href="{{ route('shopee.orders.awb', ['orderSn' => $order->order_sn]) }}" target="_blank" rel="noopener" class="btn">Print AWB</a>
                </div>
            @endif
        </div>
    </div>
</div>

<div class="card">
    <h3 class="section-title mt-0">Items</h3>
    @if($items->count() === 0)
        <div class="text-secondary">No items cached yet. Click <strong>Refresh from Shopee</strong>.</div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:90px;">Image</th>
                        <th>Product</th>
                        <th style="width:130px;">SKU</th>
                        <th style="width:100px;">Qty</th>
                        <th style="width:120px;">Price</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $it)
                        @php
                            $ir = is_array($it->raw ?? null) ? $it->raw : [];
                            $img = (string)($it->image ?? ($ir['image_info']['image_url'] ?? ''));
                            $name = (string)($it->name ?? ($ir['item_name'] ?? ''));
                            $sku = (string)($it->sku ?? ($ir['item_sku'] ?? ($ir['model_sku'] ?? '')));
                            $variation = (string)($it->variation ?? ($ir['model_name'] ?? ''));
                            $qty = max(1, (int)($it->quantity ?? 1));
                            $price = (float)($it->price ?? 0);
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
                            <td>{{ number_format($price, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@php
    $fees = is_array($order->fees ?? null) ? $order->fees : [];
    $hasFees = !empty($fees);

    // Shopee escrow detail fields
    $oBuyerTotal = (float)($fees['buyer_total_amount'] ?? 0);
    $oOriginalPrice = (float)($fees['original_price'] ?? 0);
    $oSellerDiscount = (float)($fees['seller_discount'] ?? 0);
    $oPlatformDiscount = (float)($fees['platform_discount'] ?? 0);
    $oVoucherSeller = (float)($fees['voucher_from_seller'] ?? 0);
    $oVoucherPlatform = (float)($fees['voucher_from_platform'] ?? 0);
    $oCoinOffset = (float)($fees['coins'] ?? ($fees['coin_offset'] ?? 0));
    $oCommission = (float)($fees['commission_fee'] ?? 0);
    $oServiceFee = (float)($fees['service_fee'] ?? 0);
    $oTransactionFee = (float)($fees['seller_transaction_fee'] ?? ($fees['credit_card_transaction_fee'] ?? 0));
    $oShippingBuyer = (float)($fees['buyer_paid_shipping_fee'] ?? 0);
    $oShippingDiscount = (float)($fees['shipping_fee_discount_from_3pl'] ?? 0);
    $oShippingSeller = (float)($fees['seller_shipping_discount'] ?? ($fees['shipping_seller'] ?? 0));
    $oEscrow = (float)($fees['escrow_amount'] ?? ($fees['order_income'] ?? 0));
    $oSellerDue = (float)($fees['seller_due'] ?? 0);

    // Compute net if escrow is available
    $netAmount = $oEscrow ?: $oSellerDue;
@endphp

@if($hasFees)
<div class="card mt-16">
    <h3 class="section-title mt-0">Fees & Revenue Breakdown</h3>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px;">
        <div>
            <table class="table" style="width:100%;">
                <tbody>
                    @if($oOriginalPrice)
                    <tr><td class="text-secondary">Original Price</td><td class="text-right font-bold">{{ number_format($oOriginalPrice, 2) }}</td></tr>
                    @endif
                    @if($oBuyerTotal)
                    <tr><td class="text-secondary">Buyer Total</td><td class="text-right font-bold">{{ number_format($oBuyerTotal, 2) }}</td></tr>
                    @endif
                    @if($oSellerDiscount)
                    <tr><td class="text-secondary">Seller Discount</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($oSellerDiscount), 2) }}</td></tr>
                    @endif
                    @if($oPlatformDiscount)
                    <tr><td class="text-secondary">Platform Discount</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($oPlatformDiscount), 2) }}</td></tr>
                    @endif
                    @if($oVoucherSeller)
                    <tr><td class="text-secondary">Voucher (Seller)</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($oVoucherSeller), 2) }}</td></tr>
                    @endif
                    @if($oVoucherPlatform)
                    <tr><td class="text-secondary">Voucher (Platform)</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($oVoucherPlatform), 2) }}</td></tr>
                    @endif
                    @if($oCoinOffset)
                    <tr><td class="text-secondary">Coins</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($oCoinOffset), 2) }}</td></tr>
                    @endif
                    @if($oShippingBuyer)
                    <tr><td class="text-secondary">Shipping (Buyer Paid)</td><td class="text-right">{{ number_format($oShippingBuyer, 2) }}</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
        <div>
            <table class="table" style="width:100%;">
                <tbody>
                    @if($oCommission)
                    <tr><td class="text-secondary">Commission Fee</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($oCommission), 2) }}</td></tr>
                    @endif
                    @if($oServiceFee)
                    <tr><td class="text-secondary">Service Fee</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($oServiceFee), 2) }}</td></tr>
                    @endif
                    @if($oTransactionFee)
                    <tr><td class="text-secondary">Transaction Fee</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($oTransactionFee), 2) }}</td></tr>
                    @endif
                    @if($oShippingDiscount)
                    <tr><td class="text-secondary">3PL Shipping Discount</td><td class="text-right">{{ number_format($oShippingDiscount, 2) }}</td></tr>
                    @endif
                    @if($oShippingSeller)
                    <tr><td class="text-secondary">Seller Shipping Cost</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($oShippingSeller), 2) }}</td></tr>
                    @endif
                    @if($netAmount)
                    <tr style="border-top:2px solid var(--border);">
                        <td class="font-bold">Net to Seller</td>
                        <td class="text-right font-bold" style="font-size:1.1em; color:#16a34a;">{{ number_format($netAmount, 2) }}</td>
                    </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

@php
    $returns = $returns ?? collect();
@endphp
@if($returns->count() > 0)
<div class="card mt-16">
    <h3 class="section-title mt-0">Returns / Refunds</h3>
    @foreach($returns as $ret)
        <div style="padding:12px; background:var(--surface-alt); border-radius:8px;{{ !$loop->first ? ' margin-top:12px;' : '' }}">
            <div class="d-flex justify-between items-start gap-12 flex-wrap">
                <div>
                    <div class="text-xs text-secondary">Return SN</div>
                    <div class="font-bold">{{ $ret->return_sn }}</div>
                </div>
                <div>
                    <div class="text-xs text-secondary">Status</div>
                    <div class="font-bold">{{ $ret->status ?: '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-secondary">Reason</div>
                    <div class="font-semibold">{{ $ret->reason ?: '—' }}</div>
                    @if($ret->reason_text)
                        <div class="text-xs text-secondary" style="margin-top:2px;">{{ $ret->reason_text }}</div>
                    @endif
                </div>
                <div>
                    <div class="text-xs text-secondary">Refund Amount</div>
                    <div class="font-bold" style="color:#dc2626;">{{ ($ret->currency ?: '₱') . number_format((float)$ret->refund_amount, 2) }}</div>
                </div>
                <div>
                    <div class="text-xs text-secondary">Created</div>
                    <div>{{ $ret->return_created_at ? $ret->return_created_at->format('Y-m-d H:i') : '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-secondary">Updated</div>
                    <div>{{ $ret->return_updated_at ? $ret->return_updated_at->format('Y-m-d H:i') : '—' }}</div>
                </div>
            </div>

            @php $retItems = is_array($ret->items) ? $ret->items : []; @endphp
            @if(!empty($retItems))
                <div style="margin-top:12px;">
                    <div class="text-xs text-secondary font-bold" style="margin-bottom:6px;">Items</div>
                    <div class="table-wrap">
                        <table class="table" style="width:100%;">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th style="width:100px;">Qty</th>
                                    <th style="width:120px;">Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($retItems as $ri)
                                    @php
                                        $ri = is_array($ri) ? $ri : [];
                                        $riName = $ri['name'] ?? ($ri['item_name'] ?? ($ri['model_name'] ?? '—'));
                                        $riQty = $ri['quantity'] ?? ($ri['amount'] ?? 1);
                                        $riPrice = $ri['item_price'] ?? ($ri['price'] ?? 0);
                                    @endphp
                                    <tr>
                                        <td>{{ $riName }}</td>
                                        <td>{{ $riQty }}</td>
                                        <td>{{ is_numeric($riPrice) ? number_format((float)$riPrice, 2) : $riPrice }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @php $nego = is_array($ret->negotiation) ? $ret->negotiation : []; @endphp
            @if(!empty($nego))
                <div style="margin-top:12px;">
                    <details>
                        <summary style="cursor:pointer;" class="text-xs text-secondary font-bold">Negotiation History ({{ count($nego) }})</summary>
                        <div style="margin-top:8px;">
                            @foreach($nego as $n)
                                @php $n = is_array($n) ? $n : []; @endphp
                                <div style="padding:6px 8px; background:var(--bg); border-radius:4px;{{ !$loop->first ? ' margin-top:6px;' : '' }}">
                                    <div class="d-flex justify-between items-center">
                                        <span class="font-semibold text-xs">{{ $n['role'] ?? ($n['offer_by'] ?? 'Unknown') }}</span>
                                        @if(!empty($n['create_time']))
                                            <span class="text-xs text-secondary">{{ date('Y-m-d H:i', is_numeric($n['create_time']) ? $n['create_time'] : strtotime($n['create_time'])) }}</span>
                                        @endif
                                    </div>
                                    @if(!empty($n['offer_amount']))
                                        <div class="text-xs" style="margin-top:4px;">Amount: <strong>{{ number_format((float)$n['offer_amount'], 2) }}</strong></div>
                                    @endif
                                    @if(!empty($n['reason']))
                                        <div class="text-xs text-secondary" style="margin-top:2px;">{{ $n['reason'] }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </details>
                </div>
            @endif
        </div>
    @endforeach
</div>
@endif

<div class="card mt-16">
    <h3 class="section-title mt-0">Raw Payload (debug)</h3>
    <details>
        <summary style="cursor:pointer;" class="font-bold">Show raw JSON</summary>
        <pre class="pre" style="margin-top:10px; white-space:pre-wrap; word-break:break-word;">{{ json_encode($raw, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
    </details>
</div>
@endsection
