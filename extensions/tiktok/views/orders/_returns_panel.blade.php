@php
    $panelBaseUrl = $panelBaseUrl ?? route('ext.tiktok.orders.returns');
    $panelHiddenParams = $panelHiddenParams ?? [];
    $panelOrdersUrl = $panelOrdersUrl ?? route('ext.tiktok.orders.index');
    $panelSelfUrl = function (array $params = [], array $except = []) use ($panelBaseUrl, $panelHiddenParams) {
        $query = array_merge(request()->except($except), $params, $panelHiddenParams);
        return $query === [] ? $panelBaseUrl : $panelBaseUrl . '?' . \Illuminate\Support\Arr::query($query);
    };
    $panelUrl = function (array $params = []) use ($panelBaseUrl, $panelHiddenParams) {
        $query = array_merge($panelHiddenParams, $params);
        return $query === [] ? $panelBaseUrl : $panelBaseUrl . '?' . \Illuminate\Support\Arr::query($query);
    };
    $canManage = auth()->user()?->hasPermission('manage_tiktok/order') ?? false;
    $tabs = $tabs ?? [];
    $active_tab = strtoupper((string) ($active_tab ?? 'ALL'));
    $tab_counts = $tab_counts ?? [];
    $erpMap = $erpMap ?? collect();
    $perPage = (int) ($per_page ?? request()->query('per_page', 10));
    $findFilter = (string) ($filters['q'] ?? '');
    $openedFromFilter = (string) ($filters['opened_from'] ?? '');
    $openedToFilter = (string) ($filters['opened_to'] ?? '');
    $hasSearch = $findFilter !== '' || $openedFromFilter !== '' || $openedToFilter !== '';
    $sortLabels = [
        'updated_desc' => 'Last update, newest first',
        'updated_asc' => 'Last update, oldest first',
        'opened_desc' => 'Opened, newest first',
        'opened_asc' => 'Opened, oldest first',
    ];
    $statusMap = \Extensions\tiktok\Services\TikTokReturnsPanel::STATUS_MAP;
    $typeMap = \Extensions\tiktok\Services\TikTokReturnsPanel::TYPE_MAP;
    $activeFilters = (int) ($openedFromFilter !== '') + (int) ($openedToFilter !== '') + (int) (($sort ?? 'updated_desc') !== 'updated_desc') + (int) ($perPage !== 10);
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
            @php $isActive = $active_tab === strtoupper((string) $key); $count = $tab_counts[$key] ?? null; @endphp
            <a href="{{ $panelSelfUrl(['tab' => $key], ['page']) }}" class="x-segment__item {{ $isActive ? 'is-active' : '' }}" @if($isActive) aria-current="true" @endif>
                <span>{{ $label }}</span>
                @if($count !== null)<span class="x-segment__count">{{ number_format($count) }}</span>@endif
            </a>
        @endforeach
    </nav>
    <a class="x-segment-bar__aside" href="{{ $panelOrdersUrl }}">
        <x-ui.icon name="chevron-left" size="12" />
        All orders
    </a>
</div>

<div class="x-toolbar">
<form method="GET" action="{{ $panelBaseUrl }}" class="x-filters" data-desk-tool id="orders-filter">
@foreach($panelHiddenParams as $k => $v)    <input type="hidden" name="{{ $k }}" value="{{ $v }}">
@endforeach
    <input type="hidden" name="tab" value="{{ $active_tab }}">
    <x-ui.input type="search" name="q" class="x-filters__find" value="{{ $findFilter }}"
                placeholder="Return no, order no or product" aria-label="Find a return by return number, order number or product" />
    <x-ui.foldout label="Filters" :count="$activeFilters ?: null">
        <span class="x-filters__group">
            <span class="x-filters__group-label">Opened</span>
            <x-ui.input type="date" name="opened_from" class="x-filters__date" value="{{ $openedFromFilter }}" aria-label="Opened on or after" title="Opened on or after" />
            <x-ui.input type="date" name="opened_to" class="x-filters__date" value="{{ $openedToFilter }}" aria-label="Opened on or before" title="Opened on or before" />
        </span>
        <div class="x-select-wrap x-filters__select x-filters__select--wide">
            <select name="sort" class="x-input" data-autosubmit aria-label="Sort returns">
                @foreach($sortLabels as $value => $label)
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

@if($canManage)
    <x-ui.foldout label="Sync" class="x-toolbar__sync" hint="Pulls returns from TikTok Shop opened between the dates below.">
        <div class="x-cmdbar x-cmdbar--folded">
            <form method="POST" action="{{ route('ext.tiktok.orders.fetch_returns') }}" class="x-cmdbar__row"
                  data-confirm="Fetch the returns and refunds TikTok Shop has on file for these dates?" data-confirm-tone="primary" data-confirm-verb="Fetch returns">
                @csrf
                <div class="x-cmdbar__field">
                    <label class="x-cmdbar__label" for="ttReturnFrom">Pull returns from</label>
                    <x-ui.input type="date" id="ttReturnFrom" name="date_from" class="x-cmdbar__date" value="{{ old('date_from', request('date_from', now()->subDays(15)->format('Y-m-d'))) }}" />
                </div>
                <div class="x-cmdbar__field">
                    <label class="x-cmdbar__label" for="ttReturnTo">Pull returns to</label>
                    <x-ui.input type="date" id="ttReturnTo" name="date_to" class="x-cmdbar__date" value="{{ old('date_to', request('date_to', now()->format('Y-m-d'))) }}" />
                </div>
                <div class="x-cmdbar__spacer"></div>
                <div class="x-cmdbar__actions">
                    <x-ui.button type="submit" variant="primary">Fetch returns</x-ui.button>
                </div>
            </form>
            <form method="POST" action="{{ route('ext.tiktok.orders.fetch_returns') }}"
                  data-confirm="Re-read every return TikTok Shop has on file, ignoring the dates? This refreshes statuses for returns the buyer may have cancelled." data-confirm-tone="primary" data-confirm-verb="Update statuses">
                @csrf
                <x-ui.button type="submit" variant="secondary">Update statuses</x-ui.button>
            </form>
        </div>
    </x-ui.foldout>
@endif
</div>

@if($orders->count() === 0)
    @php
        $emptyDesc = $hasSearch ? 'Nothing matches that search. Clear it to see the full list.'
            : ($active_tab !== 'ALL' ? 'No returns in ' . ($tabs[$active_tab] ?? $active_tab) . ' right now.'
            : ($canManage ? 'Nothing has been synced yet. Use the button above to pull returns from TikTok Shop.'
                : 'Nothing has been synced yet. Someone with permission to sync returns needs to pull them from TikTok Shop first.'));
        $emptyFiltered = $active_tab !== 'ALL' || $hasSearch;
    @endphp
    <x-ui.empty title="No returns here" :description="$emptyDesc">
        @if($emptyFiltered)
            <x-slot:action>
                <x-ui.button variant="secondary" :href="$panelUrl(['tab' => 'ALL'])">Show all returns</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table caption="{{ 'TikTok Shop returns and refunds' . ($active_tab !== 'ALL' && isset($tabs[$active_tab]) ? ' in ' . $tabs[$active_tab] : '') }}" class="x-table--stack">
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
    @foreach($orders as $ret)
        @php
            $currencyCode = (string) ($ret->currency ?? '');
            $items = is_array($ret->items) && count($ret->items) ? $ret->items : [[]];
            $lineCount = count($items);
            $erpId = $erpMap[$ret->order_id] ?? null;
            [$statusLabel, $tone] = $statusMap[(string) $ret->return_status] ?? [str_replace('_', ' ', ucfirst(strtolower((string) $ret->return_status))), 'neutral'];
            $orderShowUrl = $ret->order_id ? route('ext.tiktok.orders.show', ['id' => $ret->order_id]) : null;
            $updated = $ret->return_updated_at?->format('Y-m-d H:i');
        @endphp
        <tr class="co-band">
            <td colspan="7">
                <div class="co-band__row">
                    <div class="co-band__left">
                        <span class="co-band__label">Return</span>
                        <span class="co-band__value co-band__value--mono">{{ $ret->return_id }}</span>
                        <span class="co-band__label">Order</span>
                        @if($orderShowUrl)
                            <a class="co-sn" href="{{ $orderShowUrl }}">{{ $ret->order_id }}</a>
                        @else
                            <span class="co-empty-mark">No order number</span>
                        @endif
                        @if($ret->return_type)
                            <span class="co-band__muted">{{ $typeMap[$ret->return_type] ?? $ret->return_type }}</span>
                        @endif
                        @if($erpId)
                            <a class="co-ref" href="{{ url('/sales/orders/' . $erpId) }}">Sales order {{ $erpId }}</a>
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
        @foreach($items as $idx => $it)
            @php
                $sku = (string) ($it['seller_sku'] ?? '');
                $name = (string) ($it['product_name'] ?? '');
                $img = (string) ($it['image'] ?? '');
                $qty = (int) ($it['quantity'] ?? 1);
                $itRefund = $it['refund_amount'] ?? null;
            @endphp
            <tr class="co-itemrow {{ $idx === $lineCount - 1 ? 'co-itemrow--last' : '' }}">
                <td class="lz-col-rproducts" data-label="Returned products">
                    <div class="co-item">
                        <div class="order-img-wrap">
                            @if($img)<img class="order-img" src="{{ $img }}" alt="">@else<span class="co-item__none">No image</span>@endif
                        </div>
                        <div class="co-item__body">
                            <span class="co-item__name">{{ $name !== '' ? $name : ($sku !== '' ? $sku : 'Unnamed product') }}</span>
                            <div class="co-item__meta">
                                @if($sku !== '')<span class="co-item__sku">{{ $sku }}</span>@endif
                                @if(!empty($it['sku_name']))<span>{{ $it['sku_name'] }}</span>@endif
                            </div>
                        </div>
                    </div>
                </td>
                <td class="lz-col-rqty" data-label="Qty"><span class="co-qty">&times;{{ max(1, $qty) }}</span></td>
                <td class="lz-col-refund x-td-num" data-label="Refund">
                    @if($itRefund !== null)
                        <span class="co-price">{{ $currencyCode !== '' ? \App\Support\Money::foreign((float) $itRefund, $currencyCode) : \App\Support\Money::base((float) $itRefund) }}</span>
                    @else
                        <span class="co-empty-mark">-</span>
                    @endif
                </td>
                @if($idx === 0)
                <td class="lz-col-rtotal x-td-num" rowspan="{{ $lineCount }}" data-label="Total">
                    <span class="co-total">
                        @if($ret->refund_amount !== null)
                            {{ $currencyCode !== '' ? \App\Support\Money::foreign((float) $ret->refund_amount, $currencyCode) : \App\Support\Money::base((float) $ret->refund_amount) }}
                        @else
                            <span class="co-empty-mark">Not recorded</span>
                        @endif
                    </span>
                </td>
                <td class="lz-col-rtrack" rowspan="{{ $lineCount }}" data-label="Return tracking">
                    @if($ret->tracking_number)<span class="lz-track">{{ $ret->tracking_number }}</span>@else<span class="co-empty-mark">-</span>@endif
                </td>
                <td class="lz-col-rstatus" rowspan="{{ $lineCount }}" data-label="Status">
                    <div class="co-status">
                        <x-ui.badge :tone="$tone">{{ $statusLabel }}</x-ui.badge>
                        @if($ret->reason)
                            <span class="co-status__note">Reason: {{ \Illuminate\Support\Str::limit($ret->reason, 40) }}</span>
                        @endif
                    </div>
                </td>
                <td class="lz-col-ract" rowspan="{{ $lineCount }}">
                    @if($orderShowUrl)
                        <div class="co-actions"><x-ui.button size="sm" :href="$orderShowUrl">Open order</x-ui.button></div>
                    @endif
                </td>
                @endif
            </tr>
        @endforeach
    @endforeach
</x-ui.table>
<x-ui.pager :paginator="$orders" />
@endif
