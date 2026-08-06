@extends('layouts.channel')
@section('breadcrumb', 'Orders')

{{-- Leaves the .x-legacy content boundary. This page is now built directly
     on the design tokens and the x-ui components, which is the designed
     exit path of that boundary: migrate a page by rebuilding it, then drop
     its wrapper. The old .marketplace-shopee wrapper went with it, because
     its only job was to repaint --accent with Shopee orange, and channel
     identity belongs in the sidebar and the breadcrumb, not on every
     control of every row. --}}
@section('content-class', '')

@section('content')
@php
    // Phase 4 - Shopee Orders is visible to view_shopee_orders holders; the
    // Fetch/Update/Ship/Reset actions require manage_shopee_orders.
    $canManageShopeeOrders = true;

    $tabs = $tabs ?? [];
    $active_tab = strtoupper((string) ($active_tab ?? 'ALL'));
    $tab_counts = $tab_counts ?? [];
    $pending_sub_counts = $pending_sub_counts ?? [];
    $perPage = (int) ($per_page ?? request()->query('per_page', 10));
    $orderNumberFilter = (string) ($filters['order_number'] ?? '');
    $buyerNameFilter = (string) ($filters['buyer_name'] ?? '');
    $hasSearch = $orderNumberFilter !== '' || $buyerNameFilter !== '';
    $pendingSub = $pending_subtab ?? request()->query('pending_sub', 'to_pack');

    // Status tone map. Colour has exactly one job on this page: telling the
    // operator which orders are waiting on them. Accent marks the single
    // status a human has to act on right now; everything already handled
    // recedes to neutral; the two failure states take danger; the two
    // waiting-on-someone-else states take warning and info.
    //
    // One deliberate reading of the spec's tone list: COMPLETED is mapped to
    // neutral rather than the success tone, because x-badge--success and the
    // accent tone resolve to the same token, which would have made "ready to
    // ship" and "completed" the identical colour and undone the point of the
    // map. A finished order is the one state that should recede.
    //
    // This array is the pattern to extract into a helper (alongside
    // App\Support\OrderStatusTone) when the second channel converts.
    $spStatusTones = [
        'UNPAID'             => 'warning',
        'READY_TO_SHIP'      => 'success',
        'PROCESSED'          => 'info',
        'SHIPPED'            => 'info',
        'TO_CONFIRM_RECEIVE' => 'info',
        'COMPLETED'          => 'neutral',
        'CANCELLED'          => 'danger',
        'IN_CANCEL'          => 'danger',
        'RETRY_SHIP'         => 'warning',
    ];

    $spStatusLabels = [
        'UNPAID'             => 'Unpaid',
        'READY_TO_SHIP'      => 'Ready to ship',
        'PROCESSED'          => 'To handover',
        'SHIPPED'            => 'Shipped',
        'TO_CONFIRM_RECEIVE' => 'Awaiting confirmation',
        'COMPLETED'          => 'Completed',
        'CANCELLED'          => 'Cancelled',
        'IN_CANCEL'          => 'Cancelling',
        'RETRY_SHIP'         => 'Delivery failed',
    ];

    $spSortLabels = [
        'created_desc'          => 'Order date, newest first',
        'created_asc'           => 'Order date, oldest first',
        'confirmed_pay_desc'    => 'Payment time, newest first',
        'confirmed_pay_asc'     => 'Payment time, oldest first',
        'confirmed_update_desc' => 'Last update, newest first',
        'confirmed_update_asc'  => 'Last update, oldest first',
        'confirmed_create_desc' => 'Shopee create time, newest first',
        'confirmed_create_asc'  => 'Shopee create time, oldest first',
    ];
@endphp

<div id="shopee-orders-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Orders</h1>
        <p class="x-page-sub">
            {{ number_format($orders->total()) }} {{ $orders->total() === 1 ? 'order' : 'orders' }}@if($active_tab !== 'ALL' && isset($tabs[$active_tab])) in {{ $tabs[$active_tab] }}@endif
        </p>
    </div>
</div>

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
                $qs = array_merge(request()->except(['page', 'pending_sub']), ['tab' => $key]);
            @endphp
            <a href="{{ route('ext.shopee.orders.index', $qs) }}"
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
                $qs = array_merge(request()->except('page'), ['tab' => 'PENDING', 'pending_sub' => $k]);
                $count = $pending_sub_counts[$k] ?? null;
            @endphp
            <a href="{{ route('ext.shopee.orders.index', $qs) }}"
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
<form method="GET" action="{{ route('ext.shopee.orders.index') }}" class="x-filters" id="orders-filter">
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
        <a class="x-filters__reset" href="{{ route('ext.shopee.orders.index', ['tab' => $active_tab, 'per_page' => $perPage]) }}">Reset</a>
    @endif
</form>

@if($orders->count() === 0)
    @php
        if ($hasSearch) {
            $spEmptyDesc = 'Nothing matches that order number or buyer. Clear the search to see the full list.';
        } elseif ($active_tab !== 'ALL') {
            $spEmptyDesc = 'There are no ' . strtolower((string) ($tabs[$active_tab] ?? $active_tab)) . ' orders right now.';
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
     Every id below is queried by the page script at the bottom of this file
     and must not change. Two inline display styles survive on purpose: the
     script reveals step two with .style.display = '', which falls back to
     the stylesheet, so #shipStep2 and #shipStep2Content must not be hidden
     from CSS. They are hidden here for the initial state only. --}}
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
        <div id="shipStep2" style="display:none;">
            <div id="shipStep2Loading" class="sp-modal__loading">
                <span class="sp-spinner" aria-hidden="true"></span>
                <span>Loading addresses</span>
            </div>
            <div id="shipStep2Content" class="sp-modal__body" style="display:none;"></div>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('spLoadingOverlay');
    var fetchForm = document.getElementById('formFetchShopeeOrders');

    var clickedBtn = null;
    var fetchBtn = document.getElementById('btnFetchOrders');
    var updateBtn = document.getElementById('btnUpdateOrders');

    if (fetchBtn) fetchBtn.addEventListener('click', function () { clickedBtn = 'fetch'; });
    if (updateBtn) updateBtn.addEventListener('click', function () { clickedBtn = 'update'; });

    if (fetchForm) {
        fetchForm.addEventListener('submit', function (e) {
            // Require From/To dates
            var dateFrom = fetchForm.querySelector('input[name="date_from"]');
            var dateTo = fetchForm.querySelector('input[name="date_to"]');
            if (!dateFrom.value || !dateTo.value) {
                e.preventDefault();
                showFlashError('Please provide both From and To dates.');
                return;
            }
            if (overlay) {
                document.getElementById('spLoadingTitle').textContent = (clickedBtn === 'update') ? 'Updating orders…' : 'Fetching orders…';
                overlay.style.display = 'flex';
            }
        });
    }

    var fetchReturnsForm = document.getElementById('formFetchReturns');
    if (fetchReturnsForm && overlay) {
        fetchReturnsForm.addEventListener('submit', function () {
            setTimeout(function () {
                overlay.style.display = 'flex';
                document.getElementById('spLoadingTitle').textContent = 'Fetching returns…';
            }, 50);
        });
    }

    // Ship method modal
    var shipModal = document.getElementById('spShipModal');
    var shipOrderSnEl = document.getElementById('spShipOrderSn');
    var shipForm = document.getElementById('formShipOrder');
    var shipTypeInput = document.getElementById('shipTypeInput');
    var shipAddressInput = document.getElementById('shipAddressInput');
    var shipBranchInput = document.getElementById('shipBranchInput');
    var shipStep1 = document.getElementById('shipStep1');
    var shipStep2 = document.getElementById('shipStep2');
    var shipStep2Loading = document.getElementById('shipStep2Loading');
    var shipStep2Content = document.getElementById('shipStep2Content');
    var btnShipConfirm = document.getElementById('btnShipConfirm');
    var btnShipBack = document.getElementById('btnShipBack');
    var currentShipOrderSn = '';
    var currentShipType = '';

    function shipResetModal() {
        shipStep1.style.display = '';
        shipStep2.style.display = 'none';
        shipStep2Loading.style.display = 'flex';
        shipStep2Content.style.display = 'none';
        shipStep2Content.innerHTML = '';
        shipAddressInput.value = '';
        shipBranchInput.value = '';
        btnShipConfirm.disabled = true;
        currentShipType = '';
    }

    document.querySelectorAll('.btnArrangeShipment').forEach(function (el) {
        el.addEventListener('click', function () {
            currentShipOrderSn = el.getAttribute('data-order-sn');
            shipOrderSnEl.textContent = currentShipOrderSn;
            shipForm.action = '/shopee/orders/' + encodeURIComponent(currentShipOrderSn) + '/ship';
            shipResetModal();
            shipModal.style.display = 'flex';
        });
    });

    document.querySelectorAll('.btnShipChoice').forEach(function (el) {
        el.addEventListener('click', function () {
            currentShipType = el.getAttribute('data-type');
            shipTypeInput.value = currentShipType;
            shipStep1.style.display = 'none';
            shipStep2.style.display = '';
            shipStep2Loading.style.display = 'flex';
            shipStep2Content.style.display = 'none';

            fetch('/shopee/orders/' + encodeURIComponent(currentShipOrderSn) + '/shipping-addresses', {
                headers: { 'Accept': 'application/json' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                shipStep2Loading.style.display = 'none';
                shipStep2Content.style.display = '';

                if (!data.ok) {
                    shipStep2Content.innerHTML = '<div class="alert danger">' + (data.message || 'Failed to load addresses.') + '</div>';
                    return;
                }

                var html = '';
                if (currentShipType === 'pickup') {
                    var addresses = (data.pickup && data.pickup.address_list) ? data.pickup.address_list : [];
                    if (addresses.length === 0) {
                        shipStep2Content.innerHTML = '<div class="text-secondary">No pickup addresses available.</div>';
                        return;
                    }

                    // Auto-select: find the address flagged as pickup_address
                    var autoAddr = null;
                    addresses.forEach(function (addr) {
                        if (!autoAddr && (addr.address_flag || []).indexOf('pickup_address') !== -1) {
                            autoAddr = addr;
                        }
                    });
                    // Fallback to first address if none flagged
                    if (!autoAddr) autoAddr = addresses[0];

                    shipAddressInput.value = autoAddr.address_id || 0;
                    var addrParts = [autoAddr.address, autoAddr.city, autoAddr.state, autoAddr.district].filter(function(p) { return p && p !== ''; });
                    var flags = (autoAddr.address_flag || []);

                    html += '<div class="text-sm font-bold" style="margin-bottom:8px;">Pickup address (auto-selected):</div>';
                    html += '<div style="padding:10px 12px; border:2px solid #16a34a; border-radius:8px; background:#16a34a0a;">';
                    html += '<div class="text-sm">' + addrParts.join(', ') + '</div>';
                    if (flags.length > 0) {
                        html += '<div style="margin-top:4px;">';
                        flags.forEach(function (f) {
                            var color = f === 'pickup_address' ? '#16a34a' : (f === 'return_address' ? '#2563eb' : '#6b7280');
                            html += '<span style="display:inline-block; font-size:11px; padding:1px 6px; border-radius:4px; background:' + color + '18; color:' + color + '; margin-right:4px;">' + f.replace(/_/g, ' ') + '</span>';
                        });
                        html += '</div>';
                    }
                    html += '</div>';
                } else {
                    var branches = (data.dropoff && data.dropoff.branch_list) ? data.dropoff.branch_list : [];
                    if (branches.length === 0) {
                        shipStep2Content.innerHTML = '<div class="text-secondary">No drop-off branches available. The order will be shipped without branch selection.</div>';
                        btnShipConfirm.disabled = false;
                        return;
                    }
                    html += '<div class="text-sm font-bold" style="margin-bottom:8px;">Select drop-off branch:</div>';
                    branches.forEach(function (branch, i) {
                        var checked = i === 0 ? ' checked' : '';
                        var addrParts = [branch.address, branch.city, branch.state, branch.district].filter(function(p) { return p && p !== ''; });

                        html += '<label style="display:block; padding:10px 12px; border:2px solid var(--border); border-radius:8px; cursor:pointer; margin-bottom:8px;" class="ship-addr-label">';
                        html += '<div class="d-flex items-start gap-10">';
                        html += '<input type="radio" name="ship_branch" value="' + (branch.branch_id || 0) + '"' + checked + ' style="margin-top:3px;">';
                        html += '<div style="flex:1;">';
                        html += '<div class="text-sm">' + addrParts.join(', ') + '</div>';
                        html += '</div></div></label>';

                        if (i === 0) shipBranchInput.value = branch.branch_id || 0;
                    });
                }

                shipStep2Content.innerHTML = html;
                btnShipConfirm.disabled = false;

                // Listen for radio changes
                shipStep2Content.querySelectorAll('input[type=radio]').forEach(function (radio) {
                    radio.addEventListener('change', function () {
                        if (currentShipType === 'pickup') {
                            shipAddressInput.value = radio.value;
                        } else {
                            shipBranchInput.value = radio.value;
                        }
                    });
                });
            })
            .catch(function () {
                shipStep2Loading.style.display = 'none';
                shipStep2Content.style.display = '';
                shipStep2Content.innerHTML = '<div class="alert danger">Network error loading addresses.</div>';
            });
        });
    });

    btnShipBack.addEventListener('click', function () {
        shipStep1.style.display = '';
        shipStep2.style.display = 'none';
    });

    btnShipConfirm.addEventListener('click', function () {
        btnShipConfirm.disabled = true;
        shipModal.style.display = 'none';

        if (overlay) {
            overlay.style.display = 'flex';
            document.getElementById('spLoadingTitle').textContent = 'Shipping order…';
        }

        var csrfToken = document.querySelector('meta[name="csrf-token"]');
        if (!csrfToken) csrfToken = shipForm.querySelector('input[name="_token"]');
        var token = csrfToken ? (csrfToken.content || csrfToken.value) : '';

        var formData = new FormData(shipForm);

        fetch(shipForm.action, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
            body: formData
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok) {
                if (overlay) overlay.style.display = 'none';
                showFlashError('Ship failed: ' + (data.message || 'Unknown error'));
                btnShipConfirm.disabled = false;
                return;
            }

            // Ship succeeded — now poll for AWB
            if (overlay) {
                document.getElementById('spLoadingTitle').textContent = 'Generating AWB…';
            }

            var awbUrl = '/shopee/orders/' + encodeURIComponent(currentShipOrderSn) + '/awb';
            var awbAttempts = 0;
            var maxAttempts = 20;

            function pollAwb() {
                awbAttempts++;
                fetch(awbUrl, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (awbData) {
                    if (awbData.ok && awbData.ready) {
                        // AWB ready — open in new tab and reload page
                        if (overlay) overlay.style.display = 'none';
                        window.open(awbUrl, '_blank');
                        location.reload();
                    } else if (awbAttempts < maxAttempts) {
                        setTimeout(pollAwb, 3000);
                    } else {
                        // Timed out — let user know and reload
                        if (overlay) overlay.style.display = 'none';
                        showFlashError('Order shipped successfully but AWB is still being generated by Shopee. Use the Print AWB button to try again.');
                        location.reload();
                    }
                })
                .catch(function () {
                    if (awbAttempts < maxAttempts) {
                        setTimeout(pollAwb, 3000);
                    } else {
                        if (overlay) overlay.style.display = 'none';
                        showFlashError('Order shipped successfully but could not generate AWB. Use the Print AWB button to try again.');
                        location.reload();
                    }
                });
            }

            // Start polling after a short initial delay
            setTimeout(pollAwb, 2000);
        })
        .catch(function () {
            if (overlay) overlay.style.display = 'none';
            showFlashError('Network error while shipping order.');
            btnShipConfirm.disabled = false;
        });
    });

    var closeShip = function () { if (shipModal) shipModal.style.display = 'none'; };
    var btnCloseShip = document.getElementById('btnCloseShip');
    if (btnCloseShip) btnCloseShip.addEventListener('click', closeShip);
    if (shipModal) shipModal.addEventListener('click', function (e) { if (e.target === shipModal) closeShip(); });

    // Tracking modal
    var trackingModal = document.getElementById('spTrackingModal');
    var trackingBody = document.getElementById('spTrackingBody');

    var closeTracking = function () { trackingModal.classList.remove('active'); };
    var btnCloseTracking = document.getElementById('btnCloseTracking');
    var btnTrackingClose2 = document.getElementById('btnTrackingClose2');
    if (btnCloseTracking) btnCloseTracking.addEventListener('click', closeTracking);
    if (btnTrackingClose2) btnTrackingClose2.addEventListener('click', closeTracking);
    if (trackingModal) trackingModal.addEventListener('click', function (e) { if (e.target === trackingModal) closeTracking(); });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btnShopeeTracking');
        if (!btn) return;
        var url = btn.getAttribute('data-url');
        if (!url) return;
        trackingBody.textContent = 'Loading…';
        trackingModal.classList.add('active');

        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                trackingBody.textContent = '';
                if (!data.ok) {
                    var err = document.createElement('div');
                    err.className = 'text-danger';
                    err.textContent = data.message || 'Error loading tracking info.';
                    trackingBody.appendChild(err);
                    return;
                }

                if (data.tracking_number) {
                    var tnDiv = document.createElement('div');
                    tnDiv.className = 'mb-8';
                    var tnLabel = document.createElement('strong');
                    tnLabel.textContent = 'Tracking: ';
                    var tnValue = document.createElement('span');
                    tnValue.textContent = data.tracking_number;
                    tnDiv.appendChild(tnLabel);
                    tnDiv.appendChild(tnValue);
                    trackingBody.appendChild(tnDiv);
                }
                if (data.shipping_carrier) {
                    var scDiv = document.createElement('div');
                    scDiv.className = 'mb-8';
                    var scLabel = document.createElement('strong');
                    scLabel.textContent = 'Courier: ';
                    var scValue = document.createElement('span');
                    scValue.textContent = data.shipping_carrier;
                    scDiv.appendChild(scLabel);
                    scDiv.appendChild(scValue);
                    trackingBody.appendChild(scDiv);
                }

                var events = data.tracking_info || [];
                if (!Array.isArray(events) || events.length === 0) {
                    if (!data.tracking_number) {
                        var empty = document.createElement('div');
                        empty.className = 'text-secondary';
                        empty.textContent = 'No tracking info available yet.';
                        trackingBody.appendChild(empty);
                    }
                    return;
                }

                events.forEach(function (ev) {
                    var row = document.createElement('div');
                    row.style.cssText = 'margin-bottom:10px; padding-left:12px; border-left:2px solid var(--accent);';
                    var time = document.createElement('div');
                    time.className = 'text-xs text-secondary';
                    var ts = ev.update_time || null;
                    if (ts && typeof ts === 'number') {
                        var dt = new Date(ts > 1e12 ? ts : ts * 1000);
                        time.textContent = dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0') + '-' + String(dt.getDate()).padStart(2,'0') + ' ' + String(dt.getHours()).padStart(2,'0') + ':' + String(dt.getMinutes()).padStart(2,'0');
                    }
                    var desc = document.createElement('div');
                    desc.className = 'text-sm';
                    desc.textContent = ev.description || '';
                    row.appendChild(time);
                    row.appendChild(desc);
                    trackingBody.appendChild(row);
                });
            })
            .catch(function (err) {
                trackingBody.textContent = '';
                var errEl = document.createElement('div');
                errEl.className = 'text-danger';
                errEl.textContent = 'Failed to load tracking info.';
                trackingBody.appendChild(errEl);
            });
    });
});
</script>
<script>
(function () {
    var preview = document.createElement('div');
    preview.className = 'order-img-preview';
    preview.innerHTML = '<img src="" alt="">';
    document.body.appendChild(preview);
    var previewImg = preview.querySelector('img');
    document.addEventListener('mouseover', function (e) {
        var wrap = e.target.closest('.order-img-wrap');
        if (!wrap) return;
        var img = wrap.querySelector('img.order-img');
        if (!img || !img.src) return;
        previewImg.src = img.src;
        var rect = wrap.getBoundingClientRect();
        var top = rect.top + rect.height / 2 - 110;
        var left = rect.right + 12;
        if (left + 230 > window.innerWidth) left = rect.left - 232;
        if (top + 220 > window.innerHeight) top = window.innerHeight - 224;
        if (top < 4) top = 4;
        preview.style.transformOrigin = (left < rect.left ? 'right' : 'left') + ' center';
        preview.style.top = top + 'px';
        preview.style.left = left + 'px';
        preview.classList.add('visible');
    }, false);

    document.addEventListener('mouseout', function (e) {
        var wrap = e.target.closest('.order-img-wrap');
        if (!wrap) return;
        var related = e.relatedTarget;
        if (related && wrap.contains(related)) return;
        preview.classList.remove('visible');
    }, false);
})();
</script>
</div>{{-- /#shopee-orders-page --}}
@endsection
