@php
    $panelHiddenParams = $panelHiddenParams ?? [];

    $panelSelfUrl = function (array $params = [], array $except = []) use ($panelBaseUrl, $panelHiddenParams) {
        $query = array_merge(request()->except($except), $params, $panelHiddenParams);

        return $query === [] ? $panelBaseUrl : $panelBaseUrl . '?' . \Illuminate\Support\Arr::query($query);
    };

    $panelUrl = function (array $params = []) use ($panelBaseUrl, $panelHiddenParams) {
        $query = array_merge($panelHiddenParams, $params);

        return $query === [] ? $panelBaseUrl : $panelBaseUrl . '?' . \Illuminate\Support\Arr::query($query);
    };

    $canManageLazadaOrders = $canManageLazadaOrders
        ?? (auth()->user()?->hasPermission('manage_lazada/order') ?? false);

    $tabs = $tabs ?? [];
    $active_tab = strtoupper((string) ($active_tab ?? 'ALL'));
    $tab_counts = $tab_counts ?? [];
    $pending_sub_counts = $pending_sub_counts ?? [];
    $fd_sub_counts = $fd_sub_counts ?? [];
    $savedAwbs = $savedAwbs ?? [];
    $live_statuses = $live_statuses ?? [];
    $perPage = (int) ($per_page ?? request()->query('per_page', 10));
    $findFilter = (string) ($filters['q'] ?? '');
    $placedFromFilter = (string) ($filters['placed_from'] ?? '');
    $placedToFilter = (string) ($filters['placed_to'] ?? '');
    $hasSearch = $findFilter !== '' || $placedFromFilter !== '' || $placedToFilter !== '';
    $pendingSub = $pendingSub ?? ($pending_subtab ?? request()->query('pending_sub', 'to_pack'));
    $fdSub = $fdSub ?? ($fd_subtab ?? request()->query('fd_sub', 'failed_delivery'));

    $pendingNav = $pendingNav ?? [
        'to_pack' => 'To Pack',
        'to_arrange' => 'To Arrange Shipment',
        'to_handover' => 'To Handover',
    ];

    $fdNav = $fdNav ?? [
        'failed_delivery' => 'Failed Delivery',
        'shipped_back' => 'Shipped Back',
        'lost_damaged' => 'Lost and Damaged',
    ];

    if (!isset($scopeLabel)) {
        $scopeLabel = null;
        if ($active_tab === 'TO_SHIP') {
            $scopeLabel = $pendingNav[$pendingSub] ?? ($tabs['TO_SHIP'] ?? null);
        } elseif ($active_tab === 'FAILED_DELIVERY') {
            $scopeLabel = $fdNav[$fdSub] ?? ($tabs['FAILED_DELIVERY'] ?? null);
        } elseif ($active_tab !== 'ALL') {
            $scopeLabel = $tabs[$active_tab] ?? null;
        }
    }

    $lzSortLabels = $lzSortLabels ?? [
        'created_desc'           => 'Order date, newest first',
        'created_asc'            => 'Order date, oldest first',
        'confirmed_created_desc' => 'Lazada create time, newest first',
        'confirmed_created_asc'  => 'Lazada create time, oldest first',
        'confirmed_updated_desc' => 'Last update, newest first',
        'confirmed_updated_asc'  => 'Last update, oldest first',
        'promised_shipping_asc'  => 'Ship-by time, soonest first',
        'promised_shipping_desc' => 'Ship-by time, latest first',
    ];
@endphp

@if($last_result)
    <div class="co-notice {{ $last_result['ok'] ? 'co-notice--ok' : 'co-notice--fail' }}" role="status" aria-live="polite">
        <span class="co-notice__title">{{ $last_result['ok'] ? 'Done' : 'Failed' }}</span>
        @if(!empty($last_result['message']))
            <span class="co-notice__body">{{ $last_result['message'] }}</span>
        @endif
    </div>
@endif

<button type="button" class="x-desk-toggle" data-desk-toggle aria-expanded="true">
    <span data-desk-label>Find, dates and sync</span>
    <span class="x-desk-toggle__mark" aria-hidden="true">&#9662;</span>
</button>

<div class="x-segment-bar">
    <nav class="x-segment" aria-label="Order status">
        @foreach($tabs as $key => $label)
            @php
                $isActive = $active_tab === strtoupper((string) $key);
                $count = $tab_counts[$key] ?? null;
            @endphp
            <a href="{{ $panelSelfUrl(['tab' => $key], ['page', 'pending_sub', 'fd_sub', 'courier']) }}"
               class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
               @if($isActive) aria-current="true" @endif>
                <span>{{ $label }}</span>
                @if($count !== null)
                    <span class="x-segment__count">{{ number_format($count) }}</span>
                @endif
            </a>
        @endforeach
    </nav>
    <a class="x-segment-bar__aside" href="{{ $panelReturnsUrl ?? route('ext.lazada.orders.returns') }}">
        Returns and refunds
        <x-ui.icon name="chevron-right" size="12" />
    </a>
</div>

@if($active_tab === 'TO_SHIP')
    <div class="x-segment x-segment--sub" aria-label="To Ship step">
        @foreach($pendingNav as $k => $lbl)
            @php
                $isActive = ($pendingSub === $k) || ($pendingSub === '' && $k === 'to_pack');
                $count = $pending_sub_counts[$k] ?? null;
            @endphp
            <a href="{{ $panelSelfUrl(['tab' => 'TO_SHIP', 'pending_sub' => $k], ['page', 'courier']) }}"
               class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
                @if($isActive) aria-current="step" @endif>
                <span>{{ $lbl }}</span>
                @if($count !== null)
                    <span class="x-segment__count">{{ number_format($count) }}</span>
                @endif
            </a>
        @endforeach
    </div>

    @if($pendingSub !== '')
        <x-fulfilment.courier-strip :tally="$courier_tally ?? []" :self-url="$panelSelfUrl" />
    @endif
@endif

@if($active_tab === 'FAILED_DELIVERY')
    <div class="x-segment x-segment--sub" aria-label="Delivery failure type">
        @foreach($fdNav as $k => $lbl)
            @php
                $isActive = ($fdSub === $k) || ($fdSub === '' && $k === 'failed_delivery');
                $count = $fd_sub_counts[$k] ?? null;
            @endphp
            <a href="{{ $panelSelfUrl(['tab' => 'FAILED_DELIVERY', 'fd_sub' => $k], ['page']) }}"
               class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
                @if($isActive) aria-current="step" @endif>
                <span>{{ $lbl }}</span>
                @if($count !== null)
                    <span class="x-segment__count">{{ number_format($count) }}</span>
                @endif
            </a>
        @endforeach
    </div>
@endif

@php
    $lzActiveFilters = 0;
    if (($placedFromFilter ?? '') !== '') { $lzActiveFilters++; }
    if (($placedToFilter ?? '') !== '') { $lzActiveFilters++; }
    if (($sort ?? '') !== '' && ($sort ?? '') !== ('created_' . (($active_tab ?? '') === 'TO_SHIP' ? 'asc' : 'desc'))) { $lzActiveFilters++; }
    if ((int) ($perPage ?? 10) !== 10) { $lzActiveFilters++; }
@endphp

<div class="x-toolbar">
<form method="GET" action="{{ $panelBaseUrl }}" class="x-filters" data-desk-tool id="orders-filter">
@foreach($panelHiddenParams as $panelHiddenKey => $panelHiddenValue)
        <input type="hidden" name="{{ $panelHiddenKey }}" value="{{ $panelHiddenValue }}">
@endforeach
    <input type="hidden" name="tab" value="{{ $active_tab }}">
    @if($active_tab === 'TO_SHIP')
        <input type="hidden" name="pending_sub" value="{{ $pendingSub }}">
        @if(($filters['courier'] ?? '') !== '')
            <input type="hidden" name="courier" value="{{ $filters['courier'] }}">
        @endif
    @endif
    @if($active_tab === 'FAILED_DELIVERY')
        <input type="hidden" name="fd_sub" value="{{ $fdSub }}">
    @endif

    <x-ui.input type="search" name="q" class="x-filters__find"
                value="{{ $findFilter }}"
                placeholder="Order no, buyer, product or tracking"
                aria-label="Find an order by order number, buyer, product or tracking" />

    <x-ui.hint label="About searching Lazada buyers">Lazada sends buyer names already masked, so the rows show
        "J*******e" and a name typed here will not match. Order number, product and the date range all
        search normally.</x-ui.hint>

    <x-ui.foldout label="Filters" :count="$lzActiveFilters ?: null">
    <span class="x-filters__group">
            <span class="x-filters__group-label">Show orders placed</span>
            <x-ui.input type="date" name="placed_from" class="x-filters__date"
                        value="{{ $placedFromFilter }}" aria-label="Placed on or after" title="Placed on or after" />
            <x-ui.input type="date" name="placed_to" class="x-filters__date"
                        value="{{ $placedToFilter }}" aria-label="Placed on or before" title="Placed on or before" />
        </span>

        <div class="x-select-wrap x-filters__select x-filters__select--wide">
            <select name="sort" class="x-input" data-autosubmit aria-label="Sort orders">
                @foreach($lzSortLabels as $value => $label)
                    <option value="{{ $value }}" @selected(($sort ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
        </div>

        <div class="x-select-wrap x-filters__select x-filters__select--narrow">
            <select name="per_page" class="x-input" data-autosubmit aria-label="Orders per page">
                @foreach([10, 20, 50, 100] as $n)
                    <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }} per page</option>
                @endforeach
            </select>
            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
        </div>
    </x-ui.foldout>

    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>

    @if($hasSearch)
        <a class="x-filters__reset" href="{{ $panelUrl(['tab' => $active_tab, 'per_page' => $perPage]) }}">Reset</a>
    @endif
</form>

@if($canManageLazadaOrders)
    <x-ui.foldout label="Sync" class="x-toolbar__sync"
                  hint="Pulls orders from Lazada for the dates below. Fetching an order that has not been seen before adjusts stock.">
        <div class="x-cmdbar x-cmdbar--folded">
            <form method="POST" action="{{ route('ext.lazada.orders.fetch') }}" id="formFetchLazadaOrders" class="x-cmdbar__row"
              data-confirm="Fetch Lazada orders for the chosen dates? Any order that has not been seen before will adjust stock."
              data-confirm-tone="primary">
            @csrf
            <div class="x-cmdbar__field">
                <label class="x-cmdbar__label" for="lzDateFrom">Pull orders from</label>
                <x-ui.input type="date" id="lzDateFrom" name="date_from" class="x-cmdbar__date"
                            value="{{ old('date_from', request('date_from', now()->subDays(15)->format('Y-m-d'))) }}" />
            </div>
            <div class="x-cmdbar__field">
                <label class="x-cmdbar__label" for="lzDateTo">Pull orders to</label>
                <x-ui.input type="date" id="lzDateTo" name="date_to" class="x-cmdbar__date"
                            value="{{ old('date_to', request('date_to', now()->format('Y-m-d'))) }}" />
            </div>
            <div class="x-cmdbar__spacer"></div>
            <div class="x-cmdbar__actions">
                <x-ui.button type="submit" variant="primary" id="btnFetchOrders">Fetch orders</x-ui.button>
                <x-ui.button type="submit" variant="secondary" id="btnUpdateOrders"
                             data-confirm="Re-read the statuses of Lazada orders in the chosen dates? Existing orders are updated in place; no new orders are pulled."
                             data-confirm-tone="primary"
                             formaction="{{ route('ext.lazada.orders.update_statuses') }}">Update statuses</x-ui.button>
                <x-ui.hint>Fetch pulls the orders created between these dates. Update statuses uses the same two dates, so both are required.</x-ui.hint>
            </div>
        </form>
        <x-channel.order-fetch integration="lazada" :store-id="\Extensions\lazada\Models\LazadaSetting::defaultStore()?->id" form-id="formFetchLazadaOrders" channel-label="Lazada"
            :begin-url="route('ext.lazada.orders.fetch_run_begin')" :step-url="route('ext.lazada.orders.fetch_run_step', ['run' => 0])" :stop-url="route('ext.lazada.orders.fetch_run_stop', ['run' => 0])" :state-url="route('ext.lazada.orders.fetch_run_state', ['run' => 0])" />
        </div>
    </x-ui.foldout>
@endif
</div>

@if($orders->count() === 0)
    @php
        if ($hasSearch) {
            $lzEmptyDesc = 'Nothing matches that search. Clear it to see the full list.';
        } elseif ($active_tab !== 'ALL') {
            $lzEmptyDesc = 'No orders in ' . ($scopeLabel ?? $active_tab) . ' right now.';
        } else {
            $lzEmptyDesc = $canManageLazadaOrders
                ? 'Nothing has been synced yet. Pick a date range above and fetch orders from Lazada.'
                : 'Nothing has been synced yet. Someone with permission to sync orders needs to pull them from Lazada first.';
        }

        $emptyFiltered = (isset($active_tab) && strtoupper((string) $active_tab) !== 'ALL')
            || collect(request()->except(array_merge(array_keys($panelHiddenParams ?? []), ['page'])))
            ->filter(fn ($v, $k) => $v !== null && $v !== '' && !($k === 'tab' && $v === 'ALL'))
            ->isNotEmpty();
    @endphp
    <x-ui.empty title="No orders here" :description="$lzEmptyDesc">
        @if($emptyFiltered)
            <x-slot:action>
                <x-ui.button variant="secondary" :href="isset($active_tab) ? $panelUrl(['tab' => 'ALL']) : $panelUrl()">Show all orders</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else

<div data-order-selection>
    <x-ui.selectbar :step="$active_tab !== 'ALL' ? ($tabs[$active_tab] ?? null) : null">
        <form method="POST" action="{{ route('fulfilment.print.pick', ['channel' => 'lazada']) }}"
              id="lazada-print-pick" class="x-selectbar__actions" target="_blank" data-selection-actions hidden>
            @csrf
            <x-ui.button type="submit" variant="secondary" size="sm">Print pick list</x-ui.button>
            <x-ui.button type="submit" variant="secondary" size="sm"
                         formaction="{{ route('fulfilment.print.packing', ['channel' => 'lazada']) }}">Print packing lists</x-ui.button>
            <x-ui.hint label="About these two lists">One pick list for the whole selection, with lines consolidated by SKU so three of the same item is one trip to the shelf. Packing lists are one per order, nothing consolidated, to go in the parcel.</x-ui.hint>

            @if($canManageLazadaOrders && $pendingSub === 'to_pack' && ($active_tab ?? '') === 'TO_SHIP')
                <x-ui.button type="submit" variant="primary" size="sm"
                             formaction="{{ route('ext.lazada.orders.bulk_pack_print') }}"
                             data-pc-channel="lazada" data-pc-bulk="ids[]"
                             formtarget="_self"
                             data-confirm="Pack the selected orders on Lazada? This creates a package for each one and cannot be undone from here."
                             data-confirm-count="Pack :n selected orders on Lazada? This creates a package for each one and cannot be undone from here."
                             data-busy="Packing the selected orders on Lazada"
                             data-busy-count="Packing :n orders on Lazada">Pack and print selected</x-ui.button>
            @endif
        </form>
    </x-ui.selectbar>

<x-ui.table caption="{{ 'Lazada orders' . ($active_tab !== 'ALL' && isset($tabs[$active_tab]) ? ' in ' . $tabs[$active_tab] : '') }}" class="x-table--stack">
    <x-slot:head>
        <tr>
            <th scope="col" class="lz-col-products">Products</th>
            <th scope="col" class="lz-col-qty">Qty</th>
            <th scope="col" class="lz-col-price x-td-num">Price</th>
            <th scope="col" class="lz-col-total x-td-num">Amount</th>
            <th scope="col" class="lz-col-ship">Shipping</th>
            <th scope="col" class="lz-col-status">Status</th>
            <th scope="col" class="lz-col-act"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

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
            $isCancelled = in_array($statusNorm, ['canceled', 'cancelled'], true);
            $isToPack = in_array($statusNorm, ['pending', 'repacked'], true);
            $isToArrange = ($statusNorm === 'packed');
            $isToHandover = ($statusNorm === 'ready_to_ship');

            $created = $o->order_created_at
                ? $o->order_created_at->format('Y-m-d H:i')
                : ($raw['created_at'] ?? $raw['createdAt'] ?? $raw['created_time'] ?? null);

            $buyer = (string) ($raw['customer_first_name'] ?? $raw['customer_name'] ?? $raw['buyer_name'] ?? '');

            $items = [];
            if (method_exists($o, 'products') && $o->relationLoaded('products')) {
                $items = $o->products ? $o->products->toArray() : [];
            }
            if (empty($items)) {
                $items = $raw['order_items'] ?? $raw['items'] ?? $raw['orderItems'] ?? [];
                if (!is_array($items)) $items = [];
            }

            $groupedItems = \App\Support\Fulfilment\OrderItemGrouper::group($items);

            $displayItems = $groupedItems !== [] ? $groupedItems : [[]];
            $lineCount = count($displayItems);
            $itemCount = count($groupedItems);

            $totalAmount = $raw['price'] ?? $raw['total_amount'] ?? $raw['total_price'] ?? $raw['order_amount'] ?? $raw['amount'] ?? null;

            $currencyCode = (string) ($raw['currency'] ?? '');

            $paymentMethod = $raw['payment_method'] ?? $raw['payment_method_type'] ?? $raw['paymentMethod'] ?? $raw['payment_method_name'] ?? null;
            if (is_array($paymentMethod)) $paymentMethod = implode(', ', $paymentMethod);
            $paymentMethod = is_string($paymentMethod) ? trim($paymentMethod) : null;
            $paymentBadge = $paymentMethod;
            if (!$paymentBadge) {
                $isCod = $raw['is_cod'] ?? $raw['cod'] ?? $raw['cash_on_delivery'] ?? null;
                if ($isCod === true || (string) $isCod === '1') {
                    $paymentBadge = 'COD';
                }
            }

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

            $courier = $pick(['shipment_provider', 'shipping_provider', 'shipping_provider_name']);
            $deliveryMethod = $pick(['shipping_type', 'delivery_type']);
            $trackingNo = $pick(['tracking_code', 'tracking_number', 'tracking_code_pre']);

            $awaitingShip = in_array($statusNorm, ['pending', 'confirmed', 'ready_to_ship', 'packed'], true);
            $shipBy = 0;
            if ($awaitingShip) {
                $tsRaw = $pick(['sla_time_stamp', 'promised_shipping_time']);
                if (is_numeric($tsRaw) && (int) $tsRaw > 0) {
                    $shipBy = (int) $tsRaw;
                    if ($shipBy > 1000000000000) $shipBy = (int) ($shipBy / 1000);
                } else {
                    $slaText = $pick(['fulfillment_sla']);
                    if ($slaText && preg_match('/(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $slaText, $m)) {
                        $parsed = strtotime(str_replace('T', ' ', $m[1]));
                        if ($parsed) $shipBy = $parsed;
                    }
                }
            }

            $tone = \App\Support\ChannelStatusTone::toneFor('lazada.orders', $statusStr);
            $statusLabel = \App\Support\ChannelStatusTone::labelFor('lazada.orders', $statusStr);

            $hasAwb = $orderId ? (bool) ($savedAwbs[$orderId] ?? false) : false;

            $showsAwbButton = $orderId && (
                ($canManageLazadaOrders && ($isToArrange || $isToHandover))
                || (!$canManageLazadaOrders && !$isToPack && $hasAwb)
                || ($canManageLazadaOrders && !$isToPack && !$isToArrange && !$isToHandover && !$isCancelled && $hasAwb)
            );
        @endphp
        <tr class="co-band">
            <td colspan="7">
                <div class="co-band__row">
                    <div class="co-band__left">
                        <input type="checkbox" class="x-pick" data-selection-item
                               name="ids[]" value="{{ $o->id }}" form="lazada-print-pick"
                               aria-label="Select order {{ $orderId }}">
                        <span class="co-band__label">Buyer</span>
                        @if($buyer !== '')
                            <span class="co-band__value">{{ $buyer }}</span>
                        @else
                            <span class="co-empty-mark">Not recorded</span>
                        @endif
                        <span class="co-band__label">Order</span>
                        @if($orderId)
                            <a class="co-sn" href="{{ route('ext.lazada.orders.show', ['orderId' => $orderId]) }}">{{ $orderId }}</a>
                        @else
                            <span class="co-empty-mark">No order number</span>
                        @endif
                        @if($itemCount > 0)
                            <span class="co-band__muted">({{ $itemCount }} {{ $itemCount === 1 ? 'item' : 'items' }})</span>
                        @endif
                        @if($o->catalog_order_id)
                            <a class="co-ref" href="{{ url('/sales/orders/'.$o->catalog_order_id) }}">Sales order {{ $o->catalog_order_id }}</a>
                        @endif
                    </div>
                    <div class="co-band__right">
                        @if($created)
                            <span class="co-band__label">Created</span>
                            <span class="co-band__value co-band__value--mono">{{ $created }}</span>
                        @endif
                    </div>
                </div>
            </td>
        </tr>
        @foreach($displayItems as $idx => $it)
        <tr class="co-itemrow {{ $idx === $lineCount - 1 ? 'co-itemrow--last' : '' }}">
            <td class="lz-col-products" data-label="Products">
                <div class="co-item">
                    <div class="order-img-wrap">
                        @php $itImg = !empty($it['image']) ? $it['image'] : \App\Support\CatalogImages::urlFor($it['sku'] ?? ($it['seller_sku'] ?? '')); @endphp
                        @if($itImg)
                            <img class="order-img" src="{{ $itImg }}" alt="">
                        @else
                            <span class="co-item__none">No image</span>
                        @endif
                    </div>
                    <div class="co-item__body">
                        <span class="co-item__name">{{ ($it['name'] ?? '') !== '' ? $it['name'] : 'Unnamed product' }}</span>
                        <div class="co-item__meta">
                            @if(trim((string) ($it['sku'] ?? '')) !== '')
                                <span class="co-item__sku">{{ $it['sku'] }}</span>
                            @endif
                            <x-fulfilment.variation :sku="$it['sku'] ?? null" :fallback="$it['variation'] ?? null" />
                        </div>
                    </div>
                </div>
            </td>

            <td class="lz-col-qty" data-label="Qty">
                <span class="co-qty">&times;{{ max(1, (int) ($it['quantity'] ?? 1)) }}</span>
            </td>

            <td class="lz-col-price x-td-num" data-label="Price">
                @php
                    $unitPrice = $it['item_price'] ?? null;
                    $unitCurrency = ($it['currency'] ?? '') !== '' ? $it['currency'] : $currencyCode;
                @endphp
                @if($unitPrice !== null && $unitPrice !== '' && is_numeric($unitPrice))
                    <span class="co-price">{{ $unitCurrency !== ''
                        ? \App\Support\Money::foreign((float) $unitPrice, $unitCurrency)
                        : \App\Support\Money::base((float) $unitPrice) }}</span>
                @elseif($unitPrice !== null && $unitPrice !== '')
                    <span class="co-price">{{ $unitPrice }}</span>
                @else
                    <span class="co-empty-mark">-</span>
                @endif
            </td>

            @if($idx === 0)
            <td class="lz-col-total x-td-num" rowspan="{{ $lineCount }}" data-label="Amount">
                <span class="co-total">
                    @if($totalAmount !== null && $totalAmount !== '' && is_numeric($totalAmount))
                        {{ $currencyCode !== ''
                            ? \App\Support\Money::foreign((float) $totalAmount, $currencyCode)
                            : \App\Support\Money::base((float) $totalAmount) }}
                    @elseif($totalAmount !== null && $totalAmount !== '')
                        {{ $totalAmount }}
                    @else
                        <span class="co-empty-mark">Not recorded</span>
                    @endif
                </span>
                @if($paymentBadge)
                    <span class="co-total__method">{{ \App\Support\ChannelStatusTone::labelFor('lazada.payment', $paymentBadge) }}</span>
                @endif
            </td>

            <td class="lz-col-ship" rowspan="{{ $lineCount }}" data-label="Shipping">
                @if($courier)
                    <span class="co-carrier">{{ $courier }}</span>
                    @if($trackingNo)
                        <span class="co-ship__no">{{ $trackingNo }}</span>
                    @endif
                    @if($deliveryMethod)
                        <span class="co-ship__note">{{ $deliveryMethod }}</span>
                    @endif
                @else
                    <span class="co-empty-mark">-</span>
                @endif
            </td>

            <td class="lz-col-status" rowspan="{{ $lineCount }}" data-label="Status">
                <div class="co-status">
                    <x-ui.badge :tone="$tone">{{ $statusLabel }}</x-ui.badge>
                    <x-sla-chip :deadline="$shipBy" />
                </div>
            </td>

            <td class="lz-col-act" rowspan="{{ $lineCount }}">
                @if(!$orderId)
                    <span class="co-empty-mark">No order number</span>
                @elseif($isUnpaid)
                    <span class="co-empty-mark">-</span>
                @else
                <div class="co-actions co-actions--wrap">
                    @if($canManageLazadaOrders)
                        @if($isToPack)
                            <form method="POST" action="{{ route('ext.lazada.orders.pack_print', ['orderId' => $orderId]) }}"
                                  data-pc-channel="lazada" data-pc-order="{{ $o->id }}"
                                  data-confirm="Pack this order now? This creates the packages and makes the waybill available."
                                  data-busy="Packing this order on Lazada">
                                @csrf
                                <x-ui.button type="submit" variant="secondary" size="sm">Pack and print</x-ui.button>
                            </form>
                        @endif

                        @if($isToArrange)
                            <x-ui.button size="sm"
                                         :href="route('ext.lazada.orders.awb', ['orderId' => $orderId])"
                                         target="_blank" rel="noopener">Print waybill</x-ui.button>

                            <form method="POST" action="{{ route('ext.lazada.orders.rts', ['orderId' => $orderId]) }}"
                                  data-confirm="Arrange shipment for this order? Lazada will mark it ready to ship."
                                  data-busy="Arranging shipment on Lazada">
                                @csrf
                                <x-ui.button type="submit" variant="secondary" size="sm">Arrange shipment</x-ui.button>
                            </form>

                            <form method="POST" action="{{ route('ext.lazada.orders.recreate_package', ['orderId' => $orderId]) }}"
                                  data-confirm="Recreate the package locally? Lazada may still keep the original package. Continue?"
                                  data-busy="Recreating the package">
                                @csrf
                                <x-ui.button type="submit" variant="secondary" size="sm">Recreate package</x-ui.button>
                            </form>
                        @endif

                        @if($isToHandover)
                            <x-ui.button size="sm"
                                         :href="route('ext.lazada.orders.awb', ['orderId' => $orderId])"
                                         target="_blank" rel="noopener">Print waybill</x-ui.button>
                        @endif
                    @else
                        @if(!$isToPack && $hasAwb)
                            <x-ui.button size="sm"
                                         :href="route('ext.lazada.orders.awb', ['orderId' => $orderId])"
                                         target="_blank" rel="noopener">Print waybill</x-ui.button>
                        @endif
                    @endif

                    @if($canManageLazadaOrders && !$isToPack && !$isToArrange && !$isToHandover && !$isCancelled && $hasAwb)
                        <x-ui.button size="sm"
                                     :href="route('ext.lazada.orders.awb', ['orderId' => $orderId])"
                                     target="_blank" rel="noopener">Print waybill</x-ui.button>
                    @endif

                    @if(!$isCancelled && !$isToPack)
                        <x-ui.button size="sm" class="btnLzLogistics"
                                     data-url="{{ route('ext.lazada.orders.logistics_trace', ['orderId' => $orderId]) }}">Tracking</x-ui.button>
                    @endif
                </div>

                @if(!$isCancelled && !$isToPack)
                    <div class="x-docs">
                        <span class="x-docs__label">Print</span>
                        @if($hasAwb && !$showsAwbButton)
                            <a class="x-doc" href="{{ route('ext.lazada.orders.awb', ['orderId' => $orderId]) }}" target="_blank" rel="noopener">Waybill</a>
                        @endif
                        <a class="x-doc" href="{{ route('ext.lazada.orders.packing_list', ['orderId' => $orderId]) }}" target="_blank" rel="noopener">Packing list</a>
                        <a class="x-doc" href="{{ route('ext.lazada.orders.pick_list', ['orderId' => $orderId]) }}" target="_blank" rel="noopener">Pick list</a>
                    </div>
                @endif
                @endif
            </td>
            @endif
        </tr>
        @endforeach
    @endforeach
</x-ui.table>
</div>

<x-ui.pager :paginator="$orders" />
@endif

<div id="lzLoadingOverlay" class="modal-backdrop" data-busy-overlay>
    <div class="modal co-modal co-modal--sm">
        <div class="co-progress">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div id="lzLoadingTitle" class="co-progress__title" data-busy-title>Fetching orders</div>
                <div class="co-progress__note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

@if($canManageLazadaOrders)
<div id="lzPackPrintModal" class="modal-backdrop">
    <div class="modal co-modal">
        <div class="modal-header co-modal__head">
            <h3 class="co-modal__title">Packed</h3>
            <button type="button" id="btnClosePackPrint" class="modal-close co-modal__close" aria-label="Close">&times;</button>
        </div>
        <div class="co-modal__meta">Order <strong id="lzPackPrintOrder"></strong></div>

        <x-fulfilment.parcel :parcel="$pack_print_parcel ?? null" />

        <p class="co-modal__prompt">Close this and the order waits in To Arrange Shipment.</p>
        <div class="co-modal__foot co-modal__foot--end">
            <form method="POST" id="formRecreatePackage" action="#" data-busy="Recreating the package">
                @csrf
                <x-ui.button type="submit" variant="secondary">Recreate package</x-ui.button>
            </form>
            <a href="#" id="linkPrintOnly" class="x-btn x-btn--secondary" target="_blank" rel="noopener">Print only</a>
            <form method="POST" id="formShipPrint" action="#" data-busy="Arranging shipment on Lazada">
                @csrf
                <x-ui.button type="submit" variant="primary">Ship and print</x-ui.button>
            </form>
        </div>
    </div>
</div>
@endif

@if(!empty($bulk_pack))
    @php $lzBulk = $bulk_pack; @endphp
    <div id="lzBulkPackModal" class="modal-backdrop">
        <div class="modal co-modal">
            <div class="modal-header co-modal__head">
                <h3 class="co-modal__title">{{ $lzBulk['packed'] }} of {{ $lzBulk['total'] }} packed</h3>
                <button type="button" id="btnCloseBulkPack" class="modal-close co-modal__close" aria-label="Close">&times;</button>
            </div>

            <ul class="co-outcomes">
                @foreach($lzBulk['outcomes'] as $lzOut)
                    @php
                        $lzOutTone = match ($lzOut['state']) {
                            'packed' => 'success',
                            'already' => 'info',
                            'sof' => 'warning',
                            default => 'danger',
                        };
                        $lzOutWord = match ($lzOut['state']) {
                            'packed' => 'Packed',
                            'already' => 'Was packed',
                            'sof' => 'Skipped',
                            'gone' => 'Gone',
                            default => 'Failed',
                        };
                    @endphp
                    <li class="co-outcome">
                        <x-ui.badge :tone="$lzOutTone">{{ $lzOutWord }}</x-ui.badge>
                        <span class="co-outcome__id">
                            @if($lzOut['order_id'] !== '')
                                {{ $lzOut['order_id'] }}
                            @else
                                <span class="co-empty-mark">Order {{ $lzOut['local_id'] }}</span>
                            @endif
                        </span>
                        <span class="co-outcome__what">{{ $lzOut['message'] }}</span>
                    </li>
                @endforeach
            </ul>

            <div class="co-modal__foot co-modal__foot--end">
                <x-ui.button type="button" id="btnBulkPackDone" variant="secondary">Close</x-ui.button>
                @if(!empty($lzBulk['packed_ids']))
                    <form method="POST" action="{{ route('ext.lazada.orders.bulk_awb') }}" target="_blank">
                        @csrf
                        @foreach($lzBulk['packed_ids'] as $lzPackedId)
                            <input type="hidden" name="ids[]" value="{{ $lzPackedId }}">
                        @endforeach
                        <x-ui.button type="submit" variant="primary">Print {{ count($lzBulk['packed_ids']) }} {{ count($lzBulk['packed_ids']) === 1 ? 'waybill' : 'waybills' }}</x-ui.button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endif

@include('ext-lazada::orders._modals')
