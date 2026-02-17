@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Lazada / Orders')

@section('content')
<div class="page-header">
    <h2>Lazada Orders <span class="text-secondary text-sm">({{ $orders->total() }})</span></h2>
</div>

    @if($last_result)
        <div class="alert {{ $last_result['ok'] ? 'success' : 'danger' }}">
            <strong>{{ $last_result['ok'] ? 'OK' : 'Error' }}</strong>
            @if(!empty($last_result['message']))
                <div style="margin-top:6px;">{{ $last_result['message'] }}</div>
            @endif
        </div>
@endif

<div class="card mb-16">
<form method="POST" action="{{ route('lazada.orders.fetch') }}" id="formFetchLazadaOrders" class="d-flex gap-12 flex-wrap items-center">
        @csrf
        <div>
            <label class="text-xs text-secondary">From</label>
            <input type="date" name="date_from" value="{{ old('date_from', request('date_from')) }}" class="input" style="width:auto;">
        </div>
        <div>
            <label class="text-xs text-secondary">To</label>
            <input type="date" name="date_to" value="{{ old('date_to', request('date_to')) }}" class="input" style="width:auto;">
        </div>
        <div>
            <label class="text-xs text-secondary">Status (optional)</label>
            <input type="text" name="status" value="{{ old('status', request('status')) }}" placeholder="e.g. ready_to_ship" class="input" style="min-width:220px;">
            @error('status')<div class="field-error">{{ $message }}</div>@enderror
        </div>
        <div class="d-flex gap-8">
            <button type="submit" class="btn" id="btnFetchOrders">
                <span>Fetch Orders</span>
                <span id="fetchInlineSpinner" aria-hidden="true" class="hidden" style="width:14px; height:14px; border:2px solid rgba(255,255,255,.55); border-top-color:#fff; border-radius:50%; animation:lzSpin 0.8s linear infinite;"></span>
            </button>

            <button type="submit" class="btn secondary" id="btnUpdateOrders" formaction="{{ route('lazada.orders.update_statuses') }}">
                <span>Update Orders</span>
                <span id="updateInlineSpinner" aria-hidden="true" class="hidden" style="width:14px; height:14px; border:2px solid rgba(15,23,42,.25); border-top-color:#0f172a; border-radius:50%; animation:lzSpin 0.8s linear infinite;"></span>
            </button>
        </div>
        <div>
            <button type="button" class="btn danger" id="btnResetLazadaOrders">Reset Orders</button>
        </div>
        <div class="text-secondary text-xs">
	        Leave <strong>From/To</strong> empty for <strong>automatic sync</strong> (maximum <strong>14 days</strong> lookback). Use dates only when you need a specific range (max <strong>14 days</strong> per fetch).
        </div>
    </form>
</div>

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
                $qs = array_merge(request()->query(), ['tab' => $key]);
            @endphp
            <a href="{{ route('lazada.orders.index', $qs) }}" class="tab {{ $isActive ? 'active' : '' }}">
                <span>{{ $label }}</span>
                @if($count !== null)
                    <span class="badge {{ $isActive ? 'badge-blue' : 'badge-gray' }}" style="margin-left:6px;">
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
        <div class="card mb-12" style="padding:12px 16px;">
            <div class="d-flex items-center justify-between gap-10" style="margin-bottom:8px;">
                <div class="font-semibold">Pending workflow</div>
                <div class="text-xs text-secondary">Choose the step you are working on</div>
            </div>

            <div class="d-flex gap-10 flex-wrap">
                @foreach($pendingNav as $k => $lbl)
                    @php
                        $isActive = ($ps === $k) || ($ps === '' && $k === 'to_pack');
                        $qs = array_merge(request()->query(), ['tab' => 'PENDING', 'pending_sub' => $k]);
                        $count = $pending_sub_counts[$k] ?? null;
                    @endphp
                    <a href="{{ route('lazada.orders.index', $qs) }}" class="tab {{ $isActive ? 'active' : '' }}">
                        <span>{{ $lbl }}</span>
                        @if($count !== null)
                            <span class="badge {{ $isActive ? 'badge-blue' : 'badge-gray' }}" style="margin-left:6px;">
                                {{ $count }}
                            </span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card mb-12" style="padding:12px 16px;">
    <form method="GET" action="{{ route('lazada.orders.index') }}" class="d-flex gap-10 flex-wrap items-center">
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

        <div class="d-flex gap-8">
            <button type="submit" class="btn secondary">Search</button>
            <a href="{{ route('lazada.orders.index', ['tab' => ($active_tab ?? 'ALL'), 'per_page' => (int)($per_page ?? 10)]) }}" class="btn secondary">Reset</a>
        </div>
    </form>
    </div>

    <div class="card mb-12" style="padding:12px 16px;">
    <form method="GET" action="{{ route('lazada.orders.index') }}" class="d-flex gap-10 items-center">
    <input type="hidden" name="tab" value="{{ $active_tab ?? request()->query('tab', 'ALL') }}">
    <input type="hidden" name="order_number" value="{{ $filters['order_number'] ?? request()->query('order_number', '') }}">
    <input type="hidden" name="buyer_name" value="{{ $filters['buyer_name'] ?? request()->query('buyer_name', '') }}">
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
            <div class="text-secondary" style="padding:12px 0;">No orders loaded yet.</div>
        @else
            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:42px;">
                            <input type="checkbox" aria-label="Select all" disabled>
                        </th>
                        <th style="min-width:360px;">Products</th>
                        <th style="width:110px;">Quantity</th>
                        <th style="width:140px;">Product Price</th>
                        <th>Amount</th>
                        <th>Shipping</th>
                        <th>Status</th>
                        <th style="width:360px;">Actions</th>
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

                            // SLA / urgency
                            $sla = $raw['sla_time'] ?? $raw['shipping_sla'] ?? $raw['sla'] ?? $raw['ship_by_date'] ?? null;
                            if (is_array($sla)) $sla = implode(', ', $sla);
                            $sla = is_string($sla) ? trim($sla) : null;
                        @endphp

                        @if($orderId)
                            <tr>
                                <td colspan="8" style="padding:10px 12px 6px 12px;">
                                    <div class="d-flex justify-between items-start gap-12">
                                        <div class="text-xs text-secondary" style="line-height:1.35;">
                                            <span>Buyer:</span> <span class="font-semibold" style="color:var(--text-primary);">{{ $buyer !== '' ? $buyer : '—' }}</span>
                                            @if($itemCount > 0)
                                                <span style="margin-left:8px;">({{ $itemCount }} {{ $itemCount === 1 ? 'item' : 'items' }})</span>
                                            @endif
                                        </div>
                                        <div class="text-xs text-secondary text-right" style="line-height:1.35;">
                                            <span>Order Number:</span>
                                            <a href="{{ route('lazada.orders.show', ['orderId' => $orderId]) }}" target="_blank" rel="noopener" class="font-semibold" style="color:var(--text-primary); text-decoration:underline; text-underline-offset:2px;">
                                                {{ $orderId }}
                                            </a>
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
                            @if($idx === 0)
                            <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                                @if(!$orderId || !$canSelect)
                                    <span class="text-muted">—</span>
                                @else
                                    <input type="checkbox" name="select_order[]" value="{{ $orderId }}">
                                @endif
                            </td>
                            @endif

                            <td style="vertical-align:top;">
                                @php
                                    $itRaw = $it['raw'] ?? $it;
                                    $name = (string)($it['name'] ?? $itRaw['name'] ?? $itRaw['item_name'] ?? $itRaw['product_name'] ?? '');
                                    $sku = (string)($it['sku'] ?? $itRaw['sku'] ?? $itRaw['seller_sku'] ?? $itRaw['SellerSku'] ?? $itRaw['SellerSKU'] ?? $itRaw['Sku'] ?? '');
                                    $variation = (string)($it['variation'] ?? $itRaw['variation'] ?? $itRaw['Variation'] ?? $itRaw['variant'] ?? '');
                                    $img = (string)($it['image'] ?? $itRaw['image'] ?? $itRaw['product_main_image'] ?? $itRaw['product_image'] ?? '');
                                @endphp

                                <div class="d-flex gap-10">
                                    <div style="width:44px; height:44px; border-radius:8px; overflow:hidden; background:var(--surface-alt); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                        @if($img)
                                            <img src="{{ $img }}" alt="" style="width:44px; height:44px; object-fit:cover;">
                                        @else
                                            <span class="text-xs text-muted">No image</span>
                                        @endif
                                    </div>
                                    <div style="min-width:0;">
                                        <div class="font-semibold" style="word-break:break-word;">{{ $name !== '' ? $name : '—' }}</div>
                                        <div class="text-xs" style="margin-top:3px;">
                                            <span class="text-secondary">Seller SKU:</span> <strong>{{ $sku !== '' ? $sku : '—' }}</strong>
                                        </div>
                                        @if($variation !== '')
                                            <div class="text-xs text-secondary" style="margin-top:3px;">{{ $variation }}</div>
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
                                    $itRaw = $it['raw'] ?? $it;
                                    $unitPrice = $itRaw['item_price'] ?? $itRaw['paid_price'] ?? $itRaw['price'] ?? $itRaw['unit_price'] ?? null;
                                    $unitCurrency = $itRaw['currency'] ?? ($raw['currency'] ?? '₱');
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
                                @if($sla)
                                    <div style="margin-top:6px;">
                                        <span class="badge badge-yellow">{{ $sla }}</span>
                                    </div>
                                @endif
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
                                            <form method="POST" action="{{ route('lazada.orders.pack_print', ['orderId' => $orderId]) }}" onsubmit="return confirm('Pack this order now? This will create packages and make AWB available.');">
                                                @csrf
                                                <button type="submit" class="btn small">Pack &amp; Print</button>
                                            </form>
                                        @endif

                                        {{-- TO ARRANGE (packed): Print + Arrange Shipment + Recreate Package --}}
                                        @if($isToArrange)
                                            <a href="{{ route('lazada.orders.awb', ['orderId' => $orderId]) }}" target="_blank" class="btn small secondary">Print AWB</a>

                                            <form method="POST" action="{{ route('lazada.orders.rts', ['orderId' => $orderId]) }}" onsubmit="return confirm('Arrange Shipment (Ready To Ship) for this order?');">
                                                @csrf
                                                <button type="submit" class="btn small" style="background:#16a34a; border-color:#16a34a; color:#fff;">Arrange Shipment</button>
                                            </form>

                                            <form method="POST" action="{{ route('lazada.orders.recreate_package', ['orderId' => $orderId]) }}" onsubmit="return confirm('Recreate Package (local)? Lazada may still keep the original package. Continue?');">
                                                @csrf
                                                <button type="submit" class="btn small secondary">Recreate Package</button>
                                            </form>
                                        @endif

                                        {{-- TO HANDOVER (ready_to_ship): Print only --}}
                                        @if($isToHandover)
                                            <a href="{{ route('lazada.orders.awb', ['orderId' => $orderId]) }}" target="_blank" class="btn small secondary">Print AWB</a>
                                        @endif

                                        {{-- Cancel available in these workflow statuses --}}
                                        @if($isToPack || $isToArrange || $isToHandover)
                                            <button type="button"
                                                    class="btnCancelOrder btn small danger"
                                                    data-order-id="{{ $orderId }}"
                                                    data-reasons-url="{{ route('lazada.orders.cancel_reasons', ['orderId' => $orderId]) }}"
                                                    data-submit-url="{{ route('lazada.orders.cancel', ['orderId' => $orderId]) }}">
                                                Cancel
                                            </button>
                                        @endif
                                    </div>

                                    {{-- Print quick-links (only when AWB exists) --}}
                                    @if($isToArrange || $isToHandover)
                                        <div class="d-flex gap-10 flex-wrap text-xs" style="margin-top:8px;">
                                            <span class="text-secondary">Print:</span>
                                            <a href="{{ route('lazada.orders.awb', ['orderId' => $orderId]) }}" target="_blank" style="color:var(--accent); text-decoration:none;">AWB</a>
                                            <span class="text-muted">|</span>
                                            <a href="{{ route('lazada.orders.packing_list', ['orderId' => $orderId]) }}" target="_blank" style="color:var(--accent); text-decoration:none;">Packing List</a>
                                            <span class="text-muted">|</span>
                                            <a href="{{ route('lazada.orders.pick_list', ['orderId' => $orderId]) }}" target="_blank" style="color:var(--accent); text-decoration:none;">Pick List</a>

                                            <span class="text-muted">|</span>
                                            <button type="button"
                                                    class="btnLzLogistics"
                                                    data-url="{{ route('lazada.orders.logistics_trace', ['orderId' => $orderId]) }}"
                                                    style="border:0; padding:0; background:transparent; color:var(--accent); cursor:pointer; font-size:12px;">
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
            </div>
            <div style="padding:12px 0 0;">{{ $orders->links() }}</div>
        @endif
    </div>

<form method="POST" action="{{ route('lazada.orders.reset') }}" id="formResetLazadaOrders" class="hidden">
    @csrf
    <input type="hidden" name="confirm" id="resetConfirmInput" value="">
</form>

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

{{-- Cancel order modal --}}
<div id="lzCancelModal" class="modal-backdrop">
    <div class="modal" style="max-width:560px;">
        <div class="modal-header">
            <div>
                <h3>Cancel Order</h3>
                <div id="lzCancelModalSub" class="text-xs text-secondary" style="margin-top:2px;"></div>
            </div>
            <button type="button" id="btnCloseCancel" class="modal-close">&times;</button>
        </div>

        <form method="POST" id="lzCancelForm" action="#" class="mt-16">
            @csrf
            <div>
                <label>Reason</label>
                <select name="reason_id" id="lzCancelReason" required class="input">
                    <option value="" selected disabled>Loading reasons…</option>
                </select>
                <div id="lzCancelReasonErr" class="field-error hidden"></div>
            </div>
            <div class="mt-12">
                <label>Details (optional)</label>
                <textarea name="reason_detail" rows="2" class="input" placeholder="Add extra details if needed" style="resize:vertical;"></textarea>
            </div>
            <div class="d-flex gap-10 justify-end mt-16">
                <button type="button" id="btnCancelClose2" class="btn secondary">Close</button>
                <button type="submit" class="btn danger">Confirm Cancel</button>
            </div>
        </form>
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
            var ov = document.getElementById('lzLoadingOverlay');
            var tt = document.getElementById('lzLoadingTitle');
            if (ov) {
                if (tt) tt.textContent = 'Resetting orders…';
                ov.classList.add('active');
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
            if (nextTitle === 'Updating orders…') {
                if (inlineUpSp) inlineUpSp.classList.remove('hidden');
            } else {
                if (inlineSp) inlineSp.classList.remove('hidden');
            }
            var ov = document.getElementById('lzLoadingOverlay');
            var tt = document.getElementById('lzLoadingTitle');
            if (ov) {
                if (tt) tt.textContent = nextTitle;
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
        var awbTpl = @json(route('lazada.orders.awb', ['orderId' => '__OID__']));
        var shipTpl = @json(route('lazada.orders.ship_print_post', ['orderId' => '__OID__']));
        var recreateTpl = @json(route('lazada.orders.recreate_package', ['orderId' => '__OID__']));

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
        cancelModal.classList.add('active');
    }
    function hideCancelModal() {
        if (!cancelModal) return;
        cancelModal.classList.remove('active');
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
        if (cancelErr) { cancelErr.classList.add('hidden'); cancelErr.textContent = ''; }
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
                cancelErr.classList.remove('hidden');
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

    function renderTrace(body) {
        var data = (body && body.data) ? body.data : body;
        var traces = (data && (data.traces || data.trace_list || data.traceList || data.events)) || [];
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
            var status = t.status || t.event || t.action || t.title || '';
            var detail = t.description || t.desc || t.detail || t.message || '';
            html += '<tr>'
                + '<td>' + esc(time) + '</td>'
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

@endsection
