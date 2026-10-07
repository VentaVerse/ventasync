@extends('layouts.blotter')
@section('title', 'Order ' . $order->order_id)
@section('breadcrumb', $order->order_id)

@section('content')
@php
    $canManageOrders = auth()->user()?->hasPermission('manage_sales/order') ?? false;
    $canManagePayments = auth()->user()?->hasPermission('manage_sales/order_payment') ?? false;

    $registry = app(\App\Integrations\IntegrationRegistry::class);

    $src = trim((string) $order->marketplace_source);
    $chanKey = $src === '' ? '' : strtok($src, ':');
    $chanTone = $src === '' ? 'neutral' : $chanKey;
    $chanLabel = $src === ''
        ? 'Manual'
        : ($registry->resolveMarketplaceSourceLabel($src) ?: ucfirst($chanKey));

    $orderRef = $order->marketplace_order_id
        ? $registry->resolveOrderRef((string) $order->marketplace_source, (string) $order->marketplace_order_id)
        : null;

    $refUrl = $orderRef['url'] ?? null;
    $refText = $orderRef['display'] ?? (string) $order->marketplace_order_id;

    $defaultSymbol = \App\Support\Money::defaultSymbol();
    $isForeign = (string) $order->currency_code !== ''
        && strcasecmp((string) $order->currency_code, $defaultCurrency->code ?? 'PHP') !== 0;
    $orderRate = (float) ($order->currency_value ?: 1);
    $normalized = !$isForeign || $order->foreign_total !== null;
    $dual = $isForeign && $normalized;

    $payments = $payments ?? collect();
    $totalPaid = (float) ($totalPaid ?? 0);
    $orderTotal = (float) $order->total;
    $balance = $orderTotal - $totalPaid;


    $marginTone = fn (float $pct) => $pct >= 20 ? '' : ($pct >= 10 ? 'od-warn' : 'od-neg');
@endphp

<div class="od-page"
     data-order-detail
     data-order-id="{{ $order->order_id }}"
     data-base-url="{{ url('/sales/orders') }}">

    <div class="od-flash" id="od-flash" role="status" aria-live="polite">
        @if(session('status'))
            <div class="od-note od-note--ok">
                <div class="od-note__body">{{ session('status') }}</div>
            </div>
        @endif
        @if(session('error'))
            <div class="od-note od-note--fail">
                <div class="od-note__body">{{ session('error') }}</div>
            </div>
        @endif
        @if($errors->any())
            <div class="od-note od-note--fail">
                <div class="od-note__body">
                    <ul class="od-note__list">
                        @foreach($errors->all() as $e)
                            <li>{{ $e }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </div>

    <a class="od-back" href="{{ route('orders.index') }}">
        <x-ui.icon name="chevron-left" size="14" />
        Orders
    </a>

    <header class="od-head">
        <div class="od-head__id">
            <h1 class="x-page-title od-title">#{{ $order->order_id }}</h1>
            @if($canManageOrders)
                <details class="od-status">
                    <summary class="od-status__summary" aria-label="Change the order status">
                        <x-ui.badge :tone="\App\Support\OrderStatusTone::for($order->status->name ?? null)">{{ $order->status->name ?? '-' }}</x-ui.badge>
                        <span class="od-status__hint">Change status</span>
                        <x-ui.icon name="chevron-down" size="12" class="od-status__caret" />
                    </summary>
                    <div class="od-status__panel">
        <form method="POST" action="{{ route('orders.toggle_override', $order->order_id) }}">
                @csrf
                <div class="od-override">
                    <button type="submit"
                            class="od-switch"
                            role="switch"
                            aria-checked="{{ $order->sync_override ? 'true' : 'false' }}"
                            aria-label="{{ $order->sync_override
                                ? 'Hand status back to the marketplace'
                                : 'Take over this order\'s status' }}">
                    </button>
                    <div class="od-override__body">
                        <div class="od-override__title">Status override</div>
                        <div class="od-override__note">
                            @if($order->sync_override)
                                On. This order's status no longer syncs from the marketplace, and syncs will not change its stock.
                            @else
                                Off. Turning it on stops syncing this order's status from the marketplace.
                            @endif
                        </div>
                    </div>
                </div>
            </form>

            <form method="POST" action="{{ route('orders.update_status', $order->order_id) }}" data-status-form
                  data-confirm-tone="primary" data-confirm-verb="Update status">
                @csrf
                <div class="od-fields">
                    <div class="od-field">
                        <label class="od-field__k" for="od-status">Order status</label>
                        <div class="x-select-wrap">
                            <select id="od-status" class="x-input" name="order_status_id" data-status-select
                                    data-current="{{ (int) $order->order_status_id }}"
                                    data-current-subtract="{{ (int) ($order->status->subtract_stock ?? 0) }}"
                                    data-units="{{ (int) $products->sum('quantity') }}">
                                @include('sales.orders.partials._status_options', ['statuses' => $statuses, 'selected' => $order->order_status_id])
                            </select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>
                    </div>
                    <div class="od-field">
                        <label class="od-field__k" for="od-status-comment">Comment</label>
                        <input class="x-input" id="od-status-comment" name="comment" value="" placeholder="Optional note">
                    </div>
                    <div class="od-field">
                        <x-ui.button variant="primary" type="submit">Update status</x-ui.button>
                    </div>
                </div>
            </form>

                        <p class="od-status__note" data-status-note data-note-default="Moving to or from a status that takes stock adjusts inventory the moment you update.">Moving to or from a status that takes stock adjusts inventory the moment you update.</p>
                        <div class="od-status__foot">
                            <button type="button" class="x-btn x-btn--ghost x-btn--sm" data-status-close>Close</button>
                        </div>
                    </div>
                    <div class="od-status__scrim" data-status-close aria-hidden="true"></div>
                </details>
            @else
                <x-ui.badge :tone="\App\Support\OrderStatusTone::for($order->status->name ?? null)">{{ $order->status->name ?? '-' }}</x-ui.badge>
            @endif
            <x-ui.badge :tone="$chanTone" :dot="false">{{ $chanLabel }}</x-ui.badge>
        </div>
        @if($canManageOrders)
        <div class="od-head__actions">
            <x-ui.button variant="primary" :href="route('orders.edit', $order->order_id)">Edit order</x-ui.button>
        </div>
        @endif
    </header>

    <div class="od-stamps">
        <span class="od-stamp">
            <span class="od-stamp__k">Created</span>
            <span class="od-stamp__v">{{ $order->date_added }}</span>
        </span>
        @if($order->marketplace_order_id)
        <span class="od-stamp">
            <span class="od-stamp__k">Marketplace order</span>
            @if($refUrl)
                <a class="od-stamp__v" href="{{ $refUrl }}">{{ $refText }}</a>
            @else
                <span class="od-stamp__v">{{ $refText }}</span>
            @endif
        </span>
        @endif
    </div>

    @if($isForeign)
        <div class="currency-banner {{ $normalized ? '' : 'currency-banner--pending' }}">
            @if($normalized)
                <span>
                    <strong>{{ $order->currency_code }}</strong> order.
                    1 {{ $order->currency_code }} = <span class="od-rate">{{ $defaultSymbol }}{{ rtrim(rtrim(number_format($orderRate, 8, '.', ''), '0'), '.') }}</span>
                </span>
                <span class="currency-banner__date">Based on <span class="od-rate">{{ \Illuminate\Support\Carbon::parse($order->date_added)->format('Y-m-d') }}</span> currency rate</span>
            @else
                <strong>{{ $order->currency_code }}</strong> order. Exchange rate not yet recorded,
                so the amounts below are shown exactly as they were stored.
            @endif
        </div>
    @endif

    <div class="od-facts">
            <section class="od-card">
                <div class="od-card__head">
                    <h2 class="od-card__title">Customer</h2>
                </div>
                <div class="od-card__body od-card__body--tight">
                    <div class="od-lines">
                        <span class="od-lines__name">{{ trim($order->firstname . ' ' . $order->lastname) ?: 'Guest' }}</span>
                        @if(trim((string) ($order->customer_group ?? '')) !== '')
                            <span class="od-fact">
                                <span class="od-fact__k">Customer group</span>
                                <span class="od-fact__v">{{ $order->customer_group }}</span>
                            </span>
                        @endif
                        @if($order->email)<span><a href="mailto:{{ $order->email }}">{{ $order->email }}</a></span>@endif
                        @if($order->telephone)<span>{{ $order->telephone }}</span>@endif
                    </div>
                </div>
            </section>

            @php
                $hasShipping = $order->shipping_firstname || $order->shipping_lastname || $order->shipping_company
                    || $order->shipping_address_1 || $order->shipping_address_2 || $order->shipping_city
                    || $order->shipping_postcode || $order->shipping_zone || $order->shipping_country
                    || $order->shipping_method || $order->tracking_number;
            @endphp
            @if($hasShipping)
            <section class="od-card">
                <div class="od-card__head">
                    <h2 class="od-card__title">Shipping</h2>
                </div>
                <div class="od-card__body">
                    @if($order->shipping_method)
                    <div class="od-fact">
                        <span class="od-fact__k">Method</span>
                        <span class="od-fact__v">{{ $order->shipping_method }}</span>
                    </div>
                    @endif
                    @if($order->tracking_number)
                    <div class="od-fact">
                        <span class="od-fact__k">Tracking number</span>
                        <span class="od-fact__v od-fact__v--mono">
                            @if($trackingHref = $order->trackingHref())
                                <a href="{{ $trackingHref }}" target="_blank" rel="noopener">
                                    {{ $order->tracking_number }}<x-ui.icon name="external-link" size="11" class="od-stamp__out" />
                                </a>
                            @else
                                {{ $order->tracking_number }}
                            @endif
                        </span>
                    </div>
                    @endif
                    @if($order->shipping_firstname || $order->shipping_lastname || $order->shipping_address_1 || $order->shipping_city || $order->shipping_country)
                    <div class="od-fact">
                        <span class="od-fact__k">Delivery address</span>
                        <div class="od-lines">
                            @if($order->shipping_firstname || $order->shipping_lastname)
                                <span class="od-lines__name">{{ trim($order->shipping_firstname . ' ' . $order->shipping_lastname) }}</span>
                            @endif
                            @if($order->shipping_company)<span>{{ $order->shipping_company }}</span>@endif
                            @if($order->shipping_address_1)<span>{{ $order->shipping_address_1 }}</span>@endif
                            @if($order->shipping_address_2)<span>{{ $order->shipping_address_2 }}</span>@endif
                            @if($order->shipping_city || $order->shipping_postcode)
                                <span>{{ trim($order->shipping_city . ' ' . $order->shipping_postcode) }}</span>
                            @endif
                            @if($order->shipping_zone)<span>{{ $order->shipping_zone }}</span>@endif
                            @if($order->shipping_country)<span>{{ $order->shipping_country }}</span>@endif
                        </div>
                    </div>
                    @endif
                </div>
            </section>
            @endif

            <section class="od-card">
                <div class="od-card__head">
                    <h2 class="od-card__title">Billing</h2>
                </div>
<div class="od-card__body">
                    @if($order->payment_method)
                    <div class="od-fact">
                        <span class="od-fact__k">Method</span>
                        <span class="od-fact__v">{{ $order->payment_method }}</span>
                    </div>
                    @endif

                    @php
                        $hasBilling = $order->payment_firstname || $order->payment_lastname || $order->payment_company
                            || $order->payment_address_1 || $order->payment_address_2 || $order->payment_city
                            || $order->payment_postcode || $order->payment_zone || $order->payment_country;
                    @endphp
                    @if($hasBilling)
                    <div class="od-fact">
                        <span class="od-fact__k">Billing address</span>
                        <div class="od-lines">
                            @if($order->payment_firstname || $order->payment_lastname)
                                <span class="od-lines__name">{{ trim($order->payment_firstname . ' ' . $order->payment_lastname) }}</span>
                            @endif
                            @if($order->payment_company)<span>{{ $order->payment_company }}</span>@endif
                            @if($order->payment_address_1)<span>{{ $order->payment_address_1 }}</span>@endif
                            @if($order->payment_address_2)<span>{{ $order->payment_address_2 }}</span>@endif
                            @if($order->payment_city || $order->payment_postcode)
                                <span>{{ trim($order->payment_city . ' ' . $order->payment_postcode) }}</span>
                            @endif
                            @if($order->payment_zone)<span>{{ $order->payment_zone }}</span>@endif
                            @if($order->payment_country)<span>{{ $order->payment_country }}</span>@endif
                        </div>
                    </div>
                    @endif

                </div>
            </section>
            @if($order->comment)
            <section class="od-card">
                <div class="od-card__head">
                    <h2 class="od-card__title">Order comment</h2>
                </div>
                <div class="od-card__body">
                    <p class="od-fact__v">{{ $order->comment }}</p>
                </div>
            </section>
            @endif
    </div>

    <div class="od-body">
        <div class="od-col od-col--main">

            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">
                        Items
                        @if($products->count())<span class="od-section__count">{{ $products->count() }}</span>@endif
                    </h2>
                    @if($canManageOrders && $products->contains(fn ($p) => !$p->cost || (float) $p->cost == 0))
                    <div class="od-section__aside">
                        <form method="POST" action="{{ route('orders.backfill_costs', $order->order_id) }}">
                            @csrf
                            <x-ui.button size="sm" type="submit" title="Update products with missing costs from current catalog data">Backfill missing costs</x-ui.button>
                        </form>
                    </div>
                    @endif
                </div>

                @if($products->count())
                @php
                    $totalRevenue = 0; $totalCogs = 0;
                    $lineImages = \App\Support\CatalogImages::forSkus($products->pluck('model')->map(fn ($m) => trim((string) $m))->filter()->all());
                @endphp
                <div class="od-items">
                    <div class="od-items__head" aria-hidden="true">
                        <span></span>
                        <span>Product</span>
                        <span>Qty</span>
                        <span>Unit price</span>
                        <span>Line total</span>
                    </div>

                    @foreach($products as $p)
                        @php
                            $catalogProduct = $catalogProducts[$p->order_product_id] ?? null;
                            $img = $lineImages[trim((string) $p->model)] ?? ($catalogProduct ? trim((string) ($catalogProduct->image ?? '')) : '');
                            $imgSrc = $img !== '' ? \App\Services\Media\ImageCache::url($img) : '';
                            if ($imgSrc === '') {
                                $channelImg = trim($orderLineImages[$p->order_product_id] ?? '');
                                if ($channelImg !== '') { $imgSrc = $channelImg; }
                            }

                            $unitCost = (float) ($p->cost ?? 0);
                            $cogs = $unitCost * (int) $p->quantity;
                            $lineTotal = (float) $p->total;
                            $profit = $lineTotal - $cogs;
                            $margin = $lineTotal > 0 ? ($profit / $lineTotal * 100) : 0;
                            $markup = $cogs > 0 ? ($profit / $cogs * 100) : 0;

                            $totalRevenue += $lineTotal;
                            $totalCogs += $cogs;
                        @endphp
                        <div class="od-item">
                            <div class="order-img-wrap od-item__media">
                                @if($imgSrc)
                                    <img class="order-img" src="{{ $imgSrc }}" alt="" loading="lazy" decoding="async">
                                @else
                                    <span class="co-item__none">No image</span>
                                @endif
                            </div>

                            <div class="co-item__body od-item__body">
                                @if($catalogProduct)
                                    <a class="co-item__name od-item__name" href="{{ route('products.edit', $catalogProduct->product_id) }}">{{ $p->name }}</a>
                                @else
                                    <span class="co-item__name od-item__name">{{ $p->name }}</span>
                                @endif
                                <div class="co-item__meta">
                                    @if(trim((string) $p->model) !== '')
                                        <span class="co-item__sku">{{ $p->model }}</span>
                                    @endif
                                    @foreach($p->options as $opt)
                                        <span class="co-item__var">{{ $opt->name }}: {{ $opt->value }}</span>
                                    @endforeach
                                </div>
                            </div>

                            <div class="od-item__figs">
                                <span class="od-fig od-fig--qty"><span class="od-fig__k">Qty</span>{{ $p->quantity }}</span>
                                <span class="od-fig od-fig--unit">
                                    <span class="od-fig__k">Unit</span>
                                    <x-money :php="$p->price" :foreign="$dual ? $p->foreign_price : null" :foreign-code="$order->currency_code" />
                                </span>
                                <span class="od-fig od-fig--total">
                                    <span class="od-fig__k">Total</span>
                                    <x-money :php="$p->total" :foreign="$dual ? $p->foreign_total : null" :foreign-code="$order->currency_code" />
                                </span>
                            </div>

                            <div class="od-econ">
                                <span class="od-econ__cell">
                                    <span class="od-econ__k">Unit cost</span>
                                    @if($canManageOrders)
                                        <button type="button"
                                                class="od-econ__v od-edit"
                                                data-edit="cost"
                                                data-op-id="{{ $p->order_product_id }}"
                                                data-cost="{{ $unitCost }}"
                                                title="Edit unit cost"
                                                aria-label="Edit unit cost, currently {{ $unitCost > 0 ? \App\Support\Money::base($unitCost) : 'not set' }}">{{ $unitCost > 0 ? \App\Support\Money::base($unitCost) : '-' }}</button>
                                    @else
                                        <span class="od-econ__v">{{ $unitCost > 0 ? \App\Support\Money::base($unitCost) : '-' }}</span>
                                    @endif
                                    @if($dual && $unitCost > 0)
                                        <span class="money-secondary">{{ \App\Support\Money::foreign(\App\Services\OrderCurrencyService::toForeign($unitCost, $orderRate), $order->currency_code) }}</span>
                                    @endif
                                </span>
                                <span class="od-econ__cell">
                                    <span class="od-econ__k">COGS</span>
                                    <span class="od-econ__v">{{ $unitCost > 0 ? \App\Support\Money::base($cogs) : '-' }}</span>
                                    @if($dual && $unitCost > 0)
                                        <span class="money-secondary">{{ \App\Support\Money::foreign(\App\Services\OrderCurrencyService::toForeign($cogs, $orderRate), $order->currency_code) }}</span>
                                    @endif
                                </span>
                                <span class="od-econ__cell">
                                    <span class="od-econ__k">Profit</span>
                                    <span class="od-econ__v {{ $unitCost > 0 && $profit < 0 ? 'od-neg' : '' }}">{{ $unitCost > 0 ? \App\Support\Money::base($profit) : '-' }}</span>
                                </span>
                                <span class="od-econ__cell">
                                    <span class="od-econ__k">Margin</span>
                                    <span class="od-econ__v {{ $unitCost > 0 ? $marginTone($margin) : '' }}">{{ $unitCost > 0 ? number_format($margin, 1) . '%' : '-' }}</span>
                                </span>
                                <span class="od-econ__cell">
                                    <span class="od-econ__k">Markup</span>
                                    <span class="od-econ__v {{ $unitCost > 0 ? $marginTone($markup) : '' }}">{{ $unitCost > 0 ? number_format($markup, 1) . '%' : '-' }}</span>
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
                @else
                    @php $totalRevenue = 0; $totalCogs = 0; @endphp
                    <p class="od-empty">No products on this order.</p>
                @endif
            </section>

            @php
                $feeCodes = array_column(\App\Services\Orders\MarketplaceFeeNormalizer::CODES, 0);
                $notSale = array_merge($feeCodes, ['total', 'escrow_amount', 'total_amount', 'partial_payment_total']);
                $totalRow = $orderTotals->firstWhere('code', 'total');
                $saleTotal = $totalRow ? (float) $totalRow->value : (float) $order->total;
                $saleTotalForeign = $dual
                    ? ($totalRow ? \App\Services\OrderCurrencyService::toForeign($saleTotal, $orderRate) : $order->foreign_total)
                    : null;
                $netProfit = $orderProfit->netProfit();
                $shippingTotal = $orderProfit->shippingCost();
                $shippingEditable = $canManageOrders && $orderProfit->reportedShippingCost == 0.0;
                $cogsForeign = $dual ? \App\Services\OrderCurrencyService::toForeign($orderProfit->cogs, $orderRate) : null;
            @endphp
            <div class="od-money">
            <div class="od-money__ledgers">
            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">Sale</h2>
                </div>

                <div class="od-ledger">
                    @foreach($orderTotals as $ot)
                        @if(! in_array($ot->code, $notSale, true))
                        @php
                            $otForeign = $dual ? \App\Services\OrderCurrencyService::toForeign((float) $ot->value, $orderRate) : null;
                        @endphp
                        <div class="od-ledger__row">
                            <span class="od-ledger__k">{{ $ot->title }}</span>
                            <span class="od-ledger__v"><x-money :php="$ot->value" :foreign="$otForeign" :foreign-code="$order->currency_code" /></span>
                        </div>
                        @endif
                    @endforeach

                    <div class="od-ledger__row od-ledger__row--total">
                        <span class="od-ledger__k">{{ $totalRow->title ?? 'Total' }}</span>
                        <span class="od-ledger__v">
                            <x-money :php="$saleTotal" :foreign="$saleTotalForeign" :foreign-code="$order->currency_code" />
                        </span>
                    </div>
                </div>
            </section>

            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">Profit</h2>
                </div>

                <div class="od-ledger">
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Sales</span>
                        <span class="od-ledger__v">{{ \App\Support\Money::base($orderProfit->revenue) }}</span>
                    </div>

                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Cost of goods</span>
                        <span class="od-ledger__v {{ $orderProfit->cogs > 0 ? 'od-neg' : '' }}">
                            <x-money :php="-$orderProfit->cogs" :foreign="$cogsForeign !== null ? -$cogsForeign : null" :foreign-code="$order->currency_code" />
                        </span>
                    </div>

                    <div class="od-ledger__row">
                        <span class="od-ledger__k">Shipping cost</span>
                        <span class="od-ledger__v">
                            @if($shippingEditable)
                                <button type="button"
                                        class="od-edit {{ $shippingTotal > 0 ? 'od-neg' : '' }}"
                                        data-edit="shipping"
                                        data-cost="{{ $orderProfit->ownShippingCost }}"
                                        title="Edit shipping cost">{{ $shippingTotal > 0 ? '-' . \App\Support\Money::base($shippingTotal) : \App\Support\Money::base(0) }}</button>
                            @else
                                <span class="{{ $shippingTotal > 0 ? 'od-neg' : '' }}">{{ $shippingTotal > 0 ? '-' . \App\Support\Money::base($shippingTotal) : \App\Support\Money::base(0) }}</span>
                            @endif
                        </span>
                    </div>

                    @foreach($orderProfit->fees as $fee)
                    <div class="od-ledger__row">
                        <span class="od-ledger__k">
                            @if($fee['fee_id'] !== null && $canManageOrders)
                                <button type="button"
                                        class="od-edit"
                                        data-edit="fee-label"
                                        data-fee-id="{{ $fee['fee_id'] }}"
                                        data-label="{{ $fee['label'] }}"
                                        data-amount="{{ $fee['amount'] }}"
                                        title="Edit fee label">{{ $fee['label'] }}</button>
                            @else
                                {{ $fee['label'] }}
                            @endif
                        </span>
                        <span class="od-ledger__v od-neg">
                            @if($fee['fee_id'] !== null && $canManageOrders)
                                <button type="button"
                                        class="od-edit od-neg"
                                        data-edit="fee-amount"
                                        data-fee-id="{{ $fee['fee_id'] }}"
                                        data-amount="{{ $fee['amount'] }}"
                                        title="Edit fee amount">-{{ \App\Support\Money::base($fee['amount']) }}</button>
                                <button type="submit"
                                        form="od-fee-del-{{ $fee['fee_id'] }}"
                                        class="od-icon-btn"
                                        title="Remove fee"
                                        aria-label="Remove fee {{ $fee['label'] }}">
                                    <x-ui.icon name="trash" size="13" />
                                </button>
                            @else
                                -{{ \App\Support\Money::base($fee['amount']) }}
                            @endif
                        </span>
                    </div>
                    @endforeach

                    @if($canManageOrders)
                    @foreach($orderProfit->fees as $fee)
                        @if($fee['fee_id'] !== null)
                        <form id="od-fee-del-{{ $fee['fee_id'] }}"
                              method="POST"
                              action="{{ route('orders.destroy_fee', [$order->order_id, $fee['fee_id']]) }}"
                              class="x-sr"
                              data-confirm="Remove this fee?">
                            @csrf
                            @method('DELETE')
                        </form>
                        @endif
                    @endforeach

                    <details class="od-disclose">
                        <summary><x-ui.icon name="plus" size="12" /> Add fee</summary>
                        <form method="POST" action="{{ route('orders.store_fee', $order->order_id) }}" class="od-ledger__form">
                            @csrf
                            <label class="x-sr" for="od-fee-label">Fee label</label>
                            <input type="text" id="od-fee-label" name="label" class="x-input od-fee-label" placeholder="Fee label" required>
                            <label class="x-sr" for="od-fee-amount">Fee amount</label>
                            <input type="number" id="od-fee-amount" name="amount" class="x-input od-fee-amount" placeholder="0.00" step="0.01" min="0.01" required>
                            <x-ui.button size="sm" type="submit">Add fee</x-ui.button>
                        </form>
                    </details>
                    @endif

                    <div class="od-ledger__row od-ledger__row--profit">
                        <span class="od-ledger__k">
                            Net profit
                            <span class="od-ledger__margins">
                                <span class="od-ledger__margin {{ $marginTone($orderProfit->margin()) }}">{{ number_format($orderProfit->margin(), 1) }}% margin</span>
                                <span class="od-ledger__margin {{ $marginTone($orderProfit->markup()) }}">{{ number_format($orderProfit->markup(), 1) }}% markup</span>
                            </span>
                        </span>
                        <span class="od-ledger__v {{ $netProfit >= 0 ? '' : 'od-neg' }}">{{ \App\Support\Money::base($netProfit) }}</span>
                    </div>
                </div>
            </section>
            </div>

            <section class="od-section od-payments">
                <div class="od-section__head">
                    <h2 class="od-section__title">Payments</h2>
                    @if($order->track_payments)
                        @if($balance <= 0 && $orderTotal > 0)
                            <x-ui.badge tone="success">Paid in full</x-ui.badge>
                        @elseif($totalPaid > 0)
                            <x-ui.badge tone="warning">Partly paid</x-ui.badge>
                        @endif
                        @if($canManagePayments)
                        <x-ui.menu label="Receivables options">
                            <button type="submit" form="od-toggle-payments" class="x-menu__item">Remove from receivables</button>
                        </x-ui.menu>
                        <form id="od-toggle-payments"
                              method="POST"
                              action="{{ route('orders.toggle_payments', $order->order_id) }}"
                              class="x-sr"
                              data-confirm="Remove this order from receivables?">
                            @csrf
                        </form>
                        @endif
                    @endif
                </div>
                <div class="od-payments__body">
                    @if($order->track_payments)

                        <div class="od-paid">
                            <span class="od-paid__k">Total paid</span>
                            <span class="od-paid__v">{{ \App\Support\Money::base($totalPaid) }}</span>
                        </div>
                        <div class="od-paid od-paid--balance">
                            <span class="od-paid__k">Balance</span>
                            <span class="od-paid__v {{ $balance > 0 ? 'od-warn' : '' }}">{{ \App\Support\Money::base(max(0, $balance)) }}</span>
                        </div>

                        @if($payments->count())
                            <div>
                                @foreach($payments as $pay)
                                <div class="od-pay">
                                    <span class="od-pay__when">{{ $pay->paid_at->format('M d, Y') }}</span>
                                    <span class="od-pay__amount">{{ \App\Support\Money::base((float) $pay->amount) }}</span>
                                    @if($canManagePayments)
                                    <span class="od-pay__act">
                                        <x-ui.menu label="Payment {{ $pay->id }} actions">
                                            <button type="button"
                                                    class="x-menu__item x-menu__item--danger"
                                                    data-confirm="Delete this payment?"
                                                    data-confirm-submit="od-pay-del-{{ $pay->id }}">Delete payment</button>
                                            <form id="od-pay-del-{{ $pay->id }}"
                                                  method="POST"
                                                  action="{{ route('orders.destroy_payment', [$order->order_id, $pay->id]) }}"
                                                  class="x-sr">
                                                @csrf
                                                @method('DELETE')
                                            </form>
                                        </x-ui.menu>
                                    </span>
                                    @endif
                                    <span class="od-pay__meta">
                                        {{ $pay->payment_method }}@if($pay->reference_no) &middot; {{ $pay->reference_no }}@endif @if($pay->notes) &middot; {{ $pay->notes }}@endif
                                    </span>
                                </div>
                                @endforeach
                            </div>

                        @elseif($orderTotal > 0)
                            <p class="od-empty">No payments recorded.</p>
                        @endif

                        @if($canManagePayments)
                        <details class="od-disclose">
                            <summary><x-ui.icon name="plus" size="12" /> Record a payment</summary>
                            <form method="POST" action="{{ route('orders.store_payment', $order->order_id) }}">
                                @csrf
                                <div class="od-fields">
                                    <div class="od-field">
                                        <label class="od-field__k" for="od-pay-date">Date</label>
                                        <input type="date" id="od-pay-date" name="paid_at" class="x-input" value="{{ date('Y-m-d') }}">
                                    </div>
                                    <div class="od-field">
                                        <label class="od-field__k" for="od-pay-amount">Amount</label>
                                        <input type="number" id="od-pay-amount" name="amount" class="x-input" step="0.01" min="0.01" placeholder="0.00" value="{{ $balance > 0 ? number_format($balance, 2, '.', '') : '' }}">
                                    </div>
                                    <div class="od-field">
                                        <label class="od-field__k" for="od-pay-method">Method</label>
                                        <div class="x-select-wrap">
                                            <select id="od-pay-method" name="payment_method" class="x-input">
                                                @foreach(\App\Services\Sales\Receivables::METHODS as $method)
                                                    <option value="{{ $method }}">{{ $method }}</option>
                                                @endforeach
                                            </select>
                                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                                        </div>
                                    </div>
                                    <div class="od-field">
                                        <label class="od-field__k" for="od-pay-ref">Reference #</label>
                                        <input type="text" id="od-pay-ref" name="reference_no" class="x-input" placeholder="Transaction/check #">
                                    </div>
                                    <div class="od-field od-field--wide">
                                        <label class="od-field__k" for="od-pay-notes">Notes</label>
                                        <input type="text" id="od-pay-notes" name="notes" class="x-input" placeholder="Optional">
                                    </div>
                                </div>
                                <div class="od-form__actions">
                                    <x-ui.button variant="primary" size="sm" type="submit">Add payment</x-ui.button>
                                </div>
                            </form>
                        </details>
                        @endif
                    @elseif($canManageOrders)
                        <p class="od-empty">Not in receivables.</p>
                        <form method="POST" action="{{ route('orders.toggle_payments', $order->order_id) }}">
                            @csrf
                            <x-ui.button size="sm" type="submit">Add to receivables</x-ui.button>
                        </form>
                    @endif

                </div>
            </section>
            </div>
        </div>
    </div>

    <section class="od-section">
        <div class="od-section__head">
            <h2 class="od-section__title">
                Order history
                @if($history->count())<span class="od-section__count">{{ $history->count() }}</span>@endif
            </h2>
        </div>

        @if($history->count())
        <div class="od-time">
            @foreach($history as $h)
            <div class="od-time__row">
                <span class="od-time__when">{{ $h->date_added }}</span>
                <div class="od-time__what">
                    <div class="od-time__status">{{ $h->status->name ?? '-' }}</div>
                    @if($h->user_name)
                        <div class="od-time__who">by {{ $h->user_name }}</div>
                    @endif
                    @if($h->comment)
                        <div class="od-time__note">{{ $h->comment }}</div>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
        @else
            <p class="od-empty">No history records.</p>
        @endif
    </section>
</div>
@endsection
