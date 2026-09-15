@extends('layouts.channel')
@section('breadcrumb', 'Listings')

@section('title', 'Shopee Listings')

{{-- Leaves the .x-legacy content boundary, rebuilt on the token set and the
     x-ui components. The page's behaviour moved out with it: everything that
     used to be an inline <script> at the bottom of this file now lives in
     resources/js/pages/shopee-listings.js, bound from data- attributes on
     the elements below. --}}
@section('content')
@php
    // Read tier is `view_shopee|manage_shopee`; every push, sync, link,
    // dismiss and delete below is `manage_shopee`. Replaces a hardcoded
    // `true` that showed a read-only operator five bulk buttons and eight
    // row actions, all of which the route middleware would 403.
    $canManageShopee = auth()->user()?->hasPermission('manage_shopee/product') ?? false;
    $canEditCatalog = auth()->user()?->hasPermission('manage_catalog/product') ?? false;

    $q = (string) ($q ?? '');
    $syncStatus = (string) ($syncStatus ?? 'all');
    $manufacturerFilter = (string) ($manufacturerFilter ?? 'all');
    $groupFilter = (string) ($groupFilter ?? 'all');
    $erpStatus = (string) ($erpStatus ?? 'all');

    $hasFilters = $q !== ''
        || $syncStatus !== 'all'
        || ($shopeeTab ?? 'all') !== 'all'
        || ($failedFlag ?? false)
        || ($changeFlag ?? false)
        || $manufacturerFilter !== 'all'
        || $groupFilter !== 'all'
        || $erpStatus !== 'all';

    $sort = (string) ($sort ?? 'id');
    $dir = (string) ($dir ?? 'asc');

    /* The same pair Lazada's listings page uses. Current query in, sort and
       dir replaced, page dropped so a re-sort starts at the top. The
       controller allow-lists the column, so an edited URL cannot reach
       anything it does not name. */
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
        ids: {{ \Illuminate\Support\Js::from($products->pluck('product_id')->map(fn ($v) => (string) $v)->values()) }}
     }">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Listings</h1>
        <p class="x-page-sub">
            {{-- This store's own list: the products this store carries, not
                 the whole catalogue. A filtered count says what it is
                 counting. --}}
            @if($hasFilters)
                {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product matches' : 'products match' }}@if($statusMenu['label'] ?? null): {{ $statusMenu['label'] }}@endif
            @else
                {{ number_format($catalogueTotal ?? $products->total()) }} {{ ($catalogueTotal ?? $products->total()) === 1 ? 'product' : 'products' }} on this store
                &middot; {{ number_format($listedTotal ?? 0) }} on Shopee
            @endif
        </p>
    </div>
    @if($canManageShopee)
        <div class="cc-head-actions">
            {{-- Add products: the one way a main-catalogue product joins this
                 store, opening the slide-over in place. --}}
            <x-ui.button type="button" variant="primary" data-add-panel-open>
                <x-ui.icon name="plus" size="14" /> Add products
            </x-ui.button>
        </div>
    @endif
</div>



{{-- Shopee's own statuses, as the tab row above the table: what the store
     says about each item, beside the menu's VentaSync status. The two
     combine, and each one's counts follow the other's choice. When Shopee
     cannot be asked, the note says why and the row is not drawn. --}}
@if(($liveTabError ?? null) !== null)
    <div class="fm-note fm-note--warn" role="status">
        <div class="fm-note__body">@include('partials.channel-answer', ['channel' => 'Shopee', 'raw' => $liveTabError, 'settingsRoute' => 'ext.shopee.index'])</div>
    </div>
@elseif(($liveCounts ?? null) !== null)
    @include('ext-shopee::products._scope_strips', ['stripRoute' => 'ext.shopee.products.index', 'stripParams' => []])
@endif

@include('partials.channel-state-filter', [
    'bucket' => $troubleFilter ?? null,
    'count' => $products->total(),
    'clear' => route('ext.shopee.products.index', request()->except(['state', 'page'])),
])

{{-- One filter row. The old page had a five-column CSS grid of labelled
     selects inside a card; the selects here submit on change (data-autosubmit,
     resources/js/list-filters.js) so there is no Filter button to press, and
     Clear is a link because it is navigation. --}}
<div class="lsm-layout">
@include('partials.listing-status-menu', ['menu' => $statusMenu])
<div class="lsm-layout__main">

<form method="GET" action="{{ route('ext.shopee.products.index') }}" class="x-filters" id="shopee-listings-filter">
    {{-- The menu's choice rides a search. --}}
    @if(($shopeeTab ?? 'all') !== 'all')
        <input type="hidden" name="shopee_tab" value="{{ $shopeeTab }}">
    @endif
    @if($syncStatus !== 'all')
        <input type="hidden" name="sync_status" value="{{ $syncStatus }}">
    @endif
    @if($failedFlag ?? false)
        <input type="hidden" name="failed" value="1">
    @endif
    @if($changeFlag ?? false)
        <input type="hidden" name="change" value="1">
    @endif
    <x-ui.input type="search" name="q" class="x-filters__search"
                value="{{ $q }}" placeholder="Name, model or SKU" aria-label="Search listings" />

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
            @foreach(($allGroups ?? collect()) as $pId => $pName)
                <option value="{{ $pId }}" @selected($groupFilter === (string) $pId)>{{ $pName }}</option>
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

    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>

    @if($hasFilters)
        <a class="x-filters__reset" href="{{ route('ext.shopee.products.index') }}">Clear</a>
    @endif
</form>

@if($canManageShopee)
{{-- Bulk bar. It exists only once at least one row is ticked, which is the
     whole reason the pre-redesign page's standing row of five marketplace
     buttons is gone. Each action is still its own form posting product_ids[]
     to its own route, exactly as before; the module injects the ticked ids
     into whichever form is submitted. Delete keeps the transparent danger
     treatment, not a solid red fill. --}}
{{-- role="status" so the bar arriving is heard, not only seen. aria-live is
     on the COUNT rather than the whole bar: the buttons do not change, and
     re-reading five labels on every tick would be worse than silence. --}}
@php
    $bulk = [
        /* Multi-tick push, back on 2026-09-09: each ticked product goes up
           on its own settings and reports for itself. */
        'push' => ['action' => route('ext.shopee.products.bulk_push'), 'label' => 'Push to Shopee',
            'confirm' => 'Push the selected products to Shopee? Each goes up on its own settings, and anything not ready is named rather than sent.'],
        'switch' => [
            ['action' => route('ext.shopee.products.bulk_toggle'), 'verb' => 'unlist', 'codes' => 'NORMAL', 'label' => 'Delist :n from Shopee', 'button' => 'Delist from Shopee', 'fields' => ['action' => 'unlist'], 'confirm' => 'Delist the selected items from Shopee? Buyers stop seeing them immediately. They stay here and can be published again at any time.'],
            ['action' => route('ext.shopee.products.bulk_toggle'), 'verb' => 'list', 'codes' => 'UNLIST', 'label' => 'Publish :n on Shopee', 'button' => 'Publish on Shopee', 'fields' => ['action' => 'list'], 'confirm' => 'Publish the selected items on Shopee? Buyers see them again immediately.'],
        ],
        'stock' => ['action' => route('ext.shopee.products.bulk_sync_quantity')],
        'prices' => ['action' => route('ext.shopee.products.bulk_sync_price')],
        'link' => ['action' => route('ext.shopee.products.check'), 'label' => 'Link IDs'],
        'delete' => ['action' => route('ext.shopee.products.bulk_delete'), 'label' => 'Delete from Shopee', 'confirm' => 'Delete the selected products from Shopee? The listings are removed at the marketplace and this cannot be undone.'],
        'remove' => ['action' => route('ext.shopee.products.bulk_remove_from_store'), 'label' => 'Remove from this channel', 'confirm' => 'Remove the selected products from this channel? They leave this store\'s list and stop syncing. Anything already on Shopee stays up until you delete it there.'],
    ];
@endphp
@include('partials.channel-bulk-bar', ['bulk' => $bulk])
@endif

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : (($shopeeTab ?? 'all') !== 'all' ? 'Nothing holds this status' : 'No products on this store yet') }}"
                :description="$hasFilters
                    ? 'Try clearing the search, or widening the product group and status filters.'
                    : ((($shopeeTab ?? 'all') !== 'all')
                        ? 'No listing on this store holds this status right now.'
                        : 'This store carries nothing yet. Add products from your main catalogue and they will land here, ready to configure and push to Shopee.')">
        {{-- A new store opens here empty (#201): the way forward is Add
             products, not a dead end. The other two branches keep the reader
             from being stuck behind a filter with no visible way out. --}}
        @if($hasFilters)
        <x-slot:action>
            <x-ui.button :href="route('ext.shopee.products.index')">Clear filters</x-ui.button>
        </x-slot:action>
        @elseif(($shopeeTab ?? 'all') !== 'all')
        <x-slot:action>
            <x-ui.button :href="route('ext.shopee.products.index')">Show every status</x-ui.button>
        </x-slot:action>
        @elseif($canManageShopee)
        <x-slot:action>
            <x-ui.button variant="primary" type="button" data-add-panel-open>Add products</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
@php $colCount = 7 + ($canManageShopee ? 1 : 0); @endphp
<x-ui.table id="shopee-listings-table">
    <x-slot:head>
        <tr>
            @if($canManageShopee)
            <th scope="col" class="cc-col-check">
                <input type="checkbox" class="cc-check"
                       :checked="ids.length > 0 && selected.length === ids.length"
                       x-effect="$el.indeterminate = selected.length > 0 && selected.length < ids.length"
                       @change="selected = $event.target.checked ? ids.slice() : []"
                       aria-label="Select every product on this page">
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
            <th scope="col" class="cc-col-price x-td-num">
                <a class="x-th-sort x-th-sort--num" href="{{ $sortUrl('price') }}">
                    Price
                    @if($sort === 'price')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="cc-col-stat">Catalog</th>
            <th scope="col" class="cc-col-sync">Listing</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($products as $p)
        @php
            $links = $shopeeLinks->get($p->product_id, collect());
            $link = $links->first();
            $pGroups = isset($groupsByProductId) ? ($groupsByProductId[$p->product_id] ?? []) : [];
            $optRows = isset($optionRowsByProductId) ? $optionRowsByProductId->get($p->product_id) : null;
            $variationCount = $optRows ? count($optRows) : 0;

            $img = trim((string) ($p->image ?? ''));
            $thumbSrc = $img !== '' ? asset('storage/' . ltrim($img, '/')) : '';

            $syncFailed = $link && !is_null($link->last_sync_ok) && !$link->last_sync_ok;
            $pName = (string) ($rowTitles[(int) $p->product_id] ?? $p->name);
            $rowLabel = $pName !== '' ? $pName : (string) ($p->sku ?: 'product ' . $p->product_id);
        @endphp
        @include('partials.channel-error-row', ['error' => $rowErrors[(int) $p->product_id] ?? null, 'cols' => $colCount])
        @include('partials.channel-ready-row', ['state' => ($listingStates ?? [])[(int) $p->product_id] ?? null, 'cols' => $colCount])
        <tr>
            @if($canManageShopee)
            <td class="cc-col-check">
                {{-- No name attribute: these boxes belong to no form. Each
                     bulk action is its own form and the module injects the
                     ticked ids into whichever one is submitted, which is the
                     pre-redesign wire unchanged. x-model keeps Alpine's
                     count in step with the DOM the module reads. --}}
                <input type="checkbox" class="cc-check" data-row-check value="{{ (string) $p->product_id }}"
                       data-live="{{ $link ? ($liveStatuses[(int) $link->shopee_item_id] ?? '') : 'NOT_LISTED' }}"
                       x-model="selected" aria-label="Select product {{ $p->product_id }}">
            </td>
            @endif

            <td class="cc-col-id" data-label="ID"><span class="x-num">{{ $p->product_id }}</span></td>

            <td data-label="Product">
                <div class="co-item">
                    <div class="cc-thumb">
                        <span class="co-item__none">No image</span>
                        @if($thumbSrc)
                            <img src="{{ $thumbSrc }}" alt="" loading="lazy" decoding="async" data-thumb>
                        @endif
                    </div>
                    <div class="co-item__body">
                        <a class="x-row-link co-item__name" href="{{ route('products.edit', $p->product_id) }}">{{ $pName ?: 'Unnamed product' }}</a>
                        <div class="co-item__meta">
                            <span class="co-item__sku">{{ $p->sku ?: 'No SKU' }}</span>
                            @if($link)
                                {{-- The item id opens the live listing, as the buyer sees it. --}}
                                @php $liveUrl = \Extensions\shopee\Models\ShopeeSetting::publicListingUrl($link->shopee_item_id); @endphp
                                @if($liveUrl)
                                    <a class="co-item__sku lsm-chanid" href="{{ $liveUrl }}" target="_blank" rel="noopener">Shopee {{ $link->shopee_item_id }}<x-ui.icon name="external-link" size="10" class="x-extlink" /><span class="x-sr"> (opens Shopee in a new tab)</span></a>
                                @else
                                    <span class="co-item__sku lsm-chanid">Shopee {{ $link->shopee_item_id }}</span>
                                @endif
                            @endif
                            @if($p->manufacturer_name)
                                <span>{{ $p->manufacturer_name }}</span>
                            @endif
                            @if(count($pGroups) > 0)
                                {{-- Which product GROUPS hold this product.
                                     It used to render the bare group name,
                                     which read as a category or a variation
                                     (the class is the variation chip on
                                     every order page). The label now says
                                     what it is, and the full list rides on
                                     the title for the "and N more" case. --}}
                                <span class="co-item__var" @if(count($pGroups) > 1) title="Product Groups: {{ implode(', ', $pGroups) }}" @endif>Product group{{ count($pGroups) > 1 ? 's' : '' }}: {{ $pGroups[0] }}@if(count($pGroups) > 1) and {{ count($pGroups) - 1 }} more @endif</span>
                            @endif
                        </div>

                    </div>
                </div>
            </td>

            <td class="cc-col-qty x-td-num" data-label="Stock"><span class="x-num">{{ (int) $p->quantity }}</span></td>

            <td class="cc-col-price x-td-num" data-label="Price"><x-money :php="(float) ($rowPrices[(int) $p->product_id] ?? $p->price)" /></td>

            <td class="cc-col-stat" data-label="Catalog">
                <x-ui.badge :tone="(int) $p->status === 1 ? 'success' : 'neutral'">{{ (int) $p->status === 1 ? 'Enabled' : 'Disabled' }}</x-ui.badge>
            </td>

            <td class="cc-col-sync" data-label="Listing">
                {{-- ONE state, from the engine (Phase B, #184). This cell
                     used to carry its own label map, one of three copies
                     that had already drifted apart; every word and tone now
                     comes from ListingState and cannot disagree with any
                     other page. Never checked reads as exactly that, not as
                     a guess. --}}
                @php $rowState = $listingStates[(int) $p->product_id] ?? null; @endphp
                @if($rowState === null)
                    <x-ui.badge tone="neutral">Not listed</x-ui.badge>
                @elseif($rowState->state === \App\Integrations\Listings\ListingState::UNKNOWN && $link && ($liveCounts ?? null) === null)
                    {{-- No shop configured: the mirror cannot exist, and
                         "Listed" is all the link can honestly say. --}}
                    <x-ui.badge tone="success">Listed</x-ui.badge>
                @elseif($rowState->state === \App\Integrations\Listings\ListingState::UNKNOWN)
                    <x-ui.badge tone="neutral" title="Press Refresh from Shopee to read its status">{{ $rowState->label() }}</x-ui.badge>
                @elseif($rowState->state === \App\Integrations\Listings\ListingState::NOT_LISTED)
                    <x-ui.badge :tone="$rowState->tone()">{{ $rowState->label() }}</x-ui.badge>
                    {{-- Whether a push would pass is the Ready column's. --}}
                @else
                    {{-- The title rides a wrapping span: Blade cannot compile
                         an @if inside a component tag's attribute list. --}}
                    <span @if($rowState->checkedAt) title="Checked {{ $rowState->checkedAt->diffForHumans() }}" @endif>
                        <x-ui.badge :tone="$rowState->tone()">{{ $rowState->label() }}</x-ui.badge>
                    </span>
                    @if($rowState->reasons)
                        <x-ui.hint label="What Shopee says">{{ implode(' ', $rowState->reasons) }}</x-ui.hint>
                    @endif
                @endif
                @if($rowState?->driftLabel() && $canManageShopee)
                    @include('partials.catalog-change-button', ['href' => route('ext.shopee.listings.edit', [$p->product_id, 'compare' => 1])])
                @endif
            </td>

            <td class="x-td-actions">
                {{-- Each action keeps its own form inside the menu, exactly
                     where the pre-redesign page had it, so no action gains or
                     loses a confirmation step: only Unlink and Delete carry
                     data-confirm, as before. The menu panel is z-index 20
                     (components/ui-table.css), above the row's stretched-link
                     overlay, so its controls stay clickable. --}}
                @if($canManageShopee || $canEditCatalog)
                @php
                    $menu = [
                        'label' => "Product {$p->product_id} actions",
                        'store' => 'Shopee',
                        'edit' => route('ext.shopee.listings.edit', $p->product_id),
                        'catalog' => $canEditCatalog ? route('products.edit', $p->product_id) : null,
                    ];
                    if ($canManageShopee) {
                        if (!$link) {
                            // One push, and it opens the listing page first when
                            // something is missing (#149); ready rows push at once.
                            $menu['push'] = ($rowState?->ready ?? false)
                                ? ['action' => route('ext.shopee.products.push_direct', $p->product_id), 'label' => 'Push to Shopee', 'attrs' => ['data-push-review' => route('ext.shopee.products.push_review', $p->product_id)]]
                                : ['href' => route('ext.shopee.listings.edit', [$p->product_id, 'back' => request()->getRequestUri()]), 'label' => 'Push to Shopee', 'title' => 'Not ready yet - the listing page names what is still missing'];
                            $menu['link'] = ['action' => route('ext.shopee.products.sync_shopee_id', $p->product_id)];
                        } else {
                            $menu['update'] = ['action' => route('ext.shopee.listings.push_update', $p->product_id), 'label' => 'Update on Shopee', 'confirm' => "Push this listing's content and settings onto the live Shopee item? Title, description, package, category, couriers and attributes are updated. Price and stock are not touched.", 'verb' => 'Push update', 'tone' => 'primary'];
                            $menu['stock'] = route('ext.shopee.products.sync_quantity', $p->product_id);
                            $menu['price'] = route('ext.shopee.products.sync_price', $p->product_id);
                            if ($rowState?->state === \App\Integrations\Listings\ListingState::LIVE) {
                                $menu['switch'] = ['action' => route('ext.shopee.listings.toggle', $p->product_id), 'label' => 'Delist from Shopee', 'fields' => ['action' => 'unlist'], 'confirm' => 'Delist this item from Shopee? Buyers stop seeing it immediately. It can be published again at any time.', 'verb' => 'Delist'];
                            } elseif ($rowState?->state === \App\Integrations\Listings\ListingState::INACTIVE) {
                                $menu['switch'] = ($parcelGaps[$p->product_id] ?? null) !== null
                                    ? ['href' => route('products.edit', $p->product_id), 'label' => 'Add weight and size, then publish', 'title' => 'Shopee will refuse to publish without ' . $parcelGaps[$p->product_id]]
                                    : ['action' => route('ext.shopee.listings.toggle', $p->product_id), 'label' => 'Publish on Shopee', 'fields' => ['action' => 'list'], 'confirm' => 'Publish this item on Shopee? Buyers see it again immediately.', 'verb' => 'Publish', 'tone' => 'primary'];
                            }
                            $menu['check'] = ['action' => route('ext.shopee.products.check'), 'label' => 'Check against Shopee', 'fields' => ['product_ids[]' => $p->product_id]];
                            $menu['unlink'] = ['action' => route('ext.shopee.products.unlink', $p->product_id), 'confirm' => 'Unlink this product from Shopee? Only the link is removed. The Shopee listing stays up and can be linked again later.'];
                            $menu['delete'] = ['action' => route('ext.shopee.products.delete', $p->product_id), 'confirm' => 'Delete this listing from Shopee? The listing is removed at the marketplace and this cannot be undone.'];
                        }
                        $menu['remove'] = ['action' => route('ext.shopee.products.remove_from_store', $p->product_id), 'label' => 'Remove from this channel', 'verb' => 'Remove', 'confirm' => $link
                            ? "Remove this product from this channel? It leaves this store's list and stops syncing. It is on Shopee (item {$link->shopee_item_id}) and stays up, still orderable, until you remove it there. Use Delete from Shopee for that."
                            : "Remove this product from this channel? It leaves this store's list and stops syncing. Nothing on Shopee is changed."];
                    }
                @endphp
                @include('partials.channel-row-menu', ['menu' => $menu])
                @endif
            </td>
        </tr>
        @if($variationCount > 0)
            {{-- The variations as rows of this table, under the same headers (partials.channel-variation-rows). --}}
            @include('partials.channel-variation-rows', ['rows' => $optRows, 'check' => $canManageShopee, 'trail' => ['cc-col-stat', 'cc-col-sync', 'x-td-actions']])
        @endif
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$products" />

@endif

</div>{{-- /.lsm-layout__main --}}
</div>{{-- /.lsm-layout --}}

{{-- Work-in-progress overlay. A push to Shopee is a multi-second round trip
     and the page has to say so, or the operator clicks again. Rides
     components/modals.css's .modal-backdrop show/hide mechanics, the same
     single `.active` class partials/confirm-modal.blade.php and the orders
     pages' own overlay already use. --}}
<div id="shopee-listings-progress" class="modal-backdrop">
    {{-- A dialog, and one that speaks. This overlay covers the page for
         several seconds while a marketplace call is in flight and carried no
         role, no name and no live region, so the only signal that anything
         was happening was the spinner - which is to say none at all for
         anyone not looking at it. --}}
    <div class="modal co-modal co-modal--sm" role="dialog" aria-modal="true"
         aria-labelledby="shopee-listings-progress-title" aria-describedby="shopee-listings-progress-note">
        <div class="cc-progress" role="status" aria-live="polite">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div class="cc-progress__title" id="shopee-listings-progress-title" data-progress-title>Talking to Shopee</div>
                <div class="cc-progress__note" id="shopee-listings-progress-note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

{{-- The Add products slide-over (#201, Draft C), manage-gated like its
     button and every route it calls. Each addition joins this store's list
     (unpushed, the push workflow unchanged) and lands in the table behind
     when the panel closes; the full catalogue page is the overflow. --}}
@if($canManageShopee)
    <x-channel.add-panel
        id="shopee-add-panel"
        :search-url="route('ext.shopee.products.catalogue_search')"
        :add-url="route('ext.shopee.products.add_to_store', ['productId' => '__PID__'])"
        sub="Search your main catalogue. Each product you add joins this store's list, ready to configure and push to Shopee."
        toggle-label="Show products already on this store"
        in-label="On this store"
        scope="this store"
        empty-default="Every enabled product is already on this store."
        empty-all="Your catalogue has no enabled products."
        list-label="Catalogue products"
        :full-url="route('ext.shopee.products.index', ['list' => 'add'])" />
@endif

</div>{{-- /.cc-page --}}

@if($canManageShopee)
    @include('ext-shopee::products._push_review_modal')
@endif

@endsection
