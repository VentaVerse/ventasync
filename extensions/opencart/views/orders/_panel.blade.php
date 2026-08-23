@php
    // Contract identical to ext-venta::orders._panel, and to the three
    // single-store channels' panels it descends from. $panelBaseUrl is the
    // caller's own route (/opencart/{store}/orders in the seller center,
    // /channels/fulfilment on the fulfilment hub) and $panelHiddenParams are
    // context params the caller always wants kept (channel=opencart:2 on the
    // hub, nothing on the seller center, where the store rides in the URI
    // path instead). Query strings go through Arr::query() rather than
    // http_build_query() so the encoding matches byte for byte what this
    // page produced before the panel existed (RFC 3986: spaces as %20).
    //
    // Which store this panel is showing is not said here: the seller center
    // has it in the sidebar head and the breadcrumb, and the hub's panel
    // wrapper names it once for whichever channel is open.
    $panelHiddenParams = $panelHiddenParams ?? [];

    $panelSelfUrl = function (array $params = [], array $except = []) use ($panelBaseUrl, $panelHiddenParams) {
        $query = array_merge(request()->except($except), $params, $panelHiddenParams);

        return $query === [] ? $panelBaseUrl : $panelBaseUrl . '?' . \Illuminate\Support\Arr::query($query);
    };

    $panelUrl = function (array $params = []) use ($panelBaseUrl, $panelHiddenParams) {
        $query = array_merge($panelHiddenParams, $params);

        return $query === [] ? $panelBaseUrl : $panelBaseUrl . '?' . \Illuminate\Support\Arr::query($query);
    };

    // This surface is read-only on the channel's own routes: OpenCart
    // declares no order write routes at all. The two mutating controls a row
    // offers, Edit and Delete, are CORE order routes, so they are gated on
    // the core orders permission rather than on an opencart_orders one that
    // nothing would check. Coalesced, because the seller center reads the
    // same flag before this include for its own page header.
    $canManageOcOrders = $canManageOcOrders
        ?? (auth()->user()?->hasPermission('manage_orders') ?? false);

    // Opening a sales order needs the core read tier. The legacy page linked
    // there from every row regardless, so an operator holding only
    // view_opencart_orders was offered a View button that 403s.
    $canOpenOcOrders = $canOpenOcOrders ?? ($canManageOcOrders
        || (auth()->user()?->hasPermission('view_orders') ?? false));

    $ocQ = (string) ($filters['q'] ?? '');
    $ocStatusId = (int) ($filters['status'] ?? 0);
    $ocHasFilters = $ocQ !== '' || $ocStatusId > 0;

    // Sort links flip the direction of the column already sorted and start
    // ascending on any other. They go through $panelSelfUrl, so every other
    // filter the operator has set (and the caller's own context params)
    // survives a sort.
    $ocSortUrl = fn (string $col) => $panelSelfUrl(
        ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc'],
        ['page']
    );
@endphp
{{-- One filter row. There is no command bar on this page because there is no
     command: an OpenCart storefront's orders arrive through the sync cron,
     and this channel exposes no fetch, ship or update route for a person to
     press. Inventing a button for a route that does not exist would be worse
     than the empty space.

     Status stays a select rather than becoming a segmented control. The
     statuses come from the ERP's own order_status table, which an admin adds
     to and renames from Settings; a segment built on a list of fifteen
     admin-defined names is a wall, not a choice. --}}
{{-- Phone only. The fetch bar and the filter row are a desk's tools, not
     a bench's - measured at 443px of the 887px a packer scrolled past before
     the first order - so below the table's restack width they start folded
     behind this and one tap brings them back. Nothing is removed and nothing
     moves; above that width this button does not exist. --}}
<button type="button" class="x-desk-toggle" data-desk-toggle aria-expanded="true">
    <span data-desk-label>Find, dates and sync</span>
    <span class="x-desk-toggle__mark" aria-hidden="true">&#9662;</span>
</button>

<form method="GET" action="{{ $panelBaseUrl }}" class="x-filters" data-desk-tool id="orders-filter">{{-- The caller's context params, kept across every filter change. The loop
     is written tight against its own lines because Blade eats the newline
     after @endforeach: laid out conventionally, a caller passing no params
     (the seller center) still moved the next input onto the form's own
     line, which is a rendered difference this page's extraction proof does
     not allow. --}}
@foreach($panelHiddenParams as $panelHiddenKey => $panelHiddenValue)    <input type="hidden" name="{{ $panelHiddenKey }}" value="{{ $panelHiddenValue }}">
@endforeach    <input type="hidden" name="sort" value="{{ $sort }}">
    <input type="hidden" name="dir" value="{{ $dir }}">

    <x-ui.input type="search" name="q" class="x-filters__search"
                value="{{ $ocQ }}" placeholder="Customer, email or order number" aria-label="Search orders" />

    <div class="x-select-wrap x-filters__select">
        <select name="status" class="x-input" data-autosubmit aria-label="Filter by status">
            <option value="0">All statuses</option>
            @foreach($statuses as $ocS)
                <option value="{{ $ocS->order_status_id }}" @selected($ocStatusId === (int) $ocS->order_status_id)>{{ $ocS->name }}</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>

    @if($ocHasFilters)
        <a class="x-filters__reset" href="{{ $panelUrl() }}">Reset</a>
    @endif
</form>

@if($orders->count() === 0)
    @php
        $ocEmptyDesc = $ocHasFilters
            ? 'Nothing matches those filters. Clear them to see the full list.'
            : 'Nothing has synced from this storefront yet. Orders appear here as soon as the OpenCart sync brings them in.';

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
    <x-ui.empty title="No orders here" :description="$ocEmptyDesc">
        @if($emptyFiltered)
            <x-slot:action>
                <x-ui.button variant="secondary" :href="isset($active_tab) ? $panelUrl(['tab' => 'ALL']) : $panelUrl()">Show all orders</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table caption="OpenCart orders" class="x-table--stack">
    <x-slot:head>
        {{-- The legacy column format the other channels settled on: one row
             per item so Qty and Price align in their own columns, and the
             order-level facts (total, status, date, actions) rowspan beside
             the item rows. Order number, customer and the marketplace
             reference ride in a band above each order.

             The legacy page carried a Store column whose value was identical
             on every row, because this page is one store. It is gone, and its
             width went to the product titles.

             Sorting stays on the two headers that survived the move to bands.
             The controller still accepts order_id and firstname, which used
             to be sortable columns and are now band facts; nothing rejects
             them, they simply have no header to click. --}}
        <tr>
            <th scope="col" class="oc-col-products">Products</th>
            <th scope="col" class="oc-col-qty">Qty</th>
            <th scope="col" class="oc-col-price x-td-num">Price</th>
            <th scope="col" class="oc-col-total x-td-num">
                <a class="x-th-sort x-th-sort--num" href="{{ $ocSortUrl('total') }}">
                    Total
                    @if($sort === 'total')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="oc-col-status">Status</th>
            <th scope="col" class="oc-col-date">
                <a class="x-th-sort" href="{{ $ocSortUrl('date_added') }}">
                    Date
                    @if($sort === 'date_added')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="oc-col-act"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($orders as $o)
        @php
            $ocCustomer = trim($o->firstname . ' ' . $o->lastname);
            $ocExternal = trim((string) ($o->marketplace_order_id ?? ''));

            $ocItems = $o->products ? $o->products->all() : [];
            // Legacy shape: an order with no recorded items still renders one
            // empty item row under its band, rather than a band with nothing
            // attached to it.
            $ocRows = $ocItems !== [] ? $ocItems : [null];
            $ocLineCount = count($ocRows);
            $ocItemCount = count($ocItems);

            // App\Support\OrderStatusTone, not ChannelStatusTone. These rows
            // ARE core catalog orders (the OpenCart sync writes into the ERP's
            // own order tables), so the status is a row of the live,
            // admin-editable order_status table, which is exactly the input
            // OrderStatusTone was written for. The core Orders list and the
            // fulfilment hub's activity feed already read it through the same
            // helper, so one status has one colour everywhere it appears.
            $ocStatusName = $o->status->name ?? '';
            $ocTone = \App\Support\OrderStatusTone::for($ocStatusName);
        @endphp
        <tr class="co-band">
            <td colspan="7">
                <div class="co-band__row">
                    <div class="co-band__left">
                        <span class="co-band__label">Order</span>
                        @if($canOpenOcOrders)
                            <a class="co-sn" href="{{ route('orders.show', $o->order_id) }}">{{ $o->order_id }}</a>
                        @else
                            <span class="co-band__value co-band__value--mono">{{ $o->order_id }}</span>
                        @endif
                        <span class="co-band__label">Customer</span>
                        @if($ocCustomer !== '')
                            <span class="co-band__value">{{ $ocCustomer }}</span>
                        @else
                            <span class="co-empty-mark">Guest</span>
                        @endif
                        @if($ocItemCount > 0)
                            <span class="co-band__muted">({{ $ocItemCount }} {{ $ocItemCount === 1 ? 'item' : 'items' }})</span>
                        @endif
                    </div>
                    <div class="co-band__right">
                        @if($ocExternal !== '')
                            <span class="co-band__label">Marketplace order</span>
                            <span class="oc-ext">{{ $ocExternal }}</span>
                        @endif
                    </div>
                </div>
            </td>
        </tr>
        @foreach($ocRows as $ocIdx => $ocItem)
            @php
                // The shared image pipeline resolves storage-relative paths
                // for catalog products and absolute URLs for Lazada-sourced
                // ones, both keyed by order_product_id. Same two-step fallback
                // the legacy page ran, applied per item rather than only to
                // the first product of the order.
                $ocImgSrc = '';
                if ($ocItem) {
                    $ocPath = trim((string) ($productImages[$ocItem->order_product_id] ?? ''));
                    if ($ocPath !== '') {
                        $ocImgSrc = asset('storage/' . ltrim($ocPath, '/'));
                    } else {
                        $ocImgSrc = trim((string) ($lazadaImages[$ocItem->order_product_id] ?? ''));
                    }
                }
            @endphp
        <tr class="co-itemrow {{ $ocIdx === $ocLineCount - 1 ? 'co-itemrow--last' : '' }}">
            <td class="oc-col-products" data-label="Products">
                <div class="co-item">
                    <div class="order-img-wrap">
                        @if($ocImgSrc !== '')
                            <img class="order-img" src="{{ $ocImgSrc }}" alt="" loading="lazy" decoding="async">
                        @else
                            <span class="co-item__none">No image</span>
                        @endif
                    </div>
                    <div class="co-item__body">
                        <span class="co-item__name">{{ trim((string) ($ocItem?->name ?? '')) !== '' ? $ocItem->name : 'Unnamed product' }}</span>
                        <div class="co-item__meta">
                            @if(trim((string) ($ocItem?->model ?? '')) !== '')
                                <span class="co-item__sku">{{ $ocItem->model }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </td>

            <td class="oc-col-qty" data-label="Qty">
                <span class="co-qty">&times;{{ max(1, (int) ($ocItem?->quantity ?? 1)) }}</span>
            </td>

            <td class="oc-col-price x-td-num" data-label="Price">
                @if($ocItem && (float) $ocItem->price > 0)
                    <span class="co-price">
                        <x-money :php="(float) $ocItem->price" :foreign="$ocItem->foreign_price" :foreign-code="$o->currency_code" />
                    </span>
                @else
                    <span class="co-empty-mark">-</span>
                @endif
            </td>

            @if($ocIdx === 0)
            <td class="oc-col-total x-td-num" rowspan="{{ $ocLineCount }}" data-label="Total">
                <span class="co-total">
                    <x-money :php="(float) $o->total" :foreign="$o->foreign_total" :foreign-code="$o->currency_code" />
                </span>
            </td>

            <td class="oc-col-status" rowspan="{{ $ocLineCount }}" data-label="Status">
                <div class="co-status">
                    @if($ocStatusName !== '')
                        <x-ui.badge :tone="$ocTone">{{ $ocStatusName }}</x-ui.badge>
                    @else
                        <span class="co-empty-mark">-</span>
                    @endif
                </div>
            </td>

            <td class="oc-col-date" rowspan="{{ $ocLineCount }}" data-label="Date">
                <span class="co-date">{{ \Illuminate\Support\Carbon::parse($o->date_added)->format('Y-m-d H:i') }}</span>
            </td>

            <td class="oc-col-act" rowspan="{{ $ocLineCount }}">
                @if(!$canManageOcOrders)
                    {{-- A read-only operator opens the order from its number
                         in the band above and has nothing else to do to it,
                         so the column prints a dash rather than an empty
                         control. --}}
                    <span class="co-empty-mark">-</span>
                @else
                <div class="co-actions">
                    {{-- Fifty rows used to carry a View, an Edit and a solid
                         red Delete each, which put a hundred and fifty
                         controls on one screen and fifty of them destructive.
                         The order number in the band is the way in now, and
                         the two secondary moves live behind one overflow
                         trigger that appears on hover or focus. --}}
                    <x-ui.menu label="Order {{ $o->order_id }} actions">
                        <a class="x-menu__item" href="{{ route('orders.edit', $o->order_id) }}">Edit</a>
                        <div class="x-menu__sep"></div>
                        <button type="button" class="x-menu__item x-menu__item--danger"
                                data-confirm="Delete order {{ $o->order_id }}? This cannot be undone."
                                data-confirm-submit="oc-del-{{ $o->order_id }}">Delete</button>
                        <form id="oc-del-{{ $o->order_id }}" method="POST"
                              action="{{ route('orders.destroy', $o->order_id) }}" class="x-sr">
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

<x-ui.pager :paginator="$orders" />
@endif
