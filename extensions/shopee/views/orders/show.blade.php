@extends('layouts.channel')
@section('title', 'Order ' . $order->order_sn)
@section('breadcrumb', $order->order_sn)

{{-- Leaves the .x-legacy content boundary, the same exit the Orders and
     Returns pages took: this page is built directly on the design tokens,
     the x-ui components and the shared `od-` detail anatomy. --}}
@section('content-class', '')

@section('content')
@php
    // Wire-format truth is ShopeeOrderController@show. Every extraction
    // below is the expression the previous version of this page used, moved
    // unchanged: Shopee's payload is inconsistent across API versions and
    // each `??` chain is a fallback someone hit in production.
    $raw = is_array($order->raw ?? null) ? $order->raw : [];

    $status = $order->status ?? ($raw['order_status'] ?? '');
    $statusStr = is_string($status) ? $status : '';
    $statusNorm = strtoupper(trim($statusStr));

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
    $addrLine2 = trim(trim($city . ' ' . $state) . ' ' . $zipcode . ' ' . $country);

    $courier = (string)($raw['shipping_carrier'] ?? ($raw['checkout_shipping_carrier'] ?? ''));
    $tracking = (string)($raw['tracking_no'] ?? ($raw['tracking_number'] ?? ''));

    $items = $order->products ?? collect();

    $isToPack = ($statusNorm === 'READY_TO_SHIP');
    // The waybill and the tracking trace exist once Shopee has a parcel to
    // talk about. Same four statuses the previous version of this page used.
    $hasParcel = in_array($statusNorm, ['PROCESSED', 'SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED'], true);

    // Phase 4 - Shopee order detail: refresh-from-Shopee and Arrange
    // Shipment are write-tier actions (refresh re-pulls from the API and
    // persists, ship triggers a marketplace state change). The route
    // middleware is the real gate (perm:manage_shopee_orders on the ship
    // POST); this flag only stops the page offering what would 403.
    $canManageShopeeOrders = auth()->user()?->hasPermission('manage_shopee_orders') ?? false;

    // Tone and label from the same maps the orders list uses, so a status
    // means the same colour and reads the same words on both surfaces. No
    // marketplace constant reaches the screen raw.
    $tone = \App\Support\ChannelStatusTone::toneFor('shopee.orders', $statusStr);
    $statusLabel = \App\Support\ChannelStatusTone::labelFor('shopee.orders', $statusStr);

    // Currency is resolved through App\Support\Money, which reads the symbol
    // from the currencies table, so no symbol is written into this view. An
    // order carrying a code nobody has configured locally falls back to
    // "CODE 1,234.00" rather than a wrong glyph. Same resolution the orders
    // list already applies to this order's own amount.
    $currencyCode = (string) ($raw['currency'] ?? '');
    $money = fn ($amount) => $currencyCode !== ''
        ? \App\Support\Money::foreign((float) $amount, $currencyCode)
        : \App\Support\Money::base((float) $amount);

    $orderTotal = $raw['total_amount'] ?? $raw['escrow_amount'] ?? null;
    $paymentMethod = (string) ($raw['payment_method'] ?? '');

    $returns = $returns ?? collect();
@endphp

<div id="shopee-order-detail-page" class="od-page">

    <a class="od-back" href="{{ route('ext.shopee.orders.index') }}">
        <x-ui.icon name="chevron-left" size="14" />
        Orders
    </a>

    <header class="od-head">
        <div class="od-head__id">
            <h1 class="x-page-title od-title">{{ $order->order_sn }}</h1>
            @if($statusStr !== '')
                <x-ui.badge :tone="$tone">{{ $statusLabel }}</x-ui.badge>
            @endif
        </div>
        @if($canManageShopeeOrders)
        <div class="od-head__actions">
            <x-ui.button :href="route('ext.shopee.orders.show', ['orderSn' => $order->order_sn, 'refresh' => 1])">Refresh from Shopee</x-ui.button>
            @if($isToPack)
                <x-ui.button variant="primary" class="btnArrangeShipment" data-order-sn="{{ $order->order_sn }}">Arrange shipment</x-ui.button>
            @endif
        </div>
        @endif
    </header>

    <div class="od-stamps">
        @if($created)
        <span class="od-stamp">
            <span class="od-stamp__k">Created</span>
            <span class="od-stamp__v">{{ $created }}</span>
        </span>
        @endif
        @if($updated)
        <span class="od-stamp">
            <span class="od-stamp__k">Updated</span>
            <span class="od-stamp__v">{{ $updated }}</span>
        </span>
        @endif
        @if($order->catalog_order_id)
        <span class="od-stamp">
            <span class="od-stamp__k">Sales order</span>
            <a class="od-stamp__v" href="{{ url('/sales/orders/' . $order->catalog_order_id) }}">{{ $order->catalog_order_id }}</a>
        </span>
        @endif
    </div>

    @if($api_error)
        <div class="od-note od-note--warn">
            <div class="od-note__body">
                <strong>Could not refresh from Shopee.</strong>
                This is normal for older or completed orders. The figures below are the last ones synced.
            </div>
        </div>
    @endif

    <div class="od-body">
        {{-- ============ MAIN COLUMN ============ --}}
        <div class="od-col od-col--main">

            {{-- Items. Same `co-` item grammar as the fulfilment list (96px
                 image, two-line name, SKU and variation meta), in the `od-`
                 item grid so qty and price align down the page. Shopee
                 stores no line total, so this page's grid is four columns
                 rather than the core show's five. --}}
            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">
                        Items
                        @if($items->count())<span class="od-section__count">{{ $items->count() }}</span>@endif
                    </h2>
                </div>

                @if($items->count() === 0)
                    <p class="od-empty">No items synced yet. Items will be fetched on the next order sync.</p>
                @else
                <div class="od-items spd-items">
                    <div class="od-items__head" aria-hidden="true">
                        <span></span>
                        <span>Product</span>
                        <span>Qty</span>
                        <span>Price</span>
                    </div>

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
                        <div class="od-item">
                            <div class="order-img-wrap od-item__media">
                                @if($img !== '')
                                    <img class="order-img" src="{{ $img }}" alt="" loading="lazy" decoding="async">
                                @else
                                    <span class="co-item__none">No image</span>
                                @endif
                            </div>

                            <div class="co-item__body od-item__body">
                                <span class="co-item__name od-item__name">{{ $name !== '' ? $name : 'Unnamed product' }}</span>
                                <div class="co-item__meta">
                                    @if(trim($sku) !== '')
                                        <span class="co-item__sku">{{ $sku }}</span>
                                    @endif
                                    @if(trim($variation) !== '')
                                        <span class="co-item__var">{{ $variation }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="od-item__figs">
                                <span class="od-fig od-fig--qty"><span class="od-fig__k">Qty</span>{{ $qty }}</span>
                                <span class="od-fig od-fig--total">
                                    <span class="od-fig__k">Price</span>{{ $money($price) }}
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
                @endif
            </section>

            {{-- ============ INVOICE REQUEST ============
                 The same three states and the same five fields the shared
                 invoice card rendered, poured into this page's own grammar
                 (that component is built from the legacy .card chrome and raw
                 hex, which cannot follow this page out of .x-legacy). It sits
                 in the main column, where the previous version of this page
                 carried it: an invoice the seller has to issue is a document
                 about the order, not a reference fact about its delivery.
                 The copy buttons keep the [data-copy] contract
                 resources/js/invoice-copy.js binds. --}}
            @php
                $inv = is_array($order->buyer_invoice ?? null) ? $order->buyer_invoice : null;
                $invState = 'unavailable';
                if (is_array($inv)) {
                    if (($inv['is_requested'] ?? null) === true)       $invState = 'requested';
                    elseif (($inv['is_requested'] ?? null) === false)  $invState = 'declined';
                }
                $invName    = $inv ? (string) ($inv['name']  ?? '') : '';
                $invType    = $inv ? (string) ($inv['type']  ?? '') : '';
                $invTin     = $inv ? (string) ($inv['tin']   ?? '') : '';
                $invEmail   = $inv ? (string) ($inv['email'] ?? '') : '';
                $invPhone   = $inv ? (string) ($inv['phone'] ?? '') : '';
                $invAddress = $inv && is_array($inv['address'] ?? null) ? $inv['address'] : [];
                $invFull    = (string) ($invAddress['full'] ?? '');
            @endphp
            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">Invoice request</h2>
                    @if($invState === 'requested')
                        <x-ui.badge tone="warning">Requested by buyer</x-ui.badge>
                    @endif
                </div>

                @if($invState === 'requested')
                    <div class="spd-invoice">
                        <div class="od-fact">
                            <span class="od-fact__k">Buyer</span>
                            <span class="od-fact__v">{{ $invName !== '' ? $invName : 'Not recorded' }}</span>
                        </div>
                        @if($invType !== '')
                        <div class="od-fact">
                            <span class="od-fact__k">Type</span>
                            <span class="od-fact__v">{{ ucfirst($invType) }}</span>
                        </div>
                        @endif
                        <div class="od-fact spd-copyable">
                            <span class="od-fact__k">TIN</span>
                            <span class="od-fact__v {{ $invTin !== '' ? 'od-fact__v--mono' : '' }}">{{ $invTin !== '' ? $invTin : 'Not recorded' }}</span>
                            @if($invTin !== '')
                                <button type="button" class="spd-copy" data-copy="{{ $invTin }}">Copy</button>
                            @endif
                        </div>
                        <div class="od-fact spd-copyable">
                            <span class="od-fact__k">Email</span>
                            <span class="od-fact__v">{{ $invEmail !== '' ? $invEmail : 'Not recorded' }}</span>
                            @if($invEmail !== '')
                                <button type="button" class="spd-copy" data-copy="{{ $invEmail }}">Copy</button>
                            @endif
                        </div>
                        <div class="od-fact">
                            <span class="od-fact__k">Phone</span>
                            <span class="od-fact__v">{{ $invPhone !== '' ? $invPhone : 'Not recorded' }}</span>
                        </div>
                        <div class="od-fact spd-copyable spd-invoice__wide">
                            <span class="od-fact__k">Address</span>
                            <span class="od-fact__v">{{ $invFull !== '' ? $invFull : 'Not recorded' }}</span>
                            @if($invFull !== '')
                                <button type="button" class="spd-copy" data-copy="{{ $invFull }}">Copy</button>
                            @endif
                        </div>
                    </div>
                @elseif($invState === 'declined')
                    <p class="od-empty">Buyer did not request an invoice.</p>
                @else
                    <p class="od-empty">Invoice information not available from Shopee.</p>
                @endif
            </section>

            {{-- Returns and refunds. Full-width in the main column rather
                 than the side, because a return carries its own items and
                 its own negotiation history: it is a second subject, not a
                 reference fact about the first one. --}}
            @if($returns->count() > 0)
            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">
                        Returns and refunds
                        <span class="od-section__count">{{ $returns->count() }}</span>
                    </h2>
                </div>

                @foreach($returns as $ret)
                <div class="spd-return">
                    <div class="spd-return__head">
                        <div class="od-fact">
                            <span class="od-fact__k">Return SN</span>
                            <span class="od-fact__v od-fact__v--mono">{{ $ret->return_sn }}</span>
                        </div>
                        <div class="od-fact">
                            <span class="od-fact__k">Status</span>
                            <span class="od-fact__v">
                                @if($ret->status)
                                    <x-ui.badge :tone="\App\Support\ChannelStatusTone::toneFor('shopee.returns', $ret->status)">{{ \App\Support\ChannelStatusTone::labelFor('shopee.returns', $ret->status) }}</x-ui.badge>
                                @else
                                    <span class="co-empty-mark">Not recorded</span>
                                @endif
                            </span>
                        </div>
                        <div class="od-fact">
                            <span class="od-fact__k">Refund amount</span>
                            <span class="od-fact__v od-fact__v--mono od-neg">
                                {{ $ret->currency
                                    ? \App\Support\Money::foreign((float) $ret->refund_amount, (string) $ret->currency)
                                    : \App\Support\Money::base((float) $ret->refund_amount) }}
                            </span>
                        </div>
                        <div class="od-fact">
                            <span class="od-fact__k">Created</span>
                            <span class="od-fact__v {{ $ret->return_created_at ? 'od-fact__v--mono' : '' }}">{{ $ret->return_created_at ? $ret->return_created_at->format('Y-m-d H:i') : 'Not recorded' }}</span>
                        </div>
                        <div class="od-fact">
                            <span class="od-fact__k">Updated</span>
                            <span class="od-fact__v {{ $ret->return_updated_at ? 'od-fact__v--mono' : '' }}">{{ $ret->return_updated_at ? $ret->return_updated_at->format('Y-m-d H:i') : 'Not recorded' }}</span>
                        </div>
                    </div>

                    <div class="od-fact spd-return__reason">
                        <span class="od-fact__k">Reason</span>
                        <span class="od-fact__v">{{ $ret->reason ?: 'Not recorded' }}</span>
                        @if($ret->reason_text)
                            <span class="spd-return__note">{{ $ret->reason_text }}</span>
                        @endif
                    </div>

                    @php $retItems = is_array($ret->items) ? $ret->items : []; @endphp
                    @if(!empty($retItems))
                        <div class="spd-lines">
                            <div class="spd-lines__head" aria-hidden="true">
                                <span>Item</span>
                                <span>Qty</span>
                                <span>Price</span>
                            </div>
                            @foreach($retItems as $ri)
                                @php
                                    $ri = is_array($ri) ? $ri : [];
                                    $riName = $ri['name'] ?? ($ri['item_name'] ?? ($ri['model_name'] ?? 'Not recorded'));
                                    $riQty = $ri['quantity'] ?? ($ri['amount'] ?? 1);
                                    $riPrice = $ri['item_price'] ?? ($ri['price'] ?? 0);
                                @endphp
                                <div class="spd-lines__row">
                                    <span class="spd-lines__name">{{ $riName }}</span>
                                    <span class="spd-lines__num">{{ $riQty }}</span>
                                    <span class="spd-lines__num">{{ is_numeric($riPrice) ? $money($riPrice) : $riPrice }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @php $nego = is_array($ret->negotiation) ? $ret->negotiation : []; @endphp
                    @if(!empty($nego))
                        <details class="od-disclose spd-return__nego">
                            <summary>Negotiation history ({{ count($nego) }})</summary>
                            <div class="od-time">
                                @foreach($nego as $n)
                                    @php $n = is_array($n) ? $n : []; @endphp
                                    <div class="od-time__row">
                                        <span class="od-time__when">
                                            @if(!empty($n['create_time']))
                                                {{ date('Y-m-d H:i', is_numeric($n['create_time']) ? $n['create_time'] : strtotime($n['create_time'])) }}
                                            @endif
                                        </span>
                                        <div class="od-time__what">
                                            <div class="od-time__status">{{ $n['role'] ?? ($n['offer_by'] ?? 'Unknown') }}</div>
                                            @if(!empty($n['offer_amount']))
                                                <div class="od-time__who">Offer {{ $money($n['offer_amount']) }}</div>
                                            @endif
                                            @if(!empty($n['reason']))
                                                <div class="od-time__note">{{ $n['reason'] }}</div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif
                </div>
                @endforeach
            </section>
            @endif

            {{-- Raw payload. Kept from the previous version of this page:
                 when Shopee's response and this page disagree, this is what
                 settles it. It closes the main column rather than running
                 full width under the body, because the side column on this
                 page is three cards taller than the item list and a
                 full-width band below both left it marooned under a screen
                 of empty canvas. --}}
            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">Raw payload</h2>
                </div>
                <details class="od-disclose">
                    <summary>Show the JSON Shopee returned</summary>
                    <pre class="spd-raw">{{ json_encode($raw, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
                </details>
            </section>
        </div>

        {{-- ============ SIDE COLUMN ============ --}}
        <div class="od-col od-col--side">

            {{-- Customer and delivery address --}}
            <section class="od-card">
                <div class="od-card__head">
                    <h2 class="od-card__title">Customer</h2>
                </div>
                <div class="od-card__body">
                    <div class="od-fact">
                        <span class="od-fact__k">Buyer</span>
                        <span class="od-fact__v">{{ $buyer !== '' ? $buyer : 'Not recorded' }}</span>
                    </div>
                    <div class="od-fact">
                        <span class="od-fact__k">Delivery address</span>
                        <div class="od-lines">
                            @if($receiverName !== '')
                                <span class="od-lines__name">{{ $receiverName }}</span>
                            @endif
                            @if($phone !== '')<span>{{ $phone }}</span>@endif
                            <span>{{ $address1 !== '' ? $address1 : 'Not recorded' }}</span>
                            @if($addrLine2 !== '')<span>{{ $addrLine2 }}</span>@endif
                        </div>
                    </div>
                </div>
            </section>

            {{-- Payment. Both figures are the ones the fulfilment list already
                 prints for this same order, from the same payload keys. --}}
            @if(($orderTotal !== null && $orderTotal !== '') || $paymentMethod !== '')
            <section class="od-card">
                <div class="od-card__head">
                    <h2 class="od-card__title">Payment</h2>
                </div>
                <div class="od-card__body">
                    @if($orderTotal !== null && $orderTotal !== '')
                    {{-- "Order total", not "buyer paid": this is Shopee's
                         total_amount, which an UNPAID order carries before
                         any money has moved. A label the data does not
                         support is the one thing this page cannot print. --}}
                    <div class="od-paid">
                        <span class="od-paid__k">Order total</span>
                        <span class="od-paid__v">{{ is_numeric($orderTotal) ? $money($orderTotal) : $orderTotal }}</span>
                    </div>
                    @endif
                    @if($paymentMethod !== '')
                    <div class="od-fact">
                        <span class="od-fact__k">Method</span>
                        <span class="od-fact__v">{{ $paymentMethod }}</span>
                    </div>
                    @endif
                </div>
            </section>
            @endif

            {{-- Shipping --}}
            <section class="od-card">
                <div class="od-card__head">
                    <h2 class="od-card__title">Shipping</h2>
                </div>
                <div class="od-card__body">
                    <div class="od-fact">
                        <span class="od-fact__k">Courier</span>
                        <span class="od-fact__v">{{ $courier !== '' ? $courier : 'Not recorded' }}</span>
                    </div>
                    <div class="od-fact">
                        <span class="od-fact__k">Tracking number</span>
                        {{-- Mono is for the identifier, not for the words
                             that stand in when there is no identifier. --}}
                        <span class="od-fact__v {{ $tracking !== '' ? 'od-fact__v--mono' : '' }}">{{ $tracking !== '' ? $tracking : 'Not recorded' }}</span>
                    </div>
                </div>
                @if($hasParcel)
                <div class="od-card__foot spd-parcel">
                    <a class="x-btn x-btn--secondary x-btn--sm" href="{{ route('ext.shopee.orders.awb', ['orderSn' => $order->order_sn]) }}" target="_blank" rel="noopener">Print waybill</a>
                    <button type="button" class="btnShopeeTracking x-btn x-btn--secondary x-btn--sm"
                            data-url="{{ route('ext.shopee.orders.tracking_info', ['orderSn' => $order->order_sn]) }}">Tracking</button>
                </div>
                @endif
            </section>

            {{-- ============ ESCROW AND FEES ============
                 Shopee's settlement, not this system's arithmetic: nothing on
                 this page is derived from these lines, so they belong in the
                 side column as an independent block rather than as ledger
                 lines feeding a profit figure (that is the core Sales show's
                 shape, and it stays there). Every key, every fallback and
                 every "print only when non-zero" condition is the one the
                 previous version of this page used. --}}
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

                // The rule dividing the block: above it is what the buyer
                // paid and what came off that price, below it is what Shopee
                // keeps. It only earns its place when there is something on
                // both sides of it, which mirrors the two columns the
                // previous version of this page split these lines across.
                $feesPaidSide = $oOriginalPrice || $oBuyerTotal || $oSellerDiscount || $oPlatformDiscount
                    || $oVoucherSeller || $oVoucherPlatform || $oCoinOffset || $oShippingBuyer;
                $feesKeptSide = $oCommission || $oServiceFee || $oTransactionFee || $oWithholdingTax
                    || $oCrossBorderTax || $oEscrowTax || $oPaymentPromotion || $oShippingDiscount
                    || $oShippingSeller;
            @endphp
            <section class="od-card">
                <div class="od-card__head">
                    <h2 class="od-card__title">Escrow and fees</h2>
                </div>
                @if($hasFees)
                <div class="od-card__body spd-fees">
                    @if($oOriginalPrice)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Original price</span>
                        <span class="od-ledger__v">{{ $money($oOriginalPrice) }}</span>
                    </div>
                    @endif
                    @if($oBuyerTotal)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Buyer total</span>
                        <span class="od-ledger__v">{{ $money($oBuyerTotal) }}</span>
                    </div>
                    @endif
                    @if($oSellerDiscount)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Seller discount</span>
                        <span class="od-ledger__v od-neg">-{{ $money(abs($oSellerDiscount)) }}</span>
                    </div>
                    @endif
                    @if($oPlatformDiscount)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Platform discount</span>
                        <span class="od-ledger__v od-neg">-{{ $money(abs($oPlatformDiscount)) }}</span>
                    </div>
                    @endif
                    @if($oVoucherSeller)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Voucher (seller)</span>
                        <span class="od-ledger__v od-neg">-{{ $money(abs($oVoucherSeller)) }}</span>
                    </div>
                    @endif
                    @if($oVoucherPlatform)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Voucher (platform)</span>
                        <span class="od-ledger__v od-neg">-{{ $money(abs($oVoucherPlatform)) }}</span>
                    </div>
                    @endif
                    @if($oCoinOffset)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Coins</span>
                        <span class="od-ledger__v od-neg">-{{ $money(abs($oCoinOffset)) }}</span>
                    </div>
                    @endif
                    @if($oShippingBuyer)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Shipping (buyer paid)</span>
                        <span class="od-ledger__v">{{ $money($oShippingBuyer) }}</span>
                    </div>
                    @endif

                    @if($feesPaidSide && $feesKeptSide)
                    <hr class="od-ledger__split">
                    @endif

                    @if($oCommission)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Commission fee</span>
                        <span class="od-ledger__v od-neg">-{{ $money(abs($oCommission)) }}</span>
                    </div>
                    @endif
                    @if($oServiceFee)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Service fee</span>
                        <span class="od-ledger__v od-neg">-{{ $money(abs($oServiceFee)) }}</span>
                    </div>
                    @endif
                    @if($oTransactionFee)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Transaction fee</span>
                        <span class="od-ledger__v od-neg">-{{ $money(abs($oTransactionFee)) }}</span>
                    </div>
                    @endif
                    @if($oWithholdingTax)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Withholding tax</span>
                        <span class="od-ledger__v od-neg">-{{ $money(abs($oWithholdingTax)) }}</span>
                    </div>
                    @endif
                    @if($oCrossBorderTax)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Cross-border tax</span>
                        <span class="od-ledger__v od-neg">-{{ $money(abs($oCrossBorderTax)) }}</span>
                    </div>
                    @endif
                    @if($oEscrowTax)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Escrow tax</span>
                        <span class="od-ledger__v od-neg">-{{ $money(abs($oEscrowTax)) }}</span>
                    </div>
                    @endif
                    @if($oPaymentPromotion)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Payment promotion</span>
                        <span class="od-ledger__v">{{ $money($oPaymentPromotion) }}</span>
                    </div>
                    @endif
                    @if($oShippingDiscount)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Shipping rebate (3PL)</span>
                        <span class="od-ledger__v">{{ $money($oShippingDiscount) }}</span>
                    </div>
                    @endif
                    @if($oShippingSeller)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Final shipping fee</span>
                        <span class="od-ledger__v od-neg">-{{ $money(abs($oShippingSeller)) }}</span>
                    </div>
                    @endif

                    @if($netAmount)
                    <div class="od-ledger__row od-ledger__row--profit">
                        <span class="od-ledger__k">Net to seller</span>
                        <span class="od-ledger__v od-pos">{{ $money($netAmount) }}</span>
                    </div>
                    @endif
                </div>
                @if(!$oCommission && !$oServiceFee && !$oTransactionFee)
                <div class="od-card__foot spd-note">Fee details may not be available until the order is completed and settled by Shopee.</div>
                @endif
                @else
                <div class="od-card__body">
                    <p class="od-empty">No escrow or fee data yet. Fees are usually fetched automatically once Shopee completes the order.</p>
                </div>
                @endif
            </section>

        </div>
    </div>

</div>{{-- /#shopee-order-detail-page --}}

@include('ext-shopee::orders._modals')
@endsection
