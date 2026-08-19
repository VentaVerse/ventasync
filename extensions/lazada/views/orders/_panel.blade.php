@php
    // Contract identical to ext-shopee::orders._panel - see that file's top
    // @php block for the full rationale. $panelBaseUrl is the caller's own
    // route (/lazada/orders in the seller center, and the fulfilment hub's
    // own route once it switches this panel in place) and $panelHiddenParams
    // are context params the caller always wants kept (e.g. channel=lazada
    // on the hub). Query strings go through Arr::query() rather than
    // http_build_query() so the encoding matches byte for byte what the old
    // hardcoded route() calls on this page produced before this panel
    // existed (RFC 3986: spaces as %20, not +).
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
    // this shaping in another view is exactly how the two surfaces would
    // start drifting apart. Each line coalesces, so a caller that already
    // defined the value (index.blade.php still computes
    // $canManageLazadaOrders, $tabs, $active_tab, the sub-step nav maps and
    // $scopeLabel itself, because it reads them before this include, for its
    // own title and the ltrim whitespace mirror below) keeps its own.
    //
    // The page is visible to view_lazada_orders holders; Fetch, Update,
    // Pack, Arrange shipment and Recreate package render only for the
    // manage tier. The route middleware (perm:manage_lazada_orders on every
    // write POST) is the real gate; this flag only stops the panel offering
    // buttons that would 403.
    $canManageLazadaOrders = $canManageLazadaOrders
        ?? (auth()->user()?->hasPermission('manage_lazada_orders') ?? false);

    $tabs = $tabs ?? [];
    $active_tab = strtoupper((string) ($active_tab ?? 'ALL'));
    $tab_counts = $tab_counts ?? [];
    $pending_sub_counts = $pending_sub_counts ?? [];
    $fd_sub_counts = $fd_sub_counts ?? [];
    $savedAwbs = $savedAwbs ?? [];
    $live_statuses = $live_statuses ?? [];
    $perPage = (int) ($per_page ?? request()->query('per_page', 10));
    $orderNumberFilter = (string) ($filters['order_number'] ?? '');
    $buyerNameFilter = (string) ($filters['buyer_name'] ?? '');
    $hasSearch = $orderNumberFilter !== '' || $buyerNameFilter !== '';
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

    // The count in the deck is the count of what is actually listed, so it
    // names the step being listed. Saying "in To Ship" while standing on To
    // Arrange Shipment put a 1 next to a tab reading 2, which reads as a bug
    // rather than as two different questions being answered.
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

    // Same eight sort values the controller accepts, in the words an
    // operator uses. "Confirmed: Created At" was Lazada's own column name
    // leaking into the UI; what it actually orders by is the timestamp
    // Lazada recorded, so that is what it says.
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
@if($canManageLazadaOrders)
{{-- One command bar, the same grammar as Shopee's: every child of the row
     ends in a box exactly --control-h tall and the row is bottom aligned, so
     the date inputs, the checkbox and the two buttons share a baseline and a
     top edge. The three stacked cards this replaces each set their own
     control heights, which is what left the buttons off the baseline of
     their own date inputs. --}}
<div class="x-cmdbar">
    {{-- See the note on Shopee's bar: a fetch writes unseen orders and
         writing an order adjusts stock, so the action names its own
         consequence before it runs. --}}
    <form method="POST" action="{{ route('ext.lazada.orders.fetch') }}" id="formFetchLazadaOrders" class="x-cmdbar__row"
          data-confirm="Fetch Lazada orders for the chosen dates? Any order that has not been seen before will adjust stock."
          data-confirm-tone="primary">
        @csrf
        <div class="x-cmdbar__field">
            <label class="x-cmdbar__label" for="lzDateFrom">From</label>
            {{-- Pre-filled, matching TikTok's bar. --}}
            <x-ui.input type="date" id="lzDateFrom" name="date_from" class="x-cmdbar__date"
                        value="{{ old('date_from', request('date_from', now()->subDays(15)->format('Y-m-d'))) }}" />
        </div>
        <div class="x-cmdbar__field">
            <label class="x-cmdbar__label" for="lzDateTo">To</label>
            <x-ui.input type="date" id="lzDateTo" name="date_to" class="x-cmdbar__date"
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
                         formaction="{{ route('ext.lazada.orders.update_statuses') }}">Update statuses</x-ui.button>
            <x-ui.hint>Both dates are required, for fetching new orders and for updating statuses.</x-ui.hint>
        </div>
    </form>
</div>
@endif

@if($last_result)
    <div class="co-notice {{ $last_result['ok'] ? 'co-notice--ok' : 'co-notice--fail' }}">
        <span class="co-notice__title">{{ $last_result['ok'] ? 'Done' : 'Failed' }}</span>
        @if(!empty($last_result['message']))
            <span class="co-notice__body">{{ $last_result['message'] }}</span>
        @endif
    </div>
@endif

{{-- Status filters as a segmented control. Seven views over one list, so
     "pick one" is the right grammar; the old .tabs strip implied seven
     pages. Counts ride along as muted mono numbers on every tab, not only
     the three the old strip decorated with a blue pill: a count that appears
     on some tabs and not others reads as an alert rather than a figure.
     Returns is a sibling page, so it sits outside the trough as a plain
     link, the way the channel sidebar already lists it. --}}
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
            {{-- A filter with no members is shown but not offered.
                 Four of Lazada's seven return tabs read 0, and each was a full
                 target onto an empty list - the operator spends a click to be
                 told what the tab already told them. It stays visible, because
                 a tab that disappears when its queue empties makes the strip
                 change shape as the day goes on; it just stops being a target.

                 An anchor without an href is the whole mechanism: not in the
                 tab order, not announced as a link, no pointer cursor. The
                 active tab keeps its href even at 0, because that URL is where
                 the operator already is.

                 Top level only. The sub step strip below keeps its hrefs - see
                 the note there. --}}
            @php $segEmpty = ($count !== null && (int) $count === 0 && ! $isActive); @endphp
            <a @if(! $segEmpty) href="{{ $panelSelfUrl(['tab' => $key], ['page', 'pending_sub', 'fd_sub']) }}" @endif
               class="x-segment__item {{ $isActive ? 'is-active' : '' }}{{ $segEmpty ? ' is-empty' : '' }}"
               @if($segEmpty) aria-disabled="true" @endif
               @if($isActive) aria-current="page" @endif>
                <span>{{ $label }}</span>
                @if($count !== null)
                    <span class="x-segment__count">{{ number_format($count) }}</span>
                @endif
            </a>
        @endforeach
    </nav>
    {{-- See the Shopee panel: the caller decides where Returns lives, so
         opening it from the hub stays on the hub. --}}
    <a class="x-segment-bar__aside" href="{{ $panelReturnsUrl ?? route('ext.lazada.orders.returns') }}">
        Returns and refunds
        <x-ui.icon name="chevron-right" size="12" />
    </a>
</div>

@if($active_tab === 'TO_SHIP')
    {{-- Lazada's To Ship has three working steps where Shopee's has two. The
         sub row drops the trough so it cannot be mistaken for a peer of the
         row above it, and the "To Ship workflow / Choose the step you are
         working on" card that used to frame it is gone: a segmented control
         under a tab needs no caption explaining that it filters. --}}
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
            <a href="{{ $panelSelfUrl(['tab' => 'TO_SHIP', 'pending_sub' => $k], ['page']) }}"
               class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
               @if($isActive) aria-current="page" @endif>
                <span>{{ $lbl }}</span>
                @if($count !== null)
                    <span class="x-segment__count">{{ number_format($count) }}</span>
                @endif
            </a>
        @endforeach
    </div>

    @if(!empty($to_ship_courier_counts))
        <div class="co-couriers">
            <span class="co-couriers__label">Parcels waiting for handover</span>
            @foreach($to_ship_courier_counts as $courier => $count)
                <span class="co-courier">
                    <span class="co-courier__count">{{ number_format($count) }}</span>
                    {{ $courier }}
                </span>
            @endforeach
        </div>
    @endif
@endif

@if($active_tab === 'FAILED_DELIVERY')
    <div class="x-segment x-segment--sub" aria-label="Delivery failure type">
        @foreach($fdNav as $k => $lbl)
            @php
                $isActive = ($fdSub === $k) || ($fdSub === '' && $k === 'failed_delivery');
                $count = $fd_sub_counts[$k] ?? null;
            @endphp
            {{-- A sub step keeps its href at zero, unlike the top level tabs
                 above. These split one queue between them, and the split
                 empties one side into the other as the work gets done - To
                 Pack drains into To Handover over a morning. A zero here is a
                 moment in that loop rather than a settled state, so making the
                 control inert would have it flicker in and out of being
                 clickable while someone is working through it. --}}
            <a href="{{ $panelSelfUrl(['tab' => 'FAILED_DELIVERY', 'fd_sub' => $k], ['page']) }}"
               class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
               @if($isActive) aria-current="page" @endif>
                <span>{{ $lbl }}</span>
                @if($count !== null)
                    <span class="x-segment__count">{{ number_format($count) }}</span>
                @endif
            </a>
        @endforeach
    </div>
@endif

{{-- One filter row. Search, sort and per page were three separate card forms
     doing one job, one of them a form whose only control was a select. The
     selects auto-submit through the shared data-autosubmit handler that
     /sales/orders already uses. --}}
<form method="GET" action="{{ $panelBaseUrl }}" class="x-filters" id="orders-filter">
@foreach($panelHiddenParams as $panelHiddenKey => $panelHiddenValue)
        <input type="hidden" name="{{ $panelHiddenKey }}" value="{{ $panelHiddenValue }}">
@endforeach
    <input type="hidden" name="tab" value="{{ $active_tab }}">
    @if($active_tab === 'TO_SHIP')
        <input type="hidden" name="pending_sub" value="{{ $pendingSub }}">
    @endif
    @if($active_tab === 'FAILED_DELIVERY')
        <input type="hidden" name="fd_sub" value="{{ $fdSub }}">
    @endif

    <x-ui.input type="search" name="order_number" class="x-filters__search"
                value="{{ $orderNumberFilter }}" placeholder="Order number" aria-label="Search by order number" />
    <x-ui.input type="search" name="buyer_name" class="x-filters__search"
                value="{{ $buyerNameFilter }}" placeholder="Buyer" aria-label="Search by buyer name" />

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
            @foreach([10, 20, 50] as $n)
                <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }} per page</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>

    @if($hasSearch)
        <a class="x-filters__reset" href="{{ $panelUrl(['tab' => $active_tab, 'per_page' => $perPage]) }}">Reset</a>
    @endif
</form>

@if($orders->count() === 0)
    @php
        if ($hasSearch) {
            $lzEmptyDesc = 'Nothing matches that order number or buyer. Clear the search to see the full list.';
        } elseif ($active_tab !== 'ALL') {
            // Keep the tab's own capitalisation: lowercasing it produced
            // "no to ship orders", which reads broken.
            $lzEmptyDesc = 'No orders in ' . ($scopeLabel ?? $active_tab) . ' right now.';
        } else {
            // The empty state names the way forward the reader actually
            // has. Telling a read-only operator to use a command bar their
            // permission tier never renders is an instruction they cannot
            // follow.
            $lzEmptyDesc = $canManageLazadaOrders
                ? 'Nothing has been synced yet. Pick a date range above and fetch orders from Lazada.'
                : 'Nothing has been synced yet. Someone with permission to sync orders needs to pull them from Lazada first.';
        }

        /* A way out of the empty state.
         *
         * Every one of these messages already named the escape - "Clear the
         * search", "Clear them to see the full list" - and then left the
         * operator to find the filter themselves and empty it by hand. The
         * message is where the dead end is noticed, so the way out belongs
         * there too.
         *
         * Read off the request rather than off each branch's own condition,
         * because those conditions differ per channel and this does not:
         * anything the operator narrowed by is a query parameter. page is
         * excluded because paging is not narrowing, tab=ALL because that is
         * the unnarrowed state, and the hidden params because they identify
         * the panel rather than filter it. */
        $emptyFiltered = collect(request()->except(array_merge(array_keys($panelHiddenParams ?? []), ['page'])))
            ->filter(fn ($v, $k) => $v !== null && $v !== '' && !($k === 'tab' && $v === 'ALL'))
            ->isNotEmpty();
    @endphp
    <x-ui.empty title="No orders here" :description="$lzEmptyDesc">
        @if($emptyFiltered)
            <x-slot:action>
                <x-ui.button variant="secondary" :href="$panelUrl()">Show all orders</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table class="x-table--stack">
    <x-slot:head>
        {{-- The legacy column format, the same one Shopee settled on: one row
             per item so Qty and Price align in their own columns, and the
             order-level facts (amount, shipping, status, actions) rowspan
             beside the item rows. The buyer, order number and create time
             ride in a band above each order, exactly where the legacy page
             carried them. --}}
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

            // Products: prefer persisted products; fall back to common raw nodes.
            $items = [];
            if (method_exists($o, 'products') && $o->relationLoaded('products')) {
                $items = $o->products ? $o->products->toArray() : [];
            }
            if (empty($items)) {
                $items = $raw['order_items'] ?? $raw['items'] ?? $raw['orderItems'] ?? [];
                if (!is_array($items)) $items = [];
            }

            // Group identical products (same SKU + name + variation) and sum
            // quantities. Lazada emits one line per unit on some payloads.
            $grouped = [];
            foreach ($items as $row) {
                $rowRaw = $row['raw'] ?? $row;

                $gName = $row['name'] ?? $rowRaw['name'] ?? $rowRaw['item_name'] ?? $rowRaw['product_name'] ?? '';
                $gSku = $row['sku'] ?? $rowRaw['sku'] ?? $rowRaw['seller_sku'] ?? $rowRaw['SellerSku'] ?? $rowRaw['SellerSKU'] ?? $rowRaw['Sku'] ?? '';
                $gVariation = (string) ($row['variation'] ?? $rowRaw['variation'] ?? $rowRaw['Variation'] ?? $rowRaw['variant'] ?? '');
                $gVariationNorm = mb_strtolower(trim($gVariation));
                if (in_array($gVariationNorm, ['', 'blank', 'null', 'n/a', 'na', 'none', '-', '--'], true)) {
                    $gVariation = '';
                }

                $gQty = (int) ($row['quantity'] ?? $rowRaw['quantity'] ?? $rowRaw['qty'] ?? 1);
                if ($gQty < 1) { $gQty = 1; }

                $gImg = $row['image'] ?? $rowRaw['image'] ?? $rowRaw['product_main_image'] ?? $rowRaw['product_image'] ?? '';
                $gItemPrice = $row['item_price'] ?? $row['paid_price'] ?? $rowRaw['item_price'] ?? $rowRaw['paid_price'] ?? null;
                $gCurrency = (string) ($rowRaw['currency'] ?? '');

                $key = mb_strtolower(trim((string) $gSku)) . '|' . mb_strtolower(trim((string) $gName)) . '|' . mb_strtolower(trim((string) $gVariation));
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'name' => $gName,
                        'sku' => $gSku,
                        'variation' => $gVariation,
                        'quantity' => $gQty,
                        'image' => $gImg,
                        'item_price' => $gItemPrice,
                        'currency' => $gCurrency,
                    ];
                } else {
                    $grouped[$key]['quantity'] += $gQty;
                    if (($grouped[$key]['image'] ?? '') === '' && $gImg !== '') {
                        $grouped[$key]['image'] = $gImg;
                    }
                }
            }
            $groupedItems = array_values($grouped);

            // Legacy shape: an order with no recorded items still renders one
            // empty item row under its band, rather than a band with nothing
            // attached to it.
            $displayItems = $groupedItems !== [] ? $groupedItems : [[]];
            $lineCount = count($displayItems);
            $itemCount = count($groupedItems);

            $totalAmount = $raw['price'] ?? $raw['total_amount'] ?? $raw['total_price'] ?? $raw['order_amount'] ?? $raw['amount'] ?? null;

            // Currency is resolved through App\Support\Money, which reads the
            // symbol from the currencies table, so no symbol is ever written
            // into a view. An order carrying a code nobody has configured
            // locally falls back to "CODE 1,234.00" rather than a wrong glyph.
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

            // Shipping / courier / tracking. Check order-level raw, then
            // _detail, then the first product's raw.
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

            // Ship-by timestamp, only for awaiting-ship statuses. Lazada
            // exposes either sla_time_stamp / promised_shipping_time (often
            // empty) or fulfillment_sla, a string like
            // "TTS before 2026-05-06 23:59:17 ...".
            $awaitingShip = in_array($statusNorm, ['pending', 'confirmed', 'ready_to_ship', 'packed'], true);
            $shipBy = 0;
            if ($awaitingShip) {
                $tsRaw = $pick(['sla_time_stamp', 'promised_shipping_time']);
                if (is_numeric($tsRaw) && (int) $tsRaw > 0) {
                    $shipBy = (int) $tsRaw;
                    if ($shipBy > 1000000000000) $shipBy = (int) ($shipBy / 1000); // ms to s
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

            // Which branches of the action matrix put a Print waybill control
            // in the button row. The quick-link row below repeats a waybill
            // link whenever a local PDF exists; where the button is already
            // there, that second link points at the identical route and is
            // dropped. No action is lost, only a duplicate of one.
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
                        @if(!empty($it['image']))
                            <img class="order-img" src="{{ $it['image'] }}" alt="">
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
                            @if(trim((string) ($it['variation'] ?? '')) !== '')
                                <span class="co-item__var">{{ $it['variation'] }}</span>
                            @endif
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
                    <span class="co-total__method">{{ $paymentBadge }}</span>
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
                    {{-- Nothing can be done to an order the buyer has not
                         paid for, which is why the legacy page printed a dash
                         here rather than a control. --}}
                    <span class="co-empty-mark">-</span>
                @else
                <div class="co-actions co-actions--wrap">
                    {{-- Every action a row's status allows is a visible
                         button, matching the legacy page: this is the packing
                         desk's working surface, and an overflow menu made the
                         column read as empty. Every one of them is a
                         secondary button, so a screen of forty rows has no
                         wall of colour in it; the accent that says "your
                         move" is spent once per row, on the status badge.

                         Pack, arrange and recreate are marketplace writes, so
                         they render only for the manage tier and keep the
                         data-confirm they carried before. --}}
                    @if($canManageLazadaOrders)
                        @if($isToPack)
                            <form method="POST" action="{{ route('ext.lazada.orders.pack_print', ['orderId' => $orderId]) }}"
                                  data-confirm="Pack this order now? This creates the packages and makes the waybill available.">
                                @csrf
                                <x-ui.button type="submit" variant="secondary" size="sm">Pack and print</x-ui.button>
                            </form>
                        @endif

                        @if($isToArrange)
                            <x-ui.button size="sm"
                                         :href="route('ext.lazada.orders.awb', ['orderId' => $orderId])"
                                         target="_blank" rel="noopener">Print waybill</x-ui.button>

                            <form method="POST" action="{{ route('ext.lazada.orders.rts', ['orderId' => $orderId]) }}"
                                  data-confirm="Arrange shipment for this order? Lazada will mark it ready to ship.">
                                @csrf
                                <x-ui.button type="submit" variant="secondary" size="sm">Arrange shipment</x-ui.button>
                            </form>

                            <form method="POST" action="{{ route('ext.lazada.orders.recreate_package', ['orderId' => $orderId]) }}"
                                  data-confirm="Recreate the package locally? Lazada may still keep the original package. Continue?">
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
                        {{-- A viewer gets the waybill shortcut on any
                             non-pending order that already has one saved:
                             opening a stored PDF is read-only, which is why
                             the route sits in the read tier. --}}
                        @if(!$isToPack && $hasAwb)
                            <x-ui.button size="sm"
                                         :href="route('ext.lazada.orders.awb', ['orderId' => $orderId])"
                                         target="_blank" rel="noopener">Print waybill</x-ui.button>
                        @endif
                    @endif

                    {{-- Post-fulfilment: the waybill is offered only when a
                         local copy exists, because Lazada will not regenerate
                         one for a shipped order. --}}
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
                    {{-- The picking and packing documents. They are printing,
                         not fulfilment, so they read as a row of links under
                         the buttons rather than as four more controls of the
                         same weight. The pipe separators the legacy row used
                         are gone; spacing separates them. --}}
                    <div class="lz-docs">
                        <span class="lz-docs__label">Print</span>
                        @if($hasAwb && !$showsAwbButton)
                            <a class="lz-doc" href="{{ route('ext.lazada.orders.awb', ['orderId' => $orderId]) }}" target="_blank" rel="noopener">Waybill</a>
                        @endif
                        <a class="lz-doc" href="{{ route('ext.lazada.orders.packing_list', ['orderId' => $orderId]) }}" target="_blank" rel="noopener">Packing list</a>
                        <a class="lz-doc" href="{{ route('ext.lazada.orders.pick_list', ['orderId' => $orderId]) }}" target="_blank" rel="noopener">Pick list</a>
                    </div>
                @endif
                @endif
            </td>
            @endif
        </tr>
        @endforeach
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$orders" />
@endif

{{-- Loading overlay. Every id below is queried by
     resources/js/pages/lazada-orders.js and must not change. --}}
<div id="lzLoadingOverlay" class="modal-backdrop">
    <div class="modal co-modal co-modal--sm">
        <div class="co-progress">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div id="lzLoadingTitle" class="co-progress__title">Fetching orders</div>
                <div class="co-progress__note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

@if($canManageLazadaOrders)
{{-- Pack and print follow-up, shown after a pack succeeds. Only meaningful
     for the write tier, because every control in it is a write. --}}
<div id="lzPackPrintModal" class="modal-backdrop">
    <div class="modal co-modal">
        <div class="modal-header co-modal__head">
            <h3 class="co-modal__title">Packed</h3>
            <button type="button" id="btnClosePackPrint" class="modal-close co-modal__close" aria-label="Close">&times;</button>
        </div>
        <div class="co-modal__meta">Order <strong id="lzPackPrintOrder"></strong></div>
        <p class="co-modal__prompt">Close this and the order waits in To Arrange Shipment.</p>
        <div class="co-modal__foot co-modal__foot--end">
            <form method="POST" id="formRecreatePackage" action="#">
                @csrf
                <x-ui.button type="submit" variant="secondary">Recreate package</x-ui.button>
            </form>
            <a href="#" id="linkPrintOnly" class="x-btn x-btn--secondary" target="_blank" rel="noopener">Print only</a>
            <form method="POST" id="formShipPrint" action="#">
                @csrf
                <x-ui.button type="submit" variant="primary">Ship and print</x-ui.button>
            </form>
        </div>
    </div>
</div>
@endif

{{-- Tracking modal, filled by the page module from the logistics trace
     endpoint. One copy of the markup, in orders/_modals.blade.php, included
     by this panel and by the order detail page: the module reads every id in
     it, and two copies of a contract read by id is how the two drift. --}}
@include('ext-lazada::orders._modals')
