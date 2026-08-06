@extends('layouts.shell')
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
<div class="x-fh-head">
    <h1 class="x-page-title">Fulfilment</h1>
    <p class="x-page-sub">Work orders across every marketplace</p>
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
    @php
        // Strip data. A counter closure that is null means the channel does
        // not measure that figure at all, which is not the same statement as
        // a measured zero. OrderTab's accessors coalesce null to 0 for two
        // of the three, so the closures are tested directly here and the
        // figure is dropped rather than printed as a fabricated zero.
        $fhCards = [];
        $fhWaiting = 0;
        $fhWaitingTracked = 0;
        $fhToday = 0;
        $fhTodayTracked = 0;
        $fhRevenue = 0.0;
        $fhRevenueTracked = 0;

        foreach ($orderTabs as $fhTab) {
            $fhUnprocessed = $fhTab->unprocessedCounter === null ? null : $fhTab->unprocessedCount();
            $fhTabToday = $fhTab->dailyOrdersCounter === null ? null : $fhTab->dailyOrdersCount();
            $fhTabRevenue = $fhTab->dailyRevenueCounter === null ? null : $fhTab->dailyRevenue();

            if ($fhUnprocessed !== null) {
                $fhWaiting += $fhUnprocessed;
                $fhWaitingTracked++;
            }
            if ($fhTabToday !== null) {
                $fhToday += $fhTabToday;
                $fhTodayTracked++;
            }
            if ($fhTabRevenue !== null) {
                $fhRevenue += $fhTabRevenue;
                $fhRevenueTracked++;
            }

            $fhCards[] = [
                'id' => $fhTab->id,
                'name' => $fhTab->label,
                // Shopee's orders render in place on this page. Every other
                // channel still navigates to its own orders page until its
                // panel is extracted the same way (spec D4).
                'url' => $fhTab->id === 'shopee'
                    ? route('integrations.orders', ['channel' => 'shopee'])
                    : route($fhTab->routeName, $fhTab->routeParams),
                'unprocessed' => $fhUnprocessed,
                'today' => $fhTabToday,
                'revenue' => $fhTabRevenue,
            ];
        }

        $fhChannelCount = count($orderTabs);
        // Says how much of the total the total actually covers, but only
        // when that is worth saying: with every channel measuring, the
        // qualifier is noise on a card already titled Summary, and the
        // shorter label is what lets a third card show at the edge of a
        // phone screen and advertise that the strip scrolls.
        $fhSummaryLabel = $fhWaitingTracked === $fhChannelCount
            ? 'to process'
            : 'to process, ' . $fhWaitingTracked . ' of ' . $fhChannelCount . ' channels';
    @endphp

    {{-- The strip is the page's spine: one card per fulfillable surface,
         each leading with the count of orders waiting on a human. Colour
         does exactly one job here, marking which surface is open, so the
         cards carry no marketplace brand hues; the channel's name is what
         identifies it. Scrolls inside itself when the cards outrun the
         width, so the page never scrolls sideways. --}}
    <nav class="x-fh-strip" aria-label="Fulfilment surfaces">
        <a href="{{ route('integrations.orders') }}"
           class="x-fh-card {{ $selectedChannel === null ? 'is-active' : '' }}"
           @if($selectedChannel === null) aria-current="page" @endif>
            <span class="x-fh-card__name">Summary</span>
            @if($fhWaitingTracked === 0)
                <span class="x-fh-card__lead is-untracked">Not tracked</span>
                <span class="x-fh-card__label">across {{ $fhChannelCount }} {{ $fhChannelCount === 1 ? 'channel' : 'channels' }}</span>
            @else
                <span class="x-fh-card__lead x-num {{ $fhWaiting === 0 ? 'is-clear' : '' }}">{{ number_format($fhWaiting) }}</span>
                <span class="x-fh-card__label">{{ $fhSummaryLabel }}</span>
            @endif
            {{-- Totals of what is measured. If not one channel counts today's
                 orders, there is no total to print and a zero here would be a
                 number nobody produced. --}}
            @if($fhTodayTracked > 0 || $fhRevenueTracked > 0)
                <span class="x-fh-card__meta">
                    @if($fhTodayTracked > 0)
                        <span class="x-num">{{ number_format($fhToday) }}</span> today
                    @endif
                    @if($fhTodayTracked > 0 && $fhRevenueTracked > 0)
                        <span class="x-fh-card__sep">·</span>
                    @endif
                    @if($fhRevenueTracked > 0)
                        <span class="x-num">{{ \App\Support\Money::base($fhRevenue) }}</span>
                    @endif
                </span>
            @endif
        </a>

        {{-- Summary aggregates the channels rather than sitting beside them
             as a peer, and the rule says so without a word of copy. --}}
        <span class="x-fh-strip__split" aria-hidden="true"></span>

        @foreach($fhCards as $fhCard)
            <a href="{{ $fhCard['url'] }}"
               class="x-fh-card {{ $selectedChannel === $fhCard['id'] ? 'is-active' : '' }}"
               @if($selectedChannel === $fhCard['id']) aria-current="page" @endif>
                <span class="x-fh-card__name">{{ $fhCard['name'] }}</span>
                @if($fhCard['unprocessed'] === null)
                    <span class="x-fh-card__lead is-untracked">Not tracked</span>
                    <span class="x-fh-card__label">orders to process</span>
                @else
                    <span class="x-fh-card__lead x-num {{ $fhCard['unprocessed'] === 0 ? 'is-clear' : '' }}">{{ number_format($fhCard['unprocessed']) }}</span>
                    <span class="x-fh-card__label">to process</span>
                @endif
                @if($fhCard['today'] !== null || $fhCard['revenue'] !== null)
                    <span class="x-fh-card__meta">
                        @if($fhCard['today'] !== null)
                            <span class="x-num">{{ number_format($fhCard['today']) }}</span> today
                        @endif
                        @if($fhCard['today'] !== null && $fhCard['revenue'] !== null)
                            <span class="x-fh-card__sep">·</span>
                        @endif
                        @if($fhCard['revenue'] !== null)
                            <span class="x-num">{{ \App\Support\Money::base($fhCard['revenue']) }}</span>
                        @endif
                    </span>
                @endif
            </a>
        @endforeach
    </nav>

    @if($selectedChannel === 'shopee')
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
    @else
        <section class="x-fh-sec">
            <header class="x-fh-sec__head">
                <h2 class="x-fh-sec__title">Recent orders</h2>
                <span class="x-fh-sec__meta">Newest {{ count($recentOrders) }} across every channel</span>
            </header>

            @if(empty($recentOrders))
                <x-ui.empty title="No orders yet"
                            description="Orders appear here as soon as a channel syncs one." />
            @else
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <th class="x-fh-col-ref">Order</th>
                            <th class="x-col-chan">Channel</th>
                            <th>Customer</th>
                            <th class="x-col-stat">Status</th>
                            <th class="x-td-num x-col-total">Total</th>
                            <th class="x-fh-col-when">Placed</th>
                        </tr>
                    </x-slot:head>

                    @foreach($recentOrders as $fhRow)
                        @php
                            $fhOrder = $fhRow['order'];
                            $fhSourceTab = $fhRow['tab'];
                            // "venta:2" and "opencart:1" carry a store id the
                            // badge tone does not; the tone is per channel.
                            $fhTone = strtok((string) $fhSourceTab->id, ':');

                            // Channels hand back their own status string and
                            // some of them are the marketplace's raw constant
                            // ("READY_TO_SHIP"). A screaming-snake token is
                            // not something to put in front of a person, so
                            // an all-caps-and-underscores value becomes an
                            // ordinary sentence. Anything already written for
                            // humans ("Complete", "pending") is left alone
                            // apart from its first letter.
                            $fhStatus = (string) $fhOrder->statusLabel;
                            if (preg_match('/^[A-Z0-9_]+$/', $fhStatus)) {
                                $fhStatus = str_replace('_', ' ', strtolower($fhStatus));
                            }
                            $fhStatus = ucfirst($fhStatus);
                        @endphp
                        <tr>
                            <td class="x-fh-col-ref" data-label="Order">
                                @if($fhOrder->url)
                                    <a class="x-row-link x-num" href="{{ $fhOrder->url }}">{{ $fhOrder->reference }}</a>
                                @else
                                    <span class="x-num">{{ $fhOrder->reference }}</span>
                                @endif
                            </td>
                            {{-- Channel drops the dot and lets the tinted text
                                 carry identity, the same rule /sales/orders
                                 follows: a dot means "this is a state", no dot
                                 means "this is where it came from". --}}
                            <td class="x-col-chan" data-label="Channel">
                                <x-ui.badge :tone="$fhTone" :dot="false">{{ $fhSourceTab->label }}</x-ui.badge>
                            </td>
                            {{-- Not "Guest": a marketplace order always has a
                                 buyer, so an empty name means this channel
                                 did not hand one over, which is a different
                                 statement and should not look like a value. --}}
                            @if($fhOrder->customerName)
                                <td class="x-cell-strong" data-label="Customer">{{ $fhOrder->customerName }}</td>
                            @else
                                <td class="x-cell-muted" data-label="Customer">Not provided</td>
                            @endif
                            <td class="x-col-stat" data-label="Status">
                                <x-ui.badge :tone="\App\Support\OrderStatusTone::for($fhStatus)">{{ $fhStatus }}</x-ui.badge>
                            </td>
                            <td class="x-td-num x-col-total" data-label="Total">
                                <x-money :php="(float) $fhOrder->total" />
                            </td>
                            <td class="x-num x-cell-muted x-fh-col-when" data-label="Placed">
                                {{ $fhOrder->orderedAt ? \Illuminate\Support\Carbon::instance($fhOrder->orderedAt)->diffForHumans(null, true) . ' ago' : 'Unknown' }}
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </section>

        <section class="x-fh-sec">
            <header class="x-fh-sec__head">
                <h2 class="x-fh-sec__title x-fh-sec__title--minor">Top products today</h2>
                <span class="x-fh-sec__meta">{{ count($topProducts) }} {{ count($topProducts) === 1 ? 'product' : 'products' }}, every channel combined</span>
            </header>

            @if(empty($topProducts))
                <x-ui.empty title="Nothing sold yet today"
                            description="This list rebuilds from today's orders as they arrive." />
            @else
                <ol class="x-fh-board">
                    @foreach($topProducts as $fhIndex => $fhProduct)
                        <li class="x-fh-prod">
                            <span class="x-fh-prod__rank x-num">{{ $fhIndex + 1 }}</span>
                            {{-- With no image the frame drops its border and
                                 stays a plain recess. An empty bordered box
                                 reads as a picture that failed to load. --}}
                            <span class="x-fh-prod__thumb {{ $fhProduct['imageUrl'] ? '' : 'is-empty' }}">
                                @if($fhProduct['imageUrl'])
                                    <img src="{{ $fhProduct['imageUrl'] }}" alt="" loading="lazy" class="x-fh-prod__img">
                                @endif
                            </span>
                            <span class="x-fh-prod__body">
                                <span class="x-fh-prod__name">{{ $fhProduct['name'] }}</span>
                                <span class="x-fh-prod__sku x-num">{{ $fhProduct['sku'] }}</span>
                                <span class="x-fh-prod__from">
                                    @foreach($fhProduct['contributors'] as $fhContribIndex => $fhContrib)
                                        @if($fhContribIndex > 0)<span class="x-fh-prod__sep">·</span>@endif
                                        {{ $fhContrib['label'] }}
                                        <span class="x-num">{{ number_format($fhContrib['qty']) }}</span>
                                    @endforeach
                                </span>
                            </span>
                            <span class="x-fh-prod__stats">
                                <span class="x-fh-prod__qty x-num">{{ number_format($fhProduct['qtySold']) }}</span>
                                <span class="x-fh-prod__rev x-num">{{ \App\Support\Money::base((float) $fhProduct['revenue']) }}</span>
                            </span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    @endif
@endif
@endsection
