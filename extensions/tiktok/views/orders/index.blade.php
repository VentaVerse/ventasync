@extends('layouts.app')
@section('breadcrumb', 'Marketplace / TikTok Shop / Orders')

@section('content')
<style>
    #content {
        --accent: #E60023;
        --accent-hover: #c4001e;
        --accent-light: rgba(230, 0, 35, 0.08);
    }
    #content .input:focus {
        box-shadow: 0 0 0 3px rgba(230, 0, 35, 0.12);
    }
</style>

<div class="page-header">
    <h2>TikTok Shop Orders <span class="text-secondary text-sm">({{ $orders->total() }})</span></h2>
</div>

{{-- Fetch / Update --}}
<div class="card mb-16">
    <form method="POST" action="{{ route('ext.tiktok.orders.fetch') }}" class="d-flex gap-12 flex-wrap items-center">
        @csrf
        <div>
            <label class="text-xs text-secondary">From</label>
            <input type="date" name="date_from" value="{{ old('date_from', now()->subDays(15)->format('Y-m-d')) }}" class="input" style="width:auto;">
        </div>
        <div>
            <label class="text-xs text-secondary">To</label>
            <input type="date" name="date_to" value="{{ old('date_to', now()->format('Y-m-d')) }}" class="input" style="width:auto;">
        </div>
        <div class="d-flex gap-8">
            <button type="submit" class="btn">Fetch Orders</button>
            <button type="submit" class="btn secondary" formaction="{{ route('ext.tiktok.orders.updateStatuses') }}">Update Statuses</button>
        </div>
        <div class="text-secondary text-xs" style="flex:1;">
            <strong>From/To</strong> required for Fetch. Update refreshes all active orders.
        </div>
    </form>
</div>

@if($last_result)
    <div class="alert {{ $last_result['ok'] ? 'success' : 'danger' }}">
        <strong>{{ $last_result['ok'] ? 'OK' : 'Error' }}</strong>
        @if(!empty($last_result['message']))
            <div style="margin-top:6px;">{{ $last_result['message'] }}</div>
        @endif
    </div>
@endif

{{-- Tabs --}}
<div class="tabs mb-12">
    @foreach($tabs as $key => $label)
        @php
            $isActive = $active_tab === $key;
            $count = $tab_counts[$key] ?? 0;
            $qs = array_merge(request()->except('page'), ['tab' => $key]);
            $showBadge = in_array($key, ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION', 'IN_TRANSIT']);
        @endphp
        <a href="{{ route('ext.tiktok.orders.index', $qs) }}" class="tab {{ $isActive ? 'active' : '' }}">
            <span>{{ $label }}</span>
            @if($showBadge && $count > 0)
                <span style="margin-left:6px; display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 6px; border-radius:999px; font-weight:700; font-size:12px; {{ $isActive ? 'background:#fff; color:#E60023;' : 'background:#E60023; color:#fff;' }}">{{ $count }}</span>
            @endif
        </a>
    @endforeach
</div>

{{-- Search / Filter --}}
<div class="card mb-12" style="padding:12px 16px;">
    <form method="GET" action="{{ route('ext.tiktok.orders.index') }}" class="d-flex gap-10 flex-wrap items-center">
        <input type="hidden" name="tab" value="{{ $active_tab }}">
        <input type="hidden" name="per_page" value="{{ $per_page }}">

        <div>
            <label class="text-xs text-secondary">Order Number</label>
            <input type="text" name="order_number" value="{{ $filters['order_number'] ?? '' }}" placeholder="Order ID" class="input" style="min-width:220px;">
        </div>
        <div>
            <label class="text-xs text-secondary">Buyer Name</label>
            <input type="text" name="buyer_name" value="{{ $filters['buyer_name'] ?? '' }}" placeholder="Customer" class="input" style="min-width:220px;">
        </div>
        <div>
            <label class="text-xs text-secondary">Sort by</label>
            <select name="sort" class="input" style="width:auto; min-width:200px;">
                <option value="created_desc" {{ ($sort ?? '') === 'created_desc' ? 'selected' : '' }}>Newest First</option>
                <option value="created_asc" {{ ($sort ?? '') === 'created_asc' ? 'selected' : '' }}>Oldest First</option>
            </select>
        </div>
        <div class="d-flex gap-8">
            <button type="submit" class="btn">Search</button>
            <a href="{{ route('ext.tiktok.orders.index', ['tab' => $active_tab, 'per_page' => $per_page]) }}" class="btn secondary">Reset</a>
        </div>
    </form>
</div>

{{-- Per page --}}
<div class="card mb-12" style="padding:12px 16px;">
    <form method="GET" action="{{ route('ext.tiktok.orders.index') }}" class="d-flex gap-10 items-center">
        <input type="hidden" name="tab" value="{{ $active_tab }}">
        <input type="hidden" name="order_number" value="{{ $filters['order_number'] ?? '' }}">
        <input type="hidden" name="buyer_name" value="{{ $filters['buyer_name'] ?? '' }}">
        <input type="hidden" name="sort" value="{{ $sort ?? '' }}">
        <div>
            <label class="text-xs text-secondary">Orders per page</label>
            <select name="per_page" onchange="this.form.submit()" class="input" style="width:auto;">
                <option value="10" {{ $per_page === 10 ? 'selected' : '' }}>10</option>
                <option value="20" {{ $per_page === 20 ? 'selected' : '' }}>20</option>
                <option value="50" {{ $per_page === 50 ? 'selected' : '' }}>50</option>
            </select>
        </div>
    </form>
</div>

{{-- Orders Table --}}
<div class="card">
    <div class="font-bold" style="margin-bottom:12px;">
        Orders ({{ $orders->total() }})
    </div>

    @if($orders->count() === 0)
        <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; padding:48px 24px; text-align:center;">
            <svg style="width:48px; height:48px; color:var(--text-muted); margin-bottom:16px; opacity:.45;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            <div style="font-weight:600; font-size:15px; color:var(--text-primary); margin-bottom:6px;">No orders found</div>
            <div class="text-muted" style="font-size:13px; max-width:360px;">
                @if(($filters['order_number'] ?? '') !== '' || ($filters['buyer_name'] ?? '') !== '')
                    No results match your search filters. Try clearing the filters above.
                @elseif($active_tab !== 'ALL')
                    There are no <strong>{{ $tabs[$active_tab] ?? $active_tab }}</strong> orders right now.
                @else
                    No orders have been synced yet. Use <strong>Fetch Orders</strong> above to pull orders from TikTok Shop.
                @endif
            </div>
        </div>
    @endif

    @if($orders->count() > 0)
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="min-width:360px;">Products</th>
                        <th style="width:80px;">Qty</th>
                        <th style="width:120px;">Price</th>
                        <th style="width:120px;">Amount</th>
                        <th style="width:140px;">Shipping</th>
                        <th style="width:120px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($orders as $o)
                        @php
                            $raw = $o->raw ?? [];
                            $orderId = $o->order_id;
                            $status = $o->status ?? '';
                            $created = $o->order_created_at ? $o->order_created_at->format('Y-m-d H:i') : '';
                            $buyer = $o->buyer_name ?: '';

                            $items = $o->products ? $o->products->toArray() : [];
                            $itemCount = count($items);
                            if (empty($items)) { $items = [[]]; }
                            $lineCount = count($items);

                            $payment = $raw['payment'] ?? [];
                            $totalAmount = $payment['total_amount'] ?? $payment['product_total_amount'] ?? null;
                            $currency = $payment['currency'] ?? '₱';

                            // Shipping info
                            $shipping = $raw['shipping_provider'] ?? '';
                            $trackingNo = $raw['tracking_number'] ?? '';
                            if (!$shipping && !empty($raw['line_items'])) {
                                $firstLi = $raw['line_items'][0] ?? [];
                                $shipping = $firstLi['shipping_provider_name'] ?? '';
                                $trackingNo = $firstLi['tracking_number'] ?? $trackingNo;
                            }

                            // Status badge color
                            $badgeClass = match($status) {
                                'COMPLETED', 'DELIVERED' => 'badge-green',
                                'CANCELLED' => 'badge-red',
                                'UNPAID' => 'badge-yellow',
                                'IN_TRANSIT' => 'badge-blue',
                                default => '',
                            };
                            $statusLabel = str_replace('_', ' ', $status);
                        @endphp

                        {{-- Order header row --}}
                        <tr>
                            <td colspan="6" style="padding:10px 12px 6px 12px;">
                                <div class="d-flex justify-between items-start gap-12">
                                    <div class="text-xs text-secondary" style="line-height:1.35;">
                                        <span>Buyer:</span> <span class="font-semibold" style="color:var(--text-primary);">{{ $buyer !== '' ? $buyer : '—' }}</span>
                                        <span style="margin-left:8px;">Order:</span> <span class="font-semibold" style="color:var(--text-primary);">{{ $orderId }}</span>
                                        @if($itemCount > 0)
                                            <span style="margin-left:8px;">({{ $itemCount }} {{ $itemCount === 1 ? 'item' : 'items' }})</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-secondary text-right" style="line-height:1.35;">
                                        @if($created)
                                            <span>Created:</span> <span class="font-semibold" style="color:var(--text-primary);">{{ $created }}</span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        </tr>

                        {{-- Product rows --}}
                        @foreach($items as $idx => $it)
                            @php
                                $itRaw = $it['raw'] ?? $it;
                                $name = $it['name'] ?? '';
                                $sku = $it['sku'] ?? '';
                                $variation = $it['variation'] ?? '';
                                $variationNorm = mb_strtolower(trim($variation));
                                if (in_array($variationNorm, ['', 'blank', 'null', 'n/a', 'na', 'none', '-', '--'], true)) {
                                    $variation = '';
                                }
                                $img = $it['image'] ?? '';
                                $qty = (int) ($it['quantity'] ?? 1);
                                $unitPrice = $it['sale_price'] ?? $it['item_price'] ?? null;
                            @endphp
                            <tr>
                                <td style="vertical-align:top;">
                                    <div class="d-flex gap-10">
                                        <div style="width:44px; height:44px; border-radius:8px; overflow:hidden; background:var(--surface-alt); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                            @if($img)
                                                <img src="{{ $img }}" alt="" style="width:44px; height:44px; object-fit:cover;" loading="lazy">
                                            @else
                                                <span class="text-xs text-muted">—</span>
                                            @endif
                                        </div>
                                        <div style="min-width:0;">
                                            <div class="font-semibold" style="word-break:break-word;">{{ $name ?: '—' }}</div>
                                            <div class="text-xs" style="margin-top:3px;">
                                                <span class="text-secondary">SKU:</span> <strong>{{ $sku ?: '—' }}</strong>
                                            </div>
                                            @if($variation !== '')
                                                <div style="margin-top:3px;">
                                                    <span class="badge badge-gray" style="font-size:11px;">{{ $variation }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td style="vertical-align:top;">
                                    <div class="font-bold">x{{ $qty }}</div>
                                </td>
                                <td style="vertical-align:top;">
                                    <div class="font-bold">
                                        @if($unitPrice !== null && $unitPrice > 0)
                                            {{ $currency }}{{ number_format((float)$unitPrice, 2) }}
                                        @else
                                            —
                                        @endif
                                    </div>
                                </td>
                                @if($idx === 0)
                                    <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                                        <div class="font-bold">
                                            @if($totalAmount !== null)
                                                {{ $currency }}{{ number_format((float)$totalAmount, 2) }}
                                            @else
                                                —
                                            @endif
                                        </div>
                                    </td>
                                    <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                                        @if($shipping)
                                            <div class="text-sm font-semibold">{{ $shipping }}</div>
                                        @endif
                                        @if($trackingNo)
                                            <div class="text-xs text-secondary" style="margin-top:3px;">{{ $trackingNo }}</div>
                                        @endif
                                        @if(!$shipping && !$trackingNo)
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                                        <span class="badge {{ $badgeClass }}">{{ $statusLabel }}</span>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-16">{{ $orders->onEachSide(1)->links('vendor.pagination.simple') }}</div>
    @endif
</div>
@endsection
