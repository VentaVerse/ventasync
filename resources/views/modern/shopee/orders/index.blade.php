@extends('layouts.modern.app')
@section('breadcrumb', 'Marketplace / Shopee / Orders')

@section('content')
@php
    $tabs       = $tabs ?? [];
    $active_tab = $active_tab ?? 'ALL';
    $tab_counts = $tab_counts ?? [];
    $pending_sub_counts = $pending_sub_counts ?? [];
    $filters    = $filters ?? [];
@endphp

{{-- Page header --}}
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <h1 class="text-2xl font-bold text-gray-900">
        Shopee Orders
        <span class="text-gray-400 text-base font-normal ml-1">({{ method_exists($orders,'total') ? $orders->total() : $orders->count() }})</span>
    </h1>
</div>

{{-- Fetch / Update form --}}
<div class="bg-white rounded-xl shadow-sm border p-4 mb-6">
    <form method="POST" action="{{ route('shopee.orders.fetch') }}" id="formFetchShopeeOrders"
          class="flex gap-3 flex-wrap items-end">
        @csrf
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">From</label>
            <input type="date" name="date_from" value="{{ old('date_from', request('date_from')) }}"
                   class="border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-[#EE4D2D]/30 focus:border-[#EE4D2D]">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">To</label>
            <input type="date" name="date_to" value="{{ old('date_to', request('date_to')) }}"
                   class="border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-[#EE4D2D]/30 focus:border-[#EE4D2D]">
        </div>
        <div class="flex items-center">
            <label class="inline-flex items-center gap-1.5 text-xs text-gray-500 cursor-pointer select-none">
                <input type="checkbox" name="no_stock" value="1"
                       class="rounded border-gray-300 text-[#EE4D2D] focus:ring-[#EE4D2D]">
                Skip stock adjust
            </label>
        </div>
        <div class="flex gap-2">
            <button type="submit" id="btnFetchOrders"
                    class="bg-[#EE4D2D] hover:bg-[#d4431f] text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                Fetch Orders
            </button>
            <button type="submit" id="btnUpdateOrders" formaction="{{ route('shopee.orders.update_statuses') }}"
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
            $showBadge = in_array($key, ['PENDING', 'SHIPPING', 'FAILED_DELIVERY']);
        @endphp
        <a href="{{ route('shopee.orders.index', $qs) }}"
           class="relative px-4 py-2.5 text-sm font-medium whitespace-nowrap transition-colors
                  {{ $isActive
                      ? 'border-b-2 border-[#EE4D2D] text-[#EE4D2D] font-semibold'
                      : 'text-gray-500 hover:text-gray-700' }}">
            <span>{{ $label }}</span>
            @if($showBadge && $count !== null && $count > 0)
                <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-xs font-bold ml-1.5
                    {{ $isActive ? 'bg-white text-[#EE4D2D] ring-1 ring-[#EE4D2D]/20' : 'bg-[#EE4D2D] text-white' }}">{{ $count }}</span>
            @endif
        </a>
    @endforeach
    <a href="{{ route('shopee.orders.returns') }}"
       class="px-4 py-2.5 text-sm font-medium whitespace-nowrap text-gray-500 hover:text-gray-700 transition-colors">
        Return/Refund/Cancel
    </a>
</div>

{{-- PENDING (To Ship) sub-tabs: 2 steps only --}}
@if(strtoupper((string)($active_tab ?? 'ALL')) === 'PENDING')
    @php
        $ps = $pending_subtab ?? request()->query('pending_sub', 'to_pack');
        $pendingNav = [
            'to_pack'     => 'To Pack',
            'to_handover' => 'To Handover',
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
                    $qs    = array_merge(request()->except('page'), ['tab' => 'PENDING', 'pending_sub' => $k]);
                    $count = $pending_sub_counts[$k] ?? null;
                @endphp
                <a href="{{ route('shopee.orders.index', $qs) }}"
                   class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors
                          {{ $isActive
                              ? 'bg-[#EE4D2D] text-white'
                              : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    <span>{{ $lbl }}</span>
                    @if($count !== null && $count > 0)
                        <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-xs font-bold ml-1
                            {{ $isActive ? 'bg-white text-[#EE4D2D]' : 'bg-[#EE4D2D] text-white' }}">{{ $count }}</span>
                    @endif
                </a>
            @endforeach
        </div>

        @if(!empty($pending_courier_counts))
            <div class="mt-3 pt-3 border-t border-gray-100">
                <div class="text-xs text-gray-400 mb-2">Courier tally (To Handover only)</div>
                <div class="flex gap-2 flex-wrap">
                    @foreach($pending_courier_counts as $courier => $count)
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

{{-- Search / filter bar --}}
<div class="bg-white rounded-xl shadow-sm border p-4 mb-4">
    <form method="GET" action="{{ route('shopee.orders.index') }}" class="flex gap-3 items-end flex-wrap">
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
                       placeholder="Order SN"
                       class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#EE4D2D]/30 focus:border-[#EE4D2D]">
            </div>
        </div>

        <div class="min-w-[180px]">
            <label class="block text-xs font-medium text-gray-500 mb-1">Buyer Name</label>
            <input type="text" name="buyer_name"
                   value="{{ old('buyer_name', $filters['buyer_name'] ?? request()->query('buyer_name', '')) }}"
                   placeholder="Buyer"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#EE4D2D]/30 focus:border-[#EE4D2D]">
        </div>

        <div class="min-w-[220px]">
            <label class="block text-xs font-medium text-gray-500 mb-1">Sort by</label>
            <select name="sort"
                    class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-[#EE4D2D]/30 focus:border-[#EE4D2D]">
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

        <div class="min-w-[90px]">
            <label class="block text-xs font-medium text-gray-500 mb-1">Per page</label>
            <select name="per_page" onchange="this.form.submit()"
                    class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-[#EE4D2D]/30 focus:border-[#EE4D2D]">
                <option value="10" {{ (int)($per_page ?? 10)===10 ? 'selected' : '' }}>10</option>
                <option value="20" {{ (int)($per_page ?? 10)===20 ? 'selected' : '' }}>20</option>
                <option value="50" {{ (int)($per_page ?? 10)===50 ? 'selected' : '' }}>50</option>
            </select>
        </div>

        <div class="flex gap-2">
            <button type="submit"
                    class="bg-[#EE4D2D] hover:bg-[#d4431f] text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                Search
            </button>
            <a href="{{ route('shopee.orders.index', ['tab' => ($active_tab ?? 'ALL'), 'per_page' => (int)($per_page ?? 10)]) }}"
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
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider" style="min-width:360px;">Products</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">Qty</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32">Product Price</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Shipping</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider" style="width:280px;">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
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

                            // Status badge color mapping (Shopee palette)
                            $statusColor = match(true) {
                                $isUnpaid        => 'bg-yellow-100 text-yellow-800',
                                $isCancelled     => 'bg-red-100 text-red-800',
                                $isToPack        => 'bg-orange-100 text-orange-800',
                                $isToHandover    => 'bg-indigo-100 text-indigo-800',
                                $isShipped       => 'bg-cyan-100 text-cyan-800',
                                $isFailedDelivery => 'bg-rose-100 text-rose-800',
                                $statusNorm === 'COMPLETED' => 'bg-green-100 text-green-800',
                                $statusNorm === 'TO_RETURN' => 'bg-purple-100 text-purple-800',
                                default          => 'bg-gray-100 text-gray-800',
                            };
                        @endphp

                        {{-- Order header row --}}
                        @if($orderSn)
                            <tr class="bg-gray-50/50">
                                <td colspan="7" class="px-4 py-2.5">
                                    <div class="flex justify-between items-start gap-4">
                                        <div class="text-xs text-gray-500 leading-relaxed">
                                            <span>Buyer:</span>
                                            <span class="font-semibold text-gray-900">{{ $buyer !== '' ? $buyer : '---' }}</span>
                                            <span class="ml-3">Order SN:</span>
                                            <span class="font-semibold text-gray-900">{{ $orderSn }}</span>
                                            @if($itemCount > 0)
                                                <span class="ml-2 text-gray-400">({{ $itemCount }} {{ $itemCount === 1 ? 'item' : 'items' }})</span>
                                            @endif
                                        </div>
                                        <div class="text-xs text-gray-500 text-right leading-relaxed whitespace-nowrap">
                                            @if($created)
                                                <span>Create Time:</span>
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
                                <td class="px-4 py-3 align-top">
                                    @php
                                        $itRaw     = $it['raw'] ?? $it;
                                        $name      = (string)($it['name'] ?? $itRaw['item_name'] ?? '');
                                        $sku       = (string)($it['sku'] ?? $itRaw['item_sku'] ?? $itRaw['model_sku'] ?? '');
                                        $variation = (string)($it['variation'] ?? $itRaw['model_name'] ?? '');
                                        $img       = (string)($it['image'] ?? $itRaw['image_info']['image_url'] ?? '');
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
                                                @if($orderSn)
                                                    <a href="{{ route('shopee.orders.show', ['orderSn' => $orderSn]) }}"
                                                       target="_blank" rel="noopener"
                                                       class="hover:text-[#EE4D2D] underline underline-offset-2 decoration-gray-300 hover:decoration-[#EE4D2D] transition-colors">
                                                        {{ $name !== '' ? $name : '---' }}
                                                    </a>
                                                @else
                                                    {{ $name !== '' ? $name : '---' }}
                                                @endif
                                            </div>
                                            <div class="text-xs mt-1 text-gray-500">
                                                SKU: <strong class="text-gray-700">{{ $sku !== '' ? $sku : '---' }}</strong>
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
                                        $qty = max(1, (int)($it['quantity'] ?? 1));
                                    @endphp
                                    <span class="font-bold text-sm text-gray-900">x{{ $qty }}</span>
                                </td>

                                <td class="px-4 py-3 align-top">
                                    @php
                                        $unitPrice = $it['price'] ?? null;
                                    @endphp
                                    <span class="font-bold text-sm text-gray-900">
                                        @if($unitPrice !== null && $unitPrice !== '')
                                            {{ is_numeric($unitPrice) ? $currency . number_format((float)$unitPrice, 2) : $unitPrice }}
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
                                    @if($paymentMethod)
                                        <div class="mt-1.5">
                                            <span class="inline-flex items-center rounded-full bg-gray-100 text-gray-700 px-2 py-0.5 text-xs font-medium">{{ $paymentMethod }}</span>
                                        </div>
                                    @endif
                                </td>

                                <td rowspan="{{ $lineCount }}" class="px-4 py-3 align-top">
                                    <div class="font-semibold text-sm text-gray-900">{{ $courier ?: '---' }}</div>
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
                                </td>

                                <td rowspan="{{ $lineCount }}" class="px-4 py-3 align-top">
                                    @if(!$orderSn)
                                        <span class="text-gray-400">N/A</span>
                                    @elseif($isUnpaid)
                                        <span class="text-gray-400">---</span>
                                    @else
                                        <div class="flex gap-2 flex-wrap items-center">
                                            @if($isToPack)
                                                <button type="button"
                                                        class="btnArrangeShipment bg-[#EE4D2D] hover:bg-[#d4431f] text-white px-3 py-1.5 rounded-lg text-xs font-medium transition"
                                                        data-order-sn="{{ $orderSn }}">
                                                    Arrange Shipment
                                                </button>
                                            @endif

                                            @if(!$isToPack)
                                                <a href="{{ route('shopee.orders.awb', ['orderSn' => $orderSn]) }}"
                                                   onclick="window.open(this.href, 'awb', 'width=900,height=700'); return false;"
                                                   class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-1.5 rounded-lg text-xs font-medium transition inline-flex items-center gap-1">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2z"/></svg>
                                                    Print AWB
                                                </a>
                                            @endif

                                            @if($isToHandover || $isShipped)
                                                <button type="button"
                                                        class="btnShopeeTracking bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-1.5 rounded-lg text-xs font-medium transition"
                                                        data-url="{{ route('shopee.orders.tracking', ['orderSn' => $orderSn]) }}">
                                                    Tracking #
                                                </button>
                                            @endif
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

    {{-- Pagination --}}
    <div class="px-5 py-3 border-t border-gray-100">
        {{ $orders->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>

{{-- Ship method modal --}}
<div id="spShipModal"
     class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden"
     style="display:none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-4 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-bold text-gray-900">Arrange Shipment</h3>
            <button type="button" id="btnCloseShip"
                    class="text-gray-400 hover:text-gray-600 transition-colors text-2xl leading-none">&times;</button>
        </div>
        <div class="px-6 py-4">
            <div class="text-sm text-gray-500">Order: <strong id="spShipOrderSn" class="text-gray-900"></strong></div>

            {{-- Step 1: Choose shipping type --}}
            <div id="shipStep1">
                <div class="text-sm mt-3 text-gray-700">How will this order be shipped?</div>
                <div class="flex gap-3 mt-4">
                    <button type="button" class="btnShipChoice flex-1 bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2.5 rounded-lg text-sm font-medium transition inline-flex items-center justify-center gap-2" data-type="dropoff">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Drop Off
                    </button>
                    <button type="button" class="btnShipChoice flex-1 bg-[#EE4D2D] hover:bg-[#d4431f] text-white px-4 py-2.5 rounded-lg text-sm font-medium transition inline-flex items-center justify-center gap-2" data-type="pickup">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                        Pickup
                    </button>
                </div>
            </div>

            {{-- Step 2: Address/branch selection --}}
            <div id="shipStep2" style="display:none;">
                <div id="shipStep2Loading" class="flex items-center gap-3 mt-4">
                    <div class="w-4 h-4 border-[3px] border-gray-200 border-t-[#EE4D2D] rounded-full animate-spin"></div>
                    <span class="text-sm text-gray-500">Loading addresses...</span>
                </div>
                <div id="shipStep2Content" style="display:none;" class="mt-4"></div>
                <div class="flex gap-3 mt-4">
                    <button type="button" id="btnShipBack"
                            class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium transition">
                        &larr; Back
                    </button>
                    <button type="button" id="btnShipConfirm"
                            class="flex-1 bg-[#EE4D2D] hover:bg-[#d4431f] text-white px-4 py-2 rounded-lg text-sm font-medium transition disabled:opacity-50 disabled:cursor-not-allowed"
                            disabled>
                        Confirm & Ship
                    </button>
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
</div>

{{-- Loading overlay --}}
<div id="spLoadingOverlay"
     class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden"
     style="display:none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 p-6">
        <div class="flex items-center gap-4">
            <div class="w-5 h-5 border-[3px] border-gray-200 border-t-[#EE4D2D] rounded-full animate-spin flex-shrink-0"></div>
            <div>
                <div id="spLoadingTitle" class="font-bold text-gray-900">Fetching orders...</div>
                <div class="text-xs text-gray-500 mt-0.5">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

{{-- Tracking modal --}}
<div id="spTrackingModal"
     class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden"
     style="display:none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-4 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-bold text-gray-900">Tracking Number</h3>
            <button type="button" id="btnCloseTracking"
                    class="text-gray-400 hover:text-gray-600 transition-colors text-2xl leading-none">&times;</button>
        </div>
        <div id="spTrackingBody" class="px-6 py-4">
            <div class="text-gray-500">Loading...</div>
        </div>
        <div class="flex gap-3 justify-end px-6 py-4 border-t border-gray-100">
            <button type="button" id="btnTrackingClose2"
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
                alert('Please provide both From and To dates.');
                return;
            }
            if (overlay) {
                document.getElementById('spLoadingTitle').textContent = (clickedBtn === 'update') ? 'Updating orders...' : 'Fetching orders...';
                showModal(overlay);
            }
        });
    }

    var fetchReturnsForm = document.getElementById('formFetchReturns');
    if (fetchReturnsForm && overlay) {
        fetchReturnsForm.addEventListener('submit', function () {
            setTimeout(function () {
                showModal(overlay);
                document.getElementById('spLoadingTitle').textContent = 'Fetching returns...';
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
        shipStep2Content.textContent = '';
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
            showModal(shipModal);
        });
    });

    function renderAddressContent(data) {
        // Clear previous content
        while (shipStep2Content.firstChild) {
            shipStep2Content.removeChild(shipStep2Content.firstChild);
        }

        if (!data.ok) {
            var errDiv = document.createElement('div');
            errDiv.className = 'rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm';
            errDiv.textContent = data.message || 'Failed to load addresses.';
            shipStep2Content.appendChild(errDiv);
            return;
        }

        if (currentShipType === 'pickup') {
            var addresses = (data.pickup && data.pickup.address_list) ? data.pickup.address_list : [];
            if (addresses.length === 0) {
                var noAddr = document.createElement('div');
                noAddr.className = 'text-gray-500 text-sm';
                noAddr.textContent = 'No pickup addresses available.';
                shipStep2Content.appendChild(noAddr);
                return;
            }

            // Auto-select: find the address flagged as pickup_address
            var autoAddr = null;
            addresses.forEach(function (addr) {
                if (!autoAddr && (addr.address_flag || []).indexOf('pickup_address') !== -1) {
                    autoAddr = addr;
                }
            });
            if (!autoAddr) autoAddr = addresses[0];

            shipAddressInput.value = autoAddr.address_id || 0;
            var addrParts = [autoAddr.address, autoAddr.city, autoAddr.state, autoAddr.district].filter(function(p) { return p && p !== ''; });
            var flags = (autoAddr.address_flag || []);

            var titleDiv = document.createElement('div');
            titleDiv.className = 'text-sm font-bold text-gray-900 mb-2';
            titleDiv.textContent = 'Pickup address (auto-selected):';
            shipStep2Content.appendChild(titleDiv);

            var addrBox = document.createElement('div');
            addrBox.className = 'p-3 border-2 border-green-500 rounded-lg bg-green-50/50';

            var addrText = document.createElement('div');
            addrText.className = 'text-sm text-gray-700';
            addrText.textContent = addrParts.join(', ');
            addrBox.appendChild(addrText);

            if (flags.length > 0) {
                var flagsWrap = document.createElement('div');
                flagsWrap.className = 'mt-1 flex gap-1 flex-wrap';
                flags.forEach(function (f) {
                    var flagSpan = document.createElement('span');
                    var colorClass = f === 'pickup_address' ? 'bg-green-100 text-green-700' : (f === 'return_address' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600');
                    flagSpan.className = 'inline-block text-xs px-1.5 py-0.5 rounded ' + colorClass;
                    flagSpan.textContent = f.replace(/_/g, ' ');
                    flagsWrap.appendChild(flagSpan);
                });
                addrBox.appendChild(flagsWrap);
            }

            shipStep2Content.appendChild(addrBox);
        } else {
            var branches = (data.dropoff && data.dropoff.branch_list) ? data.dropoff.branch_list : [];
            if (branches.length === 0) {
                var noBranch = document.createElement('div');
                noBranch.className = 'text-gray-500 text-sm';
                noBranch.textContent = 'No drop-off branches available. The order will be shipped without branch selection.';
                shipStep2Content.appendChild(noBranch);
                btnShipConfirm.disabled = false;
                return;
            }

            var branchTitle = document.createElement('div');
            branchTitle.className = 'text-sm font-bold text-gray-900 mb-2';
            branchTitle.textContent = 'Select drop-off branch:';
            shipStep2Content.appendChild(branchTitle);

            branches.forEach(function (branch, i) {
                var addrParts = [branch.address, branch.city, branch.state, branch.district].filter(function(p) { return p && p !== ''; });

                var label = document.createElement('label');
                label.className = 'block p-3 border-2 border-gray-200 rounded-lg cursor-pointer mb-2 hover:bg-gray-50 transition-colors ship-addr-label';

                var wrapper = document.createElement('div');
                wrapper.className = 'flex items-start gap-3';

                var radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = 'ship_branch';
                radio.value = branch.branch_id || 0;
                radio.className = 'mt-0.5 text-[#EE4D2D] focus:ring-[#EE4D2D]';
                if (i === 0) {
                    radio.checked = true;
                    shipBranchInput.value = branch.branch_id || 0;
                }

                radio.addEventListener('change', function () {
                    shipBranchInput.value = radio.value;
                });

                var contentDiv = document.createElement('div');
                contentDiv.className = 'flex-1';
                var addrDiv = document.createElement('div');
                addrDiv.className = 'text-sm text-gray-700';
                addrDiv.textContent = addrParts.join(', ');
                contentDiv.appendChild(addrDiv);

                wrapper.appendChild(radio);
                wrapper.appendChild(contentDiv);
                label.appendChild(wrapper);
                shipStep2Content.appendChild(label);
            });
        }

        btnShipConfirm.disabled = false;
    }

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
                renderAddressContent(data);
            })
            .catch(function () {
                shipStep2Loading.style.display = 'none';
                shipStep2Content.style.display = '';
                while (shipStep2Content.firstChild) {
                    shipStep2Content.removeChild(shipStep2Content.firstChild);
                }
                var errDiv = document.createElement('div');
                errDiv.className = 'rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm';
                errDiv.textContent = 'Network error loading addresses.';
                shipStep2Content.appendChild(errDiv);
            });
        });
    });

    btnShipBack.addEventListener('click', function () {
        shipStep1.style.display = '';
        shipStep2.style.display = 'none';
    });

    btnShipConfirm.addEventListener('click', function () {
        btnShipConfirm.disabled = true;
        hideModal(shipModal);

        if (overlay) {
            document.getElementById('spLoadingTitle').textContent = 'Shipping order...';
            showModal(overlay);
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
                hideModal(overlay);
                alert('Ship failed: ' + (data.message || 'Unknown error'));
                btnShipConfirm.disabled = false;
                return;
            }

            // Ship succeeded -- now poll for AWB
            if (overlay) {
                document.getElementById('spLoadingTitle').textContent = 'Generating AWB...';
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
                        hideModal(overlay);
                        window.open(awbUrl, '_blank');
                        location.reload();
                    } else if (awbAttempts < maxAttempts) {
                        setTimeout(pollAwb, 3000);
                    } else {
                        hideModal(overlay);
                        alert('Order shipped successfully but AWB is still being generated by Shopee. Use the Print AWB button to try again.');
                        location.reload();
                    }
                })
                .catch(function () {
                    if (awbAttempts < maxAttempts) {
                        setTimeout(pollAwb, 3000);
                    } else {
                        hideModal(overlay);
                        alert('Order shipped successfully but could not generate AWB. Use the Print AWB button to try again.');
                        location.reload();
                    }
                });
            }

            setTimeout(pollAwb, 2000);
        })
        .catch(function () {
            hideModal(overlay);
            alert('Network error while shipping order.');
            btnShipConfirm.disabled = false;
        });
    });

    var closeShip = function () { hideModal(shipModal); };
    var btnCloseShip = document.getElementById('btnCloseShip');
    if (btnCloseShip) btnCloseShip.addEventListener('click', closeShip);
    if (shipModal) shipModal.addEventListener('click', function (e) { if (e.target === shipModal) closeShip(); });

    // Tracking modal
    var trackingModal = document.getElementById('spTrackingModal');
    var trackingBody = document.getElementById('spTrackingBody');

    function showTrackingModal() { showModal(trackingModal); }
    function hideTrackingModal() { hideModal(trackingModal); }

    document.querySelectorAll('.btnShopeeTracking').forEach(function (el) {
        el.addEventListener('click', function () {
            var url = el.getAttribute('data-url');
            if (!url) return;
            trackingBody.textContent = 'Loading...';
            showTrackingModal();

            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    while (trackingBody.firstChild) {
                        trackingBody.removeChild(trackingBody.firstChild);
                    }
                    if (data.tracking_number) {
                        var trackDiv = document.createElement('div');
                        trackDiv.className = 'font-bold text-lg text-gray-900';
                        trackDiv.textContent = data.tracking_number;
                        trackingBody.appendChild(trackDiv);
                    } else {
                        var pre = document.createElement('pre');
                        pre.className = 'text-xs bg-gray-50 rounded-lg p-3 overflow-x-auto';
                        pre.textContent = JSON.stringify(data, null, 2);
                        trackingBody.appendChild(pre);
                    }
                })
                .catch(function (err) {
                    while (trackingBody.firstChild) {
                        trackingBody.removeChild(trackingBody.firstChild);
                    }
                    var errDiv = document.createElement('div');
                    errDiv.className = 'text-red-600 text-sm';
                    errDiv.textContent = 'Error: ' + err.message;
                    trackingBody.appendChild(errDiv);
                });
        });
    });

    var closeTracking = function () { hideTrackingModal(); };
    var btnCloseTracking = document.getElementById('btnCloseTracking');
    var btnTrackingClose2 = document.getElementById('btnTrackingClose2');
    if (btnCloseTracking) btnCloseTracking.addEventListener('click', closeTracking);
    if (btnTrackingClose2) btnTrackingClose2.addEventListener('click', closeTracking);
    if (trackingModal) trackingModal.addEventListener('click', function (e) { if (e.target === trackingModal) closeTracking(); });
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
