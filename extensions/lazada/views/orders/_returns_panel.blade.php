{{-- The Lazada returns list, as a panel. Same contract and same reason as
     ext-shopee::orders._returns_panel: this list renders both in the channel
     workspace and in place on the fulfilment hub, so every tab, filter and
     pager is built from the caller's own base URL rather than from this
     channel's seller-centre route. Detail links still leave for their own
     page, which is what they are for. --}}
@php
    $panelBaseUrl = $panelBaseUrl ?? route('ext.lazada.orders.returns');
    $panelHiddenParams = $panelHiddenParams ?? [];
    $panelOrdersUrl = $panelOrdersUrl ?? route('ext.lazada.orders.index', ['tab' => 'TO_SHIP']);

    $panelSelfUrl = function (array $params = [], array $except = []) use ($panelBaseUrl, $panelHiddenParams) {
        $query = array_merge(request()->except($except), $params, $panelHiddenParams);

        return $query === [] ? $panelBaseUrl : $panelBaseUrl . '?' . \Illuminate\Support\Arr::query($query);
    };

    $panelUrl = function (array $params = []) use ($panelBaseUrl, $panelHiddenParams) {
        $query = array_merge($panelHiddenParams, $params);

        return $query === [] ? $panelBaseUrl : $panelBaseUrl . '?' . \Illuminate\Support\Arr::query($query);
    };
@endphp
@php
    // Returns is visible to view_lazada_orders holders; Fetch returns renders
    // only for the manage tier. The route middleware
    // (perm:manage_lazada_orders on the POST) is the real gate; this flag
    // only stops the page offering a button that would 403. The old page
    // hardcoded it to true and showed a viewer a button that always failed.
    $canManageLazadaOrders = auth()->user()?->hasPermission('manage_lazada_orders') ?? false;

    $tabs = $tabs ?? [];
    $active_tab = strtoupper((string) ($active_tab ?? 'ALL'));
    $tab_counts = $tab_counts ?? [];
    $erpMap = $erpMap ?? collect();
    $imgMap = $imgMap ?? collect();
    $nameMap = $nameMap ?? collect();
    $perPage = (int) ($per_page ?? request()->query('per_page', 10));
    $findFilter = (string) ($filters['q'] ?? '');
    $openedFromFilter = (string) ($filters['opened_from'] ?? '');
    $openedToFilter = (string) ($filters['opened_to'] ?? '');
    $hasSearch = $findFilter !== '' || $openedFromFilter !== '' || $openedToFilter !== '';
    /* Four orderings over the two dates a return actually has. The same
       "field, direction" phrasing the orders list uses, so the two controls
       read as one idea. */
    $rtSortLabels = [
        'updated_desc' => 'Last update, newest first',
        'updated_asc'  => 'Last update, oldest first',
        'opened_desc'  => 'Opened, newest first',
        'opened_asc'   => 'Opened, oldest first',
    ];
@endphp


{{-- The head is the HOST's, not the panel's. The orders panels are
     headless for exactly this reason: on the fulfilment hub the page
     already carries an <h1> naming the channel, so a panel that brought
     its own printed two <h1> elements and told the operator where they
     were twice, neither time about returns. The seller-centre wrapper
     renders this block itself; the hub folds the word into its own
     title. --}}
@if($panelOwnsHead ?? false)
<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Returns and refunds</h1>
        <p class="x-page-sub">
            {{ number_format($orders->total()) }} {{ $orders->total() === 1 ? 'return' : 'returns' }}@if($active_tab !== 'ALL' && isset($tabs[$active_tab])) in {{ $tabs[$active_tab] }}@endif
        </p>
    </div>
</div>
@endif

@if($canManageLazadaOrders)
{{-- One command bar, the same grammar as the Orders page's fetch bar. The
     dates are real here, unlike on Shopee's returns page: Lazada's
     getreverseordersforseller takes create_time_start / create_time_end and
     LazadaOrderController::fetchReturns() passes them through when they are
     filled, so the pair is offered and the hint says plainly what leaving
     them empty does. Promising a filter the fetch does not perform is the
     mistake Shopee's returns page was built to avoid; so is hiding one it
     does. --}}
<div class="x-cmdbar" data-desk-tool>
    <form method="POST" action="{{ route('ext.lazada.orders.fetch_returns') }}" id="formFetchLazadaReturns" class="x-cmdbar__row"
          data-confirm="Fetch every return and refund Lazada has on file? This walks the channel's full history and can take a while."
          data-confirm-tone="primary">
        @csrf
        <div class="x-cmdbar__field">
            <label class="x-cmdbar__label" for="lzReturnFrom">Fetch from</label>
            {{-- Prefilled to the same 15-day window the orders bar uses.
                 Empty meant "walk the entire history", which is the slowest
                 call on the surface and was also its default. --}}
            <x-ui.input type="date" id="lzReturnFrom" name="date_from" class="x-cmdbar__date"
                        value="{{ old('date_from', request('date_from', now()->subDays(15)->format('Y-m-d'))) }}" />
        </div>
        <div class="x-cmdbar__field">
            <label class="x-cmdbar__label" for="lzReturnTo">Fetch to</label>
            <x-ui.input type="date" id="lzReturnTo" name="date_to" class="x-cmdbar__date"
                        value="{{ old('date_to', request('date_to', now()->format('Y-m-d'))) }}" />
        </div>
        <div class="x-cmdbar__spacer"></div>
        <div class="x-cmdbar__actions">
            <x-ui.button type="submit" variant="primary" id="btnFetchLazadaReturns">Fetch returns</x-ui.button>
            <x-ui.hint>Fetch pulls the returns opened between these dates. Update statuses ignores them and re-reads every return on file.</x-ui.hint>
        </div>
    </form>

    {{-- Update statuses, the same pair the orders bar carries and for the
         same reason. A buyer can cancel a return at any time, and this
         channel's fetch is date-scoped - so a return opened outside the
         chosen window never gets re-read and a cancellation on an older one
         sits stale indefinitely. This posts to the same endpoint with no
         range, which that controller already treats as "walk everything".

         Shopee needs no equivalent: its get_return_list takes no range, so
         its Fetch already re-reads the full history every time. --}}
    <form method="POST" action="{{ route('ext.lazada.orders.fetch_returns') }}" id="formUpdateLazadaReturns"
          data-confirm="Re-read every return Lazada has on file, ignoring the dates? This refreshes statuses for returns the buyer may have cancelled."
          data-confirm-tone="primary">
        @csrf
        <x-ui.button type="submit" variant="secondary" id="btnUpdateLazadaReturns">Update statuses</x-ui.button>
    </form>
</div>
@endif

@if($last_result)
    <div class="co-notice {{ $last_result['ok'] ? 'co-notice--ok' : 'co-notice--fail' }}" role="status" aria-live="polite">
        <span class="co-notice__title">{{ $last_result['ok'] ? 'Done' : 'Failed' }}</span>
        @if(!empty($last_result['message']))
            <span class="co-notice__body">{{ $last_result['message'] }}</span>
        @endif
    </div>
@endif

{{-- The old markup carried two tab rows: one repeating the Orders page's
     seven status tabs purely as a way back, and one filtering this list. The
     channel sidebar already lists Orders and Returns as siblings, so the
     first row's only real job is covered by standing navigation; it survives
     as a single plain-weight link on the far side of the bar, the same
     treatment the Orders page uses for the trip here. The second row, the
     actual filter, becomes the segment. --}}
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
    <nav class="x-segment" aria-label="Return status">
        @foreach($tabs as $key => $label)
            @php
                $isActive = $active_tab === strtoupper((string) $key);
                $count = $tab_counts[$key] ?? null;
            @endphp
            {{-- A filter with no members is shown but not offered.
                 Four of Lazada's seven return tabs read 0, and each was a
                 full target onto an empty list - the operator spends a click
                 to be told what the tab already told them. It stays visible,
                 because a tab that disappears when its queue empties makes
                 the strip change shape as the day goes on and the operator
                 re-learn where things are; it just stops being a target.

                 An anchor without an href is the whole mechanism: not in the
                 tab order, not announced as a link, no pointer cursor. The
                 active tab keeps its href even at 0, because that URL is
                 where the operator already is. --}}
            {{-- A tab with nothing in it stays clickable.
                 It was briefly rendered without an href, on the reasoning that
                 spending a click to be told what the tab already told you is
                 waste. The owner asked for it back, and the reasoning was thinner
                 than it looked: a count is a number the last sync produced,
                 and going to the tab is how an operator checks whether that is
                 still true. A control that refuses the check is arguing with
                 the person using it. --}}
            <a href="{{ $panelSelfUrl(['tab' => $key], ['page']) }}"
               class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
               @if($isActive) aria-current="true" @endif>
                <span>{{ $label }}</span>
                @if($count !== null)
                    <span class="x-segment__count">{{ number_format($count) }}</span>
                @endif
            </a>
        @endforeach
    </nav>
    <a class="x-segment-bar__aside" href="{{ $panelOrdersUrl }}">
        <x-ui.icon name="chevron-left" size="12" />
        All orders
    </a>
</div>

{{-- One filter row, replacing the old search card and the separate per-page
     form that sat below the table. --}}
<form method="GET" action="{{ $panelBaseUrl }}" class="x-filters" data-desk-tool id="orders-filter">
@foreach($panelHiddenParams as $panelHiddenKey => $panelHiddenValue)    <input type="hidden" name="{{ $panelHiddenKey }}" value="{{ $panelHiddenValue }}">
@endforeach
    <input type="hidden" name="tab" value="{{ $active_tab }}">

    {{-- One box, matching whatever the caller just said. See the note in
         the panel service for why this is not four labelled fields. --}}
    <x-ui.input type="search" name="q" class="x-filters__find"
                value="{{ $findFilter }}"
                placeholder="Return no, order no or product"
                aria-label="Find a return by return number, order number or product" />

    {{-- The list range, labelled on screen and not only in an aria-label.
         This pair filters the rows already on the page; the pair up in the
         command bar tells the marketplace which returns to send. Both are
         about when a return was opened, both are date boxes, and until this
         label existed the only thing separating them was a word screen
         readers could hear and nobody else could see. On Lazada that put
         four identical boxes on one screen, two of which write orders. --}}
    <span class="x-filters__group">
        <span class="x-filters__group-label" id="lz-opened-label">Opened</span>
        <x-ui.input type="date" name="opened_from" class="x-filters__date"
                    value="{{ $openedFromFilter }}" aria-label="Opened on or after" title="Opened on or after" />
        <x-ui.input type="date" name="opened_to" class="x-filters__date"
                    value="{{ $openedToFilter }}" aria-label="Opened on or before" title="Opened on or before" />
    </span>

    <div class="x-select-wrap x-filters__select x-filters__select--wide">
        <select name="sort" class="x-input" data-autosubmit aria-label="Sort returns">
            @foreach($rtSortLabels as $value => $label)
                <option value="{{ $value }}" @selected(($sort ?? 'updated_desc') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="per_page" class="x-input" data-autosubmit aria-label="Returns per page">
            @foreach([10, 20, 50, 100] as $n)
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
            $lzEmptyDesc = 'Nothing matches that search. Clear it to see the full list.';
        } elseif ($active_tab !== 'ALL') {
            // Keep the tab's own capitalisation: lowercasing produced
            // unreadable copy on the Orders page and would here too.
            $lzEmptyDesc = 'No returns in ' . ($tabs[$active_tab] ?? $active_tab) . ' right now.';
        } else {
            // Same rule as the Orders page: a viewer is not told to press a
            // button their tier never renders.
            $lzEmptyDesc = $canManageLazadaOrders
                ? 'Nothing has been synced yet. Use the button above to pull returns from Lazada.'
                : 'Nothing has been synced yet. Someone with permission to sync returns needs to pull them from Lazada first.';
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
    <x-ui.empty title="No returns here" :description="$lzEmptyDesc">
        @if($emptyFiltered)
            <x-slot:action>
                <x-ui.button variant="secondary" :href="isset($active_tab) ? $panelUrl(['tab' => 'ALL']) : $panelUrl()">Show all returns</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table caption="{{ 'Lazada returns and refunds' . ($active_tab !== 'ALL' && isset($tabs[$active_tab]) ? ' in ' . $tabs[$active_tab] : '') }}" class="x-table--stack">
    <x-slot:head>
        {{-- The Orders page's column format, with this page's own fields
             poured into it: the per-line refund sits where the unit price
             sits there, the order's total refund where the order amount
             sits, and the reverse parcel's tracking where the forward
             carrier sits. That also gets the money out of the Actions
             column, where the old markup printed the order total underneath
             a button. --}}
        <tr>
            <th scope="col" class="lz-col-rproducts">Returned products</th>
            <th scope="col" class="lz-col-rqty">Qty</th>
            <th scope="col" class="lz-col-refund x-td-num">Refund</th>
            <th scope="col" class="lz-col-rtotal x-td-num">Total</th>
            <th scope="col" class="lz-col-rtrack">Return tracking</th>
            <th scope="col" class="lz-col-rstatus">Status</th>
            <th scope="col" class="lz-col-ract"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($orders as $order)
        @php
            $currencyCode = (string) ($order->currency ?? '');
            $items = is_array($order->items) ? $order->items : [];

            // Lazada emits one reverse line per unit, so identical lines are
            // consolidated and their refunds summed.
            $grouped = [];
            foreach ($items as $line) {
                $key = (($line['seller_sku_id'] ?? '') . '|' . ($line['product']['product_sku'] ?? '')) ?: uniqid();
                if (!isset($grouped[$key])) {
                    $grouped[$key] = $line;
                    $grouped[$key]['_qty'] = 0;
                    $grouped[$key]['_refund_sum'] = 0.0;
                }
                $grouped[$key]['_qty']++;
                $grouped[$key]['_refund_sum'] += (float) ($line['refund_amount'] ?? 0);
            }
            $displayItems = array_values($grouped);
            if ($displayItems === []) { $displayItems = [[]]; }
            $lineCount = count($displayItems);
            $itemCount = count(array_values($grouped));

            $erpId = $erpMap[$order->trade_order_id] ?? null;
            $buyerId = $items[0]['buyer']['buyer_id'] ?? null;
            $firstTracking = $items[0]['tracking_number'] ?? null;
            $ofc = $items[0]['ofc_status'] ?? null;

            $statusStr = (string) ($order->reverse_status ?? '');
            $tone = \App\Support\ChannelStatusTone::toneFor('lazada.returns', $statusStr);
            $statusLabel = \App\Support\ChannelStatusTone::labelFor('lazada.returns', $statusStr);

            $orderShowUrl = $order->trade_order_id
                ? route('ext.lazada.orders.show', ['orderId' => $order->trade_order_id])
                : null;

            $updated = $order->updated_at ? $order->updated_at->format('Y-m-d H:i') : null;
        @endphp
        <tr class="co-band">
            <td colspan="7">
                <div class="co-band__row">
                    <div class="co-band__left">
                        <span class="co-band__label">Buyer</span>
                        @if($buyerId)
                            <span class="co-band__value">#{{ $buyerId }}</span>
                        @else
                            <span class="co-empty-mark">Not recorded</span>
                        @endif
                        <span class="co-band__label">Return</span>
                        <span class="co-band__value co-band__value--mono">{{ $order->reverse_order_id }}</span>
                        <span class="co-band__label">Order</span>
                        @if($orderShowUrl)
                            <a class="co-sn" href="{{ $orderShowUrl }}">{{ $order->trade_order_id }}</a>
                        @else
                            <span class="co-empty-mark">No order number</span>
                        @endif
                        @if($itemCount > 0)
                            <span class="co-band__muted">({{ $itemCount }} {{ $itemCount === 1 ? 'item' : 'items' }})</span>
                        @endif
                        @if($order->reverse_type)
                            <span class="co-band__muted">{{ \App\Support\ChannelStatusTone::labelFor('lazada.reverse_type', $order->reverse_type) }}</span>
                        @endif
                        @if($erpId)
                            <a class="co-ref" href="{{ url('/sales/orders/'.$erpId) }}">Sales order {{ $erpId }}</a>
                        @endif
                    </div>
                    <div class="co-band__right">
                        @if($updated)
                            <span class="co-band__label">Updated</span>
                            <span class="co-band__value co-band__value--mono">{{ $updated }}</span>
                        @endif
                    </div>
                </div>
            </td>
        </tr>
        @foreach($displayItems as $idx => $it)
            @php
                $pSku = (string) ($it['product']['product_sku'] ?? '');
                $sSku = (string) ($it['seller_sku_id'] ?? '');
                $qty = (int) ($it['_qty'] ?? 1);
                // Lazada's item-line amounts arrive in cents.
                $itRefund = isset($it['_refund_sum']) ? ((float) $it['_refund_sum'] / 100) : null;
                $itPrice = isset($it['item_unit_price']) ? ((float) $it['item_unit_price'] / 100) : null;
                $img = (string) ($imgMap[$sSku] ?? ($imgMap[$pSku] ?? ''));
                // The title, resolved by SKU the same way the picture is.
                // Lazada's reverse payload carries no product name at all.
                $pName = (string) ($nameMap[$sSku] ?? ($nameMap[$pSku] ?? ''));
                // Lazada records a reason on the reverse order and again on
                // each of its lines, and for a single-line return they are
                // usually the same sentence. The Status cell already prints
                // the order-level one, so the line only speaks when it has
                // something different to say.
                $reason = (string) ($it['reason_text'] ?? '');
                if ($reason !== '' && trim(mb_strtolower($reason)) === trim(mb_strtolower((string) ($order->reason ?? '')))) {
                    $reason = '';
                }
            @endphp
        <tr class="co-itemrow {{ $idx === $lineCount - 1 ? 'co-itemrow--last' : '' }}">
            <td class="lz-col-rproducts" data-label="Returned products">
                <div class="co-item">
                    <div class="order-img-wrap">
                        @if($img)
                            <img class="order-img" src="{{ $img }}" alt="">
                        @else
                            <span class="co-item__none">No image</span>
                        @endif
                    </div>
                    <div class="co-item__body">
                        {{-- The name where every other list puts a name. This
                             printed the marketplace item id, so the one page a
                             caller's question lands on showed a number where
                             they had said a product. The SKU keeps its place
                             in the meta line below; when no name resolves, the
                             SKU steps up rather than leaving the row blank. --}}
                        <span class="co-item__name">{{ $pName !== '' ? $pName : ($sSku !== '' ? $sSku : ($pSku !== '' ? $pSku : 'Unnamed product')) }}</span>
                        <div class="co-item__meta">
                            @if($sSku !== '')
                                <span class="co-item__sku">{{ $sSku }}</span>
                            @endif
                        </div>
                        @if($reason !== '')
                            <span class="lz-reason">{{ \Illuminate\Support\Str::limit($reason, 60) }}</span>
                        @endif
                    </div>
                </div>
            </td>

            <td class="lz-col-rqty" data-label="Qty">
                <span class="co-qty">&times;{{ max(1, $qty) }}</span>
            </td>

            <td class="lz-col-refund x-td-num" data-label="Refund">
                @if($itRefund !== null)
                    <span class="co-price">{{ $currencyCode !== ''
                        ? \App\Support\Money::foreign($itRefund, $currencyCode)
                        : \App\Support\Money::base($itRefund) }}</span>
                    @if($itPrice !== null)
                        <span class="co-total__method">of {{ $currencyCode !== ''
                            ? \App\Support\Money::foreign($itPrice, $currencyCode)
                            : \App\Support\Money::base($itPrice) }}</span>
                    @endif
                @else
                    <span class="co-empty-mark">-</span>
                @endif
            </td>

            @if($idx === 0)
            <td class="lz-col-rtotal x-td-num" rowspan="{{ $lineCount }}" data-label="Total">
                <span class="co-total">
                    @if($order->refund_amount !== null)
                        {{ $currencyCode !== ''
                            ? \App\Support\Money::foreign((float) $order->refund_amount, $currencyCode)
                            : \App\Support\Money::base((float) $order->refund_amount) }}
                    @else
                        <span class="co-empty-mark">Not recorded</span>
                    @endif
                </span>
            </td>

            <td class="lz-col-rtrack" rowspan="{{ $lineCount }}" data-label="Return tracking">
                @if($firstTracking)
                    <span class="lz-track">{{ $firstTracking }}</span>
                @else
                    <span class="co-empty-mark">-</span>
                @endif
                {{-- Was the raw constant with its underscores swapped for
                     spaces, so RETURN_DELIVERED reached the operator as
                     "RETURN DELIVERED". Through the label class it is the
                     same plain wording Shopee's list uses for the same
                     state. --}}
                @if($ofc)
                    <span class="co-ship__note">{{ \App\Support\ChannelStatusTone::labelFor('lazada.reverse_logistics', $ofc) }}</span>
                @endif
            </td>

            <td class="lz-col-rstatus" rowspan="{{ $lineCount }}" data-label="Status">
                <div class="co-status">
                    <x-ui.badge :tone="$tone">{{ $statusLabel }}</x-ui.badge>
                    {{-- Named, not floating. This printed the reason as a
                         bare phrase under the badge, so "Change of mind" read
                         as a second status rather than as the answer to why.
                         The critique called Shopee's version of this cell the
                         best thing on the page for exactly that reason: each
                         fact says which fact it is, in the order a person
                         asks them.

                         Lazada keeps its own tracking column rather than
                         folding it in the way Shopee's does - Shopee's API
                         returns no return tracking number, so its cell has
                         nothing to fold. Copying the layout here would have
                         deleted a real number off the page. What carries
                         across is the treatment. --}}
                    {{-- Lazada's own seller clock, documented as `sla` in
                         milliseconds on each reverse order line. It is absent
                         from every return in our sandbox, so this renders
                         nothing today and starts working the moment real data
                         carries one. Reported as "Lazada has no deadline"
                         before the docs were read; that was reading our data,
                         not the API. --}}
                    @if($order->sellerDueAt())
                        <x-sla-chip :deadline="$order->sellerDueAt()" label="Respond by" />
                    @endif

                    @if($order->reason)
                        <span class="co-status__note">Reason: {{ \Illuminate\Support\Str::limit($order->reason, 40) }}</span>
                    @endif
                </div>
            </td>

            <td class="lz-col-ract" rowspan="{{ $lineCount }}">
                @if($orderShowUrl)
                    <div class="co-actions">
                        <x-ui.button size="sm" :href="$orderShowUrl">Open order</x-ui.button>
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

{{-- Loading overlay. Same ids as the Orders page's own copy, which is what
     resources/js/pages/lazada-orders.js queries; each page carries its own
     copy because a modal cannot be shared across two separate documents. --}}
<div id="lzLoadingOverlay" class="modal-backdrop">
    <div class="modal co-modal co-modal--sm">
        <div class="co-progress">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div id="lzLoadingTitle" class="co-progress__title">Fetching returns</div>
                <div class="co-progress__note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

