@extends('layouts.app')
@section('breadcrumb', 'Dashboard')

@section('content')
@php
    // Resolved once per render, not hardcoded, so a future re-basing of the
    // default currency does not require touching every figure on this page.
    $currencySymbol = \App\Support\Money::defaultSymbol();
@endphp
<div class="page-header">
    <h2>{{ $greeting }}, {{ auth()->user()->name ?? 'there' }}</h2>
</div>

{{-- KPI strip --}}
@php
    $globalPending = 0;
    $user = auth()->user();
    if ($user?->hasPermission('view_lazada_orders')) $globalPending += ($lazadaPending ?? 0);
    if ($user?->hasPermission('view_shopee_orders')) $globalPending += ($shopeePending ?? 0);
    if ($user?->hasPermission('view_tiktok_orders')) $globalPending += ($tiktokPending ?? 0);
    if ($user?->hasPermission('view_opencart_orders') && $syncStatuses instanceof \Illuminate\Support\Collection) {
        $globalPending += $syncStatuses->sum('pending_count');
    }
    if ($user?->hasPermission('view_venta_orders') && !empty($ventaStoreNames ?? [])) {
        $pendingStatusIds = \App\Models\Catalog\OrderStatus::whereIn('name', ['Pending', 'Processing'])->pluck('order_status_id');
        foreach ($ventaStoreNames as $storeId => $storeName) {
            $globalPending += (int) DB::table(config('catalog.prefix') . 'order')
                ->where('marketplace_source', 'venta:' . $storeId)
                ->whereIn('order_status_id', $pendingStatusIds)
                ->count();
        }
    }
    $pendingLink = Route::has('integrations.orders') ? route('integrations.orders') : route('orders.index');
@endphp
<div class="dash-snapshot">
    <a href="{{ route('orders.index') }}" class="dash-snap-item">
        <span class="dash-snap-label">Today</span>
        @php
            $todayRevDelta = $yesterdayRevenue > 0 ? round(($todayRevenue - $yesterdayRevenue) / $yesterdayRevenue * 100) : ($todayRevenue > 0 ? 100 : 0);
        @endphp
        <span class="dash-snap-value">{{ number_format($todayOrders) }}<span class="dash-snap-unit">orders</span><span class="dash-snap-sep-dot">/</span><span class="dash-snap-rev">{{ $currencySymbol }}{{ number_format($todayRevenue, 0) }}</span></span>
        <span class="dash-snap-compare">
            @if($todayRevDelta != 0)
                <span class="dash-snap-arrow {{ $todayRevDelta > 0 ? 'is-up' : 'is-down' }}">{{ $todayRevDelta > 0 ? '↑' : '↓' }} {{ abs($todayRevDelta) }}%</span>
            @endif
            vs {{ number_format($yesterdayOrders) }} orders / {{ $currencySymbol }}{{ number_format($yesterdayRevenue, 0) }} yesterday
        </span>
    </a>
    <a href="{{ route('orders.index') }}" class="dash-snap-item">
        <span class="dash-snap-label">Weekly</span>
        @php
            $weekRevDelta = $lastWeekRevenue > 0 ? round(($weekRevenue - $lastWeekRevenue) / $lastWeekRevenue * 100) : ($weekRevenue > 0 ? 100 : 0);
        @endphp
        <span class="dash-snap-value">{{ number_format($weekOrders) }}<span class="dash-snap-unit">orders</span><span class="dash-snap-sep-dot">/</span><span class="dash-snap-rev">{{ $currencySymbol }}{{ number_format($weekRevenue, 0) }}</span></span>
        <span class="dash-snap-compare">
            @if($weekRevDelta != 0)
                <span class="dash-snap-arrow {{ $weekRevDelta > 0 ? 'is-up' : 'is-down' }}">{{ $weekRevDelta > 0 ? '↑' : '↓' }} {{ abs($weekRevDelta) }}%</span>
            @endif
            vs {{ number_format($lastWeekOrders) }} orders / {{ $currencySymbol }}{{ number_format($lastWeekRevenue, 0) }} last week
        </span>
    </a>
    <a href="{{ route('orders.index') }}" class="dash-snap-item">
        <span class="dash-snap-label">Monthly</span>
        @php
            $monthRevDelta = $lastMonthRevenue > 0 ? round(($monthRevenue - $lastMonthRevenue) / $lastMonthRevenue * 100) : ($monthRevenue > 0 ? 100 : 0);
        @endphp
        <span class="dash-snap-value">{{ number_format($monthOrders) }}<span class="dash-snap-unit">orders</span><span class="dash-snap-sep-dot">/</span><span class="dash-snap-rev">{{ $currencySymbol }}{{ number_format($monthRevenue, 0) }}</span></span>
        <span class="dash-snap-compare">
            @if($monthRevDelta != 0)
                <span class="dash-snap-arrow {{ $monthRevDelta > 0 ? 'is-up' : 'is-down' }}">{{ $monthRevDelta > 0 ? '↑' : '↓' }} {{ abs($monthRevDelta) }}%</span>
            @endif
            vs {{ number_format($lastMonthOrders) }} orders / {{ $currencySymbol }}{{ number_format($lastMonthRevenue, 0) }} last month
        </span>
    </a>
    <a href="{{ route('orders.index') }}" class="dash-snap-item">
        <span class="dash-snap-label">All-time</span>
        <span class="dash-snap-value">{{ number_format($totalOrders) }}<span class="dash-snap-unit">orders</span></span>
        <span class="dash-snap-sub"><b>{{ $currencySymbol }}{{ number_format($totalRevenue, 0) }}</b> gross</span>
    </a>
    <a href="{{ $pendingLink }}" class="dash-snap-item">
        <span class="dash-snap-label">Pending</span>
        <span class="dash-snap-value" style="{{ $globalPending > 0 ? 'color:var(--danger);' : '' }}">{{ number_format($globalPending) }}<span class="dash-snap-unit">orders</span></span>
        <span class="dash-snap-sub">across all channels</span>
    </a>
</div>

{{-- Purchasing stats (from extension) --}}
@if(Route::has('ext.purchasing.purchase_orders.index') && auth()->user()?->hasPermission('manage_purchasing'))
<div class="dash-snapshot">
    <a href="{{ route('ext.purchasing.purchase_orders.index') }}" class="dash-snap-item">
        <span class="dash-snap-label">Open POs</span>
        <span class="dash-snap-value">{{ number_format($openPos) }}</span>
    </a>
    <a href="{{ route('ext.purchasing.purchase_orders.index', ['status' => 'ordered']) }}" class="dash-snap-item">
        <span class="dash-snap-label">Pending Delivery</span>
        <span class="dash-snap-value">{{ number_format($pendingDelivery) }}</span>
    </a>
    <a href="{{ route('ext.purchasing.purchase_orders.index', ['status' => 'ordered']) }}" class="dash-snap-item">
        <span class="dash-snap-label">Overdue</span>
        <span class="dash-snap-value" style="{{ $overduePos > 0 ? 'color:var(--danger);' : '' }}">{{ number_format($overduePos) }}</span>
    </a>
    <a href="{{ route('ext.purchasing.purchase_orders.index', ['status' => 'received']) }}" class="dash-snap-item">
        <span class="dash-snap-label">PO Spend (MTD)</span>
        <span class="dash-snap-value" style="font-size:16px;">{{ $currencySymbol }}{{ number_format($monthlyPoSpend, 0) }}</span>
    </a>
    {{-- Inventory figures come from core queries; only the drill-down page
         belongs to the reports extension. Guard the link per-extension -
         this block's outer @if only proves purchasing is enabled. --}}
    @if(Route::has('ext.reports.inventory'))
    <a href="{{ route('ext.reports.inventory') }}" class="dash-snap-item">
    @else
    <div class="dash-snap-item">
    @endif
        <span class="dash-snap-label">Inventory Value</span>
        <span class="dash-snap-value" style="font-size:16px;">{{ $currencySymbol }}{{ number_format($inventoryAtCost ?? 0, 0) }}<span class="dash-snap-sep-dot">/</span>{{ $currencySymbol }}{{ number_format($inventoryAtSale ?? 0, 0) }}</span>
        <span class="dash-snap-compare">cost / sale · {{ $inventoryMargin ?? 0 }}% margin</span>
    @if(Route::has('ext.reports.inventory'))
    </a>
    @else
    </div>
    @endif
</div>
@endif

{{-- Charts: unified filter + side-by-side layout --}}
<div class="card" style="margin-bottom:16px;">
    <div class="d-flex justify-between items-center flex-wrap gap-8" style="margin-bottom:16px;">
        <h3 class="section-title mt-0" style="border:0; padding:0; margin:0;" id="chartTitle">Sales Overview (Last 30 Days)</h3>
        <div class="chart-range-toggle" id="chartRangeToggle">
            <button class="chart-range-btn active" data-range="30d">30 Days</button>
            <button class="chart-range-btn" data-range="monthly">Monthly</button>
            <button class="chart-range-btn" data-range="yearly">Yearly</button>
        </div>
    </div>
    <div style="display:grid; grid-template-columns: 3fr 2fr; gap:24px; align-items:start;">
        <div style="position:relative; width:100%; height:320px;">
            <canvas id="salesChart"></canvas>
        </div>
        <div style="position:relative; overflow:visible;">
            <h4 style="margin:0 0 12px; font-size:13px; font-weight:600; color:var(--text-secondary);">Orders by Platform</h4>
            <div style="display:flex; align-items:center; gap:24px;">
                <div style="position:relative; width:200px; height:200px; flex-shrink:0; overflow:visible;">
                    <canvas id="platformChart"></canvas>
                </div>
                <div id="pieLegend" style="flex:1; font-size:13px;"></div>
            </div>
        </div>
    </div>
</div>

{{-- Sync status row (now its own row, was previously to the right of the pie) --}}
<div style="display:flex; flex-direction:column; gap:16px; margin-bottom:16px;">
    @php
        // Freshness helper: green <1h, yellow <6h, red >6h or never
        $syncDotClass = function ($timestamp, $tokenExpiry = null) {
            if ($tokenExpiry && !$tokenExpiry->isFuture()) return 'dash-sync-fail';
            if (!$timestamp) return 'dash-sync-idle';
            $hours = now()->diffInHours($timestamp);
            if ($hours < 1) return 'dash-sync-ok';
            if ($hours < 6) return 'dash-sync-warn';
            return 'dash-sync-fail';
        };
        $syncTimeLabelFn = function ($label, $timestamp) {
            if (!$timestamp) return $label . ': never';
            if (is_string($timestamp)) $timestamp = \Carbon\Carbon::parse($timestamp);
            $hours = now()->diffInHours($timestamp);
            $text = $timestamp->diffForHumans();
            $color = $hours < 1 ? '#16a34a' : ($hours < 6 ? '#d97706' : '#dc2626');
            return $label . ': <span style="color:' . $color . '">' . e($text) . '</span>';
        };
        $ocDotClass = function ($store) use ($syncDotClass) {
            if ($store->last_status === 'failed') return 'dash-sync-fail';
            return $syncDotClass(
                $store->last_order_sync_at ? \Carbon\Carbon::parse($store->last_order_sync_at) : null
            );
        };
    @endphp
    <div style="display:flex; flex-direction:column; gap:16px;">
        @if(auth()->user()?->hasPermission('view_opencart') && $syncStatuses->isNotEmpty())
        <div class="card">
            <h3 class="section-title mt-0" style="border:0; padding:0; margin:0 0 12px;">OpenCart Sync Status</h3>
            <div class="dash-sync-grid">
                @foreach($syncStatuses as $store)
                    <div class="dash-sync-store">
                        <div class="dash-sync-header">
                            <span class="dash-sync-dot {{ $ocDotClass($store) }}"></span>
                            <span class="font-bold">{{ $store->store_name ?: 'Store #'.$store->id }}</span>
                        </div>
                        <div class="dash-sync-details">
                            <span class="text-xs">{!! $syncTimeLabelFn('Order sync', $store->last_order_sync_at) !!}</span>
                            <span class="text-xs">{!! $syncTimeLabelFn('Push stock', $store->last_push_qty_at) !!}</span>
                            @if($store->last_status === 'failed' && $store->last_error)
                                <span class="text-xs" style="color:#ef4444;">{{ \Illuminate\Support\Str::limit($store->last_error, 60) }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        @if($shopeeSyncStatus || $lazadaSyncStatus || ($tiktokSyncStatus ?? null))
        @php
            $tokenLabel = function ($expiresAt) {
                if (!$expiresAt) return '<span style="color:#dc2626;">Token: not configured</span>';
                $ok = $expiresAt->isFuture();
                $color = $ok ? '#16a34a' : '#dc2626';
                $text = $ok ? 'valid (expires ' . $expiresAt->diffForHumans() . ')' : 'EXPIRED (' . $expiresAt->diffForHumans() . ')';
                return '<span style="color:' . $color . ';">Token: ' . e($text) . '</span>';
            };
        @endphp
        <div class="card">
            <h3 class="section-title mt-0" style="border:0; padding:0; margin:0 0 12px;">Marketplace Sync Status</h3>
            <div class="dash-sync-grid">
                @if($shopeeSyncStatus && auth()->user()?->hasPermission('view_shopee'))
                    @php $shopeeTokenOk = !empty($shopeeSyncStatus->expires_at) && $shopeeSyncStatus->expires_at->isFuture(); @endphp
                    <div class="dash-sync-store">
                        <div class="dash-sync-header">
                            <span class="dash-sync-dot {{ $syncDotClass($shopeeSyncStatus->order_sync_at, $shopeeSyncStatus->expires_at ?? null) }}"></span>
                            <span class="font-bold">Shopee</span>
                        </div>
                        <div class="dash-sync-details">
                            <span class="text-xs">{!! $syncTimeLabelFn('Order sync', $shopeeSyncStatus->order_sync_at) !!}</span>
                            <span class="text-xs">{!! $syncTimeLabelFn('Push stock', $shopeeSyncStatus->push_stock_at) !!}</span>
                            <span class="text-xs">{!! $syncTimeLabelFn('Return sync', $shopeeSyncStatus->return_sync_at) !!}</span>
                            <span class="text-xs">{!! $tokenLabel($shopeeSyncStatus->expires_at ?? null) !!}</span>
                        </div>
                    </div>
                @endif
                @if($lazadaSyncStatus && auth()->user()?->hasPermission('view_lazada'))
                    <div class="dash-sync-store">
                        <div class="dash-sync-header">
                            <span class="dash-sync-dot {{ $syncDotClass($lazadaSyncStatus->order_sync_at, $lazadaSyncStatus->expires_at ?? null) }}"></span>
                            <span class="font-bold">Lazada</span>
                        </div>
                        <div class="dash-sync-details">
                            <span class="text-xs">{!! $syncTimeLabelFn('Order sync', $lazadaSyncStatus->order_sync_at) !!}</span>
                            <span class="text-xs">{!! $syncTimeLabelFn('Push stock', $lazadaSyncStatus->push_stock_at) !!}</span>
                            <span class="text-xs">{!! $syncTimeLabelFn('Return sync', $lazadaSyncStatus->return_sync_at) !!}</span>
                            <span class="text-xs">{!! $tokenLabel($lazadaSyncStatus->expires_at ?? null) !!}</span>
                        </div>
                    </div>
                @endif
                @if(($tiktokSyncStatus ?? null) && auth()->user()?->hasPermission('view_tiktok'))
                    <div class="dash-sync-store">
                        <div class="dash-sync-header">
                            <span class="dash-sync-dot {{ $syncDotClass($tiktokSyncStatus->order_sync_at ?? null, $tiktokSyncStatus->expires_at ?? null) }}"></span>
                            <span class="font-bold">TikTok Shop</span>
                            @if(($tiktokPending ?? 0) > 0)
                                <span style="margin-left:6px; display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 6px; border-radius:999px; font-weight:700; font-size:12px; background:#1e1e1e; color:#fff;">{{ $tiktokPending }}</span>
                            @endif
                        </div>
                        <div class="dash-sync-details">
                            <span class="text-xs">{!! $syncTimeLabelFn('Order sync', $tiktokSyncStatus->order_sync_at ?? null) !!}</span>
                            <span class="text-xs">{!! $syncTimeLabelFn('Push stock', $tiktokSyncStatus->push_stock_at ?? null) !!}</span>
                            <span class="text-xs">{!! $tokenLabel($tiktokSyncStatus->expires_at ?? null) !!}</span>
                        </div>
                    </div>
                @endif
            </div>
        </div>
        @endif
</div>

{{-- Bottom row: Quick links + Recent orders --}}
<div style="display:grid; grid-template-columns: 1fr 2fr; gap:16px;">
    {{-- Quick links --}}
    <div class="card">
        <h3 class="section-title mt-0">Quick Links</h3>
        <div class="dash-links">
            @if(auth()->user()?->hasPermission('view_lazada_orders') && Route::has('ext.lazada.orders.index'))
            <a href="{{ route('ext.lazada.orders.index') }}">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <span>Lazada Orders</span>
                @if($lazadaPending > 0)
                    <span class="dash-link-count" style="background:#dc2626; color:#fff;">{{ $lazadaPending }}</span>
                @else
                    <span class="dash-link-count">No new orders</span>
                @endif
            </a>
            @endif
            @if(auth()->user()?->hasPermission('view_shopee_orders') && Route::has('ext.shopee.orders.index'))
            <a href="{{ route('ext.shopee.orders.index') }}">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <span>Shopee Orders</span>
                @if($shopeePending > 0)
                    <span class="dash-link-count" style="background:#dc2626; color:#fff;">{{ $shopeePending }}</span>
                @else
                    <span class="dash-link-count">No new orders</span>
                @endif
            </a>
            @endif
            @if(auth()->user()?->hasPermission('view_tiktok_orders') && Route::has('ext.tiktok.orders.index'))
            <a href="{{ route('ext.tiktok.orders.index') }}">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <span>TikTok Orders</span>
                @if(($tiktokPending ?? 0) > 0)
                    <span class="dash-link-count" style="background:#dc2626; color:#fff;">{{ $tiktokPending }}</span>
                @else
                    <span class="dash-link-count">No new orders</span>
                @endif
            </a>
            @endif
            <a href="{{ route('orders.index') }}">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
                <span>Orders</span>
                <span class="dash-link-count">{{ number_format($totalOrders) }}</span>
            </a>
        </div>
    </div>

    {{-- Recent orders --}}
    <div class="card">
        <div class="d-flex justify-between items-center" style="margin-bottom:12px;">
            <h3 class="section-title mt-0" style="border:0; padding:0; margin:0;">Recent Orders</h3>
            <a href="{{ route('orders.index') }}" class="btn small">View All</a>
        </div>
        @if($recentOrders->isEmpty())
            <div class="text-secondary" style="padding:12px 0;">No orders yet.</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Source</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentOrders as $o)
                            <tr>
                                <td>
                                    <a href="{{ route('orders.show', $o->order_id) }}" class="font-bold" style="color:var(--accent);">
                                        #{{ $o->order_id }}
                                    </a>
                                </td>
                                <td>{{ trim(($o->firstname ?? '') . ' ' . ($o->lastname ?? '')) ?: '-' }}</td>
                                <td class="font-bold">
                                    <x-money :php="(float) $o->total" :foreign="$o->foreign_total" :foreign-code="$o->currency_code" />
                                </td>
                                <td>
                                    @if($o->status_name)
                                        <span class="badge badge-blue">{{ $o->status_name }}</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $src = trim((string)$o->marketplace_source);
                                        $opt = $sourceLabelsMap[$src] ?? null;
                                    @endphp
                                    @if($src === '')
                                        <span class="text-muted">Direct</span>
                                    @elseif($opt)
                                        <span class="badge {{ $opt['badge_class'] ?? 'badge-dark' }}">{{ $opt['label'] }}</span>
                                    @else
                                        <span class="badge badge-gray">{{ ucfirst($src) }}</span>
                                    @endif
                                </td>
                                <td class="text-secondary text-xs">{{ $o->date_added ? \Carbon\Carbon::parse($o->date_added)->format('M d, Y H:i') : '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var ctx = document.getElementById('salesChart');
    if (!ctx) return;

    var labels = @json($chartLabels);
    var revenue = @json($chartRevenue);
    var orders = @json($chartOrders);
    // Resolved server-side from the currencies table, not hardcoded - see
    // $currencySymbol above.
    var currencySymbol = @json($currencySymbol);

    // Detect dark mode
    var cs = getComputedStyle(document.documentElement);
    var textColor = cs.getPropertyValue('--text-secondary').trim() || '#64748b';
    var borderColor = cs.getPropertyValue('--border-light').trim() || '#e2e8f0';

    var titles = {
        '30d': 'Sales (Last 30 Days)',
        'monthly': 'Sales (Last 12 Months)',
        'yearly': 'Sales (Last 5 Years)'
    };
    var subtitles = {
        '30d': 'Revenue & order count per day',
        'monthly': 'Revenue & order count per month',
        'yearly': 'Revenue & order count per year'
    };

    var salesChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Revenue',
                    data: revenue,
                    backgroundColor: 'rgba(59,130,246,.65)',
                    borderColor: '#3b82f6',
                    borderWidth: 1,
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 2,
                },
                {
                    label: 'Orders',
                    data: orders,
                    type: 'line',
                    borderColor: '#22c55e',
                    backgroundColor: 'rgba(34,197,94,.12)',
                    pointBackgroundColor: '#22c55e',
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    yAxisID: 'y1',
                    order: 1,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        color: textColor,
                        usePointStyle: true,
                        pointStyle: 'circle',
                        padding: 16,
                        font: { size: 12 }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15,23,42,.9)',
                    titleFont: { size: 12 },
                    bodyFont: { size: 12 },
                    padding: 10,
                    cornerRadius: 8,
                    callbacks: {
                        label: function(ctx) {
                            if (ctx.dataset.label === 'Revenue') {
                                return 'Revenue: ' + currencySymbol + Number(ctx.parsed.y).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
                            }
                            return 'Orders: ' + ctx.parsed.y;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: {
                        color: textColor,
                        font: { size: 11 },
                        maxRotation: 45,
                        autoSkip: true,
                        maxTicksLimit: 15,
                    }
                },
                y: {
                    position: 'left',
                    beginAtZero: true,
                    grid: { color: borderColor },
                    ticks: {
                        color: textColor,
                        font: { size: 11 },
                        callback: function(v) {
                            if (v >= 1000000) return currencySymbol + (v/1000000).toFixed(1) + 'M';
                            if (v >= 1000) return currencySymbol + (v/1000).toFixed(0) + 'K';
                            return currencySymbol + v;
                        }
                    },
                    title: {
                        display: true,
                        text: 'Revenue',
                        color: textColor,
                        font: { size: 12 }
                    }
                },
                y1: {
                    position: 'right',
                    beginAtZero: true,
                    grid: { drawOnChartArea: false },
                    ticks: {
                        color: textColor,
                        font: { size: 11 },
                        precision: 0,
                    },
                    title: {
                        display: true,
                        text: 'Orders',
                        color: textColor,
                        font: { size: 12 }
                    }
                }
            }
        }
    });

    // ---- Platform distribution pie chart ----
    var pieCtx = document.getElementById('platformChart');
    var pieLegend = document.getElementById('pieLegend');

    var platformChart = null;

    function renderPie(slices) {
        var labels = slices.map(function(s) { return s.label; });
        var data = slices.map(function(s) { return s.orders; });
        var colors = slices.map(function(s) { return s.color; });

        if (platformChart) {
            platformChart.data.labels = labels;
            platformChart.data.datasets[0].data = data;
            platformChart.data.datasets[0].backgroundColor = colors;
            platformChart.update();
        } else if (pieCtx) {
            platformChart = new Chart(pieCtx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: colors,
                        borderWidth: 2,
                        borderColor: cs.getPropertyValue('--card-bg').trim() || '#fff',
                        hoverOffset: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '55%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            enabled: false,
                            external: function(context) {
                                var tooltipEl = document.getElementById('pieChartTooltip');
                                if (!tooltipEl) {
                                    tooltipEl = document.createElement('div');
                                    tooltipEl.id = 'pieChartTooltip';
                                    tooltipEl.style.cssText = 'position:fixed;pointer-events:none;z-index:9999;background:rgba(15,23,42,.92);color:#fff;padding:8px 12px;border-radius:8px;font-size:12px;font-family:inherit;white-space:nowrap;transition:opacity .15s;box-shadow:0 4px 12px rgba(0,0,0,.25);';
                                    document.body.appendChild(tooltipEl);
                                }
                                var tooltip = context.tooltip;
                                if (tooltip.opacity === 0) {
                                    tooltipEl.style.opacity = '0';
                                    return;
                                }
                                var s = slices[tooltip.dataPoints[0].dataIndex];
                                var total = data.reduce(function(a,b){return a+b;}, 0);
                                var pct = total > 0 ? Math.round(s.orders / total * 100) : 0;
                                tooltipEl.innerHTML = '<strong>' + s.label + '</strong><br>' + s.orders + ' orders (' + pct + '%) - ' + currencySymbol + Number(s.revenue).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
                                var canvasRect = context.chart.canvas.getBoundingClientRect();
                                tooltipEl.style.opacity = '1';
                                tooltipEl.style.left = (canvasRect.left + tooltip.caretX + 10) + 'px';
                                tooltipEl.style.top = (canvasRect.top + tooltip.caretY - 10) + 'px';
                            }
                        }
                    }
                }
            });
        }

        // Custom legend
        if (pieLegend) {
            var totalOrders = slices.reduce(function(a,s){return a+s.orders;}, 0);
            var totalRevenue = slices.reduce(function(a,s){return a+s.revenue;}, 0);
            var html = '';
            slices.forEach(function(s) {
                var pct = totalOrders > 0 ? Math.round(s.orders / totalOrders * 100) : 0;
                html += '<div class="pie-legend-item">';
                html += '<span class="pie-legend-dot" style="background:' + s.color + ';"></span>';
                html += '<span class="pie-legend-label">' + s.label + '</span>';
                html += '<span class="pie-legend-value">';
                html += '<span class="font-bold">' + s.orders + '</span>';
                html += '<span class="text-secondary text-xs"> (' + pct + '%)</span>';
                html += '<br><span class="text-xs text-secondary">' + currencySymbol + Number(s.revenue).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</span>';
                html += '</span>';
                html += '</div>';
            });
            if (slices.length > 0) {
                html += '<div class="pie-legend-item" style="border-top:2px solid var(--border); margin-top:4px; padding-top:8px;">';
                html += '<span class="pie-legend-dot" style="background:transparent;"></span>';
                html += '<span class="pie-legend-label">Total</span>';
                html += '<span class="pie-legend-value">';
                html += '<span class="font-bold">' + totalOrders + '</span>';
                html += '<br><span class="text-xs" style="color:#16a34a; font-weight:600;">' + currencySymbol + Number(totalRevenue).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</span>';
                html += '</span>';
                html += '</div>';
            }
            pieLegend.innerHTML = html;
        }
    }

    function loadPieData(range) {
        fetch('{{ route("dashboard.platform_data") }}?range=' + range)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                renderPie(data.slices || []);
            });
    }

    // Initial load
    loadPieData('30d');

    // Unified range toggle - drives BOTH charts
    document.querySelectorAll('#chartRangeToggle .chart-range-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var range = this.dataset.range;
            this.closest('.chart-range-toggle').querySelectorAll('.chart-range-btn').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            document.getElementById('chartTitle').textContent = titles[range] || titles['30d'];

            fetch('{{ route("dashboard.chart_data") }}?range=' + range)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    salesChart.data.labels = data.labels;
                    salesChart.data.datasets[0].data = data.revenue;
                    salesChart.data.datasets[1].data = data.orders;
                    salesChart.update();
                });

            loadPieData(range);
        });
    });
});
</script>
@endsection
