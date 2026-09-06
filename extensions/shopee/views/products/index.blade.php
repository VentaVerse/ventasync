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
            {{-- This store's own list now (#201): the products this store
                 carries, not the whole catalogue. A filtered count says what
                 it is counting. --}}
            @if($hasFilters)
                {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product matches' : 'products match' }}
            @else
                {{ number_format($catalogueTotal ?? $products->total()) }} {{ ($catalogueTotal ?? $products->total()) === 1 ? 'product' : 'products' }} on this store
                &middot; {{ number_format($listedTotal ?? 0) }} on Shopee
            @endif
        </p>
    </div>
    @if($canManageShopee)
        <div class="cc-head-actions">
            {{-- Refresh and Repair links live beside the freshness stamp on
                 the strip (2026-09-03): global maintenance, page furniture.
                 Add products (#201, Draft C - the owner 2026-09-05) is the one
                 way a main-catalogue product joins this store: a primary
                 button, sized to how often it happens, opening the slide-over
                 in place - not a tab beside the daily table, which gave a
                 rare act equal billing. --}}
            <x-ui.button type="button" variant="primary" data-add-panel-open>
                <x-ui.icon name="plus" size="14" /> Add products
            </x-ui.button>
        </div>
    @endif
</div>



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
    @include('ext-shopee::products._scope_strips', ['stripRoute' => 'ext.shopee.products.index', 'stripParams' => []])
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
        {{-- No multi-tick push here (the owner, 2026-09-06): many products go
             up from a product group's page; this bar keeps the verbs that
             cannot half-fail. --}}
        {{-- Replaces Match Shopee IDs, which could only fill in blanks; the
             repair also verifies the ids the rows already have (Phase F, #184). --}}
        <form method="POST" action="{{ route('ext.shopee.products.check') }}" data-bulk-form>
            @csrf
            <x-ui.button type="submit" size="sm">Repair links</x-ui.button>
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
              data-bulk-verb="unlist" data-verb-codes="NORMAL" data-verb-label="Delist :n from Shopee"
              data-bulk-confirm="Delist the selected items from Shopee? Buyers stop seeing them immediately. They stay here and can be published again at any time." hidden>
            @csrf
            <input type="hidden" name="action" value="unlist">
            <x-ui.button type="submit" size="sm">Delist from Shopee</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.shopee.products.bulk_toggle') }}" data-bulk-form
              data-bulk-verb="list" data-verb-codes="UNLIST" data-verb-label="Publish :n on Shopee"
              data-bulk-confirm="Publish the selected items on Shopee? Buyers see them again immediately." hidden>
            @csrf
            <input type="hidden" name="action" value="list">
            <x-ui.button type="submit" size="sm">Publish on Shopee</x-ui.button>
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
            @include('partials.channel-ready-head')
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
            $rowLabel = (string) ($p->name ?: ($p->sku ?: 'product ' . $p->product_id));
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
                                <span class="co-item__var" @if(count($pGroups) > 1) title="Product Groups: {{ implode(', ', $pGroups) }}" @endif>Product group{{ count($pGroups) > 1 ? 's' : '' }}: {{ $pGroups[0] }}@if(count($pGroups) > 1) and {{ count($pGroups) - 1 }} more @endif</span>
                            @endif
                        </div>

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
            <td class="cc-col-ready" data-label="Ready">
                @include('partials.channel-ready', ['state' => ($listingStates ?? [])[(int) $p->product_id] ?? null])
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
                {{-- One answer for this column on every page (the owner,
                     2026-09-05): the shared refusal, else the newest
                     successful sync, else never. --}}
                @include('partials.channel-last-sync', ['sync' => $lastSync[(int) $p->product_id] ?? null])
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
                            {{-- The one-editor merge (2026-09-03): a READY
                                 listing pushes directly, with a confirm that
                                 names its settings as the source; anything
                                 incomplete lands in the listing editor, which
                                 names what is missing. #149's see-before-it-
                                 goes survives as the editor itself - it shows
                                 everything that would go up. --}}
                            @php $rowReady = $pushReady[$p->product_id]['ready'] ?? false; @endphp
                            @if($rowReady)
                                {{-- Opens the review modal (cleanup 7+9):
                                     what goes up, shown before it goes.
                                     Without JS the form still pushes. --}}
                                <form method="POST" action="{{ route('ext.shopee.products.push_direct', $p->product_id) }}" data-slow-action
                                      data-push-review="{{ route('ext.shopee.products.push_review', $p->product_id) }}">
                                    @csrf
                                    <button type="submit" class="x-menu__item">Push to Shopee</button>
                                </form>
                            @else
                                <a class="x-menu__item" href="{{ route('ext.shopee.listings.edit', [$p->product_id, 'back' => request()->getRequestUri()]) }}"
                                   title="Not ready yet - the listing page names what is still missing">Push to Shopee</a>
                            @endif
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
                                      data-confirm="Delist this item from Shopee? Buyers stop seeing it immediately. It can be published again at any time." data-confirm-verb="Unlist">
                                    @csrf
                                    <input type="hidden" name="action" value="unlist">
                                    <button type="submit" class="x-menu__item">Delist from Shopee</button>
                                </form>
                            @elseif($link && $rowState?->state === \App\Integrations\Listings\ListingState::INACTIVE)
                                @if(($parcelGaps[$p->product_id] ?? null) !== null)
                                    {{-- The preflight, worn by the row (#191): Shopee
                                         would refuse this publish for a knowable reason,
                                         so the menu offers the FIX, not the failure. --}}
                                    <a class="x-menu__item" href="{{ route('products.edit', $p->product_id) }}"
                                       title="Shopee will refuse to publish without {{ $parcelGaps[$p->product_id] }}">Add weight and size, then publish</a>
                                @else
                                <form method="POST" action="{{ route('ext.shopee.listings.toggle', $p->product_id) }}" data-slow-action
                                      data-confirm="Publish this item on Shopee? Buyers see it again immediately." data-confirm-tone="primary" data-confirm-verb="Relist">
                                    @csrf
                                    <input type="hidden" name="action" value="list">
                                    <button type="submit" class="x-menu__item">Publish on Shopee</button>
                                </form>
                                @endif
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

                        {{-- Remove from this store's list (#201) - the inverse
                             of Add. Deliberately no blocker (the owner,
                             2026-09-05): it leaves the store and stops syncing
                             at once. It never touches Shopee - a live listing
                             stays up, still orderable, until removed there,
                             which the confirm says plainly; Delete from Shopee
                             above is the door for that. --}}
                        <div class="x-menu__sep"></div>
                        <form method="POST" action="{{ route('ext.shopee.products.remove_from_store', $p->product_id) }}" data-slow-action
                              data-confirm="{{ $link
                                  ? "Remove {$rowLabel} from this store? It leaves this store's list and stops syncing. It is on Shopee (item {$link->shopee_item_id}) and stays up, still orderable, until you remove it there. Use Delete from Shopee for that."
                                  : "Remove {$rowLabel} from this store? It leaves this store's list and stops syncing. Nothing on Shopee is changed." }}"
                              data-confirm-verb="Remove from store">
                            @csrf
                            <button type="submit" class="x-menu__item x-menu__item--danger">Remove from store</button>
                        </form>
                    @endif
                </x-ui.menu>
                @endif
            </td>
        </tr>
        @if($variationCount > 0)
            {{-- The variations as rows of this table, under the same headers (partials.channel-variation-rows). --}}
            @include('partials.channel-variation-rows', ['rows' => $optRows, 'check' => $canManageShopee, 'trail' => ['cc-col-chanid', 'cc-col-stat', 'cc-col-sync', 'cc-col-err', 'x-td-actions']])
        @endif
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
