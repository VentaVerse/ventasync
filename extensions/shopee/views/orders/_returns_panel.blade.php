{{-- The Shopee returns list, as a panel.

     Same contract as ext-shopee::orders._panel and for the same reason: this
     list now renders in two places - the channel workspace, and in place on
     the fulfilment hub. It used to exist only as a seller-centre page, so the
     hub's "Returns and refunds" link navigated out of the hub and into the
     channel shell, which is a different sidebar and a different sense of
     where you are, reached by a link that looked like every other filter
     beside it.

     $panelBaseUrl is the caller's own route and $panelHiddenParams the
     context it needs carried (channel + view on the hub, nothing in the
     seller centre), so every tab, filter and pager below comes back to the
     page the operator is actually standing on. Detail links (a return, its
     order, a waybill) still leave for their own page, which is what they are
     for. --}}
@php
    $panelBaseUrl = $panelBaseUrl ?? route('ext.shopee.orders.returns');
    $panelHiddenParams = $panelHiddenParams ?? [];

    // The way back to this channel's orders. On the hub that is the same
    // page with the view swapped; in the seller centre it is the orders
    // route. Passed in rather than assumed, because only the caller knows.
    $panelOrdersUrl = $panelOrdersUrl ?? route('ext.shopee.orders.index');

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
    // Returns is visible to view_shopee_orders holders; Fetch Returns
    // renders only for the manage tier. The route middleware
    // (perm:manage_shopee_orders on the POST) is the real gate; this flag
    // only stops the page offering a button that would 403.
    $canManageShopeeOrders = auth()->user()?->hasPermission('manage_shopee_orders') ?? false;

    $tabs = $tabs ?? [];
    $active_tab = strtoupper((string) ($active_tab ?? 'ALL'));
    $tab_counts = $tab_counts ?? [];
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

    // The return status tone and label maps that used to sit here now live
    // in App\Support\ChannelStatusTone under the 'shopee.returns' surface.
    // The reasoning travelled with them: this page has no mutating row
    // action, so no status takes the accent tone; warning marks the states
    // awaiting an outcome, info the states sitting with Shopee's own
    // review, neutral a settled amount, danger the ways a return ends
    // without a payout.
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
            {{ number_format($returns->total()) }} {{ $returns->total() === 1 ? 'return' : 'returns' }}@if($active_tab !== 'ALL' && isset($tabs[$active_tab])) in {{ $tabs[$active_tab] }}@endif
        </p>
    </div>
</div>
@endif

{{-- No command bar on this page.

     Shopee's get_return_list takes no date range, so the bar had nothing in
     it but a button: measured 1282x63 with 1150px of white, a card holding
     one control, and on an empty tab it was most of the screen. A bar whose
     only job is to hold one action is not a bar. The action moved to the
     tab strip's aside slot below, beside the way back to Orders, which is
     where this page's other standing link already lives. --}}

@if($last_result)
    <div class="co-notice {{ $last_result['ok'] ? 'co-notice--ok' : 'co-notice--fail' }}">
        <span class="co-notice__title">{{ $last_result['ok'] ? 'Done' : 'Failed' }}</span>
        @if(!empty($last_result['message']))
            <span class="co-notice__body">{{ $last_result['message'] }}</span>
        @endif
    </div>
@endif

{{-- Status filters as the same segmented control the Orders page uses.
     The old markup carried two tab rows: one that mirrored the Orders
     page's own status tabs (with this page's tab marked active, purely to
     get back to Orders) and one that filtered this list by return status.
     The channel sidebar already lists Orders and Returns as siblings (see
     partials/channel-nav.blade.php), so the first row's only real job,
     getting back to Orders, is already covered by standing navigation and
     does not need a second, heavier control repeating it. It is kept here
     as a single plain-weight link on the far side of the bar, the same
     x-segment-bar__aside treatment the Orders page uses for the reverse
     trip to Returns. The second row, the actual filter, becomes the
     segment; there is no second data axis on this page the way Orders'
     To Ship tab has Pack/Handover, so x-segment--sub is not pressed into
     service just to use it. --}}
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
            @php $segEmpty = ($count !== null && (int) $count === 0 && ! $isActive); @endphp
            <a @if(! $segEmpty) href="{{ $panelSelfUrl(['tab' => $key], ['page']) }}" @endif
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
    <div class="x-segment-bar__aside-group">
        <a class="x-segment-bar__aside" href="{{ $panelOrdersUrl }}">
            <x-ui.icon name="chevron-left" size="12" />
            All orders
        </a>
        @if($canManageShopeeOrders)
            {{-- Fetch is a standing action, not a filter, so it sits outside
                 the trough with the other standing link rather than in a bar
                 of its own. It still names its cost before it runs: this
                 endpoint always walks the full history. --}}
            <form method="POST" action="{{ route('ext.shopee.orders.fetch_returns') }}" id="formFetchReturns"
                  data-confirm="Fetch every return and refund Shopee has on file? This walks the channel's full history and can take a while."
                  data-confirm-tone="primary">
                @csrf
                <x-ui.button type="submit" variant="secondary" size="sm" id="btnFetchReturns">Fetch returns</x-ui.button>
            </form>
        @endif
    </div>
</div>

{{-- One filter row, replacing the old search card and the old separate
     per-page form. --}}
<form method="GET" action="{{ $panelBaseUrl }}" class="x-filters" id="orders-filter">
@foreach($panelHiddenParams as $panelHiddenKey => $panelHiddenValue)    <input type="hidden" name="{{ $panelHiddenKey }}" value="{{ $panelHiddenValue }}">
@endforeach    <input type="hidden" name="tab" value="{{ $active_tab }}">

    {{-- One box, matching whatever the caller just said. See the note in
         the panel service for why this is not four labelled fields. --}}
    <x-ui.input type="search" name="q" class="x-filters__find"
                value="{{ $findFilter }}"
                placeholder="Return no, order no, buyer or product"
                aria-label="Find a return by return number, order number, buyer or product" />

    {{-- Opened from / to, not the fetch range. On Lazada the command bar
         above carries its own From and To that tell the API which returns to
         pull; these two narrow the list already on the page. Named "Opened"
         rather than "Date" so the two pairs cannot be read as the same
         control, and reading return_created_at, which is when the marketplace
         opened the return rather than when a sync last touched the row. --}}
    <x-ui.input type="date" name="opened_from" class="x-filters__date"
                value="{{ $openedFromFilter }}" aria-label="Opened on or after" title="Opened on or after" />
    <x-ui.input type="date" name="opened_to" class="x-filters__date"
                value="{{ $openedToFilter }}" aria-label="Opened on or before" title="Opened on or before" />

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

@if($returns->count() === 0)
    @php
        if ($hasSearch) {
            $rtEmptyDesc = 'Nothing matches that search. Clear it to see the full list.';
        } elseif ($active_tab !== 'ALL') {
            // Keep the tab's own capitalisation, same fix as the orders
            // page: lowercasing produced unreadable copy.
            $rtEmptyDesc = 'No returns in ' . ($tabs[$active_tab] ?? $active_tab) . ' right now.';
        } else {
            $rtEmptyDesc = 'Nothing has been synced yet. Click Fetch returns to pull data from Shopee.';
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
    <x-ui.empty title="No returns here" :description="$rtEmptyDesc">
        @if($emptyFiltered)
            <x-slot:action>
                <x-ui.button variant="secondary" :href="$panelUrl()">Show all returns</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table class="x-table--stack">
    <x-slot:head>
        <tr>
            <th scope="col" class="sp-col-order">Return</th>
            <th scope="col">Items</th>
            <th scope="col" class="sp-col-buyer">Buyer</th>
            <th scope="col" class="sp-col-total x-td-num">Refund</th>
            <th scope="col" class="sp-col-status">Status</th>
            <th scope="col" class="sp-col-act"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($returns as $ret)
        @php
            $items = is_array($ret->items) ? $ret->items : [];

            // ordered-qty map (item_id_model_id => qty) from the linked order,
            // so a returned line can say how many of the original order it
            // covers.
            $orderedMap = [];
            if ($ret->order && $ret->order->relationLoaded('products')) {
                foreach ($ret->order->products as $p) {
                    $k = ((string) $p->item_id) . '_' . ((string) $p->model_id);
                    $orderedMap[$k] = ($orderedMap[$k] ?? 0) + (int) $p->quantity;
                }
            }

            $statusStr = (string) ($ret->status ?? '');
            $tone = \App\Support\ChannelStatusTone::toneFor('shopee.returns', $statusStr);
            $statusLabel = \App\Support\ChannelStatusTone::labelFor('shopee.returns', $statusStr);

            $detailUrl = route('ext.shopee.orders.return_detail', ['returnSn' => $ret->return_sn]);
            $rawRet = is_array($ret->raw) ? $ret->raw : [];
            $buyer = (string) ($rawRet['user']['username'] ?? '');
            $created = $ret->return_created_at ? $ret->return_created_at->format('Y-m-d H:i') : null;
        @endphp
        <tr>
            <td class="sp-col-order" data-label="Return">
                <a class="x-row-link co-sn" href="{{ $detailUrl }}">{{ $ret->return_sn }}</a>
                @if($created)<span class="co-date">{{ $created }}</span>@endif
                @if($ret->order_sn)
                    <a class="co-ref" href="{{ route('ext.shopee.orders.show', ['orderSn' => $ret->order_sn]) }}">Order {{ $ret->order_sn }}</a>
                @endif
                @if($ret->order?->catalog_order_id)
                    <a class="co-ref" href="{{ url('/sales/orders/'.$ret->order->catalog_order_id) }}">Sales order {{ $ret->order->catalog_order_id }}</a>
                @endif
            </td>

            <td data-label="Items">
                <div class="co-items">
                    @forelse($items as $it)
                        @php
                            $name = (string) ($it['name'] ?? '');
                            $sku = (string) ($it['item_sku'] ?? '');
                            $variation = (string) ($it['variation_sku'] ?? '');
                            $img = (string) ($it['images'][0] ?? '');
                            $retQty = (int) ($it['amount'] ?? 0);
                            $key = ((string) ($it['item_id'] ?? '')) . '_' . ((string) ($it['model_id'] ?? ''));
                            $ordered = $orderedMap[$key] ?? null;
                        @endphp
                        <div class="co-item">
                            <div class="order-img-wrap">
                                @if($img)
                                    <img class="order-img" src="{{ $img }}" alt="">
                                @else
                                    <span class="co-item__none">No image</span>
                                @endif
                            </div>
                            <div class="co-item__body">
                                <span class="co-item__name">{{ $name !== '' ? $name : 'Unnamed product' }}</span>
                                <div class="co-item__meta">
                                    @if($sku !== '')<span class="co-item__sku">{{ $sku }}</span>@endif
                                    @if($variation !== '')<span class="co-item__var">{{ $variation }}</span>@endif
                                    @if($ordered !== null)<span>{{ $retQty }} of {{ $ordered }} ordered</span>@endif
                                </div>
                            </div>
                            <span class="sp-item__qty">&times;{{ $retQty }}</span>
                        </div>
                    @empty
                        <span class="co-empty-mark">No items recorded</span>
                    @endforelse
                </div>
            </td>

            <td class="sp-col-buyer" data-label="Buyer">
                @if($buyer !== '')
                    <span class="sp-buyer">{{ $buyer }}</span>
                @else
                    <span class="sp-buyer co-empty-mark">Not recorded</span>
                @endif
            </td>

            <td class="sp-col-total x-td-num" data-label="Refund">
                <span class="co-total">
                    @if($ret->refund_amount !== null)
                        {{ $ret->currency
                            ? \App\Support\Money::foreign((float) $ret->refund_amount, $ret->currency)
                            : \App\Support\Money::base((float) $ret->refund_amount) }}
                    @else
                        <span class="co-empty-mark">Not recorded</span>
                    @endif
                </span>
            </td>

            <td class="sp-col-status" data-label="Status">
                <div class="co-status">
                    <x-ui.badge :tone="$tone">{{ $statusLabel }}</x-ui.badge>
                    {{-- Both lines used to print Shopee's own constants at
                         the operator - CHANGE_MIND under a badge that said
                         "Requested" - and neither said which was the
                         buyer's reason and which was the parcel's
                         whereabouts. Named, and translated through the
                         class whose whole job is that no marketplace
                         constant reaches a screen raw. --}}
                    @if($ret->reason)
                        <span class="co-band__muted text-xs">Reason: {{ \App\Support\ChannelStatusTone::labelFor('shopee.return_reason', $ret->reason) }}</span>
                    @endif
                    {{-- Some rows carry a literal "-" where Shopee sent
                         nothing, which is a value the truthy check let
                         through and which says less than an absent line. --}}
                    @if($ret->reverse_logistics_status && trim($ret->reverse_logistics_status) !== '-')
                        <span class="co-band__muted text-xs">Parcel: {{ \App\Support\ChannelStatusTone::labelFor('shopee.reverse_logistics', $ret->reverse_logistics_status) }}</span>
                    @endif
                </div>
            </td>

            <td class="sp-col-act">
                <div class="co-actions">
                    <x-ui.menu label="Return {{ $ret->return_sn }} actions">
                        <a class="x-menu__item" href="{{ $detailUrl }}">Open return</a>
                        @if($ret->order_sn)
                            <a class="x-menu__item" href="{{ route('ext.shopee.orders.awb', ['orderSn' => $ret->order_sn]) }}"
                               target="_blank" rel="noopener">Print waybill</a>
                        @endif
                    </x-ui.menu>
                </div>
            </td>
        </tr>
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$returns" />
@endif

{{-- Loading overlay. Identical markup and ids to the Orders page's own
     copy (resources/js/pages/shopee-orders.js queries these by id and is
     already built to wire up #formFetchReturns when it finds it on the
     page - see initFetchReturnsOverlay()). Each page carries its own copy
     because a modal cannot be shared across two separate documents. --}}
<div id="spLoadingOverlay" class="modal-backdrop">
    <div class="modal co-modal co-modal--sm">
        <div class="co-progress">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div id="spLoadingTitle" class="co-progress__title">Fetching returns</div>
                <div class="co-progress__note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

