@extends('layouts.channel')
@section('title', $storeLabel . ' Orders')
@section('breadcrumb', 'Orders')

{{-- Leaves the .x-legacy content boundary, the same exit Shopee's, Lazada's
     and TikTok's Orders pages took: this page is now built directly on the
     design tokens and the x-ui components. The cross-marketplace tab strip
     went with it. Inside a store's own workspace you are working one store,
     and the workspace sidebar (which names the store in its head) and the
     fulfilment hub are the two ways to reach another one. --}}
@section('content-class', '')

@section('content')
@php
    // Orders is visible to view_venta_orders holders; Fetch and the bulk
    // delete render only for the manage tier. The route middleware
    // (perm:manage_venta_orders on every write in
    // extensions/venta/routes/web.php) is the real gate; this flag only
    // stops the page offering controls that would 403.
    //
    // The old page hardcoded this to true, the same defect Lazada's and
    // TikTok's pages carried, which showed a read-only operator a fetch bar
    // and a row of Delete buttons that all fail on submit.
    $canManageVentaOrders = auth()->user()?->hasPermission('manage_venta_orders') ?? false;

    // Opening the sales order the sync created needs the core read tier. The
    // legacy page linked there from every row regardless, so an operator
    // holding only view_venta_orders was offered a View button that 403s.
    $canOpenSalesOrders = (bool) (auth()->user()?->hasPermission('view_orders')
        || auth()->user()?->hasPermission('manage_orders'));

    $vtBaseUrl = route('ext.venta.orders.index', $storeId);

    // Self-links (segment, sort, reset) keep whatever else the operator has
    // set. The legacy page rebuilt each link from scratch, so choosing a
    // status silently discarded the search box next to it. Arr::query rather
    // than http_build_query so spaces encode as %20 (RFC 3986), matching
    // every other URL this page emits.
    $vtSelfUrl = function (array $params = [], array $except = []) use ($vtBaseUrl) {
        $query = array_merge(request()->except($except), $params);

        return $query === [] ? $vtBaseUrl : $vtBaseUrl . '?' . \Illuminate\Support\Arr::query($query);
    };

    $vtQ = (string) ($filters['q'] ?? '');
    $vtStatus = (string) ($filters['status'] ?? '');
    $vtHasFilters = $vtQ !== '' || $vtStatus !== '';

    // A status name is a word, not a constant, on this channel: see the
    // OrderStatusTone note in the table below.
    $vtStatusLabel = fn (string $s) => $s !== '' ? ucwords(str_replace('_', ' ', $s)) : 'No status';

    // The count in the deck names what is actually listed, so standing on a
    // status filter it says that status rather than the whole store.
    $vtScopeLabel = $vtStatus !== '' ? $vtStatusLabel($vtStatus) : null;

    // Sort links flip the direction of the column already sorted and start
    // ascending on any other, which is what the legacy header links did.
    $vtSortUrl = fn (string $col) => $vtSelfUrl(
        ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc'],
        ['page']
    );
@endphp

<div id="venta-orders-page" class="co-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Orders</h1>
        <p class="x-page-sub">
            {{ number_format($orders->total()) }} {{ $orders->total() === 1 ? 'order' : 'orders' }}@if($vtScopeLabel) in {{ $vtScopeLabel }}@endif
        </p>
    </div>
</div>

@if($canManageVentaOrders)
{{-- One command bar, the same grammar as the three marketplace channels':
     every child of the row ends in a box exactly --control-h tall and the
     row is bottom aligned, so the date inputs and the button share a
     baseline and a top edge. --}}
<div class="x-cmdbar">
    <form method="POST" action="{{ route('ext.venta.orders.fetch', $storeId) }}" id="formFetchVentaOrders" class="x-cmdbar__row">
        @csrf
        <div class="x-cmdbar__field">
            <label class="x-cmdbar__label" for="vtDateFrom">From</label>
            <x-ui.input type="date" id="vtDateFrom" name="date_from" class="x-cmdbar__date"
                        value="{{ old('date_from', request('date_from', now()->subDays(15)->format('Y-m-d'))) }}" />
        </div>
        <div class="x-cmdbar__field">
            <label class="x-cmdbar__label" for="vtDateTo">To</label>
            <x-ui.input type="date" id="vtDateTo" name="date_to" class="x-cmdbar__date"
                        value="{{ old('date_to', request('date_to', now()->format('Y-m-d'))) }}" />
        </div>
        <div class="x-cmdbar__spacer"></div>
        <div class="x-cmdbar__actions">
            <x-ui.button type="submit" variant="primary" id="btnFetchVentaOrders">Fetch orders</x-ui.button>
        </div>
    </form>
    <p class="x-cmdbar__hint">Fetching pulls the orders this store created between those two dates.</p>
</div>
@endif

{{-- Status filters as a segmented control. The statuses are whatever this
     store has actually sent, counted by the query behind the page, so the
     bar never offers a filter that would return nothing. Counts ride along
     as muted mono numbers on every item rather than only on some: a count
     that appears on some tabs and not others reads as an alert rather than
     a figure. --}}
<div class="x-segment-bar">
    <div class="x-segment" role="tablist" aria-label="Order status">
        <a href="{{ $vtSelfUrl([], ['page', 'status']) }}"
           class="x-segment__item {{ $vtStatus === '' ? 'is-active' : '' }}"
           @if($vtStatus === '') aria-current="page" @endif>
            <span>All</span>
            <span class="x-segment__count">{{ number_format($statusCounts->sum()) }}</span>
        </a>
        @foreach($statusCounts as $vtKey => $vtCount)
            @php $vtKey = (string) $vtKey; @endphp
            <a href="{{ $vtSelfUrl(['status' => $vtKey], ['page']) }}"
               class="x-segment__item {{ $vtStatus === $vtKey ? 'is-active' : '' }}"
               @if($vtStatus === $vtKey) aria-current="page" @endif>
                <span>{{ $vtStatusLabel($vtKey) }}</span>
                <span class="x-segment__count">{{ number_format($vtCount) }}</span>
            </a>
        @endforeach
    </div>
</div>

{{-- One filter row. Search was its own card form on the legacy page, sitting
     under a second card holding the status badges; both are one job. --}}
<form method="GET" action="{{ $vtBaseUrl }}" class="x-filters" id="orders-filter">
    @if($vtStatus !== '')
        <input type="hidden" name="status" value="{{ $vtStatus }}">
    @endif
    <input type="hidden" name="sort" value="{{ $sort }}">
    <input type="hidden" name="dir" value="{{ $dir }}">

    <x-ui.input type="search" name="q" class="x-filters__search"
                value="{{ $vtQ }}" placeholder="Customer, order number or tracking" aria-label="Search orders" />

    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>

    @if($vtHasFilters)
        <a class="x-filters__reset" href="{{ $vtBaseUrl }}">Reset</a>
    @endif
</form>

@if($orders->count() === 0)
    @php
        if ($vtHasFilters) {
            $vtEmptyDesc = 'Nothing matches those filters. Clear them to see the full list.';
        } else {
            // The empty state names the way forward the reader actually has.
            // Telling a read-only operator to use a command bar their
            // permission tier never renders is an instruction they cannot
            // follow.
            $vtEmptyDesc = $canManageVentaOrders
                ? 'Nothing has been synced yet. Pick a date range above and fetch orders from this store.'
                : 'Nothing has been synced yet. Someone with permission to sync orders needs to pull them from this store first.';
        }
    @endphp
    <x-ui.empty title="No orders here" :description="$vtEmptyDesc" />
@else
@if($canManageVentaOrders)
{{-- Selection bar. Delete is never a standing control on a row here: at rest
     this bar offers the select-all box and nothing else, and the destructive
     button only exists once the operator has said what it would act on. The
     count sits beside the button, so the number of orders about to go is
     never off screen from the control that removes them. --}}
<div class="vt-selectbar">
    <label class="vt-selectbar__pick" for="venta-select-all">
        <input type="checkbox" id="venta-select-all" class="vt-pick">
        <span>Select all on this page</span>
    </label>
    <span class="vt-selectbar__count" id="venta-bulk-count" role="status"></span>
    <div class="vt-selectbar__spacer"></div>
    <form id="venta-bulk-delete-form" class="vt-selectbar__form" method="POST"
          action="{{ route('ext.venta.orders.bulk_delete', $storeId) }}"
          data-confirm="Delete the selected orders from this store? The sales orders they created are not affected."
          hidden>
        @csrf
        <x-ui.button type="submit" variant="danger" size="sm">Delete selected</x-ui.button>
    </form>
</div>
@endif
<x-ui.table>
    <x-slot:head>
        {{-- The legacy column format the other three channels settled on: one
             row per item so Qty and Price align in their own columns, and the
             order-level facts (amount, payment, tracking, status, date,
             actions) rowspan beside the item rows. Customer, order number and
             the sales-order reference ride in a band above each order.

             The legacy page put a sort link on nine headers, including four
             fields that are now band facts rather than columns. Sorting stays
             on the headers that survived, which are the ones a person sorts a
             fulfilment list by; the controller still accepts every value it
             ever did. --}}
        <tr>
            <th class="vt-col-products">Products</th>
            <th class="vt-col-qty">Qty</th>
            <th class="vt-col-price x-td-num">Price</th>
            <th class="vt-col-total x-td-num">
                <a class="x-th-sort x-th-sort--num" href="{{ $vtSortUrl('total') }}">
                    Amount
                    @if($sort === 'total')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th class="vt-col-pay">
                <a class="x-th-sort" href="{{ $vtSortUrl('payment_method') }}">
                    Payment
                    @if($sort === 'payment_method')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th class="vt-col-track">
                <a class="x-th-sort" href="{{ $vtSortUrl('tracking_number') }}">
                    Tracking
                    @if($sort === 'tracking_number')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th class="vt-col-status">
                <a class="x-th-sort" href="{{ $vtSortUrl('status') }}">
                    Status
                    @if($sort === 'status')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th class="vt-col-date">
                <a class="x-th-sort" href="{{ $vtSortUrl('order_created_at') }}">
                    Date
                    @if($sort === 'order_created_at')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th class="vt-col-act"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($orders as $o)
        @php
            $vtStatusRaw = (string) ($o->status ?? '');
            $vtNumber = (string) ($o->venta_order_number ?: $o->venta_order_id);
            $vtCustomer = trim((string) ($o->customer_name ?? ''));
            $vtTracking = trim((string) ($o->tracking_number ?? ''));
            $vtPayment = trim((string) ($o->payment_method ?? ''));
            $vtCreated = $o->order_created_at ? $o->order_created_at->format('Y-m-d H:i') : null;

            $vtItems = $o->products ? $o->products->all() : [];
            // Legacy shape: an order with no recorded items still renders one
            // empty item row under its band, rather than a band with nothing
            // attached to it.
            $vtRows = $vtItems !== [] ? $vtItems : [null];
            $vtLineCount = count($vtRows);
            $vtItemCount = count($vtItems);

            // App\Support\OrderStatusTone, not ChannelStatusTone. What
            // venta_orders.status holds is the storefront's own status NAME,
            // and VentaOrderSync::buildStatusMap() resolves it by matching
            // that name against this ERP's order_status table. It is the same
            // open, admin-editable vocabulary the core Orders list carries,
            // not a closed set of marketplace constants like Shopee's or
            // TikTok's, so enumerating it in ChannelStatusTone would be
            // inventing a fixed list for something an admin renames from
            // Settings. Anything unrecognised reads neutral, which is
            // OrderStatusTone's deliberate behaviour rather than a gap.
            $vtTone = \App\Support\OrderStatusTone::for($vtStatusRaw);
        @endphp
        <tr class="co-band">
            <td colspan="9">
                <div class="co-band__row">
                    <div class="co-band__left">
                        @if($canManageVentaOrders)
                            <input type="checkbox" class="vt-pick venta-order-check" name="ids[]"
                                   value="{{ $o->id }}" form="venta-bulk-delete-form"
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
                    <div class="co-item__body">
                        <span class="co-item__name">{{ trim((string) ($vtItem?->name ?? '')) !== '' ? $vtItem->name : 'Unnamed product' }}</span>
                        <div class="co-item__meta">
                            @if(trim((string) ($vtItem?->sku ?? '')) !== '')
                                <span class="co-item__sku">{{ $vtItem->sku }}</span>
                            @endif
                            @if(trim((string) ($vtItem?->variant_label ?? '')) !== '')
                                <span class="co-item__var">{{ $vtItem->variant_label }}</span>
                            @endif
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
                    <span class="co-empty-mark">&mdash;</span>
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
                    <span class="co-empty-mark">&mdash;</span>
                @endif
            </td>

            <td class="vt-col-track" rowspan="{{ $vtLineCount }}" data-label="Tracking">
                @if($vtTracking !== '')
                    <span class="co-ship__no">{{ $vtTracking }}</span>
                @else
                    <span class="co-empty-mark">&mdash;</span>
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
                    <span class="co-empty-mark">&mdash;</span>
                @endif
            </td>

            <td class="vt-col-act" rowspan="{{ $vtLineCount }}">
                @if(!$canManageVentaOrders)
                    {{-- A read-only operator opens the sales order from the
                         reference in the band above and has nothing else to
                         do to the row, so the column prints a dash rather
                         than an empty control. --}}
                    <span class="co-empty-mark">&mdash;</span>
                @else
                <div class="co-actions">
                    {{-- Delete is the only write this page offers a row, and
                         it is destructive, so it never stands as a button of
                         its own: it lives behind an overflow trigger that
                         appears on hover or focus, or in the selection bar
                         above once rows are picked. --}}
                    <x-ui.menu label="Order {{ $vtNumber }} actions">
                        <button type="button" class="x-menu__item x-menu__item--danger"
                                data-confirm="Delete order {{ $vtNumber }} from this store? The sales order it created is not affected."
                                data-confirm-submit="vt-del-{{ $o->id }}">Delete</button>
                        <form id="vt-del-{{ $o->id }}" method="POST"
                              action="{{ route('ext.venta.orders.destroy', [$storeId, $o->id]) }}" class="x-sr">
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

<div class="x-pager">
    <span class="x-pager__count x-num">{{ number_format($orders->firstItem() ?? 0) }} to {{ number_format($orders->lastItem() ?? 0) }} of {{ number_format($orders->total()) }}</span>
    <div class="x-pager__nav">
        @if($orders->previousPageUrl())
            <x-ui.button size="sm" :href="$orders->previousPageUrl()"><x-ui.icon name="chevron-left" size="14" /> Previous</x-ui.button>
        @else
            <span class="x-btn x-btn--secondary x-btn--sm" aria-disabled="true"><x-ui.icon name="chevron-left" size="14" /> Previous</span>
        @endif
        @if($orders->nextPageUrl())
            <x-ui.button size="sm" :href="$orders->nextPageUrl()">Next <x-ui.icon name="chevron-right" size="14" /></x-ui.button>
        @else
            <span class="x-btn x-btn--secondary x-btn--sm" aria-disabled="true">Next <x-ui.icon name="chevron-right" size="14" /></span>
        @endif
    </div>
</div>
@endif

</div>{{-- /#venta-orders-page --}}
@endsection
