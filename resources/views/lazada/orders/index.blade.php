@extends('layouts.app')

@section('content')
<div style="padding:16px;">
    <h2 style="margin:0 0 12px 0;">Lazada Orders <span style="color:#64748b; font-size:14px;">({{ $orders->total() }})</span></h2>

    @if($last_result)
        <div style="padding:10px 12px; border-radius:8px; margin-bottom:12px; background: {{ $last_result['ok'] ? '#ecfdf5' : '#fef2f2' }}; border:1px solid {{ $last_result['ok'] ? '#a7f3d0' : '#fecaca' }};">
            <strong>{{ $last_result['ok'] ? 'OK' : 'Error' }}</strong>
            @if(!empty($last_result['message']))
                <div style="margin-top:6px;">{{ $last_result['message'] }}</div>
            @endif
        </div>
@endif

<form method="POST" action="{{ route('lazada.orders.fetch') }}" id="formFetchLazadaOrders" style="display:flex; gap:12px; flex-wrap:wrap; align-items:end; background:#fff; padding:12px; border-radius:10px; border:1px solid #e5e7eb; margin-bottom:14px;">
        @csrf
        <div>
            <label style="display:block; font-size:12px; color:#64748b; margin-bottom:4px;">From</label>
            <input type="date" name="date_from" value="{{ old('date_from', request('date_from')) }}" style="padding:8px; border:1px solid #cbd5e1; border-radius:8px;">
        </div>
        <div>
            <label style="display:block; font-size:12px; color:#64748b; margin-bottom:4px;">To</label>
            <input type="date" name="date_to" value="{{ old('date_to', request('date_to')) }}" style="padding:8px; border:1px solid #cbd5e1; border-radius:8px;">
        </div>
        <div>
            <label style="display:block; font-size:12px; color:#64748b; margin-bottom:4px;">Status (optional)</label>
            <input type="text" name="status" value="{{ old('status', request('status')) }}" placeholder="e.g. ready_to_ship" style="padding:8px; border:1px solid #cbd5e1; border-radius:8px; min-width:220px;">
            @error('status')<div style="color:#dc2626; font-size:12px;">{{ $message }}</div>@enderror
        </div>
        <div style="display:flex; gap:8px;">
            <button type="submit" class="btn" id="btnFetchOrders" style="padding:10px 14px; border-radius:8px; border:0; background:#2563eb; color:#fff; cursor:pointer; display:inline-flex; align-items:center; gap:8px;">
                <span>Fetch Orders</span>
                <span id="fetchInlineSpinner" aria-hidden="true" style="display:none; width:14px; height:14px; border:2px solid rgba(255,255,255,.55); border-top-color:#fff; border-radius:50%; animation:lzSpin 0.8s linear infinite;"></span>
            </button>

            <button type="submit" class="btn" id="btnUpdateOrders" formaction="{{ route('lazada.orders.update_statuses') }}" style="padding:10px 14px; border-radius:8px; border:1px solid #e5e7eb; background:#fff; color:#0f172a; cursor:pointer; display:inline-flex; align-items:center; gap:8px;">
                <span>Update Orders</span>
                <span id="updateInlineSpinner" aria-hidden="true" style="display:none; width:14px; height:14px; border:2px solid rgba(15,23,42,.25); border-top-color:#0f172a; border-radius:50%; animation:lzSpin 0.8s linear infinite;"></span>
            </button>
        </div>
<div>
            <button type="button" class="btn" id="btnResetLazadaOrders" style="padding:10px 14px; border-radius:8px; border:1px solid #ef4444; background:#fff; color:#b91c1c; cursor:pointer;">Reset Orders</button>
        </div>
        <div style="color:#64748b; font-size:12px;">
	        Leave <strong>From/To</strong> empty for <strong>automatic sync</strong> (maximum <strong>14 days</strong> lookback). Use dates only when you need a specific range (max <strong>14 days</strong> per fetch).
        </div>
    </form>

    @php
        $tabs = $tabs ?? [];
        $active_tab = $active_tab ?? 'ALL';
        $tab_counts = $tab_counts ?? [];
        $pending_sub_counts = $pending_sub_counts ?? [];
    @endphp

    <div style="display:flex; gap:8px; flex-wrap:wrap; margin:0 0 12px 0;">
        @foreach($tabs as $key => $label)
            @php
                $isActive = strtoupper((string)$active_tab) === strtoupper((string)$key);
                $count = $tab_counts[$key] ?? null;
                $qs = array_merge(request()->query(), ['tab' => $key]);
            @endphp
            <a href="{{ route('lazada.orders.index', $qs) }}"
               style="display:inline-flex; align-items:center; gap:8px; padding:8px 10px; border-radius:999px; text-decoration:none;
                      border:1px solid {{ $isActive ? '#2563eb' : '#e5e7eb' }};
                      background: {{ $isActive ? '#eff6ff' : '#fff' }};
                      color: {{ $isActive ? '#1d4ed8' : '#0f172a' }};
                      font-size:13px;">
                <span>{{ $label }}</span>
                @if($count !== null)
                    <span style="display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:20px; padding:0 6px; border-radius:999px; font-size:12px;
                                 background: {{ $isActive ? '#1d4ed8' : '#f1f5f9' }};
                                 color: {{ $isActive ? '#fff' : '#334155' }};">
                        {{ $count }}
                    </span>
                @endif
            </a>
        @endforeach
    </div>

    {{-- Lazada workflow: PENDING has sub steps (To Pack / To Arrange / To Handover) --}}
    @if(strtoupper((string)($active_tab ?? 'ALL')) === 'PENDING')
        @php
            $ps = $pending_subtab ?? request()->query('pending_sub', 'to_pack');
            $pendingNav = [
                'to_pack' => 'To Pack',
                'to_arrange' => 'To Arrange',
                'to_handover' => 'To Handover',
            ];
        @endphp
        <div style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:12px; padding:10px 12px; margin:0 0 12px 0;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px;">
                <div style="font-weight:600; color:#0f172a;">Pending workflow</div>
                <div style="font-size:12px; color:#64748b;">Choose the step you are working on</div>
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                @foreach($pendingNav as $k => $lbl)
                    @php
                        $isActive = ($ps === $k) || ($ps === '' && $k === 'to_pack');
                        $qs = array_merge(request()->query(), ['tab' => 'PENDING', 'pending_sub' => $k]);
                        $count = $pending_sub_counts[$k] ?? null;
                    @endphp
                    <a href="{{ route('lazada.orders.index', $qs) }}"
                       style="display:inline-flex; align-items:center; gap:10px; padding:10px 14px; border-radius:12px; text-decoration:none;
                              border:1px solid {{ $isActive ? '#2563eb' : '#e5e7eb' }};
                              background: {{ $isActive ? '#eff6ff' : '#fff' }};
                              color: {{ $isActive ? '#1d4ed8' : '#0f172a' }};
                              font-size:13px; font-weight:600;">                        <span>{{ $lbl }}</span>
                        @if($count !== null)
                            <span style="display:inline-flex; align-items:center; justify-content:center; min-width:26px; height:22px; padding:0 8px; border-radius:999px; font-size:12px;
                                         background: {{ $isActive ? '#1d4ed8' : '#f1f5f9' }};
                                         color: {{ $isActive ? '#fff' : '#334155' }};">
                                {{ $count }}
                            </span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <form method="GET" action="{{ route('lazada.orders.index') }}" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end; margin:0 0 12px 0;">
        <input type="hidden" name="tab" value="{{ $active_tab ?? request()->query('tab', 'ALL') }}">
        <input type="hidden" name="per_page" value="{{ (int)($per_page ?? request()->query('per_page', 10)) }}">

        <div>
            <label style="display:block; font-size:12px; color:#64748b; margin-bottom:4px;">Order Number</label>
            <input type="text" name="order_number" value="{{ old('order_number', $filters['order_number'] ?? request()->query('order_number', '')) }}" placeholder="Order ID" style="padding:8px; border:1px solid #cbd5e1; border-radius:8px; min-width:220px;">
        </div>

        <div>
            <label style="display:block; font-size:12px; color:#64748b; margin-bottom:4px;">Buyer Name</label>
            <input type="text" name="buyer_name" value="{{ old('buyer_name', $filters['buyer_name'] ?? request()->query('buyer_name', '')) }}" placeholder="Customer" style="padding:8px; border:1px solid #cbd5e1; border-radius:8px; min-width:220px;">
        </div>

        <div style="display:flex; gap:8px;">
            <button type="submit" class="btn" style="padding:10px 14px; border-radius:8px; border:0; background:#0f172a; color:#fff; cursor:pointer;">Search</button>
            <a href="{{ route('lazada.orders.index', ['tab' => ($active_tab ?? 'ALL'), 'per_page' => (int)($per_page ?? 10)]) }}" class="btn" style="display:inline-block; padding:10px 14px; border-radius:8px; border:1px solid #e5e7eb; background:#fff; color:#0f172a; text-decoration:none;">Reset</a>
        </div>
    </form>



    <form method="GET" action="{{ route('lazada.orders.index') }}" style="display:flex; gap:10px; align-items:end; margin:0 0 12px 0;">
    <input type="hidden" name="tab" value="{{ $active_tab ?? request()->query('tab', 'ALL') }}">
    <input type="hidden" name="order_number" value="{{ $filters['order_number'] ?? request()->query('order_number', '') }}">
    <input type="hidden" name="buyer_name" value="{{ $filters['buyer_name'] ?? request()->query('buyer_name', '') }}">
    <div>
        <label style="display:block; font-size:12px; color:#64748b; margin-bottom:4px;">Orders per page</label>
        <select name="per_page" onchange="this.form.submit()" style="padding:8px; border:1px solid #cbd5e1; border-radius:8px;">
            <option value="10" {{ (int)($per_page ?? 10)===10 ? 'selected' : '' }}>10</option>
            <option value="20" {{ (int)($per_page ?? 10)===20 ? 'selected' : '' }}>20</option>
            <option value="50" {{ (int)($per_page ?? 10)===50 ? 'selected' : '' }}>50</option>
        </select>
    </div>
    <noscript><button type="submit" class="btn" style="padding:10px 14px; border-radius:8px; border:0; background:#0f172a; color:#fff; cursor:pointer;">Apply</button></noscript>
</form>

<div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <div style="padding:10px 12px; border-bottom:1px solid #e5e7eb; font-weight:700;">
            Orders ({{ method_exists($orders,'total') ? $orders->total() : $orders->count() }})
        </div>

        @if($orders->count() === 0)
            <div style="padding:12px; color:#64748b;">No orders loaded yet.</div>
        @else
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc; text-align:left;">
                        <th style="padding:10px 12px; border-bottom:1px solid #e5e7eb; width:42px;">
                            <input type="checkbox" aria-label="Select all" disabled>
                        </th>
                        <th style="padding:10px 12px; border-bottom:1px solid #e5e7eb; min-width:360px;">Products</th>
                        <th style="padding:10px 12px; border-bottom:1px solid #e5e7eb; width:110px;">Quantity</th>
                        <th style="padding:10px 12px; border-bottom:1px solid #e5e7eb; width:140px;">Product Price</th>
                        <th style="padding:10px 12px; border-bottom:1px solid #e5e7eb;">Amount</th>
                        <th style="padding:10px 12px; border-bottom:1px solid #e5e7eb;">Shipping</th>
                        <th style="padding:10px 12px; border-bottom:1px solid #e5e7eb;">Status</th>
                        <th style="padding:10px 12px; border-bottom:1px solid #e5e7eb; width:360px;">Actions</th>
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
                            $isToPack = ($statusNorm === 'pending');
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
                                    $gVariation = $row['variation'] ?? $rowRaw['variation'] ?? $rowRaw['Variation'] ?? $rowRaw['variant'] ?? '';

                                    $gQty = (int)($row['quantity'] ?? $rowRaw['quantity'] ?? $rowRaw['qty'] ?? 1);
                                    if ($gQty < 1) { $gQty = 1; }

                                    $gImg = $row['image'] ?? $rowRaw['image'] ?? $rowRaw['product_main_image'] ?? $rowRaw['product_image'] ?? '';

                                    $key = mb_strtolower(trim((string)$gSku)) . '|' . mb_strtolower(trim((string)$gName)) . '|' . mb_strtolower(trim((string)$gVariation));
                                    if (!isset($grouped[$key])) {
                                        $grouped[$key] = [
                                            'raw' => $rowRaw,
                                            'name' => $gName,
                                            'sku' => $gSku,
                                            'variation' => $gVariation,
                                            'quantity' => $gQty,
                                            'image' => $gImg,
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
                            $courier = $raw['shipping_provider'] ?? $raw['shipping_provider_type'] ?? $raw['shipping_provider_name'] ?? $raw['shipment_provider'] ?? $raw['shippingCompany'] ?? null;
                            if (is_array($courier)) $courier = implode(', ', $courier);
                            $courier = is_string($courier) ? trim($courier) : null;

                            $deliveryMethod = $raw['delivery_type'] ?? $raw['deliveryType'] ?? $raw['shipping_type'] ?? null;
                            if (is_array($deliveryMethod)) $deliveryMethod = implode(', ', $deliveryMethod);
                            $deliveryMethod = is_string($deliveryMethod) ? trim($deliveryMethod) : null;

                            $trackingNo = $raw['tracking_code'] ?? $raw['tracking_number'] ?? $raw['trackingNo'] ?? $raw['tracking'] ?? null;
                            if (is_array($trackingNo)) $trackingNo = implode(', ', $trackingNo);
                            $trackingNo = is_string($trackingNo) ? trim($trackingNo) : null;

                            // SLA / urgency: show only if present in API (we'll standardize later)
                            $sla = $raw['sla_time'] ?? $raw['shipping_sla'] ?? $raw['sla'] ?? $raw['ship_by_date'] ?? null;
                            if (is_array($sla)) $sla = implode(', ', $sla);
                            $sla = is_string($sla) ? trim($sla) : null;
                        @endphp

                        @if($orderId)
                            <tr>
                                <td colspan="8" style="padding:10px 12px 6px 12px;">
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
                                        <div style="font-size:12px; color:#64748b; text-align:left; line-height:1.35;">
                                            <span>Buyer:</span> <span style="color:#334155; font-weight:600;">{{ $buyer !== '' ? $buyer : '—' }}</span>
                                            @if($itemCount > 0)
                                                <span style="margin-left:8px;">({{ $itemCount }} {{ $itemCount === 1 ? 'item' : 'items' }})</span>
                                            @endif
                                        </div>
                                        <div style="font-size:12px; color:#64748b; text-align:right; line-height:1.35;">
                                            <span>Order Number:</span>
                                            <a href="{{ route('lazada.orders.show', ['orderId' => $orderId]) }}" target="_blank" rel="noopener" style="color:#334155; font-weight:600; text-decoration:underline; text-underline-offset:2px;">
                                                {{ $orderId }}
                                            </a>
                                            @if($created)
                                                <span style="margin-left:8px;">Create Time:</span> <span style="color:#334155; font-weight:600;">{{ $created }}</span>
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
                            @if($idx === 0)
                            <td rowspan="{{ $lineCount }}" style="padding:10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top;">
                                @if(!$orderId || !$canSelect)
                                    <span style="color:#94a3b8;">—</span>
                                @else
                                    <input type="checkbox" name="select_order[]" value="{{ $orderId }}">
                                @endif
                            </td>
                            @endif

                            <td style="padding:10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top;">
                                @php
                                    $itRaw = $it['raw'] ?? $it;
                                    $name = (string)($it['name'] ?? $itRaw['name'] ?? $itRaw['item_name'] ?? $itRaw['product_name'] ?? '');
                                    $sku = (string)($it['sku'] ?? $itRaw['sku'] ?? $itRaw['seller_sku'] ?? $itRaw['SellerSku'] ?? $itRaw['SellerSKU'] ?? $itRaw['Sku'] ?? '');
                                    $variation = (string)($it['variation'] ?? $itRaw['variation'] ?? $itRaw['Variation'] ?? $itRaw['variant'] ?? '');
                                    $img = (string)($it['image'] ?? $itRaw['image'] ?? $itRaw['product_main_image'] ?? $itRaw['product_image'] ?? '');
                                @endphp

                                <div style="display:flex; gap:10px;">
                                    <div style="width:44px; height:44px; border-radius:8px; overflow:hidden; background:#f1f5f9; display:flex; align-items:center; justify-content:center;">
                                        @if($img)
                                            <img src="{{ $img }}" alt="" style="width:44px; height:44px; object-fit:cover;">
                                        @else
                                            <span style="font-size:11px; color:#94a3b8;">No image</span>
                                        @endif
                                    </div>
                                    <div style="min-width:0;">
                                        <div style="font-weight:600; color:#0f172a; white-space:normal; overflow:visible; text-overflow:clip; word-break:break-word;">{{ $name !== '' ? $name : '—' }}</div>
                                        <div style="margin-top:3px; font-size:12px; color:#334155;">
                                            <span style="color:#64748b;">Seller SKU:</span> <strong>{{ $sku !== '' ? $sku : '—' }}</strong>
                                        </div>
                                        @if($variation !== '')
                                            <div style="margin-top:3px; font-size:12px; color:#64748b;">{{ $variation }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td style="padding:10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top;">
                                @php
                                    $itRaw = $it['raw'] ?? $it;
                                    $qty = (int)($it['quantity'] ?? $itRaw['quantity'] ?? $itRaw['qty'] ?? 1);
                                    if ($qty < 1) { $qty = 1; }
                                @endphp
                                <div style="font-weight:800; color:#0f172a;">x{{ $qty }}</div>
                            </td>
                            <td style="padding:10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top;">
                                @php
                                    $itRaw = $it['raw'] ?? $it;
                                    $unitPrice = $itRaw['item_price'] ?? $itRaw['paid_price'] ?? $itRaw['price'] ?? $itRaw['unit_price'] ?? null;
                                    $unitCurrency = $itRaw['currency'] ?? ($raw['currency'] ?? '₱');
                                @endphp
                                <div style="font-weight:800; color:#0f172a;">
                                    @if($unitPrice !== null && $unitPrice !== '')
                                        {{ is_numeric($unitPrice) ? $unitCurrency . number_format((float)$unitPrice, 2) : $unitPrice }}
                                    @else
                                        —
                                    @endif
                                </div>
                            </td>

                            @if($idx === 0)
                            <td rowspan="{{ $lineCount }}" style="padding:10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top;">
                                <div style="font-weight:700; color:#0f172a;">
                                    @if($totalAmount !== null && $totalAmount !== '')
                                        {{ is_numeric($totalAmount) ? $currency . number_format((float)$totalAmount, 2) : $totalAmount }}
                                    @else
                                        —
                                    @endif
                                </div>
                                @if($paymentBadge)
                                    <div style="margin-top:6px; display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:12px; border:1px solid #e5e7eb; background:#f8fafc; color:#334155;">
                                        {{ $paymentBadge }}
                                    </div>
                                @endif
                            </td>
                            <td rowspan="{{ $lineCount }}" style="padding:10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top;">
                                <div style="font-weight:600; color:#0f172a;">{{ $courier ?: '—' }}</div>
                                @if($deliveryMethod)
                                    <div style="margin-top:4px; font-size:12px; color:#64748b;">{{ $deliveryMethod }}</div>
                                @endif
                                @if($trackingNo)
                                    <div style="margin-top:4px; font-size:12px; color:#64748b;">Tracking: <span style="color:#334155;">{{ $trackingNo }}</span></div>
                                @endif
                            </td>
                            <td rowspan="{{ $lineCount }}" style="padding:10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top;">
                                <div style="font-weight:700; color:#0f172a;">{{ $statusStr !== '' ? $statusStr : '—' }}</div>
                                @if($sla)
                                    <div style="margin-top:6px; display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:12px; border:1px solid #e5e7eb; background:#fff7ed; color:#9a3412;">
                                        {{ $sla }}
                                    </div>
                                @endif
                            </td>
                            <td rowspan="{{ $lineCount }}" style="padding:10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top;">
                                @if(!$orderId)
                                    <span style="color:#94a3b8;">N/A</span>
                                @elseif($isUnpaid)
                                    <span style="color:#94a3b8;">—</span>
                                @else
                                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                                        {{-- TO PACK (pending): only Pack & Print --}}
                                        @if($isToPack)
                                            <form method="POST" action="{{ route('lazada.orders.pack_print', ['orderId' => $orderId]) }}" onsubmit="return confirm('Pack this order now? This will create packages and make AWB available.');">
                                                @csrf
                                                <button type="submit" style="padding:8px 10px; border-radius:8px; border:0; background:#2563eb; color:#fff; cursor:pointer;">Pack &amp; Print</button>
                                            </form>
                                        @endif

                                        {{-- TO ARRANGE (packed): Print + Arrange Shipment + Recreate Package --}}
                                        @if($isToArrange)
                                            <a href="{{ route('lazada.orders.awb', ['orderId' => $orderId]) }}" target="_blank" style="display:inline-block; padding:8px 10px; border-radius:8px; background:#0f172a; color:#fff; text-decoration:none;">Print AWB</a>

                                            <form method="POST" action="{{ route('lazada.orders.rts', ['orderId' => $orderId]) }}" onsubmit="return confirm('Arrange Shipment (Ready To Ship) for this order?');">
                                                @csrf
                                                <button type="submit" style="padding:8px 10px; border-radius:8px; border:0; background:#16a34a; color:#fff; cursor:pointer;">Arrange Shipment</button>
                                            </form>

                                            <form method="POST" action="{{ route('lazada.orders.recreate_package', ['orderId' => $orderId]) }}" onsubmit="return confirm('Recreate Package (local)? Lazada may still keep the original package. Continue?');">
                                                @csrf
                                                <button type="submit" style="padding:8px 10px; border-radius:8px; border:1px solid #e5e7eb; background:#fff; cursor:pointer;">Recreate Package</button>
                                            </form>
                                        @endif

                                        {{-- TO HANDOVER (ready_to_ship): Print only --}}
                                        @if($isToHandover)
                                            <a href="{{ route('lazada.orders.awb', ['orderId' => $orderId]) }}" target="_blank" style="display:inline-block; padding:8px 10px; border-radius:8px; background:#0f172a; color:#fff; text-decoration:none;">Print AWB</a>
                                        @endif

                                        {{-- Cancel available in these workflow statuses --}}
                                        @if($isToPack || $isToArrange || $isToHandover)
                                            <button type="button"
                                                    class="btnCancelOrder"
                                                    data-order-id="{{ $orderId }}"
                                                    data-reasons-url="{{ route('lazada.orders.cancel_reasons', ['orderId' => $orderId]) }}"
                                                    data-submit-url="{{ route('lazada.orders.cancel', ['orderId' => $orderId]) }}"
                                                    style="padding:8px 10px; border-radius:8px; border:1px solid #ef4444; background:#fff; color:#b91c1c; cursor:pointer;">
                                                Cancel
                                            </button>
                                        @endif
                                    </div>

                                    {{-- Print quick-links (only when AWB exists) --}}
                                    @if($isToArrange || $isToHandover)
                                        <div style="margin-top:8px; display:flex; gap:10px; flex-wrap:wrap; font-size:12px;">
                                            <span style="color:#64748b;">Print:</span>
                                            <a href="{{ route('lazada.orders.awb', ['orderId' => $orderId]) }}" target="_blank" style="color:#2563eb; text-decoration:none;">AWB</a>
                                            <span style="color:#cbd5e1;">|</span>
                                            <a href="{{ route('lazada.orders.packing_list', ['orderId' => $orderId]) }}" target="_blank" style="color:#2563eb; text-decoration:none;">Packing List</a>
                                            <span style="color:#cbd5e1;">|</span>
                                            <a href="{{ route('lazada.orders.pick_list', ['orderId' => $orderId]) }}" target="_blank" style="color:#2563eb; text-decoration:none;">Pick List</a>

                                            <span style="color:#cbd5e1;">|</span>
                                            <button type="button"
                                                    class="btnLzLogistics"
                                                    data-url="{{ route('lazada.orders.logistics_trace', ['orderId' => $orderId]) }}"
                                                    style="border:0; padding:0; background:transparent; color:#2563eb; cursor:pointer; font-size:12px;">
                                                Logistics Status
                                            </button>
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
            <div style="padding:12px; border-top:1px solid #e5e7eb;">{{ $orders->links() }}</div>
        @endif
    </div>
</div>
<form method="POST" action="{{ route('lazada.orders.reset') }}" id="formResetLazadaOrders" style="display:none;">
    @csrf
    <input type="hidden" name="confirm" id="resetConfirmInput" value="">
</form>

{{-- Loading overlay for long-running fetch/reset actions --}}
<div id="lzLoadingOverlay" style="position:fixed; inset:0; background:rgba(15,23,42,.55); display:none; align-items:center; justify-content:center; z-index:9999;">
    <div style="background:#fff; border-radius:14px; padding:18px 18px; width:min(420px, calc(100% - 32px)); box-shadow:0 10px 35px rgba(0,0,0,.22);">
        <div style="display:flex; align-items:center; gap:12px;">
            <div aria-hidden="true" style="width:18px; height:18px; border:3px solid #cbd5e1; border-top-color:#2563eb; border-radius:50%; animation:lzSpin 0.8s linear infinite;"></div>
            <div>
                <div id="lzLoadingTitle" style="font-weight:700; color:#0f172a;">Fetching orders…</div>
                <div style="font-size:12px; color:#64748b; margin-top:2px;">Please keep this tab open. This may take a while depending on Lazada API response.</div>
            </div>
        </div>
    </div>
</div>

{{-- Pack & Print follow-up modal --}}
<div id="lzPackPrintModal" style="position:fixed; inset:0; background:rgba(15,23,42,.55); display:none; align-items:center; justify-content:center; z-index:10000;">
    <div style="background:#fff; border-radius:14px; padding:16px; width:min(520px, calc(100% - 32px)); box-shadow:0 10px 35px rgba(0,0,0,.22);">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
                <div style="font-weight:800; color:#0f172a;">Pack &amp; Print</div>
                <div style="font-size:12px; color:#64748b; margin-top:2px;">Choose what to do next. If you close this, the order stays in <strong>To Arrange</strong>.</div>
            </div>
            <button type="button" id="btnClosePackPrint" style="border:0; background:transparent; cursor:pointer; font-size:18px; line-height:1; color:#64748b;">×</button>
        </div>
        <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
            <form method="POST" id="formRecreatePackage" action="#" style="margin:0;">
                @csrf
                <button type="submit" style="padding:10px 12px; border-radius:10px; border:1px solid #e5e7eb; background:#fff; cursor:pointer;">Recreate Package</button>
            </form>

            <form method="POST" id="formShipPrint" action="#" style="margin:0;">
                @csrf
                <button type="submit" style="padding:10px 12px; border-radius:10px; border:0; background:#16a34a; color:#fff; cursor:pointer;">Ship &amp; Print</button>
            </form>

            <a href="#" id="linkPrintOnly" target="_blank" style="display:inline-block; padding:10px 12px; border-radius:10px; border:1px solid #e5e7eb; background:#fff; color:#0f172a; text-decoration:none;">Print Only</a>
        </div>
    </div>
</div>

{{-- Cancel order modal --}}
<div id="lzCancelModal" style="position:fixed; inset:0; background:rgba(15,23,42,.55); display:none; align-items:center; justify-content:center; z-index:10000;">
    <div style="background:#fff; border-radius:14px; padding:16px; width:min(560px, calc(100% - 32px)); box-shadow:0 10px 35px rgba(0,0,0,.22);">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
                <div style="font-weight:800; color:#0f172a;">Cancel Order</div>
                <div id="lzCancelModalSub" style="font-size:12px; color:#64748b; margin-top:2px;"></div>
            </div>
            <button type="button" id="btnCloseCancel" style="border:0; background:transparent; cursor:pointer; font-size:18px; line-height:1; color:#64748b;">×</button>
        </div>

        <form method="POST" id="lzCancelForm" action="#" style="margin-top:14px;">
            @csrf
            <div>
                <label style="display:block; font-size:12px; color:#64748b; margin-bottom:6px;">Reason</label>
                <select name="reason_id" id="lzCancelReason" required style="width:100%; padding:10px 10px; border:1px solid #cbd5e1; border-radius:10px;">
                    <option value="" selected disabled>Loading reasons…</option>
                </select>
                <div id="lzCancelReasonErr" style="display:none; margin-top:6px; color:#dc2626; font-size:12px;"></div>
            </div>
            <div style="margin-top:12px;">
                <label style="display:block; font-size:12px; color:#64748b; margin-bottom:6px;">Details (optional)</label>
                <textarea name="reason_detail" rows="2" style="width:100%; padding:10px 10px; border:1px solid #cbd5e1; border-radius:10px; resize:vertical;" placeholder="Add extra details if needed"></textarea>
            </div>
            <div style="margin-top:14px; display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" id="btnCancelClose2" style="padding:10px 12px; border-radius:10px; border:1px solid #e5e7eb; background:#fff; cursor:pointer;">Close</button>
                <button type="submit" style="padding:10px 12px; border-radius:10px; border:0; background:#ef4444; color:#fff; cursor:pointer;">Confirm Cancel</button>
            </div>
        </form>
    </div>
</div>

{{-- Logistics status modal --}}
<div id="lzLogisticsModal" style="position:fixed; inset:0; background:rgba(15,23,42,.55); display:none; align-items:center; justify-content:center; z-index:10000;">
    <div style="background:#fff; border-radius:14px; padding:16px; width:min(720px, calc(100% - 32px)); box-shadow:0 10px 35px rgba(0,0,0,.22);">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
                <div style="font-weight:800; color:#0f172a;">Logistics Status</div>
                <div id="lzLogisticsSub" style="font-size:12px; color:#64748b; margin-top:2px;"></div>
            </div>
            <button type="button" id="btnCloseLogistics" style="border:0; background:transparent; cursor:pointer; font-size:18px; line-height:1; color:#64748b;">×</button>
        </div>
        <div id="lzLogisticsBody" style="margin-top:14px; font-size:12px; color:#0f172a;">
            <div style="color:#64748b;">Loading…</div>
        </div>
        <div style="margin-top:14px; display:flex; gap:10px; justify-content:flex-end;">
            <button type="button" id="btnLogisticsClose2" style="padding:10px 12px; border-radius:10px; border:1px solid #e5e7eb; background:#fff; cursor:pointer;">Close</button>
        </div>
    </div>
</div>

<style>
@keyframes lzSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var awbUrlToOpen = @json(session('lazada_awb_url'));
    if (awbUrlToOpen) {
        try { window.open(String(awbUrlToOpen), '_blank'); } catch (e) {}
    }

    var btn = document.getElementById('btnResetLazadaOrders');
    var form = document.getElementById('formResetLazadaOrders');
    var input = document.getElementById('resetConfirmInput');
    if (btn && form && input) {
        btn.addEventListener('click', function () {
            var v = prompt('Type RESET to delete Lazada orders and re-sync:');
            if (!v) return;
            input.value = v.trim();
            // Show loading overlay for reset
            var ov = document.getElementById('lzLoadingOverlay');
            var tt = document.getElementById('lzLoadingTitle');
            if (ov) {
                if (tt) tt.textContent = 'Resetting orders…';
                ov.style.display = 'flex';
            }
            form.submit();
        });
    }

    // Show loading overlay for fetch/update
    var fetchForm = document.getElementById('formFetchLazadaOrders');
    var fetchBtn = document.getElementById('btnFetchOrders');
    var updateBtn = document.getElementById('btnUpdateOrders');
    var inlineSp = document.getElementById('fetchInlineSpinner');
    var inlineUpSp = document.getElementById('updateInlineSpinner');
    var nextTitle = 'Fetching orders…';

    if (fetchBtn) {
        fetchBtn.addEventListener('click', function () {
            nextTitle = 'Fetching orders…';
        });
    }
    if (updateBtn) {
        updateBtn.addEventListener('click', function () {
            nextTitle = 'Updating orders…';
        });
    }

    if (fetchForm) {
        fetchForm.addEventListener('submit', function () {
            if (fetchBtn) {
                fetchBtn.disabled = true;
                fetchBtn.style.opacity = '0.85';
            }
            if (updateBtn) {
                updateBtn.disabled = true;
                updateBtn.style.opacity = '0.85';
            }
            // Show the right inline spinner
            if (nextTitle === 'Updating orders…') {
                if (inlineUpSp) inlineUpSp.style.display = 'inline-block';
            } else {
                if (inlineSp) inlineSp.style.display = 'inline-block';
            }
            var ov = document.getElementById('lzLoadingOverlay');
            var tt = document.getElementById('lzLoadingTitle');
            if (ov) {
                if (tt) tt.textContent = nextTitle;
                ov.style.display = 'flex';
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
        var awbTpl = @json(route('lazada.orders.awb', ['orderId' => '__OID__']));
        var shipTpl = @json(route('lazada.orders.ship_print_post', ['orderId' => '__OID__']));
        var recreateTpl = @json(route('lazada.orders.recreate_package', ['orderId' => '__OID__']));

        if (linkPrintOnly) linkPrintOnly.href = awbTpl.replace('__OID__', orderId);
        if (formShipPrint) formShipPrint.action = shipTpl.replace('__OID__', orderId);
        if (formRecreate) formRecreate.action = recreateTpl.replace('__OID__', orderId);

        packPrintModal.style.display = 'flex';
    }

    function hidePackPrintModal() {
        if (!packPrintModal) return;
        packPrintModal.style.display = 'none';
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

    // --- Cancel modal (fetch reasons on demand) ---
    var cancelModal = document.getElementById('lzCancelModal');
    var cancelForm = document.getElementById('lzCancelForm');
    var cancelReason = document.getElementById('lzCancelReason');
    var cancelErr = document.getElementById('lzCancelReasonErr');
    var cancelSub = document.getElementById('lzCancelModalSub');
    var closeCancel = document.getElementById('btnCloseCancel');
    var closeCancel2 = document.getElementById('btnCancelClose2');

    function showCancelModal() {
        if (!cancelModal) return;
        cancelModal.style.display = 'flex';
    }
    function hideCancelModal() {
        if (!cancelModal) return;
        cancelModal.style.display = 'none';
    }

    if (closeCancel) closeCancel.addEventListener('click', hideCancelModal);
    if (closeCancel2) closeCancel2.addEventListener('click', hideCancelModal);
    if (cancelModal) {
        cancelModal.addEventListener('click', function (e) {
            if (e.target === cancelModal) hideCancelModal();
        });
    }

    function setCancelReasonLoading() {
        if (!cancelReason) return;
        cancelReason.innerHTML = '<option value="" selected disabled>Loading reasons…</option>';
        if (cancelErr) { cancelErr.style.display = 'none'; cancelErr.textContent = ''; }
    }

    async function loadCancelReasons(url) {
        setCancelReasonLoading();
        try {
            var r = await fetch(url, { headers: { 'Accept': 'application/json' } });
            var j = await r.json();
            if (!r.ok || !j || j.ok !== true) {
                throw new Error((j && j.message) ? j.message : 'Failed to fetch reasons');
            }
            var data = j.data;
            var list = [];
            if (Array.isArray(data)) {
                list = data;
            } else if (data && Array.isArray(data.reasons)) {
                list = data.reasons;
            } else if (data && Array.isArray(data.data)) {
                list = data.data;
            }

            if (!cancelReason) return;
            cancelReason.innerHTML = '<option value="" selected disabled>Select a reason</option>';
            list.forEach(function (it) {
                var id = it.reason_id || it.reasonId || it.id || it.ReasonId;
                var name = it.reason_name || it.reasonName || it.name || it.ReasonName;
                if (!id || !name) return;
                var opt = document.createElement('option');
                opt.value = String(id);
                opt.textContent = String(name);
                cancelReason.appendChild(opt);
            });
        } catch (e) {
            if (cancelErr) {
                cancelErr.style.display = 'block';
                cancelErr.textContent = e && e.message ? e.message : 'Failed to fetch reasons.';
            }
            if (cancelReason) {
                cancelReason.innerHTML = '<option value="" selected disabled>Unable to load reasons</option>';
            }
        }
    }

    document.querySelectorAll('.btnCancelOrder').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var orderId = btn.getAttribute('data-order-id') || '';
            var reasonsUrl = btn.getAttribute('data-reasons-url') || '';
            var submitUrl = btn.getAttribute('data-submit-url') || '';
            if (!orderId || !reasonsUrl || !submitUrl || !cancelForm) return;

            cancelForm.action = submitUrl;
            if (cancelSub) cancelSub.textContent = 'Order Number: ' + orderId;
            showCancelModal();
            loadCancelReasons(reasonsUrl);
        });
    });

    // --- Logistics trace modal ---
    var logisticsModal = document.getElementById('lzLogisticsModal');
    var logisticsBody = document.getElementById('lzLogisticsBody');
    var logisticsSub = document.getElementById('lzLogisticsSub');
    var closeLogistics = document.getElementById('btnCloseLogistics');
    var closeLogistics2 = document.getElementById('btnLogisticsClose2');

    function showLogisticsModal() {
        if (!logisticsModal) return;
        logisticsModal.style.display = 'flex';
    }
    function hideLogisticsModal() {
        if (!logisticsModal) return;
        logisticsModal.style.display = 'none';
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

    function renderTrace(body) {
        // Attempt to normalize common structures
        var data = (body && body.data) ? body.data : body;
        var traces = (data && (data.traces || data.trace_list || data.traceList || data.events)) || [];
        if (!Array.isArray(traces)) traces = [];
        if (!logisticsBody) return;

        if (traces.length === 0) {
            logisticsBody.innerHTML = '<div style="color:#64748b;">No logistics events returned by Lazada for this order.</div>';
            return;
        }

        var html = '<div style="border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">';
        html += '<table style="width:100%; border-collapse:collapse;">';
        html += '<thead><tr style="background:#f8fafc;">'
            + '<th style="text-align:left; padding:10px; font-size:12px; border-bottom:1px solid #e5e7eb; width:160px;">Time</th>'
            + '<th style="text-align:left; padding:10px; font-size:12px; border-bottom:1px solid #e5e7eb;">Status</th>'
            + '<th style="text-align:left; padding:10px; font-size:12px; border-bottom:1px solid #e5e7eb;">Details</th>'
            + '</tr></thead><tbody>';
        traces.forEach(function (t) {
            var time = t.event_time || t.time || t.timestamp || t.update_time || '';
            var status = t.status || t.event || t.action || t.title || '';
            var detail = t.description || t.desc || t.detail || t.message || '';
            html += '<tr>'
                + '<td style="padding:10px; border-bottom:1px solid #e5e7eb; color:#0f172a;">' + esc(time) + '</td>'
                + '<td style="padding:10px; border-bottom:1px solid #e5e7eb; font-weight:700;">' + esc(status) + '</td>'
                + '<td style="padding:10px; border-bottom:1px solid #e5e7eb; color:#334155;">' + esc(detail) + '</td>'
                + '</tr>';
        });
        html += '</tbody></table></div>';
        logisticsBody.innerHTML = html;
    }

    async function fetchLogistics(url) {
        if (!logisticsBody) return;
        logisticsBody.innerHTML = '<div style="color:#64748b;">Loading…</div>';
        try {
            var r = await fetch(url, { headers: { 'Accept': 'application/json' } });
            var j = await r.json();
            if (!r.ok || !j || j.ok !== true) {
                var msg = (j && j.body && j.body.message) ? j.body.message : ((j && j.message) ? j.message : 'Failed to fetch logistics trace');
                throw new Error(msg);
            }
            renderTrace(j.body);
        } catch (e) {
            logisticsBody.innerHTML = '<div style="color:#dc2626;">' + esc(e && e.message ? e.message : 'Failed to fetch logistics trace') + '</div>';
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

@endsection
