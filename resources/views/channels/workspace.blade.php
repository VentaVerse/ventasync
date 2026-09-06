@extends('layouts.channel')

{{-- Both names in the tab title on a multi-store channel: store names repeat
     across channels, so "Shop A" alone does not identify an open tab. --}}
@section('title', \App\Support\StoreLabel::of($channelCard->name, $channelStoreLabel ?? null))

@section('content')
    <div class="x-overview-head">
        <div>
            {{-- The store is what this page is about on a multi-store
                 channel: its figures, its orders, its settings. The channel
                 it belongs to is named in the sidebar head and the
                 breadcrumb, both on screen beside this. --}}
            <h1 class="x-page-title">{{ $channelStoreLabel ?? $channelCard->name }}</h1>
            <p class="x-page-sub">{{ $channelCard->tagline }}</p>
        </div>

        {{-- The workspace's own most-needed action: Connect while the
             channel needs setup, otherwise the same "View orders" secondary
             action the status board already offers. Never both.

             Connect opens in a new tab, same as the "Authorize with Shopee"
             link on the Settings page it mirrors: it hands off to Shopee's
             own consent screen, not a page inside this app. --}}
        @if($channelAction && $channelState === 'setup')
            <x-ui.button variant="primary" size="sm" :href="$channelAction['url']" target="_blank" rel="noopener">
                {{ $channelAction['label'] }}
            </x-ui.button>
        @elseif($channelAction)
            <x-ui.button variant="secondary" size="sm" :href="$channelAction['url']">
                {{ $channelAction['label'] }}
            </x-ui.button>
        @endif
    </div>

    {{-- Work on the left, context on the right, the same split the order page
         and every form page already use. It is not decoration here: the page
         was four full-width bands down a 1160px column, which gave a product
         title and its revenue 600px of empty carpet between them, stretched a
         30-day plot to a 6:1 ratio that flattened the trend into a squiggle,
         and gave "Connection" — a footnote you read on the day it breaks —
         exactly the same weight as the month's takings.

         The left column answers "how is this channel doing"; the rail answers
         "is it working and what is queued". --}}
    <div class="cw-body">
        <div class="cw-col">
            @if($channelTrend)
                {{-- How this channel is doing, which is what the page leads
                     with. Scoped to this channel only: the cross-channel
                     comparison lives on the dashboard, and repeating it here
                     would give a seller two places to check that eventually
                     disagree. --}}
                @php
                    $tr = $channelTrend;
                    $revDelta = $tr['prevRevenue'] > 0
                        ? round((($tr['totalRevenue'] - $tr['prevRevenue']) / $tr['prevRevenue']) * 100)
                        : null;
                    $ordDelta = $tr['prevOrders'] > 0
                        ? round((($tr['totalOrders'] - $tr['prevOrders']) / $tr['prevOrders']) * 100)
                        : null;
                @endphp

                <section class="cw-section">
                    <div class="cw-section__head">
                        <h2 class="cw-section__title">Last 30 days</h2>
                        <p class="cw-section__note">Compared with the 30 days before</p>
                    </div>

                    <div class="cw-figures">
                        {{-- A percentage against a period that sold nothing is
                             not a number: it prints the plain total instead of
                             "up infinity". --}}
                        <div class="cw-figure">
                            <span class="cw-figure__k">Revenue</span>
                            <span class="cw-figure__v">{{ \App\Support\Money::base($tr['totalRevenue']) }}</span>
                            @if($revDelta !== null)
                                <span class="cw-figure__d {{ $revDelta < 0 ? 'is-down' : 'is-up' }}">
                                    {{ $revDelta > 0 ? '+' : '' }}{{ $revDelta }}%
                                </span>
                            @else
                                <span class="cw-figure__d is-flat">no prior sales</span>
                            @endif
                        </div>

                        <div class="cw-figure">
                            <span class="cw-figure__k">Orders</span>
                            <span class="cw-figure__v">{{ number_format($tr['totalOrders']) }}</span>
                            @if($ordDelta !== null)
                                <span class="cw-figure__d {{ $ordDelta < 0 ? 'is-down' : 'is-up' }}">
                                    {{ $ordDelta > 0 ? '+' : '' }}{{ $ordDelta }}%
                                </span>
                            @else
                                <span class="cw-figure__d is-flat">no prior orders</span>
                            @endif
                        </div>
                    </div>

                    @include('partials._trend-plot', [
                        'labels' => $tr['labels'],
                        'revenue' => $tr['revenue'],
                        'orders' => $tr['orders'],
                    ])
                </section>
            @endif

            @if($channelBestSellers)
                {{-- What actually moved here, which differs per marketplace and
                     is the closest thing this page offers to a stocking
                     decision. Ranked by revenue; units sit alongside because
                     the two together are what makes a line readable. --}}
                <section class="cw-section">
                    <div class="cw-section__head">
                        <h2 class="cw-section__title">Best sellers here</h2>
                        <p class="cw-section__note">By revenue, last 30 days</p>
                    </div>

                    <ol class="cw-top">
                        @foreach($channelBestSellers as $i => $product)
                            <li class="cw-top__row">
                                <span class="cw-top__rank" aria-hidden="true">{{ $i + 1 }}</span>

                                <span class="cw-top__name">
                                    {{ $product['name'] }}
                                    @if($product['model'])
                                        <span class="cw-top__model">{{ $product['model'] }}</span>
                                    @endif
                                </span>

                                <span class="cw-top__units">{{ number_format($product['units']) }} sold</span>
                                <span class="cw-top__rev">{{ \App\Support\Money::base($product['revenue']) }}</span>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif

            @unless($channelTrend || $channelBestSellers)
                {{-- A channel connected today has no 30 days to report, and an
                     empty left column beside a full rail reads as a page that
                     failed to load. Say which it is. --}}
                <section class="cw-section cw-section--empty">
                    <p class="cw-empty__title">No sales recorded here yet</p>
                    <p class="cw-empty__body">
                        Revenue, orders and best sellers appear once this channel has
                        imported its first orders. Syncing runs on a schedule, so a
                        channel connected in the last few hours may still be catching up.
                    </p>
                </section>
            @endunless
        </div>

        <aside class="cw-col cw-col--side">
            @if($channelWaiting)
                {{-- What needs doing, above what is merely true about the
                     connection: an operator opening a channel is asking "what
                     is waiting for me", and the connection panel below answers
                     a different question. --}}
                <section class="cw-section cw-section--rail">
                    <div class="cw-section__head">
                        <h2 class="cw-section__title">Waiting on you</h2>
                    </div>

                    <dl class="cw-rows">
                        @foreach($channelWaiting as $row)
                            <div class="cw-row">
                                <dt class="cw-row__label">{{ $row['label'] }}</dt>
                                <dd class="cw-row__value {{ $row['value'] === null ? 'is-untracked' : '' }}">
                                    {{ $row['value'] ?? 'Not tracked' }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif

            {{-- Connection last. It matters on the day it breaks and never
                 otherwise, and the badge in the channel sidebar already carries
                 the alarm. --}}
            <section class="cw-section cw-section--rail">
                <div class="cw-section__head">
                    <h2 class="cw-section__title">Connection</h2>
                </div>

                <dl class="cw-rows">
                    @foreach($channelHealth as $row)
                        <div class="cw-row {{ ($row['tone'] ?? null) ? 'is-'.$row['tone'] : '' }}">
                            <dt class="cw-row__label">{{ $row['label'] }}</dt>
                            {{-- A null value means the figure is not tracked.
                                 Printing a zero here would read as "no errors"
                                 when it means "never measured". --}}
                            <dd class="cw-row__value {{ $row['value'] === null ? 'is-untracked' : '' }}">
                                {{ $row['value'] ?? 'Not tracked' }}
                                {{-- The note is what the reading means, and it
                                     is what carries the tone in words: an
                                     expired token says "Expired" beside its
                                     date, so the colour is never the only
                                     thing saying so. --}}
                                @if($row['note'] ?? null)
                                    <span class="cw-row__note">{{ $row['note'] }}</span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        </aside>
    </div>
@endsection
