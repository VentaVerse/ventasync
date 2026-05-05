@extends('layouts.app')
@section('breadcrumb', 'Orders')

@section('content')

{{-- Tab strip lives at the very top of the content area, OUTSIDE any card,
     matching the position used by every marketplace's orders index view.
     Keeping the strip's Y position identical between Summary and the
     marketplace pages means clicking a tab feels like switching panels,
     not navigating away. --}}
@include('integrations.partials._tab_strip', ['activeTabId' => null])

@if(empty($hasMarketplaces))
    <div class="card">
        <div class="page-header">
            <h2>Orders</h2>
        </div>
        <div class="int-page">
            <div class="int-empty">
                <h3>No marketplace orders available</h3>
                <p>
                    Activate or enable a marketplace integration on the
                    <a href="{{ route('extensions.index') }}">Extensions page</a>,
                    or grant yourself the appropriate orders permission to see
                    marketplace orders here.
                </p>
            </div>
        </div>
    </div>
@else
    <div class="page-header">
        <h2>Summary</h2>
    </div>

    <div class="ord-summary-grid">
        {{-- Block 2: Top products today (combined leaderboard) --}}
        <section class="ord-block">
            <header class="ord-block-head">
                <h3>Top products today</h3>
                <span class="ord-block-meta">{{ count($topProducts) }} {{ count($topProducts) === 1 ? 'item' : 'items' }}</span>
            </header>
            @if(empty($topProducts))
                <div class="ord-block-empty">No orders yet today.</div>
            @else
                <ol class="ord-leaderboard">
                    @foreach($topProducts as $i => $tp)
                        <li>
                            <div class="ord-rank">{{ $i + 1 }}</div>
                            <div class="ord-thumb">
                                @if($tp['imageUrl'])
                                    <img src="{{ $tp['imageUrl'] }}" alt="" loading="lazy">
                                @else
                                    <div class="ord-thumb-blank"></div>
                                @endif
                            </div>
                            <div class="ord-prod">
                                <div class="ord-prod-name">{{ $tp['name'] }}</div>
                                <div class="ord-prod-sku">{{ $tp['sku'] }}</div>
                                <div class="ord-prod-contrib">
                                    @foreach($tp['contributors'] as $c)
                                        <span class="ord-contrib" title="{{ $c['label'] }}: {{ $c['qty'] }}" style="--contrib-accent: {{ $c['accent'] }};">
                                            <span class="ord-contrib-dot"></span>
                                            {{ $c['label'] }} · {{ $c['qty'] }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                            <div class="ord-prod-stats">
                                <div class="ord-prod-qty">{{ $tp['qtySold'] }}</div>
                                <div class="ord-prod-rev">₱{{ number_format($tp['revenue'], 0) }}</div>
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        {{-- Block 3: Recent activity feed --}}
        <section class="ord-block">
            <header class="ord-block-head">
                <h3>Recent activity</h3>
                <span class="ord-block-meta">{{ count($recentOrders) }} {{ count($recentOrders) === 1 ? 'order' : 'orders' }}</span>
            </header>
            @if(empty($recentOrders))
                <div class="ord-block-empty">No recent orders.</div>
            @else
                <ul class="ord-feed">
                    @foreach($recentOrders as $row)
                        @php($o = $row['order'])
                        @php($c = $row['card'])
                        <li>
                            <a href="{{ $o->url ?? '#' }}" class="ord-feed-row" style="--row-accent: {{ $c->accent }};">
                                <span class="ord-feed-dot"></span>
                                <div class="ord-feed-meta">
                                    <div class="ord-feed-line1">
                                        <span class="ord-feed-mp">{{ $c->name }}</span>
                                        <span class="ord-feed-ref">{{ $o->reference }}</span>
                                    </div>
                                    <div class="ord-feed-line2">
                                        @if($o->customerName)<span>{{ $o->customerName }}</span><span class="ord-feed-sep">·</span>@endif
                                        <span>{{ $o->statusLabel }}</span>
                                    </div>
                                </div>
                                <div class="ord-feed-stats">
                                    @if($o->total > 0)
                                        <div class="ord-feed-total">₱{{ number_format($o->total, 0) }}</div>
                                    @endif
                                    <div class="ord-feed-age">
                                        {{ $o->orderedAt ? \Illuminate\Support\Carbon::instance($o->orderedAt)->diffForHumans() : '' }}
                                    </div>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
@endif

@endsection
