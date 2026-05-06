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

    $badgeClass = match($statusNorm) {
        'COMPLETED' => 'badge-green',
        'CANCELLED' => 'badge-red',
        'UNPAID' => 'badge-yellow',
        'SHIPPED', 'TO_CONFIRM_RECEIVE' => 'badge-blue',
        default => '',
    };
@endphp

<div class="page-header">
    <div>
        <div class="d-flex gap-10 items-center flex-wrap">
            <h2 style="margin:0;">Shopee Order</h2>
            <span class="text-secondary text-xs">Order SN</span>
            <span class="font-bold">{{ $order->order_sn }}</span>
            @if($statusStr !== '')
                <span class="badge {{ $badgeClass }}" style="margin-left:6px;">{{ $statusStr }}</span>
            @endif
        </div>
        <div class="meta-bar" style="margin-top:8px; margin-bottom:0; padding:8px 0; background:transparent; border:none;">
            @if($created) <div class="meta-item"><span class="meta-label">Create Time:</span> {{ $created }}</div> @endif
            @if($updated) <div class="meta-item"><span class="meta-label">Update Time:</span> {{ $updated }}</div> @endif
        </div>
    </div>

    <div class="page-header-actions">
        <a href="{{ route('ext.shopee.orders.index') }}" class="btn secondary">← Back to Orders</a>
        <a href="{{ route('ext.shopee.orders.show', ['orderSn' => $order->order_sn, 'refresh' => 1]) }}" class="btn secondary">Refresh from Shopee</a>
        @if($isToPack)
            <button type="button" id="btnArrangeShipShow" class="btn" style="background:#EE4D2D; border-color:#EE4D2D; color:#fff;">Arrange Shipment</button>
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
            @if(in_array($statusNorm, ['PROCESSED', 'SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED']))
            <div class="d-flex gap-10 flex-wrap mt-12">
                <a href="{{ route('ext.shopee.orders.awb', ['orderSn' => $order->order_sn]) }}" onclick="window.open(this.href, 'awb', 'width=900,height=700'); return false;" rel="noopener" class="btn" style="background:#EE4D2D; border-color:#EE4D2D; color:#fff;">AWB PDF</a>
                <button type="button" class="btn" style="background:#EE4D2D; border-color:#EE4D2D; color:#fff;" id="btnLogisticsTrace" data-url="{{ route('ext.shopee.orders.tracking_info', ['orderSn' => $order->order_sn]) }}">Tracking</button>
            </div>
            @endif
        </div>
    </div>
</div>

<div class="card">
    <h3 class="section-title mt-0">Items</h3>
    @if($items->count() === 0)
        <div class="text-secondary">No items synced yet. Items will be fetched on the next order sync.</div>
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
    $feesRaw = is_array($order->fees ?? null) ? $order->fees : [];
    // Support both flat format and old nested format (order_income sub-key)
    $fees = (isset($feesRaw['order_income']) && is_array($feesRaw['order_income']))
        ? $feesRaw['order_income']
        : $feesRaw;
    $hasFees = !empty($fees);

    // Shopee escrow detail fields
    $oBuyerTotal = (float)($fees['buyer_total_amount'] ?? 0);
    $oOriginalPrice = (float)($fees['original_price'] ?? 0);
    $oSellerDiscount = (float)($fees['seller_discount'] ?? 0);
    $oPlatformDiscount = (float)($fees['shopee_discount'] ?? ($fees['platform_discount'] ?? 0));
    $oVoucherSeller = (float)($fees['voucher_from_seller'] ?? 0);
    $oVoucherPlatform = (float)($fees['voucher_from_shopee'] ?? ($fees['voucher_from_platform'] ?? 0));
    $oCoinOffset = (float)($fees['coins'] ?? ($fees['coin_offset'] ?? 0));
    $oCommission = (float)($fees['commission_fee'] ?? 0);
    $oServiceFee = (float)($fees['service_fee'] ?? 0);
    $oTransactionFee = (float)($fees['seller_transaction_fee'] ?? ($fees['credit_card_transaction_fee'] ?? 0));
    $oShippingBuyer = (float)($fees['buyer_paid_shipping_fee'] ?? 0);
    $oShippingDiscount = (float)($fees['shipping_fee_discount_from_3pl'] ?? ($fees['shopee_shipping_rebate'] ?? 0));
    $oShippingSeller = (float)($fees['seller_shipping_discount'] ?? ($fees['final_shipping_fee'] ?? ($fees['actual_shipping_fee'] ?? 0)));
    $oEscrow = (float)($fees['escrow_amount'] ?? 0);
    $oSellerDue = (float)($fees['seller_due'] ?? 0);
    $oWithholdingTax = (float)($fees['withholding_tax'] ?? 0);
    $oCrossBorderTax = (float)($fees['cross_border_tax'] ?? 0);
    $oEscrowTax = (float)($fees['escrow_tax'] ?? 0);
    $oPaymentPromotion = (float)($fees['payment_promotion'] ?? 0);

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
                    @if($oWithholdingTax)
                    <tr><td class="text-secondary">Withholding Tax</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($oWithholdingTax), 2) }}</td></tr>
                    @endif
                    @if($oCrossBorderTax)
                    <tr><td class="text-secondary">Cross-border Tax</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($oCrossBorderTax), 2) }}</td></tr>
                    @endif
                    @if($oEscrowTax)
                    <tr><td class="text-secondary">Escrow Tax</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($oEscrowTax), 2) }}</td></tr>
                    @endif
                    @if($oPaymentPromotion)
                    <tr><td class="text-secondary">Payment Promotion</td><td class="text-right">{{ number_format($oPaymentPromotion, 2) }}</td></tr>
                    @endif
                    @if($oShippingDiscount)
                    <tr><td class="text-secondary">Shipping Rebate (3PL)</td><td class="text-right">{{ number_format($oShippingDiscount, 2) }}</td></tr>
                    @endif
                    @if($oShippingSeller)
                    <tr><td class="text-secondary">Final Shipping Fee</td><td class="text-right" style="color:#dc2626;">-{{ number_format(abs($oShippingSeller), 2) }}</td></tr>
                    @endif
                    @if($netAmount)
                    <tr style="border-top:2px solid var(--border);">
                        <td class="font-bold">Net to Seller (Escrow)</td>
                        <td class="text-right font-bold" style="font-size:1.1em; color:#16a34a;">{{ number_format($netAmount, 2) }}</td>
                    </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
    @if(!$oCommission && !$oServiceFee && !$oTransactionFee)
        <div class="text-secondary text-xs mt-12" style="padding:0 4px;">Fee details may not be available until the order is completed and settled by Shopee.</div>
    @endif
</div>
@else
<div class="card mt-16">
    <h3 class="section-title mt-0">Fees & Revenue Breakdown</h3>
    <div class="text-secondary">No escrow/fee data available yet — fees are typically fetched automatically after order completion.</div>
</div>
@endif

<x-invoice-request-card :invoice="$order->buyer_invoice" />

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

@if($isToPack)
{{-- Arrange Shipment modal --}}
<div id="spShipModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,.45); align-items:center; justify-content:center;">
    <div style="background:var(--bg, #fff); border-radius:12px; padding:24px; max-width:520px; width:95%; max-height:90vh; overflow-y:auto; box-shadow:0 8px 32px rgba(0,0,0,.2);">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0;">Arrange Shipment</h3>
            <button type="button" id="btnCloseShipShow" style="background:none; border:none; font-size:24px; cursor:pointer; color:inherit;">&times;</button>
        </div>
        <div style="margin-top:12px;">
            <div class="text-secondary text-sm">Order: <strong>{{ $order->order_sn }}</strong></div>
        </div>

        {{-- Step 1: Choose shipping type --}}
        <div id="shipStep1Show">
            <div class="text-sm" style="margin-top:10px;">How will this order be shipped?</div>
            <div style="display:flex; gap:10px; margin-top:16px;">
                <button type="button" class="btn secondary btnShipChoiceShow" data-type="dropoff" style="flex:1;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    Drop Off
                </button>
                <button type="button" class="btn btnShipChoiceShow" data-type="pickup" style="flex:1;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                    Pickup
                </button>
            </div>
        </div>

        {{-- Step 2: Address/branch selection --}}
        <div id="shipStep2Show" style="display:none;">
            <div id="shipStep2LoadingShow" style="display:flex; align-items:center; gap:10px; margin-top:12px;">
                <div style="width:16px; height:16px; border:3px solid #cbd5e1; border-top-color:#EE4D2D; border-radius:50%; animation:spSpinShow 0.8s linear infinite;"></div>
                <span class="text-secondary text-sm">Loading addresses…</span>
            </div>
            <div id="shipStep2ContentShow" style="display:none; margin-top:12px;"></div>
            <div style="display:flex; gap:10px; margin-top:16px;">
                <button type="button" id="btnShipBackShow" class="btn secondary" style="flex:0;">&larr; Back</button>
                <button type="button" id="btnShipConfirmShow" class="btn" style="flex:1; background:#EE4D2D; border-color:#EE4D2D; color:#fff;" disabled>Confirm &amp; Ship</button>
            </div>
        </div>

        <form method="POST" id="formShipOrderShow" action="{{ route('ext.shopee.orders.ship', ['orderSn' => $order->order_sn]) }}" style="display:none;">
            @csrf
            <input type="hidden" name="shipping_type" id="shipTypeInputShow" value="">
            <input type="hidden" name="address_id" id="shipAddressInputShow" value="">
            <input type="hidden" name="branch_id" id="shipBranchInputShow" value="">
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var shipModal = document.getElementById('spShipModal');
    var btnOpen = document.getElementById('btnArrangeShipShow');
    var btnClose = document.getElementById('btnCloseShipShow');
    var shipForm = document.getElementById('formShipOrderShow');
    var shipTypeInput = document.getElementById('shipTypeInputShow');
    var shipAddressInput = document.getElementById('shipAddressInputShow');
    var shipBranchInput = document.getElementById('shipBranchInputShow');
    var shipStep1 = document.getElementById('shipStep1Show');
    var shipStep2 = document.getElementById('shipStep2Show');
    var shipStep2Loading = document.getElementById('shipStep2LoadingShow');
    var shipStep2Content = document.getElementById('shipStep2ContentShow');
    var btnConfirm = document.getElementById('btnShipConfirmShow');
    var btnBack = document.getElementById('btnShipBackShow');
    var currentShipType = '';
    var isShipping = false;

    if (!btnOpen || !shipModal) return;

    function resetModal() {
        shipStep1.style.display = '';
        shipStep2.style.display = 'none';
        shipStep2Loading.style.display = 'flex';
        shipStep2Content.style.display = 'none';
        shipStep2Content.innerHTML = '';
        shipAddressInput.value = '';
        shipBranchInput.value = '';
        btnConfirm.disabled = true;
        currentShipType = '';
    }

    btnOpen.addEventListener('click', function () {
        resetModal();
        shipModal.style.display = 'flex';
    });

    btnClose.addEventListener('click', function () {
        if (!isShipping) shipModal.style.display = 'none';
    });

    shipModal.addEventListener('click', function (e) {
        if (e.target === shipModal && !isShipping) shipModal.style.display = 'none';
    });

    document.querySelectorAll('.btnShipChoiceShow').forEach(function (el) {
        el.addEventListener('click', function () {
            currentShipType = el.getAttribute('data-type');
            shipTypeInput.value = currentShipType;
            shipStep1.style.display = 'none';
            shipStep2.style.display = '';
            shipStep2Loading.style.display = 'flex';
            shipStep2Content.style.display = 'none';

            fetch('/shopee/orders/' + encodeURIComponent('{{ $order->order_sn }}') + '/shipping-addresses', {
                headers: { 'Accept': 'application/json' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                shipStep2Loading.style.display = 'none';
                shipStep2Content.style.display = '';

                if (!data.ok) {
                    shipStep2Content.innerHTML = '<div class="alert danger">' + (data.message || 'Failed to load addresses.') + '</div>';
                    return;
                }

                var html = '';
                if (currentShipType === 'pickup') {
                    var addresses = (data.pickup && data.pickup.address_list) ? data.pickup.address_list : [];
                    if (addresses.length === 0) {
                        shipStep2Content.innerHTML = '<div class="text-secondary">No pickup addresses available.</div>';
                        return;
                    }

                    var autoAddr = null;
                    addresses.forEach(function (addr) {
                        if (!autoAddr && (addr.address_flag || []).indexOf('pickup_address') !== -1) {
                            autoAddr = addr;
                        }
                    });
                    if (!autoAddr) autoAddr = addresses[0];

                    shipAddressInput.value = autoAddr.address_id || 0;
                    var addrParts = [autoAddr.address, autoAddr.city, autoAddr.state, autoAddr.district].filter(function(p) { return p && p !== ''; });
                    var flags = (autoAddr.address_flag || []);

                    html += '<div class="text-sm font-bold" style="margin-bottom:8px;">Pickup address (auto-selected):</div>';
                    html += '<div style="padding:10px 12px; border:2px solid #16a34a; border-radius:8px; background:#16a34a0a;">';
                    html += '<div class="text-sm">' + addrParts.join(', ') + '</div>';
                    if (flags.length > 0) {
                        html += '<div style="margin-top:4px;">';
                        flags.forEach(function (f) {
                            var color = f === 'pickup_address' ? '#16a34a' : (f === 'return_address' ? '#2563eb' : '#6b7280');
                            html += '<span style="display:inline-block; font-size:11px; padding:1px 6px; border-radius:4px; background:' + color + '18; color:' + color + '; margin-right:4px;">' + f.replace(/_/g, ' ') + '</span>';
                        });
                        html += '</div>';
                    }
                    html += '</div>';
                } else {
                    var branches = (data.dropoff && data.dropoff.branch_list) ? data.dropoff.branch_list : [];
                    if (branches.length === 0) {
                        shipStep2Content.innerHTML = '<div class="text-secondary">No drop-off branches available. The order will be shipped without branch selection.</div>';
                        btnConfirm.disabled = false;
                        return;
                    }
                    html += '<div class="text-sm font-bold" style="margin-bottom:8px;">Select drop-off branch:</div>';
                    branches.forEach(function (branch, i) {
                        var checked = i === 0 ? ' checked' : '';
                        var addrParts = [branch.address, branch.city, branch.state, branch.district].filter(function(p) { return p && p !== ''; });

                        html += '<label style="display:block; padding:10px 12px; border:2px solid var(--border, #e2e8f0); border-radius:8px; cursor:pointer; margin-bottom:8px;">';
                        html += '<div style="display:flex; align-items:flex-start; gap:10px;">';
                        html += '<input type="radio" name="ship_branch_show" value="' + (branch.branch_id || 0) + '"' + checked + ' style="margin-top:3px;">';
                        html += '<div style="flex:1;">';
                        html += '<div class="text-sm">' + addrParts.join(', ') + '</div>';
                        html += '</div></div></label>';

                        if (i === 0) shipBranchInput.value = branch.branch_id || 0;
                    });
                }

                shipStep2Content.innerHTML = html;
                btnConfirm.disabled = false;

                shipStep2Content.querySelectorAll('input[type=radio]').forEach(function (radio) {
                    radio.addEventListener('change', function () {
                        shipBranchInput.value = radio.value;
                    });
                });
            })
            .catch(function () {
                shipStep2Loading.style.display = 'none';
                shipStep2Content.style.display = '';
                shipStep2Content.innerHTML = '<div class="alert danger">Network error loading addresses.</div>';
            });
        });
    });

    btnBack.addEventListener('click', function () {
        shipStep1.style.display = '';
        shipStep2.style.display = 'none';
    });

    btnConfirm.addEventListener('click', function () {
        btnConfirm.disabled = true;
        isShipping = true;

        // Capture form data BEFORE replacing modal content
        var csrfToken = document.querySelector('meta[name="csrf-token"]');
        if (!csrfToken) csrfToken = shipForm.querySelector('input[name="_token"]');
        var token = csrfToken ? (csrfToken.content || csrfToken.value) : '';
        var formData = new FormData(shipForm);
        var formAction = shipForm.action;
        var orderSn = '{{ $order->order_sn }}';

        // Replace modal content with loading spinner
        var modalInner = shipModal.querySelector('div > div') || shipModal.firstElementChild;
        modalInner.innerHTML = '<div style="text-align:center; padding:40px 20px;">' +
            '<div style="width:48px; height:48px; border:4px solid #cbd5e1; border-top-color:#EE4D2D; border-radius:50%; animation:spSpinShow 0.8s linear infinite; margin:0 auto;"></div>' +
            '<div id="shipLoadingMsg" class="font-bold" style="margin-top:16px;">Shipping order…</div>' +
            '<div class="text-xs text-secondary" style="margin-top:4px;">Please keep this tab open.</div>' +
            '</div>';

        fetch(formAction, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
            body: formData
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok) {
                modalInner.innerHTML = '<div style="text-align:center; padding:40px 20px;">' +
                    '<div style="font-size:32px; margin-bottom:12px;">&#9888;</div>' +
                    '<div class="font-bold" style="color:#dc2626;">Ship failed</div>' +
                    '<div class="text-sm text-secondary" style="margin-top:8px;">' + (data.message || 'Unknown error') + '</div>' +
                    '<button type="button" class="btn secondary" style="margin-top:16px;" onclick="location.reload()">Close</button>' +
                    '</div>';
                return;
            }

            // Ship succeeded — poll for AWB
            var msg = document.getElementById('shipLoadingMsg');
            if (msg) msg.textContent = 'Generating AWB…';

            var awbUrl = '/shopee/orders/' + encodeURIComponent(orderSn) + '/awb';
            var awbAttempts = 0;
            var maxAttempts = 20;

            function pollAwb() {
                awbAttempts++;
                fetch(awbUrl, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (awbData) {
                    if (awbData.ok && awbData.ready) {
                        shipModal.style.display = 'none';
                        window.open(awbUrl, '_blank');
                        location.reload();
                    } else if (awbAttempts < maxAttempts) {
                        setTimeout(pollAwb, 3000);
                    } else {
                        modalInner.innerHTML = '<div style="text-align:center; padding:40px 20px;">' +
                            '<div style="font-size:32px; margin-bottom:12px;">&#9888;</div>' +
                            '<div class="font-bold">Order shipped successfully</div>' +
                            '<div class="text-sm text-secondary" style="margin-top:8px;">AWB is still being generated by Shopee. Use the Print AWB button to try again.</div>' +
                            '<button type="button" class="btn" style="margin-top:16px;" onclick="location.reload()">OK</button>' +
                            '</div>';
                    }
                })
                .catch(function () {
                    if (awbAttempts < maxAttempts) {
                        setTimeout(pollAwb, 3000);
                    } else {
                        modalInner.innerHTML = '<div style="text-align:center; padding:40px 20px;">' +
                            '<div style="font-size:32px; margin-bottom:12px;">&#9888;</div>' +
                            '<div class="font-bold">Order shipped successfully</div>' +
                            '<div class="text-sm text-secondary" style="margin-top:8px;">Could not generate AWB. Use the Print AWB button to try again.</div>' +
                            '<button type="button" class="btn" style="margin-top:16px;" onclick="location.reload()">OK</button>' +
                            '</div>';
                    }
                });
            }

            setTimeout(pollAwb, 2000);
        })
        .catch(function () {
            modalInner.innerHTML = '<div style="text-align:center; padding:40px 20px;">' +
                '<div style="font-size:32px; margin-bottom:12px;">&#9888;</div>' +
                '<div class="font-bold" style="color:#dc2626;">Network error</div>' +
                '<div class="text-sm text-secondary" style="margin-top:8px;">Failed to connect. Please try again.</div>' +
                '<button type="button" class="btn secondary" style="margin-top:16px;" onclick="location.reload()">Close</button>' +
                '</div>';
        });
    });
});
</script>
@endif

{{-- Logistics trace modal --}}
<div id="spLogisticsModal" class="modal-backdrop">
    <div class="modal" style="max-width:720px;">
        <div class="modal-header">
            <h3>Logistics Trace</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('spLogisticsModal').classList.remove('active')">&times;</button>
        </div>
        <div id="spLogisticsBody" class="text-xs mt-16">
            <div class="text-secondary">Loading…</div>
        </div>
        <div class="d-flex gap-10 justify-end mt-16">
            <button type="button" class="btn secondary" onclick="document.getElementById('spLogisticsModal').classList.remove('active')">Close</button>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('spLogisticsModal');
    var body = document.getElementById('spLogisticsBody');
    var btn = document.getElementById('btnLogisticsTrace');
    if (!btn || !modal || !body) return;

    modal.addEventListener('click', function (e) { if (e.target === modal) modal.classList.remove('active'); });

    function fmtTime(ts) {
        if (!ts) return '';
        var d = new Date(typeof ts === 'number' ? ts * 1000 : ts);
        if (isNaN(d.getTime())) return String(ts);
        return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')+' '+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0')+':'+String(d.getSeconds()).padStart(2,'0');
    }

    function renderTrace(data) {
        var events = data.tracking_info || [];
        var status = data.logistics_status || '';
        var trackNum = data.tracking_number || '';
        var carrier = data.shipping_carrier || '';

        body.textContent = '';

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
        if (carrier) {
            var scDiv = document.createElement('div');
            scDiv.className = 'mb-8';
            var scLabel = document.createElement('strong');
            scLabel.textContent = 'Courier: ';
            var scValue = document.createElement('span');
            scValue.textContent = carrier;
            scDiv.appendChild(scLabel);
            scDiv.appendChild(scValue);
            body.appendChild(scDiv);
        }

        if (!events.length) {
            if (!trackNum) {
                var emptyDiv = document.createElement('div');
                emptyDiv.className = 'text-secondary';
                emptyDiv.textContent = 'No tracking events available.';
                body.appendChild(emptyDiv);
            }
            return;
        }

        events.sort(function (a, b) { return (b.update_time || b.ctime || 0) - (a.update_time || a.ctime || 0); });

        if (status) {
            var statusDiv = document.createElement('div');
            statusDiv.className = 'text-xs text-secondary';
            statusDiv.style.marginBottom = '12px';
            statusDiv.textContent = 'Logistics status: ' + status;
            body.appendChild(statusDiv);
        }

        var wrap = document.createElement('div');
        wrap.className = 'table-wrap';
        var table = document.createElement('table');
        table.className = 'table';
        var thead = document.createElement('thead');
        var hr = document.createElement('tr');
        ['Time', 'Status', 'Description'].forEach(function (h) {
            var th = document.createElement('th'); th.textContent = h;
            if (h === 'Time') th.style.width = '160px';
            hr.appendChild(th);
        });
        thead.appendChild(hr);
        table.appendChild(thead);
        var tbody = document.createElement('tbody');
        events.forEach(function (ev) {
            var tr = document.createElement('tr');
            var tdTime = document.createElement('td'); tdTime.textContent = fmtTime(ev.update_time || ev.ctime);
            var tdStatus = document.createElement('td'); tdStatus.className = 'font-bold'; tdStatus.textContent = ev.logistics_status || '';
            var tdDesc = document.createElement('td'); tdDesc.textContent = ev.description || '';
            tr.appendChild(tdTime); tr.appendChild(tdStatus); tr.appendChild(tdDesc);
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        wrap.appendChild(table);
        body.appendChild(wrap);
    }

    btn.addEventListener('click', function () {
        modal.classList.add('active');
        body.textContent = 'Loading…';
        fetch(btn.dataset.url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.ok) throw new Error(j.message || 'Failed to fetch');
                renderTrace(j);
            })
            .catch(function (e) {
                body.textContent = '';
                var err = document.createElement('div');
                err.style.color = 'var(--danger)';
                err.textContent = e.message || 'Failed to fetch tracking info';
                body.appendChild(err);
            });
    });
})();
</script>
@endsection
