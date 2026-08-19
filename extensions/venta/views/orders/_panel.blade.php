@php
    // Contract identical to ext-shopee::orders._panel, ext-lazada::orders.
    // _panel and ext-tiktok::orders._panel, with one addition this channel
    // needs: a store. $panelBaseUrl is the caller's own route
    // (/venta/{store}/orders in the seller center, /integrations/orders on
    // the fulfilment hub) and $panelHiddenParams are context params the
    // caller always wants kept (channel=venta:2 on the hub, nothing on the
    // seller center, where the store rides in the URI path instead). Query
    // strings go through Arr::query() rather than http_build_query() so the
    // encoding matches byte for byte what this page produced before the
    // panel existed (RFC 3986: spaces as %20, not +).
    $panelHiddenParams = $panelHiddenParams ?? [];

    $panelSelfUrl = function (array $params = [], array $except = []) use ($panelBaseUrl, $panelHiddenParams) {
        $query = array_merge(request()->except($except), $params, $panelHiddenParams);

        return $query === [] ? $panelBaseUrl : $panelBaseUrl . '?' . \Illuminate\Support\Arr::query($query);
    };

    $panelUrl = function (array $params = []) use ($panelBaseUrl, $panelHiddenParams) {
        $query = array_merge($panelHiddenParams, $params);

        return $query === [] ? $panelBaseUrl : $panelBaseUrl . '?' . \Illuminate\Support\Arr::query($query);
    };

    // Which store this panel is showing is NOT said here. On the seller
    // center the sidebar head and the breadcrumb already name it; on the
    // fulfilment hub the panel wrapper in resources/views/integrations/
    // orders.blade.php names it once for whichever channel is open, so the
    // line is written in one place rather than copied into every multi-store
    // channel's partial.
    //
    // Presentation shaping the panel needs in every context it renders in.
    // Each line coalesces, so a caller that already defined the value (the
    // seller center computes $canManageVentaOrders and $vtScopeLabel itself,
    // because it reads them before this include for its own deck and for the
    // ltrim whitespace mirror) keeps its own.
    //
    // The page is visible to view_venta_orders holders; Fetch and both
    // delete paths render only for the manage tier. The route middleware
    // (perm:manage_venta_orders on every write POST) is the real gate; this
    // flag only stops the panel offering controls that would 403.
    $canManageVentaOrders = $canManageVentaOrders
        ?? (auth()->user()?->hasPermission('manage_venta_orders') ?? false);

    $canOpenSalesOrders = $canOpenSalesOrders ?? (bool) (auth()->user()?->hasPermission('view_orders')
        || auth()->user()?->hasPermission('manage_orders'));

    $vtQ = (string) ($filters['q'] ?? '');
    $vtPlacedFrom = (string) ($filters['placed_from'] ?? '');
    $vtPlacedTo = (string) ($filters['placed_to'] ?? '');
    $vtStatus = (string) ($filters['status'] ?? '');
    $vtHasFilters = $vtQ !== '' || $vtStatus !== '' || $vtPlacedFrom !== '' || $vtPlacedTo !== '';

    // A status name is a word, not a constant, on this channel: see the
    // OrderStatusTone note in the table below.
    /* Through the shared label class, like every other channel. This ran
       ucwords() over the storefront's raw string, which is how "In Transit
       to Manila" and the American "Canceled" reached the filter strip. An
       unmapped status still degrades to sentence case rather than shouting,
       so a status the storefront invents tomorrow reads as words. */
    $vtStatusLabel = fn (string $s) => $s !== ''
        ? \App\Support\ChannelStatusTone::labelFor('venta.orders', $s)
        : 'No status';

    // Sort links flip the direction of the column already sorted and start
    // ascending on any other, which is what the legacy header links did.
    // They go through $panelSelfUrl, so every other filter the operator has
    // set (and the caller's own context params) survives a sort.
    $vtSortUrl = fn (string $col) => $panelSelfUrl(
        ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc'],
        ['page']
    );
@endphp
@if($canManageVentaOrders)
{{-- One command bar, the same grammar as the three marketplace channels':
     every child of the row ends in a box exactly --control-h tall and the
     row is bottom aligned, so the date inputs and the button share a
     baseline and a top edge. --}}
<div class="x-cmdbar">
    {{-- See the note on Shopee's bar: a fetch writes unseen orders and
         writing an order adjusts stock, so the action names its own
         consequence before it runs. --}}
    <form method="POST" action="{{ route('ext.venta.orders.fetch', $storeId) }}" id="formFetchVentaOrders" class="x-cmdbar__row"
          data-confirm="Fetch orders from this Venta store for the chosen dates? Any order that has not been seen before will adjust stock."
          data-confirm-tone="primary">
        @csrf
        <div class="x-cmdbar__field">
            <label class="x-cmdbar__label" for="vtDateFrom">Fetch from</label>
            <x-ui.input type="date" id="vtDateFrom" name="date_from" class="x-cmdbar__date"
                        value="{{ old('date_from', request('date_from', now()->subDays(15)->format('Y-m-d'))) }}" />
        </div>
        <div class="x-cmdbar__field">
            <label class="x-cmdbar__label" for="vtDateTo">Fetch to</label>
            <x-ui.input type="date" id="vtDateTo" name="date_to" class="x-cmdbar__date"
                        value="{{ old('date_to', request('date_to', now()->format('Y-m-d'))) }}" />
        </div>
        <div class="x-cmdbar__spacer"></div>
        <div class="x-cmdbar__actions">
            <x-ui.button type="submit" variant="primary" id="btnFetchVentaOrders">Fetch orders</x-ui.button>
            <x-ui.hint>Fetching pulls the orders this store created between those two dates.</x-ui.hint>
        </div>
    </form>
</div>
@endif

{{-- Status filters as a segmented control. The statuses are whatever this
     store has actually sent, counted by the query behind the page, so the
     bar never offers a filter that would return nothing. Counts ride along
     as muted mono numbers on every item rather than only on some: a count
     that appears on some tabs and not others reads as an alert rather than
     a figure. --}}
<div class="x-segment-bar">
    {{-- Navigation, not an ARIA tablist. Each item is a link that loads a
     filtered page, and role="tablist" promises children with role="tab"
     controlling panels that switch in place - so a screen reader
     announced a tab list containing no tabs, on the control a
     fulfilment worker uses most. A nav landmark with aria-current is
     what a set of filter links actually is. --}}
    <nav class="x-segment" aria-label="Order status">
        <a href="{{ $panelSelfUrl([], ['page', 'status']) }}"
           class="x-segment__item {{ $vtStatus === '' ? 'is-active' : '' }}"
           @if($vtStatus === '') aria-current="page" @endif>
            <span>All</span>
            <span class="x-segment__count">{{ number_format($statusCounts->sum()) }}</span>
        </a>
        @foreach($statusCounts as $vtKey => $vtCount)
            @php $vtKey = (string) $vtKey; @endphp
            <a href="{{ $panelSelfUrl(['status' => $vtKey], ['page']) }}"
               class="x-segment__item {{ $vtStatus === $vtKey ? 'is-active' : '' }}"
               @if($vtStatus === $vtKey) aria-current="page" @endif>
                <span>{{ $vtStatusLabel($vtKey) }}</span>
                <span class="x-segment__count">{{ number_format($vtCount) }}</span>
            </a>
        @endforeach
    </nav>
</div>

{{-- One filter row. Search was its own card form on the legacy page, sitting
     under a second card holding the status badges; both are one job. --}}
<form method="GET" action="{{ $panelBaseUrl }}" class="x-filters" id="orders-filter">{{-- The caller's context params, kept across every filter change. The loop
     is written tight against its own lines because Blade eats the newline
     after @endforeach: laid out conventionally, a caller passing no params
     (the seller center) still moved the next input onto the form's own
     line, which is a rendered difference this page's extraction proof does
     not allow. --}}
@foreach($panelHiddenParams as $panelHiddenKey => $panelHiddenValue)    <input type="hidden" name="{{ $panelHiddenKey }}" value="{{ $panelHiddenValue }}">
@endforeach    @if($vtStatus !== '')
        <input type="hidden" name="status" value="{{ $vtStatus }}">
    @endif
    <input type="hidden" name="sort" value="{{ $sort }}">
    <input type="hidden" name="dir" value="{{ $dir }}">

    {{-- The same shape every other fulfilment list carries. "Customer"
         rather than "buyer" because that is what this person is on your own
         storefront, but the field does the same job and matches the same
         four things. Tracking stays in the list: on a storefront the courier
         reference is often all a caller has. --}}
    <x-ui.input type="search" name="q" class="x-filters__find"
                value="{{ $vtQ }}"
                placeholder="Order no, customer, product or tracking"
                aria-label="Find an order by order number, customer, product or tracking" />

    <span class="x-filters__group">
        <span class="x-filters__group-label">Placed</span>
        <x-ui.input type="date" name="placed_from" class="x-filters__date"
                    value="{{ $vtPlacedFrom }}" aria-label="Placed on or after" title="Placed on or after" />
        <x-ui.input type="date" name="placed_to" class="x-filters__date"
                    value="{{ $vtPlacedTo }}" aria-label="Placed on or before" title="Placed on or before" />
    </span>

    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="per_page" class="x-input" data-autosubmit aria-label="Orders per page">
            @foreach([10, 20, 50] as $n)
                <option value="{{ $n }}" @selected(($perPage ?? 10) === $n)>{{ $n }} per page</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>

    @if($vtHasFilters)
        <a class="x-filters__reset" href="{{ $panelUrl() }}">Reset</a>
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

        /* Whether the list the operator is looking at is narrowed - read off
         * the panel's STATE, not off the URL.
         *
         * This used to be derived from the query string alone, which is only
         * true for a panel whose unfiltered view is also its default. Shopee
         * orders defaults its tab to PENDING and TikTok to TO_SHIP, so
         * landing on either carries no query parameters at all: the check
         * said "not filtered", the way out was suppressed, and the operator
         * stood on "No orders in To Pack right now" with one order sitting in
         * To Handover and nothing pointing at it. The one case where they are
         * most stuck was the one case with no exit.
         *
         * page is excluded because paging is not narrowing, tab=ALL because
         * that is the unnarrowed state, and the hidden params because they
         * identify the panel rather than filter it. */
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
{{-- SELECTION, on the shared bar every other channel now uses.
     This page invented the selection model and then kept its own copy of it
     while the pattern was generalised, so the storefront - the one channel
     where the seller controls fulfilment end to end - had checkboxes whose
     only destination was Delete. It gets the same two print sheets as the
     marketplaces, and keeps the delete it already had.

     ONE FORM, THREE BUTTONS, because a checkbox can only belong to one form.
     The form posts the pick list; the other two carry formaction. The delete
     keeps its own confirmation and the prints need none - which works because
     the dialog now follows the button that was pressed rather than the form
     it sits in.

     At rest the bar offers the select-all box and nothing else: the
     destructive button only exists once the operator has said what it would
     act on, and the count sits beside it so the number about to go is never
     off screen from the control that removes them. --}}
<div data-order-selection>
    <x-ui.selectbar>
        <form method="POST" action="{{ route('fulfilment.print.pick', ['channel' => 'venta']) }}"
              id="venta-bulk-form" class="x-selectbar__actions" data-selection-actions hidden>
            @csrf
            <x-ui.button type="submit" variant="secondary" size="sm" formtarget="_blank">Print pick list</x-ui.button>
            <x-ui.button type="submit" variant="secondary" size="sm" formtarget="_blank"
                         formaction="{{ route('fulfilment.print.packing', ['channel' => 'venta']) }}">Print packing lists</x-ui.button>
            <x-ui.button type="submit" variant="danger" size="sm"
                         formaction="{{ route('ext.venta.orders.bulk_delete', $storeId) }}"
                         data-confirm="Delete the selected orders from this store? The sales orders they created are not affected.">Delete selected</x-ui.button>
        </form>
    </x-ui.selectbar>
@endif
<x-ui.table caption="Storefront orders" class="x-table--stack">
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
            /* The tone from the same class as the label. This called
               OrderStatusTone, whose warning list knows "ready to ship" but
               not this storefront's "Start Pickup" or "In Transit to
               Manila", so the two statuses that most need a colour rendered
               neutral grey. */
            $vtTone = \App\Support\ChannelStatusTone::toneFor('venta.orders', $vtStatusRaw);
        @endphp
        <tr class="co-band">
            <td colspan="9">
                <div class="co-band__row">
                    <div class="co-band__left">
                        @if($canManageVentaOrders)
                            <input type="checkbox" class="x-pick" data-selection-item name="ids[]"
                                   value="{{ $o->id }}" form="venta-bulk-form"
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
                @if($vtTracking !== '')
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
                    {{-- A read-only operator opens the sales order from the
                         reference in the band above and has nothing else to
                         do to the row, so the column prints a dash rather
                         than an empty control. --}}
                    <span class="co-empty-mark">-</span>
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
</div>{{-- /selection --}}

<x-ui.pager :paginator="$orders" />
@endif
