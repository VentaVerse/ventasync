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
    $perPage = (int) ($per_page ?? request()->query('per_page', 10));
    $reverseIdFilter = (string) ($filters['reverse_order_id'] ?? '');
    $tradeIdFilter = (string) ($filters['trade_order_id'] ?? '');
    $hasSearch = $reverseIdFilter !== '' || $tradeIdFilter !== '';
@endphp

<div id="lazada-returns-page" class="co-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Returns</h1>
        <p class="x-page-sub">
            {{ number_format($orders->total()) }} {{ $orders->total() === 1 ? 'return' : 'returns' }}@if($active_tab !== 'ALL' && isset($tabs[$active_tab])) in {{ $tabs[$active_tab] }}@endif
        </p>
    </div>
</div>

@if($canManageLazadaOrders)
{{-- One command bar, the same grammar as the Orders page's fetch bar. The
     dates are real here, unlike on Shopee's returns page: Lazada's
     getreverseordersforseller takes create_time_start / create_time_end and
     LazadaOrderController::fetchReturns() passes them through when they are
     filled, so the pair is offered and the hint says plainly what leaving
     them empty does. Promising a filter the fetch does not perform is the
     mistake Shopee's returns page was built to avoid; so is hiding one it
     does. --}}
<div class="x-cmdbar">
    <form method="POST" action="{{ route('ext.lazada.orders.fetch_returns') }}" id="formFetchLazadaReturns" class="x-cmdbar__row">
        @csrf
        <div class="x-cmdbar__field">
            <label class="x-cmdbar__label" for="lzReturnFrom">From</label>
            <x-ui.input type="date" id="lzReturnFrom" name="date_from" class="x-cmdbar__date"
                        value="{{ old('date_from', request('date_from')) }}" />
        </div>
        <div class="x-cmdbar__field">
            <label class="x-cmdbar__label" for="lzReturnTo">To</label>
            <x-ui.input type="date" id="lzReturnTo" name="date_to" class="x-cmdbar__date"
                        value="{{ old('date_to', request('date_to')) }}" />
        </div>
        <div class="x-cmdbar__spacer"></div>
        <div class="x-cmdbar__actions">
            <x-ui.button type="submit" variant="primary" id="btnFetchLazadaReturns">Fetch returns</x-ui.button>
            <x-ui.hint>Leave both dates empty to pull every return Lazada has on file. That can take a moment on a large history.</x-ui.hint>
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

{{-- The old markup carried two tab rows: one repeating the Orders page's
     seven status tabs purely as a way back, and one filtering this list. The
     channel sidebar already lists Orders and Returns as siblings, so the
     first row's only real job is covered by standing navigation; it survives
     as a single plain-weight link on the far side of the bar, the same
     treatment the Orders page uses for the trip here. The second row, the
     actual filter, becomes the segment. --}}
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
            <a href="{{ $panelSelfUrl(['tab' => $key], ['page']) }}"
               class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
               @if($isActive) aria-current="page" @endif>
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
<form method="GET" action="{{ $panelBaseUrl }}" class="x-filters" id="orders-filter">
@foreach($panelHiddenParams as $panelHiddenKey => $panelHiddenValue)    <input type="hidden" name="{{ $panelHiddenKey }}" value="{{ $panelHiddenValue }}">
@endforeach
    <input type="hidden" name="tab" value="{{ $active_tab }}">

    <x-ui.input type="search" name="reverse_order_id" class="x-filters__search"
                value="{{ $reverseIdFilter }}" placeholder="Return number" aria-label="Search by return number" />
    <x-ui.input type="search" name="trade_order_id" class="x-filters__search"
                value="{{ $tradeIdFilter }}" placeholder="Order number" aria-label="Search by original order number" />

    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="per_page" class="x-input" data-autosubmit aria-label="Returns per page">
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
            $lzEmptyDesc = 'Nothing matches that return or order number. Clear the search to see the full list.';
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
    @endphp
    <x-ui.empty title="No returns here" :description="$lzEmptyDesc" />
@else
<x-ui.table>
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
                            <span class="co-band__muted">{{ str_replace('_', ' ', $order->reverse_type) }}</span>
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
                        <span class="co-item__name">{{ $pSku !== '' ? $pSku : ($sSku !== '' ? $sSku : 'Unnamed product') }}</span>
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
                    <span class="co-empty-mark">&mdash;</span>
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
                    <span class="co-empty-mark">&mdash;</span>
                @endif
                @if($ofc)
                    <span class="co-ship__note">{{ str_replace('_', ' ', $ofc) }}</span>
                @endif
            </td>

            <td class="lz-col-rstatus" rowspan="{{ $lineCount }}" data-label="Status">
                <div class="co-status">
                    <x-ui.badge :tone="$tone">{{ $statusLabel }}</x-ui.badge>
                    @if($order->reason)
                        <span class="co-status__note">{{ \Illuminate\Support\Str::limit($order->reason, 40) }}</span>
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

</div>{{-- /#lazada-returns-page --}}
