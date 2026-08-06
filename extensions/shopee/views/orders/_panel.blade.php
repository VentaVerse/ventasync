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
@endphp
@if($canManageShopeeOrders)
{{-- One command bar. This replaces the three stacked forms whose controls
     each set their own height and self-alignment, which is what made the
     Fetch and Update buttons sit off the baseline of their own date
     inputs. Every child of the row ends in a box exactly --control-h tall
     and the row is bottom aligned, so the controls share a baseline and,
     being the same height, a top edge too. --}}
<div class="x-cmdbar">
    <form method="POST" action="{{ route('ext.shopee.orders.fetch') }}" id="formFetchShopeeOrders" class="x-cmdbar__row">
        @csrf
        <div class="x-cmdbar__field">
            <label class="x-cmdbar__label" for="spDateFrom">From</label>
            <x-ui.input type="date" id="spDateFrom" name="date_from" class="x-cmdbar__date"
                        value="{{ old('date_from', request('date_from')) }}" />
        </div>
        <div class="x-cmdbar__field">
            <label class="x-cmdbar__label" for="spDateTo">To</label>
            <x-ui.input type="date" id="spDateTo" name="date_to" class="x-cmdbar__date"
                        value="{{ old('date_to', request('date_to')) }}" />
        </div>
        <label class="x-cmdbar__check">
            <input type="checkbox" name="no_stock" value="1">
            <span>Skip stock adjust</span>
        </label>
        <div class="x-cmdbar__spacer"></div>
        <div class="x-cmdbar__actions">
            <x-ui.button type="submit" variant="primary" id="btnFetchOrders">Fetch orders</x-ui.button>
            <x-ui.button type="submit" variant="secondary" id="btnUpdateOrders"
                         formaction="{{ route('ext.shopee.orders.update_statuses') }}">Update statuses</x-ui.button>
        </div>
    </form>
    <p class="x-cmdbar__hint">Both dates are required, for fetching new orders and for updating statuses.</p>
</div>
@endif

@if($last_result)
    <div class="sp-notice {{ $last_result['ok'] ? 'sp-notice--ok' : 'sp-notice--fail' }}">
        <span class="sp-notice__title">{{ $last_result['ok'] ? 'Done' : 'Failed' }}</span>
        @if(!empty($last_result['message']))
            <span class="sp-notice__body">{{ $last_result['message'] }}</span>
        @endif
    </div>
@endif

{{-- Status filters as a segmented control. Seven views over one list, so
     "pick one" is the right grammar; the old .tabs strip implied seven
     pages. Counts ride along as muted mono numbers. Returns is a sibling
     page rather than an eighth filter, so it sits outside the trough as a
     plain link (the channel sidebar lists it too). --}}
<div class="x-segment-bar">
    <div class="x-segment" role="tablist" aria-label="Order status">
        @foreach($tabs as $key => $label)
            @php
                $isActive = $active_tab === strtoupper((string) $key);
                $count = $tab_counts[$key] ?? null;
            @endphp
            <a href="{{ $panelSelfUrl(['tab' => $key], ['page', 'pending_sub']) }}"
               class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
               @if($isActive) aria-current="page" @endif>
                <span>{{ $label }}</span>
                @if($count !== null)
                    <span class="x-segment__count">{{ number_format($count) }}</span>
                @endif
            </a>
        @endforeach
    </div>
    <a class="x-segment-bar__aside" href="{{ route('ext.shopee.orders.returns') }}">
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
            <a href="{{ $panelSelfUrl(['tab' => 'PENDING', 'pending_sub' => $k], ['page']) }}"
               class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
               @if($isActive) aria-current="page" @endif>
                <span>{{ $lbl }}</span>
                @if($count !== null)
                    <span class="x-segment__count">{{ number_format($count) }}</span>
                @endif
            </a>
        @endforeach
    </div>

    @if(!empty($pending_courier_counts))
        <div class="sp-couriers">
            <span class="sp-couriers__label">Parcels waiting for handover</span>
            @foreach($pending_courier_counts as $courier => $count)
                <span class="sp-courier">
                    <span class="sp-courier__count">{{ number_format($count) }}</span>
                    {{ $courier }}
                </span>
            @endforeach
        </div>
    @endif
@endif

{{-- One filter row. Search, sort and per page were three separate card
     forms doing one job. The selects auto-submit through the shared
     data-autosubmit handler that /sales/orders already uses. --}}
<form method="GET" action="{{ $panelBaseUrl }}" class="x-filters" id="orders-filter">
@foreach($panelHiddenParams as $panelHiddenKey => $panelHiddenValue)
        <input type="hidden" name="{{ $panelHiddenKey }}" value="{{ $panelHiddenValue }}">
@endforeach
    <input type="hidden" name="tab" value="{{ $active_tab }}">
    @if($active_tab === 'PENDING')
        <input type="hidden" name="pending_sub" value="{{ $pendingSub }}">
    @endif

    <x-ui.input type="search" name="order_number" class="x-filters__search"
                value="{{ $orderNumberFilter }}" placeholder="Order number" aria-label="Search by order number" />
    <x-ui.input type="search" name="buyer_name" class="x-filters__search"
                value="{{ $buyerNameFilter }}" placeholder="Buyer" aria-label="Search by buyer name" />

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
            $spEmptyDesc = 'Nothing matches that order number or buyer. Clear the search to see the full list.';
        } elseif ($active_tab !== 'ALL') {
            // Keep the tab's own capitalisation: lowercasing it produced
            // "no to ship orders", which reads broken.
            $spEmptyDesc = 'No orders in ' . ($tabs[$active_tab] ?? $active_tab) . ' right now.';
        } else {
            $spEmptyDesc = 'Nothing has been synced yet. Pick a date range above and fetch orders from Shopee.';
        }
    @endphp
    <x-ui.empty title="No orders here" :description="$spEmptyDesc" />
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th class="sp-col-order">Order</th>
            <th>Items</th>
            <th class="sp-col-buyer">Buyer</th>
            <th class="sp-col-total x-td-num">Total</th>
            <th class="sp-col-status">Status</th>
            <th class="sp-col-act"><span class="x-sr">Actions</span></th>
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
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'name' => $gName,
                        'sku' => $gSku,
                        'variation' => $gVariation,
                        'quantity' => $gQty,
                        'image' => $gImg,
                    ];
                } else {
                    $grouped[$key]['quantity'] += $gQty;
                }
            }
            $groupedItems = array_values($grouped);

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

            $tone = $spStatusTones[$statusNorm] ?? 'neutral';
            $statusLabel = $spStatusLabels[$statusNorm]
                ?? ($statusStr !== '' ? ucfirst(strtolower(str_replace('_', ' ', $statusNorm))) : 'Unknown');
        @endphp
        <tr>
            <td class="sp-col-order" data-label="Order">
                @if($orderSn)
                    <a class="x-row-link sp-sn" href="{{ route('ext.shopee.orders.show', ['orderSn' => $orderSn]) }}">{{ $orderSn }}</a>
                @else
                    <span class="sp-sn sp-empty-mark">No order number</span>
                @endif
                @if($created)<span class="sp-date">{{ $created }}</span>@endif
                @if($o->catalog_order_id)
                    <a class="sp-ref" href="{{ url('/sales/orders/'.$o->catalog_order_id) }}">Sales order {{ $o->catalog_order_id }}</a>
                @endif
            </td>

            <td data-label="Items">
                <div class="sp-items">
                    @forelse($groupedItems as $it)
                        <div class="sp-item">
                            <div class="order-img-wrap">
                                @if(!empty($it['image']))
                                    <img class="order-img" src="{{ $it['image'] }}" alt="">
                                @else
                                    <span class="sp-item__none">No image</span>
                                @endif
                            </div>
                            <div class="sp-item__body">
                                <span class="sp-item__name">{{ $it['name'] !== '' ? $it['name'] : 'Unnamed product' }}</span>
                                <div class="sp-item__meta">
                                    @if(trim((string) $it['sku']) !== '')
                                        <span class="sp-item__sku">{{ $it['sku'] }}</span>
                                    @endif
                                    @if(trim((string) $it['variation']) !== '')
                                        <span class="sp-item__var">{{ $it['variation'] }}</span>
                                    @endif
                                </div>
                            </div>
                            <span class="sp-item__qty">&times;{{ $it['quantity'] }}</span>
                        </div>
                    @empty
                        <span class="sp-empty-mark">No items recorded</span>
                    @endforelse
                </div>
            </td>

            <td class="sp-col-buyer" data-label="Buyer">
                @if($buyer !== '')
                    <span class="sp-buyer">{{ $buyer }}</span>
                @else
                    <span class="sp-buyer sp-empty-mark">Not recorded</span>
                @endif
            </td>

            <td class="sp-col-total" data-label="Total">
                <span class="sp-total">
                    @if($totalAmount !== null && $totalAmount !== '' && is_numeric($totalAmount))
                        {{ $currencyCode !== ''
                            ? \App\Support\Money::foreign((float) $totalAmount, $currencyCode)
                            : \App\Support\Money::base((float) $totalAmount) }}
                    @elseif($totalAmount !== null && $totalAmount !== '')
                        {{ $totalAmount }}
                    @else
                        <span class="sp-empty-mark">Not recorded</span>
                    @endif
                </span>
                @if($paymentMethod)
                    <span class="sp-total__method">{{ $paymentMethod }}</span>
                @endif
            </td>

            <td class="sp-col-status" data-label="Status">
                <div class="sp-status">
                    <x-ui.badge :tone="$tone">{{ $statusLabel }}</x-ui.badge>
                    <x-sla-chip :deadline="$shipBy" />
                    @if($courier)
                        <span class="sp-ship">{{ $courier }}@if($trackingNo) <span class="sp-ship__no">{{ $trackingNo }}</span>@endif</span>
                    @endif
                </div>
            </td>

            <td class="sp-col-act">
                @if($orderSn)
                <div class="sp-actions">
                    @if($isToPack && $canManageShopeeOrders)
                        {{-- The one action the row's own status is waiting on
                             gets a control of its own. Everything else lives
                             behind the overflow menu, so a hundred-row page
                             carries a handful of controls rather than three
                             hundred. --}}
                        <button type="button" class="btnArrangeShipment x-btn x-btn--secondary x-btn--sm"
                                data-order-sn="{{ $orderSn }}">Arrange shipment</button>
                    @endif

                    <x-invoice-request-modal :invoice="$o->buyer_invoice" :reference="$orderSn" />

                    <x-ui.menu label="Order {{ $orderSn }} actions">
                        <a class="x-menu__item" href="{{ route('ext.shopee.orders.show', ['orderSn' => $orderSn]) }}">Open order</a>
                        @if(!$isToPack && !$isUnpaid)
                            <a class="x-menu__item" href="{{ route('ext.shopee.orders.awb', ['orderSn' => $orderSn]) }}"
                               target="_blank" rel="noopener">Print waybill</a>
                        @endif
                        @if($isToHandover || $isShipped)
                            <button type="button" class="btnShopeeTracking x-menu__item"
                                    data-url="{{ route('ext.shopee.orders.tracking_info', ['orderSn' => $orderSn]) }}">Tracking</button>
                        @endif
                    </x-ui.menu>
                </div>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>

<div class="x-pager">
    <span class="x-pager__count x-num">{{ number_format($orders->firstItem() ?? 0) }} to {{ number_format($orders->lastItem() ?? 0) }} of {{ number_format($orders->total()) }}</span>
    <div class="x-pager__nav">
        @if($orders->previousPageUrl())
            <x-ui.button size="sm" :href="$orders->previousPageUrl()"><x-ui.icon name="chevron-left" size="14" /> Previous</x-ui.button>
        @else
            <span class="x-btn x-btn--secondary x-btn--sm" aria-disabled="true"><x-ui.icon name="chevron-left" size="14" /> Previous</span>
        @endif
        @if($orders->nextPageUrl())
            <x-ui.button size="sm" :href="$orders->nextPageUrl()">Next <x-ui.icon name="chevron-right" size="14" /></x-ui.button>
        @else
            <span class="x-btn x-btn--secondary x-btn--sm" aria-disabled="true">Next <x-ui.icon name="chevron-right" size="14" /></span>
        @endif
    </div>
</div>
@endif

{{-- Ship method modal.
     Every id below is queried by resources/js/pages/shopee-orders.js and
     must not change. Step two starts hidden via the .sp-hidden class (same
     toggle the module uses for every step transition), not an inline style,
     so the initial state and every later state share one mechanism. --}}
<div id="spShipModal" class="modal-backdrop">
    <div class="modal sp-modal">
        <div class="modal-header sp-modal__head">
            <h3 class="sp-modal__title">Arrange shipment</h3>
            <button type="button" id="btnCloseShip" class="modal-close sp-modal__close" aria-label="Close">&times;</button>
        </div>
        <div class="sp-modal__meta">Order <strong id="spShipOrderSn"></strong></div>

        {{-- Step 1: Choose shipping type --}}
        <div id="shipStep1">
            <p class="sp-modal__prompt">How will this order be shipped?</p>
            <div class="sp-modal__choices">
                <button type="button" class="btnShipChoice x-btn x-btn--secondary" data-type="dropoff">
                    <x-ui.icon name="map-pin" size="14" />
                    Drop off
                </button>
                <button type="button" class="btnShipChoice x-btn x-btn--secondary" data-type="pickup">
                    <x-ui.icon name="truck" size="14" />
                    Pickup
                </button>
            </div>
        </div>

        {{-- Step 2: Address/branch selection --}}
        <div id="shipStep2" class="sp-hidden">
            <div id="shipStep2Loading" class="sp-modal__loading">
                <span class="sp-spinner" aria-hidden="true"></span>
                <span>Loading addresses</span>
            </div>
            <div id="shipStep2Content" class="sp-modal__body sp-hidden"></div>
            <div class="sp-modal__foot">
                <button type="button" id="btnShipBack" class="x-btn x-btn--ghost">Back</button>
                <button type="button" id="btnShipConfirm" class="x-btn x-btn--primary" disabled>Confirm and ship</button>
            </div>
        </div>

        <form method="POST" id="formShipOrder" class="sp-hidden">
            @csrf
            <input type="hidden" name="shipping_type" id="shipTypeInput" value="">
            <input type="hidden" name="address_id" id="shipAddressInput" value="">
            <input type="hidden" name="branch_id" id="shipBranchInput" value="">
        </form>
    </div>
</div>

{{-- Loading overlay --}}
<div id="spLoadingOverlay" class="modal-backdrop">
    <div class="modal sp-modal sp-modal--sm">
        <div class="sp-progress">
            <span class="sp-spinner sp-spinner--lg" aria-hidden="true"></span>
            <div>
                <div id="spLoadingTitle" class="sp-progress__title">Fetching orders</div>
                <div class="sp-progress__note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

{{-- Tracking modal --}}
<div id="spTrackingModal" class="modal-backdrop">
    <div class="modal sp-modal sp-modal--md">
        <div class="modal-header sp-modal__head">
            <h3 class="sp-modal__title">Tracking</h3>
            <button type="button" id="btnCloseTracking" class="modal-close sp-modal__close" aria-label="Close">&times;</button>
        </div>
        <div id="spTrackingBody" class="sp-modal__body">
            <div class="text-secondary">Loading</div>
        </div>
        <div class="sp-modal__foot sp-modal__foot--end">
            <button type="button" id="btnTrackingClose2" class="x-btn x-btn--secondary">Close</button>
        </div>
    </div>
</div>
