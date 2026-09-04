@extends('layouts.blotter')
{{-- The tab and the history entry name the screen, not the section. Both
     views live at the same route, so a static title left every returns tab
     in the browser saying "Fulfilment" - and with several channels open at
     once, that is several identical tabs. --}}
@section('title', trim(($selectedChannelLabel ? $selectedChannelLabel . ' ' : '')
    . (($selectedView ?? 'orders') === 'returns'
        ? ($selectedChannelLabel ? 'returns and refunds' : 'Returns and refunds')
        : ($selectedChannelLabel ? 'fulfilment' : 'Fulfilment'))))

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

    /* WHOSE FIGURE IS THIS?
     *
     * $fh['waiting'] is the total across every channel. Printed under an h1
     * that names one channel it is simply false: the Shopee panel read
     * "Shopee" over "24 orders waiting" while Shopee's own card two inches
     * below read 0, because the 24 was Lazada's. The returns branch of this
     * same sentence was scoped when the returns view was added - its comment
     * is nine lines down and says why - and the orders branch was left on the
     * cross-channel total.
     *
     * With a channel open this page IS that channel's orders page, so the
     * sentence belongs to it: its count, and its own sync state rather than
     * a roll-call of every channel's. With no channel open the totals are
     * the point and nothing changes. */
    $fhOpen = $selectedChannel
        ? collect($fhChannels)->firstWhere('id', $selectedChannel)
        : null;

    $fhWaiting = $fhOpen !== null
        ? (int) ($fhOpen['waiting'] ?? 0)
        : ($fh['waiting'] ?? 0);

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

    if ($fhOpen !== null) {
        // One channel open: the clause is about that channel, not a
        // roll-call. "Lazada and Shopee are not syncing" under a Shopee
        // heading made the reader work out which half applied to them.
        $fhLink = ($fhOpen['stalled'] ?? false)
            ? (($fhOpen['state'] ?? '') === 'setup' ? 'not connected' : 'not syncing')
            : 'syncing';
    } else {
        $fhLink = $fhStalled === []
            ? 'all ' . count($fhChannels) . ' ' . (count($fhChannels) === 1 ? 'channel' : 'channels') . ' syncing'
            : $fhList . (count($fhStalled) === 1 ? ' is not syncing' : ' are not syncing');
    }

    // With a channel open, this page IS that channel's orders page and takes
    // its colour, the same as the seller centre does. With no channel open it
    // is the hub over all of them and stays neutral, because there is no one
    // channel for it to belong to.
@endphp

<div class="fh-scope"
     @if($fhOpen) style="--ch: {{ \App\Support\ChannelAccent::raw($fhOpen['accent']) }}; --ch-deep: {{ \App\Support\ChannelAccent::deep($fhOpen['accent']) }}; --ch-lift: {{ \App\Support\ChannelAccent::onDark($fhOpen['accent']) }};" @endif>

{{-- WHY A CHANNEL STOPPED, on the page where you would notice it had.
     The card says "not syncing", which tells you to go looking; this says
     what broke and links to the fix. It was only ever rendered by the
     channel's own layout, so the one surface showing every channel at once
     was the one that could not act on any of them.

     Scoped to the open channel when there is one, because with Shopee open a
     Lazada fault is somebody else's news; on the bare hub every contributing
     channel speaks, which is the whole point of the bare hub. --}}
@include('partials.integration-banners', ['bannerOnly' => $selectedChannel ?? null])

<div class="fh-head">
    {{-- The way back to every channel.

         The Summary card used to carry this: it sat first in the strip and
         clicking it cleared the filter. Removing it from the grid was right -
         an aggregate is not a peer of the channels, and the divider that
         separated the two broke the moment the grid wrapped - but the brief
         claimed the head sentence would take over that job and it never did.
         With a panel open, nothing in the page body led back to the
         unfiltered view; the only route was the sidebar's Fulfilment entry,
         which renders in its ACTIVE state and so reads as "you are here"
         rather than as a way out.

         Same words and same shape as the channel sidebar's own back link, so
         leaving a channel is one gesture wherever you meet it. --}}
    @if($selectedChannel)
        <a class="fh-head__back" href="{{ route('channels.fulfilment') }}">
            <x-ui.icon name="chevron-left" size="12" />
            All channels
        </a>
    @endif

    {{-- The head names the VIEW, not just the channel. The returns panel
         used to bring its own page title, which put two of them on this
         page and neither said "returns"; taking that away left the page
         titled "Shopee" above a list of returns, which is worse. The word
         belongs here, once. --}}
    @php $fhIsReturns = ($selectedView ?? 'orders') === 'returns'; @endphp
    <h1 class="x-page-title">
        {{-- "returns and refunds", matching the view's own page. One view
             was carrying three names: "Returns" in the nav, "Returns and
             refunds" on its own page, and "Shopee returns" here. The nav may
             stay short, because a nav item is read beside its siblings and
             the context carries it. Two page titles for one page cannot both
             be right. --}}
        {{ $selectedChannelLabel ?? 'Fulfilment' }}@if($fhIsReturns) returns and refunds @endif
    </h1>
    @if($fhIsReturns)
        {{-- "24 orders waiting" is an orders figure and said nothing true
             about this list. A returns view counts returns. --}}
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
    @php $fhIsReturnsViewNav = ($selectedView ?? 'orders') === 'returns'; @endphp
    <nav class="fh-grid" aria-label="Fulfilment surfaces">
        @foreach($fhChannels as $fhCard)
            {{-- The view rides with the link. Switching channel from a
                 returns list used to land on that channel's ORDERS, so the
                 strip that exists to move between channels quietly changed
                 the subject as well. --}}
            @php
                $fhCardUrl = $fhIsReturnsViewNav && str_contains($fhCard['url'], '/channels/fulfilment')
                    ? $fhCard['url'] . (str_contains($fhCard['url'], '?') ? '&' : '?') . 'view=returns'
                    : $fhCard['url'];
            @endphp
            <a href="{{ $fhCardUrl }}"
               class="fh-card {{ $selectedChannel === $fhCard['id'] ? 'is-open' : '' }} {{ $fhCard['stalled'] ? 'is-stalled' : '' }}"
               style="--ch: {{ \App\Support\ChannelAccent::raw($fhCard['accent']) }}; --ch-deep: {{ \App\Support\ChannelAccent::deep($fhCard['accent']) }}; --ch-lift: {{ \App\Support\ChannelAccent::onDark($fhCard['accent']) }};"
               {{-- "true", not "page": the card selects WHICH CHANNEL the panel
                    below shows, which is a choice within a set. The sidebar
                    nav and the pager each legitimately claim "page" - one for
                    the section, one for the page of results - and a third
                    claim from a filter left assistive tech with three
                    elements asserting they were the location. --}}
               @if($selectedChannel === $fhCard['id']) aria-current="true" @endif>

                {{-- The channel's own colour, filled rather than dotted. An
                     8px square said which channel this was only to someone
                     already looking at it; four cards in a row have to be
                     told apart from across the room, which is the whole job
                     of this grid. Two stores of one channel share a colour,
                     so the cap carries the channel and the name carries the
                     store. The fill is --ch-deep rather than the brand hex
                     because the name is reversed out of it in white: Shopee's
                     orange holds white at 3.66:1, which is a label you cannot
                     read at 12.5px. --}}
                <span class="fh-card__top">
                    <span class="fh-card__name">{{ $fhCard['label'] }}</span>
                </span>

                {{-- The count first, always, when it is tracked. A stale
                     channel can still have real orders waiting on a person,
                     and the staleness is a caveat on the number rather than a
                     reason to withhold it. --}}
                @php
                    /* A count that nothing can move. "not syncing" means the
                       credentials work and the sync has gone quiet, so the
                       number is real and merely late - the note above is
                       right that withholding it would be worse. "not
                       connected" is the other case: there are no credentials
                       at all, so the figure is whatever was true when the
                       channel was last linked and no fetch will ever change
                       it. The critique caught a card reading 24 above "not
                       connected" with nothing saying which of those two the
                       reader was looking at. */
                    $fhOrphanCount = $fhCard['stalled']
                        && $fhCard['state'] === 'setup'
                        && $fhCard['waiting'] !== null
                        && (int) $fhCard['waiting'] > 0;
                @endphp
                @php
                    /* ON THE RETURNS VIEW THE CARD COUNTS RETURNS.
                     *
                     * It used to count orders here, whatever the page was
                     * about, so "Lazada returns / 76 returns" sat above a
                     * highlighted card reading 51 and every reader made the
                     * 51 a part of the 76. It is not - 76 is every Lazada
                     * return and 51 is Lazada orders waiting to ship. Two
                     * correct answers to two different questions, printed
                     * adjacent with nothing naming either.
                     *
                     * A channel with no returns list says so rather than
                     * printing 0, which would read as "none outstanding"
                     * when the truth is "this channel does not do this". */
                    $fhIsReturnsView = ($selectedView ?? 'orders') === 'returns';
                    $fhCardReturns = $fhIsReturnsView
                        ? ($fhReturnsCounts[$fhCard['id']] ?? null)
                        : null;
                    /* The steps behind the count, when the channel tells
                     * them apart: To Pack leads, To Handover (and on
                     * Lazada, To Arrange) print beside it. The staff count
                     * the To Handover parcels against the shelf. */
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
                    {{-- A channel that tells its steps apart shows the two
                         figures the staff act on at one size, across the
                         card: To Pack on the left, the parcels nobody has
                         touched, and To Handover on the right, the parcels
                         on the shelf waiting for the courier, the way a
                         parcel moves. A middle step (Lazada's To Arrange)
                         is a line under them; it is neither. --}}
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

                {{-- and the caveat under it, which is what stops a zero from
                     a silent channel reading as "all clear". --}}
                @if($fhCard['stalled'])
                    {{-- Not connected is worse than not syncing: one has no
                         credentials at all, the other has them and has gone
                         quiet. The words carry it as well as the colour. --}}
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
                    {{-- The figures above carry their own labels. --}}
                @else
                    <span class="fh-card__label">{{ $fhCard['waiting'] === 0 ? 'clear' : 'waiting' }}</span>
                @endif

                {{-- Today's contribution, hidden by the grid at the narrow end
                     rather than by a viewport breakpoint: it is the card's own
                     width that decides whether there is room, and the grid is
                     what sets that. --}}
                {{-- Orders today, so it is withheld on the returns view. The
                     lead figure and its label were scoped to returns when the
                     cards learned to count them; this line was not, and went
                     on printing "19 today - P2,092.00" beneath a heading
                     reading "76 returns", where every reader takes it for
                     nineteen returns today. --}}
                @if(! $fhIsReturnsView && $fhCard['todayOrders'] !== null)
                    <span class="fh-card__today">
                        <span class="fh-card__count"><span class="x-num">{{ number_format($fhCard['todayOrders']) }}</span> today</span>
                        {{-- Money is the one fact on this line that does not
                             need a label to be read, so it is given the ink
                             and the far edge instead of a middot and the same
                             grey as the count beside it. The separator is
                             gone: the space between them does that job. --}}
                        @if($fhCard['todayRevenue'] !== null)
                            <span class="fh-card__money x-num">{{ \App\Support\Money::base((float) $fhCard['todayRevenue']) }}</span>
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
        {{-- role=status so the outcome is spoken. This region is the only
             feedback a fetch, a pack or a delete produces, and it announced
             nothing at all. --}}
        <div class="co-notice {{ session('error') ? 'co-notice--fail' : 'co-notice--ok' }}"
             role="status" aria-live="polite">
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
        <div class="x-fh-panel co-page" data-desk-scope id="{{ ($selectedView ?? 'orders') === 'returns' ? 'shopee-returns-page' : 'shopee-orders-page' }}">
            {{-- The FULL selected key, store suffix included (multi-store
                 S4, #194: shopee:2) - a bare 'shopee' here would drop the
                 store on every self-link and land the next click on
                 Summary. --}}
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
        {{-- Same id and the same page-level data attributes Lazada's own
             orders/index.blade.php carries, so resources/js/pages/
             lazada-orders.js (which reads them off the root element, not
             through the panel service) binds here unchanged whether this
             renders on the seller center or the hub. --}}
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
        {{-- Same id and the same data-awb-url TikTok's own
             orders/index.blade.php carries, so resources/js/pages/
             tiktok-orders.js (which reads it off the root element, not
             through the panel service) binds here unchanged whether this
             renders on the seller center or the hub. The value comes from
             the panel service's own last_result, so a ship performed from
             this page hands its waybill back here rather than only on the
             seller center. --}}
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
        <div class="x-fh-panel co-page" data-desk-scope id="venta-orders-page">
            <div class="x-fh-panel__scope">
                <span class="x-fh-panel__scope-label">Store</span>
                <span class="x-fh-panel__scope-value">{{ $storeLabel }}</span>
            </div>
            @include('ext-venta::orders._panel', [
                'panelBaseUrl' => route('channels.fulfilment'),
                'panelHiddenParams' => ['channel' => $selectedChannel],
            ])
        </div>
    @elseif($selectedChannelBase === 'opencart')
        {{-- Same shape as Venta's. This channel carries no page module of
             its own, but the id is here for the same reason every other
             panel wrapper has one: it is what the page CSS scopes its two
             responsive rules to. --}}
        <div class="x-fh-panel co-page" data-desk-scope id="opencart-orders-page">
            <div class="x-fh-panel__scope">
                <span class="x-fh-panel__scope-label">Store</span>
                <span class="x-fh-panel__scope-value">{{ $storeLabel }}</span>
            </div>
            @include('ext-opencart::orders._panel', [
                'panelBaseUrl' => route('channels.fulfilment'),
                'panelHiddenParams' => ['channel' => $selectedChannel],
            ])
        </div>
    @elseif($selectedChannelBase === 'shopify')
        {{-- Same shape as Venta's, and the same id the store's own seller
             center carries so resources/js/pages/shopify-orders.js (the raw
             payload dialog) binds here unchanged. --}}
        <div class="x-fh-panel co-page" data-desk-scope id="shopify-orders-page">
            <div class="x-fh-panel__scope">
                <span class="x-fh-panel__scope-label">Store</span>
                <span class="x-fh-panel__scope-value">{{ $storeLabel }}</span>
            </div>
            @include('ext-shopify::orders._panel', [
                'panelBaseUrl' => route('channels.fulfilment'),
                'panelHiddenParams' => ['channel' => $selectedChannel],
            ])
        </div>
    @elseif($selectedChannelBase === 'pedallion')
        {{-- Single-store, so no store line above it: the channel name on the
             card that was clicked is the whole of the scope. Same id its own
             seller center carries, which is what the page CSS scopes its
             responsive rules to. --}}
        <div class="x-fh-panel co-page" data-desk-scope id="pedallion-orders-page">
            @include('ext-pedallion::orders._panel', [
                'panelBaseUrl' => route('channels.fulfilment'),
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
                    {{-- The list is the ten best; the tail is named so ten
                         never reads as "only ten sold". --}}
                    @if(($fh['topProductsMore'] ?? 0) > 0)
                        <p class="fh-top__more">and <span class="x-num">{{ number_format($fh['topProductsMore']) }}</span> more {{ $fh['topProductsMore'] === 1 ? 'product' : 'products' }} sold today</p>
                    @endif
                @endif
            </section>
        </div>
    @endif
@endif

</div>{{-- /.fh-scope --}}
@endsection
