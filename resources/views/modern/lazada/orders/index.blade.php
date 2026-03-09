@extends('layouts.modern.app')
@section('breadcrumb', 'Marketplace / Lazada / Orders')

@section('content')
@php
    $tabs       = $tabs ?? [];
    $active_tab = $active_tab ?? 'ALL';
    $tab_counts = $tab_counts ?? [];
    $pending_sub_counts = $pending_sub_counts ?? [];
    $fd_sub_counts      = $fd_sub_counts ?? [];
    $filters    = $filters ?? [];
@endphp

{{-- Page header --}}
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <h1 class="text-2xl font-bold text-gray-900">
        Lazada Orders
        <span class="text-gray-400 text-base font-normal ml-1">({{ method_exists($orders,'total') ? $orders->total() : $orders->count() }})</span>
    </h1>
</div>

{{-- Fetch / Update form --}}
<div class="bg-white rounded-xl shadow-sm border p-4 mb-6">
    <form method="POST" action="{{ route('lazada.orders.fetch') }}" id="formFetchLazadaOrders"
          class="flex gap-3 flex-wrap items-end">
        @csrf
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">From</label>
            <input type="date" name="date_from" value="{{ old('date_from', request('date_from')) }}"
                   class="border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-[#0F146D]/30 focus:border-[#0F146D]">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">To</label>
            <input type="date" name="date_to" value="{{ old('date_to', request('date_to')) }}"
                   class="border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-[#0F146D]/30 focus:border-[#0F146D]">
        </div>
        <div class="flex items-center">
            <label class="inline-flex items-center gap-1.5 text-xs text-gray-500 cursor-pointer select-none">
                <input type="checkbox" name="no_stock" value="1"
                       class="rounded border-gray-300 text-[#0F146D] focus:ring-[#0F146D]">
                Skip stock adjust
            </label>
        </div>
        <div class="flex gap-2">
            <button type="submit" id="btnFetchOrders"
                    class="bg-[#0F146D] hover:bg-[#0b1058] text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                Fetch Orders
            </button>
            <button type="submit" id="btnUpdateOrders" formaction="{{ route('lazada.orders.update_statuses') }}"
                    class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium transition">
                Update Orders
            </button>
        </div>
        <div class="text-xs text-gray-400 flex-1 min-w-[160px]">
            <strong>From/To</strong> required for both Fetch and Update.
        </div>
    </form>
</div>

{{-- Last result alert --}}
@if($last_result)
    <div class="rounded-xl border px-4 py-3 mb-6 text-sm flex items-start gap-3
        {{ $last_result['ok'] ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' }}">
        <svg class="w-5 h-5 flex-shrink-0 mt-0.5 {{ $last_result['ok'] ? 'text-green-500' : 'text-red-500' }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            @if($last_result['ok'])
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            @else
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            @endif
        </svg>
        <div>
            <strong>{{ $last_result['ok'] ? 'OK' : 'Error' }}</strong>
            @if(!empty($last_result['message']))
                <div class="mt-1">{{ $last_result['message'] }}</div>
            @endif
        </div>
    </div>
@endif

{{-- Main status tabs --}}
<div class="flex gap-1 border-b border-gray-200 mb-4 overflow-x-auto">
    @foreach($tabs as $key => $label)
        @php
            $isActive  = strtoupper((string)$active_tab) === strtoupper((string)$key);
            $count     = $tab_counts[$key] ?? null;
            $qs        = array_merge(request()->query(), ['tab' => $key]);
            $showBadge = in_array($key, ['TO_SHIP', 'SHIPPING', 'FAILED_DELIVERY']);
        @endphp
        <a href="{{ route('lazada.orders.index', $qs) }}"
           class="relative px-4 py-2.5 text-sm font-medium whitespace-nowrap transition-colors
                  {{ $isActive
                      ? 'border-b-2 border-[#0F146D] text-[#0F146D] font-semibold'
                      : 'text-gray-500 hover:text-gray-700' }}">
            <span>{{ $label }}</span>
            @if($showBadge && $count !== null && $count > 0)
                <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-xs font-bold ml-1.5
                    {{ $isActive ? 'bg-white text-[#0F146D] ring-1 ring-[#0F146D]/20' : 'bg-[#0F146D] text-white' }}">{{ $count }}</span>
            @endif
        </a>
    @endforeach
    <a href="{{ route('lazada.orders.returns') }}"
       class="px-4 py-2.5 text-sm font-medium whitespace-nowrap text-gray-500 hover:text-gray-700 transition-colors">
        Return/Refund/Cancel
    </a>
</div>

{{-- TO_SHIP sub-tabs --}}
@if(strtoupper((string)($active_tab ?? 'ALL')) === 'TO_SHIP')
    @php
        $ps = $pending_subtab ?? request()->query('pending_sub', 'to_pack');
        $pendingNav = [
            'to_pack'      => 'To Pack',
            'to_arrange'   => 'To Arrange Shipment',
            'to_handover'  => 'To Handover',
        ];
    @endphp
    <div class="bg-white rounded-xl shadow-sm border p-4 mb-4">
        <div class="flex items-center justify-between gap-4 mb-3">
            <div class="font-semibold text-gray-900 text-sm">To Ship workflow</div>
            <div class="text-xs text-gray-400">Choose the step you are working on</div>
        </div>
        <div class="flex gap-2 flex-wrap justify-center">
            @foreach($pendingNav as $k => $lbl)
                @php
                    $isActive = ($ps === $k) || ($ps === '' && $k === 'to_pack');
                    $qs    = array_merge(request()->except('page'), ['tab' => 'TO_SHIP', 'pending_sub' => $k]);
                    $count = $pending_sub_counts[$k] ?? null;
                @endphp
                <a href="{{ route('lazada.orders.index', $qs) }}"
                   class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors
                          {{ $isActive
                              ? 'bg-[#0F146D] text-white'
                              : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    <span>{{ $lbl }}</span>
                    @if($count !== null && $count > 0)
                        <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-xs font-bold ml-1
                            {{ $isActive ? 'bg-white text-[#0F146D]' : 'bg-[#0F146D] text-white' }}">{{ $count }}</span>
                    @endif
                </a>
            @endforeach
        </div>

        @if(!empty($to_ship_courier_counts))
            <div class="mt-3 pt-3 border-t border-gray-100">
                <div class="text-xs text-gray-400 mb-2">Courier tally (To Handover only)</div>
                <div class="flex gap-2 flex-wrap">
                    @foreach($to_ship_courier_counts as $courier => $count)
                        <span class="inline-flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-lg px-3 py-1.5 text-sm">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
                            {{ $courier }}
                            <span class="font-bold text-red-600">{{ $count }}</span>
                        </span>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endif

{{-- FAILED_DELIVERY sub-tabs --}}
@if(strtoupper((string)($active_tab ?? 'ALL')) === 'FAILED_DELIVERY')
    @php
        $fs = $fd_subtab ?? request()->query('fd_sub', 'failed_delivery');
        $fdNav = [
            'failed_delivery' => 'Shipped Back',
            'lost_damaged'    => 'Lost & Damaged',
        ];
    @endphp
    <div class="bg-white rounded-xl shadow-sm border p-4 mb-4">
        <div class="flex items-center justify-between gap-4 mb-3">
            <div class="font-semibold text-gray-900 text-sm">Failed Delivery</div>
            <div class="text-xs text-gray-400">Filter by delivery failure type</div>
        </div>
        <div class="flex gap-2 flex-wrap justify-center">
            @foreach($fdNav as $k => $lbl)
                @php
                    $isActive = ($fs === $k) || ($fs === '' && $k === 'failed_delivery');
                    $qs    = array_merge(request()->query(), ['tab' => 'FAILED_DELIVERY', 'fd_sub' => $k]);
                    $count = $fd_sub_counts[$k] ?? null;
                @endphp
                <a href="{{ route('lazada.orders.index', $qs) }}"
                   class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors
                          {{ $isActive
                              ? 'bg-[#0F146D] text-white'
                              : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    <span>{{ $lbl }}</span>
                    @if($count !== null && $count > 0)
                        <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-xs font-bold ml-1
                            {{ $isActive ? 'bg-white text-[#0F146D]' : 'bg-[#0F146D] text-white' }}">{{ $count }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    </div>
@endif

{{-- Search / filter bar --}}
<div class="bg-white rounded-xl shadow-sm border p-4 mb-4">
    <form method="GET" action="{{ route('lazada.orders.index') }}" class="flex gap-3 items-end flex-wrap">
        <input type="hidden" name="tab" value="{{ $active_tab ?? request()->query('tab', 'ALL') }}">
        <input type="hidden" name="per_page" value="{{ (int)($per_page ?? request()->query('per_page', 10)) }}">

        <div class="min-w-[180px]">
            <label class="block text-xs font-medium text-gray-500 mb-1">Order Number</label>
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" name="order_number"
                       value="{{ old('order_number', $filters['order_number'] ?? request()->query('order_number', '')) }}"
                       placeholder="Order ID"
                       class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#0F146D]/30 focus:border-[#0F146D]">
            </div>
        </div>

        <div class="min-w-[180px]">
            <label class="block text-xs font-medium text-gray-500 mb-1">Buyer Name</label>
            <input type="text" name="buyer_name"
                   value="{{ old('buyer_name', $filters['buyer_name'] ?? request()->query('buyer_name', '')) }}"
                   placeholder="Customer"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#0F146D]/30 focus:border-[#0F146D]">
        </div>

        <div class="min-w-[220px]">
            <label class="block text-xs font-medium text-gray-500 mb-1">Sort by</label>
            <select name="sort"
                    class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-[#0F146D]/30 focus:border-[#0F146D]">
                <option value="created_asc" {{ ($sort ?? '') === 'created_asc' ? 'selected' : '' }}>Created Date (Oldest first)</option>
                <option value="created_desc" {{ ($sort ?? '') === 'created_desc' ? 'selected' : '' }}>Created Date (Newest first)</option>
                <option value="confirmed_created_asc" {{ ($sort ?? '') === 'confirmed_created_asc' ? 'selected' : '' }}>Confirmed: Created (Oldest)</option>
                <option value="confirmed_created_desc" {{ ($sort ?? '') === 'confirmed_created_desc' ? 'selected' : '' }}>Confirmed: Created (Newest)</option>
                <option value="confirmed_updated_asc" {{ ($sort ?? '') === 'confirmed_updated_asc' ? 'selected' : '' }}>Confirmed: Updated (Oldest)</option>
                <option value="confirmed_updated_desc" {{ ($sort ?? '') === 'confirmed_updated_desc' ? 'selected' : '' }}>Confirmed: Updated (Newest)</option>
                <option value="promised_shipping_asc" {{ ($sort ?? '') === 'promised_shipping_asc' ? 'selected' : '' }}>Promised Shipping (Oldest)</option>
                <option value="promised_shipping_desc" {{ ($sort ?? '') === 'promised_shipping_desc' ? 'selected' : '' }}>Promised Shipping (Newest)</option>
            </select>
        </div>

        <div class="min-w-[90px]">
            <label class="block text-xs font-medium text-gray-500 mb-1">Per page</label>
            <select name="per_page" onchange="this.form.submit()"
                    class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-[#0F146D]/30 focus:border-[#0F146D]">
                <option value="10" {{ (int)($per_page ?? 10)===10 ? 'selected' : '' }}>10</option>
                <option value="20" {{ (int)($per_page ?? 10)===20 ? 'selected' : '' }}>20</option>
                <option value="50" {{ (int)($per_page ?? 10)===50 ? 'selected' : '' }}>50</option>
            </select>
        </div>

        <div class="flex gap-2">
            <button type="submit"
                    class="bg-[#0F146D] hover:bg-[#0b1058] text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                Search
            </button>
            <a href="{{ route('lazada.orders.index', ['tab' => ($active_tab ?? 'ALL'), 'per_page' => (int)($per_page ?? 10)]) }}"
               class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium transition">
                Reset
            </a>
        </div>
    </form>
</div>

{{-- Orders table --}}
<div class="bg-white rounded-xl shadow-sm border overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <div class="font-semibold text-gray-900">
            Orders ({{ method_exists($orders,'total') ? $orders->total() : $orders->count() }})
        </div>
    </div>

    @if($orders->count() === 0)
        <div class="flex flex-col items-center justify-center py-16 px-6 text-center">
            <svg class="w-12 h-12 text-gray-300 mb-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                <line x1="12" y1="22.08" x2="12" y2="12"/>
            </svg>
            <div class="font-semibold text-gray-700 mb-1">No orders found</div>
            <div class="text-sm text-gray-400 max-w-sm">
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
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="w-10 px-4 py-3">
                            <input type="checkbox" aria-label="Select all" disabled
                                   class="rounded border-gray-300 text-[#0F146D] focus:ring-[#0F146D]">
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider" style="min-width:360px;">Products</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">Qty</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32">Unit Price</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Shipping</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider" style="width:360px;">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
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
                            $isUnpaid     = ($statusNorm === 'unpaid');
                            $isCancelled  = in_array($statusNorm, ['canceled','cancelled'], true);
                            $isToPack     = in_array($statusNorm, ['pending', 'repacked'], true);
                            $isToArrange  = ($statusNorm === 'packed');
                            $isToHandover = ($statusNorm === 'ready_to_ship');
                            $canSelect    = !($isUnpaid || $isCancelled);

                            $created = $o->order_created_at ? $o->order_created_at->format('Y-m-d H:i') : ($raw['created_at'] ?? $raw['createdAt'] ?? $raw['created_time'] ?? null);

                            $buyer = (string)($raw['customer_first_name'] ?? $raw['customer_name'] ?? $raw['buyer_name'] ?? '');

                            // Products: prefer persisted products; fall back to common raw nodes
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

                                    $gName      = $row['name'] ?? $rowRaw['name'] ?? $rowRaw['item_name'] ?? $rowRaw['product_name'] ?? '';
                                    $gSku       = $row['sku'] ?? $rowRaw['sku'] ?? $rowRaw['seller_sku'] ?? $rowRaw['SellerSku'] ?? $rowRaw['SellerSKU'] ?? $rowRaw['SellerSku'] ?? $rowRaw['Sku'] ?? '';
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
                                            'raw'        => $rowRaw,
                                            'name'       => $gName,
                                            'sku'        => $gSku,
                                            'variation'  => $gVariation,
                                            'quantity'   => $gQty,
                                            'image'      => $gImg,
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

                            // Amount & payment method
                            $totalAmount = $raw['price'] ?? $raw['total_amount'] ?? $raw['total_price'] ?? $raw['order_amount'] ?? $raw['amount'] ?? null;
                            $currency    = $raw['currency'] ?? '₱';

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
                            $detail = (isset($raw['_detail']) && is_array($raw['_detail'])) ? $raw['_detail'] : [];
                            $firstItemRaw = [];
                            if ($o->products && $o->products->isNotEmpty()) {
                                $fir = $o->products->first()->raw ?? null;
                                if (is_array($fir)) $firstItemRaw = $fir;
                            }

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

                            $courier        = $pick(['shipment_provider', 'shipping_provider', 'shipping_provider_name']);
                            $deliveryMethod = $pick(['shipping_type', 'delivery_type']);
                            $trackingNo     = $pick(['tracking_code', 'tracking_number', 'tracking_code_pre']);
                            $sla            = $pick(['sla_time_stamp', 'sla_time', 'shipping_sla', 'ship_by_date']);

                            // Status badge color mapping
                            $statusColor = match(true) {
                                $isUnpaid    => 'bg-yellow-100 text-yellow-800',
                                $isCancelled => 'bg-red-100 text-red-800',
                                $isToPack    => 'bg-blue-100 text-blue-800',
                                $isToArrange => 'bg-indigo-100 text-indigo-800',
                                $isToHandover => 'bg-purple-100 text-purple-800',
                                $statusNorm === 'shipped' || $statusNorm === 'shipping' => 'bg-cyan-100 text-cyan-800',
                                $statusNorm === 'delivered' => 'bg-green-100 text-green-800',
                                $statusNorm === 'failed_delivery' => 'bg-orange-100 text-orange-800',
                                $statusNorm === 'returned' => 'bg-rose-100 text-rose-800',
                                default => 'bg-gray-100 text-gray-800',
                            };
                        @endphp

                        {{-- Order header row --}}
                        @if($orderId)
                            <tr class="bg-gray-50/50">
                                <td colspan="8" class="px-4 py-2.5">
                                    <div class="flex justify-between items-start gap-4">
                                        <div class="text-xs text-gray-500 leading-relaxed">
                                            <span>Buyer:</span>
                                            <span class="font-semibold text-gray-900">{{ $buyer !== '' ? $buyer : '---' }}</span>
                                            <span class="ml-3">Order Number:</span>
                                            <span class="font-semibold text-gray-900">{{ $orderId }}</span>
                                            @if($itemCount > 0)
                                                <span class="ml-2 text-gray-400">({{ $itemCount }} {{ $itemCount === 1 ? 'item' : 'items' }})</span>
                                            @endif
                                        </div>
                                        <div class="text-xs text-gray-500 text-right leading-relaxed whitespace-nowrap">
                                            @if($created)
                                                <span>Created:</span>
                                                <span class="font-semibold text-gray-900">{{ $created }}</span>
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

                        {{-- Product line rows --}}
                        @foreach($displayItems as $idx => $it)
                            <tr class="hover:bg-gray-50/50 transition-colors">
                                @if($idx === 0)
                                <td rowspan="{{ $lineCount }}" class="px-4 py-3 align-top">
                                    @if(!$orderId || !$canSelect)
                                        <span class="text-gray-300">---</span>
                                    @else
                                        <input type="checkbox" name="select_order[]" value="{{ $orderId }}"
                                               class="rounded border-gray-300 text-[#0F146D] focus:ring-[#0F146D]">
                                    @endif
                                </td>
                                @endif

                                <td class="px-4 py-3 align-top">
                                    @php
                                        $itRaw     = $it['raw'] ?? $it;
                                        $name      = (string)($it['name'] ?? $itRaw['name'] ?? $itRaw['item_name'] ?? $itRaw['product_name'] ?? '');
                                        $sku       = (string)($it['sku'] ?? $itRaw['sku'] ?? $itRaw['seller_sku'] ?? $itRaw['SellerSku'] ?? $itRaw['SellerSKU'] ?? $itRaw['Sku'] ?? '');
                                        $variation = (string)($it['variation'] ?? $itRaw['variation'] ?? $itRaw['Variation'] ?? $itRaw['variant'] ?? '');
                                        $variationNorm = mb_strtolower(trim($variation));
                                        if (in_array($variationNorm, ['', 'blank', 'null', 'n/a', 'na', 'none', '-', '--'], true)) {
                                            $variation = '';
                                        }
                                        $img = (string)($it['image'] ?? $itRaw['image'] ?? $itRaw['product_main_image'] ?? $itRaw['product_image'] ?? '');
                                    @endphp

                                    <div class="flex gap-3">
                                        <div class="order-img-wrap w-11 h-11 rounded-lg overflow-hidden bg-gray-100 flex items-center justify-center flex-shrink-0">
                                            @if($img)
                                                <img class="order-img w-11 h-11 object-cover" src="{{ $img }}" alt="">
                                            @else
                                                <span class="text-[10px] text-gray-400">No img</span>
                                            @endif
                                        </div>
                                        <div class="min-w-0">
                                            <div class="font-semibold text-sm text-gray-900 break-words">
                                                @if($orderId)
                                                    <a href="{{ route('lazada.orders.show', ['orderId' => $orderId]) }}"
                                                       target="_blank" rel="noopener"
                                                       class="hover:text-[#0F146D] underline underline-offset-2 decoration-gray-300 hover:decoration-[#0F146D] transition-colors">
                                                        {{ $name !== '' ? $name : '---' }}
                                                    </a>
                                                @else
                                                    {{ $name !== '' ? $name : '---' }}
                                                @endif
                                            </div>
                                            <div class="text-xs mt-1 text-gray-500">
                                                Seller SKU: <strong class="text-gray-700">{{ $sku !== '' ? $sku : '---' }}</strong>
                                            </div>
                                            @if($variation !== '')
                                                <div class="mt-1">
                                                    <span class="inline-flex items-center gap-1 text-xs">
                                                        <span class="text-gray-400">Option:</span>
                                                        <span class="bg-gray-100 text-gray-700 rounded px-1.5 py-0.5 text-xs font-medium">{{ $variation }}</span>
                                                    </span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </td>

                                <td class="px-4 py-3 align-top">
                                    @php
                                        $itRaw = $it['raw'] ?? $it;
                                        $qty   = (int)($it['quantity'] ?? $itRaw['quantity'] ?? $itRaw['qty'] ?? 1);
                                        if ($qty < 1) { $qty = 1; }
                                    @endphp
                                    <span class="font-bold text-sm text-gray-900">x{{ $qty }}</span>
                                </td>

                                <td class="px-4 py-3 align-top">
                                    @php
                                        $unitPrice    = $it['item_price'] ?? $it['paid_price'] ?? null;
                                        $unitCurrency = ($it['raw']['currency'] ?? null) ?: ($raw['currency'] ?? '₱');
                                    @endphp
                                    <span class="font-bold text-sm text-gray-900">
                                        @if($unitPrice !== null && $unitPrice !== '')
                                            {{ is_numeric($unitPrice) ? $unitCurrency . number_format((float)$unitPrice, 2) : $unitPrice }}
                                        @else
                                            ---
                                        @endif
                                    </span>
                                </td>

                                @if($idx === 0)
                                <td rowspan="{{ $lineCount }}" class="px-4 py-3 align-top">
                                    <div class="font-bold text-sm text-gray-900">
                                        @if($totalAmount !== null && $totalAmount !== '')
                                            {{ is_numeric($totalAmount) ? $currency . number_format((float)$totalAmount, 2) : $totalAmount }}
                                        @else
                                            ---
                                        @endif
                                    </div>
                                    @if($paymentBadge)
                                        <div class="mt-1.5">
                                            <span class="inline-flex items-center rounded-full bg-gray-100 text-gray-700 px-2 py-0.5 text-xs font-medium">{{ $paymentBadge }}</span>
                                        </div>
                                    @endif
                                </td>

                                <td rowspan="{{ $lineCount }}" class="px-4 py-3 align-top">
                                    <div class="font-semibold text-sm text-gray-900">{{ $courier ?: '---' }}</div>
                                    @if($deliveryMethod)
                                        <div class="text-xs text-gray-500 mt-1">{{ $deliveryMethod }}</div>
                                    @endif
                                    @if($trackingNo)
                                        <div class="text-xs text-gray-500 mt-1">
                                            Tracking: <span class="text-gray-700 font-medium">{{ $trackingNo }}</span>
                                        </div>
                                    @endif
                                </td>

                                <td rowspan="{{ $lineCount }}" class="px-4 py-3 align-top">
                                    @if($statusStr !== '')
                                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusColor }}">
                                            {{ $statusStr }}
                                        </span>
                                    @else
                                        <span class="text-gray-400">---</span>
                                    @endif
                                    @if($sla)
                                        <div class="mt-1.5">
                                            <span class="inline-flex items-center rounded-full bg-amber-50 text-amber-700 border border-amber-200 px-2 py-0.5 text-xs font-medium">
                                                {{ $sla }}
                                            </span>
                                        </div>
                                    @endif
                                </td>

                                <td rowspan="{{ $lineCount }}" class="px-4 py-3 align-top">
                                    @if(!$orderId)
                                        <span class="text-gray-400">N/A</span>
                                    @elseif($isUnpaid)
                                        <span class="text-gray-400">---</span>
                                    @else
                                        <div class="flex gap-2 flex-wrap items-center">
                                            {{-- TO PACK (pending): only Pack & Print --}}
                                            @if($isToPack)
                                                <form method="POST" action="{{ route('lazada.orders.pack_print', ['orderId' => $orderId]) }}"
                                                      onsubmit="return confirm('Pack this order now? This will create packages and make AWB available.');">
                                                    @csrf
                                                    <button type="submit"
                                                            class="bg-[#0F146D] hover:bg-[#0b1058] text-white px-3 py-1.5 rounded-lg text-xs font-medium transition">
                                                        Pack & Print
                                                    </button>
                                                </form>
                                            @endif

                                            {{-- TO ARRANGE (packed): Print + Arrange Shipment + Recreate Package --}}
                                            @if($isToArrange)
                                                <a href="{{ route('lazada.orders.awb', ['orderId' => $orderId]) }}"
                                                   onclick="window.open(this.href, 'awb', 'width=900,height=700'); return false;"
                                                   class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-1.5 rounded-lg text-xs font-medium transition inline-flex items-center gap-1">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2z"/></svg>
                                                    Print AWB
                                                </a>

                                                <form method="POST" action="{{ route('lazada.orders.rts', ['orderId' => $orderId]) }}"
                                                      onsubmit="return confirm('Arrange Shipment (Ready To Ship) for this order?');">
                                                    @csrf
                                                    <button type="submit"
                                                            class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium transition">
                                                        Arrange Shipment
                                                    </button>
                                                </form>

                                                <form method="POST" action="{{ route('lazada.orders.recreate_package', ['orderId' => $orderId]) }}"
                                                      onsubmit="return confirm('Recreate Package (local)? Lazada may still keep the original package. Continue?');">
                                                    @csrf
                                                    <button type="submit"
                                                            class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-1.5 rounded-lg text-xs font-medium transition">
                                                        Recreate Package
                                                    </button>
                                                </form>
                                            @endif

                                            {{-- TO HANDOVER (ready_to_ship): Print only --}}
                                            @if($isToHandover)
                                                <a href="{{ route('lazada.orders.awb', ['orderId' => $orderId]) }}"
                                                   onclick="window.open(this.href, 'awb', 'width=900,height=700'); return false;"
                                                   class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-1.5 rounded-lg text-xs font-medium transition inline-flex items-center gap-1">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2z"/></svg>
                                                    Print AWB
                                                </a>
                                            @endif

                                            {{-- Post-fulfilment: only show AWB if a local copy exists --}}
                                            @if(!$isToPack && !$isToArrange && !$isToHandover && !$isCancelled && ($savedAwbs[$orderId] ?? false))
                                                <a href="{{ route('lazada.orders.awb', ['orderId' => $orderId]) }}"
                                                   onclick="window.open(this.href, 'awb', 'width=900,height=700'); return false;"
                                                   class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-1.5 rounded-lg text-xs font-medium transition inline-flex items-center gap-1">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2z"/></svg>
                                                    Print AWB
                                                </a>
                                            @endif
                                        </div>

                                        {{-- Quick-links row --}}
                                        @if(!$isCancelled)
                                            @php $hasAwb = ($savedAwbs[$orderId] ?? false); @endphp
                                            <div class="flex gap-2 flex-wrap text-xs mt-2 items-center">
                                                @if(!$isToPack)
                                                    <span class="text-gray-400">Print:</span>
                                                    @if($hasAwb)
                                                        <a href="{{ route('lazada.orders.awb', ['orderId' => $orderId]) }}" target="_blank"
                                                           class="text-[#0F146D] hover:underline">AWB</a>
                                                        <span class="text-gray-300">|</span>
                                                    @endif
                                                    <a href="{{ route('lazada.orders.packing_list', ['orderId' => $orderId]) }}" target="_blank"
                                                       class="text-[#0F146D] hover:underline">Packing List</a>
                                                    <span class="text-gray-300">|</span>
                                                    <a href="{{ route('lazada.orders.pick_list', ['orderId' => $orderId]) }}" target="_blank"
                                                       class="text-[#0F146D] hover:underline">Pick List</a>
                                                    <span class="text-gray-300">|</span>
                                                    <button type="button"
                                                            class="btnLzLogistics text-[#0F146D] hover:underline bg-transparent border-0 p-0 cursor-pointer text-xs"
                                                            data-url="{{ route('lazada.orders.logistics_trace', ['orderId' => $orderId]) }}">
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

    {{-- Pagination --}}
    <div class="px-5 py-3 border-t border-gray-100">
        {{ $orders->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>

{{-- Loading overlay --}}
<div id="lzLoadingOverlay"
     class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden"
     style="display:none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 p-6">
        <div class="flex items-center gap-4">
            <div class="w-5 h-5 border-[3px] border-gray-200 border-t-[#0F146D] rounded-full animate-spin flex-shrink-0"></div>
            <div>
                <div id="lzLoadingTitle" class="font-bold text-gray-900">Fetching orders...</div>
                <div class="text-xs text-gray-500 mt-0.5">Please keep this tab open. This may take a while depending on Lazada API response.</div>
            </div>
        </div>
    </div>
</div>

{{-- Pack & Print follow-up modal --}}
<div id="lzPackPrintModal"
     class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden"
     style="display:none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-4 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <div>
                <h3 class="text-lg font-bold text-gray-900">Pack & Print</h3>
                <div class="text-xs text-gray-500 mt-0.5">Choose what to do next. If you close this, the order stays in <strong>To Arrange</strong>.</div>
            </div>
            <button type="button" id="btnClosePackPrint"
                    class="text-gray-400 hover:text-gray-600 transition-colors text-2xl leading-none">&times;</button>
        </div>
        <div class="flex gap-3 flex-wrap justify-end px-6 py-4">
            <form method="POST" id="formRecreatePackage" action="#">
                @csrf
                <button type="submit"
                        class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium transition">
                    Recreate Package
                </button>
            </form>
            <form method="POST" id="formShipPrint" action="#">
                @csrf
                <button type="submit"
                        class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                    Ship & Print
                </button>
            </form>
            <a href="#" id="linkPrintOnly" target="_blank"
               class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium transition inline-flex items-center">
                Print Only
            </a>
        </div>
    </div>
</div>

{{-- Logistics status modal --}}
<div id="lzLogisticsModal"
     class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden"
     style="display:none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-3xl w-full mx-4 overflow-hidden max-h-[90vh] flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 flex-shrink-0">
            <div>
                <h3 class="text-lg font-bold text-gray-900">Logistics Status</h3>
                <div id="lzLogisticsSub" class="text-xs text-gray-500 mt-0.5"></div>
            </div>
            <button type="button" id="btnCloseLogistics"
                    class="text-gray-400 hover:text-gray-600 transition-colors text-2xl leading-none">&times;</button>
        </div>
        <div id="lzLogisticsBody" class="text-xs px-6 py-4 overflow-y-auto flex-1">
            <div class="text-gray-500">Loading...</div>
        </div>
        <div class="flex gap-3 justify-end px-6 py-4 border-t border-gray-100 flex-shrink-0">
            <button type="button" id="btnLogisticsClose2"
                    class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium transition">
                Close
            </button>
        </div>
    </div>
</div>

{{-- Image preview tooltip --}}
<div class="order-img-preview fixed z-[60] pointer-events-none opacity-0 transition-opacity duration-150" style="display:none;">
    <img src="" alt="" class="w-52 h-52 object-cover rounded-xl shadow-2xl border border-gray-200">
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Helpers: show/hide modals
    function showModal(el) {
        if (!el) return;
        el.style.display = '';
        el.classList.remove('hidden');
    }
    function hideModal(el) {
        if (!el) return;
        el.classList.add('hidden');
        el.style.display = 'none';
    }

    // Auto-open AWB from session
    var awbUrlToOpen = @json(session('lazada_awb_url'));
    if (awbUrlToOpen) {
        try { window.open(String(awbUrlToOpen), '_blank'); } catch (e) {}
    }

    // Show loading overlay on fetch/update
    var fetchForm  = document.getElementById('formFetchLazadaOrders');
    var fetchBtn   = document.getElementById('btnFetchOrders');
    var updateBtn  = document.getElementById('btnUpdateOrders');
    var clickedBtn = null;

    if (fetchBtn)  fetchBtn.addEventListener('click',  function () { clickedBtn = 'fetch'; });
    if (updateBtn) updateBtn.addEventListener('click', function () { clickedBtn = 'update'; });

    if (fetchForm) {
        fetchForm.addEventListener('submit', function (e) {
            var dateFrom = fetchForm.querySelector('input[name="date_from"]');
            var dateTo   = fetchForm.querySelector('input[name="date_to"]');
            if (!dateFrom.value || !dateTo.value) {
                e.preventDefault();
                alert('Please provide both From and To dates.');
                return;
            }
            var ov = document.getElementById('lzLoadingOverlay');
            var tt = document.getElementById('lzLoadingTitle');
            if (ov) {
                if (tt) tt.textContent = (clickedBtn === 'update') ? 'Updating orders...' : 'Fetching orders...';
                showModal(ov);
            }
        });
    }

    // Pack & Print follow-up modal
    var openPackPrintOrderId = @json(session('open_pack_print_modal_order_id'));
    var packPrintModal = document.getElementById('lzPackPrintModal');
    var closePackPrint  = document.getElementById('btnClosePackPrint');
    var linkPrintOnly   = document.getElementById('linkPrintOnly');
    var formShipPrint   = document.getElementById('formShipPrint');
    var formRecreate    = document.getElementById('formRecreatePackage');

    function showPackPrintModal(orderId) {
        if (!packPrintModal) return;
        var awbTpl      = @json(route('lazada.orders.awb', ['orderId' => '__OID__']));
        var shipTpl     = @json(route('lazada.orders.ship_print_post', ['orderId' => '__OID__']));
        var recreateTpl = @json(route('lazada.orders.recreate_package', ['orderId' => '__OID__']));

        if (linkPrintOnly) linkPrintOnly.href  = awbTpl.replace('__OID__', orderId);
        if (formShipPrint) formShipPrint.action = shipTpl.replace('__OID__', orderId);
        if (formRecreate)  formRecreate.action  = recreateTpl.replace('__OID__', orderId);

        showModal(packPrintModal);
    }

    function hidePackPrintModal() { hideModal(packPrintModal); }

    if (closePackPrint) closePackPrint.addEventListener('click', hidePackPrintModal);
    if (packPrintModal) {
        packPrintModal.addEventListener('click', function (e) {
            if (e.target === packPrintModal) hidePackPrintModal();
        });
    }
    if (openPackPrintOrderId) {
        showPackPrintModal(String(openPackPrintOrderId));
    }

    // Logistics trace modal
    var logisticsModal  = document.getElementById('lzLogisticsModal');
    var logisticsBody   = document.getElementById('lzLogisticsBody');
    var logisticsSub    = document.getElementById('lzLogisticsSub');
    var closeLogistics  = document.getElementById('btnCloseLogistics');
    var closeLogistics2 = document.getElementById('btnLogisticsClose2');

    function showLogisticsModal() { showModal(logisticsModal); }
    function hideLogisticsModal() { hideModal(logisticsModal); }

    if (closeLogistics)  closeLogistics.addEventListener('click',  hideLogisticsModal);
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
        if (typeof t === 'number' && t > 1000000000000) {
            var d = new Date(t);
            return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0')
                + ' ' + String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0') + ':' + String(d.getSeconds()).padStart(2,'0');
        }
        if (typeof t === 'number' && t > 1000000000) {
            return formatEventTime(t * 1000);
        }
        return String(t);
    }

    function renderTrace(body) {
        var data = (body && body.data) ? body.data : body;
        var traces = [];
        if (data) {
            traces = data.traces || data.trace_list || data.traceList || data.events || [];
            if ((!Array.isArray(traces) || traces.length === 0) && Array.isArray(data.packages)) {
                for (var pi = 0; pi < data.packages.length; pi++) {
                    var ptl = data.packages[pi].package_trace_list || data.packages[pi].traceList || [];
                    if (Array.isArray(ptl) && ptl.length > 0) {
                        traces = traces.concat(ptl);
                    }
                }
            }
            if ((!Array.isArray(traces) || traces.length === 0) && data.result && Array.isArray(data.result.module)) {
                for (var mi = 0; mi < data.result.module.length; mi++) {
                    var pkgs = data.result.module[mi].package_detail_info_list || [];
                    for (var pj = 0; pj < pkgs.length; pj++) {
                        var events = pkgs[pj].logistic_detail_info_list || [];
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
            logisticsBody.textContent = 'No logistics events returned by Lazada for this order.';
            return;
        }

        // Build table using DOM methods
        var container = document.createElement('div');
        container.className = 'overflow-x-auto';

        var table = document.createElement('table');
        table.className = 'min-w-full divide-y divide-gray-200 text-sm';

        var thead = document.createElement('thead');
        thead.className = 'bg-gray-50';
        var headRow = document.createElement('tr');
        ['Time', 'Status', 'Details'].forEach(function(label) {
            var th = document.createElement('th');
            th.className = 'px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase';
            th.textContent = label;
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);
        table.appendChild(thead);

        var tbody = document.createElement('tbody');
        tbody.className = 'divide-y divide-gray-100';

        traces.forEach(function (t) {
            var time   = t.event_time || t.time || t.timestamp || t.update_time || '';
            var status = t.title || t.status || t.event || t.action || '';
            var detail = t.description || t.desc || t.detail || t.message || '';

            var tr = document.createElement('tr');

            var tdTime = document.createElement('td');
            tdTime.className = 'px-4 py-2 text-gray-600 whitespace-nowrap';
            tdTime.textContent = formatEventTime(time);
            tr.appendChild(tdTime);

            var tdStatus = document.createElement('td');
            tdStatus.className = 'px-4 py-2 font-bold text-gray-900';
            tdStatus.textContent = status;
            tr.appendChild(tdStatus);

            var tdDetail = document.createElement('td');
            tdDetail.className = 'px-4 py-2 text-gray-600';
            tdDetail.textContent = detail;
            tr.appendChild(tdDetail);

            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        container.appendChild(table);

        logisticsBody.replaceChildren(container);
    }

    async function fetchLogistics(url) {
        if (!logisticsBody) return;
        logisticsBody.textContent = 'Loading...';
        try {
            var r = await fetch(url, { headers: { 'Accept': 'application/json' } });
            var j = await r.json();
            if (!r.ok || !j || j.ok !== true) {
                var msg = (j && j.body && j.body.message) ? j.body.message : ((j && j.message) ? j.message : 'Failed to fetch logistics trace');
                throw new Error(msg);
            }
            renderTrace(j.body);
        } catch (e) {
            var errDiv = document.createElement('div');
            errDiv.className = 'text-red-600';
            errDiv.textContent = (e && e.message) ? e.message : 'Failed to fetch logistics trace';
            logisticsBody.replaceChildren(errDiv);
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
    var preview    = document.querySelector('.order-img-preview');
    if (!preview) return;
    var previewImg = preview.querySelector('img');
    var hideTimer  = null;

    document.addEventListener('mouseenter', function (e) {
        var wrap = e.target.closest('.order-img-wrap');
        if (!wrap) return;
        var img = wrap.querySelector('img.order-img');
        if (!img || !img.src) return;
        clearTimeout(hideTimer);
        previewImg.src = img.src;
        preview.style.display = '';
        var rect = wrap.getBoundingClientRect();
        var top  = rect.top + rect.height / 2 - 110;
        var left = rect.right + 12;
        if (left + 230 > window.innerWidth) left = rect.left - 232;
        if (top + 220 > window.innerHeight) top  = window.innerHeight - 224;
        if (top < 4) top = 4;
        preview.style.top  = top + 'px';
        preview.style.left = left + 'px';
        preview.style.opacity = '1';
    }, true);

    document.addEventListener('mouseleave', function (e) {
        var wrap = e.target.closest('.order-img-wrap');
        if (!wrap) return;
        hideTimer = setTimeout(function () {
            preview.style.opacity = '0';
            setTimeout(function () { preview.style.display = 'none'; }, 150);
        }, 80);
    }, true);
})();
</script>
@endpush

@endsection
