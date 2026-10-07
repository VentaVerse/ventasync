@extends('layouts.channel')
@section('title', 'Order ' . ($order->order_id ?? ''))
@section('breadcrumb', $order->order_id ?? '')

@section('content')
@php
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

    $hasParcel = in_array($status, ['AWAITING_COLLECTION', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED'], true);

    $tone = \App\Support\ChannelStatusTone::toneFor('tiktok.orders', $status);
    $statusLabel = \App\Support\ChannelStatusTone::labelFor('tiktok.orders', $status);

    $canManageTiktokOrders = auth()->user()?->hasPermission('manage_tiktok/order') ?? false;

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
        <div class="od-col od-col--main">

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
                            @php $itImg = $ci['image'] !== '' ? $ci['image'] : (string) (\App\Support\CatalogImages::urlFor($ci['sku'] ?? '') ?? ''); @endphp
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
                            </div>

                            <div class="od-item__figs">
                                <span class="od-fig od-fig--qty"><span class="od-fig__k">Qty</span>{{ $ci['quantity'] }}</span>
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
                    <p class="od-empty">TikTok has not settled this order yet.</p>
                @endif
            </section>

            <details class="od-disclose od-rawbox">
                <summary>Show the JSON TikTok returned</summary>
                <pre class="ttd-raw">{{ json_encode($raw, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
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
                            <span>{{ $address !== '' ? $address : 'Not recorded' }}</span>
                            @if($addrLine2 !== '')<span>{{ $addrLine2 }}</span>@endif
                        </div>
                    </div>
                </div>
            </section>

            <section class="od-card">
                <div class="od-card__head">
                    <h2 class="od-card__title">Payment</h2>
                </div>
                <div class="od-card__body ttd-fees">
                    @if($totalAmount)
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

        </div>
    </div>

</div>

@include('ext-tiktok::orders._modals')
@endsection
