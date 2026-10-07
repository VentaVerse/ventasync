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
    $canManageLazadaOrders = auth()->user()?->hasPermission('manage_lazada/order') ?? false;

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
    $rtSortLabels = [
        'updated_desc' => 'Last update, newest first',
        'updated_asc'  => 'Last update, oldest first',
        'opened_desc'  => 'Opened, newest first',
        'opened_asc'   => 'Opened, oldest first',
    ];
@endphp


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
@endif

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
    <nav class="x-segment" aria-label="Return status">
        @foreach($tabs as $key => $label)
            @php
                $isActive = $active_tab === strtoupper((string) $key);
                $count = $tab_counts[$key] ?? null;
            @endphp
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

@php
    $lzrActiveFilters = 0;
    if (($openedFromFilter ?? '') !== '') { $lzrActiveFilters++; }
    if (($openedToFilter ?? '') !== '') { $lzrActiveFilters++; }
    if (($sort ?? 'updated_desc') !== 'updated_desc') { $lzrActiveFilters++; }
    if ((int) ($perPage ?? 10) !== 10) { $lzrActiveFilters++; }
@endphp

<div class="x-toolbar">
<form method="GET" action="{{ $panelBaseUrl }}" class="x-filters" data-desk-tool id="orders-filter">
@foreach($panelHiddenParams as $panelHiddenKey => $panelHiddenValue)    <input type="hidden" name="{{ $panelHiddenKey }}" value="{{ $panelHiddenValue }}">
@endforeach
    <input type="hidden" name="tab" value="{{ $active_tab }}">

    <x-ui.input type="search" name="q" class="x-filters__find"
                value="{{ $findFilter }}"
                placeholder="Return no, order no or product"
                aria-label="Find a return by return number, order number or product" />

    <x-ui.foldout label="Filters" :count="$lzrActiveFilters ?: null">
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
    </x-ui.foldout>

    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>

    @if($hasSearch)
        <a class="x-filters__reset" href="{{ $panelUrl(['tab' => $active_tab, 'per_page' => $perPage]) }}">Reset</a>
    @endif
</form>

@if($canManageLazadaOrders)
    <x-ui.foldout label="Sync" class="x-toolbar__sync"
                  hint="Pulls returns from Lazada opened between the dates below.">
        <div class="x-cmdbar x-cmdbar--folded">
            <form method="POST" action="{{ route('ext.lazada.orders.fetch_returns') }}" id="formFetchLazadaReturns" class="x-cmdbar__row"
              data-confirm="Fetch every return and refund Lazada has on file? This walks the channel's full history and can take a while."
              data-confirm-tone="primary">
            @csrf
            <div class="x-cmdbar__field">
                <label class="x-cmdbar__label" for="lzReturnFrom">Pull orders from</label>
                <x-ui.input type="date" id="lzReturnFrom" name="date_from" class="x-cmdbar__date"
                            value="{{ old('date_from', request('date_from', now()->subDays(15)->format('Y-m-d'))) }}" />
            </div>
            <div class="x-cmdbar__field">
                <label class="x-cmdbar__label" for="lzReturnTo">Pull orders to</label>
                <x-ui.input type="date" id="lzReturnTo" name="date_to" class="x-cmdbar__date"
                            value="{{ old('date_to', request('date_to', now()->format('Y-m-d'))) }}" />
            </div>
            <div class="x-cmdbar__spacer"></div>
            <div class="x-cmdbar__actions">
                <x-ui.button type="submit" variant="primary" id="btnFetchLazadaReturns">Fetch returns</x-ui.button>
                <x-ui.hint>Fetch pulls the returns opened between these dates. Update statuses ignores them and re-reads every return on file.</x-ui.hint>
            </div>
        </form>
    
        <form method="POST" action="{{ route('ext.lazada.orders.fetch_returns') }}" id="formUpdateLazadaReturns"
              data-confirm="Re-read every return Lazada has on file, ignoring the dates? This refreshes statuses for returns the buyer may have cancelled."
              data-confirm-tone="primary">
            @csrf
            <x-ui.button type="submit" variant="secondary" id="btnUpdateLazadaReturns">Update statuses</x-ui.button>
        </form>
        </div>
    </x-ui.foldout>
@endif
</div>

@if($orders->count() === 0)
    @php
        if ($hasSearch) {
            $lzEmptyDesc = 'Nothing matches that search. Clear it to see the full list.';
        } elseif ($active_tab !== 'ALL') {
            $lzEmptyDesc = 'No returns in ' . ($tabs[$active_tab] ?? $active_tab) . ' right now.';
        } else {
            $lzEmptyDesc = $canManageLazadaOrders
                ? 'Nothing has been synced yet. Use the button above to pull returns from Lazada.'
                : 'Nothing has been synced yet. Someone with permission to sync returns needs to pull them from Lazada first.';
        }

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
                $itRefund = isset($it['_refund_sum']) ? ((float) $it['_refund_sum'] / 100) : null;
                $itPrice = isset($it['item_unit_price']) ? ((float) $it['item_unit_price'] / 100) : null;
                $img = (string) ($imgMap[$sSku] ?? ($imgMap[$pSku] ?? ''));
                $pName = (string) ($nameMap[$sSku] ?? ($nameMap[$pSku] ?? ''));
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
                @if($ofc)
                    <span class="co-ship__note">{{ \App\Support\ChannelStatusTone::labelFor('lazada.reverse_logistics', $ofc) }}</span>
                @endif
            </td>

            <td class="lz-col-rstatus" rowspan="{{ $lineCount }}" data-label="Status">
                <div class="co-status">
                    <x-ui.badge :tone="$tone">{{ $statusLabel }}</x-ui.badge>
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

