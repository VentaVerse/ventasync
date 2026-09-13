@extends('layouts.channel')
@section('title', 'Order ' . $order->order_id)
@section('breadcrumb', $order->order_id)

{{-- Leaves the .x-legacy content boundary, the same exit the Orders and
     Returns pages took: this page is built directly on the design tokens,
     the x-ui components and the shared `od-` detail anatomy. --}}
@section('content')
@php
    // Wire-format truth is LazadaOrderController@show. Every extraction below
    // is the expression the previous version of this page used, moved
    // unchanged: Lazada answers the list endpoint and the detail endpoint
    // with different key spellings, and each `??` chain is a fallback someone
    // hit in production.
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
    $addrLine3 = trim(trim($city . ' ' . $province) . ' ' . $postcode . ' ' . $country);

    $courier = $detail['shipping_provider'] ?? $detail['shipping_provider_type'] ?? $detail['shipping_provider_name'] ?? $raw['shipping_provider'] ?? $raw['shipping_provider_type'] ?? $raw['shipping_provider_name'] ?? null;
    if (is_array($courier)) $courier = implode(', ', $courier);
    $courier = is_string($courier) ? trim($courier) : '';

    $tracking = $detail['tracking_code'] ?? $detail['tracking_number'] ?? $raw['tracking_code'] ?? $raw['tracking_number'] ?? null;
    if (is_array($tracking)) $tracking = implode(', ', $tracking);
    $tracking = is_string($tracking) ? trim($tracking) : '';

    $items = $order->products ?? collect();

    // Whether the order carries ANY fee data Lazada returned. Gates every
    // fee-derived block on this page (the per-item price breakdown, the
    // transaction-lines table, and the side-column ledger below), same as
    // the previous version of this page, which nested all three under one
    // `@if`. Scalar-only on purpose: `transaction_lines` and `other_fees`
    // are themselves array values inside $fees, and array_filter here is
    // asking "is there a settled ledger figure", not "is the key present".
    $fees = is_array($order->fees ?? null) ? $order->fees : [];
    $hasFees = !empty($fees) && !empty(array_filter($fees, fn($v) => !is_array($v) && $v != 0));

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

    // Tone and label from the same maps the fulfilment list uses, so a status
    // means the same colour and reads the same words on both surfaces. No
    // marketplace constant reaches the screen raw.
    $tone = \App\Support\ChannelStatusTone::toneFor('lazada.orders', $status);
    $statusLabel = \App\Support\ChannelStatusTone::labelFor('lazada.orders', $status);

    // Refresh re-pulls this order from Lazada and persists what comes back
    // (the detail node, the item rows and the fee breakdown all get written),
    // so it is a write-tier control. The previous version of this page offered
    // it to everyone, which is the same defect the Orders list carried before
    // the seller-center round read the permission properly. The route
    // middleware is the real gate; this flag only stops the page offering
    // what the operator has no business triggering.
    $canManageLazadaOrders = auth()->user()?->hasPermission('manage_lazada/order') ?? false;

    // Currency is resolved through App\Support\Money, which reads the symbol
    // from the currencies table, so no symbol is written into this view. An
    // order carrying a code nobody has configured locally falls back to
    // "CODE 1,234.00" rather than a wrong glyph. Same resolution the orders
    // list already applies to this order's own amount.
    $currencyCode = (string) ($raw['currency'] ?? ($detail['currency'] ?? ''));
    $money = fn ($amount) => $currencyCode !== ''
        ? \App\Support\Money::foreign((float) $amount, $currencyCode)
        : \App\Support\Money::base((float) $amount);
@endphp

<div id="lazada-order-detail-page" class="od-page">

    <a class="od-back" href="{{ route('ext.lazada.orders.index') }}">
        <x-ui.icon name="chevron-left" size="14" />
        Orders
    </a>

    <header class="od-head">
        <div class="od-head__id">
            <h1 class="x-page-title od-title">{{ $order->order_id }}</h1>
            @if($status !== '')
                <x-ui.badge :tone="$tone">{{ $statusLabel }}</x-ui.badge>
            @endif
        </div>
        @if($canManageLazadaOrders)
        <div class="od-head__actions">
            <x-ui.button :href="route('ext.lazada.orders.show', ['orderId' => $order->order_id, 'refresh' => 1])">Refresh from Lazada</x-ui.button>
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
                <strong>Could not refresh from Lazada.</strong>
                The figures below are the last ones synced.
            </div>
        </div>
    @endif

    @if($api_items_synced)
        <div class="od-note od-note--ok">
            <div class="od-note__body">Order items refreshed.</div>
        </div>
    @endif

    <div class="od-body">
        {{-- ============ MAIN COLUMN ============ --}}
        <div class="od-col od-col--main">

            {{-- Items. Same `co-` item grammar as the fulfilment list (96px
                 image, two-line name, SKU and variation meta), in the `od-`
                 item grid so the figures align down the page. Lazada returns
                 one row per unit, so identical SKU + name + variation rows
                 are consolidated exactly as the previous version of this page
                 consolidated them, and the order item ids that were their own
                 table column ride under the item as the identifiers they
                 are. --}}
            @php
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
            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">
                        Items
                        @if($items->count())<span class="od-section__count">{{ count($consolidated) }}</span>@endif
                    </h2>
                </div>

                @if($items->count() === 0)
                    <p class="od-empty">No items synced yet. Items will be fetched on the next order sync.</p>
                @else
                <div class="od-items">
                    <div class="od-items__head" aria-hidden="true">
                        <span></span>
                        <span>Product</span>
                        <span>Qty</span>
                        <span>Unit price</span>
                        <span>Paid</span>
                    </div>

                    @foreach($consolidated as $ci)
                        @php $ciIds = array_filter($ci['order_item_ids']); @endphp
                        <div class="od-item">
                            <div class="order-img-wrap od-item__media">
                                @if($ci['img'] !== '')
                                    <img class="order-img" src="{{ $ci['img'] }}" alt="" loading="lazy" decoding="async">
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
                                    <x-fulfilment.variation :sku="$ci['sku']" :fallback="$ci['variation']" />
                                </div>
                                @if(!empty($ciIds))
                                    <div class="lzd-ids">
                                        <span class="lzd-ids__k">Order item {{ count($ciIds) === 1 ? 'id' : 'ids' }}</span>
                                        <span class="lzd-ids__v">{{ implode(', ', $ciIds) }}</span>
                                    </div>
                                @endif
                            </div>

                            <div class="od-item__figs">
                                <span class="od-fig od-fig--qty"><span class="od-fig__k">Qty</span>{{ $ci['qty'] }}</span>
                                {{-- A figure Lazada did not send takes the same
                                     dash the fulfilment list's own numeric
                                     cells take: in a right-aligned money
                                     column a sentence would break the
                                     alignment the column exists for. --}}
                                <span class="od-fig">
                                    <span class="od-fig__k">Unit price</span>@if($ci['unit_price'] > 0){{ $money($ci['unit_price']) }}@else<span class="co-empty-mark">&mdash;</span>@endif
                                </span>
                                <span class="od-fig od-fig--total">
                                    <span class="od-fig__k">Paid</span>@if($ci['paid_total'] > 0){{ $money($ci['paid_total']) }}@else<span class="co-empty-mark">&mdash;</span>@endif
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
                @endif

                {{-- Per-item price composition. It sits under the items it
                     describes rather than inside the fee block, which is
                     where the previous version of this page carried it: this
                     is what each line was worth before and after Lazada's
                     vouchers, not part of the settlement. --}}
                @php
                    $itemFees = [];
                    foreach ($items as $it) {
                        $ir = is_array($it->raw ?? null) ? $it->raw : [];
                        $iName = (string)($it->name ?? ($ir['name'] ?? ($ir['product_name'] ?? '')));
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
                        // The id is what tells two rows of the same product
                        // apart. Lazada bills one row per unit, so without it
                        // a two-unit line shows twice here with nothing
                        // distinguishing the copies. Same value the item's own
                        // id line above already prints.
                        $iOrderItemId = (string)($it->order_item_id ?? ($ir['order_item_id'] ?? ''));

                        // Only include if there's meaningful data
                        $hasData = ($iItemPrice != 0 || $iPaidPrice != 0 || $iVoucherSeller != 0 || $iVoucherPlatform != 0 || $iShipService != 0);
                        if ($hasData) {
                            $itemFees[] = [
                                'name' => $iName,
                                'sku' => $iSku,
                                'order_item_id' => $iOrderItemId,
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
                @if(!empty($itemFees) && $hasFees)
                {{-- Counted in order items, not in products: the list above
                     consolidates Lazada's one-row-per-unit into one line per
                     product, so a two-unit line is 1 item there and 2 order
                     items here. Saying which unit each count is in is what
                     stops the two numbers reading as a contradiction. --}}
                <details class="od-disclose lzd-breakdown">
                    <summary>Price breakdown by order item ({{ count($itemFees) }})</summary>
                    @foreach($itemFees as $if)
                    <div class="lzd-break">
                        <div class="lzd-break__head">
                            <span class="lzd-break__name">{{ $if['name'] !== '' ? $if['name'] : 'Unnamed product' }}</span>
                            @if($if['sku'] !== '')
                                <span class="co-item__sku">{{ $if['sku'] }}</span>
                            @endif
                            @if($if['order_item_id'] !== '')
                                <span class="lzd-ids__v">{{ $if['order_item_id'] }}</span>
                            @endif
                            @if($if['status'] !== '')
                                <x-ui.badge :tone="\App\Support\ChannelStatusTone::toneFor('lazada.orders', $if['status'])">{{ \App\Support\ChannelStatusTone::labelFor('lazada.orders', $if['status']) }}</x-ui.badge>
                            @endif
                        </div>
                        <div class="lzd-break__figs">
                            <span class="od-econ__cell"><span class="od-econ__k">Item price</span><span class="od-econ__v">{{ $if['item_price'] ? $money($if['item_price']) : 'Not recorded' }}</span></span>
                            <span class="od-econ__cell"><span class="od-econ__k">Paid price</span><span class="od-econ__v">{{ $if['paid_price'] ? $money($if['paid_price']) : 'Not recorded' }}</span></span>
                            <span class="od-econ__cell"><span class="od-econ__k">Voucher (seller)</span><span class="od-econ__v {{ $if['voucher_seller'] ? 'od-neg' : '' }}">{{ $if['voucher_seller'] ? '-' . $money(abs($if['voucher_seller'])) : 'None' }}</span></span>
                            <span class="od-econ__cell"><span class="od-econ__k">Voucher (platform)</span><span class="od-econ__v {{ $if['voucher_platform'] ? 'od-neg' : '' }}">{{ $if['voucher_platform'] ? '-' . $money(abs($if['voucher_platform'])) : 'None' }}</span></span>
                            <span class="od-econ__cell"><span class="od-econ__k">Shipping</span><span class="od-econ__v">{{ $if['shipping'] ? $money($if['shipping']) : 'None' }}</span></span>
                            <span class="od-econ__cell"><span class="od-econ__k">Shipping service cost</span><span class="od-econ__v {{ $if['ship_service'] ? 'od-neg' : '' }}">{{ $if['ship_service'] ? '-' . $money(abs($if['ship_service'])) : 'None' }}</span></span>
                        </div>
                    </div>
                    @endforeach
                </details>
                @endif
            </section>

            {{-- The store's own breakdown of what this order came to, under the items it is for. --}}
            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">{{ $breakdown?->title ?? 'Order income' }}</h2>
                    @if($order->payout_status)
                        <span class="od-payout">
                            <x-ui.badge :tone="$order->payout_status === 'Paid' ? 'success' : 'warning'">{{ $order->payout_status }}</x-ui.badge>
                            @if($order->paid_at)
                                <span class="od-payout__when">Paid out {{ $order->paid_at->format('M d, Y') }}</span>
                            @endif
                        </span>
                    @endif
                </div>
                @if($breakdown)
                    @include('partials.order-breakdown', ['breakdown' => $breakdown, 'currency' => $breakdownCurrency])
                @else
                    <p class="od-empty">Lazada has not settled this order yet.</p>
                @endif
            </section>

            {{-- ============ FEE TRANSACTIONS ============
                 Lazada's finance ledger for this order: one row per charge it
                 actually posted, which is a different question from the
                 breakdown above it (that says what the order came to, this
                 says which transactions made it up). Full width in the
                 main column because it is a second subject with its own rows,
                 not a reference fact about the first one. --}}
            @php
                $trxLines = $fees['transaction_lines'] ?? [];
            @endphp
            @if(!empty($trxLines) && $hasFees)
            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">
                        Lazada platform fees
                        <span class="od-section__count">{{ count($trxLines) }}</span>
                    </h2>
                </div>
                <div class="lzd-trx">
                    <div class="lzd-trx__head" aria-hidden="true">
                        <span>Fee</span>
                        <span>SKU</span>
                        <span>Amount</span>
                        <span>Date</span>
                    </div>
                    @foreach($trxLines as $tl)
                    <div class="lzd-trx__row">
                        {{-- The code rides beside the name, but only when
                             there IS a name: with no name the label is
                             already "Fee type 112", and a "Type 112" chip
                             next to it says the same thing twice. --}}
                        <span class="lzd-trx__name">
                            {{ $tl['fee_name'] ?: ('Fee type ' . $tl['fee_type']) }}
                            @if($tl['fee_type'] && $tl['fee_name'])
                                <span class="lzd-trx__type">Type {{ $tl['fee_type'] }}</span>
                            @endif
                        </span>
                        <span class="lzd-trx__sku">{{ $tl['sku'] ?: ($tl['order_item_id'] ?: 'Not recorded') }}</span>
                        <span class="lzd-trx__num {{ $tl['amount'] < 0 ? 'od-neg' : '' }}">{{ $tl['amount'] < 0 ? '-' : '' }}{{ $money(abs($tl['amount'])) }}</span>
                        <span class="lzd-trx__when">{{ $tl['transaction_date'] ?: 'Not recorded' }}</span>
                    </div>
                    @endforeach
                </div>
            </section>
            @endif

            {{-- Raw payload. Kept from the previous version of this page: when
                 Lazada's response and this page disagree, this is what settles
                 it. It closes the main column rather than running full width
                 under the body, for the same reason Shopee's does: the side
                 column here is taller than the item list, and a full-width
                 band below both is marooned under a screen of empty canvas. --}}
            <details class="od-disclose od-rawbox">
                <summary>Show the JSON Lazada returned</summary>
                <pre class="lzd-raw">{{ json_encode(['order' => $raw, 'detail' => $detail, 'fees' => $fees], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
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
                            @if($address2 !== '')<span>{{ $address2 }}</span>@endif
                            @if($addrLine3 !== '')<span>{{ $addrLine3 }}</span>@endif
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
                        <span class="od-fact__v">{{ $courier !== '' ? $courier : 'Not recorded' }}</span>
                    </div>
                    <div class="od-fact">
                        <span class="od-fact__k">Tracking number</span>
                        {{-- Mono is for the identifier, not for the words that
                             stand in when there is no identifier. --}}
                        <span class="od-fact__v {{ $tracking !== '' ? 'od-fact__v--mono' : '' }}">{{ $tracking !== '' ? $tracking : 'Not recorded' }}</span>
                    </div>
                </div>
                <div class="od-card__foot lzd-parcel">
                    <a class="x-btn x-btn--secondary x-btn--sm" href="{{ route('ext.lazada.orders.awb', ['orderId' => $order->order_id]) }}" target="_blank" rel="noopener">Print waybill</a>
                    <button type="button" class="btnLzLogistics x-btn x-btn--secondary x-btn--sm"
                            data-url="{{ route('ext.lazada.orders.logistics_trace', ['orderId' => $order->order_id]) }}">Tracking</button>
                </div>
            </section>

        </div>
    </div>

</div>{{-- /#lazada-order-detail-page --}}

@include('ext-lazada::orders._modals')
@endsection
