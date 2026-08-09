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

    {{-- The headline figures. Filled blocks rather than a ruled band:
         the owner picked this against CoreUI Pro and it is the brief.

         Colour here says WHICH MEASURE, never how it is doing, so the
         written label carries the meaning and nothing is lost without
         hue. State colour stays on the rows below, where it belongs.

         is-zero drops a block to white. Most of these are zero on a
         quiet morning, and a saturated block shouting 0 reads as a
         fault rather than as a calm nothing-yet. --}}
    <div class="bl-stats">
        <a class="bl-stat bl-stat--violet {{ $todayOrders === 0 ? 'is-zero' : '' }}" href="{{ route('orders.index') }}">
            <span class="bl-stat__top">
                <span class="bl-stat__k">Orders today</span>
                <span class="bl-stat__ic"><x-ui.icon name="cart" size="17" /></span>
            </span>
            <span class="bl-stat__v">{{ number_format($todayOrders) }}</span>
            <span class="bl-stat__d">
                @if($ordersDelta === null)
                    none yesterday to compare
                @else
                    <x-ui.icon :name="$ordersDelta >= 0 ? 'arrow-up-right' : 'arrow-down-right'" size="13" />
                    {{ $ordersDelta >= 0 ? '+' : '' }}{{ number_format($ordersDelta, 1) }}% vs yesterday
                @endif
            </span>
        </a>

        <a class="bl-stat bl-stat--cyan {{ $todayRevenue <= 0 ? 'is-zero' : '' }}" href="{{ route('orders.index') }}">
            <span class="bl-stat__top">
                <span class="bl-stat__k">Revenue today</span>
                <span class="bl-stat__ic"><x-ui.icon name="banknote" size="17" /></span>
            </span>
            <span class="bl-stat__v">{{ Money::base($todayRevenue) }}</span>
            <span class="bl-stat__d">
                @if($revenueDelta === null)
                    none yesterday to compare
                @else
                    <x-ui.icon :name="$revenueDelta >= 0 ? 'arrow-up-right' : 'arrow-down-right'" size="13" />
                    {{ $revenueDelta >= 0 ? '+' : '' }}{{ number_format($revenueDelta, 1) }}% vs yesterday
                @endif
            </span>
        </a>

        <a class="bl-stat bl-stat--green {{ $weekRevenue <= 0 ? 'is-zero' : '' }}" href="{{ route('orders.index') }}">
            <span class="bl-stat__top">
                <span class="bl-stat__k">Week to date</span>
                <span class="bl-stat__ic"><x-ui.icon name="trending-up" size="17" /></span>
            </span>
            <span class="bl-stat__v">{{ Money::base($weekRevenue) }}</span>
            <span class="bl-stat__d">
                @if($weekDelta !== null)
                    <x-ui.icon :name="$weekDelta >= 0 ? 'arrow-up-right' : 'arrow-down-right'" size="13" />
                @endif
                {{ number_format($weekOrders) }} {{ Str::plural('order', $weekOrders) }}
                @if($weekDelta !== null)
                    &middot; {{ $weekDelta >= 0 ? '+' : '' }}{{ number_format($weekDelta, 1) }}%
                @endif
            </span>
        </a>

        <a class="bl-stat bl-stat--amber {{ $pendingTotal === 0 ? 'is-zero' : '' }}" href="{{ route('integrations.orders') }}">
            <span class="bl-stat__top">
                <span class="bl-stat__k">Waiting to ship</span>
                <span class="bl-stat__ic"><x-ui.icon name="send" size="17" /></span>
            </span>
            <span class="bl-stat__v">{{ number_format($pendingTotal) }}</span>
            <span class="bl-stat__d">
                {{ $pendingTotal === 0 ? 'nothing pending' : 'across your channels' }}
            </span>
        </a>

        <a class="bl-stat bl-stat--indigo {{ $inventoryAtCost <= 0 ? 'is-zero' : '' }}" href="{{ route('products.index') }}">
            <span class="bl-stat__top">
                <span class="bl-stat__k">Stock at cost</span>
                <span class="bl-stat__ic"><x-ui.icon name="boxes" size="17" /></span>
            </span>
            <span class="bl-stat__v">{{ abs($inventoryAtCost) >= 1000000 ? Money::compactAxis($inventoryAtCost) : Money::base($inventoryAtCost) }}</span>
            <span class="bl-stat__d">{{ number_format($inventoryMargin, 1) }}% margin at list</span>
        </a>
    </div>

    {{-- Channels, as chips. A 9px square beside a name made six channels
         read as six near-identical words; a filled rounded chip carrying
         the initial is scannable at a glance.

         The chip colour is the channel's own brand hue and is the one
         place a non-token colour is allowed. It never carries meaning by
         itself: the name is beside it and the freshness sentence under
         it, both in words. --}}
    @if($channels !== [])
        <div class="bl-chans">
            @foreach($channels as $channel)
                @php
                    $chTag = $channel['link'] ? 'a' : 'div';
                    $chBad = ($channel['state']['tone'] ?? '') === 'bad';
                @endphp
                <{{ $chTag }} class="bl-chan" @if($channel['link']) href="{{ $channel['link'] }}" @endif>
                    {{-- Drawn as SVG, not as a span with an inline custom
                         property: this project puts no CSS in Blade and the
                         suite enforces it. fill is a presentation attribute,
                         which is how the swatch this replaces carried a
                         per-channel colour too. --}}
                    <svg class="bl-chan__ic" width="40" height="40" viewBox="0 0 40 40"
                         aria-hidden="true" focusable="false">
                        <rect width="40" height="40" rx="13"
                              fill="{{ $channel['colour'] ?: '#5f6b85' }}"></rect>
                        <text class="bl-chan__in" x="20" y="20" text-anchor="middle"
                              dominant-baseline="central">{{ Str::upper(Str::substr($channel['name'], 0, 1)) }}</text>
                    </svg>
                    <span class="bl-chan__body">
                        <span class="bl-chan__n block truncate">{{ $channel['name'] }}</span>
                        <span class="bl-chan__s {{ $chBad ? 'bl-chan__s--bad' : '' }} block truncate">
                            {{ $channel['state']['text'] }}
                        </span>
                    </span>
                    @if($channel['pending'] !== null)
                        <span class="bl-chan__q">
                            <span class="bl-chan__qv {{ $channel['pending'] > 0 ? 'bl-chan__qv--attn' : '' }}">
                                {{ number_format($channel['pending']) }}
                            </span>
                            <span class="bl-chan__ql block">waiting</span>
                        </span>
                    @endif
                </{{ $chTag }}>
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
                        $n = count($values);

                        // Plot space. Wide and flat so the path has room to
                        // say something; the stroke keeps its weight through
                        // non-scaling-stroke rather than through the viewBox.
                        $pw = 1000; $ph = 200; $pad = 12;

                        $pt = function (int $i) use ($values, $peak, $pw, $ph, $pad, $n) {
                            $x = $n <= 1 ? 0 : round($i / ($n - 1) * $pw, 2);
                            $y = $peak <= 0 ? $ph - $pad
                               : round($ph - $pad - ($values[$i] / $peak) * ($ph - $pad * 2), 2);
                            return [$x, $y];
                        };

                        $line = '';
                        foreach (array_keys($values) as $i) {
                            [$x, $y] = $pt($i);
                            $line .= ($i === 0 ? 'M' : 'L') . $x . ',' . $y . ' ';
                        }
                        $area = $line . 'L' . $pw . ',' . $ph . ' L0,' . $ph . ' Z';
                        $gid = 'plot-' . Str::slug($plot['title']);
                    @endphp
                    <div class="bg-sheet px-3.5 pb-4 pt-3">
                        <h3 class="bl-label">{{ $plot['title'] }}</h3>
                        @if($peak > 0)
                            <svg class="bl-plot bl-plot--{{ $plot['unit'] }} mt-2"
                                 viewBox="0 0 {{ $pw }} {{ $ph }}" preserveAspectRatio="none"
                                 role="img" aria-label="{{ $plot['title'] }} over the period">
                                <defs>
                                    <linearGradient id="{{ $gid }}" x1="0" y1="0" x2="0" y2="1">
                                        <stop class="bl-plot__from" offset="0%"></stop>
                                        <stop class="bl-plot__to" offset="100%"></stop>
                                    </linearGradient>
                                </defs>

                                @foreach([0.25, 0.5, 0.75] as $g)
                                    <line class="bl-plot__grid" x1="0" x2="{{ $pw }}"
                                          y1="{{ $ph * $g }}" y2="{{ $ph * $g }}"></line>
                                @endforeach

                                <g class="bl-plot__reveal">
                                    <path class="bl-plot__area" d="{{ $area }}" fill="url(#{{ $gid }})"></path>
                                    <path class="bl-plot__line" d="{{ $line }}"></path>
                                </g>

                                @php [$nx, $ny] = $pt($lastIndex); @endphp
                                <line class="bl-plot__now" x1="{{ $nx }}" x2="{{ $nx }}"
                                      y1="{{ $ny }}" y2="{{ $ph }}"></line>

                                {{-- One transparent target per period, so a reading
                                     is still one hover away. The figures are all in
                                     the table below too, so nothing lives only
                                     behind a pointer. --}}
                                @foreach($values as $i => $value)
                                    @php
                                        [$hx] = $pt($i);
                                        $hw = $n <= 1 ? $pw : $pw / ($n - 1);
                                        $reading = $plot['unit'] === 'money'
                                            ? Money::base((float) $value)
                                            : number_format($value);
                                    @endphp
                                    <rect class="bl-plot__hit" x="{{ max(0, $hx - $hw / 2) }}" y="0"
                                          width="{{ $hw }}" height="{{ $ph }}">
                                        <title>{{ ($chartLabels[$i] ?? '') . ': ' . $reading }}</title>
                                    </rect>
                                @endforeach
                            </svg>
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

                {{-- The ring is the glance and the table under it is the
                     record: share at a look, exact orders and revenue when
                     you need them. The ring is not standing in for the
                     numbers, it sits above them.

                     A channel that has its own brand colour keeps it, which
                     is the same hue its chip carries at the top of the page.
                     One that has none falls back to an identity accent by
                     position, through a class rather than a fill attribute,
                     so it follows the theme. --}}
                @php
                    $ringTotal = max(1, collect($platformSlices)->sum('orders'));
                    $ringR = 70;
                    $ringC = 2 * M_PI * $ringR;
                    $ringAt = 0.0;
                    $brandFor = fn (string $label) => collect($channels ?? [])
                        ->firstWhere('name', $label)['colour'] ?? null;
                @endphp
                <div class="bl-scroll">
                    <svg class="bl-ring" width="188" height="188" viewBox="0 0 200 200"
                         role="img" aria-label="Share of orders by channel">
                        @foreach($platformSlices as $i => $slice)
                            @php
                                $share = $slice['orders'] / $ringTotal;
                                $dash = round($share * $ringC, 2);
                                $offset = round(-$ringAt * $ringC, 2);
                                $ringAt += $share;
                                $brand = $brandFor($slice['label']);
                            @endphp
                            <circle class="bl-ring__seg {{ $brand ? '' : 'bl-slice-' . ($i % 6) }}"
                                    cx="100" cy="100" r="{{ $ringR }}"
                                    @if($brand) stroke="{{ $brand }}" @endif
                                    stroke-dasharray="{{ $dash }} {{ round($ringC, 2) }}"
                                    stroke-dashoffset="{{ $offset }}"
                                    transform="rotate(-90 100 100)">
                                <title>{{ $slice['label'] }}: {{ number_format($slice['orders']) }} {{ Str::plural('order', $slice['orders']) }}</title>
                            </circle>
                        @endforeach
                        <text class="bl-ring__v" x="100" y="97" text-anchor="middle">{{ number_format($ringTotal) }}</text>
                        <text class="bl-ring__k" x="100" y="116" text-anchor="middle">{{ Str::plural('order', $ringTotal) }}</text>
                    </svg>

                    <div class="bl-share">
                        @php $shareAt = 0; @endphp
                        @foreach($platformSlices as $i => $slice)
                            @php $brand = $brandFor($slice['label']); @endphp
                            <div class="bl-share__row">
                                <svg class="bl-share__sw" width="10" height="10" viewBox="0 0 10 10"
                                     aria-hidden="true" focusable="false">
                                    <rect class="{{ $brand ? '' : 'bl-sw-' . ($i % 6) }}" width="10" height="10" rx="3"
                                          @if($brand) fill="{{ $brand }}" @endif></rect>
                                </svg>
                                <span class="bl-share__n">{{ $slice['label'] }}</span>
                                <span class="bl-share__v">{{ number_format($slice['orders'] / $ringTotal * 100, 1) }}%</span>
                            </div>
                        @endforeach
                    </div>
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
