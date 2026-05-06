@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Shopee / Orders')

@section('content')
<div class="marketplace-shopee">
@include('integrations.partials._tab_strip', ['activeTabId' => 'shopee'])
<div class="page-header">
    <h2>Shopee Orders <span class="text-secondary text-sm">({{ $orders->total() }})</span></h2>
</div>

<div class="card mb-16">
    <form method="POST" action="{{ route('ext.shopee.orders.fetch') }}" id="formFetchShopeeOrders" class="d-flex gap-12 flex-wrap items-center">
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
            <button type="submit" class="btn secondary" id="btnUpdateOrders" formaction="{{ route('ext.shopee.orders.update_statuses') }}">Update Orders</button>
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
@endphp

<div class="tabs mb-12">
    @foreach($tabs as $key => $label)
        @php
            $isActive = strtoupper((string)$active_tab) === strtoupper((string)$key);
            $count = $tab_counts[$key] ?? null;
            $qs = array_merge(request()->except('page'), ['tab' => $key]);
            $showBadge = in_array($key, ['PENDING', 'SHIPPING', 'FAILED_DELIVERY']);
        @endphp
        <a href="{{ route('ext.shopee.orders.index', $qs) }}" class="tab {{ $isActive ? 'active' : '' }}">
            <span>{{ $label }}</span>
            @if($showBadge && $count !== null && $count > 0)
                <span style="margin-left:6px; display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 6px; border-radius:999px; font-weight:700; font-size:12px; {{ $isActive ? 'background:#fff; color:#EE4D2D;' : 'background:#EE4D2D; color:#fff;' }}">{{ $count }}</span>
            @endif
        </a>
    @endforeach
    <a href="{{ route('ext.shopee.orders.returns') }}" class="tab">
        <span>Return/Refund/Cancel</span>
    </a>
</div>

{{-- Shopee workflow: TO SHIP (PENDING) has 2 sub steps (To Pack / To Handover) --}}
@if(strtoupper((string)($active_tab ?? 'ALL')) === 'PENDING')
    @php
        $ps = $pending_subtab ?? request()->query('pending_sub', 'to_pack');
        $pendingNav = [
            'to_pack' => 'To Pack',
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
                    $qs = array_merge(request()->except('page'), ['tab' => 'PENDING', 'pending_sub' => $k]);
                    $count = $pending_sub_counts[$k] ?? null;
                @endphp
                <a href="{{ route('ext.shopee.orders.index', $qs) }}" class="tab {{ $isActive ? 'active' : '' }}">
                    <span>{{ $lbl }}</span>
                    @if($count !== null && $count > 0)
                        <span style="margin-left:6px; display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 6px; border-radius:999px; font-weight:700; font-size:12px; {{ $isActive ? 'background:#fff; color:#EE4D2D;' : 'background:#EE4D2D; color:#fff;' }}">{{ $count }}</span>
                    @endif
                </a>
            @endforeach
        </div>

        @if(!empty($pending_courier_counts))
            <div style="margin-top:10px;">
                <div class="text-xs text-secondary" style="margin-bottom:6px;">Courier tally (To Handover only)</div>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    @foreach($pending_courier_counts as $courier => $count)
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

<div class="card mb-12" style="padding:12px 16px;">
    <form method="GET" action="{{ route('ext.shopee.orders.index') }}" class="d-flex gap-10 flex-wrap items-center">
        <input type="hidden" name="tab" value="{{ $active_tab ?? request()->query('tab', 'ALL') }}">
        <input type="hidden" name="per_page" value="{{ (int)($per_page ?? request()->query('per_page', 10)) }}">

        <div>
            <label class="text-xs text-secondary">Order Number</label>
            <input type="text" name="order_number" value="{{ old('order_number', $filters['order_number'] ?? '') }}" placeholder="Order SN" class="input" style="min-width:220px;">
        </div>

        <div>
            <label class="text-xs text-secondary">Buyer Name</label>
            <input type="text" name="buyer_name" value="{{ old('buyer_name', $filters['buyer_name'] ?? '') }}" placeholder="Buyer" class="input" style="min-width:220px;">
        </div>

        <div>
            <label class="text-xs text-secondary">Sort by</label>
            <select name="sort" class="input" style="width:auto; min-width:240px;">
                <option value="created_asc" {{ ($sort ?? '') === 'created_asc' ? 'selected' : '' }}>Created Date (Oldest to Newest)</option>
                <option value="created_desc" {{ ($sort ?? '') === 'created_desc' ? 'selected' : '' }}>Created Date (Newest to Oldest)</option>
                <option value="confirmed_pay_asc" {{ ($sort ?? '') === 'confirmed_pay_asc' ? 'selected' : '' }}>Confirmed: Pay Time (Oldest to Newest)</option>
                <option value="confirmed_pay_desc" {{ ($sort ?? '') === 'confirmed_pay_desc' ? 'selected' : '' }}>Confirmed: Pay Time (Newest to Oldest)</option>
                <option value="confirmed_update_asc" {{ ($sort ?? '') === 'confirmed_update_asc' ? 'selected' : '' }}>Confirmed: Update Time (Oldest to Newest)</option>
                <option value="confirmed_update_desc" {{ ($sort ?? '') === 'confirmed_update_desc' ? 'selected' : '' }}>Confirmed: Update Time (Newest to Oldest)</option>
                <option value="confirmed_create_asc" {{ ($sort ?? '') === 'confirmed_create_asc' ? 'selected' : '' }}>Confirmed: Create Time (Oldest to Newest)</option>
                <option value="confirmed_create_desc" {{ ($sort ?? '') === 'confirmed_create_desc' ? 'selected' : '' }}>Confirmed: Create Time (Newest to Oldest)</option>
            </select>
        </div>

        <div class="d-flex gap-8">
            <button type="submit" class="btn">Search</button>
            <a href="{{ route('ext.shopee.orders.index', ['tab' => ($active_tab ?? 'ALL'), 'per_page' => (int)($per_page ?? 10)]) }}" class="btn secondary">Reset</a>
        </div>
    </form>
</div>

<div class="card mb-12" style="padding:12px 16px;">
    <form method="GET" action="{{ route('ext.shopee.orders.index') }}" class="d-flex gap-10 items-center">
        <input type="hidden" name="tab" value="{{ $active_tab ?? request()->query('tab', 'ALL') }}">
        <input type="hidden" name="order_number" value="{{ $filters['order_number'] ?? '' }}">
        <input type="hidden" name="buyer_name" value="{{ $filters['buyer_name'] ?? '' }}">
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
                @if(($filters['order_number'] ?? '') !== '' || ($filters['buyer_name'] ?? '') !== '')
                    No results match your search filters. Try clearing the filters above.
                @elseif(strtoupper($active_tab ?? 'ALL') !== 'ALL')
                    There are no <strong>{{ $tabs[$active_tab] ?? $active_tab }}</strong> orders right now.
                @else
                    No orders have been synced yet. Use <strong>Fetch Orders</strong> above to pull orders from Shopee.
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

                        $orderSn = $o->order_sn ?? ($raw['order_sn'] ?? '');
                        $status = $o->status ?? ($raw['order_status'] ?? '');
                        $statusStr = is_string($status) ? $status : '';
                        $statusNorm = strtoupper(trim($statusStr));

                        $isUnpaid = ($statusNorm === 'UNPAID');
                        $isCancelled = in_array($statusNorm, ['CANCELLED', 'IN_CANCEL'], true);
                        $isToPack = ($statusNorm === 'READY_TO_SHIP');
                        $isToHandover = ($statusNorm === 'PROCESSED');
                        $isShipped = ($statusNorm === 'SHIPPED');
                        $isFailedDelivery = ($statusNorm === 'RETRY_SHIP');

                        $awaitingShip = in_array($statusNorm, ['UNPAID', 'READY_TO_SHIP', 'PROCESSED'], true);
                        $shipBy = $awaitingShip ? (int) ($raw['ship_by_date'] ?? 0) : 0;

                        $created = $o->order_created_at ? $o->order_created_at->format('Y-m-d H:i') : null;
                        $buyer = (string)($raw['buyer_username'] ?? ($raw['buyer_user_name'] ?? ''));

                        $items = [];
                        if (method_exists($o, 'products') && $o->relationLoaded('products')) {
                            $items = $o->products ? $o->products->toArray() : [];
                        }
                        if (empty($items)) {
                            $items = $raw['item_list'] ?? [];
                            if (!is_array($items)) $items = [];
                        }

                        // Group identical products
                        $grouped = [];
                        foreach ($items as $row) {
                            $rowRaw = $row['raw'] ?? $row;
                            $gName = $row['name'] ?? $rowRaw['item_name'] ?? '';
                            $gSku = $row['sku'] ?? $rowRaw['item_sku'] ?? $rowRaw['model_sku'] ?? '';
                            $gVariation = $row['variation'] ?? $rowRaw['model_name'] ?? '';
                            $gQty = max(1, (int)($row['quantity'] ?? $rowRaw['model_quantity_purchased'] ?? $rowRaw['quantity'] ?? 1));
                            $gImg = $row['image'] ?? $rowRaw['image_info']['image_url'] ?? '';

                            $gPrice = $row['price'] ?? $rowRaw['model_discounted_price'] ?? $rowRaw['model_original_price'] ?? null;

                            $key = mb_strtolower(trim((string)$gSku)) . '|' . mb_strtolower(trim((string)$gName)) . '|' . mb_strtolower(trim((string)$gVariation));
                            if (!isset($grouped[$key])) {
                                $grouped[$key] = [
                                    'raw' => $rowRaw,
                                    'name' => $gName,
                                    'sku' => $gSku,
                                    'variation' => $gVariation,
                                    'quantity' => $gQty,
                                    'image' => $gImg,
                                    'price' => $gPrice,
                                ];
                            } else {
                                $grouped[$key]['quantity'] += $gQty;
                            }
                        }
                        $groupedItems = array_values($grouped);
                        $itemCount = count($groupedItems);

                        $totalAmount = $raw['total_amount'] ?? $raw['escrow_amount'] ?? null;
                        $currency = $raw['currency'] ?? '₱';
                        $paymentMethod = $raw['payment_method'] ?? null;
                        $courier = $raw['shipping_carrier'] ?? $raw['checkout_shipping_carrier'] ?? null;
                        if (is_array($courier)) $courier = implode(', ', $courier);
                        $trackingNo = $raw['tracking_no'] ?? $raw['tracking_number'] ?? null;
                    @endphp

                    @if($orderSn)
                        <tr class="order-header-row">
                            <td colspan="7" style="padding:10px 12px 6px 12px;">
                                <div class="d-flex justify-between items-start gap-12">
                                    <div class="text-xs text-secondary" style="line-height:1.35;">
                                        <span>Buyer:</span> <span class="font-semibold" style="color:var(--text-primary);">{{ $buyer !== '' ? $buyer : '—' }}</span>
                                        <span style="margin-left:8px;">Order SN:</span> <span class="font-semibold" style="color:var(--text-primary);">{{ $orderSn }}</span>
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
                                $name = (string)($it['name'] ?? $itRaw['item_name'] ?? '');
                                $sku = (string)($it['sku'] ?? $itRaw['item_sku'] ?? $itRaw['model_sku'] ?? '');
                                $variation = (string)($it['variation'] ?? $itRaw['model_name'] ?? '');
                                $img = (string)($it['image'] ?? $itRaw['image_info']['image_url'] ?? '');
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
                                        @if($orderSn)
                                            <a href="{{ route('ext.shopee.orders.show', ['orderSn' => $orderSn]) }}" target="_blank" rel="noopener" style="color:var(--text-primary); text-decoration:underline; text-underline-offset:2px;">
                                                {{ $name !== '' ? $name : '—' }}
                                            </a>
                                        @else
                                            {{ $name !== '' ? $name : '—' }}
                                        @endif
                                    </div>
                                    <div class="text-xs" style="margin-top:3px;">
                                        <span class="text-secondary">SKU:</span> <strong>{{ $sku !== '' ? $sku : '—' }}</strong>
                                    </div>
                                    @if($variation !== '')
                                        <div class="order-option-wrap">
                                            <span class="order-option-label shopee-label">Option</span>
                                            <span class="order-option-pill">{{ $variation }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td style="vertical-align:top;">
                            @php
                                $qty = max(1, (int)($it['quantity'] ?? 1));
                            @endphp
                            <div class="font-bold">x{{ $qty }}</div>
                        </td>
                        <td style="vertical-align:top;">
                            @php
                                $unitPrice = $it['price'] ?? null;
                            @endphp
                            <div class="font-bold">
                                @if($unitPrice !== null && $unitPrice !== '')
                                    {{ is_numeric($unitPrice) ? $currency . number_format((float)$unitPrice, 2) : $unitPrice }}
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
                            @if($paymentMethod)
                                <div style="margin-top:6px;">
                                    <span class="badge badge-gray">{{ $paymentMethod }}</span>
                                </div>
                            @endif
                        </td>
                        <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                            <div class="font-semibold">{{ $courier ?: '—' }}</div>
                            @if($trackingNo)
                                <div class="text-xs text-secondary" style="margin-top:4px;">Tracking: <span style="color:var(--text-primary);">{{ $trackingNo }}</span></div>
                            @endif
                        </td>
                        <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                            <div class="font-bold" @if($isFailedDelivery) style="color:#e53e3e;" @endif>{{ $statusStr !== '' ? $statusStr : '—' }}</div>
                            <x-sla-chip :deadline="$shipBy" />
                        </td>
                        <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                            @if(!$orderSn)
                                <span class="text-muted">N/A</span>
                            @elseif($isUnpaid)
                                <span class="text-muted">—</span>
                            @else
                                <div class="d-flex gap-8 flex-wrap items-center">
                                    @if($isToPack)
                                        <button type="button" class="btn small btnArrangeShipment" data-order-sn="{{ $orderSn }}">Arrange Shipment</button>
                                    @endif

                                    @if(!$isToPack)
                                        <a href="{{ route('ext.shopee.orders.awb', ['orderSn' => $orderSn]) }}" onclick="window.open(this.href, 'awb', 'width=900,height=700'); return false;" class="btn small">Print AWB</a>
                                    @endif

                                    @if($isToHandover || $isShipped)
                                        <button type="button"
                                                class="btnShopeeTracking btn small secondary"
                                                data-url="{{ route('ext.shopee.orders.tracking_info', ['orderSn' => $orderSn]) }}">
                                            Tracking #
                                        </button>
                                    @endif

                                    <x-invoice-request-modal :invoice="$o->buyer_invoice" :reference="$orderSn" />
                                </div>
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


{{-- Ship method modal --}}
<div id="spShipModal" class="modal-backdrop">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header">
            <h3>Arrange Shipment</h3>
            <button type="button" id="btnCloseShip" class="modal-close">&times;</button>
        </div>
        <div style="margin-top:12px;">
            <div class="text-secondary text-sm">Order: <strong id="spShipOrderSn"></strong></div>
        </div>

        {{-- Step 1: Choose shipping type --}}
        <div id="shipStep1">
            <div class="text-sm" style="margin-top:10px;">How will this order be shipped?</div>
            <div class="d-flex gap-10" style="margin-top:16px;">
                <button type="button" class="btn secondary btnShipChoice" data-type="dropoff" style="flex:1;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    Drop Off
                </button>
                <button type="button" class="btn btnShipChoice" data-type="pickup" style="flex:1;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                    Pickup
                </button>
            </div>
        </div>

        {{-- Step 2: Address/branch selection --}}
        <div id="shipStep2" style="display:none;">
            <div id="shipStep2Loading" class="d-flex items-center gap-10" style="margin-top:12px;">
                <div aria-hidden="true" style="width:16px; height:16px; border:3px solid #cbd5e1; border-top-color:var(--accent); border-radius:50%; animation:spSpin 0.8s linear infinite;"></div>
                <span class="text-secondary text-sm">Loading addresses…</span>
            </div>
            <div id="shipStep2Content" style="display:none; margin-top:12px;"></div>
            <div class="d-flex gap-10" style="margin-top:16px;">
                <button type="button" id="btnShipBack" class="btn secondary" style="flex:0;">← Back</button>
                <button type="button" id="btnShipConfirm" class="btn" style="flex:1;" disabled>Confirm & Ship</button>
            </div>
        </div>

        <form method="POST" id="formShipOrder" style="display:none;">
            @csrf
            <input type="hidden" name="shipping_type" id="shipTypeInput" value="">
            <input type="hidden" name="address_id" id="shipAddressInput" value="">
            <input type="hidden" name="branch_id" id="shipBranchInput" value="">
        </form>
    </div>
</div>

{{-- Loading overlay --}}
<div id="spLoadingOverlay" class="modal-backdrop">
    <div class="modal" style="max-width:420px;">
        <div class="d-flex items-center gap-12">
            <div aria-hidden="true" style="width:18px; height:18px; border:3px solid #cbd5e1; border-top-color:var(--accent); border-radius:50%; animation:spSpin 0.8s linear infinite;"></div>
            <div>
                <div id="spLoadingTitle" class="font-bold">Fetching orders…</div>
                <div class="text-xs text-secondary" style="margin-top:2px;">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

{{-- Tracking modal --}}
<div id="spTrackingModal" class="modal-backdrop">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <h3>Tracking</h3>
            <button type="button" id="btnCloseTracking" class="modal-close">&times;</button>
        </div>
        <div id="spTrackingBody" class="mt-12">
            <div class="text-secondary">Loading…</div>
        </div>
        <div class="d-flex gap-10 justify-end mt-16">
            <button type="button" id="btnTrackingClose2" class="btn secondary">Close</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('spLoadingOverlay');
    var fetchForm = document.getElementById('formFetchShopeeOrders');

    var clickedBtn = null;
    var fetchBtn = document.getElementById('btnFetchOrders');
    var updateBtn = document.getElementById('btnUpdateOrders');

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
            if (overlay) {
                document.getElementById('spLoadingTitle').textContent = (clickedBtn === 'update') ? 'Updating orders…' : 'Fetching orders…';
                overlay.style.display = 'flex';
            }
        });
    }

    var fetchReturnsForm = document.getElementById('formFetchReturns');
    if (fetchReturnsForm && overlay) {
        fetchReturnsForm.addEventListener('submit', function () {
            setTimeout(function () {
                overlay.style.display = 'flex';
                document.getElementById('spLoadingTitle').textContent = 'Fetching returns…';
            }, 50);
        });
    }

    // Ship method modal
    var shipModal = document.getElementById('spShipModal');
    var shipOrderSnEl = document.getElementById('spShipOrderSn');
    var shipForm = document.getElementById('formShipOrder');
    var shipTypeInput = document.getElementById('shipTypeInput');
    var shipAddressInput = document.getElementById('shipAddressInput');
    var shipBranchInput = document.getElementById('shipBranchInput');
    var shipStep1 = document.getElementById('shipStep1');
    var shipStep2 = document.getElementById('shipStep2');
    var shipStep2Loading = document.getElementById('shipStep2Loading');
    var shipStep2Content = document.getElementById('shipStep2Content');
    var btnShipConfirm = document.getElementById('btnShipConfirm');
    var btnShipBack = document.getElementById('btnShipBack');
    var currentShipOrderSn = '';
    var currentShipType = '';

    function shipResetModal() {
        shipStep1.style.display = '';
        shipStep2.style.display = 'none';
        shipStep2Loading.style.display = 'flex';
        shipStep2Content.style.display = 'none';
        shipStep2Content.innerHTML = '';
        shipAddressInput.value = '';
        shipBranchInput.value = '';
        btnShipConfirm.disabled = true;
        currentShipType = '';
    }

    document.querySelectorAll('.btnArrangeShipment').forEach(function (el) {
        el.addEventListener('click', function () {
            currentShipOrderSn = el.getAttribute('data-order-sn');
            shipOrderSnEl.textContent = currentShipOrderSn;
            shipForm.action = '/shopee/orders/' + encodeURIComponent(currentShipOrderSn) + '/ship';
            shipResetModal();
            shipModal.style.display = 'flex';
        });
    });

    document.querySelectorAll('.btnShipChoice').forEach(function (el) {
        el.addEventListener('click', function () {
            currentShipType = el.getAttribute('data-type');
            shipTypeInput.value = currentShipType;
            shipStep1.style.display = 'none';
            shipStep2.style.display = '';
            shipStep2Loading.style.display = 'flex';
            shipStep2Content.style.display = 'none';

            fetch('/shopee/orders/' + encodeURIComponent(currentShipOrderSn) + '/shipping-addresses', {
                headers: { 'Accept': 'application/json' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                shipStep2Loading.style.display = 'none';
                shipStep2Content.style.display = '';

                if (!data.ok) {
                    shipStep2Content.innerHTML = '<div class="alert danger">' + (data.message || 'Failed to load addresses.') + '</div>';
                    return;
                }

                var html = '';
                if (currentShipType === 'pickup') {
                    var addresses = (data.pickup && data.pickup.address_list) ? data.pickup.address_list : [];
                    if (addresses.length === 0) {
                        shipStep2Content.innerHTML = '<div class="text-secondary">No pickup addresses available.</div>';
                        return;
                    }

                    // Auto-select: find the address flagged as pickup_address
                    var autoAddr = null;
                    addresses.forEach(function (addr) {
                        if (!autoAddr && (addr.address_flag || []).indexOf('pickup_address') !== -1) {
                            autoAddr = addr;
                        }
                    });
                    // Fallback to first address if none flagged
                    if (!autoAddr) autoAddr = addresses[0];

                    shipAddressInput.value = autoAddr.address_id || 0;
                    var addrParts = [autoAddr.address, autoAddr.city, autoAddr.state, autoAddr.district].filter(function(p) { return p && p !== ''; });
                    var flags = (autoAddr.address_flag || []);

                    html += '<div class="text-sm font-bold" style="margin-bottom:8px;">Pickup address (auto-selected):</div>';
                    html += '<div style="padding:10px 12px; border:2px solid #16a34a; border-radius:8px; background:#16a34a0a;">';
                    html += '<div class="text-sm">' + addrParts.join(', ') + '</div>';
                    if (flags.length > 0) {
                        html += '<div style="margin-top:4px;">';
                        flags.forEach(function (f) {
                            var color = f === 'pickup_address' ? '#16a34a' : (f === 'return_address' ? '#2563eb' : '#6b7280');
                            html += '<span style="display:inline-block; font-size:11px; padding:1px 6px; border-radius:4px; background:' + color + '18; color:' + color + '; margin-right:4px;">' + f.replace(/_/g, ' ') + '</span>';
                        });
                        html += '</div>';
                    }
                    html += '</div>';
                } else {
                    var branches = (data.dropoff && data.dropoff.branch_list) ? data.dropoff.branch_list : [];
                    if (branches.length === 0) {
                        shipStep2Content.innerHTML = '<div class="text-secondary">No drop-off branches available. The order will be shipped without branch selection.</div>';
                        btnShipConfirm.disabled = false;
                        return;
                    }
                    html += '<div class="text-sm font-bold" style="margin-bottom:8px;">Select drop-off branch:</div>';
                    branches.forEach(function (branch, i) {
                        var checked = i === 0 ? ' checked' : '';
                        var addrParts = [branch.address, branch.city, branch.state, branch.district].filter(function(p) { return p && p !== ''; });

                        html += '<label style="display:block; padding:10px 12px; border:2px solid var(--border); border-radius:8px; cursor:pointer; margin-bottom:8px;" class="ship-addr-label">';
                        html += '<div class="d-flex items-start gap-10">';
                        html += '<input type="radio" name="ship_branch" value="' + (branch.branch_id || 0) + '"' + checked + ' style="margin-top:3px;">';
                        html += '<div style="flex:1;">';
                        html += '<div class="text-sm">' + addrParts.join(', ') + '</div>';
                        html += '</div></div></label>';

                        if (i === 0) shipBranchInput.value = branch.branch_id || 0;
                    });
                }

                shipStep2Content.innerHTML = html;
                btnShipConfirm.disabled = false;

                // Listen for radio changes
                shipStep2Content.querySelectorAll('input[type=radio]').forEach(function (radio) {
                    radio.addEventListener('change', function () {
                        if (currentShipType === 'pickup') {
                            shipAddressInput.value = radio.value;
                        } else {
                            shipBranchInput.value = radio.value;
                        }
                    });
                });
            })
            .catch(function () {
                shipStep2Loading.style.display = 'none';
                shipStep2Content.style.display = '';
                shipStep2Content.innerHTML = '<div class="alert danger">Network error loading addresses.</div>';
            });
        });
    });

    btnShipBack.addEventListener('click', function () {
        shipStep1.style.display = '';
        shipStep2.style.display = 'none';
    });

    btnShipConfirm.addEventListener('click', function () {
        btnShipConfirm.disabled = true;
        shipModal.style.display = 'none';

        if (overlay) {
            overlay.style.display = 'flex';
            document.getElementById('spLoadingTitle').textContent = 'Shipping order…';
        }

        var csrfToken = document.querySelector('meta[name="csrf-token"]');
        if (!csrfToken) csrfToken = shipForm.querySelector('input[name="_token"]');
        var token = csrfToken ? (csrfToken.content || csrfToken.value) : '';

        var formData = new FormData(shipForm);

        fetch(shipForm.action, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
            body: formData
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok) {
                if (overlay) overlay.style.display = 'none';
                showFlashError('Ship failed: ' + (data.message || 'Unknown error'));
                btnShipConfirm.disabled = false;
                return;
            }

            // Ship succeeded — now poll for AWB
            if (overlay) {
                document.getElementById('spLoadingTitle').textContent = 'Generating AWB…';
            }

            var awbUrl = '/shopee/orders/' + encodeURIComponent(currentShipOrderSn) + '/awb';
            var awbAttempts = 0;
            var maxAttempts = 20;

            function pollAwb() {
                awbAttempts++;
                fetch(awbUrl, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (awbData) {
                    if (awbData.ok && awbData.ready) {
                        // AWB ready — open in new tab and reload page
                        if (overlay) overlay.style.display = 'none';
                        window.open(awbUrl, '_blank');
                        location.reload();
                    } else if (awbAttempts < maxAttempts) {
                        setTimeout(pollAwb, 3000);
                    } else {
                        // Timed out — let user know and reload
                        if (overlay) overlay.style.display = 'none';
                        showFlashError('Order shipped successfully but AWB is still being generated by Shopee. Use the Print AWB button to try again.');
                        location.reload();
                    }
                })
                .catch(function () {
                    if (awbAttempts < maxAttempts) {
                        setTimeout(pollAwb, 3000);
                    } else {
                        if (overlay) overlay.style.display = 'none';
                        showFlashError('Order shipped successfully but could not generate AWB. Use the Print AWB button to try again.');
                        location.reload();
                    }
                });
            }

            // Start polling after a short initial delay
            setTimeout(pollAwb, 2000);
        })
        .catch(function () {
            if (overlay) overlay.style.display = 'none';
            showFlashError('Network error while shipping order.');
            btnShipConfirm.disabled = false;
        });
    });

    var closeShip = function () { if (shipModal) shipModal.style.display = 'none'; };
    var btnCloseShip = document.getElementById('btnCloseShip');
    if (btnCloseShip) btnCloseShip.addEventListener('click', closeShip);
    if (shipModal) shipModal.addEventListener('click', function (e) { if (e.target === shipModal) closeShip(); });

    // Tracking modal
    var trackingModal = document.getElementById('spTrackingModal');
    var trackingBody = document.getElementById('spTrackingBody');

    var closeTracking = function () { trackingModal.classList.remove('active'); };
    var btnCloseTracking = document.getElementById('btnCloseTracking');
    var btnTrackingClose2 = document.getElementById('btnTrackingClose2');
    if (btnCloseTracking) btnCloseTracking.addEventListener('click', closeTracking);
    if (btnTrackingClose2) btnTrackingClose2.addEventListener('click', closeTracking);
    if (trackingModal) trackingModal.addEventListener('click', function (e) { if (e.target === trackingModal) closeTracking(); });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btnShopeeTracking');
        if (!btn) return;
        var url = btn.getAttribute('data-url');
        if (!url) return;
        trackingBody.textContent = 'Loading…';
        trackingModal.classList.add('active');

        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                trackingBody.textContent = '';
                if (!data.ok) {
                    var err = document.createElement('div');
                    err.className = 'text-danger';
                    err.textContent = data.message || 'Error loading tracking info.';
                    trackingBody.appendChild(err);
                    return;
                }

                if (data.tracking_number) {
                    var tnDiv = document.createElement('div');
                    tnDiv.className = 'mb-8';
                    var tnLabel = document.createElement('strong');
                    tnLabel.textContent = 'Tracking: ';
                    var tnValue = document.createElement('span');
                    tnValue.textContent = data.tracking_number;
                    tnDiv.appendChild(tnLabel);
                    tnDiv.appendChild(tnValue);
                    trackingBody.appendChild(tnDiv);
                }
                if (data.shipping_carrier) {
                    var scDiv = document.createElement('div');
                    scDiv.className = 'mb-8';
                    var scLabel = document.createElement('strong');
                    scLabel.textContent = 'Courier: ';
                    var scValue = document.createElement('span');
                    scValue.textContent = data.shipping_carrier;
                    scDiv.appendChild(scLabel);
                    scDiv.appendChild(scValue);
                    trackingBody.appendChild(scDiv);
                }

                var events = data.tracking_info || [];
                if (!Array.isArray(events) || events.length === 0) {
                    if (!data.tracking_number) {
                        var empty = document.createElement('div');
                        empty.className = 'text-secondary';
                        empty.textContent = 'No tracking info available yet.';
                        trackingBody.appendChild(empty);
                    }
                    return;
                }

                events.forEach(function (ev) {
                    var row = document.createElement('div');
                    row.style.cssText = 'margin-bottom:10px; padding-left:12px; border-left:2px solid var(--accent);';
                    var time = document.createElement('div');
                    time.className = 'text-xs text-secondary';
                    var ts = ev.update_time || null;
                    if (ts && typeof ts === 'number') {
                        var dt = new Date(ts > 1e12 ? ts : ts * 1000);
                        time.textContent = dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0') + '-' + String(dt.getDate()).padStart(2,'0') + ' ' + String(dt.getHours()).padStart(2,'0') + ':' + String(dt.getMinutes()).padStart(2,'0');
                    }
                    var desc = document.createElement('div');
                    desc.className = 'text-sm';
                    desc.textContent = ev.description || '';
                    row.appendChild(time);
                    row.appendChild(desc);
                    trackingBody.appendChild(row);
                });
            })
            .catch(function (err) {
                trackingBody.textContent = '';
                var errEl = document.createElement('div');
                errEl.className = 'text-danger';
                errEl.textContent = 'Failed to load tracking info.';
                trackingBody.appendChild(errEl);
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
</div>{{-- /.marketplace-shopee --}}
@endsection
