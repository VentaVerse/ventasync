@extends('layouts.channel')
@section('title', 'Order ' . $order->order_id)
@section('breadcrumb', $order->order_id)

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
    $addrLine3 = trim(trim($city . ' ' . $province) . ' ' . $postcode . ' ' . $country);

    $courier = $detail['shipping_provider'] ?? $detail['shipping_provider_type'] ?? $detail['shipping_provider_name'] ?? $raw['shipping_provider'] ?? $raw['shipping_provider_type'] ?? $raw['shipping_provider_name'] ?? null;
    if (is_array($courier)) $courier = implode(', ', $courier);
    $courier = is_string($courier) ? trim($courier) : '';

    $tracking = $detail['tracking_code'] ?? $detail['tracking_number'] ?? $raw['tracking_code'] ?? $raw['tracking_number'] ?? null;
    if (is_array($tracking)) $tracking = implode(', ', $tracking);
    $tracking = is_string($tracking) ? trim($tracking) : '';

    $items = $order->products ?? collect();

    $fees = is_array($order->fees ?? null) ? $order->fees : [];
    $hasFees = !empty($fees) && !empty(array_filter($fees, fn($v) => !is_array($v) && $v != 0));

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

    $tone = \App\Support\ChannelStatusTone::toneFor('lazada.orders', $status);
    $statusLabel = \App\Support\ChannelStatusTone::labelFor('lazada.orders', $status);

    $canManageLazadaOrders = auth()->user()?->hasPermission('manage_lazada/order') ?? false;

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
        <div class="od-col od-col--main">

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
                            @php $itImg = $ci['img'] !== '' ? $ci['img'] : (string) (\App\Support\CatalogImages::urlFor($ci['sku'] ?? '') ?? ''); @endphp
                            <div class="order-img-wrap od-item__media">
                                @if($itImg !== '')
                                    <img class="order-img" src="{{ $itImg }}" alt="" loading="lazy" decoding="async">
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
                        $iOrderItemId = (string)($it->order_item_id ?? ($ir['order_item_id'] ?? ''));

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

            <details class="od-disclose od-rawbox">
                <summary>Show the JSON Lazada returned</summary>
                <pre class="lzd-raw">{{ json_encode(['order' => $raw, 'detail' => $detail, 'fees' => $fees], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
        </div>

        <div class="od-col od-col--side">

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

</div>

@include('ext-lazada::orders._modals')
@endsection
