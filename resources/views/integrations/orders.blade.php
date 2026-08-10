@extends('layouts.blotter')
@section('title', 'Fulfilment')

{{-- The trail's first segment comes from the nav tree automatically (the
     top-level Fulfilment entry in App\Support\Navigation). This adds the
     selected channel as the current segment, so the bar reads
     "Fulfilment / Shopee" with a channel open and plain "Fulfilment"
     without one. --}}
@if($selectedChannelLabel)
    @section('breadcrumb', $selectedChannelLabel)
@endif

@section('content')
@php
    $fh = $summary ?? [];
    $fhChannels = $fh['channels'] ?? [];
    $fhStalled = $fh['stalled'] ?? [];
    $fhWaiting = $fh['waiting'] ?? 0;

    // The one sentence the page exists to say, assembled so the head reads as
    // a statement rather than as a row of figures. Work first, because that is
    // what a person opens this page to find out; the connection clause second,
    // because a channel that stopped talking makes every count above it
    // suspect and a reader has to be told before they trust the calm ones.
    $fhWork = match (true) {
        $fhChannels === [] => null,
        $fhWaiting === 1 => '1 order waiting',
        $fhWaiting > 0 => number_format($fhWaiting) . ' orders waiting',
        default => 'Nothing waiting',
    };

    // "Lazada and Shopee and 3 more" was what the first version produced.
    // Comma between all but the last pair, "and" once.
    $fhNamed = array_slice($fhStalled, 0, 2);
    $fhRest = count($fhStalled) - count($fhNamed);
    if ($fhRest > 0) {
        $fhNamed[] = $fhRest . ' more';
    }
    $fhList = count($fhNamed) > 1
        ? implode(', ', array_slice($fhNamed, 0, -1)) . ' and ' . end($fhNamed)
        : ($fhNamed[0] ?? '');

    $fhLink = $fhStalled === []
        ? 'all ' . count($fhChannels) . ' ' . (count($fhChannels) === 1 ? 'channel' : 'channels') . ' syncing'
        : $fhList . (count($fhStalled) === 1 ? ' is not syncing' : ' are not syncing');
@endphp

<div class="fh-head">
    <h1 class="x-page-title">Fulfilment</h1>
    @if($fhWork)
        <p class="fh-head__state">
            <span class="{{ $fhWaiting > 0 ? 'fh-head__work is-live' : 'fh-head__work' }}">{{ $fhWork }}</span>
            <span class="fh-head__sep" aria-hidden="true">&middot;</span>
            <span class="{{ $fhStalled === [] ? 'fh-head__link' : 'fh-head__link is-bad' }}">{{ $fhLink }}</span>
        </p>
    @else
        <p class="x-page-sub">Work orders across every marketplace</p>
    @endif
</div>

@if(empty($hasMarketplaces))
    <x-ui.empty
        title="No marketplace orders available"
        description="Enable a marketplace integration on the Extensions page, or ask for the orders permission for a channel that is already connected.">
        {{-- Only offered to someone who can actually open that page.
             Navigation::allows() reads the route's own perm: middleware, so
             this cannot drift out of step with routes/web.php. --}}
        @if(\App\Support\Navigation::allows('extensions.index'))
            <x-slot:action>
                <x-ui.button variant="secondary" :href="route('extensions.index')">Open Extensions</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
    {{-- RIGHT NOW.

         One card per fulfillable surface, filling the width and shrinking as
         channels are enabled: repeat(auto-fit, minmax(...)) does the whole of
         "stretch, shrink, floor, then wrap to the next row" in one rule, with
         no JS and no per-count breakpoints. A card wide enough also prints
         today's contribution; at the floor it drops to the count alone.

         Each card carries its channel's connection state, because a channel
         that stopped syncing three weeks ago would otherwise render a serene
         "0" indistinguishable from a channel that is genuinely clear - on a
         page whose whole job is "is anything waiting", that is the one thing
         it must not do. The staleness is a caveat printed under the count,
         never a substitute for it. --}}
    <nav class="fh-grid" aria-label="Fulfilment surfaces">
        @foreach($fhChannels as $fhCard)
            <a href="{{ $fhCard['url'] }}"
               class="fh-card {{ $selectedChannel === $fhCard['id'] ? 'is-open' : '' }} {{ $fhCard['stalled'] ? 'is-stalled' : '' }}"
               @if($selectedChannel === $fhCard['id']) aria-current="page" @endif>

                <span class="fh-card__top">
                    {{-- The channel's own brand hue, drawn rather than set by
                         an inline style, the same route the dashboard's chips
                         take. Two stores of one channel share a hue, so the
                         name carries the store and the mark carries the
                         channel. --}}
                    <svg class="fh-card__mark" width="8" height="8" viewBox="0 0 8 8"
                         aria-hidden="true" focusable="false">
                        <rect width="8" height="8" rx="2" fill="{{ $fhCard['accent'] }}"></rect>
                    </svg>
                    <span class="fh-card__name">{{ $fhCard['label'] }}</span>
                </span>

                {{-- The count first, always, when it is tracked. A stale
                     channel can still have real orders waiting on a person,
                     and the staleness is a caveat on the number rather than a
                     reason to withhold it. --}}
                @if($fhCard['waiting'] === null)
                    <span class="fh-card__lead is-untracked">Not tracked</span>
                @else
                    <span class="fh-card__lead x-num {{ $fhCard['waiting'] === 0 ? 'is-clear' : '' }}">{{ number_format($fhCard['waiting']) }}</span>
                @endif

                {{-- and the caveat under it, which is what stops a zero from
                     a silent channel reading as "all clear". --}}
                @if($fhCard['stalled'])
                    {{-- Not connected is worse than not syncing: one has no
                         credentials at all, the other has them and has gone
                         quiet. The words carry it as well as the colour. --}}
                    <span class="fh-card__label {{ $fhCard['state'] === 'setup' ? 'is-bad' : 'is-attn' }}">
                        {{ $fhCard['state'] === 'setup' ? 'not connected' : 'not syncing' }}
                    </span>
                @elseif($fhCard['waiting'] === null)
                    <span class="fh-card__label">orders waiting</span>
                @else
                    <span class="fh-card__label">{{ $fhCard['waiting'] === 0 ? 'clear' : 'waiting' }}</span>
                @endif

                {{-- Today's contribution, hidden by the grid at the narrow end
                     rather than by a viewport breakpoint: it is the card's own
                     width that decides whether there is room, and the grid is
                     what sets that. --}}
                @if($fhCard['todayOrders'] !== null)
                    <span class="fh-card__today">
                        <span class="x-num">{{ number_format($fhCard['todayOrders']) }}</span> today
                        @if($fhCard['todayRevenue'] !== null)
                            <span class="fh-card__sep">&middot;</span>
                            <span class="x-num">{{ \App\Support\Money::base((float) $fhCard['todayRevenue']) }}</span>
                        @endif
                    </span>
                @endif
            </a>
        @endforeach
    </nav>

    @if($selectedChannel && (session('status') || session('error')))
        {{-- The outcome of a write performed from inside a panel on THIS
             page. layouts/channel.blade.php gives the seller centers a flash
             region through partials/legacy-page-contract; layouts/shell has
             none, so a fetch run from here used to redirect back with a
             message nothing rendered. Channels that carry their own result
             through the panel service (Shopee, Lazada and TikTok all use a
             *_orders_last_result session key) are unaffected: this reads the
             plain status/error keys only, so nothing renders twice. --}}
        <div class="co-notice {{ session('error') ? 'co-notice--fail' : 'co-notice--ok' }}">
            <span class="co-notice__title">{{ session('error') ? 'Failed' : 'Done' }}</span>
            <span class="co-notice__body">{{ session('error') ?: session('status') }}</span>
        </div>
    @endif

    @if($selectedChannelBase === 'shopee')
        {{-- The id the seller center's own page carries, so
             resources/js/pages/shopee-orders.js binds here unchanged.

             Nothing separates this wrapper from the include on purpose:
             Illuminate\View\Engines\PhpEngine::evaluatePath() ltrim()s every
             rendered view, including every @include, so any blank line or
             indentation written at this boundary is stripped before it ever
             reaches the page. All spacing above the panel comes from
             .x-fh-panel's own margin in pages/fulfilment.css instead, which
             is where it belongs. --}}
        <div class="x-fh-panel co-page" id="shopee-orders-page">
            @include('ext-shopee::orders._panel', [
                'panelBaseUrl' => route('integrations.orders'),
                'panelHiddenParams' => ['channel' => 'shopee'],
            ])
        </div>
    @elseif($selectedChannelBase === 'lazada')
        {{-- Same id and the same page-level data attributes Lazada's own
             orders/index.blade.php carries, so resources/js/pages/
             lazada-orders.js (which reads them off the root element, not
             through the panel service) binds here unchanged whether this
             renders on the seller center or the hub. --}}
        <div class="x-fh-panel co-page" id="lazada-orders-page"
             data-awb-url="{{ session('lazada_awb_url') }}"
             data-pack-print-order="{{ session('open_pack_print_modal_order_id') }}"
             data-awb-template="{{ route('ext.lazada.orders.awb', ['orderId' => '__OID__']) }}"
             data-ship-print-template="{{ route('ext.lazada.orders.ship_print_post', ['orderId' => '__OID__']) }}"
             data-recreate-template="{{ route('ext.lazada.orders.recreate_package', ['orderId' => '__OID__']) }}">
            @include('ext-lazada::orders._panel', [
                'panelBaseUrl' => route('integrations.orders'),
                'panelHiddenParams' => ['channel' => 'lazada'],
            ])
        </div>
    @elseif($selectedChannelBase === 'tiktok')
        {{-- Same id and the same data-awb-url TikTok's own
             orders/index.blade.php carries, so resources/js/pages/
             tiktok-orders.js (which reads it off the root element, not
             through the panel service) binds here unchanged whether this
             renders on the seller center or the hub. The value comes from
             the panel service's own last_result, so a ship performed from
             this page hands its waybill back here rather than only on the
             seller center. --}}
        <div class="x-fh-panel co-page" id="tiktok-orders-page"
             data-awb-url="{{ $last_result['awb_url'] ?? '' }}">
            @include('ext-tiktok::orders._panel', [
                'panelBaseUrl' => route('integrations.orders'),
                'panelHiddenParams' => ['channel' => 'tiktok'],
            ])
        </div>
    @elseif($selectedChannelBase === 'venta')
        {{-- The id the store's own seller center carries, so
             resources/js/pages/venta-orders.js (the selection bar) binds
             here unchanged.

             Multi-store channels are the only ones that name their store
             above the panel. On the seller center the sidebar head and the
             breadcrumb say which shop you are in; here the only other place
             it appears is the card that was clicked, and an operator about
             to delete orders should not have to work it out from a
             highlighted card. One line, written once for every multi-store
             channel rather than copied into each channel's partial. --}}
        <div class="x-fh-panel co-page" id="venta-orders-page">
            <div class="x-fh-panel__scope">
                <span class="x-fh-panel__scope-label">Store</span>
                <span class="x-fh-panel__scope-value">{{ $storeLabel }}</span>
            </div>
            @include('ext-venta::orders._panel', [
                'panelBaseUrl' => route('integrations.orders'),
                'panelHiddenParams' => ['channel' => $selectedChannel],
            ])
        </div>
    @elseif($selectedChannelBase === 'opencart')
        {{-- Same shape as Venta's. This channel carries no page module of
             its own, but the id is here for the same reason every other
             panel wrapper has one: it is what the page CSS scopes its two
             responsive rules to. --}}
        <div class="x-fh-panel co-page" id="opencart-orders-page">
            <div class="x-fh-panel__scope">
                <span class="x-fh-panel__scope-label">Store</span>
                <span class="x-fh-panel__scope-value">{{ $storeLabel }}</span>
            </div>
            @include('ext-opencart::orders._panel', [
                'panelBaseUrl' => route('integrations.orders'),
                'panelHiddenParams' => ['channel' => $selectedChannel],
            ])
        </div>
    @elseif($selectedChannelBase === 'shopify')
        {{-- Same shape as Venta's, and the same id the store's own seller
             center carries so resources/js/pages/shopify-orders.js (the raw
             payload dialog) binds here unchanged. --}}
        <div class="x-fh-panel co-page" id="shopify-orders-page">
            <div class="x-fh-panel__scope">
                <span class="x-fh-panel__scope-label">Store</span>
                <span class="x-fh-panel__scope-value">{{ $storeLabel }}</span>
            </div>
            @include('ext-shopify::orders._panel', [
                'panelBaseUrl' => route('integrations.orders'),
                'panelHiddenParams' => ['channel' => $selectedChannel],
            ])
        </div>
    @elseif($selectedChannelBase === 'pedallion')
        {{-- Single-store, so no store line above it: the channel name on the
             card that was clicked is the whole of the scope. Same id its own
             seller center carries, which is what the page CSS scopes its
             responsive rules to. --}}
        <div class="x-fh-panel co-page" id="pedallion-orders-page">
            @include('ext-pedallion::orders._panel', [
                'panelBaseUrl' => route('integrations.orders'),
                'panelHiddenParams' => ['channel' => 'pedallion'],
            ])
        </div>
    @else
        {{-- TODAY.

             One line, not a row of metric tiles. Three shadowed boxes reading
             "13 orders", "P28,340", "4 channels" is the reflex answer to an
             empty band and it is what makes a page read as templated; the same
             three figures in a sentence take one line and say more. --}}
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
                {{-- The second clause is the whole point of this line on a
                     quiet day: it is what separates "nothing has sold yet"
                     from "nothing has reached us in three weeks", and those
                     are not the same morning. --}}
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
                    {{-- Time leads, because on a feed that is today by
                         definition "when" is the scannable column and the
                         reference is the thing you click. --}}
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
                                    <span class="fh-top__sku">{{ $fhProduct['sku'] }}</span>
                                </span>
                                <span class="fh-top__qty x-num">{{ number_format($fhProduct['qtySold']) }}</span>
                                <span class="fh-top__rev x-num">{{ \App\Support\Money::base((float) $fhProduct['revenue']) }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>
    @endif
@endif
@endsection
