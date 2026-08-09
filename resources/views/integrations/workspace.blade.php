@extends('layouts.channel')

{{-- Both names in the tab title on a multi-store channel: store names repeat
     across channels, so "Shop A" alone does not identify an open tab. --}}
@section('title', ($channelStoreLabel ?? null) ? $channelCard->name.' '.$channelStoreLabel : $channelCard->name)

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

    @if($channelTrend)
        {{-- How this channel is doing, which is what the page leads with.
             Scoped to this channel only: the cross-channel comparison lives
             on the dashboard, and repeating it here would give a seller two
             places to check that eventually disagree. --}}
        @php
            $tr = $channelTrend;
            $revDelta = $tr['prevRevenue'] > 0
                ? round((($tr['totalRevenue'] - $tr['prevRevenue']) / $tr['prevRevenue']) * 100)
                : null;
            $ordDelta = $tr['prevOrders'] > 0
                ? round((($tr['totalOrders'] - $tr['prevOrders']) / $tr['prevOrders']) * 100)
                : null;
        @endphp

        <div class="cw-panel__head">
            <h2 class="cw-panel__title">Last 30 days</h2>
            <p class="cw-panel__note">Compared with the 30 days before</p>
        </div>

        <section class="cw-panel">
            <div class="cw-figures">
                {{-- A percentage against a period that sold nothing is not a
                     number: it prints the plain total instead of "up
                     infinity". --}}
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

    @if($channelWaiting)
        {{-- What needs doing, before what is merely true about the
             connection. An operator opening a channel is asking "what is
             waiting for me", and the health strip below answers a different
             question. --}}
        <div class="cw-panel__head">
            <h2 class="cw-panel__title">Waiting on you</h2>
        </div>
        <div class="x-waiting">
            @foreach($channelWaiting as $row)
                <div class="x-waiting__cell">
                    <span class="x-waiting__value {{ $row['value'] === null ? 'is-untracked' : '' }}">
                        {{ $row['value'] ?? 'Not tracked' }}
                    </span>
                    <span class="x-waiting__label">{{ $row['label'] }}</span>
                </div>
            @endforeach
        </div>
    @endif

    @if($channelBestSellers)
        {{-- What actually moved here, which differs per marketplace and is
             the closest thing this page offers to a stocking decision.
             Ranked by revenue; units sit alongside because the two together
             are what makes a line readable. --}}
        <div class="cw-panel__head">
            <h2 class="cw-panel__title">Best sellers here</h2>
            <p class="cw-panel__note">By revenue, last 30 days</p>
        </div>

        <section class="cw-panel">
            <ol class="cw-top">
                @foreach($channelBestSellers as $product)
                    <li class="cw-top__row">
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

    {{-- Health last. It matters on the day it breaks and never otherwise,
         and the badge in the channel sidebar already carries the alarm. --}}
    <div class="cw-panel__head">
        <h2 class="cw-panel__title">Connection</h2>
    </div>
    <div class="x-health">
        @foreach($channelHealth as $row)
            <div class="x-health__cell">
                <span class="x-health__label">{{ $row['label'] }}</span>
                {{-- A null value means the figure is not tracked. Printing a
                     zero here would read as "no errors" when it means
                     "never measured". --}}
                <span class="x-health__value {{ $row['value'] === null ? 'is-untracked' : '' }}">
                    {{ $row['value'] ?? 'Not tracked' }}
                </span>
            </div>
        @endforeach
    </div>
@endsection
