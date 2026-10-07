@extends('layouts.blotter')
@section('title', trim(($selectedChannelLabel ? $selectedChannelLabel . ' ' : '')
    . (($selectedView ?? 'orders') === 'returns'
        ? ($selectedChannelLabel ? 'returns and refunds' : 'Returns and refunds')
        : ($selectedChannelLabel ? 'fulfilment' : 'Fulfilment'))))

@if($selectedChannelLabel)
    @section('breadcrumb', $selectedChannelLabel)
@endif

@section('content')
@php
    $fh = $summary ?? [];
    $fhChannels = $fh['channels'] ?? [];
    $fhStalled = $fh['stalled'] ?? [];

    $fhOpen = $selectedChannel
        ? collect($fhChannels)->firstWhere('id', $selectedChannel)
        : null;

    $fhWaiting = $fhOpen !== null
        ? (int) ($fhOpen['waiting'] ?? 0)
        : ($fh['waiting'] ?? 0);

    $fhWork = match (true) {
        $fhChannels === [] => null,
        $fhWaiting === 1 => '1 order waiting',
        $fhWaiting > 0 => number_format($fhWaiting) . ' orders waiting',
        default => 'Nothing waiting',
    };

    $fhNamed = array_slice($fhStalled, 0, 2);
    $fhRest = count($fhStalled) - count($fhNamed);
    if ($fhRest > 0) {
        $fhNamed[] = $fhRest . ' more';
    }
    $fhList = count($fhNamed) > 1
        ? implode(', ', array_slice($fhNamed, 0, -1)) . ' and ' . end($fhNamed)
        : ($fhNamed[0] ?? '');

    if ($fhOpen !== null) {
        $fhLink = ($fhOpen['stalled'] ?? false)
            ? (($fhOpen['state'] ?? '') === 'setup' ? 'not connected' : 'not syncing')
            : 'syncing';
    } else {
        $fhLink = $fhStalled === []
            ? 'all ' . count($fhChannels) . ' ' . (count($fhChannels) === 1 ? 'channel' : 'channels') . ' syncing'
            : $fhList . (count($fhStalled) === 1 ? ' is not syncing' : ' are not syncing');
    }

@endphp

<div class="fh-scope"
     @if($fhOpen) style="--ch: {{ \App\Support\ChannelAccent::raw($fhOpen['accent']) }}; --ch-deep: {{ \App\Support\ChannelAccent::deep($fhOpen['accent']) }}; --ch-lift: {{ \App\Support\ChannelAccent::onDark($fhOpen['accent']) }};" @endif>

@include('partials.integration-banners', ['bannerOnly' => $selectedChannel ?? null])

<div class="fh-head">
    @if($selectedChannel)
        <a class="fh-head__back" href="{{ route('channels.fulfilment') }}">
            <x-ui.icon name="chevron-left" size="12" />
            All channels
        </a>
    @endif

    @php $fhIsReturns = ($selectedView ?? 'orders') === 'returns'; @endphp
    <h1 class="x-page-title">
        {{ $selectedChannelLabel ?? 'Fulfilment' }}@if($fhIsReturns) returns and refunds @endif
    </h1>
    @if($fhIsReturns)
        <p class="x-page-sub">
            {{ number_format($fhReturnsTotal ?? 0) }} {{ ($fhReturnsTotal ?? 0) === 1 ? 'return' : 'returns' }}@if(($active_tab ?? 'ALL') !== 'ALL' && isset($tabs[$active_tab])) in {{ $tabs[$active_tab] }}@endif
        </p>
    @elseif($fhWork)
        <p class="fh-head__state">
            <span class="{{ $fhWaiting > 0 ? 'fh-head__work is-live' : 'fh-head__work' }}">{{ $fhWork }}</span>
            <span class="fh-head__sep" aria-hidden="true">&middot;</span>
            <span class="{{ ($fhOpen !== null ? !($fhOpen['stalled'] ?? false) : $fhStalled === []) ? 'fh-head__link' : 'fh-head__link is-bad' }}">{{ $fhLink }}</span>
        </p>
    @else
        <p class="x-page-sub">Work orders across every marketplace</p>
    @endif
</div>

@if(empty($hasMarketplaces))
    <x-ui.empty
        title="No marketplace orders available"
        description="Enable a marketplace integration on the Extensions page, or ask for the orders permission for a channel that is already connected.">
        @if(\App\Support\Navigation::allows('extensions.index'))
            <x-slot:action>
                <x-ui.button variant="secondary" :href="route('extensions.index')">Open Extensions</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
    @php $fhIsReturnsViewNav = ($selectedView ?? 'orders') === 'returns'; @endphp
    @if($fhOpen !== null)
        <div class="fh-band" style="--ch-deep: {{ \App\Support\ChannelAccent::deep($fhOpen['accent']) }}" aria-hidden="true">
            <span class="fh-band__k">Open</span>
            <span class="fh-band__name">{{ $fhOpen['label'] }}</span>
            @if($fhWork)<span class="fh-band__r">{{ $fhWork }}</span>@endif
        </div>
    @endif
    <nav class="fh-grid" aria-label="Fulfilment surfaces">
        @foreach($fhChannels as $fhCard)
            @php
                $fhCardUrl = $fhIsReturnsViewNav && str_contains($fhCard['url'], '/channels/fulfilment')
                    ? $fhCard['url'] . (str_contains($fhCard['url'], '?') ? '&' : '?') . 'view=returns'
                    : $fhCard['url'];
            @endphp
            <a href="{{ $fhCardUrl }}"
               class="fh-card {{ $selectedChannel === $fhCard['id'] ? 'is-open' : '' }} {{ $fhCard['stalled'] ? 'is-stalled' : '' }}"
               style="--ch: {{ \App\Support\ChannelAccent::raw($fhCard['accent']) }}; --ch-deep: {{ \App\Support\ChannelAccent::deep($fhCard['accent']) }}; --ch-lift: {{ \App\Support\ChannelAccent::onDark($fhCard['accent']) }};"
               @if($selectedChannel === $fhCard['id']) aria-current="true" @endif>

                @php
                    $fhChannelId = strtok((string) $fhCard['id'], ':');
                    $fhMark = \App\Extensions\ExtensionImages::has($fhChannelId, 'logo.png');
                @endphp
                <span class="fh-card__top">
                    @if($fhMark)
                        <span class="fh-card__mark"><img src="{{ \App\Extensions\ExtensionImages::url($fhChannelId, 'logo.png') }}" alt=""></span>
                    @endif
                    <span class="fh-card__name">{{ $fhMark && ! empty($fhCard['store']) ? $fhCard['store'] : $fhCard['label'] }}</span>
                </span>

                @php
                    $fhOrphanCount = $fhCard['stalled']
                        && $fhCard['state'] === 'setup'
                        && $fhCard['waiting'] !== null
                        && (int) $fhCard['waiting'] > 0;
                @endphp
                @php
                    $fhIsReturnsView = ($selectedView ?? 'orders') === 'returns';
                    $fhCardReturns = $fhIsReturnsView
                        ? ($fhReturnsCounts[$fhCard['id']] ?? null)
                        : null;
                    $fhStages = !$fhIsReturnsView && !empty($fhCard['stages']) ? $fhCard['stages'] : null;
                @endphp
                @if($fhIsReturnsView)
                    @if($fhCardReturns === null)
                        <span class="fh-card__lead is-untracked">No returns list</span>
                    @else
                        <span class="fh-card__lead x-num {{ $fhCardReturns === 0 ? 'is-clear' : '' }}">{{ number_format($fhCardReturns) }}</span>
                    @endif
                @elseif($fhCard['waiting'] === null)
                    <span class="fh-card__lead is-untracked">Not tracked</span>
                @elseif($fhStages)
                    @php
                        $fhFirst = $fhStages[0];
                        $fhLast = count($fhStages) > 1 ? $fhStages[count($fhStages) - 1] : null;
                        $fhMiddle = count($fhStages) > 2 ? array_slice($fhStages, 1, -1) : [];
                    @endphp
                    <span class="fh-card__figures">
                        <span class="fh-card__fig">
                            <span class="fh-card__fig-l">{{ $fhFirst['count'] === 0 ? 'nothing to pack' : $fhFirst['label'] }}</span>
                            <span class="fh-card__fig-n x-num {{ $fhFirst['count'] === 0 ? 'is-clear' : '' }}{{ $fhOrphanCount ? ' is-stale' : '' }}">{{ number_format($fhFirst['count']) }}</span>
                        </span>
                        @if($fhLast)
                            <span class="fh-card__fig fh-card__fig--end">
                                <span class="fh-card__fig-l">{{ $fhLast['label'] }}</span>
                                <span class="fh-card__fig-n x-num {{ $fhLast['count'] === 0 ? 'is-clear' : '' }}">{{ number_format($fhLast['count']) }}</span>
                            </span>
                        @endif
                    </span>
                    @foreach($fhMiddle as $fhStage)
                        <span class="fh-card__fig-mid"><span class="fh-card__fig-mid-n x-num {{ $fhStage['count'] === 0 ? 'is-clear' : '' }}">{{ number_format($fhStage['count']) }}</span> {{ $fhStage['label'] }}</span>
                    @endforeach
                @else
                    <span class="fh-card__lead x-num {{ $fhCard['waiting'] === 0 ? 'is-clear' : '' }}{{ $fhOrphanCount ? ' is-stale' : '' }}">{{ number_format($fhCard['waiting']) }}</span>
                @endif

                @if($fhCard['stalled'])
                    <span class="fh-card__label {{ $fhCard['state'] === 'setup' ? 'is-bad' : 'is-attn' }}">
                        @if($fhOrphanCount)
                            not connected &middot; last known
                        @else
                            {{ $fhCard['state'] === 'setup' ? 'not connected' : 'not syncing' }}
                        @endif
                    </span>
                @elseif($fhIsReturnsView)
                    <span class="fh-card__label">{{ $fhCardReturns === null ? 'not offered here' : ($fhCardReturns === 1 ? 'return' : 'returns') }}</span>
                @elseif($fhCard['waiting'] === null)
                    <span class="fh-card__label">orders waiting</span>
                @elseif($fhStages)
                @else
                    <span class="fh-card__label">{{ $fhCard['waiting'] === 0 ? 'clear' : 'waiting' }}</span>
                @endif

                @if(! $fhIsReturnsView && $fhCard['todayOrders'] !== null)
                    <span class="fh-card__today">
                        <span class="fh-card__count"><span class="x-num">{{ number_format($fhCard['todayOrders']) }}</span> today</span>
                        @if($fhCard['todayRevenue'] !== null)
                            <span class="fh-card__money x-num">{{ \App\Support\Money::base((float) $fhCard['todayRevenue']) }}</span>
                        @endif
                    </span>
                @endif
            </a>
        @endforeach
    </nav>

    @if($selectedChannel && (session('status') || session('error')))
        <div class="co-notice {{ session('error') ? 'co-notice--fail' : 'co-notice--ok' }}"
             role="status" aria-live="polite">
            <span class="co-notice__title">{{ session('error') ? 'Failed' : 'Done' }}</span>
            <span class="co-notice__body">{{ session('error') ?: session('status') }}</span>
        </div>
    @endif

    @if($selectedChannelBase === 'shopee')
        <div class="x-fh-panel co-page" data-desk-scope id="{{ ($selectedView ?? 'orders') === 'returns' ? 'shopee-returns-page' : 'shopee-orders-page' }}">
            @if(($selectedView ?? 'orders') === 'returns')
                @include('ext-shopee::orders._returns_panel', [
                    'panelBaseUrl' => route('channels.fulfilment'),
                    'panelHiddenParams' => ['channel' => $selectedChannel, 'view' => 'returns'],
                    'panelOrdersUrl' => route('channels.fulfilment', ['channel' => $selectedChannel]),
                ])
            @else
                @include('ext-shopee::orders._panel', [
                    'panelBaseUrl' => route('channels.fulfilment'),
                    'panelHiddenParams' => ['channel' => $selectedChannel],
                    'panelReturnsUrl' => route('channels.fulfilment', ['channel' => $selectedChannel, 'view' => 'returns']),
                ])
            @endif
        </div>
    @elseif($selectedChannelBase === 'lazada')
        <div class="x-fh-panel co-page" data-desk-scope id="lazada-orders-page"
             data-awb-url="{{ session('lazada_awb_url') }}"
             data-pack-print-order="{{ session('open_pack_print_modal_order_id') }}"
             data-awb-template="{{ route('ext.lazada.orders.awb', ['orderId' => '__OID__']) }}"
             data-ship-print-template="{{ route('ext.lazada.orders.ship_print_post', ['orderId' => '__OID__']) }}"
             data-recreate-template="{{ route('ext.lazada.orders.recreate_package', ['orderId' => '__OID__']) }}">
            @if(($selectedView ?? 'orders') === 'returns')
                @include('ext-lazada::orders._returns_panel', [
                    'panelBaseUrl' => route('channels.fulfilment'),
                    'panelHiddenParams' => ['channel' => $selectedChannel, 'view' => 'returns'],
                    'panelOrdersUrl' => route('channels.fulfilment', ['channel' => $selectedChannel]),
                ])
            @else
                @include('ext-lazada::orders._panel', [
                    'panelBaseUrl' => route('channels.fulfilment'),
                    'panelHiddenParams' => ['channel' => $selectedChannel],
                    'panelReturnsUrl' => route('channels.fulfilment', ['channel' => $selectedChannel, 'view' => 'returns']),
                ])
            @endif
        </div>
    @elseif($selectedChannelBase === 'tiktok')
        <div class="x-fh-panel co-page" data-desk-scope id="{{ ($selectedView ?? 'orders') === 'returns' ? 'tiktok-returns-page' : 'tiktok-orders-page' }}"
             data-awb-url="{{ $last_result['awb_url'] ?? '' }}">
            @if(($selectedView ?? 'orders') === 'returns')
                @include('ext-tiktok::orders._returns_panel', [
                    'panelBaseUrl' => route('channels.fulfilment'),
                    'panelHiddenParams' => ['channel' => $selectedChannel, 'view' => 'returns'],
                    'panelOrdersUrl' => route('channels.fulfilment', ['channel' => $selectedChannel]),
                ])
            @else
                @include('ext-tiktok::orders._panel', [
                    'panelBaseUrl' => route('channels.fulfilment'),
                    'panelHiddenParams' => ['channel' => $selectedChannel],
                    'panelReturnsUrl' => route('channels.fulfilment', ['channel' => $selectedChannel, 'view' => 'returns']),
                ])
            @endif
        </div>
    @elseif($selectedChannelBase === 'ventacart')
        <div class="x-fh-panel co-page" data-desk-scope id="ventacart-orders-page">
            @include('ext-ventacart::orders._panel', [
                'panelBaseUrl' => route('channels.fulfilment'),
                'panelHiddenParams' => ['channel' => $selectedChannel],
            ])
        </div>
    @elseif($selectedChannelBase === 'woocommerce')
        <div class="x-fh-panel co-page" data-desk-scope id="woocommerce-orders-page">
            @include('ext-woocommerce::orders._panel', [
                'panelBaseUrl' => route('channels.fulfilment'),
                'panelHiddenParams' => ['channel' => $selectedChannel],
            ])
        </div>
    @elseif($selectedChannelBase === 'opencart')
        <div class="x-fh-panel co-page" data-desk-scope id="opencart-orders-page">
            @include('ext-opencart::orders._panel', [
                'panelBaseUrl' => route('channels.fulfilment'),
                'panelHiddenParams' => ['channel' => $selectedChannel],
            ])
        </div>
    @elseif($selectedChannelBase === 'shopify')
        <div class="x-fh-panel co-page" data-desk-scope id="shopify-orders-page">
            @include('ext-shopify::orders._panel', [
                'panelBaseUrl' => route('channels.fulfilment'),
                'panelHiddenParams' => ['channel' => $selectedChannel],
            ])
        </div>
    @elseif($selectedChannelBase === 'pedallion')
        <div class="x-fh-panel co-page" data-desk-scope id="pedallion-orders-page">
            @include('ext-pedallion::orders._panel', [
                'panelBaseUrl' => route('channels.fulfilment'),
                'panelHiddenParams' => ['channel' => 'pedallion'],
            ])
        </div>
    @else
        <div class="fh-today">
            <span class="fh-today__k">Today</span>

            @if(($fh['todayOrders'] ?? 0) > 0)
                <span class="fh-today__v">
                    <span class="x-num">{{ number_format($fh['todayOrders']) }}</span>
                    {{ $fh['todayOrders'] === 1 ? 'order' : 'orders' }}
                </span>
                <span class="fh-today__sep" aria-hidden="true">&middot;</span>
                <span class="fh-today__v x-num">{{ \App\Support\Money::base((float) ($fh['todayRevenue'] ?? 0)) }}</span>
                @if(($fh['todayTracked'] ?? 0) < count($fhChannels))
                    <span class="fh-today__sep" aria-hidden="true">&middot;</span>
                    <span class="fh-today__note">
                        from {{ $fh['todayTracked'] }} of {{ count($fhChannels) }} channels
                    </span>
                @endif
            @else
                <span class="fh-today__v is-quiet">No orders yet</span>
                @if($fh['lastOrderAt'] ?? null)
                    <span class="fh-today__sep" aria-hidden="true">&middot;</span>
                    <span class="fh-today__note">
                        last order {{ \Illuminate\Support\Carbon::instance($fh['lastOrderAt'])->diffForHumans() }}
                    </span>
                @endif
            @endif
        </div>

        <div class="fh-body">
            <section class="fh-sec">
                <header class="fh-sec__head">
                    <h2 class="fh-sec__title">Today's orders</h2>
                    @if(! empty($fh['feed']))
                        <a class="fh-sec__more" href="{{ route('orders.index') }}">View all orders</a>
                    @endif
                </header>

                @if(empty($fh['feed']))
                    <p class="fh-empty">Nothing has come in yet today.</p>
                @else
                    <ul class="fh-feed">
                        @foreach($fh['feed'] as $fhRow)
                            @php($fhOrder = $fhRow['order'])
                            @php($fhTab = $fhRow['tab'])
                            <li class="fh-feed__row">
                                <span class="fh-feed__time x-num">
                                    {{ $fhOrder->orderedAt ? \Illuminate\Support\Carbon::instance($fhOrder->orderedAt)->format('H:i') : '' }}
                                </span>

                                <span class="fh-feed__chan">
                                    <svg class="fh-feed__mark" width="7" height="7" viewBox="0 0 7 7"
                                         aria-hidden="true" focusable="false">
                                        <rect width="7" height="7" rx="2" fill="{{ $fhTab->accent }}"></rect>
                                    </svg>
                                    {{ $fhTab->label }}
                                </span>

                                <span class="fh-feed__ref x-num">
                                    @if($fhOrder->url)
                                        <a href="{{ $fhOrder->url }}">{{ $fhOrder->reference }}</a>
                                    @else
                                        {{ $fhOrder->reference }}
                                    @endif
                                </span>

                                <span class="fh-feed__who">{{ $fhOrder->customerName ?: 'Not provided' }}</span>
                                <span class="fh-feed__total x-num">{{ \App\Support\Money::base((float) $fhOrder->total) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="fh-sec">
                <header class="fh-sec__head">
                    <h2 class="fh-sec__title">Top products today</h2>
                </header>

                @if(empty($fh['topProducts']))
                    <p class="fh-empty">Nothing sold yet today.</p>
                @else
                    <ol class="fh-top">
                        @foreach($fh['topProducts'] as $fhIndex => $fhProduct)
                            <li class="fh-top__row">
                                <span class="fh-top__rank x-num" aria-hidden="true">{{ $fhIndex + 1 }}</span>
                                <span class="fh-top__name">
                                    {{ $fhProduct['name'] }}
                                    <span class="fh-top__meta">
                                        <x-fulfilment.variation :sku="$fhProduct['sku']" :fallback="$fhProduct['variation'] ?? null" />
                                        <span class="fh-top__sku">{{ $fhProduct['sku'] }}</span>
                                    </span>
                                </span>
                                <span class="fh-top__qty x-num">{{ number_format($fhProduct['qtySold']) }}</span>
                                <span class="fh-top__rev x-num">{{ \App\Support\Money::base((float) $fhProduct['revenue']) }}</span>
                            </li>
                        @endforeach
                    </ol>
                    @if(($fh['topProductsMore'] ?? 0) > 0)
                        <p class="fh-top__more">and <span class="x-num">{{ number_format($fh['topProductsMore']) }}</span> more {{ $fh['topProductsMore'] === 1 ? 'product' : 'products' }} sold today</p>
                    @endif
                @endif
            </section>
        </div>
    @endif
@endif

</div>
@endsection
