@extends('layouts.channel')
@section('breadcrumb', 'Listings')

@section('title', 'Lazada Listings')

{{-- Leaves the .x-legacy content boundary, rebuilt on the token set and the
     x-ui components. The page's behaviour moved out with it: everything that
     used to be an inline <script> at the bottom of this file now lives in
     resources/js/pages/lazada-listings.js (Lazada's three values) on top of
     resources/js/pages/channel-listings.js (the behaviour it shares with
     Shopee), bound from data- attributes on the elements below. --}}
@section('content')
@php
    // Read tier is `view_lazada|manage_lazada`; every upload, sync, link,
    // dismiss, unlink and delete below is `manage_lazada`. Replaces a
    // hardcoded `true` that showed a read-only operator five bulk buttons and
    // seven row actions, all of which the route middleware would refuse.
    $canManageLazada = auth()->user()?->hasPermission('manage_lazada/product') ?? false;
    $canEditCatalog = auth()->user()?->hasPermission('manage_catalog/product') ?? false;

    $q = (string) ($q ?? '');
    $syncStatus = (string) ($syncStatus ?? 'all');
    $manufacturerFilter = (string) ($manufacturerFilter ?? 'all');
    $groupFilter = (string) ($groupFilter ?? 'all');
    $erpStatus = (string) ($erpStatus ?? 'all');
    $sort = (string) ($sort ?? 'id');
    $dir = (string) ($dir ?? 'desc');

    $hasFilters = $q !== ''
        || $syncStatus !== 'all'
        || $manufacturerFilter !== 'all'
        || $groupFilter !== 'all'
        || $erpStatus !== 'all';

    // Sort links, the same three closures the old header carried and the same
    // ones resources/views/catalog/products/index.blade.php uses: current
    // query in, sort and dir replaced, page dropped so a re-sort starts at the
    // top. The controller allow-lists the column, so an edited URL cannot
    // reach anything it does not name.
    $toggleDir = function (string $col) use ($sort, $dir) {
        if ($sort !== $col) return 'asc';
        return $dir === 'asc' ? 'desc' : 'asc';
    };
    $sortUrl = function (string $col) use ($toggleDir) {
        $qp = request()->query();
        $qp['sort'] = $col;
        $qp['dir'] = $toggleDir($col);
        unset($qp['page']);
        return url()->current() . '?' . http_build_query($qp);
    };

@endphp

<div class="cc-page"
     x-data="{
        selected: [],
        ids: {{ \Illuminate\Support\Js::from(collect($products->items())->pluck('product_id')->map(fn ($v) => (string) $v)->values()) }}
     }">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Listings</h1>
        <p class="x-page-sub">
            {{-- This store's own list now (#201): the products this store
                 carries, not the whole catalogue. A filtered count says what
                 it is counting. --}}
            @if($hasFilters)
                {{ number_format($paginator->total()) }} {{ $paginator->total() === 1 ? 'product matches' : 'products match' }}
            @else
                {{ number_format($catalogueTotal) }} {{ $catalogueTotal === 1 ? 'product' : 'products' }} on this store
                &middot; {{ number_format($listedTotal) }} live on Lazada
            @endif
        </p>
    </div>
    @if($canManageLazada)
        <div class="cc-head-actions">
            {{-- Refresh and Repair links live beside the freshness stamp on
                 the strip (the Shopee shape, ported back 2026-09-05): global
                 maintenance, page furniture, not page actions.
                 Add products (#201, Draft C - the owner 2026-09-05): the one
                 way a main-catalogue product joins this store. A primary
                 button, sized to how often it happens, opening the slide-over
                 in place - not a tab beside the daily table, which gave a
                 rare act equal billing. "New listing" (#196) stays retired:
                 the row's Set up on Lazada is the push door. --}}
            <x-ui.button type="button" variant="primary" data-add-panel-open>
                <x-ui.icon name="plus" size="14" /> Add products
            </x-ui.button>
        </div>
    @endif
</div>

{{-- One filter row. The old page had a six-column CSS grid of labelled selects
     inside a card with a Filter button at the end; the selects here submit on
     change (data-autosubmit, resources/js/list-filters.js) so there is no
     Filter button to press, and Clear is a link because it is navigation. The
     two hidden fields keep whatever column the list is sorted by from being
     dropped the moment a filter is submitted, exactly as before. --}}
{{-- The seller center's tabs, answered by Lazada on this page load
     (Lazada parity with Shopee's listings index, 2026-08-24). All is the
     stored view; the other tabs narrow the table to the items Lazada
     holds in that status right now. Absent entirely when Lazada cannot
     be asked - the note below says why. --}}
@if(($liveTabError ?? null) !== null)
    <div class="fm-note fm-note--warn" role="status">
        <div class="fm-note__body">@include('partials.channel-answer', ['channel' => 'Lazada', 'raw' => $liveTabError, 'settingsRoute' => 'ext.lazada.index'])</div>
    </div>
@elseif(($liveCounts ?? null) !== null)
    @php
        $liveTabDefs = [
            'active' => 'Active',
            'inactive' => 'Inactive',
            'pending' => 'Pending QC',
            // One state, one word: the row badge says Rejected (the
            // engine's label), so the tab that filters to it must not
            // say Violation (Phase C, #184).
            'violation' => 'Rejected',
            'soldout' => 'Sold out',
            'deleted' => 'Deleted',
        ];
        $tabQuery = request()->except('page', 'lazada_tab');
    @endphp
    {{-- Two questions on one strip, each named (the owner, 2026-08-28): the
         catalogue's own total, whether pushed or not, and Lazada's buckets.
         The bucket counts and every row's chip come from the mirror on
         the listing rows, refreshed every 30 minutes, on demand here, and
         by every activate and deactivate. The strip says when. --}}
    <div class="x-segment-bar">
        <div class="x-segment-groups">
            <div class="x-segment-group">
                {{-- This store's own state filter (#201): the "All products"
                     tab is gone with the catalogue framing - This store IS the
                     store's list. "All" here means every state, the stored
                     view; the rest narrow to what Lazada holds right now. --}}
                <nav class="x-segment" aria-label="Lazada listing status">
                    <a href="{{ route('ext.lazada.products.index', $tabQuery) }}"
                       class="x-segment__item {{ ($lazadaTab ?? 'all') === 'all' ? 'is-active' : '' }}"
                       @if(($lazadaTab ?? 'all') === 'all') aria-current="true" @endif>
                        <span>All</span>
                        <span class="x-segment__count">{{ number_format((int) ($catalogueTotal ?? $paginator->total())) }}</span>
                    </a>
                    @foreach($liveTabDefs as $tabKey => $tabLabel)
                        @php $isActive = ($lazadaTab ?? 'all') === $tabKey; @endphp
                        <a href="{{ route('ext.lazada.products.index', array_merge($tabQuery, ['lazada_tab' => $tabKey])) }}"
                           class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
                           @if($isActive) aria-current="true" @endif>
                            <span>{{ $tabLabel }}</span>
                            <span class="x-segment__count">{{ number_format((int) ($liveCounts[$tabKey] ?? 0)) }}</span>
                        </a>
                    @endforeach
                </nav>
                <span class="x-segment-group__meta">
                    @if($liveCheckedAt ?? null)
                        as of {{ $liveCheckedAt->format('H:i, M j') }}
                    @else
                        not refreshed yet
                    @endif
                </span>
                @if($canManageLazada)
                    {{-- The one button (the owner, 2026-09-07): Refresh checks every link against
                         the store and re-reads every status, the old Repair links folded in. --}}
                    <form method="POST" action="{{ route('ext.lazada.products.refresh_status') }}" class="x-segment-group__refresh" data-slow-action>
                        @csrf
                        <x-ui.button type="submit"><x-ui.icon name="refresh-cw" size="14" /> Refresh from Lazada</x-ui.button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endif

<form method="GET" action="{{ route('ext.lazada.products.index') }}" class="x-filters" id="lazada-listings-filter">
    @if(($lazadaTab ?? 'all') !== 'all')
        <input type="hidden" name="lazada_tab" value="{{ $lazadaTab }}">
    @endif
    <x-ui.input type="search" name="q" class="x-filters__search"
                value="{{ $q }}" placeholder="Name, model, SKU or Lazada ID" aria-label="Search listings" />

    <div class="x-select-wrap x-filters__select">
        <select name="manufacturer" class="x-input" data-autosubmit aria-label="Filter by manufacturer">
            <option value="all">All manufacturers</option>
            @foreach(($allManufacturers ?? collect()) as $mId => $mName)
                <option value="{{ $mId }}" @selected($manufacturerFilter === (string) $mId)>{{ $mName }}</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <div class="x-select-wrap x-filters__select">
        <select name="group" class="x-input" data-autosubmit aria-label="Filter by product group">
            <option value="all" @selected($groupFilter === 'all')>All product groups</option>
            <option value="none" @selected($groupFilter === 'none')>Not in a product group</option>
            @foreach(($allGroups ?? collect()) as $gId => $gName)
                <option value="{{ $gId }}" @selected($groupFilter === (string) $gId)>{{ $gName }}</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="erp_status" class="x-input" data-autosubmit aria-label="Filter by catalog status">
            <option value="all" @selected($erpStatus === 'all')>Any catalog status</option>
            <option value="enabled" @selected($erpStatus === 'enabled')>Enabled</option>
            <option value="disabled" @selected($erpStatus === 'disabled')>Disabled</option>
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="sync_status" class="x-input" data-autosubmit aria-label="Filter by Lazada listing status">
            <option value="all" @selected($syncStatus === 'all')>Listed or not</option>
            <option value="uploaded" @selected($syncStatus === 'uploaded')>Listed on Lazada</option>
            <option value="not_uploaded" @selected($syncStatus === 'not_uploaded')>Not listed</option>
            <option value="deleted" @selected($syncStatus === 'deleted')>Deleted from Lazada</option>
            {{-- The computed answer (#101): uploadable right now, or not. --}}
            <option value="ready" @selected($syncStatus === 'ready')>Ready to push</option>
            <option value="not_ready" @selected($syncStatus === 'not_ready')>Not ready to push</option>
            {{-- The reason to open this page. Separated from the three above
                 because they describe where a listing IS and this one
                 describes what happened to it. --}}
            <option value="error" @selected($syncStatus === 'error')>Last sync failed</option>
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <input type="hidden" name="sort" value="{{ $sort }}">
    <input type="hidden" name="dir" value="{{ $dir }}">

    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>

    @if($hasFilters)
        <a class="x-filters__reset" href="{{ route('ext.lazada.products.index') }}">Clear</a>
    @endif
</form>

@php
    // Guards check the ext.-prefixed name the route() call actually generates.
    // They used to check the unprefixed name (lazada.products.bulk_sync_quantity
    // etc.), which stopped existing when these routes gained their ext.
    // prefix - Route::has() was therefore always false and every button
    // silently fell back to a hardcoded URL that matches no registered route.
    // Caught by RouteNameSweepTest's Route::has() sweep, and kept here
    // unchanged: these action URLs are the wire. (The multi-tick push left
    // on 2026-09-06: many products go up from a product group's page.)
    $bulkSyncQtyUrl = \Illuminate\Support\Facades\Route::has('ext.lazada.products.bulk_sync_quantity')
        ? route('ext.lazada.products.bulk_sync_quantity')
        : url('/channels/lazada/products/bulk/sync/quantity');
    $bulkSyncPriceUrl = \Illuminate\Support\Facades\Route::has('ext.lazada.products.bulk_sync_price')
        ? route('ext.lazada.products.bulk_sync_price')
        : url('/channels/lazada/products/bulk/sync/price');
    $bulkSyncLazadaIdUrl = \Illuminate\Support\Facades\Route::has('ext.lazada.products.bulk_sync_lazada_id')
        ? route('ext.lazada.products.bulk_sync_lazada_id')
        : url('/channels/lazada/products/bulk/sync/lazada-id');
    $bulkDeleteUrl = \Illuminate\Support\Facades\Route::has('ext.lazada.products.bulk_delete')
        ? route('ext.lazada.products.bulk_delete')
        : url('/channels/lazada/products/bulk/delete');
@endphp

@if($canManageLazada)
{{-- Bulk bar. It exists only once at least one row is ticked, which is the
     whole reason the pre-redesign page's standing accent-tinted strip of five
     buttons is gone. Each action is still its own form posting product_ids[]
     to its own route, exactly as before; the module injects the ticked ids
     into whichever form is submitted. Delete keeps the transparent danger
     treatment, not a solid red fill. --}}
{{-- role="status" so the bar arriving is heard, not only seen. Ticking a
     row made five actions appear with no announcement of any kind, so a
     screen reader user selected twelve listings and was never told the count
     or that anything had opened. aria-live is on the COUNT rather than the
     whole bar: the buttons do not change, and re-reading five labels on
     every tick would be worse than silence. --}}
@php
    $bulk = [
        'switch' => [
            ['action' => route('ext.lazada.products.bulk_toggle'), 'verb' => 'deactivate', 'codes' => 'active', 'label' => 'Deactivate :n on Lazada', 'button' => 'Deactivate on Lazada', 'fields' => ['action' => 'deactivate'], 'confirm' => 'Deactivate the selected items on Lazada? Buyers stop seeing them. They stay here and can be activated again at any time.'],
            ['action' => route('ext.lazada.products.bulk_toggle'), 'verb' => 'activate', 'codes' => 'inactive', 'label' => 'Activate :n on Lazada', 'button' => 'Activate on Lazada', 'fields' => ['action' => 'activate'], 'confirm' => 'Activate the selected items on Lazada? Buyers see them again once Lazada applies it.'],
        ],
        'stock' => ['action' => route('ext.lazada.products.bulk_sync_quantity')],
        'prices' => ['action' => route('ext.lazada.products.bulk_sync_price')],
        'link' => ['action' => route('ext.lazada.products.bulk_sync_lazada_id'), 'label' => 'Link IDs'],
        'delete' => ['action' => route('ext.lazada.products.bulk_delete'), 'label' => 'Delete from Lazada', 'confirm' => 'Delete the selected listings from Lazada? The listings are removed at the marketplace and this cannot be undone.'],
        'remove' => ['action' => route('ext.lazada.products.bulk_remove_from_store'), 'label' => 'Remove from this channel', 'confirm' => 'Remove the selected products from this channel? They leave this store\'s list and stop syncing. Anything already on Lazada stays up until you delete it there.'],
    ];
@endphp
@include('partials.channel-bulk-bar', ['bulk' => $bulk])
@endif

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : (($lazadaTab ?? 'all') !== 'all' ? 'Nothing holds this status' : 'No products on this store yet') }}"
                :description="$hasFilters
                    ? 'Try clearing the search, or widening the product group and status filters.'
                    : ((($lazadaTab ?? 'all') !== 'all')
                        ? 'No listing on this store holds this status right now.'
                        : 'This store carries nothing yet. Add products from your main catalogue and they will land here, ready to configure and push to Lazada.')">
        {{-- A new store opens here empty (#201): the way forward is Add
             products, not a dead end. The other two branches keep the reader
             from being stuck behind a filter with no visible way out. --}}
        @if($hasFilters)
        <x-slot:action>
            <x-ui.button :href="route('ext.lazada.products.index')">Clear filters</x-ui.button>
        </x-slot:action>
        @elseif(($lazadaTab ?? 'all') !== 'all')
        <x-slot:action>
            <x-ui.button :href="route('ext.lazada.products.index')">Show every status</x-ui.button>
        </x-slot:action>
        @elseif($canManageLazada)
        <x-slot:action>
            <x-ui.button variant="primary" type="button" data-add-panel-open>Add products</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table id="lazada-listings-table">
    <x-slot:head>
        <tr>
            @if($canManageLazada)
            <th scope="col" class="cc-col-check">
                <input type="checkbox" class="cc-check"
                       :checked="ids.length > 0 && selected.length === ids.length"
                       x-effect="$el.indeterminate = selected.length > 0 && selected.length < ids.length"
                       @change="selected = $event.target.checked ? ids.slice() : []"
                       aria-label="Select every listing on this page">
            </th>
            @endif
            <th scope="col" class="cc-col-id">
                <a class="x-th-sort" href="{{ $sortUrl('id') }}">
                    ID
                    @if($sort === 'id')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col">
                <a class="x-th-sort" href="{{ $sortUrl('product') }}">
                    Product
                    @if($sort === 'product')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="cc-col-qty x-td-num">
                <a class="x-th-sort x-th-sort--num" href="{{ $sortUrl('quantity') }}">
                    Stock
                    @if($sort === 'quantity')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="cc-col-price x-td-num">Price</th>
            <th scope="col" class="cc-col-chanid">
                <a class="x-th-sort" href="{{ $sortUrl('lazada_item_id') }}">
                    Lazada ID
                    @if($sort === 'lazada_item_id')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            @include('partials.channel-ready-head')
            <th scope="col" class="cc-col-sync">
                <a class="x-th-sort" href="{{ $sortUrl('lazada_status') }}">
                    Listing
                    @if($sort === 'lazada_status')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="cc-col-err">
                <a class="x-th-sort" href="{{ $sortUrl('last_sync') }}">
                    Last sync
                    @if($sort === 'last_sync')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($products as $row)
        @php
            $pid = (int) $row->product_id;
            $listing = $row->listing_id ? $listingsById->get((int) $row->listing_id) : null;
            /* ONE state per row (Phase C, #184): badge, readiness AND the
               action menu below all read this same object, so the words and
               the buttons can never disagree (#188). */
            $rowState = $listingStates[$pid] ?? null;
            $stateClass = \App\Integrations\Listings\ListingState::class;

            $thumbSrc = $productThumbsById->get($pid);
            $pName = (string) ($row->name ?? '');
            $pSku = (string) ($row->sku ?? '');
            $pQty = (int) ($row->quantity ?? 0);
            $pStatus = (int) ($row->status ?? 0);
            $pMfg = (string) ($row->manufacturer_name ?? '');

            $isDeleted = !is_null($row->lazada_deleted_at);
            $hasItemId = !is_null($row->lazada_item_id) && (string) $row->lazada_item_id !== '';
            $isUnlinked = !is_null($row->unlinked_at);

            $hasSyncInfo = !is_null($row->last_synced_at) || !is_null($row->last_sync_ok);
            $syncFailed = $hasSyncInfo && !is_null($row->last_sync_ok) && !$row->last_sync_ok;

            $optRows = isset($optionRowsByProductId) ? $optionRowsByProductId->get($pid) : null;
            $variationCount = $optRows ? count($optRows) : 0;

            // A product with no listing row begins at the create form,
            // prefilled; one with a row opens it.
            // The listing page carries this page's own URL (search, filters,
            // page) as `back`, so Save and push and Cancel return here
            // (the owner, 2026-09-06). App\Support\BackTo keeps it on this host.
            // The product id is the key on every route (2026-09-07); a product with no row yet opens the same page.
            $editUrl = route('ext.lazada.products.edit', ['productId' => $pid, 'back' => request()->getRequestUri()]);

            // What the operator is about to act on, for the confirms below.
            $rowLabel = $pName !== '' ? $pName : ($pSku !== '' ? $pSku : 'product ' . $pid);
        @endphp
        <tr>
            @if($canManageLazada)
            <td class="cc-col-check">
                {{-- Bulk actions post product_ids[]; a product without a row is skipped by the action, so only a product that
                     HAS a listing row can be ticked. No name attribute: the
                     module injects the ticked ids into whichever bulk form
                     is submitted. data-live carries the raw mirror word for
                     the bulk toggle's verb filter; it is a wire, the
                     rendered words come from the engine. --}}
                @if($listing)
                <input type="checkbox" class="cc-check" data-row-check value="{{ (string) $pid }}"
                       data-live="{{ $hasItemId ? (string) ($listing->live_status ?? '') : 'NOT_LISTED' }}"
                       x-model="selected" aria-label="Select {{ $rowLabel }}">
                @endif
            </td>
            @endif

            <td class="cc-col-id" data-label="ID">
                <span class="x-num">{{ $pid }}</span>
            </td>

            <td data-label="Product">
                <div class="co-item">
                    <div class="cc-thumb">
                        <span class="co-item__none">No image</span>
                        @if($thumbSrc)
                            <img src="{{ $thumbSrc }}" alt="" loading="lazy" decoding="async" data-thumb>
                        @endif
                    </div>
                    <div class="co-item__body">
                        {{-- The edit page is manage-gated, so a read-only
                             operator gets the name as text rather than a link
                             that would be refused. --}}
                        @if($canManageLazada)
                            <a class="x-row-link co-item__name" href="{{ $editUrl }}">{{ $pName ?: 'Unnamed product' }}</a>
                        @else
                            <span class="co-item__name">{{ $pName ?: 'Unnamed product' }}</span>
                        @endif
                        <div class="co-item__meta">
                            <x-ui.badge :tone="$pStatus ? 'success' : 'neutral'">{{ $pStatus ? 'Enabled' : 'Disabled' }}</x-ui.badge>
                            <span class="co-item__sku">{{ $pSku ?: 'No SKU' }}</span>
                            @if($pMfg !== '')
                                <span>{{ $pMfg }}</span>
                            @endif
                            @if($listing && $listing->groups->count() > 0)
                                @php $firstGroup = $listing->groups->first(); @endphp
                                <span class="co-item__var" @if($listing->groups->count() > 1) title="Product Groups: {{ $listing->groups->pluck('name')->implode(', ') }}" @endif>Product group{{ $listing->groups->count() > 1 ? 's' : '' }}: {{ $firstGroup->name }}@if($listing->groups->count() > 1) and {{ $listing->groups->count() - 1 }} more @endif</span>
                            @endif
                            @if($isUnlinked)
                                <span>Unlinked</span>
                            @endif
                        </div>

                    </div>
                </div>
            </td>

            <td class="cc-col-qty x-td-num" data-label="Stock">
                <span class="x-num">{{ $pQty }}</span>
            </td>

            <td class="cc-col-price x-td-num" data-label="Price">
                <x-money :php="(float) $row->price" />
            </td>

            <td class="cc-col-chanid" data-label="Lazada ID">
                @if($hasItemId)
                    <span class="x-num">{{ $row->lazada_item_id }}</span>
                @else
                    <span class="x-cell-muted">None</span>
                @endif
            </td>
            <td class="cc-col-ready" data-label="Ready">
                @include('partials.channel-ready', ['state' => $rowState])
            </td>


            <td class="cc-col-sync" data-label="Listing">
                {{-- The engine's answer, rendered verbatim. This cell used
                     to carry its own label map - the fourth status
                     vocabulary on one channel, already drifted from the
                     controller's - and a five-branch fallback ladder.
                     Never checked reads as exactly that, not as a guess. --}}
                @if($rowState === null)
                    <x-ui.badge tone="neutral">Not listed</x-ui.badge>
                @elseif($rowState->state === $stateClass::UNKNOWN && $hasItemId && ($liveCounts ?? null) === null)
                    {{-- No shop configured: the mirror cannot exist, and
                         "Listed" is all the link can honestly say. --}}
                    <x-ui.badge tone="success">Listed</x-ui.badge>
                @elseif($rowState->state === $stateClass::UNKNOWN)
                    <x-ui.badge tone="neutral" title="Press Refresh from Lazada to read its status">{{ $rowState->label() }}</x-ui.badge>
                @elseif($rowState->state === $stateClass::NOT_LISTED)
                    <x-ui.badge :tone="$rowState->tone()">{{ $rowState->label() }}</x-ui.badge>
                    {{-- Whether a push would pass is the Ready column's. --}}
                @else
                    {{-- The title rides a wrapping span: Blade cannot compile
                         an @if inside a component tag's attribute list. --}}
                    <span @if($rowState->checkedAt) title="Checked {{ $rowState->checkedAt->diffForHumans() }}" @endif>
                        <x-ui.badge :tone="$rowState->tone()">{{ $rowState->label() }}</x-ui.badge>
                    </span>
                    {{-- Drift (#100): live and out of date are different
                         facts, so both are shown. --}}
                    @if($rowState->driftLabel())
                        <span class="cc-checked"><x-ui.badge tone="warning">{{ $rowState->driftLabel() }}</x-ui.badge>
                            <x-ui.hint label="What changed">{{ implode(' ', $rowState->reasons) }}</x-ui.hint></span>
                    @elseif($rowState->reasons)
                        <x-ui.hint label="What Lazada says">{{ implode(' ', $rowState->reasons) }}</x-ui.hint>
                    @endif
                @endif
            </td>

            <td class="cc-col-err" data-label="Last sync">
                {{-- One answer for this column on every page (the owner,
                     2026-09-05): the shared refusal, else the newest
                     successful sync, else never. --}}
                @include('partials.channel-last-sync', ['sync' => ($lastSync ?? [])[(int) $pid] ?? null])
            </td>

            <td class="x-td-actions">
                @if($canManageLazada)
                {{-- The menu derives from the SAME state the badge wears
                     (#188). The old ladder keyed on three booleans and a
                     raw status, and rendered both toggles with one hidden -
                     so a row could say one thing and offer the actions of
                     another. Now: what the badge says decides what the
                     menu holds, and only the applicable toggle exists. --}}
                @php
                    $menu = [
                        'label' => "{$rowLabel} actions",
                        'store' => 'Lazada',
                        'edit' => $editUrl,
                        'catalog' => $canEditCatalog ? route('products.edit', $pid) : null,
                    ];
                    if ($canManageLazada) {
                        if (!$listing || !$hasItemId || $isUnlinked) {
                            // One push, and it opens the listing page first (#149), where Save and push sits under what is about to go up.
                            $menu['push'] = ['href' => $editUrl, 'label' => 'Push to Lazada'];
                            if ($listing) {
                                $menu['link'] = ['action' => route('ext.lazada.products.sync_lazada_id', $pid)];
                            }
                        } else {
                            $menu['update'] = ['action' => route('ext.lazada.products.upload', $pid), 'label' => 'Update on Lazada', 'confirm' => "Push this listing's content and settings onto the live Lazada item? Name, description, package, category, brand and attributes are updated. Price and stock are not touched.", 'verb' => 'Push update', 'tone' => 'primary'];
                            $menu['stock'] = route('ext.lazada.products.sync_quantity', $pid);
                            $menu['price'] = route('ext.lazada.products.sync_price', $pid);
                            if ($rowState?->state === $stateClass::LIVE) {
                                $menu['switch'] = ['action' => route('ext.lazada.listings.toggle', $pid), 'label' => 'Deactivate on Lazada', 'fields' => ['action' => 'deactivate'], 'confirm' => "Deactivate {$rowLabel} on Lazada? Buyers stop seeing it. It can be activated again at any time.", 'verb' => 'Deactivate'];
                            } elseif ($rowState?->state === $stateClass::INACTIVE) {
                                $menu['switch'] = ['action' => route('ext.lazada.listings.toggle', $pid), 'label' => 'Activate on Lazada', 'fields' => ['action' => 'activate'], 'confirm' => "Activate {$rowLabel} on Lazada? Buyers see it again once Lazada applies it.", 'verb' => 'Activate', 'tone' => 'primary'];
                            }
                            $menu['check'] = ['action' => route('ext.lazada.products.check'), 'label' => 'Check against Lazada', 'fields' => ['product_ids[]' => $pid]];
                            $menu['unlink'] = ['action' => route('ext.lazada.products.unlink', $pid), 'confirm' => "Unlink {$rowLabel} from Lazada? Only the link is removed. The Lazada listing stays up and can be linked again later."];
                            $menu['delete'] = ['action' => route('ext.lazada.products.delete_lazada', $pid), 'confirm' => "Delete {$rowLabel} from Lazada? The listing is removed at the marketplace and this cannot be undone."];
                        }
                        if ($listing) {
                            $menu['remove'] = ['action' => route('ext.lazada.products.remove_from_store', $pid), 'label' => 'Remove from this channel', 'verb' => 'Remove', 'confirm' => $hasItemId && !$isUnlinked
                                ? "Remove {$rowLabel} from this channel? It leaves this store's list and stops syncing. It is on Lazada (item {$listing->lazada_item_id}) and stays up, still orderable, until you remove it there. Use Delete from Lazada for that."
                                : "Remove {$rowLabel} from this channel? It leaves this store's list and stops syncing. Nothing on Lazada is changed."];
                        }
                    }
                @endphp
                @include('partials.channel-row-menu', ['menu' => $menu])
                @endif
            </td>
        </tr>
        @if($variationCount > 0)
            {{-- The variations as rows of this table, under the same headers (partials.channel-variation-rows). --}}
            @include('partials.channel-variation-rows', ['rows' => $optRows, 'check' => $canManageLazada, 'trail' => ['cc-col-chanid', 'cc-col-sync', 'cc-col-err', 'x-td-actions']])
        @endif
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$paginator" />

@endif

{{-- Work-in-progress overlay. An upload to Lazada is a multi-second round trip
     and the page has to say so, or the operator clicks again. Rides
     components/modals.css's .modal-backdrop show/hide mechanics, the same
     single `.active` class partials/confirm-modal.blade.php and the orders
     pages' own overlay already use. --}}
<div id="lazada-listings-progress" class="modal-backdrop">
    {{-- A dialog, and one that speaks. This overlay covers the page for
         several seconds while a marketplace call is in flight and carried no
         role, no name and no live region, so the only signal that anything
         was happening was the spinner - which is to say, none at all for
         anyone not looking at it. --}}
    <div class="modal co-modal co-modal--sm" role="dialog" aria-modal="true"
         aria-labelledby="lazada-progress-title" aria-describedby="lazada-progress-note">
        <div class="cc-progress" role="status" aria-live="polite">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div class="cc-progress__title" id="lazada-progress-title" data-progress-title>Talking to Lazada</div>
                <div class="cc-progress__note" id="lazada-progress-note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

{{-- The Add products slide-over (#201, Draft C), manage-gated like its
     button and every route it calls. --}}
@if($canManageLazada)
    @include('ext-lazada::products._add-panel')
@endif

</div>{{-- /.cc-page --}}
@endsection
