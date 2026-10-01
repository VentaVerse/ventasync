@php
    // Every self link in this panel is built from $panelBaseUrl (the
    // caller's own route: /shopee/orders in the seller center, and the
    // fulfilment hub's own route once it switches this panel in place)
    // plus $panelHiddenParams (context params the caller always wants kept,
    // e.g. channel=shopee on the hub). Query strings go through
    // Arr::query() rather than http_build_query() so the encoding matches
    // byte for byte what the old hardcoded route() calls on this page
    // produced before this panel existed (RFC 3986: spaces as %20, not +).
    $panelHiddenParams = $panelHiddenParams ?? [];

    $panelSelfUrl = function (array $params = [], array $except = []) use ($panelBaseUrl, $panelHiddenParams) {
        $query = array_merge(request()->except($except), $params, $panelHiddenParams);

        return $query === [] ? $panelBaseUrl : $panelBaseUrl . '?' . \Illuminate\Support\Arr::query($query);
    };

    $panelUrl = function (array $params = []) use ($panelBaseUrl, $panelHiddenParams) {
        $query = array_merge($panelHiddenParams, $params);

        return $query === [] ? $panelBaseUrl : $panelBaseUrl . '?' . \Illuminate\Support\Arr::query($query);
    };

    // Presentation shaping the panel needs in every context it renders in.
    // It lived in orders/index.blade.php while the seller center was the
    // only caller; the fulfilment hub is the second, and a second copy of
    // the status tone and label maps in another view is exactly how the two
    // surfaces would start drifting apart. Each line coalesces, so a caller
    // that already defined the value (index.blade.php still computes
    // $canManageShopeeOrders itself, because it reads it before this
    // include) keeps its own.
    //
    // The page is visible to view_shopee_orders holders; Fetch, Update,
    // Ship and Reset render only for the manage tier. The route middleware
    // (perm:manage_shopee_orders on every write POST) is the real gate; this
    // flag only stops the panel offering buttons that would 403.
    $canManageShopeeOrders = $canManageShopeeOrders
        ?? (auth()->user()?->hasPermission('manage_shopee/order') ?? false);

    $tabs = $tabs ?? [];
    $active_tab = strtoupper((string) ($active_tab ?? 'ALL'));
    $tab_counts = $tab_counts ?? [];
    $pending_sub_counts = $pending_sub_counts ?? [];
    $perPage = (int) ($per_page ?? request()->query('per_page', 10));
    $findFilter = (string) ($filters['q'] ?? '');
    $placedFromFilter = (string) ($filters['placed_from'] ?? '');
    $placedToFilter = (string) ($filters['placed_to'] ?? '');
    $hasSearch = $findFilter !== '' || $placedFromFilter !== '' || $placedToFilter !== '';
    $pendingSub = $pending_subtab ?? request()->query('pending_sub', 'to_pack');

    // The status tone and label maps that used to sit here now live in
    // App\Support\ChannelStatusTone under the 'shopee.orders' surface, moved
    // there verbatim when Lazada's conversion would have created the third
    // and fourth copies of the same idea. The reasoning that produced them
    // travelled with them and is documented on that class: accent for the
    // one status that is the operator's move, neutral for settled, danger
    // for failures, warning and info for the two kinds of waiting. Nothing
    // about this panel's rendering changed in the move.
    $spSortLabels = $spSortLabels ?? [
        'created_desc'          => 'Order date, newest first',
        'created_asc'           => 'Order date, oldest first',
        'confirmed_pay_desc'    => 'Payment time, newest first',
        'confirmed_pay_asc'     => 'Payment time, oldest first',
        'confirmed_update_desc' => 'Last update, newest first',
        'confirmed_update_asc'  => 'Last update, oldest first',
        'confirmed_create_desc' => 'Shopee create time, newest first',
        'confirmed_create_asc'  => 'Shopee create time, oldest first',
        'ship_by_asc'           => 'Ship-by time, soonest first',
        'ship_by_desc'          => 'Ship-by time, latest first',
    ];
@endphp
@if($canManageShopeeOrders)
{{-- One command bar. This replaces the three stacked forms whose controls
     each set their own height and self-alignment, which is what made the
     Fetch and Update buttons sit off the baseline of their own date
     inputs. Every child of the row ends in a box exactly --control-h tall
     and the row is bottom aligned, so the controls share a baseline and,
     being the same height, a top edge too. --}}
@endif

@if($last_result)
    <div class="co-notice {{ $last_result['ok'] ? 'co-notice--ok' : 'co-notice--fail' }}" role="status" aria-live="polite">
        <span class="co-notice__title">{{ $last_result['ok'] ? 'Done' : 'Failed' }}</span>
        @if(!empty($last_result['message']))
            <span class="co-notice__body">{{ $last_result['message'] }}</span>
        @endif
    </div>
@endif

{{-- Status filters as a segmented control. Seven views over one list, so
     "pick one" is the right grammar; the old .tabs strip implied seven
     pages. Counts ride along as muted mono numbers. Returns is a sibling
     page rather than an eighth filter, so it sits outside the trough as a
     plain link (the channel sidebar lists it too). --}}
{{-- Phone only. The fetch bar and the filter row are a desk's tools, not
     a bench's - measured at 443px of the 887px a packer scrolled past before
     the first order - so below the table's restack width they start folded
     behind this and one tap brings them back. Nothing is removed and nothing
     moves; above that width this button does not exist. --}}
<button type="button" class="x-desk-toggle" data-desk-toggle aria-expanded="true">
    <span data-desk-label>Find, dates and sync</span>
    <span class="x-desk-toggle__mark" aria-hidden="true">&#9662;</span>
</button>

<div class="x-segment-bar">
    {{-- Navigation, not an ARIA tablist. Each item is a link that loads a
     filtered page, and role="tablist" promises children with role="tab"
     controlling panels that switch in place - so a screen reader
     announced a tab list containing no tabs, on the control a
     fulfilment worker uses most. A nav landmark with aria-current is
     what a set of filter links actually is. --}}
    <nav class="x-segment" aria-label="Order status">
        @foreach($tabs as $key => $label)
            @php
                $isActive = $active_tab === strtoupper((string) $key);
                $count = $tab_counts[$key] ?? null;
            @endphp
            {{-- A tab with nothing in it stays clickable.
                 It was briefly rendered without an href, on the reasoning that
                 spending a click to be told what the tab already told you is
                 waste. The owner asked for it back, and the reasoning was thinner
                 than it looked: a count is a number the last sync produced,
                 and going to the tab is how an operator checks whether that is
                 still true. A control that refuses the check is arguing with
                 the person using it. --}}
            <a href="{{ $panelSelfUrl(['tab' => $key], ['page', 'pending_sub', 'courier']) }}"
               class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
               @if($isActive) aria-current="true" @endif>
                <span>{{ $label }}</span>
                @if($count !== null)
                    <span class="x-segment__count">{{ number_format($count) }}</span>
                @endif
            </a>
        @endforeach
    </nav>
    {{-- Returns lives wherever this panel lives. On the hub the caller
         hands in its own URL so the list swaps in place; in the seller
         centre it is this channel's returns route. It used to be that route
         unconditionally, which is why opening it from the hub threw the
         operator into the channel shell. --}}
    <a class="x-segment-bar__aside" href="{{ $panelReturnsUrl ?? route('ext.shopee.orders.returns') }}">
        Returns and refunds
        <x-ui.icon name="chevron-right" size="12" />
    </a>
</div>

@if($active_tab === 'PENDING')
    @php
        $pendingNav = ['to_pack' => 'To Pack', 'to_handover' => 'To Handover'];
    @endphp
    {{-- The one tab with two working steps. The sub row drops the trough so
         it cannot be mistaken for a peer of the row above it. --}}
    <div class="x-segment x-segment--sub" aria-label="To Ship step">
        @foreach($pendingNav as $k => $lbl)
            @php
                $isActive = ($pendingSub === $k) || ($pendingSub === '' && $k === 'to_pack');
                $count = $pending_sub_counts[$k] ?? null;
            @endphp
            {{-- A sub step keeps its href at zero, unlike the top level tabs
                 above. These split one queue between them, and the split
                 empties one side into the other as the work gets done - To
                 Pack drains into To Handover over a morning. A zero here is a
                 moment in that loop rather than a settled state, so making the
                 control inert would have it flicker in and out of being
                 clickable while someone is working through it. --}}
            <a href="{{ $panelSelfUrl(['tab' => 'PENDING', 'pending_sub' => $k], ['page', 'courier']) }}"
               class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
               {{-- "step", not "page": both strips describe the same URL, and two
                     aria-current="page" in one document leaves assistive tech
                     to guess which is the location. The sub level is a step
                     inside the tab above it, which is the value's own
                     meaning. --}}
                @if($isActive) aria-current="step" @endif>
                <span>{{ $lbl }}</span>
                @if($count !== null)
                    <span class="x-segment__count">{{ number_format($count) }}</span>
                @endif
            </a>
        @endforeach
    </div>

    {{-- The courier split of the open To Ship step, and the filter it drives:
         whose rider comes first is decided at To Pack as much as at To
         Handover. See components/fulfilment/courier-strip. --}}
    @if($pendingSub !== '')
        <x-fulfilment.courier-strip :tally="$courier_tally ?? []" :self-url="$panelSelfUrl" />
    @endif
@endif

{{-- One filter row. Search, sort and per page were three separate card
     forms doing one job. The selects auto-submit through the shared
     data-autosubmit handler that /sales/orders already uses. --}}
@php
    /* What the Filters button admits to. A fold that hides an active filter
       is worse than no fold. Sort and page size count only when they are not
       the default, because a default is not a choice the reader made. */
    $spActiveFilters = 0;
    if (($placedFromFilter ?? '') !== '') { $spActiveFilters++; }
    if (($placedToFilter ?? '') !== '') { $spActiveFilters++; }
    if (($sort ?? '') !== '' && ($sort ?? '') !== ('created_' . (($active_tab ?? '') === 'PENDING' ? 'asc' : 'desc'))) { $spActiveFilters++; }
    if ((int) ($perPage ?? 10) !== 10) { $spActiveFilters++; }
@endphp

{{-- One toolbar, after the tabs: choose the queue, then narrow it. --}}
<div class="x-toolbar">
<form method="GET" action="{{ $panelBaseUrl }}" class="x-filters" data-desk-tool id="orders-filter">
@foreach($panelHiddenParams as $panelHiddenKey => $panelHiddenValue)
        <input type="hidden" name="{{ $panelHiddenKey }}" value="{{ $panelHiddenValue }}">
@endforeach
    <input type="hidden" name="tab" value="{{ $active_tab }}">
    @if($active_tab === 'PENDING')
        <input type="hidden" name="pending_sub" value="{{ $pendingSub }}">
        @if(($filters['courier'] ?? '') !== '')
            <input type="hidden" name="courier" value="{{ $filters['courier'] }}">
        @endif
    @endif

    {{-- One box, matching whatever the caller just said. See the note in
         the panel service for why this replaced an order-number field beside
         a buyer field. --}}
    <x-ui.input type="search" name="q" class="x-filters__find"
                value="{{ $findFilter }}"
                placeholder="Order no, buyer, product or tracking"
                aria-label="Find an order by order number, buyer, product or tracking" />

    {{-- When the order was placed. This list has never had a date filter of
         any kind - the only dates on the page were the command bar's, which
         tell the marketplace what to send and do not touch the rows below
         them. A caller with "sometime last week" and a product name could not
         narrow this list at all. --}}
    {{-- Folded, with the count of what is set. Search stays out: it is the
         control used most. See ext-lazada's panel for the reasoning; both
         hosts fold the same way so a packer crossing channels does not
         relearn the toolbar. --}}
    <x-ui.foldout label="Filters" :count="$spActiveFilters ?: null">
    <span class="x-filters__group">
            <span class="x-filters__group-label">Show orders placed</span>
            <x-ui.input type="date" name="placed_from" class="x-filters__date"
                        value="{{ $placedFromFilter }}" aria-label="Placed on or after" title="Placed on or after" />
            <x-ui.input type="date" name="placed_to" class="x-filters__date"
                        value="{{ $placedToFilter }}" aria-label="Placed on or before" title="Placed on or before" />
        </span>

        <div class="x-select-wrap x-filters__select x-filters__select--wide">
            <select name="sort" class="x-input" data-autosubmit aria-label="Sort orders">
                @foreach($spSortLabels as $value => $label)
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

@if($canManageShopeeOrders)
    {{-- The marketplace pull, folded. Used a few times a day, not per order,
         and it wrote to stock from above every row on every page load. --}}
    <x-ui.foldout label="Sync" class="x-toolbar__sync"
                  hint="Pulls orders from Shopee for the dates below. Fetching an order that has not been seen before adjusts stock.">
        <div class="x-cmdbar x-cmdbar--folded">
            <form method="POST" action="{{ route('ext.shopee.orders.fetch') }}" id="formFetchShopeeOrders" class="x-cmdbar__row"
              data-confirm="Fetch Shopee orders for the chosen dates? Any order that has not been seen before will adjust stock."
              data-confirm-tone="primary">
            @csrf
            <div class="x-cmdbar__field">
                <label class="x-cmdbar__label" for="spDateFrom">Pull orders from</label>
                {{-- Pre-filled, as TikTok's bar already was. Both dates are
                     required, so a blank pair meant the page's only primary
                     button could not succeed in its default state and said so
                     only inside a hint bubble. --}}
                <x-ui.input type="date" id="spDateFrom" name="date_from" class="x-cmdbar__date"
                            value="{{ old('date_from', request('date_from', now()->subDays(15)->format('Y-m-d'))) }}" />
            </div>
            <div class="x-cmdbar__field">
                <label class="x-cmdbar__label" for="spDateTo">Pull orders to</label>
                <x-ui.input type="date" id="spDateTo" name="date_to" class="x-cmdbar__date"
                            value="{{ old('date_to', request('date_to', now()->format('Y-m-d'))) }}" />
            </div>
            {{-- "Skip stock adjust" was here, and it is retired 2026-08-19.
    
                 It was opt-OUT, so the default behaviour of this page's primary
                 button was to move inventory, and the control that stopped it
                 read as "do less" while meaning "let the stock ledger diverge,
                 permanently, with no audit line and no undo". Nobody had
                 documented why it existed; the reason turned out to be a
                 development-era double-decrement that the sync's own dedupe
                 (marketplace_source + marketplace_order_id) and the
                 status-change guard in adjustStock have since closed. The owner
                 confirmed he has never used it on live.
    
                 The one legitimate use survives where it belongs: OpenCart's
                 historical import, behind the --no-stock flag on its sync
                 command, where skipping stock for years of already-fulfilled
                 orders is the correct behaviour rather than a trap. --}}
            <div class="x-cmdbar__spacer"></div>
            <div class="x-cmdbar__actions">
                <x-ui.button type="submit" variant="primary" id="btnFetchOrders">Fetch orders</x-ui.button>
                <x-ui.button type="submit" variant="secondary" id="btnUpdateOrders"
                             data-confirm="Re-read the statuses of Shopee orders in the chosen dates? Existing orders are updated in place; no new orders are pulled."
                             data-confirm-tone="primary"
                             formaction="{{ route('ext.shopee.orders.update_statuses') }}">Update statuses</x-ui.button>
                <x-ui.hint>Fetch pulls the orders created between these dates. Update statuses uses the same two dates, so both are required.</x-ui.hint>
            </div>
        </form>
        <x-channel.order-fetch integration="shopee" :store-id="\Extensions\shopee\Models\ShopeeSetting::defaultStore()?->id" form-id="formFetchShopeeOrders" channel-label="Shopee"
            :begin-url="route('ext.shopee.orders.fetch_run_begin')" :step-url="route('ext.shopee.orders.fetch_run_step', ['run' => 0])" :stop-url="route('ext.shopee.orders.fetch_run_stop', ['run' => 0])" :state-url="route('ext.shopee.orders.fetch_run_state', ['run' => 0])" />
        </div>
    </x-ui.foldout>
@endif
</div>

@if($orders->count() === 0)
    @php
        if ($hasSearch) {
            $spEmptyDesc = 'Nothing matches that search. Clear it to see the full list.';
        } elseif ($active_tab !== 'ALL') {
            // Keep the tab's own capitalisation: lowercasing it produced
            // "no to ship orders", which reads broken.
            //
            // Name the queue actually being listed, not the tab above it.
            // Standing on To Pack, this said "No orders in To Ship right
            // now" - naming a parent that may well have orders in its other
            // step, and contradicting the sub-tab just clicked. TikTok's
            // panel already derives its label this way.
            $spScopeLabel = $tabs[$active_tab] ?? $active_tab;
            if ($active_tab === 'PENDING') {
                $spScopeLabel = ['to_pack' => 'To Pack', 'to_handover' => 'To Handover'][$pendingSub] ?? $spScopeLabel;
            }
            $spEmptyDesc = 'No orders in ' . $spScopeLabel . ' right now.';
        } else {
            $spEmptyDesc = 'Nothing has been synced yet. Pick a date range above and fetch orders from Shopee.';
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
    <x-ui.empty title="No orders here" :description="$spEmptyDesc">
        @if($emptyFiltered)
            <x-slot:action>
                <x-ui.button variant="secondary" :href="isset($active_tab) ? $panelUrl(['tab' => 'ALL']) : $panelUrl()">Show all orders</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else

{{-- SELECTION, and the two things a warehouse does with one.
     The critique measured what a packer does with a To Ship queue of
     fifty-one orders at ten to a page: open one, print, go back, open the
     next. These two sheets replace that with one submission.

     Both are reads. Neither contacts Shopee - the sync already stored these
     orders' products - so a selection of forty costs forty rows of SQL and
     no rate limit. That is also why the bar renders for the view tier and
     not only for manage: printing a pick list reveals nothing the operator
     cannot already read on the row.

     data-order-selection scopes the module to this list; the two forms both
     take the ticked boxes because a checkbox names its form by id. --}}
<div data-order-selection>
    <x-ui.selectbar :step="$active_tab !== 'ALL' ? ($tabs[$active_tab] ?? null) : null">
        <form method="POST" action="{{ route('fulfilment.print.pick', ['channel' => 'shopee']) }}"
              id="shopee-print-pick" class="x-selectbar__actions" target="_blank" data-selection-actions hidden>
            @csrf
            <x-ui.button type="submit" variant="secondary" size="sm">Print pick list</x-ui.button>
            <x-ui.button type="submit" variant="secondary" size="sm"
                         formaction="{{ route('fulfilment.print.packing', ['channel' => 'shopee']) }}">Print packing lists</x-ui.button>
            <x-ui.hint label="About these two lists">One pick list for the whole selection, with lines consolidated by SKU so three of the same item is one trip to the shelf. Packing lists are one per order, nothing consolidated, to go in the parcel.</x-ui.hint>
        </form>
    </x-ui.selectbar>

<x-ui.table caption="{{ 'Shopee orders' . ($active_tab !== 'ALL' && isset($tabs[$active_tab]) ? ' in ' . $tabs[$active_tab] : '') }}" class="x-table--stack">
    <x-slot:head>
        {{-- The legacy column format, copied at the owner's direction: one row
             per item so Qty and Price align in their own columns, and the
             order-level facts (amount, shipping, status, actions) rowspan
             beside the item rows. The buyer, order number and create time
             ride in a band above each order, exactly where the legacy page
             carried them. --}}
        <tr>
            <th scope="col" class="sp-col-products">Products</th>
            <th scope="col" class="sp-col-qty">Qty</th>
            <th scope="col" class="sp-col-price x-td-num">Price</th>
            <th scope="col" class="sp-col-total x-td-num">Amount</th>
            <th scope="col" class="sp-col-ship">Shipping</th>
            <th scope="col" class="sp-col-status">Status</th>
            <th scope="col" class="sp-col-act"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($orders as $o)
        @php
            $raw = $o->raw ?? [];

            $orderSn = $o->order_sn ?? ($raw['order_sn'] ?? '');
            $status = $o->status ?? ($raw['order_status'] ?? '');
            $statusStr = is_string($status) ? $status : '';
            $statusNorm = strtoupper(trim($statusStr));

            $isUnpaid = ($statusNorm === 'UNPAID');
            $isToPack = ($statusNorm === 'READY_TO_SHIP');
            $isToHandover = ($statusNorm === 'PROCESSED');
            $isShipped = ($statusNorm === 'SHIPPED');

            $awaitingShip = in_array($statusNorm, ['UNPAID', 'READY_TO_SHIP', 'PROCESSED'], true);
            $shipBy = $awaitingShip ? (int) ($raw['ship_by_date'] ?? 0) : 0;

            $created = $o->order_created_at ? $o->order_created_at->format('Y-m-d H:i') : null;
            $buyer = (string) ($raw['buyer_username'] ?? ($raw['buyer_user_name'] ?? ''));

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

                $key = mb_strtolower(trim((string)$gSku)) . '|' . mb_strtolower(trim((string)$gName)) . '|' . mb_strtolower(trim((string)$gVariation));
                $gPrice = $row['price'] ?? $rowRaw['model_discounted_price'] ?? $rowRaw['model_original_price'] ?? null;

                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
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

            // Legacy shape: an order with no recorded items still renders one
            // empty item row under its band, rather than a band with nothing
            // attached to it.
            $displayItems = $groupedItems !== [] ? $groupedItems : [[]];
            $lineCount = count($displayItems);
            $itemCount = count($groupedItems);

            $totalAmount = $raw['total_amount'] ?? $raw['escrow_amount'] ?? null;
            // Currency is resolved through App\Support\Money, which reads the
            // symbol from the currencies table, so no symbol is ever written
            // into a view. An order carrying a code nobody has configured
            // locally falls back to "CODE 1,234.00" rather than a wrong glyph.
            $currencyCode = (string) ($raw['currency'] ?? '');
            $paymentMethod = $raw['payment_method'] ?? null;
            $courier = $raw['shipping_carrier'] ?? $raw['checkout_shipping_carrier'] ?? null;
            if (is_array($courier)) $courier = implode(', ', $courier);
            $trackingNo = $raw['tracking_no'] ?? $raw['tracking_number'] ?? null;

            // The parcel the ship dialog shows for this row (see the
            // template beside the Arrange shipment button): the grouped
            // items above, the courier, and enough of the address to tell
            // two orders apart, and no more. Images the same way the row
            // resolves them, so the dialog and the row cannot differ.
            $spAddr = is_array($raw['recipient_address'] ?? null) ? $raw['recipient_address'] : (is_array($raw['shipping_address'] ?? null) ? $raw['shipping_address'] : []);
            $spCity = null;
            foreach (['city', 'district', 'town', 'state'] as $k) {
                if (is_string($spAddr[$k] ?? null) && trim($spAddr[$k]) !== '') { $spCity = trim($spAddr[$k]); break; }
            }
            $spParcel = \App\Support\Fulfilment\PackedParcel::fromItems(array_map(function ($it) {
                $it['image'] = !empty($it['image']) ? $it['image'] : \App\Support\CatalogImages::urlFor($it['sku'] ?? '');
                return $it;
            }, $groupedItems), is_string($courier) ? $courier : null, $spCity);

            $tone = \App\Support\ChannelStatusTone::toneFor('shopee.orders', $statusStr);
            $statusLabel = \App\Support\ChannelStatusTone::labelFor('shopee.orders', $statusStr);
        @endphp
        <tr class="co-band">
            <td colspan="7">
                <div class="co-band__row">
                    <div class="co-band__left">
                        {{-- Selecting the order, in the order's own band rather than in a
                             column of its own. See components/selectbar.css. --}}
                        <input type="checkbox" class="x-pick" data-selection-item
                               name="ids[]" value="{{ $o->id }}" form="shopee-print-pick"
                               aria-label="Select order {{ $orderSn }}">
                        <span class="co-band__label">Buyer</span>
                        @if($buyer !== '')
                            <span class="co-band__value">{{ $buyer }}</span>
                        @else
                            <span class="co-empty-mark">Not recorded</span>
                        @endif
                        <span class="co-band__label">Order</span>
                        @if($orderSn)
                            {{-- Navigates in place: the detail page now renders inside this
                                 same seller center, so the sidebar and the channel context
                                 survive the click and Back returns to the filtered list. --}}
                            <a class="co-sn" href="{{ route('ext.shopee.orders.show', ['orderSn' => $orderSn]) }}">{{ $orderSn }}</a>
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
            <td class="sp-col-products" data-label="Products">
                <div class="co-item">
                    <div class="order-img-wrap">
                        @php $itImg = !empty($it['image']) ? $it['image'] : \App\Support\CatalogImages::urlFor($it['sku'] ?? ''); @endphp
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

            <td class="sp-col-qty" data-label="Qty">
                <span class="co-qty">&times;{{ max(1, (int) ($it['quantity'] ?? 1)) }}</span>
            </td>

            <td class="sp-col-price x-td-num" data-label="Price">
                @php $unitPrice = $it['price'] ?? null; @endphp
                @if($unitPrice !== null && $unitPrice !== '' && is_numeric($unitPrice))
                    <span class="co-price">{{ $currencyCode !== ''
                        ? \App\Support\Money::foreign((float) $unitPrice, $currencyCode)
                        : \App\Support\Money::base((float) $unitPrice) }}</span>
                @elseif($unitPrice !== null && $unitPrice !== '')
                    <span class="co-price">{{ $unitPrice }}</span>
                @else
                    <span class="co-empty-mark">-</span>
                @endif
            </td>

            @if($idx === 0)
            <td class="sp-col-total x-td-num" rowspan="{{ $lineCount }}" data-label="Amount">
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
                @if($paymentMethod)
                    <span class="co-total__method">{{ $paymentMethod }}</span>
                @endif
            </td>

            <td class="sp-col-ship" rowspan="{{ $lineCount }}" data-label="Shipping">
                @if($courier)
                    <span class="co-carrier">{{ $courier }}</span>
                    @if($trackingNo)
                        <span class="co-ship__no">{{ $trackingNo }}</span>
                    @endif
                @else
                    <span class="co-empty-mark">-</span>
                @endif
            </td>

            <td class="sp-col-status" rowspan="{{ $lineCount }}" data-label="Status">
                <div class="co-status">
                    <x-ui.badge :tone="$tone">{{ $statusLabel }}</x-ui.badge>
                    <x-sla-chip :deadline="$shipBy" />
                </div>
            </td>

            <td class="sp-col-act" rowspan="{{ $lineCount }}">
                @if($orderSn)
                <div class="co-actions">
                    {{-- Every action is a visible button, matching the legacy
                         page. Per the owner: this is the packing desk's working
                         surface, and Print waybill exists on most tabs, not
                         only To Ship; an overflow menu made the column read
                         as empty on every other tab. --}}
                    @if($isToPack && $canManageShopeeOrders)
                        <button type="button" class="btnArrangeShipment x-btn x-btn--secondary x-btn--sm"
                                data-order-sn="{{ $orderSn }}"
                                data-pc-channel="shopee" data-pc-order="{{ $o->id }}">Arrange shipment</button>
                        {{-- The row's parcel, inert until the dialog opens:
                             resources/js/pages/shopee-orders.js clones this
                             into #spShipParcel by order number. Rendered
                             here, not fetched, so the dialog shows the box
                             the instant it opens. --}}
                        <template data-parcel-for="{{ $orderSn }}"><x-fulfilment.parcel :parcel="$spParcel" /></template>
                    @endif

                    @if(!$isToPack && !$isUnpaid)
                        <a class="x-btn x-btn--secondary x-btn--sm" href="{{ route('ext.shopee.orders.awb', ['orderSn' => $orderSn]) }}"
                           target="_blank" rel="noopener">Print waybill</a>
                    @endif

                    @if($isToHandover || $isShipped)
                        <button type="button" class="btnShopeeTracking x-btn x-btn--secondary x-btn--sm"
                                data-url="{{ route('ext.shopee.orders.tracking_info', ['orderSn' => $orderSn]) }}">Tracking</button>
                    @endif

                    <x-invoice-request-modal :invoice="$o->buyer_invoice" :reference="$orderSn" />
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

{{-- The ship, loading and tracking modals moved to orders/_modals.blade.php
     when the order detail page became the second surface that needs them.
     Same markup, same ids, one definition. --}}
@include('ext-shopee::orders._modals')
