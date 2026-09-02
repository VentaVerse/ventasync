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
        ids: {{ \Illuminate\Support\Js::from(collect($products->items())->filter(fn ($r) => $r->listing_id)->pluck('listing_id')->map(fn ($v) => (string) $v)->values()) }}
     }">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Listings</h1>
        <p class="x-page-sub">
            {{-- The table is the CATALOGUE now (Phase C, #184): every
                 product appears, listed or not, so the subtitle carries the
                 two numbers a seller actually asks for. A filtered count
                 says what it is counting. --}}
            @if($hasFilters)
                {{ number_format($paginator->total()) }} {{ $paginator->total() === 1 ? 'product matches' : 'products match' }}
            @else
                {{ number_format($catalogueTotal) }} {{ $catalogueTotal === 1 ? 'product' : 'products' }} in your catalogue
                &middot; {{ number_format($listedTotal) }} listed on Lazada
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

@php $orphans = $orphanListings ?? collect(); @endphp
@if($orphans->isNotEmpty())
{{-- Orphan listings (Phase C, #184). The table below is the catalogue, so
     a listing whose catalog product was deleted has no row to live on -
     but it may still be live on Lazada, taking orders for a product this
     system no longer knows. That is a work queue, not a footnote. --}}
<section class="fm-section cc-unmatched">
    <div class="fm-section__head">
        <h2 class="fm-section__title">{{ number_format($orphans->count()) }} {{ $orphans->count() === 1 ? 'listing points' : 'listings point' }} at a deleted catalog product</h2>
    </div>
    <p class="fm-section__note">Each of these was mapped to a catalog product that no longer exists. If one is still live on Lazada, delete it there or unlink it here; once unlinked, it can be removed from this list.</p>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th scope="col" class="cc-col-chanid">Lazada ID</th>
                <th scope="col" class="cc-col-link">Catalog product</th>
                <th scope="col" class="cc-col-sync">Listing</th>
                <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
            </tr>
        </x-slot:head>

        @foreach($orphans as $ol)
            @php
                $oHasItem = !empty($ol->lazada_item_id);
                $oUnlinked = !is_null($ol->unlinked_at);
                $oState = $ol->product_id ? (($orphanStates ?? [])[(int) $ol->product_id] ?? null) : null;
                $oLabel = 'Lazada item ' . ($ol->lazada_item_id ?: '#' . $ol->id);
            @endphp
            <tr>
                <td class="cc-col-chanid" data-label="Lazada ID">
                    @if($oHasItem)
                        <span class="x-num">{{ $ol->lazada_item_id }}</span>
                    @else
                        <span class="x-cell-muted">None</span>
                    @endif
                </td>
                <td class="cc-col-link" data-label="Catalog product">
                    <span class="x-cell-muted">No product{{ $ol->product_id ? ' (was #' . $ol->product_id . ')' : '' }}</span>
                </td>
                <td class="cc-col-sync" data-label="Listing">
                    {{-- The same engine words as the main table, never a
                         private map. --}}
                    @if($oState && $oState->state !== \App\Integrations\Listings\ListingState::UNKNOWN && $oState->state !== \App\Integrations\Listings\ListingState::NOT_LISTED)
                        <x-ui.badge :tone="$oState->tone()">{{ $oState->label() }}</x-ui.badge>
                    @elseif($oHasItem)
                        <x-ui.badge tone="neutral">Not checked yet</x-ui.badge>
                    @else
                        <x-ui.badge tone="neutral">Not listed</x-ui.badge>
                    @endif
                </td>
                <td class="x-td-actions">
                    @if($canManageLazada)
                    <x-ui.menu label="{{ $oLabel }} actions">
                        @if($oHasItem && !$oUnlinked)
                            <form method="POST" action="{{ route('ext.lazada.products.sync_quantity', $ol->id) }}" data-slow-action>
                                @csrf
                                <button type="submit" class="x-menu__item">Push stock</button>
                            </form>
                            <form method="POST" action="{{ route('ext.lazada.products.sync_price', $ol->id) }}" data-slow-action>
                                @csrf
                                <button type="submit" class="x-menu__item">Push price</button>
                            </form>
                            <div class="x-menu__sep"></div>
                            <form method="POST" action="{{ route('ext.lazada.products.unlink', $ol->id) }}" data-slow-action
                                  data-confirm="Unlink {{ $oLabel }}? Only the link is removed. The Lazada listing stays up and syncing stops." data-confirm-verb="Unlink">
                                @csrf
                                <button type="submit" class="x-menu__item x-menu__item--danger">Unlink from Lazada</button>
                            </form>
                            <form method="POST" action="{{ route('ext.lazada.products.delete_lazada', $ol->id) }}" data-slow-action
                                  data-confirm="Delete {{ $oLabel }} from Lazada? The listing is removed at the marketplace and this cannot be undone." data-confirm-verb="Delete from Lazada">
                                @csrf
                                <button type="submit" class="x-menu__item x-menu__item--danger">Delete from Lazada</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('ext.lazada.products.remove', $ol->id) }}" data-slow-action
                                  data-confirm="Remove {{ $oLabel }} from the local list? Nothing on Lazada is deleted." data-confirm-verb="Remove from list">
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
                <span class="x-segment-group__k">Your catalogue</span>
                <nav class="x-segment" aria-label="Catalogue">
                    <a href="{{ route('ext.lazada.products.index', $tabQuery) }}"
                       class="x-segment__item {{ ($lazadaTab ?? 'all') === 'all' ? 'is-active' : '' }}"
                       @if(($lazadaTab ?? 'all') === 'all') aria-current="true" @endif>
                        <span>All products</span>
                        <span class="x-segment__count">{{ number_format((int) ($catalogueTotal ?? $paginator->total())) }}</span>
                    </a>
                </nav>
            </div>
            <div class="x-segment-group">
                <span class="x-segment-group__k">On Lazada</span>
                <nav class="x-segment" aria-label="Lazada listing status">
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
                    <form method="POST" action="{{ route('ext.lazada.products.refresh_status') }}" class="x-segment-group__refresh" data-slow-action>
                        @csrf
                        <x-ui.button type="submit" size="sm">Refresh from Lazada</x-ui.button>
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
    <span class="cc-bulkbar__count" aria-live="polite"><span class="x-num" x-text="selected.length"></span> selected <span class="cc-bulkbar__note" data-bulk-skipped></span></span>
    <div class="cc-bulkbar__actions">
        {{-- Deactivate / Activate for the selection (the owner, 2026-08-28). Each
             form acts only on the ticked rows its verb applies to, per the
             mirrored status; the count is in the label and the rest of the
             selection is named as skipped beside the count. --}}
        <form method="POST" action="{{ route('ext.lazada.products.bulk_toggle') }}" data-bulk-form
              data-bulk-verb="deactivate" data-verb-codes="active" data-verb-label="Deactivate :n on Lazada"
              data-bulk-confirm="Deactivate the selected items on Lazada? Buyers stop seeing them. They stay here and can be activated again at any time." hidden>
            @csrf
            <input type="hidden" name="action" value="deactivate">
            <x-ui.button type="submit" size="sm">Deactivate on Lazada</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.lazada.products.bulk_toggle') }}" data-bulk-form
              data-bulk-verb="activate" data-verb-codes="inactive" data-verb-label="Activate :n on Lazada"
              data-bulk-confirm="Activate the selected items on Lazada? Buyers see them again once Lazada applies it." hidden>
            @csrf
            <input type="hidden" name="action" value="activate">
            <x-ui.button type="submit" size="sm">Activate on Lazada</x-ui.button>
        </form>
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

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : (($lazadaTab ?? 'all') !== 'all' ? 'Nothing holds this status' : 'No products yet') }}"
                :description="$hasFilters
                    ? 'Try clearing the search, or widening the group and status filters.'
                    : ((($lazadaTab ?? 'all') !== 'all')
                        ? 'No listing on Lazada holds this status right now.'
                        : 'Every catalog product appears here, ready to list on Lazada. Add your first product and it will be waiting.')">
        {{-- An empty state that tells you to clear the search and offers no
             way to do it is a dead end: the only control that clears it was
             a 30x19px link back up in the filter bar, above the fold on a
             phone. The way out belongs where the reader already is. --}}
        @if($hasFilters)
        <x-slot:action>
            <x-ui.button :href="route('ext.lazada.products.index')">Clear filters</x-ui.button>
        </x-slot:action>
        @elseif(($lazadaTab ?? 'all') !== 'all')
        <x-slot:action>
            <x-ui.button :href="route('ext.lazada.products.index')">Back to all products</x-ui.button>
        </x-slot:action>
        @elseif($canEditCatalog)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('products.create')">Add a product</x-ui.button>
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
            $editUrl = $listing
                ? route('ext.lazada.products.edit', $listing->id)
                : route('ext.lazada.products.create', ['product_id' => $pid]);

            // What the operator is about to act on, for the confirms below.
            $rowLabel = $pName !== '' ? $pName : ($pSku !== '' ? $pSku : 'product ' . $pid);
        @endphp
        <tr>
            @if($canManageLazada)
            <td class="cc-col-check">
                {{-- Bulk actions post listing_ids[], so only a product that
                     HAS a listing row can be ticked. No name attribute: the
                     module injects the ticked ids into whichever bulk form
                     is submitted. data-live carries the raw mirror word for
                     the bulk toggle's verb filter; it is a wire, the
                     rendered words come from the engine. --}}
                @if($listing)
                <input type="checkbox" class="cc-check" data-row-check value="{{ (string) $listing->id }}"
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
                                <span class="co-item__var" @if($listing->groups->count() > 1) title="Groups: {{ $listing->groups->pluck('name')->implode(', ') }}" @endif>Group{{ $listing->groups->count() > 1 ? 's' : '' }}: {{ $firstGroup->name }}@if($listing->groups->count() > 1) and {{ $listing->groups->count() - 1 }} more @endif</span>
                            @endif
                            @if($isUnlinked)
                                <span>Unlinked</span>
                            @endif
                        </div>

                        @if($variationCount > 0)
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
                    {{-- The answer BEFORE the push is pressed (#101), from
                         the same computation every other surface uses. --}}
                    @if($rowState->ready)
                        <span class="cc-checked">Ready to push</span>
                    @else
                        <span class="cc-checked"><x-ui.badge tone="warning">Not ready</x-ui.badge>
                            <x-ui.hint label="What is missing before a push">Missing: {{ $rowState->missingSummary() }}</x-ui.hint></span>
                    @endif
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
                @if($syncFailed)
                    @php
                        $reason = trim((string) ($row->last_sync_error_message ?? ''));
                        // 120, matching the group page: at 80 the actionable
                        // half of a Lazada validation message was cut off.
                        $clipped = Str::limit($reason, 120);
                    @endphp

                    @if($reason === '')
                        <span class="cc-err">
                            @if($row->last_sync_error_code)<span class="cc-err__code">{{ $row->last_sync_error_code }}</span> @endif
                            Failed
                        </span>
                    @elseif($clipped === $reason)
                        <span class="cc-err">
                            @if($row->last_sync_error_code)<span class="cc-err__code">{{ $row->last_sync_error_code }}</span> @endif
                            {{ $reason }}
                        </span>
                    @else
                        <details class="cc-reason">
                            <summary class="cc-reason__summary">
                                <span class="cc-err cc-reason__clip">
                                    @if($row->last_sync_error_code)<span class="cc-err__code">{{ $row->last_sync_error_code }}</span> @endif
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
                    @if($row->last_synced_at)
                        <span class="cc-when">{{ \Carbon\Carbon::parse($row->last_synced_at)->diffForHumans() }}</span>
                    @endif
                @elseif($hasSyncInfo && $row->last_sync_ok)
                    <span class="x-cell-muted">Succeeded</span>
                    @if($row->last_synced_at)
                        <span class="cc-when">{{ \Carbon\Carbon::parse($row->last_synced_at)->diffForHumans() }}</span>
                    @endif
                @else
                    <span class="x-cell-muted">Never</span>
                @endif
            </td>

            <td class="x-td-actions">
                @if($canManageLazada)
                {{-- The menu derives from the SAME state the badge wears
                     (#188). The old ladder keyed on three booleans and a
                     raw status, and rendered both toggles with one hidden -
                     so a row could say one thing and offer the actions of
                     another. Now: what the badge says decides what the
                     menu holds, and only the applicable toggle exists. --}}
                <x-ui.menu label="{{ $rowLabel }} actions">
                    @if(!$listing)
                        {{-- No listing row yet: the one action is to begin.
                             The create form opens with this product picked. --}}
                        <a class="x-menu__item x-menu__item--primary" href="{{ $editUrl }}">Set up on Lazada</a>
                    @else
                        <a class="x-menu__item x-menu__item--primary" href="{{ $editUrl }}">Edit listing</a>

                        @if(!$hasItemId)
                            {{-- One push, and it opens the listing page first
                                 (#149), where the push button sits under what
                                 is about to go up. --}}
                            <a class="x-menu__item" href="{{ $editUrl }}">Push to Lazada</a>
                            <form method="POST" action="{{ route('ext.lazada.products.sync_lazada_id', $listing->id) }}" data-slow-action>
                                @csrf
                                <button type="submit" class="x-menu__item">Match Lazada ID</button>
                            </form>
                        @endif

                        @if($hasItemId && !$isUnlinked)
                            <form method="POST" action="{{ route('ext.lazada.products.sync_quantity', $listing->id) }}" data-slow-action>
                                @csrf
                                <button type="submit" class="x-menu__item">Push stock</button>
                            </form>
                            <form method="POST" action="{{ route('ext.lazada.products.sync_price', $listing->id) }}" data-slow-action>
                                @csrf
                                <button type="submit" class="x-menu__item">Push price</button>
                            </form>
                            {{-- The marketplace's own on/off, from the state
                                 the badge already wears: a live listing can
                                 be deactivated, an unlisted one activated,
                                 and any other state offers neither. --}}
                            @if($rowState?->state === $stateClass::LIVE)
                                <form method="POST" action="{{ route('ext.lazada.listings.toggle', $listing->id) }}" data-slow-action
                                      data-confirm="Deactivate {{ $rowLabel }} on Lazada? Buyers stop seeing it. It can be activated again at any time." data-confirm-verb="Deactivate">
                                    @csrf
                                    <input type="hidden" name="action" value="deactivate">
                                    <button type="submit" class="x-menu__item">Deactivate on Lazada</button>
                                </form>
                            @elseif($rowState?->state === $stateClass::INACTIVE)
                                <form method="POST" action="{{ route('ext.lazada.listings.toggle', $listing->id) }}" data-slow-action
                                      data-confirm="Activate {{ $rowLabel }} on Lazada? Buyers see it again once Lazada applies it." data-confirm-tone="primary" data-confirm-verb="Activate">
                                    @csrf
                                    <input type="hidden" name="action" value="activate">
                                    <button type="submit" class="x-menu__item">Activate on Lazada</button>
                                </form>
                            @endif
                            <div class="x-menu__sep"></div>
                            <form method="POST" action="{{ route('ext.lazada.products.unlink', $listing->id) }}" data-slow-action
                                  data-confirm="Unlink {{ $rowLabel }} from Lazada? Only the link is removed. The Lazada listing stays up and syncing stops." data-confirm-verb="Unlink">
                                @csrf
                                <button type="submit" class="x-menu__item x-menu__item--danger">Unlink from Lazada</button>
                            </form>
                            <form method="POST" action="{{ route('ext.lazada.products.delete_lazada', $listing->id) }}" data-slow-action
                                  data-confirm="Delete {{ $rowLabel }} from Lazada? The listing is removed at the marketplace and this cannot be undone." data-confirm-verb="Delete from Lazada">
                                @csrf
                                <button type="submit" class="x-menu__item x-menu__item--danger">Delete from Lazada</button>
                            </form>
                        @endif

                        @if($isUnlinked)
                            <div class="x-menu__sep"></div>
                            <form method="POST" action="{{ route('ext.lazada.products.remove', $listing->id) }}" data-slow-action
                                  data-confirm="Remove {{ $rowLabel }} from the local list? Nothing on Lazada is deleted." data-confirm-verb="Remove from list">
                                @csrf
                                <button type="submit" class="x-menu__item x-menu__item--danger">Remove from this list</button>
                            </form>
                        @endif
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
