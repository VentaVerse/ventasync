@extends('layouts.channel')
@section('breadcrumb', 'Listings')

@section('title', 'Shopee Listings')

@section('content')
@php
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
        || ($gapFilter ?? 'all') !== 'all'
        || ($failedFlag ?? false)
        || ($changeFlag ?? false)
        || $manufacturerFilter !== 'all'
        || $groupFilter !== 'all'
        || $erpStatus !== 'all';

    $sort = (string) ($sort ?? 'id');
    $dir = (string) ($dir ?? 'asc');

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
            <x-ui.button type="button" variant="primary" data-add-panel-open>
                <x-ui.icon name="plus" size="14" /> Add products
            </x-ui.button>
        </div>
    @endif
</div>



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

<div class="lsm-layout">
@include('partials.listing-status-menu', ['menu' => $statusMenu])
<div class="lsm-layout__main">

@php
    $canManageGroups = auth()->user()?->hasPermission('manage_shopee/product_group') ?? false;
    $chosenGroup = collect($statusMenu['groups'])->first(fn ($g) => $g['active'] && $g['key'] !== 'none');
    $groupLine = ($chosenGroup && $canManageGroups) ? [
        'return' => request()->getRequestUri(),
        'manage' => route('ext.shopee.product-groups.edit', $chosenGroup['key']),
        'send' => [
            'action' => route('ext.shopee.product-groups.send', $chosenGroup['key']),
            'confirm' => 'Send the ticked products to ' . 'Shopee' . ' on the settings of ' . $chosenGroup['label'] . ', rather than their own?',
        ],
        'send_all' => [
            'begin' => route('ext.shopee.product-groups.send_run_begin', ['id' => $chosenGroup['key']]),
            'step' => route('ext.shopee.product-groups.send_run_step', ['id' => $chosenGroup['key'], 'run' => 0]),
            'stop' => route('ext.shopee.product-groups.send_run_stop', ['id' => $chosenGroup['key'], 'run' => 0]),
            'name' => 'Send ' . $chosenGroup['label'],
            'count' => (int) ($groupSize ?? 0),
            'confirm' => 'Send all ' . number_format((int) ($groupSize ?? 0)) . ' ' . \Illuminate\Support\Str::plural('product', (int) ($groupSize ?? 0)) . ' in ' . $chosenGroup['label'] . ' to ' . 'Shopee' . ' on group settings, rather than their own?',
        ],
    ] : null;
@endphp
@include('partials.listing-stand-segment', ['menu' => $statusMenu, 'group' => $groupLine])

<form method="GET" action="{{ route('ext.shopee.products.index') }}" class="x-filters" id="shopee-listings-filter">
    @if(($shopeeTab ?? 'all') !== 'all')
        <input type="hidden" name="shopee_tab" value="{{ $shopeeTab }}">
    @endif
    @if($syncStatus !== 'all')
        <input type="hidden" name="sync_status" value="{{ $syncStatus }}">
    @endif
    @if(($groupFilter ?? 'all') !== 'all')
        <input type="hidden" name="group" value="{{ $groupFilter }}">
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


    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="erp_status" class="x-input" data-autosubmit aria-label="Filter by catalog status">
            <option value="all" @selected($erpStatus === 'all')>Any catalog status</option>
            <option value="enabled" @selected($erpStatus === 'enabled')>Enabled</option>
            <option value="disabled" @selected($erpStatus === 'disabled')>Disabled</option>
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    @if(!empty($orderOptions))
        @include('partials.listing-sort', ['options' => $orderOptions, 'chosen' => $order])
    @endif

    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>

    @if($hasFilters)
        <a class="x-filters__reset" href="{{ route('ext.shopee.products.index') }}">Clear</a>
    @endif
</form>

@if($canManageShopee)
@php
    $bulk = [
        'move' => $canManageGroups ? [
            'action' => route('ext.shopee.product-groups.move'),
            'groups' => collect($statusMenu['groups'])->reject(fn ($g) => $g['key'] === 'none')
                ->map(fn ($g) => ['id' => $g['key'], 'name' => $g['label'], 'count' => $g['count']])->values()->all(),
            'current' => $groupFilter ?? 'all',
            'return' => request()->getRequestUri(),
        ] : null,
        'ungroup' => ($canManageGroups && $chosenGroup) ? [
            'action' => route('ext.shopee.product-groups.massRemove', $chosenGroup['key']),
            'label' => 'Remove from group',
            'confirm' => 'Take these products out of ' . $chosenGroup['label'] . '? They become ungrouped and stop taking its settings on the next push.',
            'return' => request()->getRequestUri(),
        ] : null,
        'push' => ['action' => route('ext.shopee.products.bulk_push'), 'label' => 'Send to Shopee',
            'confirm' => 'Send the selected products to Shopee? Each goes up on its own settings, and anything not ready is named rather than sent.'],
        'switch' => [
            ['action' => route('ext.shopee.products.bulk_toggle'), 'verb' => 'unlist', 'codes' => 'NORMAL', 'label' => 'Delist :n from Shopee', 'button' => 'Delist from Shopee', 'fields' => ['action' => 'unlist'], 'confirm' => 'Delist the selected items from Shopee? Buyers stop seeing them immediately. They stay here and can be published again at any time.'],
            ['action' => route('ext.shopee.products.bulk_toggle'), 'verb' => 'list', 'codes' => 'UNLIST', 'label' => 'Publish :n on Shopee', 'button' => 'Publish on Shopee', 'fields' => ['action' => 'list'], 'confirm' => 'Publish the selected items on Shopee? Buyers see them again immediately.'],
        ],
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
                        : 'This store carries nothing yet. Add products from your Master Catalog and they will land here, ready to configure and push to Shopee.')">
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
            <th scope="col" class="cc-col-id" @if($sort === 'id') aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                <a class="x-th-sort" href="{{ $sortUrl('id') }}">
                    ID
                    @if($sort === 'id')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" @if($sort === 'product') aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                <a class="x-th-sort" href="{{ $sortUrl('product') }}">
                    Product
                    @if($sort === 'product')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="cc-col-qty x-td-num" @if($sort === 'quantity') aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                <a class="x-th-sort x-th-sort--num" href="{{ $sortUrl('quantity') }}">
                    Stock
                    @if($sort === 'quantity')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="cc-col-price x-td-num" @if($sort === 'price') aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
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

    @php

        $marks = new \App\Integrations\Listings\ListingMarks(

            'shopee', \App\Integrations\Listings\ListingStore::id('shopee'),

            $products->pluck('product_id')->map(fn ($v) => (int) $v)->all(),

            'shopee_listings', 'shopee_setting_id', 'shopee_product_groups', 'shopee_product_group_products', 'shopee_product_group_id', 'shopee_setting_id'

        );

    @endphp


    @foreach($products as $p)
        @php
            $links = $shopeeLinks->get($p->product_id, collect());
            $link = $links->first();
            $pGroups = isset($groupsByProductId) ? ($groupsByProductId[$p->product_id] ?? []) : [];
            $optRows = isset($optionRowsByProductId) ? $optionRowsByProductId->get($p->product_id) : null;
            $variationCount = $optRows ? count($optRows) : 0;

            $img = trim((string) ($p->image ?? ''));
            $thumbSrc = $marks->thumb((int) $p->product_id, $img) ?? '';

            $syncFailed = $link && !is_null($link->last_sync_ok) && !$link->last_sync_ok;
            $pName = (string) ($rowTitles[(int) $p->product_id] ?? $p->name);
            $rowLabel = $pName !== '' ? $pName : (string) ($p->sku ?: 'product ' . $p->product_id);
        @endphp
        @include('partials.channel-error-row', ['error' => $rowErrors[(int) $p->product_id] ?? null, 'cols' => $colCount])
        @include('partials.channel-ready-row', ['state' => ($listingStates ?? [])[(int) $p->product_id] ?? null, 'cols' => $colCount, 'productId' => (int) $p->product_id])
        <tr>
            @if($canManageShopee)
            <td class="cc-col-check">
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
                            @if(($groupFilter ?? 'all') === 'all' && count($pGroups) > 0)
                                <a class="co-item__var" href="{{ route('ext.shopee.products.index', array_merge(request()->except(['page', 'sync_status', 'group']), ['group' => $pGroups[0]['id']])) }}">Product group: {{ $pGroups[0]['name'] }}</a>
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
                @php $rowState = $listingStates[(int) $p->product_id] ?? null; @endphp
                @if($rowState === null)
                    <x-ui.badge tone="neutral">Not listed</x-ui.badge>
                @elseif($rowState->state === \App\Integrations\Listings\ListingState::UNKNOWN && $link && ($liveCounts ?? null) === null)
                    <x-ui.badge tone="success">Listed</x-ui.badge>
                @elseif($rowState->state === \App\Integrations\Listings\ListingState::UNKNOWN)
                    <x-ui.badge tone="neutral" title="Press Refresh from Shopee to read its status">{{ $rowState->label() }}</x-ui.badge>
                @elseif($rowState->state === \App\Integrations\Listings\ListingState::NOT_LISTED)
                    <x-ui.badge :tone="$rowState->tone()">{{ $rowState->label() }}</x-ui.badge>
                @else
                    <span @if($rowState->checkedAt) title="Checked {{ $rowState->checkedAt->diffForHumans() }}" @endif>
                        <x-ui.badge :tone="$rowState->tone()">{{ $rowState->label() }}</x-ui.badge>
                    </span>
                    @if($rowState->reasons)
                        <x-ui.hint label="What Shopee says">{{ implode(' ', $rowState->reasons) }}</x-ui.hint>
                    @endif
                @endif
                @if($rowState?->driftLabel() && $canManageShopee)
                    @include('partials.catalog-change-button', ['href' => route('ext.shopee.listings.edit', [$p->product_id, 'compare' => 1, 'back' => request()->getRequestUri()])])
                @endif
            </td>

            <td class="x-td-actions">
                @if($canManageShopee || $canEditCatalog)
                @php
                    $menu = [
                        'label' => "Product {$p->product_id} actions",
                        'store' => 'Shopee',
                        'edit' => route('ext.shopee.listings.edit', [$p->product_id, 'back' => request()->getRequestUri()]),
                        'catalog' => $canEditCatalog ? route('products.edit', $p->product_id) : null,
                    ];
                    if ($canManageShopee) {
                        if (!$link) {
                            $menu['push'] = ($rowState?->ready ?? false)
                                ? ['action' => route('ext.shopee.products.push_direct', $p->product_id), 'label' => 'Send to Shopee', 'attrs' => ['data-push-review' => route('ext.shopee.products.push_review', $p->product_id)]]
                                : ['href' => route('ext.shopee.listings.edit', [$p->product_id, 'back' => request()->getRequestUri()]), 'label' => 'Send to Shopee', 'title' => 'Not ready yet - the listing page names what is still missing'];
                            $menu['link'] = ['action' => route('ext.shopee.products.sync_shopee_id', $p->product_id)];
                        } else {
                            $menu['update'] = ['action' => route('ext.shopee.listings.push_update', $p->product_id), 'label' => 'Send to Shopee', 'confirm' => "Send this listing's content and settings to the live Shopee item? Title, description, package, category, couriers, attributes, price and stock are updated.", 'verb' => 'Send', 'tone' => 'primary'];
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
            @include('partials.channel-variation-rows', ['rows' => $optRows, 'check' => $canManageShopee, 'trail' => ['cc-col-stat', 'cc-col-sync', 'x-td-actions']])
        @endif
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$products" />

@endif

</div>
</div>

<div id="shopee-listings-progress" class="modal-backdrop">
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

@if($canManageShopee)
    <x-channel.add-panel
        id="shopee-add-panel"
        :search-url="route('ext.shopee.products.catalogue_search')"
        :add-url="route('ext.shopee.products.add_to_store', ['productId' => '__PID__'])"
        :groups="\App\Integrations\Listings\StatusMenu::groupChoices($statusMenu ?? [])"
        :group="\App\Integrations\Listings\StatusMenu::chosenGroup($statusMenu ?? [])"
        sub="Search your Master Catalog. Each product you add joins this store's list, ready to configure and push to Shopee."
        toggle-label="Show products already on this store"
        in-label="On this store"
        scope="this store"
        empty-default="Every enabled product is already on this store."
        empty-all="Your catalogue has no enabled products."
        list-label="Catalogue products"
        :full-url="route('ext.shopee.products.index', ['list' => 'add'])" />
@endif

</div>

@if($canManageShopee)
    @include('ext-shopee::products._push_review_modal')
@endif

@endsection
