@extends('layouts.channel')

@section('title', \App\Support\StoreLabel::of($channelCard->name, $channelStoreLabel ?? null))

@section('content')
    <div class="x-overview-head">
        <div>
            <h1 class="x-page-title">{{ $channelStoreLabel ?? $channelCard->name }}</h1>
            <p class="x-page-sub">{{ $channelCard->tagline }}</p>
        </div>

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

    <div class="cw-body">
        <div class="cw-col">
            @if($channelTrend)
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

            @if(! empty($channelPayoutCard))
                @include('payouts.partials.money-panel', ['card' => $channelPayoutCard, 'compact' => true])
            @endif

            <section class="cw-section cw-section--rail">
                <div class="cw-section__head">
                    <h2 class="cw-section__title">Connection</h2>
                </div>

                <dl class="cw-rows">
                    @foreach($channelHealth as $row)
                        <div class="cw-row {{ ($row['tone'] ?? null) ? 'is-'.$row['tone'] : '' }}">
                            <dt class="cw-row__label">{{ $row['label'] }}</dt>
                            <dd class="cw-row__value {{ $row['value'] === null ? 'is-untracked' : '' }}">
                                {{ $row['value'] ?? 'Not tracked' }}
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
