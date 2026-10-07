@extends('layouts.channel')
@section('title', 'Order ' . $order->order_sn)
@section('breadcrumb', $order->order_sn)

@section('content')
@php
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
    $hasParcel = in_array($statusNorm, ['PROCESSED', 'SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED'], true);

    $canManageShopeeOrders = auth()->user()?->hasPermission('manage_shopee/order') ?? false;

    $tone = \App\Support\ChannelStatusTone::toneFor('shopee.orders', $statusStr);
    $statusLabel = \App\Support\ChannelStatusTone::labelFor('shopee.orders', $statusStr);

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
                <x-ui.button variant="primary" class="btnArrangeShipment" data-order-sn="{{ $order->order_sn }}" data-pc-channel="shopee" data-pc-order="{{ $order->id }}">Arrange shipment</x-ui.button>
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
        <div class="od-col od-col--main">

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
                            @php $itImg = $img !== '' ? $img : (string) (\App\Support\CatalogImages::urlFor($sku) ?? ''); @endphp
                            <div class="order-img-wrap od-item__media">
                                @if($itImg !== '')
                                    <img class="order-img" src="{{ $itImg }}" alt="" loading="lazy" decoding="async">
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
                                    <x-fulfilment.variation :sku="$sku" :fallback="$variation" />
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
                    <p class="od-empty">Shopee has not settled this order yet.</p>
                @endif
            </section>

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
            @if($invState === 'requested')
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
                @endif
            </section>
            @endif

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

            <details class="od-disclose od-rawbox">
                <summary>Show the JSON Shopee returned</summary>
                <pre class="spd-raw">{{ json_encode($raw, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
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
                    @if($invState !== 'requested')
                    <div class="od-fact">
                        <span class="od-fact__k">Invoice</span>
                        <span class="od-fact__v">{{ $invState === 'declined' ? 'Not requested' : 'Not available from Shopee' }}</span>
                    </div>
                    @endif
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

            @if(($orderTotal !== null && $orderTotal !== '') || $paymentMethod !== '')
            <section class="od-card">
                <div class="od-card__head">
                    <h2 class="od-card__title">Payment</h2>
                </div>
                <div class="od-card__body">
                    @if($orderTotal !== null && $orderTotal !== '')
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
                @if($hasParcel)
                <div class="od-card__foot spd-parcel">
                    <a class="x-btn x-btn--secondary x-btn--sm" href="{{ route('ext.shopee.orders.awb', ['orderSn' => $order->order_sn]) }}" target="_blank" rel="noopener">Print waybill</a>
                    <button type="button" class="btnShopeeTracking x-btn x-btn--secondary x-btn--sm"
                            data-url="{{ route('ext.shopee.orders.tracking_info', ['orderSn' => $order->order_sn]) }}">Tracking</button>
                </div>
                @endif
            </section>

        </div>
    </div>

</div>

@include('ext-shopee::orders._modals')
@endsection
