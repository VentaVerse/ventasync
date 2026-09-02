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

    $unmatched = $unmatchedItems ?? collect();
    $hasUnmatched = $unmatched->count() > 0;
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
            {{-- A filtered total must not wear an unfiltered total's wording:
                 a search matching nothing rendered "0 products in scope for
                 Shopee" on a shop with two thousand. --}}
            @if($hasFilters)
                {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product' : 'products' }}
                {{ $products->total() === 1 ? 'matches' : 'match' }}
            @else
                {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product' : 'products' }} in scope for Shopee
            @endif
        </p>
    </div>
    @if($canManageShopee)
        <x-ui.button variant="primary" :href="route('ext.shopee.products.add')">New listing</x-ui.button>
    @endif
</div>

@if($canManageShopee)
{{-- Unmatched items. Shopee listings whose SKU matched nothing in the
     catalog, which is a work queue and not part of the list below, so it
     sits above the table as its own titled section. Rebuilding the cache
     and re-scanning are the two machine actions that feed it, so they live
     here beside it rather than in a row of page-header buttons. --}}
<section class="fm-section cc-unmatched">
    <div class="fm-section__head">
        <h2 class="fm-section__title">
            @if($hasUnmatched)
                {{ number_format($unmatched->count()) }} unmatched {{ $unmatched->count() === 1 ? 'item' : 'items' }} on Shopee
            @else
                Unmatched items
            @endif
        </h2>
        <div class="fm-section__aside">
            <form method="POST" action="{{ route('ext.shopee.products.sync_unmatched') }}">
                @csrf
                <x-ui.button type="submit" size="sm">{{ $hasUnmatched ? 'Scan again' : 'Scan for unmatched items' }}</x-ui.button>
            </form>
            <form method="POST" action="{{ route('ext.shopee.products.rebuild_cache') }}"
                  data-confirm="Rebuild the Shopee item cache? This reads every item on your Shopee shop and can take a minute." data-confirm-tone="primary" data-confirm-verb="Rebuild cache">
                @csrf
                <x-ui.button type="submit" size="sm">Rebuild item cache</x-ui.button>
            </form>
        </div>
    </div>

    @if($hasUnmatched)
        <p class="fm-section__note">These items exist on Shopee but no catalog product has a matching SKU. Link each one to the catalog product it really is, or dismiss it if it should not be tracked here.</p>

        <x-ui.table>
            <x-slot:head>
                <tr>
                    <th scope="col">Shopee item</th>
                    <th scope="col" class="cc-col-sku">SKU</th>
                    <th scope="col" class="cc-col-chanid">Item ID</th>
                    <th scope="col" class="cc-col-link">Catalog product</th>
                    <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
                </tr>
            </x-slot:head>

            @foreach($unmatched as $ui)
                <tr>
                    <td data-label="Shopee item">
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
                        <span class="x-num">{{ $ui->shopee_item_id }}</span>
                        @if($ui->shopee_model_id)
                            <span class="cc-sub cc-sub--mono">Variation {{ $ui->shopee_model_id }}</span>
                        @endif
                    </td>
                    <td class="cc-col-link" data-label="Catalog product">
                        {{-- The typeahead's wire is unchanged: type, GET
                             search-catalog?q=, pick a result, the picked id
                             lands in this form's product_id and the form
                             POSTs to link_unmatched/{id}. What changed is
                             where the behaviour lives (a bundled module, not
                             an inline script) and how the results are built
                             (createElement/textContent, never innerHTML with
                             a product name off the API). --}}
                        <form method="POST" action="{{ route('ext.shopee.products.link_unmatched', $ui->id) }}"
                              class="cc-link"
                              data-unmatched-link
                              data-search-url="{{ route('ext.shopee.products.search_catalog') }}">
                            @csrf
                            <div class="fm-ta">
                                <input type="text" class="x-input" data-unmatched-search
                                       placeholder="Search name, model or SKU" autocomplete="off"
                                       aria-label="Search the catalog for the product this Shopee item is">
                                <div class="fm-ta__list" data-unmatched-results role="listbox" aria-label="Catalog matches"></div>
                            </div>
                            <input type="hidden" name="product_id" value="" data-unmatched-id>
                            <x-ui.button type="submit" size="sm" disabled data-unmatched-submit>Link</x-ui.button>
                        </form>
                    </td>
                    <td class="x-td-actions">
                        {{-- Dismiss is the secondary way out of this queue, so it
                             sits in the row's overflow rather than beside Link.
                             Two buttons of different weight on every row is the
                             mixed grammar this system avoids, and dismissing is
                             the rarer of the two by a wide margin. --}}
                        <x-ui.menu label="Unmatched item {{ $ui->shopee_item_id }} actions">
                            <form method="POST" action="{{ route('ext.shopee.products.dismiss_unmatched', $ui->id) }}">
                                @csrf
                                <button type="submit" class="x-menu__item">Dismiss, stop tracking it</button>
                            </form>
                        </x-ui.menu>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @else
        <p class="fm-section__note">Nothing is waiting. <x-ui.hint label="What a scan does">A scan compares every item on your Shopee shop against catalog SKUs and lists anything it could not place.</x-ui.hint></p>
    @endif
</section>
@endif

{{-- The seller center's tabs, answered by Shopee on this page load
     (quest 1 slice 2). All is the whole catalog view; the other four
     narrow the table to the items Shopee holds in that status right now.
     Absent entirely when Shopee cannot be asked - a tab bar that cannot
     answer is worse than none, and the note below says why it is gone. --}}
@if(($liveTabError ?? null) !== null)
    <div class="fm-note fm-note--warn" role="status">
        <div class="fm-note__body">@include('partials.channel-answer', ['channel' => 'Shopee', 'raw' => $liveTabError, 'settingsRoute' => 'ext.shopee.index'])</div>
    </div>
@elseif(($liveCounts ?? null) !== null)
    @php
        $liveTabDefs = [
            'live' => 'Live',
            'unlisted' => 'Unlisted',
            'violation' => 'Violation',
            'reviewing' => 'Under review',
        ];
        $tabQuery = request()->except('page', 'shopee_tab');
    @endphp
    {{-- Two questions on one strip, each named (the owner, 2026-08-28): the
         catalogue's own total, whether pushed or not, and Shopee's buckets.
         The bucket counts and every row's chip come from the mirror on
         the link rows, refreshed every 30 minutes, on demand here, and by
         every push, unlist and relist. The strip says when. --}}
    <div class="x-segment-bar">
        <div class="x-segment-groups">
            <div class="x-segment-group">
                <span class="x-segment-group__k">Your catalogue</span>
                <nav class="x-segment" aria-label="Catalogue">
                    <a href="{{ route('ext.shopee.products.index', $tabQuery) }}"
                       class="x-segment__item {{ ($shopeeTab ?? 'all') === 'all' ? 'is-active' : '' }}"
                       @if(($shopeeTab ?? 'all') === 'all') aria-current="true" @endif>
                        <span>All products</span>
                        <span class="x-segment__count">{{ number_format((int) ($catalogueTotal ?? $products->total())) }}</span>
                    </a>
                </nav>
            </div>
            <div class="x-segment-group">
                <span class="x-segment-group__k">On Shopee</span>
                <nav class="x-segment" aria-label="Shopee listing status">
                    @foreach($liveTabDefs as $tabKey => $tabLabel)
                        @php $isActive = ($shopeeTab ?? 'all') === $tabKey; @endphp
                        <a href="{{ route('ext.shopee.products.index', array_merge($tabQuery, ['shopee_tab' => $tabKey])) }}"
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
                @if($canManageShopee)
                    <form method="POST" action="{{ route('ext.shopee.products.refresh_status') }}" class="x-segment-group__refresh" data-slow-action>
                        @csrf
                        <x-ui.button type="submit" size="sm">Refresh from Shopee</x-ui.button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endif

{{-- One filter row. The old page had a five-column CSS grid of labelled
     selects inside a card; the selects here submit on change (data-autosubmit,
     resources/js/list-filters.js) so there is no Filter button to press, and
     Clear is a link because it is navigation. --}}
<form method="GET" action="{{ route('ext.shopee.products.index') }}" class="x-filters" id="shopee-listings-filter">
    @if(($shopeeTab ?? 'all') !== 'all')
        <input type="hidden" name="shopee_tab" value="{{ $shopeeTab }}">
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
            <option value="all" @selected($groupFilter === 'all')>All groups</option>
            <option value="none" @selected($groupFilter === 'none')>No group</option>
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

    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="sync_status" class="x-input" data-autosubmit aria-label="Filter by Shopee listing status">
            <option value="all" @selected($syncStatus === 'all')>Listed or not</option>
            <option value="listed" @selected($syncStatus === 'listed')>Listed on Shopee</option>
            <option value="not_listed" @selected($syncStatus === 'not_listed')>Not listed</option>
            {{-- Where the two above say where a product IS, this says what
                 happened to it. It is the reason to open this page. --}}
            <option value="error" @selected($syncStatus === 'error')>Last sync failed</option>
            {{-- The computed answer (#101): pushable right now, or not. --}}
            <option value="ready" @selected($syncStatus === 'ready')>Ready to push</option>
            <option value="not_ready" @selected($syncStatus === 'not_ready')>Not ready to push</option>
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
<div class="cc-bulkbar" x-show="selected.length > 0" x-cloak data-bulkbar role="status">
    <span class="cc-bulkbar__count" aria-live="polite"><span class="x-num" x-text="selected.length"></span> selected <span class="cc-bulkbar__note" data-bulk-skipped></span></span>
    <div class="cc-bulkbar__actions">
        {{-- Opens the combined review form for the selection (#151); the
             form is the confirmation, so this carries none. --}}
        <form method="GET" action="{{ route('ext.shopee.products.bulk_push_form') }}" data-bulk-form>
            <x-ui.button type="submit" size="sm">Push to Shopee</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.shopee.products.sync_ids') }}" data-bulk-form>
            @csrf
            <x-ui.button type="submit" size="sm">Match Shopee IDs</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.shopee.products.bulk_sync_quantity') }}" data-bulk-form>
            @csrf
            <x-ui.button type="submit" size="sm">Push stock</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.shopee.products.bulk_sync_price') }}" data-bulk-form>
            @csrf
            <x-ui.button type="submit" size="sm">Push prices</x-ui.button>
        </form>
        {{-- Unlist / Relist for the selection (the owner, 2026-08-28). Each
             form acts only on the ticked rows its verb applies to, per the
             live status Shopee answered; the count is in the label and the
             rest of the selection is named as skipped beside the count. --}}
        <form method="POST" action="{{ route('ext.shopee.products.bulk_toggle') }}" data-bulk-form
              data-bulk-verb="unlist" data-verb-codes="NORMAL" data-verb-label="Unlist :n from Shopee"
              data-bulk-confirm="Unlist the selected items from Shopee? Buyers stop seeing them immediately. They stay here and can be relisted at any time." hidden>
            @csrf
            <input type="hidden" name="action" value="unlist">
            <x-ui.button type="submit" size="sm">Unlist from Shopee</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.shopee.products.bulk_toggle') }}" data-bulk-form
              data-bulk-verb="list" data-verb-codes="UNLIST" data-verb-label="Relist :n on Shopee"
              data-bulk-confirm="Relist the selected items on Shopee? Buyers see them again immediately." hidden>
            @csrf
            <input type="hidden" name="action" value="list">
            <x-ui.button type="submit" size="sm">Relist on Shopee</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.shopee.products.bulk_delete') }}" data-bulk-form
              data-bulk-confirm="Delete the selected products from Shopee? The listings are removed at the marketplace and this cannot be undone.">
            @csrf
            <x-ui.button type="submit" variant="danger" size="sm">Delete from Shopee</x-ui.button>
        </form>
    </div>
</div>
@endif

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : 'No products in scope yet' }}"
                :description="$hasFilters
                    ? 'Try clearing the search, or widening the group and status filters.'
                    : 'This list shows catalog products that belong to a Shopee product group, plus anything already linked to a Shopee listing. Create a product group to bring products into scope.'">
        {{-- An empty state that says "try clearing the search" and offers no
             way to do it is a dead end. The way out belongs where the reader
             already is. --}}
        @if($hasFilters)
        <x-slot:action>
            <x-ui.button :href="route('ext.shopee.products.index')">Clear filters</x-ui.button>
        </x-slot:action>
        @else
        <x-slot:action>
            <x-ui.button :href="route('ext.shopee.product-groups.index')">Go to Product Groups</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
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
            <th scope="col" class="cc-col-chanid">Shopee ID</th>
            <th scope="col" class="cc-col-stat">Catalog</th>
            <th scope="col" class="cc-col-sync">Listing</th>
            <th scope="col" class="cc-col-err">Last sync</th>
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
        @endphp
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
                        <a class="x-row-link co-item__name" href="{{ route('products.edit', $p->product_id) }}">{{ $p->name ?: 'Unnamed product' }}</a>
                        <div class="co-item__meta">
                            <span class="co-item__sku">{{ $p->sku ?: 'No SKU' }}</span>
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
                                <span class="co-item__var" @if(count($pGroups) > 1) title="Groups: {{ implode(', ', $pGroups) }}" @endif>Group{{ count($pGroups) > 1 ? 's' : '' }}: {{ $pGroups[0] }}@if(count($pGroups) > 1) and {{ count($pGroups) - 1 }} more @endif</span>
                            @endif
                        </div>

                        @if($variationCount > 0)
                            {{-- Variation is the user-facing word across this
                                 app and across Shopee, Lazada and TikTok. The
                                 database columns are still option_*. --}}
                            <div class="cc-vars">
                                @foreach($optRows as $or)
                                    @php
                                        $ovn = (string) ($or->option_value_name ?? '');
                                        $osku = (string) ($or->sku ?? '');
                                        $oqty = (int) ($or->quantity ?? 0);
                                        $oprice = (float) ($or->absolute_price ?? 0);
                                        $oimg = trim((string) ($or->option_image ?? ''));
                                        $osrc = $oimg !== '' ? asset('storage/' . ltrim($oimg, '/')) : '';
                                    @endphp
                                    <div class="cc-var">
                                        <span class="cc-var__thumb">@if($osrc)<img src="{{ $osrc }}" alt="" loading="lazy" decoding="async">@endif</span>
                                        <span class="cc-var__name">{{ $ovn !== '' ? $ovn : 'Unnamed variation' }}</span>
                                        <span class="cc-var__sku">{{ $osku !== '' ? $osku : '' }}</span>
                                        <span class="cc-var__qty">{{ $oqty }} in stock</span>
                                        <span class="cc-var__price">{{ $oprice > 0 ? \App\Support\Money::base($oprice) : '' }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </td>

            <td class="cc-col-qty x-td-num" data-label="Stock"><span class="x-num">{{ (int) $p->quantity }}</span></td>

            <td class="cc-col-price x-td-num" data-label="Price"><x-money :php="(float) $p->price" /></td>

            <td class="cc-col-chanid" data-label="Shopee ID">
                @if($link)
                    @php $liveUrl = \Extensions\shopee\Models\ShopeeSetting::publicListingUrl($link->shopee_item_id); @endphp
                    @if($liveUrl)
                        {{-- The answer to "what does the buyer see right
                             now?" - the id links to the live listing. --}}
                        <a class="x-num" href="{{ $liveUrl }}" target="_blank" rel="noopener">{{ $link->shopee_item_id }}</a>
                    @else
                        <span class="x-num">{{ $link->shopee_item_id }}</span>
                    @endif
                @else
                    <span class="x-cell-muted">None</span>
                @endif
            </td>

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
                    {{-- Drift (#100), now visible on the index too: live and
                         out of date are different facts, so both are shown. --}}
                    @if($rowState->driftLabel())
                        <span class="cc-checked"><x-ui.badge tone="warning">{{ $rowState->driftLabel() }}</x-ui.badge>
                            <x-ui.hint label="What changed">{{ implode(' ', $rowState->reasons) }}</x-ui.hint></span>
                    @elseif($rowState->reasons)
                        <x-ui.hint label="What Shopee says">{{ implode(' ', $rowState->reasons) }}</x-ui.hint>
                    @endif
                @endif
            </td>

            <td class="cc-col-err" data-label="Last sync">
                @if($syncFailed)
                    @php
                        $reason = trim((string) ($link->last_sync_error_message ?? ''));
                        // 120, matching the group pages. At 80 the actionable
                        // half of a marketplace validation message was cut off
                        // with no way to reach the rest, so the one column
                        // that only fills when something is wrong could not
                        // say what was wrong.
                        $clipped = Str::limit($reason, 120);
                    @endphp

                    @if($reason === '')
                        <span class="cc-err">
                            @if($link->last_sync_error_code)<span class="cc-err__code">{{ $link->last_sync_error_code }}</span> @endif
                            Failed
                        </span>
                    @elseif($clipped === $reason)
                        <span class="cc-err">
                            @if($link->last_sync_error_code)<span class="cc-err__code">{{ $link->last_sync_error_code }}</span> @endif
                            {{ $reason }}
                        </span>
                    @else
                        {{-- <details>, not a tooltip, and the same component the
                             group pages use: a title attribute waits a second,
                             never appears on a phone, and cannot be reached
                             from the keyboard. --}}
                        <details class="cc-reason">
                            <summary class="cc-reason__summary">
                                <span class="cc-err cc-reason__clip">
                                    @if($link->last_sync_error_code)<span class="cc-err__code">{{ $link->last_sync_error_code }}</span> @endif
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
                    @if($link->last_synced_at)
                        <span class="cc-when">{{ $link->last_synced_at->diffForHumans() }}</span>
                    @endif
                @elseif($link && $link->last_sync_ok)
                    <span class="x-cell-muted">Succeeded</span>
                    @if($link->last_synced_at)
                        <span class="cc-when">{{ $link->last_synced_at->diffForHumans() }}</span>
                    @endif
                @else
                    <span class="x-cell-muted">Never</span>
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
                <x-ui.menu label="Product {{ $p->product_id }} actions">
                    <a class="x-menu__item" href="{{ route('ext.shopee.listings.edit', $p->product_id) }}">Open listing</a>
                    @if($canEditCatalog)<a class="x-menu__item" href="{{ route('products.edit', $p->product_id) }}">Edit in catalog</a>@endif

                    @if($canManageShopee)
                        @if(!$link)
                            {{-- One push, and it always opens the review form
                                 first (#149): the operator sees what goes up
                                 before it goes. The one-click push that sat
                                 beside it is gone from the menu; the route
                                 stays for the bulk bar and the group page. --}}
                            <a class="x-menu__item" href="{{ route('ext.shopee.products.push', $p->product_id) }}">Push to Shopee</a>
                            <form method="POST" action="{{ route('ext.shopee.products.sync_shopee_id', $p->product_id) }}" data-slow-action>
                                @csrf
                                <button type="submit" class="x-menu__item">Match Shopee ID</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('ext.shopee.products.sync_quantity', $p->product_id) }}" data-slow-action>
                                @csrf
                                <button type="submit" class="x-menu__item">Push stock</button>
                            </form>
                            <form method="POST" action="{{ route('ext.shopee.products.sync_price', $p->product_id) }}" data-slow-action>
                                @csrf
                                <button type="submit" class="x-menu__item">Push price</button>
                            </form>
                            {{-- The marketplace's own on/off, from the state
                                 the badge wears (#188): only the applicable
                                 toggle exists, where before both were rendered
                                 with one hidden by raw status. --}}
                            @if($link && $rowState?->state === \App\Integrations\Listings\ListingState::LIVE)
                                <form method="POST" action="{{ route('ext.shopee.listings.toggle', $p->product_id) }}" data-slow-action
                                      data-confirm="Unlist this item from Shopee? Buyers stop seeing it immediately. It can be relisted at any time." data-confirm-verb="Unlist">
                                    @csrf
                                    <input type="hidden" name="action" value="unlist">
                                    <button type="submit" class="x-menu__item">Unlist from Shopee</button>
                                </form>
                            @elseif($link && $rowState?->state === \App\Integrations\Listings\ListingState::INACTIVE)
                                <form method="POST" action="{{ route('ext.shopee.listings.toggle', $p->product_id) }}" data-slow-action
                                      data-confirm="Relist this item on Shopee? Buyers see it again immediately." data-confirm-tone="primary" data-confirm-verb="Relist">
                                    @csrf
                                    <input type="hidden" name="action" value="list">
                                    <button type="submit" class="x-menu__item">Relist on Shopee</button>
                                </form>
                            @endif
                            <div class="x-menu__sep"></div>
                            <form method="POST" action="{{ route('ext.shopee.products.unlink', $p->product_id) }}" data-slow-action
                                  data-confirm="Unlink this product from Shopee? Only the link is removed. The Shopee listing stays up and can be matched again later." data-confirm-verb="Unlink">
                                @csrf
                                <button type="submit" class="x-menu__item x-menu__item--danger">Unlink from Shopee</button>
                            </form>
                            <form method="POST" action="{{ route('ext.shopee.products.delete', $p->product_id) }}" data-slow-action
                                  data-confirm="Delete this listing from Shopee? The listing is removed at the marketplace and this cannot be undone." data-confirm-verb="Delete from Shopee">
                                @csrf
                                <button type="submit" class="x-menu__item x-menu__item--danger">Delete from Shopee</button>
                            </form>
                        @endif
                    @endif
                </x-ui.menu>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$products" />

@endif

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

</div>{{-- /.cc-page --}}
@endsection
