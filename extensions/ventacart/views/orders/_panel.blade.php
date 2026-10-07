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

    $canManageVentaOrders = $canManageVentaOrders
        ?? (auth()->user()?->hasPermission('manage_ventacart/order') ?? false);

    $canOpenSalesOrders = $canOpenSalesOrders ?? (bool) (auth()->user()?->hasPermission('view_sales/order')
        || auth()->user()?->hasPermission('manage_sales/order'));

    $vtQ = (string) ($filters['q'] ?? '');
    $vtImages = $vtImages ?? [];
    $vtPerPage = (int) ($per_page ?? 10);
    $vtPlacedFrom = (string) ($filters['placed_from'] ?? '');
    $vtPlacedTo = (string) ($filters['placed_to'] ?? '');
    $vtStatus = (string) ($filters['status'] ?? '');
    $vtHasFilters = $vtQ !== '' || $vtStatus !== '' || $vtPlacedFrom !== '' || $vtPlacedTo !== '';

    $tabs = $tabs ?? [];
    $active_tab = strtoupper((string) ($active_tab ?? 'TO_SHIP'));
    $tab_counts = $tab_counts ?? [];
    $pending_sub_counts = $pending_sub_counts ?? [];
    $pendingSub = $pending_subtab ?? request()->query('pending_sub', 'to_pack');

    $vtStatusLabel = fn (string $s) => $s !== ''
        ? \App\Support\ChannelStatusTone::labelFor('ventacart.orders', $s)
        : 'No status';

    $vtScopeStep = $vtStatus !== ''
        ? $vtStatusLabel($vtStatus)
        : ($active_tab === 'TO_SHIP'
            ? (['to_pack' => 'To Pack', 'to_handover' => 'To Handover'][$pendingSub] ?? 'To Ship')
            : ($active_tab === 'ALL' ? null : ($tabs[$active_tab] ?? null)));

    $vtSortUrl = fn (string $col) => $panelSelfUrl(
        ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc'],
        ['page']
    );
@endphp
@if($canManageVentaOrders)
@endif

<button type="button" class="x-desk-toggle" data-desk-toggle aria-expanded="true">
    <span data-desk-label>Find, dates and sync</span>
    <span class="x-desk-toggle__mark" aria-hidden="true">&#9662;</span>
</button>

<div class="x-segment-bar">
    <nav class="x-segment" aria-label="Order status">
        @foreach($tabs as $vtKey => $vtLabel)
            @php
                $vtKey = strtoupper((string) $vtKey);
                $vtIsActive = $active_tab === $vtKey;
                $vtCount = $tab_counts[$vtKey] ?? null;
            @endphp
            <a href="{{ $panelSelfUrl(['tab' => $vtKey], ['page', 'pending_sub', 'status']) }}"
               class="x-segment__item {{ $vtIsActive ? 'is-active' : '' }}"
               @if($vtIsActive) aria-current="true" @endif>
                <span>{{ $vtLabel }}</span>
                @if($vtCount !== null)
                    <span class="x-segment__count">{{ number_format($vtCount) }}</span>
                @endif
            </a>
        @endforeach
    </nav>
</div>

@if($active_tab === 'TO_SHIP')
    @php
        $vtPendingNav = ['to_pack' => 'To Pack', 'to_handover' => 'To Handover'];
    @endphp
    <div class="x-segment x-segment--sub" aria-label="To Ship step">
        @foreach($vtPendingNav as $vtSubKey => $vtSubLabel)
            @php
                $vtSubActive = ($pendingSub === $vtSubKey) || ($pendingSub === '' && $vtSubKey === 'to_pack');
                $vtSubCount = $pending_sub_counts[$vtSubKey] ?? null;
            @endphp
            <a href="{{ $panelSelfUrl(['tab' => 'TO_SHIP', 'pending_sub' => $vtSubKey], ['page', 'status']) }}"
               class="x-segment__item {{ $vtSubActive ? 'is-active' : '' }}"
               @if($vtSubActive) aria-current="step" @endif>
                <span>{{ $vtSubLabel }}</span>
                @if($vtSubCount !== null)
                    <span class="x-segment__count">{{ number_format($vtSubCount) }}</span>
                @endif
            </a>
        @endforeach
    </div>
@endif

@php
    $vtActiveFilters = 0;
    if (($vtPlacedFrom ?? '') !== '') { $vtActiveFilters++; }
    if (($vtPlacedTo ?? '') !== '') { $vtActiveFilters++; }
    if ($vtPerPage !== 10) { $vtActiveFilters++; }
@endphp

<div class="x-toolbar">
<form method="GET" action="{{ $panelBaseUrl }}" class="x-filters" data-desk-tool id="orders-filter">
@foreach($panelHiddenParams as $panelHiddenKey => $panelHiddenValue)    <input type="hidden" name="{{ $panelHiddenKey }}" value="{{ $panelHiddenValue }}">
@endforeach    <input type="hidden" name="tab" value="{{ $active_tab }}">
    @if($active_tab === 'TO_SHIP')
        <input type="hidden" name="pending_sub" value="{{ $pendingSub }}">
    @endif
    @if($vtStatus !== '')
        <input type="hidden" name="status" value="{{ $vtStatus }}">
    @endif
    <input type="hidden" name="sort" value="{{ $sort }}">
    <input type="hidden" name="dir" value="{{ $dir }}">

    <x-ui.input type="search" name="q" class="x-filters__find"
                value="{{ $vtQ }}"
                placeholder="Order no, buyer, product or tracking"
                aria-label="Find an order by order number, buyer, product or tracking" />

    <x-ui.foldout label="Filters" :count="$vtActiveFilters ?: null">
        <span class="x-filters__group">
            <span class="x-filters__group-label">Show orders placed</span>
            <x-ui.input type="date" name="placed_from" class="x-filters__date"
                        value="{{ $vtPlacedFrom }}" aria-label="Placed on or after" title="Placed on or after" />
            <x-ui.input type="date" name="placed_to" class="x-filters__date"
                        value="{{ $vtPlacedTo }}" aria-label="Placed on or before" title="Placed on or before" />
        </span>

        <div class="x-select-wrap x-filters__select x-filters__select--narrow">
            <select name="per_page" class="x-input" data-autosubmit aria-label="Orders per page">
                @foreach([10, 20, 50, 100] as $n)
                    <option value="{{ $n }}" @selected($vtPerPage === $n)>{{ $n }} per page</option>
                @endforeach
            </select>
            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
        </div>
    </x-ui.foldout>

    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>

    @if($vtHasFilters)
        <a class="x-filters__reset" href="{{ $panelUrl() }}">Reset</a>
    @endif
</form>

@if($canManageVentaOrders)
    <x-ui.foldout label="Sync" class="x-toolbar__sync"
                  hint="Pulls orders from this storefront for the dates below.">
        <div class="x-cmdbar x-cmdbar--folded">
            <form method="POST" action="{{ route('ext.ventacart.orders.fetch', $storeId) }}" id="formFetchVentaOrders" class="x-cmdbar__row"
              data-confirm="Fetch orders from this VentaCart store for the chosen dates? Any order that has not been seen before will adjust stock."
              data-busy="Fetching orders"
              data-confirm-tone="primary">
            @csrf
            <div class="x-cmdbar__field">
                <label class="x-cmdbar__label" for="vtDateFrom">Pull orders from</label>
                <x-ui.input type="date" id="vtDateFrom" name="date_from" class="x-cmdbar__date"
                            value="{{ old('date_from', request('date_from', now()->subDays(15)->format('Y-m-d'))) }}" />
            </div>
            <div class="x-cmdbar__field">
                <label class="x-cmdbar__label" for="vtDateTo">Pull orders to</label>
                <x-ui.input type="date" id="vtDateTo" name="date_to" class="x-cmdbar__date"
                            value="{{ old('date_to', request('date_to', now()->format('Y-m-d'))) }}" />
            </div>
            <div class="x-cmdbar__spacer"></div>
            <div class="x-cmdbar__actions">
                <x-ui.button type="submit" variant="primary" id="btnFetchVentaOrders">Fetch orders</x-ui.button>
                <x-ui.hint>Fetching pulls the orders this store created between those two dates.</x-ui.hint>
            </div>
        </form>
        <x-channel.order-fetch integration="ventacart" :store-id="(int) $storeId" form-id="formFetchVentaOrders" channel-label="VentaCart"
            :begin-url="route('ext.ventacart.orders.fetch_run_begin', ['store' => $storeId])" :step-url="route('ext.ventacart.orders.fetch_run_step', ['store' => $storeId, 'run' => 0])" :stop-url="route('ext.ventacart.orders.fetch_run_stop', ['store' => $storeId, 'run' => 0])" :state-url="route('ext.ventacart.orders.fetch_run_state', ['store' => $storeId, 'run' => 0])" />
        </div>
    </x-ui.foldout>
@endif
</div>

@if($orders->count() === 0)
    @php
        if ($vtHasFilters) {
            $vtEmptyDesc = 'Nothing matches those filters. Clear them to see the full list.';
        } elseif ($active_tab !== 'ALL' && $vtScopeStep) {
            $vtEmptyDesc = 'No orders in ' . $vtScopeStep . ' right now.';
        } else {
            $vtEmptyDesc = $canManageVentaOrders
                ? 'Nothing has been synced yet. Pick a date range above and fetch orders from this store.'
                : 'Nothing has been synced yet. Someone with permission to sync orders needs to pull them from this store first.';
        }

        $emptyFiltered = (isset($active_tab) && strtoupper((string) $active_tab) !== 'ALL')
            || collect(request()->except(array_merge(array_keys($panelHiddenParams ?? []), ['page'])))
            ->filter(fn ($v, $k) => $v !== null && $v !== '' && !($k === 'tab' && $v === 'ALL'))
            ->isNotEmpty();
    @endphp
    <x-ui.empty title="No orders here" :description="$vtEmptyDesc">
        @if($emptyFiltered)
            <x-slot:action>
                <x-ui.button variant="secondary" :href="isset($active_tab) ? $panelUrl(['tab' => 'ALL']) : $panelUrl()">Show all orders</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
@if($canManageVentaOrders)
<div data-order-selection>
    <x-ui.selectbar :step="$vtScopeStep">
        <form method="POST" action="{{ route('fulfilment.print.pick', ['channel' => 'ventacart']) }}"
              id="ventacart-bulk-form" class="x-selectbar__actions" data-selection-actions hidden>
            @csrf
            <x-ui.button type="submit" variant="secondary" size="sm" formtarget="_blank">Print pick list</x-ui.button>
            <x-ui.button type="submit" variant="secondary" size="sm" formtarget="_blank"
                         formaction="{{ route('fulfilment.print.packing', ['channel' => 'ventacart']) }}">Print packing lists</x-ui.button>
            <x-ui.hint label="About these two lists">One pick list for the whole selection, with lines consolidated by SKU so three of the same item is one trip to the shelf. Packing lists are one per order, nothing consolidated, to go in the parcel.</x-ui.hint>
            <span class="x-selectbar__split"></span>
            <x-ui.button type="submit" variant="danger" size="sm"
                         formaction="{{ route('ext.ventacart.orders.bulk_delete', $storeId) }}"
                         data-confirm="Delete the selected orders from this store? The sales orders they created are not affected."
                         data-confirm-count="Delete :n selected orders from this store? The sales orders they created are not affected.">Delete selected</x-ui.button>
        </form>
    </x-ui.selectbar>
@endif
<x-ui.table caption="{{ 'Storefront orders' . ($vtScopeStep ? ' in ' . $vtScopeStep : '') }}" class="x-table--stack">
    <x-slot:head>
        <tr>
            <th scope="col" class="vt-col-products">Products</th>
            <th scope="col" class="vt-col-qty">Qty</th>
            <th scope="col" class="vt-col-price x-td-num">Price</th>
            <th scope="col" class="vt-col-total x-td-num">
                <a class="x-th-sort x-th-sort--num" href="{{ $vtSortUrl('total') }}">
                    Amount
                    @if($sort === 'total')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="vt-col-pay">
                <a class="x-th-sort" href="{{ $vtSortUrl('payment_method') }}">
                    Payment
                    @if($sort === 'payment_method')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="vt-col-track">
                <a class="x-th-sort" href="{{ $vtSortUrl('tracking_number') }}">
                    Tracking
                    @if($sort === 'tracking_number')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="vt-col-status">
                <a class="x-th-sort" href="{{ $vtSortUrl('status') }}">
                    Status
                    @if($sort === 'status')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="vt-col-date">
                <a class="x-th-sort" href="{{ $vtSortUrl('order_created_at') }}">
                    Date
                    @if($sort === 'order_created_at')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="vt-col-act"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($orders as $o)
        @php
            $vtStatusRaw = (string) ($o->status ?? '');
            $vtNumber = (string) ($o->ventacart_order_number ?: $o->ventacart_order_id);
            $vtCustomer = trim((string) ($o->customer_name ?? ''));
            $vtTracking = trim((string) ($o->tracking_number ?? ''));
            $vtPayment = trim((string) ($o->payment_method ?? ''));
            $vtCreated = $o->order_created_at ? $o->order_created_at->format('Y-m-d H:i') : null;

            $vtItems = $o->products ? $o->products->all() : [];
            $vtRows = $vtItems !== [] ? $vtItems : [null];
            $vtLineCount = count($vtRows);
            $vtItemCount = count($vtItems);

            $vtTone = \App\Support\ChannelStatusTone::toneFor('ventacart.orders', $vtStatusRaw);

            $vtStep = \Extensions\ventacart\Services\VentaCart\VentaCartStatusPlacements::stepFor((int) $storeId, $vtStatusRaw);
            $vtIsToPack = $vtStep === 'to_pack';
            $vtIsToHandover = $vtStep === 'to_handover';
            $vtBooked = $o->isBooked();
            $vtCourierNo = trim((string) ($o->courier_tracking_number ?? ''));
            $vtIsManual = $o->isManualShipment();
            $vtCourierName = $vtIsManual && trim((string) $o->courier_name) !== ''
                ? trim((string) $o->courier_name)
                : \Extensions\ventacart\Services\VentaCart\VentaCartCouriers::name((string) ($o->courier_provider ?? ''));

            $vtAddr = is_array($o->shipping_address) ? $o->shipping_address : [];
            $vtParcel = \App\Support\Fulfilment\PackedParcel::fromItems(array_map(function ($it) use ($vtImages) {
                $img = trim((string) ($vtImages[trim((string) ($it->sku ?? ''))] ?? ''));
                return [
                    'name' => (string) ($it->name ?? ''),
                    'sku' => (string) ($it->sku ?? ''),
                    'variation' => (string) ($it->variant_label ?? ''),
                    'quantity' => max(1, (int) ($it->quantity ?? 1)),
                    'image' => $img !== '' ? \App\Services\Media\ImageCache::url($img) : '',
                ];
            }, $vtItems), $vtBooked ? $vtCourierName : null, trim((string) ($vtAddr['city'] ?? '')) ?: null);
        @endphp
        <tr class="co-band">
            <td colspan="9">
                <div class="co-band__row">
                    <div class="co-band__left">
                        @if($canManageVentaOrders)
                            <input type="checkbox" class="x-pick" data-selection-item name="ids[]"
                                   value="{{ $o->id }}" form="ventacart-bulk-form"
                                   aria-label="Select order {{ $vtNumber }}">
                        @endif
                        <span class="co-band__label">Customer</span>
                        @if($vtCustomer !== '')
                            <span class="co-band__value">{{ $vtCustomer }}</span>
                        @else
                            <span class="co-empty-mark">Not recorded</span>
                        @endif
                        <span class="co-band__label">Order</span>
                        @if($vtNumber !== '')
                            <span class="co-band__value co-band__value--mono">{{ $vtNumber }}</span>
                        @else
                            <span class="co-empty-mark">No order number</span>
                        @endif
                        @if($vtItemCount > 0)
                            <span class="co-band__muted">({{ $vtItemCount }} {{ $vtItemCount === 1 ? 'item' : 'items' }})</span>
                        @endif
                    </div>
                    <div class="co-band__right">
                        @if($o->catalog_order_id && $canOpenSalesOrders)
                            <a class="co-ref" href="{{ route('orders.show', $o->catalog_order_id) }}">Sales order {{ $o->catalog_order_id }}</a>
                        @elseif($o->catalog_order_id)
                            <span class="co-band__muted">Sales order {{ $o->catalog_order_id }}</span>
                        @endif
                    </div>
                </div>
            </td>
        </tr>
        @foreach($vtRows as $vtIdx => $vtItem)
        <tr class="co-itemrow {{ $vtIdx === $vtLineCount - 1 ? 'co-itemrow--last' : '' }}">
            <td class="vt-col-products" data-label="Products">
                <div class="co-item">
                    @php
                        $vtImg = trim((string) ($vtImages[trim((string) ($vtItem?->sku ?? ''))] ?? ''));
                    @endphp
                    <div class="order-img-wrap">
                        @if($vtImg !== '')
                            <img class="order-img" src="{{ \App\Services\Media\ImageCache::url($vtImg) }}" alt="" loading="lazy">
                        @else
                            <span class="co-item__none">No image</span>
                        @endif
                    </div>
                    <div class="co-item__body">
                        <span class="co-item__name">{{ trim((string) ($vtItem?->name ?? '')) !== '' ? $vtItem->name : 'Unnamed product' }}</span>
                        <div class="co-item__meta">
                            @if(trim((string) ($vtItem?->sku ?? '')) !== '')
                                <span class="co-item__sku">{{ $vtItem->sku }}</span>
                            @endif
                            <x-fulfilment.variation :sku="$vtItem?->sku" :fallback="$vtItem?->variant_label" />
                        </div>
                    </div>
                </div>
            </td>

            <td class="vt-col-qty" data-label="Qty">
                <span class="co-qty">&times;{{ max(1, (int) ($vtItem?->quantity ?? 1)) }}</span>
            </td>

            <td class="vt-col-price x-td-num" data-label="Price">
                @if($vtItem && (float) $vtItem->price > 0)
                    <span class="co-price">{{ \App\Support\Money::base((float) $vtItem->price) }}</span>
                @else
                    <span class="co-empty-mark">-</span>
                @endif
            </td>

            @if($vtIdx === 0)
            <td class="vt-col-total x-td-num" rowspan="{{ $vtLineCount }}" data-label="Amount">
                <span class="co-total">{{ \App\Support\Money::base((float) $o->total) }}</span>
            </td>

            <td class="vt-col-pay" rowspan="{{ $vtLineCount }}" data-label="Payment">
                @if($vtPayment !== '')
                    <span class="vt-pay">{{ $vtPayment }}</span>
                @else
                    <span class="co-empty-mark">-</span>
                @endif
            </td>

            <td class="vt-col-track" rowspan="{{ $vtLineCount }}" data-label="Tracking">
                @if($vtCourierNo !== '')
                    <span class="co-ship__note">{{ $vtCourierName }}</span>
                    <span class="co-ship__no">{{ $vtCourierNo }}</span>
                @elseif($vtTracking !== '')
                    <span class="co-ship__no">{{ $vtTracking }}</span>
                @else
                    <span class="co-empty-mark">-</span>
                @endif
            </td>

            <td class="vt-col-status" rowspan="{{ $vtLineCount }}" data-label="Status">
                <div class="co-status">
                    <x-ui.badge :tone="$vtTone">{{ $vtStatusLabel($vtStatusRaw) }}</x-ui.badge>
                </div>
            </td>

            <td class="vt-col-date" rowspan="{{ $vtLineCount }}" data-label="Date">
                @if($vtCreated)
                    <span class="co-date">{{ $vtCreated }}</span>
                @else
                    <span class="co-empty-mark">-</span>
                @endif
            </td>

            <td class="vt-col-act" rowspan="{{ $vtLineCount }}">
                @if(!$canManageVentaOrders)
                    @if($vtBooked)
                        <x-ui.button size="sm" :href="route('ext.ventacart.orders.awb', [$storeId, $o->id])"
                                     target="_blank" rel="noopener">Print waybill</x-ui.button>
                    @else
                        <span class="co-empty-mark">-</span>
                    @endif
                @else
                <div class="co-actions co-actions--wrap">
                    @if($vtIsToPack)
                        <x-ui.button type="button" variant="secondary" size="sm" class="btnVentaBook"
                                     data-pc-channel="ventacart" data-pc-order="{{ $o->id }}"
                                     data-order-id="{{ $o->id }}"
                                     data-order-no="{{ $vtNumber }}"
                                     data-serviceability-url="{{ route('ext.ventacart.orders.serviceability', [$storeId, $o->id]) }}"
                                     data-slots-url="{{ route('ext.ventacart.orders.pickup_slots', [$storeId, $o->id]) }}"
                                     data-estimate-url="{{ route('ext.ventacart.orders.estimate', [$storeId, $o->id]) }}"
                                     data-book-url="{{ route('ext.ventacart.orders.book', [$storeId, $o->id]) }}"
                                     data-couriers-url="{{ route('ext.ventacart.orders.shipping_couriers', $storeId) }}"
                                     data-addresses-url="{{ route('ext.ventacart.orders.pickup_addresses', $storeId) }}"
                                     data-book-manual-url="{{ route('ext.ventacart.orders.book_manual', [$storeId, $o->id]) }}">Book courier</x-ui.button>
                        <template data-parcel-for="{{ $o->id }}"><x-fulfilment.parcel :parcel="$vtParcel" /></template>
                    @endif

                    @if(($vtIsToHandover || $vtBooked) && !$vtIsManual)
                        <x-ui.button size="sm" :href="route('ext.ventacart.orders.awb', [$storeId, $o->id])"
                                     target="_blank" rel="noopener">Print waybill</x-ui.button>
                        <x-ui.button type="button" variant="secondary" size="sm" class="btnVentaTracking"
                                     data-url="{{ route('ext.ventacart.orders.tracking', [$storeId, $o->id]) }}">Tracking</x-ui.button>
                    @endif

                    @if($vtIsToHandover && $vtIsManual)
                        <form method="POST" action="{{ route('ext.ventacart.orders.clear_manual', [$storeId, $o->id]) }}"
                              data-confirm="Clear the shipment recorded for order {{ $vtNumber }}? The courier and tracking number are removed and the order goes back to To Pack."
                              data-busy="Clearing the shipment">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" size="sm">Clear shipment</x-ui.button>
                        </form>
                    @elseif($vtIsToHandover)
                        <form method="POST" action="{{ route('ext.ventacart.orders.cancel_booking', [$storeId, $o->id]) }}"
                              data-confirm="Cancel the courier booking for order {{ $vtNumber }}? The courier is told not to collect it and the order goes back to To Pack."
                              data-busy="Cancelling the booking">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" size="sm">Cancel booking</x-ui.button>
                        </form>
                    @endif

                    <form method="POST" class="x-docs"
                          action="{{ route('fulfilment.print.packing', ['channel' => 'ventacart']) }}"
                          target="_blank">
                        @csrf
                        <input type="hidden" name="ids[]" value="{{ $o->id }}">
                        <button type="submit" class="x-doc">Packing list</button>
                    </form>

                    <x-ui.menu label="Order {{ $vtNumber }} actions">
                        <button type="button" class="x-menu__item x-menu__item--danger"
                                data-confirm="Delete order {{ $vtNumber }} from this store? The sales order it created is not affected."
                                data-confirm-submit="vt-del-{{ $o->id }}">Delete</button>
                        <form id="vt-del-{{ $o->id }}" method="POST"
                              action="{{ route('ext.ventacart.orders.destroy', [$storeId, $o->id]) }}" class="x-sr">
                            @csrf
                            @method('DELETE')
                        </form>
                    </x-ui.menu>
                </div>
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

@include('ext-ventacart::orders._modals')
