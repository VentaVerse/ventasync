@php
    $panelHiddenParams = $panelHiddenParams ?? [];

    $panelSelfUrl = function (array $params = [], array $except = []) use ($panelBaseUrl, $panelHiddenParams) {
        $query = array_merge(request()->except($except), $params, $panelHiddenParams);

        return $query === [] ? $panelBaseUrl : $panelBaseUrl . '?' . \Illuminate\Support\Arr::query($query);
    };

    $panelUrl = function (array $params = []) use ($panelBaseUrl, $panelHiddenParams) {
        $query = array_merge($panelHiddenParams, $params);

        return $query === [] ? $panelBaseUrl : $panelBaseUrl . '?' . \Illuminate\Support\Arr::query($query);
    };

    $canManageTiktokOrders = $canManageTiktokOrders
        ?? (auth()->user()?->hasPermission('manage_tiktok/order') ?? false);

    $tabs = $tabs ?? [];
    $active_tab = strtoupper((string) ($active_tab ?? 'ALL'));
    $tab_counts = $tab_counts ?? [];
    $courier_tally = $courier_tally ?? [];
    $perPage = (int) ($per_page ?? request()->query('per_page', 10));
    $findFilter = (string) ($filters['q'] ?? '');
    $placedFromFilter = (string) ($filters['placed_from'] ?? '');
    $placedToFilter = (string) ($filters['placed_to'] ?? '');
    $hasSearch = $findFilter !== '' || $placedFromFilter !== '' || $placedToFilter !== '';
    $pendingSub = $pendingSub ?? ($pending_sub ?? request()->query('pending_sub', 'to_pack'));

    $pendingNav = $pendingNav ?? [
        'to_pack' => 'To Pack',
        'to_handover' => 'To Handover',
    ];

    if (!isset($scopeLabel)) {
        $scopeLabel = null;
        if ($active_tab === 'TO_SHIP') {
            $scopeLabel = $pendingNav[$pendingSub] ?? ($tabs['TO_SHIP'] ?? null);
        } elseif ($active_tab !== 'ALL') {
            $scopeLabel = $tabs[$active_tab] ?? null;
        }
    }

    $ttSortLabels = $ttSortLabels ?? [
        'created_desc' => 'Order date, newest first',
        'created_asc'  => 'Order date, oldest first',
        'ship_by_asc'  => 'Ship-by time, soonest first',
        'ship_by_desc' => 'Ship-by time, latest first',
    ];
@endphp
@if($canManageTiktokOrders)
@endif

@if($last_result)
    <div class="co-notice {{ $last_result['ok'] ? 'co-notice--ok' : 'co-notice--fail' }}" role="status" aria-live="polite">
        <span class="co-notice__title">{{ $last_result['ok'] ? 'Done' : 'Failed' }}</span>
        @if(!empty($last_result['message']))
            <span class="co-notice__body">{{ $last_result['message'] }}</span>
        @endif
        @if(!empty($last_result['awb_url']))
            <x-ui.button size="sm" :href="$last_result['awb_url']" target="_blank" rel="noopener">Print waybill</x-ui.button>
        @endif
    </div>
@endif

<button type="button" class="x-desk-toggle" data-desk-toggle aria-expanded="true">
    <span data-desk-label>Find, dates and sync</span>
    <span class="x-desk-toggle__mark" aria-hidden="true">&#9662;</span>
</button>

<div class="x-segment-bar">
    <nav class="x-segment" aria-label="Order status">
        @foreach($tabs as $key => $label)
            @php
                $isActive = $active_tab === strtoupper((string) $key);
                $count = $tab_counts[$key] ?? null;
            @endphp
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
    <a class="x-segment-bar__aside" href="{{ $panelReturnsUrl ?? route('ext.tiktok.orders.returns') }}">
        Returns and refunds
        <x-ui.icon name="chevron-right" size="12" />
    </a>
</div>

@if($active_tab === 'TO_SHIP')
    <div class="x-segment x-segment--sub" aria-label="To Ship step">
        @foreach($pendingNav as $k => $lbl)
            @php
                $isActive = ($pendingSub === $k) || ($pendingSub === '' && $k === 'to_pack');
                $count = $tab_counts[$k] ?? null;
            @endphp
            <a href="{{ $panelSelfUrl(['tab' => 'TO_SHIP', 'pending_sub' => $k], ['page', 'courier']) }}"
               class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
                @if($isActive) aria-current="step" @endif>
                <span>{{ $lbl }}</span>
                @if($count !== null)
                    <span class="x-segment__count">{{ number_format($count) }}</span>
                @endif
            </a>
        @endforeach
    </div>

    @if($pendingSub !== '')
        <x-fulfilment.courier-strip :tally="$courier_tally ?? []" :self-url="$panelSelfUrl" />
    @endif
@endif

@php
    $ttActiveFilters = 0;
    if (($placedFromFilter ?? '') !== '') { $ttActiveFilters++; }
    if (($placedToFilter ?? '') !== '') { $ttActiveFilters++; }
    if (($sort ?? '') !== '' && ($sort ?? '') !== ('created_' . (($active_tab ?? '') === 'TO_SHIP' ? 'asc' : 'desc'))) { $ttActiveFilters++; }
    if ((int) ($perPage ?? 10) !== 10) { $ttActiveFilters++; }
@endphp

<div class="x-toolbar">
<form method="GET" action="{{ $panelBaseUrl }}" class="x-filters" data-desk-tool id="orders-filter">
@foreach($panelHiddenParams as $panelHiddenKey => $panelHiddenValue)    <input type="hidden" name="{{ $panelHiddenKey }}" value="{{ $panelHiddenValue }}">
@endforeach    <input type="hidden" name="tab" value="{{ $active_tab }}">
    @if($active_tab === 'TO_SHIP')
        <input type="hidden" name="pending_sub" value="{{ $pendingSub }}">
        @if(($filters['courier'] ?? '') !== '')
            <input type="hidden" name="courier" value="{{ $filters['courier'] }}">
        @endif
    @endif

    <x-ui.input type="search" name="q" class="x-filters__find"
                value="{{ $findFilter }}"
                placeholder="Order no, buyer, product or tracking"
                aria-label="Find an order by order number, buyer, product or tracking" />

    <x-ui.foldout label="Filters" :count="$ttActiveFilters ?: null">
    <span class="x-filters__group">
            <span class="x-filters__group-label">Show orders placed</span>
            <x-ui.input type="date" name="placed_from" class="x-filters__date"
                        value="{{ $placedFromFilter }}" aria-label="Placed on or after" title="Placed on or after" />
            <x-ui.input type="date" name="placed_to" class="x-filters__date"
                        value="{{ $placedToFilter }}" aria-label="Placed on or before" title="Placed on or before" />
        </span>

        <div class="x-select-wrap x-filters__select x-filters__select--wide">
            <select name="sort" class="x-input" data-autosubmit aria-label="Sort orders">
                @foreach($ttSortLabels as $value => $label)
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

@if($canManageTiktokOrders)
    <x-ui.foldout label="Sync" class="x-toolbar__sync"
                  hint="Pulls orders from TikTok Shop for the dates below. Fetching an order that has not been seen before adjusts stock.">
        <div class="x-cmdbar x-cmdbar--folded">
            <form method="POST" action="{{ route('ext.tiktok.orders.fetch') }}" id="formFetchTiktokOrders" class="x-cmdbar__row"
              data-confirm="Fetch TikTok Shop orders for the chosen dates? Any order that has not been seen before will adjust stock."
              data-confirm-tone="primary">
            @csrf
            <div class="x-cmdbar__field">
                <label class="x-cmdbar__label" for="ttDateFrom">Pull orders from</label>
                <x-ui.input type="date" id="ttDateFrom" name="date_from" class="x-cmdbar__date"
                            value="{{ old('date_from', request('date_from', now()->subDays(15)->format('Y-m-d'))) }}" />
            </div>
            <div class="x-cmdbar__field">
                <label class="x-cmdbar__label" for="ttDateTo">Pull orders to</label>
                <x-ui.input type="date" id="ttDateTo" name="date_to" class="x-cmdbar__date"
                            value="{{ old('date_to', request('date_to', now()->format('Y-m-d'))) }}" />
            </div>
            <div class="x-cmdbar__spacer"></div>
            <div class="x-cmdbar__actions">
                <x-ui.button type="submit" variant="primary" id="btnFetchOrders">Fetch orders</x-ui.button>
                <x-ui.button type="submit" variant="secondary" id="btnUpdateOrders"
                             data-confirm="Re-read order statuses from TikTok? This refreshes the last 30 days and ignores the dates above."
                             data-confirm-tone="primary"
                             formaction="{{ route('ext.tiktok.orders.updateStatuses') }}">Update statuses</x-ui.button>
                <x-ui.hint>Fetch pulls the orders created between these dates. Update statuses ignores them and refreshes the last 30 days.</x-ui.hint>
            </div>
        </form>
        <x-channel.order-fetch integration="tiktok" :store-id="\Extensions\tiktok\Models\TikTokSetting::defaultStore()?->id" form-id="formFetchTiktokOrders" channel-label="TikTok Shop"
            :begin-url="route('ext.tiktok.orders.fetch_run_begin')" :step-url="route('ext.tiktok.orders.fetch_run_step', ['run' => 0])" :stop-url="route('ext.tiktok.orders.fetch_run_stop', ['run' => 0])" :state-url="route('ext.tiktok.orders.fetch_run_state', ['run' => 0])" />
        </div>
    </x-ui.foldout>
@endif
</div>

@if($orders->count() === 0)
    @php
        if ($hasSearch) {
            $ttEmptyDesc = 'Nothing matches that search. Clear it to see the full list.';
        } elseif ($active_tab !== 'ALL') {
            $ttEmptyDesc = 'No orders in ' . ($scopeLabel ?? $active_tab) . ' right now.';
        } else {
            $ttEmptyDesc = $canManageTiktokOrders
                ? 'Nothing has been synced yet. Pick a date range above and fetch orders from TikTok Shop.'
                : 'Nothing has been synced yet. Someone with permission to sync orders needs to pull them from TikTok Shop first.';
        }

        $emptyFiltered = (isset($active_tab) && strtoupper((string) $active_tab) !== 'ALL')
            || collect(request()->except(array_merge(array_keys($panelHiddenParams ?? []), ['page'])))
            ->filter(fn ($v, $k) => $v !== null && $v !== '' && !($k === 'tab' && $v === 'ALL'))
            ->isNotEmpty();
    @endphp
    <x-ui.empty title="No orders here" :description="$ttEmptyDesc">
        @if($emptyFiltered)
            <x-slot:action>
                <x-ui.button variant="secondary" :href="isset($active_tab) ? $panelUrl(['tab' => 'ALL']) : $panelUrl()">Show all orders</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else

<div data-order-selection>
    <x-ui.selectbar :step="$active_tab !== 'ALL' ? ($tabs[$active_tab] ?? null) : null">
        <form method="POST" action="{{ route('fulfilment.print.pick', ['channel' => 'tiktok']) }}"
              id="tiktok-print-pick" class="x-selectbar__actions" target="_blank" data-selection-actions hidden>
            @csrf
            <x-ui.button type="submit" variant="secondary" size="sm">Print pick list</x-ui.button>
            <x-ui.button type="submit" variant="secondary" size="sm"
                         formaction="{{ route('fulfilment.print.packing', ['channel' => 'tiktok']) }}">Print packing lists</x-ui.button>
            <x-ui.hint label="About these two lists">One pick list for the whole selection, with lines consolidated by SKU so three of the same item is one trip to the shelf. Packing lists are one per order, nothing consolidated, to go in the parcel.</x-ui.hint>
        </form>
    </x-ui.selectbar>

<x-ui.table caption="{{ 'TikTok orders' . ($active_tab !== 'ALL' && isset($tabs[$active_tab]) ? ' in ' . $tabs[$active_tab] : '') }}" class="x-table--stack">
    <x-slot:head>
        <tr>
            <th scope="col" class="tt-col-products">Products</th>
            <th scope="col" class="tt-col-qty">Qty</th>
            <th scope="col" class="tt-col-price x-td-num">Price</th>
            <th scope="col" class="tt-col-total x-td-num">Amount</th>
            <th scope="col" class="tt-col-ship">Shipping</th>
            <th scope="col" class="tt-col-status">Status</th>
            <th scope="col" class="tt-col-act"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($orders as $o)
        @php
            $raw = is_array($o->raw) ? $o->raw : [];
            $orderId = (string) ($o->order_id ?? '');
            $status = (string) ($o->status ?? '');

            $created = $o->order_created_at ? $o->order_created_at->format('Y-m-d H:i') : null;
            $buyer = (string) ($o->buyer_name ?? '');

            $rawItems = $o->products ? $o->products->toArray() : [];
            $grouped = [];
            foreach ($rawItems as $ri) {
                $riName = (string) ($ri['name'] ?? '');
                $riSku = (string) ($ri['sku'] ?? '');
                $riVariation = (string) ($ri['variation'] ?? '');
                $riVarNorm = mb_strtolower(trim($riVariation));
                if (in_array($riVarNorm, ['', 'blank', 'null', 'n/a', 'na', 'none', '-', '--', 'default'], true)) {
                    $riVariation = '';
                }
                $riQty = (int) ($ri['quantity'] ?? 1);
                if ($riQty < 1) { $riQty = 1; }

                $key = mb_strtolower(trim($riSku)) . '|' . mb_strtolower(trim($riName)) . '|' . mb_strtolower(trim($riVariation));
                if (!isset($grouped[$key])) {
                    $grouped[$key] = $ri;
                    $grouped[$key]['quantity'] = $riQty;
                    $grouped[$key]['variation'] = $riVariation;
                } else {
                    $grouped[$key]['quantity'] += $riQty;
                    if (empty($grouped[$key]['image']) && !empty($ri['image'])) {
                        $grouped[$key]['image'] = $ri['image'];
                    }
                }
            }
            $groupedItems = array_values($grouped);

            $displayItems = $groupedItems !== [] ? $groupedItems : [[]];
            $lineCount = count($displayItems);
            $itemCount = count($groupedItems);

            $payment = (isset($raw['payment']) && is_array($raw['payment'])) ? $raw['payment'] : [];
            $totalAmount = $payment['total_amount'] ?? $payment['product_total_amount'] ?? null;

            $currencyCode = (string) ($payment['currency'] ?? '');

            $courier = (string) ($raw['shipping_provider'] ?? '');
            $trackingNo = (string) ($raw['tracking_number'] ?? '');
            if ($courier === '' && !empty($raw['line_items'])) {
                $firstLi = is_array($raw['line_items'][0] ?? null) ? $raw['line_items'][0] : [];
                $courier = (string) ($firstLi['shipping_provider_name'] ?? '');
                if ($trackingNo === '') {
                    $trackingNo = (string) ($firstLi['tracking_number'] ?? '');
                }
            }

            $ttAddr = is_array($raw['recipient_address'] ?? null) ? $raw['recipient_address'] : [];
            $ttCity = null;
            foreach ((array) ($ttAddr['district_info'] ?? []) as $lvl) {
                if (is_array($lvl) && preg_match('/city|municipal/i', (string) ($lvl['address_level_name'] ?? '')) && trim((string) ($lvl['address_name'] ?? '')) !== '') {
                    $ttCity = trim((string) $lvl['address_name']);
                    break;
                }
            }
            if ($ttCity === null) {
                foreach (['city', 'district', 'state'] as $k) {
                    if (is_string($ttAddr[$k] ?? null) && trim($ttAddr[$k]) !== '') { $ttCity = trim($ttAddr[$k]); break; }
                }
            }
            $ttParcel = \App\Support\Fulfilment\PackedParcel::fromItems(array_map(function ($it) {
                $it['image'] = !empty($it['image']) ? $it['image'] : \App\Support\CatalogImages::urlFor($it['sku'] ?? '');
                return $it;
            }, $groupedItems), $courier, $ttCity);

            $isToPack = ($status === 'AWAITING_SHIPMENT');
            $isToHandover = ($status === 'AWAITING_COLLECTION');
            $isShipped = in_array($status, ['IN_TRANSIT', 'DELIVERED', 'COMPLETED'], true);
            $showsWaybill = $isToHandover || $isShipped;
            $hasActions = ($isToPack && $canManageTiktokOrders) || $showsWaybill;

            // tts_sla_time is a Unix timestamp and only means anything before the parcel leaves.
            $awaitingShip = $isToPack || $isToHandover;
            $shipBy = $awaitingShip ? (int) ($raw['tts_sla_time'] ?? 0) : 0;

            $tone = \App\Support\ChannelStatusTone::toneFor('tiktok.orders', $status);
            $statusLabel = \App\Support\ChannelStatusTone::labelFor('tiktok.orders', $status);
        @endphp
        <tr class="co-band">
            <td colspan="7">
                <div class="co-band__row">
                    <div class="co-band__left">
                        <input type="checkbox" class="x-pick" data-selection-item
                               name="ids[]" value="{{ $o->id }}" form="tiktok-print-pick"
                               aria-label="Select order {{ $orderId }}">
                        <span class="co-band__label">Buyer</span>
                        @if($buyer !== '')
                            <span class="co-band__value">{{ $buyer }}</span>
                        @else
                            <span class="co-empty-mark">Not recorded</span>
                        @endif
                        <span class="co-band__label">Order</span>
                        @if($orderId !== '')
                            <a class="co-sn" href="{{ route('ext.tiktok.orders.show', $o->id) }}">{{ $orderId }}</a>
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
            <td class="tt-col-products" data-label="Products">
                <div class="co-item">
                    <div class="order-img-wrap">
                        @php $itImg = !empty($it['image']) ? $it['image'] : \App\Support\CatalogImages::urlFor($it['sku'] ?? ''); @endphp
                        @if($itImg)
                            <img class="order-img" src="{{ $itImg }}" alt="" loading="lazy">
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

            <td class="tt-col-qty" data-label="Qty">
                <span class="co-qty">&times;{{ max(1, (int) ($it['quantity'] ?? 1)) }}</span>
            </td>

            <td class="tt-col-price x-td-num" data-label="Price">
                @php
                    $salePrice = $it['sale_price'] ?? null;
                    $unitPrice = (is_numeric($salePrice) && (float) $salePrice > 0)
                        ? $salePrice
                        : ($it['item_price'] ?? null);
                @endphp
                @if(is_numeric($unitPrice) && (float) $unitPrice > 0)
                    <span class="co-price">{{ $currencyCode !== ''
                        ? \App\Support\Money::foreign((float) $unitPrice, $currencyCode)
                        : \App\Support\Money::base((float) $unitPrice) }}</span>
                @else
                    <span class="co-empty-mark">-</span>
                @endif
            </td>

            @if($idx === 0)
            <td class="tt-col-total x-td-num" rowspan="{{ $lineCount }}" data-label="Amount">
                <span class="co-total">
                    @if(is_numeric($totalAmount))
                        {{ $currencyCode !== ''
                            ? \App\Support\Money::foreign((float) $totalAmount, $currencyCode)
                            : \App\Support\Money::base((float) $totalAmount) }}
                    @elseif($totalAmount !== null && $totalAmount !== '')
                        {{ $totalAmount }}
                    @else
                        <span class="co-empty-mark">Not recorded</span>
                    @endif
                </span>
            </td>

            <td class="tt-col-ship" rowspan="{{ $lineCount }}" data-label="Shipping">
                @if($courier !== '')
                    <span class="co-carrier">{{ $courier }}</span>
                    @if($trackingNo !== '')
                        <span class="co-ship__no">{{ $trackingNo }}</span>
                    @endif
                @elseif($trackingNo !== '')
                    <span class="co-ship__no">{{ $trackingNo }}</span>
                @else
                    <span class="co-empty-mark">-</span>
                @endif
            </td>

            <td class="tt-col-status" rowspan="{{ $lineCount }}" data-label="Status">
                <div class="co-status">
                    <x-ui.badge :tone="$tone">{{ $statusLabel }}</x-ui.badge>
                    <x-sla-chip :deadline="$shipBy" />
                </div>
            </td>

            <td class="tt-col-act" rowspan="{{ $lineCount }}">
                @if(!$hasActions)
                    <span class="co-empty-mark">-</span>
                @else
                <div class="co-actions co-actions--wrap">
                    @if($isToPack && $canManageTiktokOrders)
                        <form method="POST" action="{{ route('ext.tiktok.orders.ship', $o->id) }}"
                              data-busy="Marking shipped on TikTok Shop">
                            @csrf
                            <x-ui.button type="button" variant="secondary" size="sm" class="btnTtShip"
                                         data-pc-channel="tiktok" data-pc-order="{{ $o->id }}"
                                         data-order-id="{{ $o->id }}"
                                         data-order-no="{{ $o->order_id ?? $o->id }}">Ship order</x-ui.button>
                        </form>
                        <template data-parcel-for="{{ $o->id }}"><x-fulfilment.parcel :parcel="$ttParcel" /></template>
                    @endif

                    @if($showsWaybill)
                        <x-ui.button size="sm"
                                     :href="route('ext.tiktok.orders.awb', $o->id)"
                                     target="_blank" rel="noopener">Print waybill</x-ui.button>

                        @if($isToHandover)
                            <x-ui.button size="sm"
                                         :href="route('ext.tiktok.orders.awb', [$o->id, 'refresh' => 1])"
                                         target="_blank" rel="noopener">Refresh waybill</x-ui.button>
                        @endif

                        <x-ui.button size="sm" class="btnTtTracking"
                                     data-url="{{ route('ext.tiktok.orders.tracking', $o->id) }}">Tracking</x-ui.button>
                    @endif
                </div>
                @endif
            </td>
            @endif
        </tr>
        @endforeach
    @endforeach
</x-ui.table>
</div>

<x-ui.pager :paginator="$orders" />
@endif

<div id="ttLoadingOverlay" class="modal-backdrop" data-busy-overlay>
    <div class="modal co-modal co-modal--sm">
        <div class="co-progress">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div id="ttLoadingTitle" class="co-progress__title" data-busy-title>Fetching orders</div>
                <div class="co-progress__note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

@include('ext-tiktok::orders._modals')
