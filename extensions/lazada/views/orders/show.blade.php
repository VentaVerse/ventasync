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

    $addr = $detail['address_shipping'] ?? $raw['address_shipping'] ?? $detail['shipping_address'] ?? $raw['shipping_address'] ?? [];
    if (!is_array($addr)) $addr = [];

    $receiverName = (string)($addr['first_name'] ?? $addr['customer_name'] ?? $detail['receiver_name'] ?? '');
    if ($receiverName === '') { $receiverName = $buyer; }

    $phone = (string)($addr['phone'] ?? $addr['phone2'] ?? '');
    $address1 = (string)($addr['address1'] ?? $addr['address'] ?? '');
    $address2 = (string)($addr['address2'] ?? '');
    $city = (string)($addr['city'] ?? '');
    $province = (string)($addr['province'] ?? $addr['state'] ?? '');
    $postcode = (string)($addr['post_code'] ?? $addr['postcode'] ?? '');
    $country = (string)($addr['country'] ?? '');

    $courier = $detail['shipping_provider'] ?? $detail['shipping_provider_type'] ?? $detail['shipping_provider_name'] ?? $raw['shipping_provider'] ?? $raw['shipping_provider_type'] ?? $raw['shipping_provider_name'] ?? null;
    if (is_array($courier)) $courier = implode(', ', $courier);
    $courier = is_string($courier) ? trim($courier) : '';

    $tracking = $detail['tracking_code'] ?? $detail['tracking_number'] ?? $raw['tracking_code'] ?? $raw['tracking_number'] ?? null;
    if (is_array($tracking)) $tracking = implode(', ', $tracking);
    $tracking = is_string($tracking) ? trim($tracking) : '';


    $items = $order->products ?? collect();

    // Fallback: get courier and tracking from first item if order-level is empty
    if (($courier === '' || $tracking === '') && $items->count() > 0) {
        foreach ($items as $_it) {
            $_ir = is_array($_it->raw ?? null) ? $_it->raw : [];
            if ($courier === '') {
                $_c = $_ir['shipping_provider'] ?? ($_ir['shipment_provider'] ?? '');
                if (is_string($_c) && trim($_c) !== '') $courier = trim($_c);
            }
            if ($tracking === '') {
                $_t = $_ir['tracking_code'] ?? ($_ir['tracking_code_pre'] ?? '');
                if (is_string($_t) && trim($_t) !== '') $tracking = trim($_t);
            }
            if ($courier !== '' && $tracking !== '') break;
        }
    }

    $statusLower = strtolower(trim($status));
    $badgeClass = match($statusLower) {
        'delivered', 'completed' => 'badge-green',
        'canceled', 'cancelled' => 'badge-red',
        'unpaid' => 'badge-yellow',
        'shipped', 'ready_to_ship_pending' => 'badge-blue',
        default => '',
    };
@endphp

<div class="page-header">
    <div>
        <div class="d-flex gap-10 items-center flex-wrap">
            <h2 style="margin:0;">Lazada Order</h2>
            <span class="text-secondary text-xs">Order #</span>
            <span class="font-bold">{{ $order->order_id }}</span>
            @if($status !== '')
                <span class="badge {{ $badgeClass }}" style="margin-left:6px;">{{ $status }}</span>
            @endif
        </div>
        <div class="meta-bar" style="margin-top:8px; margin-bottom:0; padding:8px 0; background:transparent; border:none;">
            @if($created) <div class="meta-item"><span class="meta-label">Create Time:</span> {{ $created }}</div> @endif
            @if($updated) <div class="meta-item"><span class="meta-label">Update Time:</span> {{ $updated }}</div> @endif
        </div>
    </div>

    <div class="page-header-actions">
        <a href="{{ route('ext.lazada.orders.index') }}" class="btn secondary">← Back to Orders</a>
        <a href="{{ route('ext.lazada.orders.show', ['orderId' => $order->order_id, 'refresh' => 1]) }}" class="btn secondary">Refresh from Lazada</a>
    </div>
</div>

@if($api_error)
    <div class="alert warning">
        <strong>Note</strong>
        <div style="margin-top:6px;">Could not refresh from Lazada API. Showing cached data.</div>
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
                <a href="{{ route('ext.lazada.orders.awb', ['orderId' => $order->order_id]) }}" onclick="window.open(this.href, 'awb', 'width=900,height=700'); return false;" rel="noopener" class="btn" style="background:#0F146D; border-color:#0F146D; color:#fff;">AWB PDF</a>
                <button type="button" class="btn" style="background:#0F146D; border-color:#0F146D; color:#fff;" id="btnLogisticsTrace" data-url="{{ route('ext.lazada.orders.logistics_trace', ['orderId' => $order->order_id]) }}">Tracking</button>
            </div>
        </div>
    </div>
</div>

@php
    // Consolidate items by SKU + variation (Lazada returns each unit as a separate order_item_id)
    $consolidated = [];
    foreach ($items as $it) {
        $ir = is_array($it->raw ?? null) ? $it->raw : [];
        $img = (string)($it->image ?? ($ir['product_main_image'] ?? $ir['image'] ?? ''));
        $name = (string)($it->name ?? ($ir['name'] ?? $ir['product_name'] ?? ''));
        $sku = (string)($it->sku ?? ($ir['seller_sku'] ?? $ir['SellerSku'] ?? $ir['Sku'] ?? ''));
        $variation = (string)($it->variation ?? ($ir['variation'] ?? ''));
        $qty = (int)($it->quantity ?? ($ir['quantity'] ?? 1));
        if ($qty < 1) $qty = 1;
        $orderItemId = (string)($it->order_item_id ?? ($ir['order_item_id'] ?? $ir['orderItemId'] ?? ''));
        $itemPrice = (float)($it->item_price ?? ($ir['item_price'] ?? ($ir['price'] ?? 0)));
        $paidPrice = (float)($it->paid_price ?? ($ir['paid_price'] ?? $itemPrice));

        $groupKey = $sku . '||' . $name . '||' . $variation;

        if (isset($consolidated[$groupKey])) {
            $consolidated[$groupKey]['qty'] += $qty;
            $consolidated[$groupKey]['paid_total'] += $paidPrice;
            $consolidated[$groupKey]['order_item_ids'][] = $orderItemId;
        } else {
            $consolidated[$groupKey] = [
                'img' => $img,
                'name' => $name,
                'sku' => $sku,
                'variation' => $variation,
                'qty' => $qty,
                'unit_price' => $itemPrice,
                'paid_total' => $paidPrice,
                'order_item_ids' => [$orderItemId],
            ];
        }
    }
@endphp
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
                        <th style="width:70px;">Qty</th>
                        <th style="width:100px;">Unit Price</th>
                        <th style="width:100px;">Paid</th>
                        <th>Order Item IDs</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($consolidated as $ci)
                        <tr>
                            <td>
                                @if($ci['img'] !== '')
                                    <img src="{{ $ci['img'] }}" alt="" class="thumb" style="width:64px; height:64px;" onerror="this.style.display='none'">
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <div class="font-bold">{{ $ci['name'] !== '' ? $ci['name'] : '—' }}</div>
                                @if($ci['variation'] !== '')
                                    <div class="text-secondary text-xs" style="margin-top:4px;">{{ $ci['variation'] }}</div>
                                @endif
                            </td>
                            <td>{{ $ci['sku'] !== '' ? $ci['sku'] : '—' }}</td>
                            <td>{{ $ci['qty'] }}</td>
                            <td>{{ $ci['unit_price'] > 0 ? number_format($ci['unit_price'], 2) : '—' }}</td>
                            <td class="font-bold">{{ $ci['paid_total'] > 0 ? number_format($ci['paid_total'], 2) : '—' }}</td>
                            <td class="text-xs">{{ implode(', ', array_filter($ci['order_item_ids'])) ?: '—' }}</td>
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

    // Estimate net: item price minus all fee deductions
    $lNet = $lPaidTotal ?: $lSubtotal;
    if ($lCommission) $lNet += $lCommission;
    if ($lPaymentFee) $lNet += $lPaymentFee;
    if ($lShippingServiceCost) $lNet += $lShippingServiceCost;
    if (is_array($lOtherFees)) {
        foreach ($lOtherFees as $feeAmt) {
            if ((float) $feeAmt < 0) $lNet += (float) $feeAmt;
        }
    }

    // Per-item fee breakdown from raw item data
    $itemFees = [];
    foreach ($items as $it) {
        $ir = is_array($it->raw ?? null) ? $it->raw : [];
        $iName = (string)($it->name ?? ($ir['name'] ?? ($ir['product_name'] ?? '—')));
        $iSku = (string)($it->sku ?? ($ir['seller_sku'] ?? ($ir['SellerSku'] ?? '')));
        $iItemPrice = (float)($ir['item_price'] ?? ($ir['price'] ?? 0));
        $iPaidPrice = (float)($ir['paid_price'] ?? 0);
        $iShipping = (float)($ir['shipping_amount'] ?? ($ir['shipping_fee_original'] ?? 0));
        $iVoucherSeller = (float)($ir['voucher_seller'] ?? 0);
        $iVoucherPlatform = (float)($ir['voucher_platform'] ?? 0);
        $iShipDiscSeller = (float)($ir['shipping_fee_discount_seller'] ?? 0);
        $iShipDiscPlatform = (float)($ir['shipping_fee_discount_platform'] ?? 0);
        $iWallet = (float)($ir['wallet_credits'] ?? 0);
        $iShipService = (float)($ir['shipping_service_cost'] ?? 0);
        $iStatus = (string)($ir['status'] ?? ($it->status ?? ''));

        // Only include if there's meaningful data
        $hasData = ($iItemPrice != 0 || $iPaidPrice != 0 || $iVoucherSeller != 0 || $iVoucherPlatform != 0 || $iShipService != 0);
        if ($hasData) {
            $itemFees[] = [
                'name' => $iName,
                'sku' => $iSku,
                'item_price' => $iItemPrice,
                'paid_price' => $iPaidPrice,
                'shipping' => $iShipping,
                'voucher_seller' => $iVoucherSeller,
                'voucher_platform' => $iVoucherPlatform,
                'ship_disc_seller' => $iShipDiscSeller,
                'ship_disc_platform' => $iShipDiscPlatform,
                'wallet' => $iWallet,
                'ship_service' => $iShipService,
                'status' => $iStatus,
            ];
        }
    }
@endphp

<div class="card mt-16">
    <h3 class="section-title mt-0">Fees & Revenue Breakdown</h3>
    @if($hasFees)
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
                    @if($lNet && ($lCommission || $lPaymentFee || $lShippingServiceCost || !empty($lOtherFees)))
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

    @php
        $trxLines = $fees['transaction_lines'] ?? [];
    @endphp
    @if(!empty($trxLines))
    <div style="margin-top:20px;">
        <h4 style="margin:0 0 10px 0; font-size:0.95em;">Lazada Platform Fees ({{ count($trxLines) }} transactions)</h4>
        <div class="table-wrap">
            <table class="table" style="width:100%; font-size:0.85em;">
                <thead>
                    <tr>
                        <th>Fee Name</th>
                        <th style="width:80px;">Type</th>
                        <th style="width:120px;">SKU</th>
                        <th style="width:110px;">Amount</th>
                        <th style="width:130px;">Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($trxLines as $tl)
                    <tr>
                        <td>{{ $tl['fee_name'] ?: ('Fee Type ' . $tl['fee_type']) }}</td>
                        <td class="text-xs text-secondary">{{ $tl['fee_type'] ?: '—' }}</td>
                        <td class="text-xs">{{ $tl['sku'] ?: ($tl['order_item_id'] ?: '—') }}</td>
                        <td class="text-right font-bold" style="{{ $tl['amount'] < 0 ? 'color:#dc2626;' : '' }}">{{ $tl['amount'] < 0 ? '-' : '' }}{{ number_format(abs($tl['amount']), 2) }}</td>
                        <td class="text-xs text-secondary">{{ $tl['transaction_date'] ?: '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if(!empty($itemFees))
    <details style="margin-top:16px;">
        <summary style="cursor:pointer;" class="font-bold text-sm">Per-Item Price Breakdown ({{ count($itemFees) }} items)</summary>
        <div class="table-wrap" style="margin-top:10px;">
            <table class="table" style="width:100%; font-size:0.85em;">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th style="width:90px;">Item Price</th>
                        <th style="width:90px;">Paid Price</th>
                        <th style="width:90px;">Voucher (S)</th>
                        <th style="width:90px;">Voucher (P)</th>
                        <th style="width:90px;">Shipping</th>
                        <th style="width:100px;">Ship Svc Cost</th>
                        <th style="width:80px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($itemFees as $if)
                    <tr>
                        <td>
                            <div>{{ $if['name'] }}</div>
                            @if($if['sku'] !== '')
                                <div class="text-xs text-secondary">{{ $if['sku'] }}</div>
                            @endif
                        </td>
                        <td class="text-right">{{ $if['item_price'] ? number_format($if['item_price'], 2) : '—' }}</td>
                        <td class="text-right font-bold">{{ $if['paid_price'] ? number_format($if['paid_price'], 2) : '—' }}</td>
                        <td class="text-right" style="{{ $if['voucher_seller'] ? 'color:#dc2626;' : '' }}">{{ $if['voucher_seller'] ? '-' . number_format(abs($if['voucher_seller']), 2) : '—' }}</td>
                        <td class="text-right" style="{{ $if['voucher_platform'] ? 'color:#dc2626;' : '' }}">{{ $if['voucher_platform'] ? '-' . number_format(abs($if['voucher_platform']), 2) : '—' }}</td>
                        <td class="text-right">{{ $if['shipping'] ? number_format($if['shipping'], 2) : '—' }}</td>
                        <td class="text-right" style="{{ $if['ship_service'] ? 'color:#dc2626;' : '' }}">{{ $if['ship_service'] ? '-' . number_format(abs($if['ship_service']), 2) : '—' }}</td>
                        <td class="text-xs">{{ $if['status'] ?: '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
    @endif

    @else
    <div class="text-secondary">No fee data available yet — fees are typically fetched automatically after order completion.</div>
    @endif
</div>

<div class="card mt-16">
    <h3 class="section-title mt-0">Raw Payload (debug)</h3>
    <details>
        <summary style="cursor:pointer;" class="font-bold">Show raw JSON</summary>
        <pre class="pre" style="margin-top:10px; white-space:pre-wrap; word-break:break-word;">{{ json_encode(['order' => $raw, 'detail' => $detail, 'fees' => $fees], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
    </details>
</div>

{{-- Logistics trace modal --}}
<div id="lzLogisticsModal" class="modal-backdrop">
    <div class="modal" style="max-width:720px;">
        <div class="modal-header">
            <h3>Logistics Trace</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('lzLogisticsModal').classList.remove('active')">&times;</button>
        </div>
        <div id="lzLogisticsBody" class="text-xs mt-16">
            <div class="text-secondary">Loading…</div>
        </div>
        <div class="d-flex gap-10 justify-end mt-16">
            <button type="button" class="btn secondary" onclick="document.getElementById('lzLogisticsModal').classList.remove('active')">Close</button>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('lzLogisticsModal');
    var body = document.getElementById('lzLogisticsBody');
    var btn = document.getElementById('btnLogisticsTrace');
    if (!btn || !modal || !body) return;

    modal.addEventListener('click', function (e) { if (e.target === modal) modal.classList.remove('active'); });

    function esc(s) {
        return String(s || '').replace(/[&<>"']/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[c] || c;
        });
    }

    function fmtTime(t) {
        if (!t) return '';
        if (typeof t === 'number' && t > 1e12) { var d = new Date(t); return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')+' '+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0')+':'+String(d.getSeconds()).padStart(2,'0'); }
        if (typeof t === 'number' && t > 1e9) return fmtTime(t * 1000);
        return String(t);
    }

    var lazTrackingNo = @json($tracking);
    var lazCourier = @json($courier);

    function renderTrace(respBody) {
        var data = (respBody && respBody.data) ? respBody.data : respBody;
        var traces = [];
        if (data) {
            traces = data.traces || data.trace_list || data.traceList || data.events || [];
            if ((!Array.isArray(traces) || !traces.length) && Array.isArray(data.packages)) {
                data.packages.forEach(function (p) { traces = traces.concat(p.package_trace_list || p.traceList || []); });
            }
            if ((!Array.isArray(traces) || !traces.length) && data.result && Array.isArray(data.result.module)) {
                data.result.module.forEach(function (m) {
                    (m.package_detail_info_list || []).forEach(function (p) { traces = traces.concat(p.logistic_detail_info_list || []); });
                });
            }
        }

        body.textContent = '';

        if (lazTrackingNo) {
            var tnDiv = document.createElement('div');
            tnDiv.className = 'mb-8';
            var tnLabel = document.createElement('strong');
            tnLabel.textContent = 'Tracking: ';
            var tnValue = document.createElement('span');
            tnValue.textContent = lazTrackingNo;
            tnDiv.appendChild(tnLabel);
            tnDiv.appendChild(tnValue);
            body.appendChild(tnDiv);
        }
        if (lazCourier) {
            var scDiv = document.createElement('div');
            scDiv.className = 'mb-8';
            var scLabel = document.createElement('strong');
            scLabel.textContent = 'Courier: ';
            var scValue = document.createElement('span');
            scValue.textContent = lazCourier;
            scDiv.appendChild(scLabel);
            scDiv.appendChild(scValue);
            body.appendChild(scDiv);
        }

        if (!Array.isArray(traces) || !traces.length) {
            if (!lazTrackingNo) {
                var emptyDiv = document.createElement('div');
                emptyDiv.className = 'text-secondary';
                emptyDiv.textContent = 'No logistics events returned.';
                body.appendChild(emptyDiv);
            }
            return;
        }

        // Build table using DOM methods (safe from XSS)
        var wrap = document.createElement('div');
        wrap.className = 'table-wrap';
        var table = document.createElement('table');
        table.className = 'table';
        var thead = document.createElement('thead');
        var hr = document.createElement('tr');
        ['Time', 'Status', 'Details'].forEach(function (h) { var th = document.createElement('th'); th.textContent = h; if (h === 'Time') th.style.width = '160px'; hr.appendChild(th); });
        thead.appendChild(hr);
        table.appendChild(thead);
        var tbody = document.createElement('tbody');
        traces.forEach(function (t) {
            var tr = document.createElement('tr');
            var tdTime = document.createElement('td'); tdTime.textContent = fmtTime(t.event_time||t.time||t.timestamp||t.update_time||'');
            var tdStatus = document.createElement('td'); tdStatus.className = 'font-bold'; tdStatus.textContent = t.title||t.status||t.event||t.action||'';
            var tdDetail = document.createElement('td'); tdDetail.textContent = t.description||t.desc||t.detail||t.message||'';
            tr.appendChild(tdTime); tr.appendChild(tdStatus); tr.appendChild(tdDetail);
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
                if (!j || j.ok !== true) throw new Error((j && j.body && j.body.message) || (j && j.message) || 'Failed to fetch');
                renderTrace(j.body);
            })
            .catch(function (e) {
                body.textContent = '';
                var err = document.createElement('div');
                err.style.color = 'var(--danger)';
                err.textContent = e.message || 'Failed to fetch logistics trace';
                body.appendChild(err);
            });
    });
})();
</script>
@endsection
