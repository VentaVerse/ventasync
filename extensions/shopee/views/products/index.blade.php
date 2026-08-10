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
    $canManageShopee = auth()->user()?->hasPermission('manage_shopee') ?? false;

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
            {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product' : 'products' }} in scope for Shopee
        </p>
    </div>
    @if($canManageShopee)
        <x-ui.button variant="primary" :href="route('ext.shopee.products.add')">New listing</x-ui.button>
    @endif
</div>

@if($canManageShopee)
{{-- Unmatched items. Shopee listings whose SKU matched nothing in the
     catalogue, which is a work queue and not part of the list below, so it
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
                  data-confirm="Rebuild the Shopee item cache? This reads every item on your Shopee shop and can take a minute.">
                @csrf
                <x-ui.button type="submit" size="sm">Rebuild item cache</x-ui.button>
            </form>
        </div>
    </div>

    @if($hasUnmatched)
        <p class="fm-section__note">These items exist on Shopee but no catalogue product has a matching SKU. Link each one to the catalogue product it really is, or dismiss it if it should not be tracked here.</p>

        <x-ui.table>
            <x-slot:head>
                <tr>
                    <th>Shopee item</th>
                    <th class="cc-col-sku">SKU</th>
                    <th class="cc-col-chanid">Item ID</th>
                    <th class="cc-col-link">Catalogue product</th>
                    <th class="x-td-actions"><span class="x-sr">Actions</span></th>
                </tr>
            </x-slot:head>

            @foreach($unmatched as $ui)
                <tr>
                    <td data-label="Shopee item">
                        <div class="co-item">
                            <div class="cc-thumb cc-thumb--sm">
                                <span class="co-item__none">None</span>
                                @if($ui->image_url)
                                    <img src="{{ $ui->image_url }}" alt="" loading="lazy" decoding="async" onerror="this.style.display='none';">
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
                    <td class="cc-col-link" data-label="Catalogue product">
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
                                       aria-label="Search the catalogue for the product this Shopee item is">
                                <div class="fm-ta__list" data-unmatched-results role="listbox" aria-label="Catalogue matches"></div>
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
        <p class="fm-section__note">Nothing is waiting. <x-ui.hint label="What a scan does">A scan compares every item on your Shopee shop against catalogue SKUs and lists anything it could not place.</x-ui.hint></p>
    @endif
</section>
@endif

{{-- One filter row. The old page had a five-column CSS grid of labelled
     selects inside a card; the selects here submit on change (data-autosubmit,
     resources/js/list-filters.js) so there is no Filter button to press, and
     Clear is a link because it is navigation. --}}
<form method="GET" action="{{ route('ext.shopee.products.index') }}" class="x-filters" id="shopee-listings-filter">
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
        <select name="erp_status" class="x-input" data-autosubmit aria-label="Filter by catalogue status">
            <option value="all" @selected($erpStatus === 'all')>Any catalogue status</option>
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
<div class="cc-bulkbar" x-show="selected.length > 0" x-cloak data-bulkbar>
    <span class="cc-bulkbar__count"><span class="x-num" x-text="selected.length"></span> selected</span>
    <div class="cc-bulkbar__actions">
        <form method="POST" action="{{ route('ext.shopee.products.bulk_push') }}" data-bulk-form
              data-bulk-confirm="Push the selected products to Shopee? Anything already listed is skipped.">
            @csrf
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
                    : 'This list shows catalogue products that belong to a Shopee product group, plus anything already linked to a Shopee listing. Create a product group to bring products into scope.'">
        @if(!$hasFilters)
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
            <th class="cc-col-check">
                <input type="checkbox" class="cc-check"
                       :checked="ids.length > 0 && selected.length === ids.length"
                       x-effect="$el.indeterminate = selected.length > 0 && selected.length < ids.length"
                       @change="selected = $event.target.checked ? ids.slice() : []"
                       aria-label="Select every product on this page">
            </th>
            @endif
            <th class="cc-col-id">ID</th>
            <th>Product</th>
            <th class="cc-col-qty x-td-num">Stock</th>
            <th class="cc-col-price x-td-num">Price</th>
            <th class="cc-col-chanid">Shopee ID</th>
            <th class="cc-col-stat">Catalogue</th>
            <th class="cc-col-sync">Listing</th>
            <th class="cc-col-err">Last sync</th>
            <th class="x-td-actions"><span class="x-sr">Actions</span></th>
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
                       x-model="selected" aria-label="Select product {{ $p->product_id }}">
            </td>
            @endif

            <td class="cc-col-id" data-label="ID"><span class="x-num">{{ $p->product_id }}</span></td>

            <td data-label="Product">
                <div class="co-item">
                    <div class="cc-thumb">
                        <span class="co-item__none">No image</span>
                        @if($thumbSrc)
                            <img src="{{ $thumbSrc }}" alt="" loading="lazy" decoding="async" onerror="this.style.display='none';">
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
                                <span class="co-item__var">{{ $pGroups[0] }}@if(count($pGroups) > 1) and {{ count($pGroups) - 1 }} more @endif</span>
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
                                        $osku = (string) ($or->option_sku ?? '');
                                        $oqty = (int) ($or->option_quantity ?? 0);
                                        $oprice = (float) ($or->option_absolute_price ?? 0);
                                    @endphp
                                    <div class="cc-var">
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
                    <span class="x-num">{{ $link->shopee_item_id }}</span>
                @else
                    <span class="x-cell-muted">Not linked</span>
                @endif
            </td>

            <td class="cc-col-stat" data-label="Catalogue">
                <x-ui.badge :tone="(int) $p->status === 1 ? 'success' : 'neutral'">{{ (int) $p->status === 1 ? 'Enabled' : 'Disabled' }}</x-ui.badge>
            </td>

            <td class="cc-col-sync" data-label="Listing">
                @if($link)
                    <x-ui.badge tone="success">Listed</x-ui.badge>
                @else
                    <x-ui.badge tone="neutral">Not listed</x-ui.badge>
                @endif
            </td>

            <td class="cc-col-err" data-label="Last sync">
                @if($syncFailed)
                    <span class="cc-err">
                        @if($link->last_sync_error_code)<span class="cc-err__code">{{ $link->last_sync_error_code }}</span> @endif
                        {{ $link->last_sync_error_message ? Str::limit($link->last_sync_error_message, 80) : 'Failed' }}
                    </span>
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
                <x-ui.menu label="Product {{ $p->product_id }} actions">
                    <a class="x-menu__item" href="{{ route('products.edit', $p->product_id) }}">Edit in catalogue</a>

                    @if($canManageShopee)
                        @if(!$link)
                            <a class="x-menu__item" href="{{ route('ext.shopee.products.push', $p->product_id) }}">Review push settings</a>
                            <form method="POST" action="{{ route('ext.shopee.products.push_direct', $p->product_id) }}" data-slow-action>
                                @csrf
                                <button type="submit" class="x-menu__item">Push to Shopee</button>
                            </form>
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
                            <div class="x-menu__sep"></div>
                            <form method="POST" action="{{ route('ext.shopee.products.unlink', $p->product_id) }}" data-slow-action
                                  data-confirm="Unlink this product from Shopee? Only the link is removed. The Shopee listing stays up and can be matched again later.">
                                @csrf
                                <button type="submit" class="x-menu__item x-menu__item--danger">Unlink from Shopee</button>
                            </form>
                            <form method="POST" action="{{ route('ext.shopee.products.delete', $p->product_id) }}" data-slow-action
                                  data-confirm="Delete this listing from Shopee? The listing is removed at the marketplace and this cannot be undone.">
                                @csrf
                                <button type="submit" class="x-menu__item x-menu__item--danger">Delete from Shopee</button>
                            </form>
                        @endif
                    @endif
                </x-ui.menu>
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
    <div class="modal co-modal co-modal--sm">
        <div class="cc-progress">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div class="cc-progress__title" data-progress-title>Talking to Shopee</div>
                <div class="cc-progress__note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

</div>{{-- /.cc-page --}}
@endsection
