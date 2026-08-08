@extends('layouts.channel')
@section('title', 'Order ' . ($order->order_id ?? ''))
@section('breadcrumb', $order->order_id ?? '')

{{-- Leaves the .x-legacy content boundary, the same exit the Orders page
     took: this page is built directly on the design tokens, the x-ui
     components and the shared `od-` detail anatomy. --}}
@section('content')
@php
    // Wire-format truth is TikTokOrderController@show, which passes exactly
    // two things: the order (with products) and $api_error. Every extraction
    // below is the expression the previous version of this page used, moved
    // unchanged.
    $raw = is_array($order->raw ?? null) ? $order->raw : [];
    $status = $order->status ?? '';
    $orderId = $order->order_id ?? '';

    $created = $order->order_created_at ? $order->order_created_at->format('Y-m-d H:i') : null;
    $updated = $order->order_updated_at ? $order->order_updated_at->format('Y-m-d H:i') : null;

    $buyer = (string)($raw['buyer_name'] ?? $raw['recipient_address']['name'] ?? $order->buyer_name ?? '');
    $phone = (string)($raw['recipient_address']['phone_number'] ?? $raw['recipient_address']['phone'] ?? '');
    $receiverName = (string)($raw['recipient_address']['name'] ?? $raw['recipient_address']['full_name'] ?? $buyer);
    $address = (string)($raw['recipient_address']['full_address'] ?? '');
    $city = (string)($raw['recipient_address']['city'] ?? '');
    $state = (string)($raw['recipient_address']['state'] ?? '');
    $zipcode = (string)($raw['recipient_address']['zipcode'] ?? $raw['recipient_address']['postal_code'] ?? '');
    $region = (string)($raw['recipient_address']['region_code'] ?? $raw['recipient_address']['region'] ?? '');
    $addrLine2 = trim(trim(trim($city . ' ' . $state) . ' ' . $zipcode) . ' ' . $region);

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

    $items = $order->products ?? collect();

    // The waybill and the tracking trace exist once TikTok has a parcel to
    // talk about. Same four statuses the previous version of this page used.
    $hasParcel = in_array($status, ['AWAITING_COLLECTION', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED'], true);

    // Tone and label from the same maps the fulfilment list uses, so a status
    // means the same colour and reads the same words on both surfaces. No
    // marketplace constant reaches the screen raw.
    $tone = \App\Support\ChannelStatusTone::toneFor('tiktok.orders', $status);
    $statusLabel = \App\Support\ChannelStatusTone::labelFor('tiktok.orders', $status);

    // Refresh re-pulls this order from TikTok and persists what comes back
    // (status, payload and buyer name are all written), so it is a write-tier
    // control. The previous version of this page hardcoded its own permission
    // flag to true, the same defect the Orders list carried before the
    // seller-center round read the permission properly. The route middleware
    // is the real gate; this flag only stops the page offering what the
    // operator has no business triggering.
    $canManageTiktokOrders = auth()->user()?->hasPermission('manage_tiktok_orders') ?? false;

    // Currency is resolved through App\Support\Money, which reads the symbol
    // from the currencies table, so no symbol is written into this view. An
    // order carrying a code nobody has configured locally falls back to
    // "CODE 1,234.00" rather than a wrong glyph. Same resolution the orders
    // list already applies to this order's own amount.
    $currencyCode = (string) ($payment['currency'] ?? '');
    $money = fn ($amount) => $currencyCode !== ''
        ? \App\Support\Money::foreign((float) $amount, $currencyCode)
        : \App\Support\Money::base((float) $amount);
@endphp

<div id="tiktok-order-detail-page" class="od-page">

    <a class="od-back" href="{{ route('ext.tiktok.orders.index') }}">
        <x-ui.icon name="chevron-left" size="14" />
        Orders
    </a>

    <header class="od-head">
        <div class="od-head__id">
            <h1 class="x-page-title od-title">{{ $orderId }}</h1>
            @if($status !== '')
                <x-ui.badge :tone="$tone">{{ $statusLabel }}</x-ui.badge>
            @endif
        </div>
        @if($canManageTiktokOrders)
        <div class="od-head__actions">
            <x-ui.button :href="route('ext.tiktok.orders.show', ['id' => $order->id, 'refresh' => 1])">Refresh from TikTok</x-ui.button>
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

    @if($api_error ?? false)
        <div class="od-note od-note--warn">
            <div class="od-note__body">
                <strong>Could not refresh from TikTok.</strong>
                The figures below are the last ones synced.
            </div>
        </div>
    @endif

    <div class="od-body">
        {{-- ============ MAIN COLUMN ============ --}}
        <div class="od-col od-col--main">

            {{-- Items. Same `co-` item grammar as the fulfilment list (96px
                 image, two-line name, SKU and variation meta), in the `od-`
                 item grid so the figures align down the page. TikTok can
                 repeat a line item, so identical SKU + name + variation rows
                 are consolidated exactly as the previous version of this page
                 consolidated them, including its list of variation values
                 that mean "no variation". --}}
            @php
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
            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">
                        Items
                        @if(!empty($consolidated))<span class="od-section__count">{{ count($consolidated) }}</span>@endif
                    </h2>
                </div>

                @if(empty($consolidated))
                    <p class="od-empty">No items synced yet.</p>
                @else
                <div class="od-items">
                    <div class="od-items__head" aria-hidden="true">
                        <span></span>
                        <span>Product</span>
                        <span>Qty</span>
                        <span>Unit price</span>
                        <span>Sale price</span>
                    </div>

                    @foreach($consolidated as $ci)
                        <div class="od-item">
                            <div class="order-img-wrap od-item__media">
                                @if($ci['image'] !== '')
                                    <img class="order-img" src="{{ $ci['image'] }}" alt="" loading="lazy" decoding="async">
                                @else
                                    <span class="co-item__none">No image</span>
                                @endif
                            </div>

                            <div class="co-item__body od-item__body">
                                <span class="co-item__name od-item__name">{{ $ci['name'] !== '' ? $ci['name'] : 'Unnamed product' }}</span>
                                <div class="co-item__meta">
                                    @if(trim($ci['sku']) !== '')
                                        <span class="co-item__sku">{{ $ci['sku'] }}</span>
                                    @endif
                                    @if(trim($ci['variation']) !== '')
                                        <span class="co-item__var">{{ $ci['variation'] }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="od-item__figs">
                                <span class="od-fig od-fig--qty"><span class="od-fig__k">Qty</span>{{ $ci['quantity'] }}</span>
                                {{-- A figure TikTok did not send takes the same
                                     dash the fulfilment list's own numeric
                                     cells take: in a right-aligned money
                                     column a sentence would break the
                                     alignment the column exists for. --}}
                                <span class="od-fig">
                                    <span class="od-fig__k">Unit price</span>@if($ci['item_price'] > 0){{ $money($ci['item_price']) }}@else<span class="co-empty-mark">&mdash;</span>@endif
                                </span>
                                <span class="od-fig od-fig--total">
                                    <span class="od-fig__k">Sale price</span>@if($ci['sale_price'] > 0){{ $money($ci['sale_price']) }}@else<span class="co-empty-mark">&mdash;</span>@endif
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
                @endif
            </section>

            {{-- ============ PAYMENT ============
                 What the buyer was charged, as TikTok reports it on the order
                 itself. Separate from settlement in the side column, which is
                 what TikTok pays out, and the two answer different questions.
                 In the main column rather than beside Customer/Shipping: the
                 same rebalance Shopee's invoice request took, moving content
                 into the shorter column rather than the escrow-style block
                 back (that adjudication stands). Without it TikTok's main
                 column stopped at 283px against an 1022px side. --}}
            <section class="od-card">
                <div class="od-card__head">
                    <h2 class="od-card__title">Payment</h2>
                </div>
                <div class="od-card__body ttd-fees">
                    @if($totalAmount)
                    {{-- "Order total", not "buyer paid": this is TikTok's
                         total_amount, which an UNPAID order carries before any
                         money has moved. A label the data does not support is
                         the one thing this page cannot print. --}}
                    <div class="od-paid">
                        <span class="od-paid__k">Order total</span>
                        <span class="od-paid__v">{{ $money($totalAmount) }}</span>
                    </div>
                    @endif
                    @if($shippingFee)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Shipping fee</span>
                        <span class="od-ledger__v">{{ $money($shippingFee) }}</span>
                    </div>
                    @endif
                    @if($sellerDiscount)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Seller discount</span>
                        <span class="od-ledger__v od-neg">-{{ $money(abs((float) $sellerDiscount)) }}</span>
                    </div>
                    @endif
                    @if($platformDiscount)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Platform discount</span>
                        <span class="od-ledger__v od-neg">-{{ $money(abs((float) $platformDiscount)) }}</span>
                    </div>
                    @endif
                    @if(!$totalAmount && !$shippingFee && !$sellerDiscount && !$platformDiscount)
                    <p class="od-empty">No payment figures from TikTok yet.</p>
                    @endif
                </div>
            </section>

            {{-- Raw payload. Kept from the previous version of this page: when
                 TikTok's response and this page disagree, this is what settles
                 it. It closes the main column rather than running full width
                 under the body, for the same reason Shopee's and Lazada's do:
                 the side column here is taller than the item list, and a
                 full-width band below both is marooned under a screen of
                 empty canvas. --}}
            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">Raw payload</h2>
                </div>
                <details class="od-disclose">
                    <summary>Show the JSON TikTok returned</summary>
                    <pre class="ttd-raw">{{ json_encode($raw, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
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
                            <span>{{ $address !== '' ? $address : 'Not recorded' }}</span>
                            @if($addrLine2 !== '')<span>{{ $addrLine2 }}</span>@endif
                        </div>
                    </div>
                </div>
            </section>

            {{-- Shipping --}}
            <section class="od-card">
                <div class="od-card__head">
                    <h2 class="od-card__title">Shipping</h2>
                </div>
                <div class="od-card__body">
                    <div class="od-fact">
                        <span class="od-fact__k">Courier</span>
                        <span class="od-fact__v">{{ $shipping !== '' ? $shipping : 'Not recorded' }}</span>
                    </div>
                    <div class="od-fact">
                        <span class="od-fact__k">Tracking number</span>
                        {{-- Mono is for the identifier, not for the words that
                             stand in when there is no identifier. --}}
                        <span class="od-fact__v {{ $trackingNo !== '' ? 'od-fact__v--mono' : '' }}">{{ $trackingNo !== '' ? $trackingNo : 'Not recorded' }}</span>
                    </div>
                </div>
                @if($hasParcel)
                <div class="od-card__foot ttd-parcel">
                    <a class="x-btn x-btn--secondary x-btn--sm" href="{{ route('ext.tiktok.orders.awb', $order->id) }}" target="_blank" rel="noopener">Print waybill</a>
                    <button type="button" class="btnTtTracking x-btn x-btn--secondary x-btn--sm"
                            data-url="{{ route('ext.tiktok.orders.tracking', $order->id) }}">Tracking</button>
                </div>
                @endif
            </section>

            {{-- ============ FEES AND SETTLEMENT ============
                 TikTok's settlement, not this system's arithmetic: nothing on
                 this page is derived from these lines, so they belong in the
                 side column as an independent block rather than as ledger
                 lines feeding a profit figure (that is the core Sales show's
                 shape, and it stays there). Every key and every "print only
                 when non-zero" condition is the one the previous version of
                 this page used, including its abs() on each deduction. --}}
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
            <section class="od-card">
                <div class="od-card__head">
                    <h2 class="od-card__title">Fees and settlement</h2>
                    @if($hasFees && $order->payout_status)
                        <x-ui.badge :tone="$order->payout_status === 'Paid' ? 'success' : 'warning'">{{ $order->payout_status }}</x-ui.badge>
                    @endif
                </div>
                @if($hasFees)
                <div class="od-card__body ttd-fees">
                    @if($fRevenue > 0)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Revenue</span>
                        <span class="od-ledger__v">{{ $money($fRevenue) }}</span>
                    </div>
                    @endif

                    @if($fRevenue > 0 && ($fCommission > 0 || $fTxnFee > 0 || $fShipping > 0 || $fPlatformDisc > 0))
                    <hr class="od-ledger__split">
                    @endif

                    @if($fCommission > 0)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Commission</span>
                        <span class="od-ledger__v od-neg">-{{ $money($fCommission) }}</span>
                    </div>
                    @endif
                    @if($fTxnFee > 0)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Transaction fee</span>
                        <span class="od-ledger__v od-neg">-{{ $money($fTxnFee) }}</span>
                    </div>
                    @endif
                    @if($fShipping > 0)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Shipping fee</span>
                        <span class="od-ledger__v od-neg">-{{ $money($fShipping) }}</span>
                    </div>
                    @endif
                    @if($fPlatformDisc > 0)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Platform discount</span>
                        <span class="od-ledger__v od-neg">-{{ $money($fPlatformDisc) }}</span>
                    </div>
                    @endif
                    @if($fTotalFees > 0)
                    <div class="od-ledger__row ttd-total-fees">
                        <span class="od-ledger__k">Total fees</span>
                        <span class="od-ledger__v od-neg">-{{ $money($fTotalFees) }}</span>
                    </div>
                    @endif
                    @if($fSettlement > 0)
                    <div class="od-ledger__row od-ledger__row--profit">
                        <span class="od-ledger__k">Settlement</span>
                        <span class="od-ledger__v od-pos">{{ $money($fSettlement) }}</span>
                    </div>
                    @endif
                </div>
                @if($order->payout_status && $order->paid_at)
                <div class="od-card__foot ttd-note">Paid out {{ $order->paid_at->format('M d, Y') }}</div>
                @endif
                @else
                <div class="od-card__body">
                    <p class="od-empty">No fee data yet. Settlement figures appear once TikTok completes its settlement cycle for this order.</p>
                </div>
                @endif
            </section>

        </div>
    </div>

</div>{{-- /#tiktok-order-detail-page --}}

@include('ext-tiktok::orders._modals')
@endsection
