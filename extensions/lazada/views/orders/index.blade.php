@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Lazada / Orders')

@section('content')
<div class="marketplace-lazada">
@include('integrations.partials._tab_strip', ['activeTabId' => 'lazada'])
<div class="page-header">
    <h2>Lazada Orders <span class="text-secondary text-sm">({{ $orders->total() }})</span></h2>
</div>

<div class="card mb-16">
<form method="POST" action="{{ route('ext.lazada.orders.fetch') }}" id="formFetchLazadaOrders" class="d-flex gap-12 flex-wrap items-center">
        @csrf
        <div>
            <label class="text-xs text-secondary">From</label>
            <input type="date" name="date_from" value="{{ old('date_from', request('date_from')) }}" class="input" style="width:auto;">
        </div>
        <div>
            <label class="text-xs text-secondary">To</label>
            <input type="date" name="date_to" value="{{ old('date_to', request('date_to')) }}" class="input" style="width:auto;">
        </div>
        <div style="align-self:flex-end;">
            <label class="text-xs text-secondary" style="display:flex; align-items:center; gap:4px; cursor:pointer;">
                <input type="checkbox" name="no_stock" value="1"> Skip stock adjust
            </label>
        </div>
        <div class="d-flex gap-8">
            <button type="submit" class="btn" id="btnFetchOrders">Fetch Orders</button>
            <button type="submit" class="btn secondary" id="btnUpdateOrders" formaction="{{ route('ext.lazada.orders.update_statuses') }}">Update Orders</button>
        </div>
        <div class="text-secondary text-xs" style="flex:1;">
	        <strong>From/To</strong> required for both Fetch and Update.
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

    @php
        $tabs = $tabs ?? [];
        $active_tab = $active_tab ?? 'ALL';
        $tab_counts = $tab_counts ?? [];
        $pending_sub_counts = $pending_sub_counts ?? [];
        $fd_sub_counts = $fd_sub_counts ?? [];
    @endphp

    <div class="tabs mb-12">
        @foreach($tabs as $key => $label)
            @php
                $isActive = strtoupper((string)$active_tab) === strtoupper((string)$key);
                $count = $tab_counts[$key] ?? null;
                $qs = array_merge(request()->except('page'), ['tab' => $key]);
                $showBadge = in_array($key, ['TO_SHIP', 'SHIPPING', 'FAILED_DELIVERY']);
            @endphp
            <a href="{{ route('ext.lazada.orders.index', $qs) }}" class="tab {{ $isActive ? 'active' : '' }}">
                <span>{{ $label }}</span>
                @if($showBadge && $count !== null && $count > 0)
                    <span style="margin-left:6px; display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 6px; border-radius:999px; font-weight:700; font-size:12px; {{ $isActive ? 'background:#fff; color:#0F146D;' : 'background:#0F146D; color:#fff;' }}">{{ $count }}</span>
                @endif
            </a>
        @endforeach
        <a href="{{ route('ext.lazada.orders.returns') }}" class="tab">Return/Refund/Cancel</a>
    </div>

    {{-- To Ship workflow: sub steps (To Pack / To Arrange / To Handover) --}}
    @if(strtoupper((string)($active_tab ?? 'ALL')) === 'TO_SHIP')
        @php
            $ps = $pending_subtab ?? request()->query('pending_sub', 'to_pack');
            $pendingNav = [
                'to_pack' => 'To Pack',
                'to_arrange' => 'To Arrange Shipment',
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
                        $isActive = ($ps === $k) || ($ps === '' && $k === 'to_pack');
                        $qs = array_merge(request()->except('page'), ['tab' => 'TO_SHIP', 'pending_sub' => $k]);
                        $count = $pending_sub_counts[$k] ?? null;
                    @endphp
                    <a href="{{ route('ext.lazada.orders.index', $qs) }}" class="tab {{ $isActive ? 'active' : '' }}">
                        <span>{{ $lbl }}</span>
                        @if($count !== null && $count > 0)
                            <span style="margin-left:6px; display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 6px; border-radius:999px; font-weight:700; font-size:12px; {{ $isActive ? 'background:#fff; color:#0F146D;' : 'background:#0F146D; color:#fff;' }}">{{ $count }}</span>
                        @endif
                    </a>
                @endforeach
            </div>

            @if(!empty($to_ship_courier_counts))
                <div style="margin-top:10px;">
                    <div class="text-xs text-secondary" style="margin-bottom:6px;">Courier tally (To Handover only)</div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        @foreach($to_ship_courier_counts as $courier => $count)
                            <span class="badge badge-gray" style="display:inline-flex; align-items:center; gap:8px; font-size:14px; padding:8px 12px;">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
                                {{ $courier }}
                                <span style="margin-left:2px; font-weight:700; font-size:15px; color:#dc2626;">{{ $count }}</span>
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- Failed Delivery sub-tabs --}}
    @if(strtoupper((string)($active_tab ?? 'ALL')) === 'FAILED_DELIVERY')
        @php
            $fs = $fd_subtab ?? request()->query('fd_sub', 'failed_delivery');
            $fdNav = [
                'failed_delivery' => 'Failed Delivery',
                'shipped_back' => 'Shipped Back',
                'lost_damaged' => 'Lost & Damaged',
            ];
        @endphp
        <div class="card mb-12" style="padding:12px 16px;">
            <div class="d-flex items-center justify-between gap-10" style="margin-bottom:8px;">
                <div class="font-semibold">Failed Delivery</div>
                <div class="text-xs text-secondary">Filter by delivery failure type</div>
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap; justify-content:center;">
                @foreach($fdNav as $k => $lbl)
                    @php
                        $isActive = ($fs === $k) || ($fs === '' && $k === 'failed_delivery');
                        $qs = array_merge(request()->except('page'), ['tab' => 'FAILED_DELIVERY', 'fd_sub' => $k]);
                        $count = $fd_sub_counts[$k] ?? null;
                    @endphp
                    <a href="{{ route('ext.lazada.orders.index', $qs) }}" class="tab {{ $isActive ? 'active' : '' }}">
                        <span>{{ $lbl }}</span>
                        @if($count !== null && $count > 0)
                            <span style="margin-left:6px; display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 6px; border-radius:999px; font-weight:700; font-size:12px; {{ $isActive ? 'background:#fff; color:#0F146D;' : 'background:#0F146D; color:#fff;' }}">{{ $count }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card mb-12" style="padding:12px 16px;">
    <form method="GET" action="{{ route('ext.lazada.orders.index') }}" class="d-flex gap-10 flex-wrap items-center">
        <input type="hidden" name="tab" value="{{ $active_tab ?? request()->query('tab', 'ALL') }}">
        <input type="hidden" name="per_page" value="{{ (int)($per_page ?? request()->query('per_page', 10)) }}">

        <div>
            <label class="text-xs text-secondary">Order Number</label>
            <input type="text" name="order_number" value="{{ old('order_number', $filters['order_number'] ?? request()->query('order_number', '')) }}" placeholder="Order ID" class="input" style="min-width:220px;">
        </div>

        <div>
            <label class="text-xs text-secondary">Buyer Name</label>
            <input type="text" name="buyer_name" value="{{ old('buyer_name', $filters['buyer_name'] ?? request()->query('buyer_name', '')) }}" placeholder="Customer" class="input" style="min-width:220px;">
        </div>

        <div>
            <label class="text-xs text-secondary">Sort by</label>
            <select name="sort" class="input" style="width:auto; min-width:240px;">
                <option value="created_asc" {{ ($sort ?? '') === 'created_asc' ? 'selected' : '' }}>Created Date (Oldest to Newest)</option>
                <option value="created_desc" {{ ($sort ?? '') === 'created_desc' ? 'selected' : '' }}>Created Date (Newest to Oldest)</option>
                <option value="confirmed_created_asc" {{ ($sort ?? '') === 'confirmed_created_asc' ? 'selected' : '' }}>Confirmed: Created At (Oldest to Newest)</option>
                <option value="confirmed_created_desc" {{ ($sort ?? '') === 'confirmed_created_desc' ? 'selected' : '' }}>Confirmed: Created At (Newest to Oldest)</option>
                <option value="confirmed_updated_asc" {{ ($sort ?? '') === 'confirmed_updated_asc' ? 'selected' : '' }}>Confirmed: Updated At (Oldest to Newest)</option>
                <option value="confirmed_updated_desc" {{ ($sort ?? '') === 'confirmed_updated_desc' ? 'selected' : '' }}>Confirmed: Updated At (Newest to Oldest)</option>
                <option value="promised_shipping_asc" {{ ($sort ?? '') === 'promised_shipping_asc' ? 'selected' : '' }}>Confirmed: Promised Shipping (Oldest to Newest)</option>
                <option value="promised_shipping_desc" {{ ($sort ?? '') === 'promised_shipping_desc' ? 'selected' : '' }}>Confirmed: Promised Shipping (Newest to Oldest)</option>
            </select>
        </div>

        <div class="d-flex gap-8">
            <button type="submit" class="btn">Search</button>
            <a href="{{ route('ext.lazada.orders.index', ['tab' => ($active_tab ?? 'ALL'), 'per_page' => (int)($per_page ?? 10)]) }}" class="btn secondary">Reset</a>
        </div>
    </form>
    </div>

    <div class="card mb-12" style="padding:12px 16px;">
    <form method="GET" action="{{ route('ext.lazada.orders.index') }}" class="d-flex gap-10 items-center">
    <input type="hidden" name="tab" value="{{ $active_tab ?? request()->query('tab', 'ALL') }}">
    <input type="hidden" name="order_number" value="{{ $filters['order_number'] ?? request()->query('order_number', '') }}">
    <input type="hidden" name="buyer_name" value="{{ $filters['buyer_name'] ?? request()->query('buyer_name', '') }}">
    <input type="hidden" name="sort" value="{{ $sort ?? request()->query('sort', '') }}">
    <div>
        <label class="text-xs text-secondary">Orders per page</label>
        <select name="per_page" onchange="this.form.submit()" class="input" style="width:auto;">
            <option value="10" {{ (int)($per_page ?? 10)===10 ? 'selected' : '' }}>10</option>
            <option value="20" {{ (int)($per_page ?? 10)===20 ? 'selected' : '' }}>20</option>
            <option value="50" {{ (int)($per_page ?? 10)===50 ? 'selected' : '' }}>50</option>
        </select>
    </div>
    <noscript><button type="submit" class="btn secondary">Apply</button></noscript>
</form>
    </div>

<div class="card">
    <div class="font-bold" style="margin-bottom:12px;">
        Orders ({{ method_exists($orders,'total') ? $orders->total() : $orders->count() }})
    </div>

        @if($orders->count() === 0)
            <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; padding:48px 24px; text-align:center;">
                <svg style="width:48px; height:48px; color:var(--text-muted); margin-bottom:16px; opacity:.45;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                <div style="font-weight:600; font-size:15px; color:var(--text-primary); margin-bottom:6px;">No orders found</div>
                <div class="text-muted" style="font-size:13px; max-width:360px;">
                    @if(($filters['order_number'] ?? request()->query('order_number', '')) !== '' || ($filters['buyer_name'] ?? request()->query('buyer_name', '')) !== '')
                        No results match your search filters. Try clearing the filters above.
                    @elseif(strtoupper($active_tab ?? 'ALL') !== 'ALL')
                        There are no <strong>{{ $tabs[$active_tab] ?? $active_tab }}</strong> orders right now.
                    @else
                        No orders have been synced yet. Use <strong>Fetch Orders</strong> above to pull orders from Lazada.
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

                            $orderId = $o->order_id ?? ($raw['order_number'] ?? $raw['order_id'] ?? $raw['orderId'] ?? $raw['id'] ?? null);

                            $status = $o->status ?? ($raw['statuses'] ?? $raw['status'] ?? null);
                            $liveStatus = $orderId ? ($live_statuses[$orderId] ?? null) : null;
                            if ($liveStatus !== null && $liveStatus !== '') { $status = $liveStatus; }
                            if (is_array($status)) $status = implode(', ', $status);
                            $statusStr = is_string($status) ? $status : '';

                            $statusNorm = strtolower(trim($statusStr));
                            $isUnpaid = ($statusNorm === 'unpaid');
                            $isCancelled = in_array($statusNorm, ['canceled','cancelled'], true);
                            $isToPack = in_array($statusNorm, ['pending', 'repacked'], true);
                            $isToArrange = ($statusNorm === 'packed');
                            $isToHandover = ($statusNorm === 'ready_to_ship');
                            $canSelect = !($isUnpaid || $isCancelled);

                            $created = $o->order_created_at ? $o->order_created_at->format('Y-m-d H:i') : ($raw['created_at'] ?? $raw['createdAt'] ?? $raw['created_time'] ?? null);

                            $buyer = (string)($raw['customer_first_name'] ?? $raw['customer_name'] ?? $raw['buyer_name'] ?? '');

                            // Products: prefer persisted products; fall back to common raw nodes.
                            $items = [];
                            if (method_exists($o, 'products') && $o->relationLoaded('products')) {
                                $items = $o->products ? $o->products->toArray() : [];
                            }
                            if (empty($items)) {
                                $items = $raw['order_items'] ?? $raw['items'] ?? $raw['orderItems'] ?? [];
                                if (!is_array($items)) $items = [];
                            }


                            // Group identical products (same SKU + name + variation) and sum quantities
                            $grouped = [];
                            if (is_array($items)) {
                                foreach ($items as $row) {
                                    $rowRaw = $row['raw'] ?? $row;

                                    $gName = $row['name'] ?? $rowRaw['name'] ?? $rowRaw['item_name'] ?? $rowRaw['product_name'] ?? '';
                                    $gSku = $row['sku'] ?? $rowRaw['sku'] ?? $rowRaw['seller_sku'] ?? $rowRaw['SellerSku'] ?? $rowRaw['SellerSKU'] ?? $rowRaw['SellerSku'] ?? $rowRaw['Sku'] ?? '';
                                    $gVariation = (string)($row['variation'] ?? $rowRaw['variation'] ?? $rowRaw['Variation'] ?? $rowRaw['variant'] ?? '');
                                    $gVariationNorm = mb_strtolower(trim($gVariation));
                                    if (in_array($gVariationNorm, ['', 'blank', 'null', 'n/a', 'na', 'none', '-', '--'], true)) {
                                        $gVariation = '';
                                    }

                                    $gQty = (int)($row['quantity'] ?? $rowRaw['quantity'] ?? $rowRaw['qty'] ?? 1);
                                    if ($gQty < 1) { $gQty = 1; }

                                    $gImg = $row['image'] ?? $rowRaw['image'] ?? $rowRaw['product_main_image'] ?? $rowRaw['product_image'] ?? '';

                                    $gItemPrice = $row['item_price'] ?? $row['paid_price'] ?? $rowRaw['item_price'] ?? $rowRaw['paid_price'] ?? null;

                                    $key = mb_strtolower(trim((string)$gSku)) . '|' . mb_strtolower(trim((string)$gName)) . '|' . mb_strtolower(trim((string)$gVariation));
                                    if (!isset($grouped[$key])) {
                                        $grouped[$key] = [
                                            'raw' => $rowRaw,
                                            'name' => $gName,
                                            'sku' => $gSku,
                                            'variation' => $gVariation,
                                            'quantity' => $gQty,
                                            'image' => $gImg,
                                            'item_price' => $gItemPrice,
                                        ];
                                    } else {
                                        $grouped[$key]['quantity'] += $gQty;
                                        if (($grouped[$key]['image'] ?? '') === '' && $gImg !== '') {
                                            $grouped[$key]['image'] = $gImg;
                                        }
                                    }
                                }
                            }
                            $groupedItems = array_values($grouped);

                            $itemCount = !empty($groupedItems) ? count($groupedItems) : (is_array($items) ? count($items) : 0);
                            $totalQty = 0;

                            // Amount & payment method
                            $totalAmount = $raw['price'] ?? $raw['total_amount'] ?? $raw['total_price'] ?? $raw['order_amount'] ?? $raw['amount'] ?? null;
                            $currency = $raw['currency'] ?? '₱';

                            $paymentMethod = $raw['payment_method'] ?? $raw['payment_method_type'] ?? $raw['paymentMethod'] ?? $raw['payment_method_name'] ?? null;
                            if (is_array($paymentMethod)) $paymentMethod = implode(', ', $paymentMethod);
                            $paymentMethod = is_string($paymentMethod) ? trim($paymentMethod) : null;
                            $paymentBadge = $paymentMethod;
                            if (!$paymentBadge) {
                                $isCod = $raw['is_cod'] ?? $raw['cod'] ?? $raw['cash_on_delivery'] ?? null;
                                if ($isCod === true || (string)$isCod === '1') {
                                    $paymentBadge = 'COD';
                                }
                            }

                            // Shipping / courier / tracking
                            // Check order-level raw, then _detail, then first product's raw
                            $detail = (isset($raw['_detail']) && is_array($raw['_detail'])) ? $raw['_detail'] : [];
                            $firstItemRaw = [];
                            if ($o->products && $o->products->isNotEmpty()) {
                                $fir = $o->products->first()->raw ?? null;
                                if (is_array($fir)) $firstItemRaw = $fir;
                            }

                            // Helper: first non-empty string from multiple keys across sources
                            $pick = function (array $keys) use ($firstItemRaw, $detail, $raw) {
                                foreach ([$firstItemRaw, $detail, $raw] as $src) {
                                    foreach ($keys as $k) {
                                        $v = $src[$k] ?? null;
                                        if (is_array($v)) $v = implode(', ', $v);
                                        if (is_string($v) && trim($v) !== '') return trim($v);
                                    }
                                }
                                return null;
                            };

                            $courier = $pick(['shipment_provider', 'shipping_provider', 'shipping_provider_name']);
                            $deliveryMethod = $pick(['shipping_type', 'delivery_type']);
                            $trackingNo = $pick(['tracking_code', 'tracking_number', 'tracking_code_pre']);

                            // SLA timestamp — only for awaiting-ship statuses.
                            // Lazada exposes either sla_time_stamp / promised_shipping_time (often empty)
                            // or fulfillment_sla (string like "TTS before 2026-05-06 23:59:17 ...").
                            $awaitingShip = in_array($statusNorm, ['pending', 'confirmed', 'ready_to_ship', 'packed'], true);
                            $shipBy = 0;
                            if ($awaitingShip) {
                                $tsRaw = $pick(['sla_time_stamp', 'promised_shipping_time']);
                                if (is_numeric($tsRaw) && (int) $tsRaw > 0) {
                                    $shipBy = (int) $tsRaw;
                                    if ($shipBy > 1000000000000) $shipBy = (int) ($shipBy / 1000); // ms → s
                                } else {
                                    $slaText = $pick(['fulfillment_sla']);
                                    if ($slaText && preg_match('/(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $slaText, $m)) {
                                        $parsed = strtotime(str_replace('T', ' ', $m[1]));
                                        if ($parsed) $shipBy = $parsed;
                                    }
                                }
                            }
                        @endphp

                        @if($orderId)
                            <tr class="order-header-row">
                                <td colspan="7" style="padding:10px 12px 6px 12px;">
                                    <div class="d-flex justify-between items-start gap-12">
                                    <div class="text-xs text-secondary" style="line-height:1.35;">
                                        <span>Buyer:</span> <span class="font-semibold" style="color:var(--text-primary);">{{ $buyer !== '' ? $buyer : '—' }}</span>
                                        <span style="margin-left:8px;">Order Number:</span> <span class="font-semibold" style="color:var(--text-primary);">{{ $orderId }}</span>
                                        @if($itemCount > 0)
                                            <span style="margin-left:8px;">({{ $itemCount }} {{ $itemCount === 1 ? 'item' : 'items' }})</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-secondary text-right" style="line-height:1.35;">
                                        @if($created)
                                            <span style="margin-left:8px;">Create Time:</span> <span class="font-semibold" style="color:var(--text-primary);">{{ $created }}</span>
                                        @endif
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif


                        @php
                            $displayItems = !empty($groupedItems) ? $groupedItems : $items;
                            if (!is_array($displayItems)) { $displayItems = []; }
                            if (empty($displayItems)) { $displayItems = [ [] ]; }
                            $lineCount = count($displayItems);
                        @endphp

                        @foreach($displayItems as $idx => $it)
                        <tr>
                            <td style="vertical-align:top;">
                                @php
                                    $itRaw = $it['raw'] ?? $it;
                                    $name = (string)($it['name'] ?? $itRaw['name'] ?? $itRaw['item_name'] ?? $itRaw['product_name'] ?? '');
                                    $sku = (string)($it['sku'] ?? $itRaw['sku'] ?? $itRaw['seller_sku'] ?? $itRaw['SellerSku'] ?? $itRaw['SellerSKU'] ?? $itRaw['Sku'] ?? '');
                                    $variation = (string)($it['variation'] ?? $itRaw['variation'] ?? $itRaw['Variation'] ?? $itRaw['variant'] ?? '');
                                    $variationNorm = mb_strtolower(trim($variation));
                                    if (in_array($variationNorm, ['', 'blank', 'null', 'n/a', 'na', 'none', '-', '--'], true)) {
                                        $variation = '';
                                    }
                                    $img = (string)($it['image'] ?? $itRaw['image'] ?? $itRaw['product_main_image'] ?? $itRaw['product_image'] ?? '');
                                @endphp

                                <div class="d-flex gap-10">
                                    <div class="order-img-wrap">
                                        @if($img)
                                            <img class="order-img" src="{{ $img }}" alt="" >
                                        @else
                                            <span class="text-xs text-muted">No image</span>
                                        @endif
                                    </div>
                                    <div style="min-width:0;">
                                        <div class="font-semibold" style="word-break:break-word;">
                                            @if($orderId)
                                                <a href="{{ route('ext.lazada.orders.show', ['orderId' => $orderId]) }}" target="_blank" rel="noopener" style="color:var(--text-primary); text-decoration:underline; text-underline-offset:2px;">
                                                    {{ $name !== '' ? $name : '—' }}
                                                </a>
                                            @else
                                                {{ $name !== '' ? $name : '—' }}
                                            @endif
                                        </div>
                                        <div class="text-xs" style="margin-top:3px;">
                                            <span class="text-secondary">Seller SKU:</span> <strong>{{ $sku !== '' ? $sku : '—' }}</strong>
                                        </div>
                                        @if($variation !== '')
                                            <div class="order-option-wrap">
                                                <span class="order-option-label lazada-label">Option</span>
                                                <span class="order-option-pill">{{ $variation }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td style="vertical-align:top;">
                                @php
                                    $itRaw = $it['raw'] ?? $it;
                                    $qty = (int)($it['quantity'] ?? $itRaw['quantity'] ?? $itRaw['qty'] ?? 1);
                                    if ($qty < 1) { $qty = 1; }
                                @endphp
                                <div class="font-bold">x{{ $qty }}</div>
                            </td>
                            <td style="vertical-align:top;">
                                @php
                                    $unitPrice = $it['item_price'] ?? $it['paid_price'] ?? null;
                                    $unitCurrency = ($it['raw']['currency'] ?? null) ?: ($raw['currency'] ?? '₱');
                                @endphp
                                <div class="font-bold">
                                    @if($unitPrice !== null && $unitPrice !== '')
                                        {{ is_numeric($unitPrice) ? $unitCurrency . number_format((float)$unitPrice, 2) : $unitPrice }}
                                    @else
                                        —
                                    @endif
                                </div>
                            </td>

                            @if($idx === 0)
                            <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                                <div class="font-bold">
                                    @if($totalAmount !== null && $totalAmount !== '')
                                        {{ is_numeric($totalAmount) ? $currency . number_format((float)$totalAmount, 2) : $totalAmount }}
                                    @else
                                        —
                                    @endif
                                </div>
                                @if($paymentBadge)
                                    <div style="margin-top:6px;">
                                        <span class="badge badge-gray">{{ $paymentBadge }}</span>
                                    </div>
                                @endif
                            </td>
                            <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                                <div class="font-semibold">{{ $courier ?: '—' }}</div>
                                @if($deliveryMethod)
                                    <div class="text-xs text-secondary" style="margin-top:4px;">{{ $deliveryMethod }}</div>
                                @endif
                                @if($trackingNo)
                                    <div class="text-xs text-secondary" style="margin-top:4px;">Tracking: <span style="color:var(--text-primary);">{{ $trackingNo }}</span></div>
                                @endif
                            </td>
                            <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                                <div class="font-bold">{{ $statusStr !== '' ? $statusStr : '—' }}</div>
                                <x-sla-chip :deadline="$shipBy" />
                            </td>
                            <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                                @if(!$orderId)
                                    <span class="text-muted">N/A</span>
                                @elseif($isUnpaid)
                                    <span class="text-muted">—</span>
                                @else
                                    <div class="d-flex gap-8 flex-wrap items-center">
                                        {{-- TO PACK (pending): only Pack & Print --}}
                                        @if($isToPack)
                                            <form method="POST" action="{{ route('ext.lazada.orders.pack_print', ['orderId' => $orderId]) }}" data-confirm="Pack this order now? This will create packages and make AWB available.">
                                                @csrf
                                                <button type="submit" class="btn small">Pack &amp; Print</button>
                                            </form>
                                        @endif

                                        {{-- TO ARRANGE (packed): Print + Arrange Shipment + Recreate Package --}}
                                        @if($isToArrange)
                                            <a href="{{ route('ext.lazada.orders.awb', ['orderId' => $orderId]) }}" onclick="window.open(this.href, 'awb', 'width=900,height=700'); return false;" class="btn small secondary">Print AWB</a>

                                            <form method="POST" action="{{ route('ext.lazada.orders.rts', ['orderId' => $orderId]) }}" data-confirm="Arrange Shipment (Ready To Ship) for this order?">
                                                @csrf
                                                <button type="submit" class="btn small" style="background:#16a34a; border-color:#16a34a; color:#fff;">Arrange Shipment</button>
                                            </form>

                                            <form method="POST" action="{{ route('ext.lazada.orders.recreate_package', ['orderId' => $orderId]) }}" data-confirm="Recreate Package (local)? Lazada may still keep the original package. Continue?">
                                                @csrf
                                                <button type="submit" class="btn small secondary">Recreate Package</button>
                                            </form>
                                        @endif

                                        {{-- TO HANDOVER (ready_to_ship): Print only --}}
                                        @if($isToHandover)
                                            <a href="{{ route('ext.lazada.orders.awb', ['orderId' => $orderId]) }}" onclick="window.open(this.href, 'awb', 'width=900,height=700'); return false;" class="btn small secondary">Print AWB</a>
                                        @endif

                                        {{-- Post-fulfilment: only show AWB if a local copy exists --}}
                                        @if(!$isToPack && !$isToArrange && !$isToHandover && !$isCancelled && ($savedAwbs[$orderId] ?? false))
                                            <a href="{{ route('ext.lazada.orders.awb', ['orderId' => $orderId]) }}" onclick="window.open(this.href, 'awb', 'width=900,height=700'); return false;" class="btn small secondary">Print AWB</a>
                                        @endif
                                    </div>

                                    {{-- Quick-links row --}}
                                    @if(!$isCancelled)
                                        @php $hasAwb = ($savedAwbs[$orderId] ?? false); @endphp
                                        <div class="d-flex gap-10 flex-wrap text-xs" style="margin-top:8px;">
                                            @if(!$isToPack)
                                                <span class="text-secondary">Print:</span>
                                                @if($hasAwb)
                                                    <a href="{{ route('ext.lazada.orders.awb', ['orderId' => $orderId]) }}" target="_blank" style="color:var(--accent); text-decoration:none;">AWB</a>
                                                    <span class="text-muted">|</span>
                                                @endif
                                                <a href="{{ route('ext.lazada.orders.packing_list', ['orderId' => $orderId]) }}" target="_blank" style="color:var(--accent); text-decoration:none;">Packing List</a>
                                                <span class="text-muted">|</span>
                                                <a href="{{ route('ext.lazada.orders.pick_list', ['orderId' => $orderId]) }}" target="_blank" style="color:var(--accent); text-decoration:none;">Pick List</a>
                                                <span class="text-muted">|</span>
                                                <button type="button"
                                                        class="btnLzLogistics"
                                                        data-url="{{ route('ext.lazada.orders.logistics_trace', ['orderId' => $orderId]) }}"
                                                        style="border:0; padding:0; background:transparent; color:var(--accent); cursor:pointer; font-size:12px;">
                                                    Logistics Status
                                                </button>
                                            @endif
                                        </div>
                                    @endif
                                @endif
                            </td>
                            @endif
                        </tr>
                        @endforeach
               @endforeach
                </tbody>
            </table>
            </div>
        @endif

        <div style="padding:12px 0 0;">{{ $orders->onEachSide(1)->links('vendor.pagination.simple') }}</div>
    </div>


{{-- Loading overlay for long-running fetch/reset actions --}}
<div id="lzLoadingOverlay" class="modal-backdrop">
    <div class="modal" style="max-width:420px;">
        <div class="d-flex items-center gap-12">
            <div aria-hidden="true" style="width:18px; height:18px; border:3px solid #cbd5e1; border-top-color:var(--accent); border-radius:50%; animation:lzSpin 0.8s linear infinite;"></div>
            <div>
                <div id="lzLoadingTitle" class="font-bold">Fetching orders…</div>
                <div class="text-xs text-secondary" style="margin-top:2px;">Please keep this tab open. This may take a while depending on Lazada API response.</div>
            </div>
        </div>
    </div>
</div>

{{-- Pack & Print follow-up modal --}}
<div id="lzPackPrintModal" class="modal-backdrop">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header">
            <div>
                <h3>Pack &amp; Print</h3>
                <div class="text-xs text-secondary" style="margin-top:2px;">Choose what to do next. If you close this, the order stays in <strong>To Arrange</strong>.</div>
            </div>
            <button type="button" id="btnClosePackPrint" class="modal-close">&times;</button>
        </div>
        <div class="d-flex gap-10 flex-wrap justify-end mt-16">
            <form method="POST" id="formRecreatePackage" action="#" style="margin:0;">
                @csrf
                <button type="submit" class="btn secondary">Recreate Package</button>
            </form>

            <form method="POST" id="formShipPrint" action="#" style="margin:0;">
                @csrf
                <button type="submit" class="btn" style="background:#16a34a; border-color:#16a34a;">Ship &amp; Print</button>
            </form>

            <a href="#" id="linkPrintOnly" target="_blank" class="btn secondary">Print Only</a>
        </div>
    </div>
</div>

{{-- Logistics status modal --}}
<div id="lzLogisticsModal" class="modal-backdrop">
    <div class="modal" style="max-width:720px;">
        <div class="modal-header">
            <div>
                <h3>Logistics Status</h3>
                <div id="lzLogisticsSub" class="text-xs text-secondary" style="margin-top:2px;"></div>
            </div>
            <button type="button" id="btnCloseLogistics" class="modal-close">&times;</button>
        </div>
        <div id="lzLogisticsBody" class="text-xs mt-16">
            <div class="text-secondary">Loading…</div>
        </div>
        <div class="d-flex gap-10 justify-end mt-16">
            <button type="button" id="btnLogisticsClose2" class="btn secondary">Close</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var awbUrlToOpen = @json(session('lazada_awb_url'));
    if (awbUrlToOpen) {
        try { window.open(String(awbUrlToOpen), '_blank'); } catch (e) {}
    }

    // Show loading overlay on fetch/update
    var fetchForm = document.getElementById('formFetchLazadaOrders');
    var fetchBtn = document.getElementById('btnFetchOrders');
    var updateBtn = document.getElementById('btnUpdateOrders');
    var clickedBtn = null;

    if (fetchBtn) fetchBtn.addEventListener('click', function () { clickedBtn = 'fetch'; });
    if (updateBtn) updateBtn.addEventListener('click', function () { clickedBtn = 'update'; });

    if (fetchForm) {
        fetchForm.addEventListener('submit', function (e) {
            // Require From/To dates
            var dateFrom = fetchForm.querySelector('input[name="date_from"]');
            var dateTo = fetchForm.querySelector('input[name="date_to"]');
            if (!dateFrom.value || !dateTo.value) {
                e.preventDefault();
                showFlashError('Please provide both From and To dates.');
                return;
            }
            var ov = document.getElementById('lzLoadingOverlay');
            var tt = document.getElementById('lzLoadingTitle');
            if (ov) {
                if (tt) tt.textContent = (clickedBtn === 'update') ? 'Updating orders…' : 'Fetching orders…';
                ov.classList.add('active');
            }
        });
    }

    // --- Pack & Print follow-up modal ---
    var openPackPrintOrderId = @json(session('open_pack_print_modal_order_id'));
    var packPrintModal = document.getElementById('lzPackPrintModal');
    var closePackPrint = document.getElementById('btnClosePackPrint');
    var linkPrintOnly = document.getElementById('linkPrintOnly');
    var formShipPrint = document.getElementById('formShipPrint');
    var formRecreate = document.getElementById('formRecreatePackage');

    function showPackPrintModal(orderId) {
        if (!packPrintModal) return;
        var awbTpl = @json(route('ext.lazada.orders.awb', ['orderId' => '__OID__']));
        var shipTpl = @json(route('ext.lazada.orders.ship_print_post', ['orderId' => '__OID__']));
        var recreateTpl = @json(route('ext.lazada.orders.recreate_package', ['orderId' => '__OID__']));

        if (linkPrintOnly) linkPrintOnly.href = awbTpl.replace('__OID__', orderId);
        if (formShipPrint) formShipPrint.action = shipTpl.replace('__OID__', orderId);
        if (formRecreate) formRecreate.action = recreateTpl.replace('__OID__', orderId);

        packPrintModal.classList.add('active');
    }

    function hidePackPrintModal() {
        if (!packPrintModal) return;
        packPrintModal.classList.remove('active');
    }

    if (closePackPrint) closePackPrint.addEventListener('click', hidePackPrintModal);
    if (packPrintModal) {
        packPrintModal.addEventListener('click', function (e) {
            if (e.target === packPrintModal) hidePackPrintModal();
        });
    }
    if (openPackPrintOrderId) {
        showPackPrintModal(String(openPackPrintOrderId));
    }

    // --- Logistics trace modal ---
    var logisticsModal = document.getElementById('lzLogisticsModal');
    var logisticsBody = document.getElementById('lzLogisticsBody');
    var logisticsSub = document.getElementById('lzLogisticsSub');
    var closeLogistics = document.getElementById('btnCloseLogistics');
    var closeLogistics2 = document.getElementById('btnLogisticsClose2');

    function showLogisticsModal() {
        if (!logisticsModal) return;
        logisticsModal.classList.add('active');
    }
    function hideLogisticsModal() {
        if (!logisticsModal) return;
        logisticsModal.classList.remove('active');
    }
    if (closeLogistics) closeLogistics.addEventListener('click', hideLogisticsModal);
    if (closeLogistics2) closeLogistics2.addEventListener('click', hideLogisticsModal);
    if (logisticsModal) {
        logisticsModal.addEventListener('click', function (e) {
            if (e.target === logisticsModal) hideLogisticsModal();
        });
    }

    function esc(s) {
        return String(s || '').replace(/[&<>\"']/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[c] || c;
        });
    }

    function formatEventTime(t) {
        if (!t) return '';
        // Unix milliseconds timestamp
        if (typeof t === 'number' && t > 1000000000000) {
            var d = new Date(t);
            return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0')
                + ' ' + String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0') + ':' + String(d.getSeconds()).padStart(2,'0');
        }
        // Unix seconds
        if (typeof t === 'number' && t > 1000000000) {
            return formatEventTime(t * 1000);
        }
        return String(t);
    }

    function renderTrace(body) {
        var data = (body && body.data) ? body.data : body;
        var traces = [];
        if (data) {
            // Try common keys first
            traces = data.traces || data.trace_list || data.traceList || data.events || [];
            // Lazada packages structure: data.packages[0].package_trace_list
            if ((!Array.isArray(traces) || traces.length === 0) && Array.isArray(data.packages)) {
                for (var pi = 0; pi < data.packages.length; pi++) {
                    var ptl = data.packages[pi].package_trace_list || data.packages[pi].traceList || [];
                    if (Array.isArray(ptl) && ptl.length > 0) {
                        traces = traces.concat(ptl);
                    }
                }
            }
            // Lazada logistic/order/trace structure: result.module[].package_detail_info_list[].logistic_detail_info_list[]
            if ((!Array.isArray(traces) || traces.length === 0) && data.result && Array.isArray(data.result.module)) {
                for (var mi = 0; mi < data.result.module.length; mi++) {
                    var pkgs = data.result.module[mi].package_detail_info_list || [];
                    for (var pi = 0; pi < pkgs.length; pi++) {
                        var events = pkgs[pi].logistic_detail_info_list || [];
                        if (Array.isArray(events)) {
                            traces = traces.concat(events);
                        }
                    }
                }
            }
        }
        if (!Array.isArray(traces)) traces = [];
        if (!logisticsBody) return;

        if (traces.length === 0) {
            logisticsBody.innerHTML = '<div class="text-secondary">No logistics events returned by Lazada for this order.</div>';
            return;
        }

        var html = '<div class="table-wrap">';
        html += '<table class="table">';
        html += '<thead><tr>'
            + '<th style="width:160px;">Time</th>'
            + '<th>Status</th>'
            + '<th>Details</th>'
            + '</tr></thead><tbody>';
        traces.forEach(function (t) {
            var time = t.event_time || t.time || t.timestamp || t.update_time || '';
            var status = t.title || t.status || t.event || t.action || '';
            var detail = t.description || t.desc || t.detail || t.message || '';
            html += '<tr>'
                + '<td>' + esc(formatEventTime(time)) + '</td>'
                + '<td class="font-bold">' + esc(status) + '</td>'
                + '<td>' + esc(detail) + '</td>'
                + '</tr>';
        });
        html += '</tbody></table></div>';
        logisticsBody.innerHTML = html;
    }

    async function fetchLogistics(url) {
        if (!logisticsBody) return;
        logisticsBody.innerHTML = '<div class="text-secondary">Loading…</div>';
        try {
            var r = await fetch(url, { headers: { 'Accept': 'application/json' } });
            var j = await r.json();
            if (!r.ok || !j || j.ok !== true) {
                var msg = (j && j.body && j.body.message) ? j.body.message : ((j && j.message) ? j.message : 'Failed to fetch logistics trace');
                throw new Error(msg);
            }
            renderTrace(j.body);
        } catch (e) {
            logisticsBody.innerHTML = '<div style="color:var(--danger);">' + esc(e && e.message ? e.message : 'Failed to fetch logistics trace') + '</div>';
        }
    }

    document.querySelectorAll('.btnLzLogistics').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var url = btn.getAttribute('data-url') || '';
            if (!url) return;
            if (logisticsSub) logisticsSub.textContent = '';
            showLogisticsModal();
            fetchLogistics(url);
        });
    });
});
</script>
<script>
(function () {
    var preview = document.createElement('div');
    preview.className = 'order-img-preview';
    preview.innerHTML = '<img src="" alt="">';
    document.body.appendChild(preview);
    var previewImg = preview.querySelector('img');
    document.addEventListener('mouseover', function (e) {
        var wrap = e.target.closest('.order-img-wrap');
        if (!wrap) return;
        var img = wrap.querySelector('img.order-img');
        if (!img || !img.src) return;
        previewImg.src = img.src;
        var rect = wrap.getBoundingClientRect();
        var top = rect.top + rect.height / 2 - 110;
        var left = rect.right + 12;
        if (left + 230 > window.innerWidth) left = rect.left - 232;
        if (top + 220 > window.innerHeight) top = window.innerHeight - 224;
        if (top < 4) top = 4;
        preview.style.transformOrigin = (left < rect.left ? 'right' : 'left') + ' center';
        preview.style.top = top + 'px';
        preview.style.left = left + 'px';
        preview.classList.add('visible');
    }, false);

    document.addEventListener('mouseout', function (e) {
        var wrap = e.target.closest('.order-img-wrap');
        if (!wrap) return;
        var related = e.relatedTarget;
        if (related && wrap.contains(related)) return;
        preview.classList.remove('visible');
    }, false);
})();
</script>
</div>{{-- /.marketplace-lazada --}}
@endsection
