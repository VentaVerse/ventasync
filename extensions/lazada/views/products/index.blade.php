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

    $unmatched = $unmatchedItems ?? collect();
    $hasUnmatched = $unmatched->count() > 0;
@endphp

<div class="cc-page"
     x-data="{
        selected: [],
        ids: {{ \Illuminate\Support\Js::from(collect($listings)->pluck('id')->map(fn ($v) => (string) $v)->values()) }}
     }">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Listings</h1>
        <p class="x-page-sub">
            {{-- $paginator->total() is the FILTERED total, so a search that
                 matches nothing rendered "0 listings mapped to Lazada" on a
                 shop with two thousand of them - which reads as though the
                 mapping had been wiped. A filtered count says what it is
                 counting. --}}
            @if($hasFilters)
                {{ number_format($paginator->total()) }} of {{ number_format($totalListings) }}
                {{ $totalListings === 1 ? 'listing' : 'listings' }}
                {{ $paginator->total() === 1 ? 'matches' : 'match' }}
            @else
                {{ number_format($paginator->total()) }} {{ $paginator->total() === 1 ? 'listing' : 'listings' }} mapped to Lazada
            @endif
        </p>
    </div>
    @if($canManageLazada)
        <x-ui.button variant="primary" :href="route('ext.lazada.products.create')">New listing</x-ui.button>
    @endif
</div>

@if($canManageLazada)
{{-- Unmatched items. Lazada listings whose SKU matched nothing in the
     catalog, which is a work queue and not part of the list below, so it
     sits above the table as its own titled section. The old page showed this
     section only when it had rows and put a lone "Scan for Unmatched Lazada
     Items" button in its place when it did not, so the control moved on the
     screen depending on state. One section, one place for the button. --}}
<section class="fm-section cc-unmatched">
    <div class="fm-section__head">
        <h2 class="fm-section__title">
            @if($hasUnmatched)
                {{ number_format($unmatched->count()) }} unmatched {{ $unmatched->count() === 1 ? 'item' : 'items' }} on Lazada
            @else
                Unmatched items
            @endif
        </h2>
        <div class="fm-section__aside">
            <form method="POST" action="{{ route('ext.lazada.products.sync_unmatched') }}">
                @csrf
                <x-ui.button type="submit" size="sm">{{ $hasUnmatched ? 'Scan again' : 'Scan for unmatched items' }}</x-ui.button>
            </form>
        </div>
    </div>

    @if($hasUnmatched)
        <p class="fm-section__note">These items exist on Lazada but no catalog product has a matching SKU. Link each one to the catalog product it really is, or dismiss it if it should not be tracked here.</p>

        <x-ui.table>
            <x-slot:head>
                <tr>
                    <th scope="col">Lazada item</th>
                    <th scope="col" class="cc-col-sku">SKU</th>
                    <th scope="col" class="cc-col-chanid">Item ID</th>
                    <th scope="col" class="cc-col-link">Catalog product</th>
                    <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
                </tr>
            </x-slot:head>

            @foreach($unmatched as $ui)
                <tr>
                    <td data-label="Lazada item">
                        <div class="co-item">
                            <div class="cc-thumb cc-thumb--sm">
                                <span class="co-item__none">None</span>
                                @if($ui->image_url)
                                    <img src="{{ $ui->image_url }}" alt="" loading="lazy" decoding="async" data-thumb>
                                @endif
                            </div>
                            <div class="co-item__body">
                                <span class="co-item__name">{{ $ui->item_name ?: 'Unnamed item' }}</span>
                            </div>
                        </div>
                    </td>
                    <td class="cc-col-sku" data-label="SKU">
                        @if($ui->sku)
                            <span class="x-num">{{ $ui->sku }}</span>
                        @else
                            <span class="x-cell-muted">None</span>
                        @endif
                    </td>
                    <td class="cc-col-chanid" data-label="Item ID">
                        <span class="x-num">{{ $ui->lazada_item_id }}</span>
                    </td>
                    <td class="cc-col-link" data-label="Catalog product">
                        {{-- The typeahead's wire is unchanged: type, GET
                             search-catalog?q=, pick a result, the picked id
                             lands in this form's product_id and the form POSTs
                             to link_unmatched/{id}. What changed is where the
                             behaviour lives (a bundled module, not an inline
                             script) and how the results are built
                             (createElement/textContent, never innerHTML with a
                             product name off the API). --}}
                        <form method="POST" action="{{ route('ext.lazada.products.link_unmatched', $ui->id) }}"
                              class="cc-link"
                              data-unmatched-link
                              data-search-url="{{ route('ext.lazada.products.search_catalog') }}">
                            @csrf
                            <div class="fm-ta">
                                <input type="text" class="x-input" data-unmatched-search
                                       placeholder="Search name, model or SKU" autocomplete="off"
                                       aria-label="Search the catalog for the product this Lazada item is">
                                <div class="fm-ta__list" data-unmatched-results role="listbox" aria-label="Catalog matches"></div>
                            </div>
                            <input type="hidden" name="product_id" value="" data-unmatched-id>
                            <x-ui.button type="submit" size="sm" disabled data-unmatched-submit>Link</x-ui.button>
                        </form>
                    </td>
                    <td class="x-td-actions">
                        {{-- Dismiss is the secondary way out of this queue, so
                             it sits in the row's overflow rather than beside
                             Link. Two buttons of different weight on every row
                             is the mixed grammar this system avoids, and
                             dismissing is the rarer of the two by a wide
                             margin. --}}
                        <x-ui.menu label="Unmatched item {{ $ui->lazada_item_id }} actions">
                            <form method="POST" action="{{ route('ext.lazada.products.dismiss_unmatched', $ui->id) }}">
                                @csrf
                                <button type="submit" class="x-menu__item">Dismiss, stop tracking it</button>
                            </form>
                        </x-ui.menu>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @else
        <p class="fm-section__note">Nothing is waiting. <x-ui.hint label="What a scan does">A scan compares every item on your Lazada shop against catalog SKUs and lists anything it could not place.</x-ui.hint></p>
    @endif
</section>
@endif

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
        <div class="fm-note__body">{{ $liveTabError }}</div>
    </div>
@elseif(($liveCounts ?? null) !== null)
    @php
        $liveTabDefs = [
            'all' => 'All',
            'active' => 'Active',
            'inactive' => 'Inactive',
            'pending' => 'Pending QC',
            'violation' => 'Violation',
            'soldout' => 'Sold out',
            'deleted' => 'Deleted',
        ];
        $tabQuery = request()->except('page', 'lazada_tab');
    @endphp
    <div class="x-segment-bar">
        <nav class="x-segment" aria-label="Lazada listing status">
            @foreach($liveTabDefs as $tabKey => $tabLabel)
                @php $isActive = ($lazadaTab ?? 'all') === $tabKey; @endphp
                <a href="{{ route('ext.lazada.products.index', $tabKey === 'all' ? $tabQuery : array_merge($tabQuery, ['lazada_tab' => $tabKey])) }}"
                   class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
                   @if($isActive) aria-current="true" @endif>
                    <span>{{ $tabLabel }}</span>
                    @if($tabKey !== 'all' && isset($liveCounts[$tabKey]))
                        <span class="x-segment__count">{{ number_format($liveCounts[$tabKey]) }}</span>
                    @endif
                </a>
            @endforeach
        </nav>
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
            <option value="all" @selected($groupFilter === 'all')>All groups</option>
            <option value="none" @selected($groupFilter === 'none')>No group</option>
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
    // They used to check the unprefixed name (lazada.products.bulk_upload
    // etc.), which stopped existing when these routes gained their ext.
    // prefix - Route::has() was therefore always false and every button
    // silently fell back to a hardcoded URL that matches no registered route.
    // Caught by RouteNameSweepTest's Route::has() sweep, and kept here
    // unchanged: these five action URLs are the wire.
    $bulkUploadUrl = \Illuminate\Support\Facades\Route::has('ext.lazada.products.bulk_upload')
        ? route('ext.lazada.products.bulk_upload')
        : url('/channels/lazada/products/bulk/upload');
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
     buttons is gone. Each action is still its own form posting listing_ids[]
     to its own route, exactly as before; the module injects the ticked ids
     into whichever form is submitted. Delete keeps the transparent danger
     treatment, not a solid red fill. --}}
{{-- role="status" so the bar arriving is heard, not only seen. Ticking a
     row made five actions appear with no announcement of any kind, so a
     screen reader user selected twelve listings and was never told the count
     or that anything had opened. aria-live is on the COUNT rather than the
     whole bar: the buttons do not change, and re-reading five labels on
     every tick would be worse than silence. --}}
<div class="cc-bulkbar" x-show="selected.length > 0" x-cloak data-bulkbar role="status">
    <span class="cc-bulkbar__count" aria-live="polite"><span class="x-num" x-text="selected.length"></span> selected</span>
    <div class="cc-bulkbar__actions">
        <form method="POST" action="{{ $bulkUploadUrl }}" data-bulk-form
              data-bulk-confirm="Push the selected listings to Lazada? Anything already listed is skipped.">
            @csrf
            <x-ui.button type="submit" size="sm">Push to Lazada</x-ui.button>
        </form>
        <form method="POST" action="{{ $bulkSyncLazadaIdUrl }}" data-bulk-form>
            @csrf
            <x-ui.button type="submit" size="sm">Match Lazada IDs</x-ui.button>
        </form>
        <form method="POST" action="{{ $bulkSyncQtyUrl }}" data-bulk-form>
            @csrf
            <x-ui.button type="submit" size="sm">Push stock</x-ui.button>
        </form>
        <form method="POST" action="{{ $bulkSyncPriceUrl }}" data-bulk-form>
            @csrf
            <x-ui.button type="submit" size="sm">Push prices</x-ui.button>
        </form>
        <form method="POST" action="{{ $bulkDeleteUrl }}" data-bulk-form
              data-bulk-confirm="Delete the selected listings from Lazada? The listings are removed at the marketplace and this cannot be undone.">
            @csrf
            <x-ui.button type="submit" variant="danger" size="sm">Delete from Lazada</x-ui.button>
        </form>
    </div>
</div>
@endif

@if(count($listings) === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : 'No listings yet' }}"
                :description="$hasFilters
                    ? 'Try clearing the search, or widening the group and status filters.'
                    : 'A listing is a catalog product mapped to a Lazada category, brand and attributes. Add one, or create a product group to map a batch at once.'">
        {{-- An empty state that tells you to clear the search and offers no
             way to do it is a dead end: the only control that clears it was
             a 30x19px link back up in the filter bar, above the fold on a
             phone. The way out belongs where the reader already is. --}}
        @if($hasFilters)
        <x-slot:action>
            <x-ui.button :href="route('ext.lazada.products.index')">Clear filters</x-ui.button>
        </x-slot:action>
        @elseif($canManageLazada)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('ext.lazada.products.create')">New listing</x-ui.button>
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
            <th scope="col" class="cc-col-stat">
                <a class="x-th-sort" href="{{ $sortUrl('product_status') }}">
                    Catalog
                    @if($sort === 'product_status')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
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

    @foreach($listings as $l)
        @php
            $p = $productsById->get($l->product_id);
            $thumbSrc = $productThumbsById->get($l->product_id);
            $pName = $p ? html_entity_decode((string) ($p->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
            $pSku = $p ? (string) ($p->sku ?? '') : '';
            $pQty = $p ? (int) ($p->quantity ?? 0) : null;
            $pStatus = $p ? (int) ($p->status ?? 0) : null;
            $pMfg = $p ? (string) ($p->manufacturer_name ?? '') : '';

            $isDeleted = !is_null($l->lazada_deleted_at);
            $hasItemId = !is_null($l->lazada_item_id) && (string) $l->lazada_item_id !== '';
            $isUnlinked = !is_null($l->unlinked_at);

            $hasSyncInfo = !is_null($l->last_synced_at) || !is_null($l->last_sync_ok);
            $syncFailed = $hasSyncInfo && !is_null($l->last_sync_ok) && !$l->last_sync_ok;

            $optRows = isset($optionRowsByProductId) ? $optionRowsByProductId->get($l->product_id) : null;
            $variationCount = $optRows ? count($optRows) : 0;

            $editUrl = route('ext.lazada.products.edit', $l->id);
        @endphp
        <tr>
            @if($canManageLazada)
            <td class="cc-col-check">
                {{-- No name attribute: these boxes belong to no form. Each
                     bulk action is its own form and the module injects the
                     ticked ids into whichever one is submitted, which is the
                     pre-redesign wire unchanged. x-model keeps Alpine's count
                     in step with the DOM the module reads. --}}
                <input type="checkbox" class="cc-check" data-row-check value="{{ (string) $l->id }}"
                       x-model="selected" aria-label="Select listing {{ $l->id }}">
            </td>
            @endif

            <td class="cc-col-id" data-label="ID">
                @if($l->product_id)
                    <span class="x-num">{{ $l->product_id }}</span>
                @else
                    <span class="x-cell-muted">None</span>
                @endif
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
                        {{-- Manufacturer and group sit here rather than in
                             columns of their own. Measured, not preferred:
                             with those two as separate fixed columns the
                             remaining eleven left the Product column about
                             95px at 1440, which put "No SKU" on five lines
                             and pushed the row's overflow menu off the
                             table. This is the same fold Shopee's rebuilt
                             listings table already makes. --}}
                        <div class="co-item__meta">
                            <span class="co-item__sku">{{ $pSku ?: 'No SKU' }}</span>
                            @if($pMfg !== '')
                                <span>{{ $pMfg }}</span>
                            @endif
                            @if($l->groups->count() > 0)
                                @php $firstGroup = $l->groups->first(); @endphp
                                {{-- Which product GROUPS hold this product;
                                     same self-naming label as the Shopee
                                     listings page. --}}
                                <span class="co-item__var" @if($l->groups->count() > 1) title="Groups: {{ $l->groups->pluck('name')->implode(', ') }}" @endif>Group{{ $l->groups->count() > 1 ? 's' : '' }}: {{ $firstGroup->name }}@if($l->groups->count() > 1) and {{ $l->groups->count() - 1 }} more @endif</span>
                            @endif
                            @if($isUnlinked)
                                <span>Unlinked</span>
                            @endif
                        </div>

                        @if($variationCount > 0)
                            {{-- Variation is the user-facing word across this
                                 app and across Lazada, Shopee and TikTok. The
                                 database columns are still option_*. The old
                                 page printed these values down three separate
                                 columns, one line per variation, which only
                                 lined up while every column had the same
                                 number of lines. --}}
                            <div class="cc-vars">
                                @foreach($optRows as $or)
                                    @php
                                        $ovn = (string) ($or->option_value_name ?? '');
                                        $osku = (string) ($or->sku ?? '');
                                        $oqtyRaw = $or->quantity ?? null;
                                        $oprice = (float) ($or->absolute_price ?? 0);
                                        $oimg = trim((string) ($or->option_image ?? ''));
                                        $osrc = $oimg !== '' ? asset('storage/' . ltrim($oimg, '/')) : '';
                                    @endphp
                                    <div class="cc-var">
                                        <span class="cc-var__thumb">@if($osrc)<img src="{{ $osrc }}" alt="" loading="lazy" decoding="async">@endif</span>
                                        <span class="cc-var__name">{{ $ovn !== '' ? $ovn : 'Unnamed variation' }}</span>
                                        <span class="cc-var__sku">{{ $osku !== '' ? $osku : '' }}</span>
                                        <span class="cc-var__qty">{{ is_null($oqtyRaw) ? '' : ((int) $oqtyRaw) . ' in stock' }}</span>
                                        <span class="cc-var__price">{{ $oprice > 0 ? \App\Support\Money::base($oprice) : '' }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </td>

            <td class="cc-col-qty x-td-num" data-label="Stock">
                @if(is_null($pQty))
                    <span class="x-cell-muted">Unknown</span>
                @else
                    <span class="x-num">{{ $pQty }}</span>
                @endif
            </td>

            <td class="cc-col-price x-td-num" data-label="Price">
                @if($p)
                    <x-money :php="(float) $p->price" />
                @else
                    <span class="x-cell-muted">Unknown</span>
                @endif
            </td>

            <td class="cc-col-chanid" data-label="Lazada ID">
                @if($hasItemId)
                    <span class="x-num">{{ $l->lazada_item_id }}</span>
                @else
                    <span class="x-cell-muted">None</span>
                @endif
            </td>

            <td class="cc-col-stat" data-label="Catalog">
                @if($p === null)
                    <span class="x-cell-muted">No product</span>
                @else
                    <x-ui.badge :tone="$pStatus ? 'success' : 'neutral'">{{ $pStatus ? 'Enabled' : 'Disabled' }}</x-ui.badge>
                @endif
            </td>

            <td class="cc-col-sync" data-label="Listing">
                @php
                    /* Lazada's live answer for this row when the page-load
                       lookup got one; the stored badge otherwise. An
                       uploaded row Lazada does not recognise reads as
                       missing rather than borrowing the stored Uploaded -
                       the ERP's memory never overrules the marketplace. */
                    $rowLiveStatus = $hasItemId ? ($liveStatuses[(string) $l->lazada_item_id] ?? null) : null;
                    $rowLiveMap = [
                        'active' => ['Active', 'success'],
                        'inactive' => ['Inactive', 'neutral'],
                        'pending' => ['Pending QC', 'warning'],
                        'rejected' => ['Violation', 'danger'],
                        'sold-out' => ['Sold out', 'warning'],
                        'soldout' => ['Sold out', 'warning'],
                        'deleted' => ['Deleted on Lazada', 'danger'],
                        'missing' => ['Not found on Lazada', 'danger'],
                    ];
                @endphp
                @if($rowLiveStatus !== null && isset($rowLiveMap[$rowLiveStatus]))
                    <x-ui.badge :tone="$rowLiveMap[$rowLiveStatus][1]">{{ $rowLiveMap[$rowLiveStatus][0] }}</x-ui.badge>
                @elseif($hasItemId)
                    <x-ui.badge tone="success">Listed</x-ui.badge>
                @elseif($isDeleted)
                    <x-ui.badge tone="neutral">Deleted</x-ui.badge>
                @else
                    <x-ui.badge tone="neutral">Not listed</x-ui.badge>
                    {{-- The computed readiness (#101): the answer BEFORE the
                         upload is pressed, by the same shared computation
                         the bulk upload refuses on. --}}
                    @php $rowReady = $readinessByListingId[(int) $l->id] ?? null; @endphp
                    @if($rowReady !== null)
                        @if($rowReady['ready'])
                            <span class="cc-checked">Ready to push</span>
                        @else
                            <span class="cc-checked cc-checked--never"
                                  title="Missing: {{ implode('; ', $rowReady['missing']) }}">Missing: {{ \Illuminate\Support\Str::limit(implode(', ', $rowReady['missing']), 80) }}</span>
                        @endif
                    @endif
                @endif
            </td>

            <td class="cc-col-err" data-label="Last sync">
                @if($syncFailed)
                    @php
                        $reason = trim((string) ($l->last_sync_error_message ?? ''));
                        // 120, matching the group page. Str::limit at 80 cut
                        // the actionable half off every Lazada validation
                        // message and offered no way to reach the rest, so
                        // the one column that only fills when something is
                        // wrong could not say what was wrong.
                        $clipped = Str::limit($reason, 120);
                    @endphp

                    @if($reason === '')
                        <span class="cc-err">
                            @if($l->last_sync_error_code)<span class="cc-err__code">{{ $l->last_sync_error_code }}</span> @endif
                            Failed
                        </span>
                    @elseif($clipped === $reason)
                        <span class="cc-err">
                            @if($l->last_sync_error_code)<span class="cc-err__code">{{ $l->last_sync_error_code }}</span> @endif
                            {{ $reason }}
                        </span>
                    @else
                        {{-- <details>, not a tooltip, and the same component
                             the group page already uses: a title attribute
                             waits a second, never appears on a phone, and
                             cannot be reached from the keyboard. --}}
                        <details class="cc-reason">
                            <summary class="cc-reason__summary">
                                <span class="cc-err cc-reason__clip">
                                    @if($l->last_sync_error_code)<span class="cc-err__code">{{ $l->last_sync_error_code }}</span> @endif
                                    {{ $clipped }}
                                </span>
                                <span class="cc-reason__toggle">
                                    <x-ui.icon name="chevron-down" size="12" class="cc-reason__chevron" />
                                    <span class="cc-reason__label">Full reason</span>
                                </span>
                            </summary>
                            <p class="cc-err cc-reason__full">{{ $reason }}</p>
                        </details>
                    @endif
                    @if($l->last_synced_at)
                        <span class="cc-when">{{ \Carbon\Carbon::parse($l->last_synced_at)->diffForHumans() }}</span>
                    @endif
                @elseif($hasSyncInfo && $l->last_sync_ok)
                    <span class="x-cell-muted">Succeeded</span>
                    @if($l->last_synced_at)
                        <span class="cc-when">{{ \Carbon\Carbon::parse($l->last_synced_at)->diffForHumans() }}</span>
                    @endif
                @else
                    <span class="x-cell-muted">Never</span>
                @endif
            </td>

            <td class="x-td-actions">
                @if($canManageLazada)
                {{-- Each action keeps its own form inside the menu, exactly
                     where the pre-redesign page had it, so no action gains or
                     loses a confirmation step: Upload, Unlink, Delete and
                     Remove carry data-confirm, the three sync actions do not,
                     as before. The menu panel is z-index 20
                     (components/ui-table.css), above the row's stretched-link
                     overlay, so its controls stay clickable. --}}
                @php
                    // What the operator is about to act on, for the confirms
                    // below. The bulk path renders six product names and a
                    // count; the single-row path said "this listing" while
                    // the row it came from sat behind a backdrop - and it is
                    // the path taken ten times a day, half-attentive, from a
                    // seven-item menu.
                    $rowLabel = $pName !== '' ? $pName : ($pSku !== '' ? $pSku : 'listing ' . $l->id);
                @endphp
                <x-ui.menu label="Listing {{ $l->id }} actions">
                    {{-- --primary exists for exactly this and was used in
                         one file out of four: the action that always works,
                         so nobody reads seven items to find it. --}}
                    <a class="x-menu__item x-menu__item--primary" href="{{ $editUrl }}">Edit listing</a>

                    @if(!$hasItemId)
                        <form method="POST" action="{{ route('ext.lazada.products.upload', $l->id) }}" data-slow-action
                              data-confirm="Upload {{ $rowLabel }} to Lazada?" data-confirm-tone="primary" data-confirm-verb="Push to Lazada">
                            @csrf
                            <button type="submit" class="x-menu__item">Upload to Lazada</button>
                        </form>
                        <form method="POST" action="{{ route('ext.lazada.products.sync_lazada_id', $l->id) }}" data-slow-action>
                            @csrf
                            <button type="submit" class="x-menu__item">Match Lazada ID</button>
                        </form>
                    @endif

                    @if(!$isUnlinked && $hasItemId)
                        <form method="POST" action="{{ route('ext.lazada.products.sync_quantity', $l->id) }}" data-slow-action>
                            @csrf
                            <button type="submit" class="x-menu__item">Push stock</button>
                        </form>
                        <form method="POST" action="{{ route('ext.lazada.products.sync_price', $l->id) }}" data-slow-action>
                            @csrf
                            <button type="submit" class="x-menu__item">Push price</button>
                        </form>
                        <div class="x-menu__sep"></div>
                        <form method="POST" action="{{ route('ext.lazada.products.unlink', $l->id) }}" data-slow-action
                              data-confirm="Unlink {{ $rowLabel }} from Lazada? Only the link is removed. The Lazada listing stays up and syncing stops." data-confirm-verb="Unlink">
                            @csrf
                            <button type="submit" class="x-menu__item x-menu__item--danger">Unlink from Lazada</button>
                        </form>
                        <form method="POST" action="{{ route('ext.lazada.products.delete_lazada', $l->id) }}" data-slow-action
                              data-confirm="Delete {{ $rowLabel }} from Lazada? The listing is removed at the marketplace and this cannot be undone." data-confirm-verb="Delete from Lazada">
                            @csrf
                            <button type="submit" class="x-menu__item x-menu__item--danger">Delete from Lazada</button>
                        </form>
                    @endif

                    @if($isUnlinked)
                        <div class="x-menu__sep"></div>
                        <form method="POST" action="{{ route('ext.lazada.products.remove', $l->id) }}" data-slow-action
                              data-confirm="Remove {{ $rowLabel }} from the local list? Nothing on Lazada is deleted." data-confirm-verb="Remove from list">
                            @csrf
                            <button type="submit" class="x-menu__item x-menu__item--danger">Remove from this list</button>
                        </form>
                    @endif
                </x-ui.menu>
                @endif
            </td>
        </tr>
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

</div>{{-- /.cc-page --}}
@endsection
