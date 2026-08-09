@extends('layouts.blotter')

@section('title', 'Dashboard')

@php
    use App\Support\Money;
    use Illuminate\Support\Facades\Route as RouteFacade;
    use Illuminate\Support\Str;

    $user = auth()->user();

    /**
     * Period-over-period movement. Returns null when the comparison period
     * has no figure to compare against, because "+100%" against zero is a
     * sentence the data cannot support.
     */
    $delta = function (float $now, float $then): ?float {
        if ($then <= 0.0) {
            return null;
        }
        return ($now - $then) / $then * 100;
    };

    $ordersDelta = $delta((float) $todayOrders, (float) $yesterdayOrders);
    $revenueDelta = $delta((float) $todayRevenue, (float) $yesterdayRevenue);
    $weekDelta = $delta((float) $weekRevenue, (float) $lastWeekRevenue);

    /**
     * Order status to state colour. The status table is user-editable, so
     * this reads the words rather than assuming ids, and falls back to the
     * neutral token instead of guessing.
     */
    $statusTone = function (?string $name): string {
        $n = strtolower((string) $name);
        foreach (['cancel', 'refund', 'fail', 'void', 'denied', 'reject'] as $needle) {
            if (str_contains($n, $needle)) { return 'bad'; }
        }
        foreach (['pending', 'processing', 'unpaid', 'hold', 'await', 'partial'] as $needle) {
            if (str_contains($n, $needle)) { return 'attn'; }
        }
        foreach (['ship', 'complete', 'deliver', 'paid', 'done', 'fulfil'] as $needle) {
            if (str_contains($n, $needle)) { return 'good'; }
        }
        return 'off';
    };

    /**
     * Sync freshness as words. A figure nobody can date is a figure nobody
     * can trust, so every channel says when it last spoke to its
     * marketplace, and the state token repeats it for someone scanning.
     */
    $freshness = function ($timestamp, $expiresAt = null): array {
        if ($expiresAt && !$expiresAt->isFuture()) {
            return ['tone' => 'bad', 'text' => 'reconnect needed, the access token expired'];
        }
        if (!$timestamp) {
            return ['tone' => 'off', 'text' => 'never synced'];
        }
        $when = is_string($timestamp) ? \Carbon\Carbon::parse($timestamp) : $timestamp;
        // Carbon 3 returns a SIGNED difference, so a timestamp in the past
        // gives a negative hour count. Without abs() every stale channel
        // scored under one hour and reported itself healthy, which is the
        // exact failure this row exists to prevent.
        $hours = abs(now()->diffInHours($when));
        $tone = $hours < 1 ? 'good' : ($hours < 6 ? 'attn' : 'bad');
        return ['tone' => $tone, 'text' => 'synced ' . $when->diffForHumans()];
    };

    /*
     * One row per channel, built from the keys each marketplace extension
     * contributes through DashboardContributor. Both halves of the question
     * are on one row: is this channel healthy, and does it want me now.
     * Every row is gated on the same permission that opens the records it
     * links to, so a restricted operator is never shown a figure they
     * cannot act on.
     */
    $channelColour = fn (string $key) => $sourceLabelsMap[$key]['chart_color'] ?? null;
    $channels = [];

    if (($shopeeSyncStatus ?? null) && $user?->hasPermission('view_shopee')) {
        $channels[] = [
            'name' => 'Shopee',
            'colour' => $channelColour('shopee'),
            'state' => $freshness($shopeeSyncStatus->order_sync_at ?? null, $shopeeSyncStatus->expires_at ?? null),
            'pending' => $user->hasPermission('view_shopee_orders') ? (int) ($shopeePending ?? 0) : null,
            'link' => RouteFacade::has('ext.shopee.orders.index') ? route('ext.shopee.orders.index') : null,
        ];
    }
    if (($lazadaSyncStatus ?? null) && $user?->hasPermission('view_lazada')) {
        $channels[] = [
            'name' => 'Lazada',
            'colour' => $channelColour('lazada'),
            'state' => $freshness($lazadaSyncStatus->order_sync_at ?? null, $lazadaSyncStatus->expires_at ?? null),
            'pending' => $user->hasPermission('view_lazada_orders') ? (int) ($lazadaPending ?? 0) : null,
            'link' => RouteFacade::has('ext.lazada.orders.index') ? route('ext.lazada.orders.index') : null,
        ];
    }
    if (($tiktokSyncStatus ?? null) && $user?->hasPermission('view_tiktok')) {
        $channels[] = [
            'name' => 'TikTok Shop',
            'colour' => $channelColour('tiktok'),
            'state' => $freshness($tiktokSyncStatus->order_sync_at ?? null, $tiktokSyncStatus->expires_at ?? null),
            'pending' => $user->hasPermission('view_tiktok_orders') ? (int) ($tiktokPending ?? 0) : null,
            'link' => RouteFacade::has('ext.tiktok.orders.index') ? route('ext.tiktok.orders.index') : null,
        ];
    }
    if ($user?->hasPermission('view_opencart') && ($syncStatuses ?? null) instanceof \Illuminate\Support\Collection) {
        foreach ($syncStatuses as $store) {
            $channels[] = [
                'name' => $store->store_name ?: 'OpenCart store ' . $store->id,
                'colour' => $channelColour('opencart:' . $store->id),
                'state' => $store->last_status === 'failed'
                    ? ['tone' => 'bad', 'text' => 'last sync failed']
                    : $freshness($store->last_order_sync_at ?? null),
                'pending' => $user->hasPermission('view_opencart_orders') ? (int) ($store->pending_count ?? 0) : null,
                'link' => null,
            ];
        }
    }

    // Waiting across every channel, from the core order table rather than
    // any one extension, so a channel that keeps no order table of its own
    // still counts.
    $pendingTotal = array_sum($pendingBySource);

    $ranges = [
        '30d' => ['label' => '30 days', 'heading' => 'the last 30 days'],
        'monthly' => ['label' => '12 months', 'heading' => 'the last 12 months'],
        'yearly' => ['label' => '5 years', 'heading' => 'the last 5 years'],
    ];
    $rangeHeading = $ranges[$range]['heading'] ?? $ranges['30d']['heading'];

    $canSeePurchasing = RouteFacade::has('ext.purchasing.purchase_orders.index')
        && $user?->hasPermission('manage_purchasing');
@endphp

@section('content')
    <div class="flex flex-wrap items-end gap-4 px-gutter pt-4.5 pb-3.5">
        <div>
            <h1 class="text-[21px] font-semibold tracking-tight">{{ $greeting }}</h1>
            <p class="text-[13px] text-ink-3">
                {{ now()->format('l, j F') }} &middot; trading since 00:00
            </p>
        </div>
        <div class="ml-auto flex gap-1.5">
            <a href="{{ route('search') }}" class="bl-btn">
                <x-ui.icon name="search" size="14" /> Find an order
            </a>
        </div>
    </div>

    {{-- The trading band. Every figure is a link, not a label. --}}
    <div class="bl-band grid-cols-2 md:grid-cols-3 xl:grid-cols-5">
        <a class="bl-cell min-w-0" href="{{ route('orders.index') }}">
            <span class="bl-label">Orders today</span>
            <span class="bl-figure">{{ number_format($todayOrders) }}</span>
            <span class="text-[12px] text-ink-2">
                @if($ordersDelta === null)
                    none yesterday to compare
                @else
                    <span class="font-semibold {{ $ordersDelta >= 0 ? 'text-good' : 'text-bad' }}">
                        {{ $ordersDelta >= 0 ? '+' : '' }}{{ number_format($ordersDelta, 1) }}%
                    </span>
                    vs yesterday
                @endif
            </span>
        </a>

        <a class="bl-cell min-w-0" href="{{ route('orders.index') }}">
            <span class="bl-label">Revenue today</span>
            <span class="bl-figure">{{ Money::base($todayRevenue) }}</span>
            <span class="text-[12px] text-ink-2">
                @if($revenueDelta === null)
                    none yesterday to compare
                @else
                    <span class="font-semibold {{ $revenueDelta >= 0 ? 'text-good' : 'text-bad' }}">
                        {{ $revenueDelta >= 0 ? '+' : '' }}{{ number_format($revenueDelta, 1) }}%
                    </span>
                    vs yesterday
                @endif
            </span>
        </a>

        <a class="bl-cell min-w-0" href="{{ route('orders.index') }}">
            <span class="bl-label">Week to date</span>
            <span class="bl-figure">{{ Money::base($weekRevenue) }}</span>
            <span class="text-[12px] text-ink-2">
                {{ number_format($weekOrders) }} {{ Str::plural('order', $weekOrders) }}
                @if($weekDelta !== null)
                    &middot;
                    <span class="font-semibold {{ $weekDelta >= 0 ? 'text-good' : 'text-bad' }}">
                        {{ $weekDelta >= 0 ? '+' : '' }}{{ number_format($weekDelta, 1) }}%
                    </span>
                @endif
            </span>
        </a>

        <a class="bl-cell min-w-0" href="{{ route('orders.index') }}">
            <span class="bl-label">Waiting to ship</span>
            <span class="bl-figure {{ $pendingTotal > 0 ? 'text-attn' : '' }}">{{ number_format($pendingTotal) }}</span>
            <span class="text-[12px] text-ink-2">
                {{ $pendingTotal === 0 ? 'nothing pending' : 'across your channels' }}
            </span>
        </a>

        <a class="bl-cell min-w-0 col-span-2 md:col-span-3 xl:col-span-1" href="{{ route('products.index') }}">
            <span class="bl-label">Stock at cost</span>
            <span class="bl-figure">{{ abs($inventoryAtCost) >= 1000000 ? Money::compactAxis($inventoryAtCost) : Money::base($inventoryAtCost) }}</span>
            <span class="text-[12px] text-ink-2">
                {{ number_format($inventoryMargin, 1) }}% margin if it all sold at list
            </span>
        </a>
    </div>

    {{-- Channels. The swatch colour is the one place a brand hue appears,
         and it never carries meaning alone: the written name and the
         freshness sentence sit beside it. --}}
    @if($channels !== [])
        <div class="bl-strip grid border-b border-rule-2 bg-sheet md:grid-cols-2 xl:grid-cols-3">
            @foreach($channels as $channel)
                @if($channel['link'])
                    <a href="{{ $channel['link'] }}"
                       class="flex min-w-0 items-center gap-2.5 border-r border-b border-rule py-2.5 text-ink no-underline">
                @else
                    <div class="flex min-w-0 items-center gap-2.5 border-r border-b border-rule py-2.5">
                @endif
                    @if($channel['colour'])
                        <svg class="bl-swatch" width="9" height="9" viewBox="0 0 9 9" aria-hidden="true" focusable="false">
                            <rect width="9" height="9" fill="{{ $channel['colour'] }}"></rect>
                        </svg>
                    @else
                        <span class="bl-swatch bl-swatch-empty"></span>
                    @endif
                    <span class="min-w-0">
                        <span class="block truncate text-[13px] font-semibold">{{ $channel['name'] }}</span>
                        <span class="bl-state bl-state-{{ $channel['state']['tone'] }} font-normal">
                            <i></i>{{ $channel['state']['text'] }}
                        </span>
                    </span>
                    @if($channel['pending'] !== null)
                        <span class="ml-auto shrink-0 text-right text-[11.5px] leading-tight text-ink-3">
                            <b class="block text-[15px] font-semibold {{ $channel['pending'] > 0 ? 'text-attn' : 'text-ink' }}">
                                {{ number_format($channel['pending']) }}
                            </b>
                            waiting
                        </span>
                    @endif
                @if($channel['link'])
                    </a>
                @else
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    {{-- 2fr / 1fr, not 1.9 / 1: it puts the rail divider at exactly two
         thirds, which is where the three-column channel strip above already
         breaks. The two edges now line up down the page instead of missing
         each other by a few pixels. --}}
    <div class="bl-split grid items-start xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <div class="min-w-0">
            <div class="bl-panel-head">
                <h2>Latest orders</h2>
                <span class="text-[12px] text-ink-3">{{ number_format($totalOrders) }} in total</span>
                <a href="{{ route('orders.index') }}"
                   class="ml-auto border-b border-rule-2 text-[12px] text-ink-2 no-underline hover:border-ink hover:text-ink">
                    All orders
                </a>
            </div>

            <div class="bl-scroll">
                <table class="bl-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Channel</th>
                            <th>Buyer</th>
                            <th>Placed</th>
                            <th class="num">Total</th>
                            <th>State</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentOrders as $order)
                            @php
                                $src = $order->marketplace_source ?: 'direct';
                                $opt = $sourceLabelsMap[$src] ?? null;
                                $buyer = trim(($order->firstname ?? '') . ' ' . ($order->lastname ?? ''));
                            @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('orders.show', $order->order_id) }}"
                                       class="bl-mono border-b border-rule-2 text-ink no-underline hover:border-ink">
                                        #{{ $order->order_id }}
                                    </a>
                                </td>
                                <td class="whitespace-nowrap">
                                    @if($opt['chart_color'] ?? null)
                                        <svg class="bl-swatch" width="9" height="9" viewBox="0 0 9 9" aria-hidden="true" focusable="false">
                                            <rect width="9" height="9" fill="{{ $opt['chart_color'] }}"></rect>
                                        </svg>
                                    @else
                                        <span class="bl-swatch bl-swatch-empty"></span>
                                    @endif
                                    <span class="ml-1.5">{{ $opt['label'] ?? 'Direct' }}</span>
                                </td>
                                <td>{{ $buyer !== '' ? $buyer : 'No name recorded' }}</td>
                                <td class="whitespace-nowrap text-[12px] text-ink-3">
                                    {{ \Illuminate\Support\Carbon::parse($order->date_added)->diffForHumans(short: true) }}
                                </td>
                                <td class="num whitespace-nowrap">{{ Money::base((float) $order->total) }}</td>
                                <td class="whitespace-nowrap">
                                    <span class="bl-state bl-state-{{ $statusTone($order->status_name) }}">
                                        <i></i>{{ $order->status_name ?? 'No status' }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-ink-3">
                                    No orders yet. They appear here as each channel syncs.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Trend. One range control above both series, so the two charts
                 always show the same slice and their figures agree. --}}
            <div class="bl-panel-head">
                <h2>Sales over {{ $rangeHeading }}</h2>
                <div class="ml-auto flex gap-1" role="group" aria-label="Chart range">
                    @foreach($ranges as $key => $meta)
                        <a href="{{ route('dashboard', ['range' => $key]) }}"
                           @if($range === $key) aria-current="true" @endif
                           class="rounded-[2px] border px-2 py-0.5 text-[11.5px] no-underline
                                  {{ $range === $key
                                      ? 'border-ink bg-ink font-semibold text-paper'
                                      : 'border-rule-2 text-ink-2 hover:border-ink hover:text-ink' }}">
                            {{ $meta['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>

            @php
                $series = [
                    ['title' => 'Revenue', 'values' => $chartRevenue, 'unit' => 'money'],
                    ['title' => 'Orders', 'values' => $chartOrders, 'unit' => 'count'],
                ];
            @endphp
            <div class="bl-charts grid gap-px bg-rule-2 md:grid-cols-2">
                @foreach($series as $plot)
                    @php
                        $values = array_map('floatval', $plot['values'] ?? []);
                        $peak = $values === [] ? 0.0 : max($values);
                        $lastIndex = $values === [] ? null : array_key_last($values);
                    @endphp
                    {{-- Inside a card now, so its padding is the card's business, not the
                         column's. --}}
                    <div class="bg-sheet px-3.5 pb-4 pt-3">
                        <h3 class="bl-label">{{ $plot['title'] }}</h3>
                        @if($peak > 0)
                            {{-- Each column is its own SVG so a five-year range
                                 draws five narrow bars rather than five slabs.
                                 The bar height is the only thing here that comes
                                 from the data, so it lives in the rect's height
                                 geometry attribute: this project puts no CSS in
                                 Blade and the suite enforces it. --}}
                            <div class="mt-2 flex h-[118px] items-end gap-[3px] border-b border-rule-2">
                                @foreach($values as $i => $value)
                                    @php
                                        $h = max(1, (int) round($value / $peak * 100));
                                        $reading = $plot['unit'] === 'money'
                                            ? Money::base((float) $value)
                                            : number_format($value);
                                    @endphp
                                    <svg class="bl-col {{ $i === $lastIndex ? 'bl-col-now' : '' }}"
                                         viewBox="0 0 10 100" preserveAspectRatio="none" role="img">
                                        <title>{{ ($chartLabels[$i] ?? '') . ': ' . $reading }}</title>
                                        <rect x="0" y="{{ 100 - $h }}" width="10" height="{{ $h }}"></rect>
                                    </svg>
                                @endforeach
                            </div>
                            <div class="flex justify-between pt-1.5 text-[11px] text-ink-3">
                                <span>{{ $chartLabels[0] ?? '' }}</span>
                                <span>{{ $chartLabels[$lastIndex] ?? '' }}</span>
                            </div>
                        @else
                            <p class="py-6 text-[13px] text-ink-3">Nothing recorded in this period yet.</p>
                        @endif
                    </div>
                @endforeach
            </div>

            @if(!empty($platformSlices))
                <div class="bl-panel-head">
                    <h2>Orders by channel</h2>
                    <span class="text-[12px] text-ink-3">{{ $rangeHeading }}</span>
                </div>
                <div class="bl-scroll">
                    <table class="bl-table">
                        <thead>
                            <tr>
                                <th>Channel</th>
                                <th class="num">Orders</th>
                                <th class="num">Revenue</th>
                                <th class="num">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $sliceTotal = max(1, collect($platformSlices)->sum('orders')); @endphp
                            @foreach($platformSlices as $slice)
                                <tr>
                                    <td class="font-semibold">{{ $slice['label'] }}</td>
                                    <td class="num">{{ number_format($slice['orders']) }}</td>
                                    <td class="num">{{ Money::base((float) $slice['revenue']) }}</td>
                                    <td class="num">{{ number_format($slice['orders'] / $sliceTotal * 100, 1) }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- The rail's blocks are cards: bordered, so they read as siblings of
             the ruled table beside them rather than as loose text floating on
             the page. --}}
        {{-- The rail gets a heading of its own so its cards start level with
             the table beside them, and so the column says what it is before
             it starts listing figures. No divider between the columns: the
             cards already have edges, and a rule between two bordered things
             is one line too many. --}}
        <div class="bl-rail-stack min-w-0">
            <div class="bl-panel-head">
                <h2>Inventory</h2>
                {{-- A count, not a peso figure: "Stock at cost" is already in
                     the band above, and the panel below repeats it. Counting
                     also mirrors "N in total" on the table beside it. --}}
                <span class="text-[12px] text-ink-3">
                    {{ number_format($inventoryProducts) }} {{ Str::plural('product', $inventoryProducts) }}
                </span>
            </div>

            <section class="bl-panel">
            <div class="bl-panel__head">
                <h2>Stock</h2>
                <span class="text-[12px] text-ink-3">enabled products only</span>
            </div>
            <dl class="bl-panel__body grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-1.5 text-[13px]">
                <dt class="bl-label whitespace-nowrap">At cost</dt>
                <dd class="font-medium">{{ Money::base($inventoryAtCost) }}</dd>
                <dt class="bl-label whitespace-nowrap">At sale price</dt>
                <dd class="font-medium">{{ Money::base($inventoryAtSale) }}</dd>
                <dt class="bl-label whitespace-nowrap">Margin on stock</dt>
                <dd class="font-medium {{ $inventoryMargin > 0 ? 'text-good' : '' }}">
                    {{ number_format($inventoryMargin, 1) }}%
                </dd>
                <dt class="bl-label whitespace-nowrap">All time</dt>
                <dd class="font-medium">{{ Money::base($totalRevenue) }}</dd>
            </dl>
            </section>

            {{-- The purchase-order figures arrive from the purchasing
                 extension through the contributor registry, so the whole
                 block only appears for an operator who holds the permission
                 that opens those records. --}}
            @if($canSeePurchasing)
                <section class="bl-panel">
                <div class="bl-panel__head">
                    <h2>Purchasing</h2>
                    <a href="{{ route('ext.purchasing.purchase_orders.index') }}"
                       class="ml-auto border-b border-rule-2 text-[12px] text-ink-2 no-underline hover:border-ink hover:text-ink">
                        All purchase orders
                    </a>
                </div>
                <dl class="bl-panel__body grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-1.5 text-[13px]">
                    <dt class="bl-label whitespace-nowrap">Open purchase orders</dt>
                    <dd class="font-medium">
                        {{ number_format($openPos ?? 0) }}
                        <span class="text-[12px] font-normal text-ink-3">
                            &middot; {{ number_format($pendingDelivery ?? 0) }} awaiting delivery
                        </span>
                    </dd>
                    <dt class="bl-label whitespace-nowrap">Overdue deliveries</dt>
                    <dd class="font-medium {{ ($overduePos ?? 0) > 0 ? 'text-attn' : '' }}">
                        {{ number_format($overduePos ?? 0) }}
                    </dd>
                    <dt class="bl-label whitespace-nowrap">Purchasing this month</dt>
                    <dd class="font-medium">{{ Money::base((float) ($monthlyPoSpend ?? 0)) }}</dd>
                </dl>
                </section>
            @endif
        </div>
    </div>
@endsection
