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

    // Built from the registry, not from a list.
    //
    // This used to be four hardcoded blocks: one each for Shopee, Lazada and
    // TikTok, plus a loop over OpenCart stores. Venta, Shopify and Pedallion
    // therefore could not appear on this strip however many stores they had
    // enabled - a channel became visible here only by someone remembering to
    // add another block. visibleOrderTabs() already answers both halves of
    // the question the old blocks answered by hand: it drops an integration
    // that is disabled, and it drops one whose order permission this user
    // does not hold. It is also the source the Fulfilment page and the
    // sidebar badge read, so the three can no longer disagree.
    //
    // Freshness is the one thing a tab does not carry. The marketplaces that
    // contribute a sync status through DashboardContributor keep their
    // "synced 3 weeks ago" line; a channel that contributes none simply does
    // not print one, rather than being left off the strip entirely.
    $syncFor = [
        'shopee' => $shopeeSyncStatus ?? null,
        'lazada' => $lazadaSyncStatus ?? null,
        'tiktok' => $tiktokSyncStatus ?? null,
    ];

    foreach (($syncStatuses ?? collect()) as $store) {
        if (isset($store->id)) {
            $syncFor['opencart:' . $store->id] = $store;
        }
    }

    // The waiting count has TWO legitimate sources and the contributor wins.
    //
    // DashboardContributor lets an extension inject shopeePending and its
    // siblings straight into this view, which is the contract the extension
    // suite pins. OrderTab carries its own counter for the Fulfilment page.
    // Reading only the tab silently dropped the injected figure, so an
    // extension could report a number that never appeared. The tab is the
    // fallback for a channel that injects nothing.
    $pendingFor = [
        'shopee' => $shopeePending ?? null,
        'lazada' => $lazadaPending ?? null,
        'tiktok' => $tiktokPending ?? null,
    ];

    foreach (($syncStatuses ?? collect()) as $store) {
        if (isset($store->id) && isset($store->pending_count)) {
            $pendingFor['opencart:' . $store->id] = (int) $store->pending_count;
        }
    }

    $channels = [];

    try {
        foreach (app(\App\Integrations\IntegrationRegistry::class)->visibleOrderTabs($user) as $tab) {
            $sync = $syncFor[$tab->id] ?? null;

            if ($sync && ($sync->last_status ?? null) === 'failed') {
                $state = ['tone' => 'bad', 'text' => 'last sync failed'];
            } elseif ($sync) {
                $state = $freshness(
                    $sync->order_sync_at ?? $sync->last_order_sync_at ?? null,
                    $sync->expires_at ?? null,
                );
            } else {
                $state = ['tone' => 'muted', 'text' => 'connected'];
            }

            $channels[] = [
                'name' => $tab->label,
                'colour' => $tab->accent ?: $channelColour($tab->id),
                'state' => $state,
                'pending' => $pendingFor[$tab->id] ?? $tab->unprocessedCount(),
                'link' => RouteFacade::has($tab->routeName)
                    ? route($tab->routeName, $tab->routeParams)
                    : null,
            ];
        }
    } catch (\Throwable $e) {
        // A marketplace with an expired token or a missing table must not
        // take the dashboard down with it. No strip is the honest failure.
        $channels = [];
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

         All five carry their fill whatever the figure. An earlier
         version dropped a zero block to white on the reasoning that a
         saturated card shouting 0 reads as a fault; in practice it read
         as two of the five being unfinished, because no orders yet
         today is the normal state of a morning. --}}
    <div class="bl-stats">
        <a class="bl-stat bl-stat--violet" href="{{ route('orders.index') }}">
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

        <a class="bl-stat bl-stat--cyan" href="{{ route('orders.index') }}">
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

        <a class="bl-stat bl-stat--green" href="{{ route('orders.index') }}">
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

        <a class="bl-stat bl-stat--amber" href="{{ route('integrations.orders') }}">
            <span class="bl-stat__top">
                <span class="bl-stat__k">Waiting to ship</span>
                <span class="bl-stat__ic"><x-ui.icon name="send" size="17" /></span>
            </span>
            <span class="bl-stat__v">{{ number_format($pendingTotal) }}</span>
            <span class="bl-stat__d">
                {{ $pendingTotal === 0 ? 'nothing pending' : 'across your channels' }}
            </span>
        </a>

        <a class="bl-stat bl-stat--indigo" href="{{ route('products.index') }}">
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

            {{-- ONE plot, two series, two colours.

                 the owner's reasoning: the lines are already colour coded, so
                 they can share a frame and still be told apart. True, and it
                 halves the vertical space this section spends.

                 The honest cost, stated because it is real: revenue runs to
                 hundreds of thousands of pesos and orders to a couple of
                 dozen, so the two cannot share one y-scale. Each is drawn
                 against its OWN peak, which means the two lines crossing says
                 nothing about the data - it is decided by the scaling. The
                 shapes are comparable; the crossings are not. Each series
                 therefore prints its own peak in the key above the plot, so a
                 reader can see the two scales rather than infer one. --}}
            @php
                $rev = array_map('floatval', $chartRevenue ?? []);
                $ord = array_map('floatval', $chartOrders ?? []);
                $n = min(count($rev), count($ord));
                $revPeak = $rev === [] ? 0.0 : max($rev);
                $ordPeak = $ord === [] ? 0.0 : max($ord);
                $hasPlot = $n > 1 && ($revPeak > 0 || $ordPeak > 0);

                $pw = 1000; $ph = 200; $pad = 12;
                $trace = function (array $v, float $peak) use ($n, $pw, $ph, $pad) {
                    $d = '';
                    for ($i = 0; $i < $n; $i++) {
                        $x = round($i / ($n - 1) * $pw, 2);
                        $y = $peak <= 0 ? $ph - $pad
                           : round($ph - $pad - ($v[$i] / $peak) * ($ph - $pad * 2), 2);
                        $d .= ($i === 0 ? 'M' : 'L') . $x . ',' . $y . ' ';
                    }
                    return rtrim($d);
                };

                $revLine = $hasPlot ? $trace($rev, $revPeak) : '';
                $revArea = $revLine === '' ? '' : $revLine . ' L' . $pw . ',' . $ph . ' L0,' . $ph . ' Z';
                $ordLine = $hasPlot ? $trace($ord, $ordPeak) : '';

                $tickStep = max(1, (int) ceil(($n - 1) / 7));
                $ticks = [];
                for ($t = 0; $t < $n; $t += $tickStep) { $ticks[] = $t; }
                if ($ticks !== [] && end($ticks) !== $n - 1) { $ticks[] = $n - 1; }
            @endphp

            <div class="bl-charts grid gap-px bg-rule-2">
                {{-- The series ride on the element as pre-formatted strings.
                     Currency formatting belongs to App\Support\Money, not to a
                     reimplementation of it in JavaScript that would drift the
                     first time a second currency appeared. --}}
                <div class="bg-sheet px-3.5 pb-4 pt-3 bl-plotcard"
                     @if($hasPlot)
                     data-plot="{{ json_encode([
                         'labels' => array_map(fn ($i) => $chartLabels[$i] ?? '', range(0, $n - 1)),
                         'money'  => array_map(fn ($v) => Money::base((float) $v), array_slice($rev, 0, $n)),
                         'counts' => array_map(fn ($v) => number_format($v) . ' ' . Str::plural('order', $v), array_slice($ord, 0, $n)),
                         'revenue' => array_slice($rev, 0, $n),
                         'orders'  => array_slice($ord, 0, $n),
                         'peaks'   => ['revenue' => $revPeak, 'orders' => $ordPeak],
                     ]) }}"
                     @endif>
                    @if($hasPlot)
                        {{-- The key doubles as the scale disclosure: each series
                             names its own peak, so nobody reads a crossing as a
                             fact about the business. --}}
                        <div class="bl-key">
                            <span class="bl-key__item">
                                <svg class="bl-key__sw" width="14" height="4" viewBox="0 0 14 4" aria-hidden="true" focusable="false">
                                    <rect class="bl-key__money" width="14" height="4" rx="2"></rect>
                                </svg>
                                Revenue
                                <b class="bl-key__peak">peak {{ Money::base($revPeak) }}</b>
                            </span>
                            <span class="bl-key__item">
                                <svg class="bl-key__sw" width="14" height="4" viewBox="0 0 14 4" aria-hidden="true" focusable="false">
                                    <rect class="bl-key__count" width="14" height="4" rx="2"></rect>
                                </svg>
                                Orders
                                <b class="bl-key__peak">peak {{ number_format($ordPeak) }}</b>
                            </span>
                        </div>

                        {{-- The reading is drawn here rather than left to the
                             SVG's native <title>, which the browser holds for
                             about a second before showing. The titles stay for
                             assistive technology. --}}
                        <div class="bl-plotwrap">
                        <svg class="bl-plot bl-plot--dual" viewBox="0 0 {{ $pw }} {{ $ph }}"
                             preserveAspectRatio="none" role="img"
                             aria-label="Revenue and orders over the period, each against its own scale">
                            <defs>
                                <linearGradient id="plot-rev" x1="0" y1="0" x2="0" y2="1">
                                    <stop class="bl-plot__from" offset="0%"></stop>
                                    <stop class="bl-plot__to" offset="100%"></stop>
                                </linearGradient>
                            </defs>

                            @foreach([0.25, 0.5, 0.75] as $g)
                                <line class="bl-plot__grid" x1="0" x2="{{ $pw }}"
                                      y1="{{ $ph * $g }}" y2="{{ $ph * $g }}"></line>
                            @endforeach

                            {{-- Revenue takes the fill because it is the figure a
                                 seller opens the page for; orders ride over it as
                                 a line so neither hides the other. --}}
                            <path class="bl-trace__area" d="{{ $revArea }}" fill="url(#plot-rev)"></path>
                            <path class="bl-trace bl-trace--money" d="{{ $revLine }}"></path>
                            <path class="bl-trace bl-trace--count" d="{{ $ordLine }}"></path>

                            @for($i = 0; $i < $n; $i++)
                                @php
                                    $hx = round($i / ($n - 1) * $pw, 2);
                                    $hw = $pw / ($n - 1);
                                @endphp
                                <rect class="bl-plot__hit" x="{{ max(0, $hx - $hw / 2) }}" y="0"
                                      width="{{ $hw }}" height="{{ $ph }}">
                                    <title>{{ $chartLabels[$i] ?? '' }} &middot; {{ Money::base($rev[$i]) }} &middot; {{ number_format($ord[$i]) }} {{ Str::plural('order', $ord[$i]) }}</title>
                                </rect>
                            @endfor
                        </svg>

                        <span class="bl-guide" data-plot-guide aria-hidden="true"></span>
                        <span class="bl-dot bl-dot--money" data-plot-dot="revenue" aria-hidden="true"></span>
                        <span class="bl-dot bl-dot--count" data-plot-dot="orders" aria-hidden="true"></span>

                        <div class="bl-readout" data-plot-readout aria-hidden="true">
                            <span class="bl-readout__d" data-plot-date></span>
                            <span class="bl-readout__r">
                                <i class="bl-readout__k bl-readout__k--money"></i>
                                <span data-plot-money></span>
                            </span>
                            <span class="bl-readout__r">
                                <i class="bl-readout__k bl-readout__k--count"></i>
                                <span data-plot-count></span>
                            </span>
                        </div>
                        </div>

                        <div class="bl-axis">
                            @foreach($ticks as $t)
                                <span>{{ $chartLabels[$t] ?? '' }}</span>
                            @endforeach
                        </div>
                    @else
                        <p class="py-6 text-[13px] text-ink-3">Nothing recorded in this period yet.</p>
                    @endif
                </div>
            </div>

            @if(!empty($platformSlices))
                <div class="bl-panel-head">
                    <h2>Orders by channel</h2>
                    <span class="text-[12px] text-ink-3">{{ $rangeHeading }}</span>
                    {{-- This rode on the ring's own heading until the ring moved
                         beside the table and that heading went with the cell.
                         It belongs to the section, not to the cell, so it lives
                         on the section heading now. --}}
                    <x-ui.hint label="What this ring counts">Orders, not revenue. A channel with one large
                        order can hold a small share of the ring and the largest figure in the revenue
                        column beside it.</x-ui.hint>
                </div>
                @php
                    $ringTotal = max(1, collect($platformSlices)->sum('orders'));
                    $ringR = 70;
                    $ringC = 2 * M_PI * $ringR;
                    $ringAt = 0.0;

                    // A slice is labelled from the order's marketplace_source and
                    // a channel from its own card, so the two agree on "Shopee"
                    // and disagree on "TikTok" vs "TikTok Shop". Exact match
                    // first, then one prefix match in either direction, which is
                    // enough for that family without pairing two channels that
                    // merely share a first word.
                    $channelFor = function (string $label) use ($channels) {
                        $all = collect($channels ?? []);
                        return $all->firstWhere('name', $label)
                            ?? $all->first(function ($c) use ($label) {
                                $a = Str::lower($c['name']);
                                $b = Str::lower($label);
                                return $a !== $b && (Str::startsWith($a, $b) || Str::startsWith($b, $a));
                            });
                    };
                    $brandFor = fn (string $label) => $channelFor($label)['colour'] ?? null;
                @endphp

                {{-- Ring on the LEFT, because the colour it explains is in the
                     Channel column immediately beside it. On the right it was
                     the full width of the table away from the swatches it
                     matches. --}}
                <div class="bl-split-ring">
                    <div class="bl-split-ring__ring">
            <svg class="bl-ring" width="100%" height="188" viewBox="0 0 200 200" data-ring
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
            data-ring-seg
            data-ring-label="{{ $slice['label'] }}"
            data-ring-count="{{ number_format($slice['orders']) }} {{ Str::plural('order', $slice['orders']) }}"
            data-ring-share="{{ number_format($share * 100, 1) }}% of orders"
            cx="100" cy="100" r="{{ $ringR }}"
            @if($brand) stroke="{{ $brand }}" @endif
            stroke-dasharray="{{ $dash }} {{ round($ringC, 2) }}"
            stroke-dashoffset="{{ $offset }}"
            transform="rotate(-90 100 100)">
            <title>{{ $slice['label'] }}: {{ number_format($slice['orders']) }} {{ Str::plural('order', $slice['orders']) }}</title>
            </circle>
            @endforeach
            {{-- The middle answers the hover. Idle it is the total; over a
                 segment it becomes that channel's own figures, instantly, in
                 space that was already there. No floating panel to position,
                 and no native <title> to wait a second for. The titles stay
                 for assistive technology. --}}
            <text class="bl-ring__v" x="100" y="92" text-anchor="middle" data-ring-v>{{ number_format($ringTotal) }}</text>
            <text class="bl-ring__k" x="100" y="109" text-anchor="middle" data-ring-k>{{ Str::plural('order', $ringTotal) }}</text>
            <text class="bl-ring__s" x="100" y="125" text-anchor="middle" data-ring-s></text>
            </svg>
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
                            @foreach($platformSlices as $i => $slice)
                                @php
                                    $brand = $brandFor($slice['label']);
                                    $href = $channelFor($slice['label'])['link'] ?? null;
                                @endphp
                                <tr>
                                    {{-- The swatch lives on the row it belongs to. It used
                                         to sit in a legend beside this table, which meant
                                         every channel name and every order count was
                                         printed twice, six inches apart. --}}
                                    <td class="font-semibold">
                                        <svg class="bl-tsw" width="10" height="10" viewBox="0 0 10 10"
                                             aria-hidden="true" focusable="false">
                                            <rect class="{{ $brand ? '' : 'bl-sw-' . ($i % 6) }}" width="10" height="10" rx="3"
                                                  @if($brand) fill="{{ $brand }}" @endif></rect>
                                        </svg>
                                        @if($href)
                                            <a href="{{ $href }}">{{ $slice['label'] }}</a>
                                        @else
                                            {{ $slice['label'] }}
                                        @endif
                                    </td>
                                    <td class="num">{{ number_format($slice['orders']) }}</td>
                                    <td class="num">{{ Money::base((float) $slice['revenue']) }}</td>
                                    <td class="num">{{ number_format($slice['orders'] / $sliceTotal * 100, 1) }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
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
