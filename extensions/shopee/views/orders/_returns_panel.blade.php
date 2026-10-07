@php
    $panelBaseUrl = $panelBaseUrl ?? route('ext.shopee.orders.returns');
    $panelHiddenParams = $panelHiddenParams ?? [];

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
    $canManageShopeeOrders = auth()->user()?->hasPermission('manage_shopee/order') ?? false;

    $tabs = $tabs ?? [];
    $active_tab = strtoupper((string) ($active_tab ?? 'ALL'));
    $tab_counts = $tab_counts ?? [];
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
            {{ number_format($returns->total()) }} {{ $returns->total() === 1 ? 'return' : 'returns' }}@if($active_tab !== 'ALL' && isset($tabs[$active_tab])) in {{ $tabs[$active_tab] }}@endif
        </p>
    </div>
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
    <div class="x-segment-bar__aside-group">
        <a class="x-segment-bar__aside" href="{{ $panelOrdersUrl }}">
            <x-ui.icon name="chevron-left" size="12" />
            All orders
        </a>
        @if($canManageShopeeOrders)
            <form method="POST" action="{{ route('ext.shopee.orders.fetch_returns') }}" id="formFetchReturns"
                  data-confirm="Fetch every return and refund Shopee has on file? This walks the channel's full history and can take a while."
                  data-confirm-tone="primary">
                @csrf
                <x-ui.button type="submit" variant="secondary" size="sm" id="btnFetchReturns">Fetch returns</x-ui.button>
            </form>
        @endif
    </div>
</div>

@php
    $sprActiveFilters = 0;
    if (($openedFromFilter ?? '') !== '') { $sprActiveFilters++; }
    if (($openedToFilter ?? '') !== '') { $sprActiveFilters++; }
    if (($sort ?? 'updated_desc') !== 'updated_desc') { $sprActiveFilters++; }
    if ((int) ($perPage ?? 10) !== 10) { $sprActiveFilters++; }
@endphp

<div class="x-toolbar">
<form method="GET" action="{{ $panelBaseUrl }}" class="x-filters" data-desk-tool id="orders-filter">
@foreach($panelHiddenParams as $panelHiddenKey => $panelHiddenValue)    <input type="hidden" name="{{ $panelHiddenKey }}" value="{{ $panelHiddenValue }}">
@endforeach    <input type="hidden" name="tab" value="{{ $active_tab }}">

    <x-ui.input type="search" name="q" class="x-filters__find"
                value="{{ $findFilter }}"
                placeholder="Return no, order no, buyer or product"
                aria-label="Find a return by return number, order number, buyer or product" />

    <x-ui.foldout label="Filters" :count="$sprActiveFilters ?: null">
        <span class="x-filters__group">
            <span class="x-filters__group-label" id="sp-opened-label">Opened</span>
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
</div>

@if($returns->count() === 0)
    @php
        if ($hasSearch) {
            $rtEmptyDesc = 'Nothing matches that search. Clear it to see the full list.';
        } elseif ($active_tab !== 'ALL') {
            $rtEmptyDesc = 'No returns in ' . ($tabs[$active_tab] ?? $active_tab) . ' right now.';
        } else {
            $rtEmptyDesc = 'Nothing has been synced yet. Click Fetch returns to pull data from Shopee.';
        }

        $emptyFiltered = (isset($active_tab) && strtoupper((string) $active_tab) !== 'ALL')
            || collect(request()->except(array_merge(array_keys($panelHiddenParams ?? []), ['page'])))
            ->filter(fn ($v, $k) => $v !== null && $v !== '' && !($k === 'tab' && $v === 'ALL'))
            ->isNotEmpty();
    @endphp
    <x-ui.empty title="No returns here" :description="$rtEmptyDesc">
        @if($emptyFiltered)
            <x-slot:action>
                <x-ui.button variant="secondary" :href="isset($active_tab) ? $panelUrl(['tab' => 'ALL']) : $panelUrl()">Show all returns</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table caption="{{ 'Shopee returns and refunds' . ($active_tab !== 'ALL' && isset($tabs[$active_tab]) ? ' in ' . $tabs[$active_tab] : '') }}" class="x-table--stack">
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
                                    <x-fulfilment.variation :sku="$sku" :fallback="$variation" />
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

                    @if($ret->sellerDueAt())
                        <x-sla-chip :deadline="$ret->sellerDueAt()" label="Respond by" />
                    @endif
                    @if($ret->reason)
                        <span class="co-band__muted text-xs">Reason: {{ \App\Support\ChannelStatusTone::labelFor('shopee.return_reason', $ret->reason) }}</span>
                    @endif
                    @if($ret->reverse_logistics_status && trim($ret->reverse_logistics_status) !== '-')
                        <span class="co-band__muted text-xs">Parcel: {{ \App\Support\ChannelStatusTone::labelFor('shopee.reverse_logistics', $ret->reverse_logistics_status) }}</span>
                    @endif
                </div>
            </td>

            <td class="sp-col-act">
                <div class="co-actions">
                    <x-ui.button size="sm" :href="$detailUrl">Open return</x-ui.button>
                    @if($ret->order_sn)
                        <x-ui.button size="sm"
                                     :href="route('ext.shopee.orders.awb', ['orderSn' => $ret->order_sn])"
                                     target="_blank" rel="noopener">Print waybill</x-ui.button>
                    @endif
                </div>
            </td>
        </tr>
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$returns" />
@endif

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

