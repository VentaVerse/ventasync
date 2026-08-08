@extends('layouts.channel')
@section('breadcrumb', 'Return or Refund')

{{-- Same exit from .x-legacy that the Orders page took in Task 1-2: this
     page is rebuilt directly on the token set and the x-ui components, so
     it drops the legacy content boundary rather than living inside it. --}}
@section('content')
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
    $returnSnFilter = (string) ($filters['return_sn'] ?? '');
    $orderSnFilter = (string) ($filters['order_sn'] ?? '');
    $hasSearch = $returnSnFilter !== '' || $orderSnFilter !== '';

    // The return status tone and label maps that used to sit here now live
    // in App\Support\ChannelStatusTone under the 'shopee.returns' surface.
    // The reasoning travelled with them: this page has no mutating row
    // action, so no status takes the accent tone; warning marks the states
    // awaiting an outcome, info the states sitting with Shopee's own
    // review, neutral a settled amount, danger the ways a return ends
    // without a payout.
@endphp

<div id="shopee-returns-page" class="co-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Returns</h1>
        <p class="x-page-sub">
            {{ number_format($returns->total()) }} {{ $returns->total() === 1 ? 'return' : 'returns' }}@if($active_tab !== 'ALL' && isset($tabs[$active_tab])) in {{ $tabs[$active_tab] }}@endif
        </p>
    </div>
</div>

@if($canManageShopeeOrders)
{{-- One command bar, same grammar as the Orders page's fetch/update bar:
     every control ends on one baseline because every control is
     --control-h tall. There are no date inputs here - Shopee's
     get_return_list endpoint takes no date range, it always walks its full
     history, so a From/To pair would promise filtering the fetch does not
     do. Per D3 (no backend changes), the fix is not to invent a param the
     controller would ignore; it is to say plainly what the button does. --}}
<div class="x-cmdbar">
    <form method="POST" action="{{ route('ext.shopee.orders.fetch_returns') }}" id="formFetchReturns" class="x-cmdbar__row">
        @csrf
        <div class="x-cmdbar__spacer"></div>
        <div class="x-cmdbar__actions">
            <x-ui.button type="submit" variant="primary" id="btnFetchReturns">Fetch returns</x-ui.button>
        </div>
    </form>
    <p class="x-cmdbar__hint">Pulls every return and refund request Shopee has on file. This can take a moment on a large history.</p>
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
    <div class="x-segment" role="tablist" aria-label="Return status">
        @foreach($tabs as $key => $label)
            @php
                $isActive = $active_tab === strtoupper((string) $key);
                $count = $tab_counts[$key] ?? null;
                $qs = array_merge(request()->except('page'), ['tab' => $key]);
            @endphp
            <a href="{{ route('ext.shopee.orders.returns', $qs) }}"
               class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
               @if($isActive) aria-current="page" @endif>
                <span>{{ $label }}</span>
                @if($count !== null)
                    <span class="x-segment__count">{{ number_format($count) }}</span>
                @endif
            </a>
        @endforeach
    </div>
    <a class="x-segment-bar__aside" href="{{ route('ext.shopee.orders.index') }}">
        <x-ui.icon name="chevron-left" size="12" />
        All orders
    </a>
</div>

{{-- One filter row, replacing the old search card and the old separate
     per-page form. --}}
<form method="GET" action="{{ route('ext.shopee.orders.returns') }}" class="x-filters" id="orders-filter">
    <input type="hidden" name="tab" value="{{ $active_tab }}">

    <x-ui.input type="search" name="return_sn" class="x-filters__search"
                value="{{ $returnSnFilter }}" placeholder="Return number" aria-label="Search by return number" />
    <x-ui.input type="search" name="order_sn" class="x-filters__search"
                value="{{ $orderSnFilter }}" placeholder="Order number" aria-label="Search by order number" />

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
        <a class="x-filters__reset" href="{{ route('ext.shopee.orders.returns', ['tab' => $active_tab, 'per_page' => $perPage]) }}">Reset</a>
    @endif
</form>

@if($returns->count() === 0)
    @php
        if ($hasSearch) {
            $rtEmptyDesc = 'Nothing matches that return or order number. Clear the search to see the full list.';
        } elseif ($active_tab !== 'ALL') {
            // Keep the tab's own capitalisation, same fix as the orders
            // page: lowercasing produced unreadable copy.
            $rtEmptyDesc = 'No returns in ' . ($tabs[$active_tab] ?? $active_tab) . ' right now.';
        } else {
            $rtEmptyDesc = 'Nothing has been synced yet. Click Fetch returns to pull data from Shopee.';
        }
    @endphp
    <x-ui.empty title="No returns here" :description="$rtEmptyDesc" />
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th class="sp-col-order">Return</th>
            <th>Items</th>
            <th class="sp-col-buyer">Buyer</th>
            <th class="sp-col-total x-td-num">Refund</th>
            <th class="sp-col-status">Status</th>
            <th class="sp-col-act"><span class="x-sr">Actions</span></th>
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

            <td class="sp-col-total" data-label="Refund">
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
                    @if($ret->reason)
                        <span class="text-xs text-muted">{{ \Illuminate\Support\Str::limit($ret->reason, 40) }}</span>
                    @endif
                    @if($ret->reverse_logistics_status)
                        <span class="text-xs text-muted">{{ str_replace('_', ' ', $ret->reverse_logistics_status) }}</span>
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

<div class="x-pager">
    <span class="x-pager__count x-num">{{ number_format($returns->firstItem() ?? 0) }} to {{ number_format($returns->lastItem() ?? 0) }} of {{ number_format($returns->total()) }}</span>
    <div class="x-pager__nav">
        @if($returns->previousPageUrl())
            <x-ui.button size="sm" :href="$returns->previousPageUrl()"><x-ui.icon name="chevron-left" size="14" /> Previous</x-ui.button>
        @else
            <span class="x-btn x-btn--secondary x-btn--sm" aria-disabled="true"><x-ui.icon name="chevron-left" size="14" /> Previous</span>
        @endif
        @if($returns->nextPageUrl())
            <x-ui.button size="sm" :href="$returns->nextPageUrl()">Next <x-ui.icon name="chevron-right" size="14" /></x-ui.button>
        @else
            <span class="x-btn x-btn--secondary x-btn--sm" aria-disabled="true">Next <x-ui.icon name="chevron-right" size="14" /></span>
        @endif
    </div>
</div>
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

</div>{{-- /#shopee-returns-page --}}
@endsection
