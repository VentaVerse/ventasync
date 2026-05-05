@extends('layouts.app')
@section('title', 'TikTok Shop Orders')
@section('breadcrumb', 'Marketplace / TikTok Shop / Orders')

@section('content')
<div class="marketplace-tiktok">
@include('integrations.partials._tab_strip', ['activeTabId' => 'tiktok'])
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
            <button type="submit" class="btn secondary" formaction="{{ route('ext.tiktok.orders.updateStatuses') }}">Update Orders</button>
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
        @if(!empty($last_result['awb_url']))
            <div style="margin-top:8px;">
                <a href="{{ $last_result['awb_url'] }}" target="_blank" class="btn small">Print AWB</a>
            </div>
            <script>window.open(@json($last_result['awb_url']), '_blank');</script>
        @endif
    </div>
@endif

{{-- Tabs --}}
<div class="tabs mb-12">
    @foreach($tabs as $key => $label)
        @php
            $isActive = $active_tab === $key;
            $count = $tab_counts[$key] ?? 0;
            $qs = array_merge(request()->except('page', 'pending_sub'), ['tab' => $key]);
            $showBadge = in_array($key, ['TO_SHIP', 'IN_TRANSIT']);
        @endphp
        <a href="{{ route('ext.tiktok.orders.index', $qs) }}" class="tab {{ $isActive ? 'active' : '' }}">
            <span>{{ $label }}</span>
            @if($showBadge && $count > 0)
                <span style="margin-left:6px; display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 6px; border-radius:999px; font-weight:700; font-size:12px; {{ $isActive ? 'background:#fff; color:#1e1e1e;' : 'background:#1e1e1e; color:#fff;' }}">{{ $count }}</span>
            @endif
        </a>
    @endforeach
</div>

{{-- To Ship subtabs: To Pack / To Handover --}}
@if($active_tab === 'TO_SHIP')
    @php
        $ps = $pending_sub ?? 'to_pack';
        $pendingNav = [
            'to_pack'     => 'To Pack',
            'to_handover' => 'To Handover',
        ];
    @endphp
    <div class="card mb-12" style="padding:12px 16px;">
        <div class="d-flex items-center justify-between gap-10" style="margin-bottom:8px;">
            <div class="font-semibold">To Ship workflow</div>
            <div class="text-xs text-secondary">Choose the step you are working on</div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap; justify-content:center;">
            @foreach($pendingNav as $k => $lbl)
                @php
                    $isSubActive = ($ps === $k) || ($ps === '' && $k === 'to_pack');
                    $subQs = array_merge(request()->except('page'), ['tab' => 'TO_SHIP', 'pending_sub' => $k]);
                    $subCount = $tab_counts[$k] ?? 0;
                @endphp
                <a href="{{ route('ext.tiktok.orders.index', $subQs) }}" class="tab {{ $isSubActive ? 'active' : '' }}">
                    <span>{{ $lbl }}</span>
                    @if($subCount > 0)
                        <span style="margin-left:6px; display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 6px; border-radius:999px; font-weight:700; font-size:12px; {{ $isSubActive ? 'background:#fff; color:#1e1e1e;' : 'background:#1e1e1e; color:#fff;' }}">{{ $subCount }}</span>
                    @endif
                </a>
            @endforeach
        </div>

        @if(!empty($courier_counts))
            <div style="margin-top:10px;">
                <div class="text-xs text-secondary" style="margin-bottom:6px;">Courier tally (To Handover only)</div>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    @foreach($courier_counts as $cc)
                        <span class="badge badge-gray" style="display:inline-flex; align-items:center; gap:8px; font-size:14px; padding:8px 12px;">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
                            {{ $cc['label'] }}
                            <span style="margin-left:2px; font-weight:700; font-size:15px; color:#dc2626;">{{ $cc['count'] }}</span>
                        </span>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endif

{{-- Search / Filter --}}
<div class="card mb-12" style="padding:12px 16px;">
    <form method="GET" action="{{ route('ext.tiktok.orders.index') }}" class="d-flex gap-10 flex-wrap items-center">
        <input type="hidden" name="tab" value="{{ $active_tab }}">
        <input type="hidden" name="per_page" value="{{ $per_page }}">
        @if($pending_sub)<input type="hidden" name="pending_sub" value="{{ $pending_sub }}">@endif

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
        @if($pending_sub)<input type="hidden" name="pending_sub" value="{{ $pending_sub }}">@endif
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
                        <th style="min-width:280px;">Products</th>
                        <th style="width:110px;">Quantity</th>
                        <th style="width:140px;">Product Price</th>
                        <th>Amount</th>
                        <th>Shipping</th>
                        <th>Status</th>
                        <th style="width:280px;">Actions</th>
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

                            // Consolidate duplicate line items by SKU + name + variation
                            $rawItems = $o->products ? $o->products->toArray() : [];
                            $grouped = [];
                            foreach ($rawItems as $ri) {
                                $riName = $ri['name'] ?? '';
                                $riSku = $ri['sku'] ?? '';
                                $riVariation = $ri['variation'] ?? '';
                                $riVarNorm = mb_strtolower(trim($riVariation));
                                if (in_array($riVarNorm, ['', 'blank', 'null', 'n/a', 'na', 'none', '-', '--', 'default'], true)) {
                                    $riVariation = '';
                                }
                                $riQty = (int) ($ri['quantity'] ?? 1);
                                if ($riQty < 1) $riQty = 1;
                                $key = mb_strtolower(trim($riSku)) . '|' . mb_strtolower(trim($riName)) . '|' . mb_strtolower(trim($riVariation));
                                if (!isset($grouped[$key])) {
                                    $grouped[$key] = $ri;
                                    $grouped[$key]['quantity'] = $riQty;
                                    $grouped[$key]['variation'] = $riVariation;
                                } else {
                                    $grouped[$key]['quantity'] += $riQty;
                                    if (empty($grouped[$key]['image']) && !empty($ri['image'])) {
                                        $grouped[$key]['image'] = $ri['image'];
                                    }
                                }
                            }
                            $items = array_values($grouped);
                            $itemCount = count($rawItems);
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

                            // Status flags
                            $isToPack = ($status === 'AWAITING_SHIPMENT');
                            $isToHandover = ($status === 'AWAITING_COLLECTION');
                            $isShipped = in_array($status, ['IN_TRANSIT', 'DELIVERED', 'COMPLETED']);

                            // SLA: tts_sla_time = ship-by deadline. Only meaningful pre-shipment.
                            $awaitingShip = in_array($status, ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION'], true);
                            $shipBy = $awaitingShip ? (int) ($raw['tts_sla_time'] ?? 0) : 0;

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
                        <tr class="order-header-row">
                            <td colspan="7" style="padding:10px 12px 6px 12px;">
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
                                        <div class="order-img-wrap">
                                            @if($img)
                                                <img class="order-img" src="{{ $img }}" alt="" loading="lazy">
                                            @else
                                                <span class="text-xs text-muted">—</span>
                                            @endif
                                        </div>
                                        <div style="min-width:0;">
                                            <div class="font-semibold" style="word-break:break-word;">
                                                <a href="{{ route('ext.tiktok.orders.show', $o->id) }}" target="_blank" rel="noopener" style="color:var(--text-primary); text-decoration:underline; text-underline-offset:2px;">{{ $name ?: '—' }}</a>
                                            </div>
                                            <div class="text-xs" style="margin-top:3px;">
                                                <span class="text-secondary">SKU:</span> <strong>{{ $sku ?: '—' }}</strong>
                                            </div>
                                            @if($variation !== '')
                                                <div class="order-option-wrap">
                                                    <span class="order-option-label tiktok-label">Option</span>
                                                    <span class="order-option-pill">{{ $variation }}</span>
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
                                        <x-sla-chip :deadline="$shipBy" />
                                    </td>
                                    <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                                        <div class="d-flex flex-wrap gap-6">
                                            @if($isToPack)
                                                <form method="POST" action="{{ route('ext.tiktok.orders.ship', $o->id) }}" data-confirm="Ship this order?">
                                                    @csrf
                                                    <button type="submit" class="btn small">Arrange Shipment</button>
                                                </form>
                                            @endif
                                            @if($isToHandover || $isShipped)
                                                <a href="{{ route('ext.tiktok.orders.awb', $o->id) }}" target="_blank" class="btn small secondary">AWB</a>
                                                @if($isToHandover)
                                                    <a href="{{ route('ext.tiktok.orders.awb', [$o->id, 'refresh' => 1]) }}" target="_blank" class="btn small secondary" title="Re-download AWB from TikTok">&#8635;</a>
                                                @endif
                                            @endif
                                            @if($isToHandover || $isShipped)
                                                <button type="button" class="btn small secondary btnTtTracking"
                                                        data-url="{{ route('ext.tiktok.orders.tracking', $o->id) }}">Tracking</button>
                                            @endif
                                        </div>
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
{{-- Tracking modal --}}
<div id="ttTrackingModal" class="modal-backdrop">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <h3>Tracking</h3>
            <button type="button" id="btnCloseTtTracking" class="modal-close">&times;</button>
        </div>
        <div id="ttTrackingBody" class="mt-12">
            <div class="text-secondary">Loading...</div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var trackingModal = document.getElementById('ttTrackingModal');
    var trackingBody = document.getElementById('ttTrackingBody');
    document.getElementById('btnCloseTtTracking').addEventListener('click', function() {
        trackingModal.classList.remove('active');
    });
    trackingModal.addEventListener('click', function(e) {
        if (e.target === trackingModal) trackingModal.classList.remove('active');
    });

    function renderTracking(data) {
        trackingBody.textContent = '';
        if (!data.ok) {
            var err = document.createElement('div');
            err.className = 'text-danger';
            var errBody = data.body || {};
            var errMsg = data.message || errBody.message || '';
            if (!errMsg && errBody.code) errMsg = 'TikTok API error (code: ' + errBody.code + ')';
            if (!errMsg) errMsg = 'Error loading tracking info.';
            err.textContent = errMsg;
            trackingBody.appendChild(err);
            console.warn('Tracking API response:', data);
            return;
        }
        var apiBody = data.body || {};
        var body = apiBody.data || apiBody;
        var trackNum = data.tracking_number || body.tracking_number || '';
        var provider = data.shipping_carrier || body.shipping_provider || '';
        var events = body.tracking || body.tracking_info || body.events || [];

        if (trackNum) {
            var d = document.createElement('div');
            d.className = 'mb-8';
            d.innerHTML = '<strong>Tracking:</strong> ';
            var s = document.createElement('span');
            s.textContent = trackNum;
            d.appendChild(s);
            trackingBody.appendChild(d);
        }
        if (provider) {
            var d2 = document.createElement('div');
            d2.className = 'mb-8';
            d2.innerHTML = '<strong>Courier:</strong> ';
            var s2 = document.createElement('span');
            s2.textContent = provider;
            d2.appendChild(s2);
            trackingBody.appendChild(d2);
        }
        if (Array.isArray(events) && events.length > 0) {
            var wrap = document.createElement('div');
            wrap.style.cssText = 'margin-top:12px; border-top:1px solid var(--border); padding-top:12px;';
            events.forEach(function(ev) {
                var row = document.createElement('div');
                row.style.cssText = 'margin-bottom:10px; padding-left:12px; border-left:2px solid var(--accent);';
                var time = document.createElement('div');
                time.className = 'text-xs text-secondary';
                var ts = ev.update_time_millis || ev.update_time || ev.time || null;
                if (ts && typeof ts === 'number') {
                    var dt = new Date(ts > 1e12 ? ts : ts * 1000);
                    time.textContent = dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0') + '-' + String(dt.getDate()).padStart(2,'0') + ' ' + String(dt.getHours()).padStart(2,'0') + ':' + String(dt.getMinutes()).padStart(2,'0');
                } else {
                    time.textContent = ev.update_time_text || ev.time || '';
                }
                var desc = document.createElement('div');
                desc.className = 'text-sm';
                desc.textContent = ev.description || ev.message || '';
                row.appendChild(time);
                row.appendChild(desc);
                wrap.appendChild(row);
            });
            trackingBody.appendChild(wrap);
        }
        if (!trackingBody.children.length) {
            var empty = document.createElement('div');
            empty.className = 'text-secondary';
            empty.textContent = 'No tracking info available yet.';
            trackingBody.appendChild(empty);
        }
    }

    // Use event delegation so it works regardless of when buttons are rendered
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btnTtTracking');
        if (!btn) return;
        var url = btn.dataset.url;
        if (!url) return;
        trackingBody.textContent = 'Loading...';
        trackingModal.classList.add('active');
        fetch(url, {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}})
            .then(function(r) { return r.json(); })
            .then(renderTracking)
            .catch(function(err) {
                console.error('Tracking fetch error:', err);
                trackingBody.textContent = '';
                var errEl = document.createElement('div');
                errEl.className = 'text-danger';
                errEl.textContent = 'Failed to load tracking info: ' + (err.message || 'Network error');
                trackingBody.appendChild(errEl);
            });
    });
});

    // Image zoom on hover (same pattern as Lazada/Shopee)
    var preview = document.createElement('div');
    preview.className = 'order-img-preview';
    var previewImg = document.createElement('img');
    preview.appendChild(previewImg);
    document.body.appendChild(preview);
    document.addEventListener('mouseover', function(e) {
        var wrap = e.target.closest('.order-img-wrap');
        if (!wrap) return;
        var img = wrap.querySelector('img.order-img');
        if (!img || !img.src) return;
        previewImg.src = img.src;
        var rect = wrap.getBoundingClientRect();
        var top = rect.top + rect.height / 2 - 110;
        var left = rect.right + 12;
        if (left + 230 > window.innerWidth) left = rect.left - 232;
        if (top < 8) top = 8;
        if (top + 220 > window.innerHeight) top = window.innerHeight - 228;
        preview.style.top = top + 'px';
        preview.style.left = left + 'px';
        preview.classList.add('visible');
    }, false);

    document.addEventListener('mouseout', function(e) {
        var wrap = e.target.closest('.order-img-wrap');
        if (!wrap) return;
        var related = e.relatedTarget;
        if (related && wrap.contains(related)) return;
        preview.classList.remove('visible');
    }, false);
</script>
</div>{{-- /.marketplace-tiktok --}}
@endsection
