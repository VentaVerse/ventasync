@extends('layouts.channel')
@section('breadcrumb', 'Orders')

{{-- Leaves the .x-legacy content boundary, the same exit Shopee's and
     Lazada's Orders pages took: this page is now built directly on the
     design tokens and the x-ui components, which is the designed way out of
     that boundary. The old .marketplace-tiktok wrapper went with it, because
     its only job was to repaint --accent with TikTok's pink, and channel
     identity belongs in the sidebar and the breadcrumb, not on every control
     of every row. The cross-marketplace tab strip went too: inside a seller
     center you see one marketplace. --}}
@section('content-class', '')

@section('content')
@php
    // Orders is visible to view_tiktok_orders holders; Fetch, Update and
    // Ship render only for the manage tier. The route middleware
    // (perm:manage_tiktok_orders on every write POST in
    // extensions/tiktok/routes/web.php) is the real gate; this flag only
    // stops the page offering buttons that would 403.
    //
    // The old page hardcoded this to true, the same defect Lazada's page
    // carried, which showed a read-only operator a command bar and a Ship
    // button that all 403 on submit. Reading the permission is what the
    // flag was written for.
    $canManageTiktokOrders = auth()->user()?->hasPermission('manage_tiktok_orders') ?? false;

    $tabs = $tabs ?? [];
    $active_tab = strtoupper((string) ($active_tab ?? 'ALL'));
    $pendingSub = $pending_sub ?? request()->query('pending_sub', 'to_pack');

    $pendingNav = [
        'to_pack' => 'To Pack',
        'to_handover' => 'To Handover',
    ];

    // The count in the deck is the count of what is actually listed, so it
    // names the step being listed rather than the tab above it: standing on
    // To Handover while the deck says "in To Ship" puts one number next to a
    // different one and reads as a bug.
    $scopeLabel = null;
    if ($active_tab === 'TO_SHIP') {
        $scopeLabel = $pendingNav[$pendingSub] ?? ($tabs['TO_SHIP'] ?? null);
    } elseif ($active_tab !== 'ALL') {
        $scopeLabel = $tabs[$active_tab] ?? null;
    }
@endphp

{{-- The one session value the page module needs (a waybill produced by the
     ship request that just finished) rides on the root element as a data
     attribute, escaped by Blade, rather than as a <script> block calling
     window.open with an interpolated URL. A new-UX page owns no inline
     script, the same way it owns no inline style. --}}
<div id="tiktok-orders-page" class="co-page"
     data-awb-url="{{ $last_result['awb_url'] ?? '' }}">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Orders</h1>
        <p class="x-page-sub">
            {{ number_format($orders->total()) }} {{ $orders->total() === 1 ? 'order' : 'orders' }}@if($scopeLabel) in {{ $scopeLabel }}@endif
        </p>
    </div>
</div>

@if($canManageTiktokOrders)
{{-- One command bar, the same grammar as Shopee's and Lazada's: every child
     of the row ends in a box exactly --control-h tall and the row is bottom
     aligned, so the date inputs and the two buttons share a baseline and a
     top edge. The three stacked cards this replaces each set their own
     control heights, which is what left the buttons off the baseline of
     their own date inputs. --}}
<div class="x-cmdbar">
    <form method="POST" action="{{ route('ext.tiktok.orders.fetch') }}" id="formFetchTiktokOrders" class="x-cmdbar__row">
        @csrf
        <div class="x-cmdbar__field">
            <label class="x-cmdbar__label" for="ttDateFrom">From</label>
            <x-ui.input type="date" id="ttDateFrom" name="date_from" class="x-cmdbar__date"
                        value="{{ old('date_from', request('date_from', now()->subDays(15)->format('Y-m-d'))) }}" />
        </div>
        <div class="x-cmdbar__field">
            <label class="x-cmdbar__label" for="ttDateTo">To</label>
            <x-ui.input type="date" id="ttDateTo" name="date_to" class="x-cmdbar__date"
                        value="{{ old('date_to', request('date_to', now()->format('Y-m-d'))) }}" />
        </div>
        <div class="x-cmdbar__spacer"></div>
        <div class="x-cmdbar__actions">
            <x-ui.button type="submit" variant="primary" id="btnFetchOrders">Fetch orders</x-ui.button>
            <x-ui.button type="submit" variant="secondary" id="btnUpdateOrders"
                         formaction="{{ route('ext.tiktok.orders.updateStatuses') }}">Update statuses</x-ui.button>
        </div>
    </form>
    <p class="x-cmdbar__hint">Fetching pulls the orders created between those two dates. Updating statuses refreshes the last 30 days and ignores the dates.</p>
</div>
@endif

@if($last_result)
    <div class="co-notice {{ $last_result['ok'] ? 'co-notice--ok' : 'co-notice--fail' }}">
        <span class="co-notice__title">{{ $last_result['ok'] ? 'Done' : 'Failed' }}</span>
        @if(!empty($last_result['message']))
            <span class="co-notice__body">{{ $last_result['message'] }}</span>
        @endif
        @if(!empty($last_result['awb_url']))
            {{-- The page module opens this waybill once on load. The link is
                 here as well because a popup blocker, or a second look at the
                 same notice, should not cost the operator the label they just
                 created. --}}
            <x-ui.button size="sm" :href="$last_result['awb_url']" target="_blank" rel="noopener">Print waybill</x-ui.button>
        @endif
    </div>
@endif

{{-- Status filters as a segmented control. Seven views over one list, so
     "pick one" is the right grammar; the old .tabs strip implied seven
     pages. Counts ride along as muted mono numbers on every tab, not only on
     the two the old strip decorated with a black pill: a count that appears
     on some tabs and not others reads as an alert rather than a figure. --}}
<div class="x-segment-bar">
    <div class="x-segment" role="tablist" aria-label="Order status">
        @foreach($tabs as $key => $label)
            @php
                $isActive = $active_tab === strtoupper((string) $key);
                $count = $tab_counts[$key] ?? null;
            @endphp
            <a href="{{ route('ext.tiktok.orders.index', array_merge(request()->except('page', 'pending_sub'), ['tab' => $key])) }}"
               class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
               @if($isActive) aria-current="page" @endif>
                <span>{{ $label }}</span>
                @if($count !== null)
                    <span class="x-segment__count">{{ number_format($count) }}</span>
                @endif
            </a>
        @endforeach
    </div>
</div>

@if($active_tab === 'TO_SHIP')
    {{-- TikTok's To Ship has two working steps. The sub row drops the trough
         so it cannot be mistaken for a peer of the row above it, and the "To
         Ship workflow / Choose the step you are working on" card that used to
         frame it is gone: a segmented control under a tab needs no caption
         explaining that it filters. --}}
    <div class="x-segment x-segment--sub" aria-label="To Ship step">
        @foreach($pendingNav as $k => $lbl)
            @php
                $isActive = ($pendingSub === $k) || ($pendingSub === '' && $k === 'to_pack');
                $count = $tab_counts[$k] ?? null;
            @endphp
            <a href="{{ route('ext.tiktok.orders.index', array_merge(request()->except('page'), ['tab' => 'TO_SHIP', 'pending_sub' => $k])) }}"
               class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
               @if($isActive) aria-current="page" @endif>
                <span>{{ $lbl }}</span>
                @if($count !== null)
                    <span class="x-segment__count">{{ number_format($count) }}</span>
                @endif
            </a>
        @endforeach
    </div>

    @if(!empty($courier_counts))
        <div class="co-couriers">
            <span class="co-couriers__label">Parcels waiting for handover</span>
            @foreach($courier_counts as $cc)
                <span class="co-courier">
                    <span class="co-courier__count">{{ number_format($cc['count']) }}</span>
                    {{ $cc['label'] }}
                </span>
            @endforeach
        </div>
    @endif
@endif

{{-- One filter row. Search, sort and per page were three separate card forms
     doing one job, one of them a form whose only control was a select. The
     selects auto-submit through the shared data-autosubmit handler that
     /sales/orders already uses. --}}
<form method="GET" action="{{ route('ext.tiktok.orders.index') }}" class="x-filters" id="orders-filter">
    <input type="hidden" name="tab" value="{{ $active_tab }}">
    @if($active_tab === 'TO_SHIP')
        <input type="hidden" name="pending_sub" value="{{ $pendingSub }}">
    @endif

    <x-ui.input type="search" name="order_number" class="x-filters__search"
                value="{{ $filters['order_number'] ?? '' }}" placeholder="Order number" aria-label="Search by order number" />
    <x-ui.input type="search" name="buyer_name" class="x-filters__search"
                value="{{ $filters['buyer_name'] ?? '' }}" placeholder="Buyer" aria-label="Search by buyer name" />

    <div class="x-select-wrap x-filters__select x-filters__select--wide">
        <select name="sort" class="x-input" data-autosubmit aria-label="Sort orders">
            @foreach(['created_desc' => 'Order date, newest first', 'created_asc' => 'Order date, oldest first'] as $value => $label)
                <option value="{{ $value }}" @selected(($sort ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="per_page" class="x-input" data-autosubmit aria-label="Orders per page">
            @foreach([10, 20, 50] as $n)
                <option value="{{ $n }}" @selected((int) ($per_page ?? 10) === $n)>{{ $n }} per page</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>

    @if(($filters['order_number'] ?? '') !== '' || ($filters['buyer_name'] ?? '') !== '')
        <a class="x-filters__reset" href="{{ route('ext.tiktok.orders.index', ['tab' => $active_tab, 'per_page' => (int) ($per_page ?? 10)]) }}">Reset</a>
    @endif
</form>

@if($orders->count() === 0)
    @php
        if (($filters['order_number'] ?? '') !== '' || ($filters['buyer_name'] ?? '') !== '') {
            $ttEmptyDesc = 'Nothing matches that order number or buyer. Clear the search to see the full list.';
        } elseif ($active_tab !== 'ALL') {
            // Keep the tab's own capitalisation: lowercasing it produced
            // "no to ship orders", which reads broken.
            $ttEmptyDesc = 'No orders in ' . ($scopeLabel ?? $active_tab) . ' right now.';
        } else {
            // The empty state names the way forward the reader actually has.
            // Telling a read-only operator to use a command bar their
            // permission tier never renders is an instruction they cannot
            // follow.
            $ttEmptyDesc = $canManageTiktokOrders
                ? 'Nothing has been synced yet. Pick a date range above and fetch orders from TikTok Shop.'
                : 'Nothing has been synced yet. Someone with permission to sync orders needs to pull them from TikTok Shop first.';
        }
    @endphp
    <x-ui.empty title="No orders here" :description="$ttEmptyDesc" />
@else
<x-ui.table>
    <x-slot:head>
        {{-- The legacy column format, the same one Shopee and Lazada settled
             on: one row per item so Qty and Price align in their own columns,
             and the order-level facts (amount, shipping, status, actions)
             rowspan beside the item rows. The buyer, order number and create
             time ride in a band above each order, exactly where the legacy
             page carried them. --}}
        <tr>
            <th class="tt-col-products">Products</th>
            <th class="tt-col-qty">Qty</th>
            <th class="tt-col-price x-td-num">Price</th>
            <th class="tt-col-total x-td-num">Amount</th>
            <th class="tt-col-ship">Shipping</th>
            <th class="tt-col-status">Status</th>
            <th class="tt-col-act"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($orders as $o)
        @php
            $raw = is_array($o->raw) ? $o->raw : [];
            $orderId = (string) ($o->order_id ?? '');
            $status = (string) ($o->status ?? '');

            $created = $o->order_created_at ? $o->order_created_at->format('Y-m-d H:i') : null;
            $buyer = (string) ($o->buyer_name ?? '');

            // Consolidate duplicate line items by SKU + name + variation and
            // sum their quantities: TikTok emits one line item per unit on
            // some payloads, which listed the same product five times.
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

            // Legacy shape: an order with no recorded items still renders one
            // empty item row under its band, rather than a band with nothing
            // attached to it.
            $displayItems = $groupedItems !== [] ? $groupedItems : [[]];
            $lineCount = count($displayItems);
            $itemCount = count($groupedItems);

            $payment = (isset($raw['payment']) && is_array($raw['payment'])) ? $raw['payment'] : [];
            $totalAmount = $payment['total_amount'] ?? $payment['product_total_amount'] ?? null;

            // Currency is resolved through App\Support\Money, which reads the
            // symbol from the currencies table, so no symbol is ever written
            // into a view. The old page printed TikTok's currency CODE where
            // a symbol belongs, so a peso order read "PHP1,234.00"; an order
            // carrying a code nobody has configured locally now falls back to
            // "CODE 1,234.00" instead.
            $currencyCode = (string) ($payment['currency'] ?? '');

            // Shipping. Order-level raw first, then the first line item.
            $courier = (string) ($raw['shipping_provider'] ?? '');
            $trackingNo = (string) ($raw['tracking_number'] ?? '');
            if ($courier === '' && !empty($raw['line_items'])) {
                $firstLi = is_array($raw['line_items'][0] ?? null) ? $raw['line_items'][0] : [];
                $courier = (string) ($firstLi['shipping_provider_name'] ?? '');
                if ($trackingNo === '') {
                    $trackingNo = (string) ($firstLi['tracking_number'] ?? '');
                }
            }

            // The action matrix, one flag per branch. Ship is the only
            // marketplace write on this page, so it is the only branch gated
            // on the manage tier; the waybill and the tracking lookup sit in
            // the read tier of extensions/tiktok/routes/web.php and are
            // offered to a viewer too.
            $isToPack = ($status === 'AWAITING_SHIPMENT');
            $isToHandover = ($status === 'AWAITING_COLLECTION');
            $isShipped = in_array($status, ['IN_TRANSIT', 'DELIVERED', 'COMPLETED'], true);
            $showsWaybill = $isToHandover || $isShipped;
            $hasActions = ($isToPack && $canManageTiktokOrders) || $showsWaybill;

            // tts_sla_time is TikTok's ship-by deadline, a Unix timestamp,
            // and only means anything before the parcel leaves.
            $awaitingShip = $isToPack || $isToHandover;
            $shipBy = $awaitingShip ? (int) ($raw['tts_sla_time'] ?? 0) : 0;

            $tone = \App\Support\ChannelStatusTone::toneFor('tiktok.orders', $status);
            $statusLabel = \App\Support\ChannelStatusTone::labelFor('tiktok.orders', $status);
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
                        @if($orderId !== '')
                            {{-- New tab, matching the legacy page: same reasoning as the Shopee
                                 and Lazada panels, drop when the detail pages convert (board card #5). --}}
                            <a class="co-sn" href="{{ route('ext.tiktok.orders.show', $o->id) }}" target="_blank" rel="noopener">{{ $orderId }}</a>
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
                        @if(!empty($it['image']))
                            <img class="order-img" src="{{ $it['image'] }}" alt="" loading="lazy">
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

            <td class="tt-col-qty" data-label="Qty">
                <span class="co-qty">&times;{{ max(1, (int) ($it['quantity'] ?? 1)) }}</span>
            </td>

            <td class="tt-col-price x-td-num" data-label="Price">
                @php
                    // Sale price is what the buyer paid, so it leads. The
                    // fetch writes 0.0 rather than null when TikTok sends no
                    // sale price, which made the old ?? chain never reach
                    // item_price and printed a dash over a real figure.
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
                    <span class="co-empty-mark">&mdash;</span>
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
                    <span class="co-empty-mark">&mdash;</span>
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
                    {{-- Unpaid and cancelled orders have nothing a seller can
                         do to them, and an order still waiting to be packed
                         offers a viewer nothing either, which is why the
                         legacy page printed a dash here rather than a
                         control. --}}
                    <span class="co-empty-mark">&mdash;</span>
                @else
                <div class="co-actions co-actions--wrap">
                    {{-- Every action a row's status allows is a visible
                         button, matching the legacy page: this is the packing
                         desk's working surface, and an overflow menu made the
                         column read as empty. Every one of them is a
                         secondary button, so a screen of forty rows has no
                         wall of colour in it; the accent that says "your
                         move" is spent once per row, on the status badge. --}}
                    @if($isToPack && $canManageTiktokOrders)
                        <form method="POST" action="{{ route('ext.tiktok.orders.ship', $o->id) }}" data-confirm="Ship this order?">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" size="sm">Ship order</x-ui.button>
                        </form>
                    @endif

                    @if($showsWaybill)
                        <x-ui.button size="sm"
                                     :href="route('ext.tiktok.orders.awb', $o->id)"
                                     target="_blank" rel="noopener">Print waybill</x-ui.button>

                        @if($isToHandover)
                            {{-- The waybill is cached locally after its first
                                 download. Handover is the step where a label
                                 gets reprinted, so that is the one step that
                                 offers a fresh copy from TikTok. The old page
                                 spent a bare arrow glyph on this, which said
                                 nothing without hovering it. --}}
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

{{-- Loading overlay. Every id below is queried by
     resources/js/pages/tiktok-orders.js and must not change. --}}
<div id="ttLoadingOverlay" class="modal-backdrop">
    <div class="modal co-modal co-modal--sm">
        <div class="co-progress">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div id="ttLoadingTitle" class="co-progress__title">Fetching orders</div>
                <div class="co-progress__note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

{{-- Tracking modal, filled by the page module from the tracking endpoint. --}}
<div id="ttTrackingModal" class="modal-backdrop">
    <div class="modal co-modal co-modal--md">
        <div class="modal-header co-modal__head">
            <h3 class="co-modal__title">Tracking</h3>
            <button type="button" id="btnCloseTtTracking" class="modal-close co-modal__close" aria-label="Close">&times;</button>
        </div>
        <div id="ttTrackingSub" class="co-modal__meta"></div>
        <div id="ttTrackingBody" class="co-modal__body">
            <div class="text-secondary">Loading</div>
        </div>
        <div class="co-modal__foot co-modal__foot--end">
            <button type="button" id="btnCloseTtTracking2" class="x-btn x-btn--secondary">Close</button>
        </div>
    </div>
</div>

</div>{{-- /#tiktok-orders-page --}}
@endsection
