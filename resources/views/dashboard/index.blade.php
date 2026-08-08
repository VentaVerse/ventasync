{{--
    The dashboard. Rebuilt on layouts.shell (Round F, Task 3), which makes
    this the last core page to leave layouts/app.blade.php and takes the
    app's last CDN dependency with it.

    What changed and why, in the order a reader meets it:

    1. The KPI strip was five identical boxes in a row, each equally loud,
       which tells the eye nothing about what matters. Today is now the
       lead figure and the other periods are a hairline-separated strip
       beside it. Deltas are text with a direction word and an arrow, not
       a chart: a period-over-period change is one number and a sign.

    2. The sales chart was a DUAL-AXIS chart - revenue bars on 'y', an
       orders line on 'y1'. Two y-scales on one plot is the single worst
       thing a chart can do, because where the two series appear to cross
       is set by how the axes were scaled, not by the data. It is now two
       stacked charts over the same periods, each with its own baseline
       and its own labelled units, which is the honest version of the same
       comparison. See partials/trend.blade.php.

    3. The platform doughnut, painted in seven marketplace brand colours,
       is a ranked bar list in one colour with the channel names written
       out. See partials/rank.blade.php for the measurements behind that.

    4. Chart.js came off cdn.jsdelivr.net on every page load. Both charts
       are inline SVG built from the controller's arrays now: no library,
       no network request, no third-party script running in an
       authenticated admin session, and both themes work for free because
       every colour is a token.

    5. The range toggle used to swap chart data over AJAX. It is three
       links to ?range=... now, so the whole page (not just the canvases)
       agrees with the range, and the page needs no JavaScript at all.

    Money: every currency figure on this page goes through App\Support\Money
    or <x-money>. The order table's `total` column is in the default
    currency, and `foreign_total`/`currency_code` carry the as-transacted
    figure beside it, so the sums here are single-currency and honest - no
    figure on this page adds a converted amount to an unconverted one.

    Extension content: everything below that is not core comes from
    IntegrationRegistry::dashboardContributors(). The controller merges each
    contributor's dashboardData() into the view data unchanged; the keys are
    exactly the ones the old page consumed.
--}}
@extends('layouts.shell')
@section('title', 'Dashboard')

@section('content')
@include('partials.flash')

@php
    $user = auth()->user();

    /*
     * Period-over-period change, as a whole percent. A period with no
     * predecessor is "new" rather than "up 100%", which is what the old
     * page claimed: a jump from zero is not a percentage.
     */
    $delta = function (float $now, float $before): ?array {
        if ($before <= 0.0) {
            return $now > 0.0 ? ['dir' => 'new', 'pct' => null] : null;
        }
        $pct = (int) round(($now - $before) / $before * 100);
        if ($pct === 0) {
            return ['dir' => 'flat', 'pct' => 0];
        }
        return ['dir' => $pct > 0 ? 'up' : 'down', 'pct' => abs($pct)];
    };

    $todayDelta = $delta((float) $todayRevenue, (float) $yesterdayRevenue);
    $weekDelta = $delta((float) $weekRevenue, (float) $lastWeekRevenue);
    $monthDelta = $delta((float) $monthRevenue, (float) $lastMonthRevenue);

    /*
     * Pending across the channels this operator can actually see. Each
     * count comes from a contributor's own table (Shopee's READY_TO_SHIP,
     * Lazada's pending/repacked, and so on), so it is gated on the same
     * permission that opens that channel's order list; a figure an
     * operator cannot drill into would just be a number they cannot check.
     * Venta keeps no order table of its own, so its pending count comes
     * from the core order table via $pendingBySource.
     */
    $ventaPending = 0;
    foreach (($ventaStoreNames ?? []) as $storeId => $storeName) {
        $ventaPending += (int) ($pendingBySource['venta:' . $storeId] ?? 0);
    }

    $globalPending = 0;
    if ($user?->hasPermission('view_lazada_orders')) $globalPending += (int) ($lazadaPending ?? 0);
    if ($user?->hasPermission('view_shopee_orders')) $globalPending += (int) ($shopeePending ?? 0);
    if ($user?->hasPermission('view_tiktok_orders')) $globalPending += (int) ($tiktokPending ?? 0);
    if ($user?->hasPermission('view_opencart_orders') && ($syncStatuses ?? null) instanceof \Illuminate\Support\Collection) {
        $globalPending += (int) $syncStatuses->sum('pending_count');
    }
    if ($user?->hasPermission('view_venta_orders')) $globalPending += $ventaPending;

    $pendingLink = Route::has('integrations.orders') ? route('integrations.orders') : route('orders.index');

    $ranges = [
        '30d' => ['label' => '30 days', 'heading' => 'the last 30 days'],
        'monthly' => ['label' => '12 months', 'heading' => 'the last 12 months'],
        'yearly' => ['label' => '5 years', 'heading' => 'the last 5 years'],
    ];
    $rangeHeading = $ranges[$range]['heading'] ?? $ranges['30d']['heading'];
@endphp

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">{{ $greeting }}, {{ $user->name ?? 'there' }}</h1>
        <p class="x-page-sub">{{ now()->format('l, j F Y') }}</p>
    </div>
</div>

{{-- ================================================================
     The figures band. One lead figure at display size, then the other
     periods as a hairline-separated strip. Varying the weight is what
     tells the eye that today is the thing to read first.
     ================================================================ --}}
<section class="db-band" aria-label="Sales figures">
    <a class="db-lead" href="{{ route('orders.index') }}">
        <span class="db-lead__k">Today</span>
        <span class="db-lead__v x-num">{{ \App\Support\Money::base((float) $todayRevenue) }}</span>
        <span class="db-lead__sub">
            <span class="x-num">{{ number_format($todayOrders) }}</span> {{ \Illuminate\Support\Str::plural('order', $todayOrders) }}
            @if($todayDelta)
                <span class="db-delta db-delta--{{ $todayDelta['dir'] }}">
                    @if($todayDelta['dir'] === 'up')<x-ui.icon name="arrow-up-right" size="12" />@endif
                    @if($todayDelta['dir'] === 'down')<x-ui.icon name="arrow-down-right" size="12" />@endif
                    @if($todayDelta['pct'] !== null)<span class="x-num">{{ $todayDelta['pct'] }}%</span>@else new @endif
                    vs yesterday
                </span>
            @else
                <span class="db-delta db-delta--flat">same as yesterday</span>
            @endif
        </span>
    </a>

    <div class="db-facts">
        <a class="db-fact" href="{{ route('orders.index') }}">
            <span class="db-fact__k">This week</span>
            <span class="db-fact__v x-num">{{ \App\Support\Money::base((float) $weekRevenue) }}</span>
            <span class="db-fact__note">
                <span class="x-num">{{ number_format($weekOrders) }}</span> {{ \Illuminate\Support\Str::plural('order', $weekOrders) }}@if($weekDelta),
                <span class="db-delta db-delta--{{ $weekDelta['dir'] }}">@if($weekDelta['pct'] !== null){{ $weekDelta['dir'] === 'down' ? 'down' : ($weekDelta['dir'] === 'up' ? 'up' : 'level') }} <span class="x-num">{{ $weekDelta['pct'] }}%</span>@else new @endif</span>
                on last week
                @endif
            </span>
        </a>
        <a class="db-fact" href="{{ route('orders.index') }}">
            <span class="db-fact__k">This month</span>
            <span class="db-fact__v x-num">{{ \App\Support\Money::base((float) $monthRevenue) }}</span>
            <span class="db-fact__note">
                <span class="x-num">{{ number_format($monthOrders) }}</span> {{ \Illuminate\Support\Str::plural('order', $monthOrders) }}@if($monthDelta),
                <span class="db-delta db-delta--{{ $monthDelta['dir'] }}">@if($monthDelta['pct'] !== null){{ $monthDelta['dir'] === 'down' ? 'down' : ($monthDelta['dir'] === 'up' ? 'up' : 'level') }} <span class="x-num">{{ $monthDelta['pct'] }}%</span>@else new @endif</span>
                on last month
                @endif
            </span>
        </a>
        <a class="db-fact" href="{{ route('orders.index') }}">
            <span class="db-fact__k">All time</span>
            <span class="db-fact__v x-num">{{ \App\Support\Money::base((float) $totalRevenue) }}</span>
            <span class="db-fact__note"><span class="x-num">{{ number_format($totalOrders) }}</span> {{ \Illuminate\Support\Str::plural('order', $totalOrders) }}</span>
        </a>
        <a class="db-fact" href="{{ $pendingLink }}">
            <span class="db-fact__k">Waiting to ship</span>
            <span class="db-fact__v x-num {{ $globalPending > 0 ? 'db-fact__v--attention' : '' }}">{{ number_format($globalPending) }}</span>
            <span class="db-fact__note">across your channels</span>
        </a>
    </div>
</section>

{{-- ================================================================
     Trend. One range control above all three charts, so every chart in
     this section always shows the same slice and the numbers agree.
     ================================================================ --}}
<div class="db-section-head">
    <h2 class="db-section-title">Sales over {{ $rangeHeading }}</h2>
    <div class="x-segment" role="group" aria-label="Chart range">
        @foreach($ranges as $key => $meta)
            <a class="x-segment__item {{ $range === $key ? 'is-active' : '' }}"
               href="{{ route('dashboard', ['range' => $key]) }}"
               @if($range === $key) aria-current="true" @endif>{{ $meta['label'] }}</a>
        @endforeach
    </div>
</div>

<div class="db-charts">
    <div class="db-charts__pair">
        @include('dashboard.partials.trend', [
            'title' => 'Revenue',
            'labels' => $chartLabels,
            'values' => $chartRevenue,
            'tone' => 'money',
            'unit' => 'money',
        ])
        @include('dashboard.partials.trend', [
            'title' => 'Orders',
            'labels' => $chartLabels,
            'values' => $chartOrders,
            'tone' => 'count',
            'unit' => 'count',
        ])
    </div>
    @include('dashboard.partials.rank', ['slices' => $platformSlices])
</div>

{{-- ================================================================
     Stock and purchasing. Inventory figures are core queries; the
     purchase-order figures arrive from the purchasing extension through
     the dashboard contributor registry, so the whole strip only appears
     for an operator who holds the permission that opens those records.
     ================================================================ --}}
@php
    $canSeePurchasing = Route::has('ext.purchasing.purchase_orders.index') && $user?->hasPermission('manage_purchasing');
@endphp
<section class="db-band db-band--sub" aria-label="Stock and purchasing">
    <div class="db-facts db-facts--wide">
        @if(Route::has('ext.reports.inventory'))
        <a class="db-fact" href="{{ route('ext.reports.inventory') }}">
        @else
        <div class="db-fact">
        @endif
            <span class="db-fact__k">Stock at cost</span>
            <span class="db-fact__v x-num">{{ \App\Support\Money::base((float) ($inventoryAtCost ?? 0)) }}</span>
            <span class="db-fact__note">what the shelves cost you</span>
        @if(Route::has('ext.reports.inventory'))
        </a>
        @else
        </div>
        @endif

        <div class="db-fact">
            <span class="db-fact__k">Stock at sale price</span>
            <span class="db-fact__v x-num">{{ \App\Support\Money::base((float) ($inventoryAtSale ?? 0)) }}</span>
            <span class="db-fact__note">what it lists for</span>
        </div>

        <div class="db-fact">
            <span class="db-fact__k">Margin on stock</span>
            <span class="db-fact__v x-num">{{ number_format((float) ($inventoryMargin ?? 0), 1) }}%</span>
            <span class="db-fact__note">if it all sold at list</span>
        </div>

        @if($canSeePurchasing)
        <a class="db-fact" href="{{ route('ext.purchasing.purchase_orders.index') }}">
            <span class="db-fact__k">Open purchase orders</span>
            <span class="db-fact__v x-num">{{ number_format($openPos ?? 0) }}</span>
            <span class="db-fact__note"><span class="x-num">{{ number_format($pendingDelivery ?? 0) }}</span> awaiting delivery</span>
        </a>
        <a class="db-fact" href="{{ route('ext.purchasing.purchase_orders.index', ['status' => 'ordered']) }}">
            <span class="db-fact__k">Overdue deliveries</span>
            <span class="db-fact__v x-num {{ ($overduePos ?? 0) > 0 ? 'db-fact__v--attention' : '' }}">{{ number_format($overduePos ?? 0) }}</span>
            <span class="db-fact__note">past their expected date</span>
        </a>
        <a class="db-fact" href="{{ route('ext.purchasing.purchase_orders.index', ['status' => 'received']) }}">
            <span class="db-fact__k">Purchasing this month</span>
            <span class="db-fact__v x-num">{{ \App\Support\Money::base((float) ($monthlyPoSpend ?? 0)) }}</span>
            <span class="db-fact__note">received and booked</span>
        </a>
        @endif
    </div>
</section>

{{-- ================================================================
     Channels and recent orders.
     ================================================================ --}}
@php
    /*
     * One row per channel, built from the contributor keys. The old page
     * printed this twice: a "Quick Links" panel with pending counts and a
     * separate "Sync Status" panel with the same channels' timestamps. A
     * reader who wanted to know "is Shopee healthy and does it need me"
     * had to read two lists. One row now answers both.
     *
     * Freshness is a word, not a colour alone: "synced 4 minutes ago" is
     * the information, and the dot repeats it for someone scanning.
     */
    $freshness = function ($timestamp, $expiresAt = null): array {
        if ($expiresAt && !$expiresAt->isFuture()) {
            return ['tone' => 'danger', 'text' => 'reconnect needed, the access token expired'];
        }
        if (!$timestamp) {
            return ['tone' => 'neutral', 'text' => 'never synced'];
        }
        $when = is_string($timestamp) ? \Carbon\Carbon::parse($timestamp) : $timestamp;
        $hours = now()->diffInHours($when);
        $tone = $hours < 1 ? 'success' : ($hours < 6 ? 'warning' : 'danger');
        return ['tone' => $tone, 'text' => 'synced ' . $when->diffForHumans()];
    };

    $channels = [];

    if (($shopeeSyncStatus ?? null) && $user?->hasPermission('view_shopee')) {
        $channels[] = [
            'name' => 'Shopee', 'key' => 'shopee',
            'state' => $freshness($shopeeSyncStatus->order_sync_at ?? null, $shopeeSyncStatus->expires_at ?? null),
            'pending' => $user->hasPermission('view_shopee_orders') ? (int) ($shopeePending ?? 0) : null,
            'link' => Route::has('ext.shopee.orders.index') ? route('ext.shopee.orders.index') : null,
        ];
    }
    if (($lazadaSyncStatus ?? null) && $user?->hasPermission('view_lazada')) {
        $channels[] = [
            'name' => 'Lazada', 'key' => 'lazada',
            'state' => $freshness($lazadaSyncStatus->order_sync_at ?? null, $lazadaSyncStatus->expires_at ?? null),
            'pending' => $user->hasPermission('view_lazada_orders') ? (int) ($lazadaPending ?? 0) : null,
            'link' => Route::has('ext.lazada.orders.index') ? route('ext.lazada.orders.index') : null,
        ];
    }
    if (($tiktokSyncStatus ?? null) && $user?->hasPermission('view_tiktok')) {
        $channels[] = [
            'name' => 'TikTok Shop', 'key' => 'tiktok',
            'state' => $freshness($tiktokSyncStatus->order_sync_at ?? null, $tiktokSyncStatus->expires_at ?? null),
            'pending' => $user->hasPermission('view_tiktok_orders') ? (int) ($tiktokPending ?? 0) : null,
            'link' => Route::has('ext.tiktok.orders.index') ? route('ext.tiktok.orders.index') : null,
        ];
    }
    if ($user?->hasPermission('view_opencart') && ($syncStatuses ?? null) instanceof \Illuminate\Support\Collection) {
        foreach ($syncStatuses as $store) {
            $state = $store->last_status === 'failed'
                ? ['tone' => 'danger', 'text' => 'last sync failed']
                : $freshness($store->last_order_sync_at ?? null);
            $channels[] = [
                'name' => $store->store_name ?: 'OpenCart store ' . $store->id,
                'key' => 'opencart',
                'state' => $state,
                'pending' => $user->hasPermission('view_opencart_orders') ? (int) ($store->pending_count ?? 0) : null,
                'link' => null,
                'error' => $store->last_status === 'failed' ? $store->last_error : null,
            ];
        }
    }
@endphp

<div class="db-lower">
    <section class="db-panel">
        <div class="db-panel__head">
            <h2 class="db-panel__title">Channels</h2>
            @if(Route::has('integrations.index'))
                <a class="db-panel__link" href="{{ route('integrations.index') }}">All integrations <x-ui.icon name="chevron-right" size="12" /></a>
            @endif
        </div>

        @if($channels === [])
            <p class="db-chart__empty">No channel is connected yet.</p>
        @else
        <ul class="db-chan">
            @foreach($channels as $chan)
                <li class="db-chan__row">
                    <span class="db-chan__name">
                        @if($chan['link'])
                            <a class="db-chan__link" href="{{ $chan['link'] }}">{{ $chan['name'] }}</a>
                        @else
                            {{ $chan['name'] }}
                        @endif
                    </span>
                    <x-ui.badge :tone="$chan['state']['tone']">{{ $chan['state']['text'] }}</x-ui.badge>
                    {{-- No count at all rather than a placeholder when the
                         operator cannot open that channel's order list: a
                         dash in a figure column reads as "zero orders",
                         which would be a different and wrong statement. --}}
                    @if($chan['pending'] !== null)
                        <span class="db-chan__pending x-num">{{ number_format($chan['pending']) }} <span class="db-chan__pending-k">waiting</span></span>
                    @else
                        <span class="db-chan__pending"></span>
                    @endif
                    @if(!empty($chan['error']))
                        <span class="db-chan__err">{{ \Illuminate\Support\Str::limit($chan['error'], 90) }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
        @endif
    </section>

    <section class="db-panel">
        <div class="db-panel__head">
            <h2 class="db-panel__title">Recent orders</h2>
            <a class="db-panel__link" href="{{ route('orders.index') }}">All orders <x-ui.icon name="chevron-right" size="12" /></a>
        </div>

        @if($recentOrders->isEmpty())
            <p class="db-chart__empty">No orders yet.</p>
        @else
        <x-ui.table class="db-orders">
            <x-slot:head>
                <tr>
                    <th class="x-col-id">Order</th>
                    <th>Customer</th>
                    <th class="db-col-chan">Channel</th>
                    <th class="db-col-stat">Status</th>
                    <th class="x-td-num db-col-total">Total</th>
                    <th class="db-col-date">Date</th>
                </tr>
            </x-slot:head>
            @foreach($recentOrders as $o)
                @php
                    $src = trim((string) $o->marketplace_source);
                    $chanKey = $src === '' ? '' : strtok($src, ':');
                    $chanOpt = $src === '' ? null : ($sourceLabelsMap[$src] ?? null);
                    $chanLabel = $src === '' ? 'Manual' : ($chanOpt['label'] ?? ucfirst($chanKey));
                    $customer = trim(($o->firstname ?? '') . ' ' . ($o->lastname ?? ''));
                @endphp
                <tr>
                    <td class="x-col-id" data-label="Order">
                        <a class="x-row-link x-num" href="{{ route('orders.show', $o->order_id) }}">{{ $o->order_id }}</a>
                    </td>
                    <td class="x-cell-strong" data-label="Customer">{{ $customer !== '' ? $customer : 'Guest' }}</td>
                    {{-- Same rule the orders list settled on: presence of a
                         dot means "this is a state", no dot means "this is
                         where it came from", so a brand hue sitting one
                         column from a status hue never reads as a signal. --}}
                    <td class="db-col-chan" data-label="Channel">
                        <x-ui.badge :tone="$src === '' ? 'neutral' : $chanKey" :dot="false">{{ $chanLabel }}</x-ui.badge>
                    </td>
                    <td class="db-col-stat" data-label="Status">
                        <x-ui.badge :tone="\App\Support\OrderStatusTone::for($o->status_name ?? null)">{{ $o->status_name ?? 'Unknown' }}</x-ui.badge>
                    </td>
                    <td class="x-td-num db-col-total" data-label="Total">
                        <x-money :php="(float) $o->total" :foreign="$o->foreign_total" :foreign-code="$o->currency_code" />
                    </td>
                    {{-- Day and time, no year. These are the last ten orders,
                         so the year is the one part nobody is reading, and
                         printing it pushed the column past its width and
                         ellipsised the minutes instead. --}}
                    <td class="x-num x-cell-muted db-col-date" data-label="Date">{{ $o->date_added ? \Carbon\Carbon::parse($o->date_added)->format('M d H:i') : '-' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </section>
</div>
@endsection
