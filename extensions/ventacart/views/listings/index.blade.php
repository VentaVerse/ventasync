@extends('layouts.channel')
@section('breadcrumb', 'Listings')
@section('title', $setting->store_name . ' Listings')

@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_ventacart/listing') ?? false;
    $canEditCatalog = auth()->user()?->hasPermission('manage_catalog/product') ?? false;
    $storeName = $setting->store_name;

    $hasFilters = $q !== '' || $groupFilter !== 'all' || $erpStatus !== 'all' || $syncStatus !== 'all'
        || $ventaCartTab !== 'all' || ($failedFlag ?? false) || ($changeFlag ?? false);
    $toggleDir = fn (string $col) => $sort !== $col ? 'asc' : ($dir === 'asc' ? 'desc' : 'asc');
    $sortUrl = function (string $col) use ($toggleDir) {
        $qp = request()->query();
        $qp['sort'] = $col;
        $qp['dir'] = $toggleDir($col);
        unset($qp['page']);
        return url()->current() . '?' . http_build_query($qp);
    };
    $statusMap = \Extensions\ventacart\Services\VentaCart\VentaCartListingMirror::STATUS_MAP;
    $tabDefs = \Extensions\ventacart\Services\VentaCart\VentaCartListingMirror::TABS;
    $tabQuery = request()->except('page', 'ventacart_tab');
    $indexUrl = fn (array $params = []) => route('ext.ventacart.listings.index', array_merge(['store' => $setting->id], $params));
@endphp

<div class="cc-page" id="ventacart-listings-page" data-store-name="{{ $storeName }}"
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
                {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product' : 'products' }} in scope for {{ $storeName }}
            @endif
        </p>
    </div>
    @if($canManage)
        <div class="cc-head-actions">
            <x-ui.button type="button" variant="primary" data-add-panel-open>
                <x-ui.icon name="plus" size="14" /> Add products
            </x-ui.button>
        </div>
    @endif
</div>

@include('partials.channel-state-filter', [
    'bucket' => $troubleFilter ?? null,
    'count' => $products->total(),
    'clear' => route('ext.ventacart.listings.index', array_merge(['store' => $setting->id], request()->except(['state', 'page']))),
])

@if(($liveTabError ?? null) !== null)
    <div class="fm-note fm-note--warn" role="status">
        <div class="fm-note__body">@include('partials.channel-answer', ['channel' => $storeName, 'raw' => $liveTabError, 'settingsRoute' => null])</div>
    </div>
@endif

<div class="x-segment-bar">
    <div class="x-segment-groups">
        <div class="x-segment-group">
            <span class="x-segment-group__k">Your catalogue</span>
            <nav class="x-segment" aria-label="Catalogue">
                <a href="{{ $indexUrl($tabQuery) }}"
                   class="x-segment__item {{ $ventaCartTab === 'all' ? 'is-active' : '' }}"
                   @if($ventaCartTab === 'all') aria-current="true" @endif>
                    <span>All products</span>
                    <span class="x-segment__count">{{ number_format((int) ($catalogueTotal ?? $products->total())) }}</span>
                </a>
            </nav>
        </div>
        <div class="x-segment-group">
            <span class="x-segment-group__k">On {{ $storeName }}</span>
            <nav class="x-segment" aria-label="{{ $storeName }} listing status">
                @foreach($tabDefs as $tabKey => [$tabLabel, $tabStatus])
                    @continue($tabKey === 'all')
                    @php $isActive = $ventaCartTab === $tabKey; @endphp
                    <a href="{{ $indexUrl(array_merge($tabQuery, ['ventacart_tab' => $tabKey])) }}"
                       class="x-segment__item {{ $isActive ? 'is-active' : '' }}" @if($isActive) aria-current="true" @endif>
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
            @if($canManage && $setting->enabled)
                <form method="POST" action="{{ route('ext.ventacart.listings.refresh_status', $setting->id) }}" class="x-segment-group__refresh" data-slow-action>
                    @csrf
                    <x-ui.button type="submit"><x-ui.icon name="refresh-cw" size="14" /> Refresh from {{ $storeName }}</x-ui.button>
                </form>
            @endif
        </div>
    </div>
</div>

<div class="lsm-layout">
@include('partials.listing-status-menu', ['menu' => $statusMenu])
<div class="lsm-layout__main">

@php
    $canManageGroups = auth()->user()?->hasPermission('manage_ventacart/product_group') ?? false;
    $chosenGroup = collect($statusMenu['groups'])->first(fn ($g) => $g['active'] && $g['key'] !== 'none');
    $groupLine = ($chosenGroup && $canManageGroups) ? [
        'return' => request()->getRequestUri(),
        'manage' => route('ext.ventacart.product-groups.edit', [$setting->id, $chosenGroup['key']]),
        'send' => [
            'action' => route('ext.ventacart.product-groups.push', [$setting->id, $chosenGroup['key']]),
            'confirm' => 'Send the ticked products to ' . $storeName . ' on the settings of ' . $chosenGroup['label'] . ', rather than their own?',
        ],
        'send_all' => [
            'begin' => route('ext.ventacart.product-groups.send_run_begin', ['store' => $setting->id, 'group' => $chosenGroup['key']]),
            'step' => route('ext.ventacart.product-groups.send_run_step', ['store' => $setting->id, 'group' => $chosenGroup['key'], 'run' => 0]),
            'stop' => route('ext.ventacart.product-groups.send_run_stop', ['store' => $setting->id, 'group' => $chosenGroup['key'], 'run' => 0]),
            'name' => 'Send ' . $chosenGroup['label'],
            'count' => (int) ($groupSize ?? 0),
            'confirm' => 'Send all ' . number_format((int) ($groupSize ?? 0)) . ' ' . \Illuminate\Support\Str::plural('product', (int) ($groupSize ?? 0)) . ' in ' . $chosenGroup['label'] . ' to ' . $storeName . ' on group settings, rather than their own?',
        ],
    ] : null;
@endphp
@include('partials.listing-stand-segment', ['menu' => $statusMenu, 'group' => $groupLine])

<form method="GET" action="{{ $indexUrl() }}" class="x-filters">
    @if($ventaCartTab !== 'all')
        <input type="hidden" name="ventacart_tab" value="{{ $ventaCartTab }}">
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
    <x-ui.input type="search" name="q" class="x-filters__search" value="{{ $q }}" placeholder="Name, model or SKU" aria-label="Search listings" />
    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="erp_status" class="x-input" data-autosubmit aria-label="Filter by catalogue status">
            <option value="all" @selected($erpStatus === 'all')>Any catalogue status</option>
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
        <a class="x-filters__reset" href="{{ $indexUrl() }}">Clear</a>
    @endif
</form>

@if($canManage && $setting->enabled)
@php
    $bulk = [
        'move' => $canManageGroups ? [
            'action' => route('ext.ventacart.product-groups.move', $setting->id),
            'groups' => collect($statusMenu['groups'])->reject(fn ($g) => $g['key'] === 'none')
                ->map(fn ($g) => ['id' => $g['key'], 'name' => $g['label'], 'count' => $g['count']])->values()->all(),
            'current' => $groupFilter ?? 'all',
            'return' => request()->getRequestUri(),
        ] : null,
        'ungroup' => ($canManageGroups && $chosenGroup) ? [
            'action' => route('ext.ventacart.product-groups.mass-remove', [$setting->id, $chosenGroup['key']]),
            'label' => 'Remove from group',
            'confirm' => 'Take these products out of ' . $chosenGroup['label'] . '? They become ungrouped and stop taking its settings on the next push.',
            'return' => request()->getRequestUri(),
        ] : null,
        'push' => ['action' => route('ext.ventacart.listings.bulk_push', $setting->id), 'label' => 'Send to ' . $storeName,
            'confirm' => "Send the selected products to {$storeName}? Each goes up on its own settings, and anything not ready is named rather than sent."],
        'switch' => [
            ['action' => route('ext.ventacart.listings.bulk_toggle', $setting->id), 'verb' => 'unlist', 'codes' => 'active', 'label' => 'Unlist :n on ' . $storeName, 'button' => 'Unlist on ' . $storeName, 'fields' => ['action' => 'unlist'], 'confirm' => "Unlist the selected products on {$storeName}? Buyers stop seeing them. They can be relisted at any time."],
            ['action' => route('ext.ventacart.listings.bulk_toggle', $setting->id), 'verb' => 'relist', 'codes' => 'inactive', 'label' => 'Relist :n on ' . $storeName, 'button' => 'Relist on ' . $storeName, 'fields' => ['action' => 'relist'], 'confirm' => "Relist the selected products on {$storeName}? Buyers see them again at once."],
        ],
        'link' => ['action' => route('ext.ventacart.listings.check', $setting->id), 'label' => 'Link IDs'],
        'delete' => ['action' => route('ext.ventacart.products.bulk_delete', $setting->id), 'label' => 'Delete from ' . $storeName, 'confirm' => "Delete the selected products from {$storeName}? They are removed at the storefront and this cannot be undone."],
        'remove' => ['action' => route('ext.ventacart.products.bulk_remove_from_store', $setting->id), 'label' => 'Remove from this channel', 'confirm' => "Remove the selected products from this channel? They leave this list and their groups and stop syncing. Anything already on {$storeName} stays up until you delete it there."],
    ];
@endphp
@include('partials.channel-bulk-bar', ['bulk' => $bulk])
@endif

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : 'Nothing on this store yet' }}">
        <x-slot:action>
            @if($hasFilters)
                <x-ui.button :href="$indexUrl()">Clear filters</x-ui.button>
            @elseif($canManage)
                <x-ui.button variant="primary" type="button" data-add-panel-open>Add products</x-ui.button>
            @endif
        </x-slot:action>
    </x-ui.empty>
@else
@php $colCount = 6 + ($canManage ? 1 : 0); @endphp
<x-ui.table id="ventacart-listings-table">
    <x-slot:head>
        <tr>
            @if($canManage)
            <th scope="col" class="cc-col-check">
                <input type="checkbox" class="cc-check"
                       :checked="ids.length > 0 && selected.length === ids.length"
                       x-effect="$el.indeterminate = selected.length > 0 && selected.length < ids.length"
                       @change="selected = $event.target.checked ? ids.slice() : []"
                       aria-label="Select every product on this page">
            </th>
            @endif
            <th scope="col" class="cc-col-id" @if($sort === 'id') aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif><a class="x-th-sort" href="{{ $sortUrl('id') }}">ID @if($sort === 'id')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif</a></th>
            <th scope="col" @if($sort === 'product') aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif><a class="x-th-sort" href="{{ $sortUrl('product') }}">Product @if($sort === 'product')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif</a></th>
            <th scope="col" class="cc-col-qty x-td-num" @if($sort === 'quantity') aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif><a class="x-th-sort x-th-sort--num" href="{{ $sortUrl('quantity') }}">Stock @if($sort === 'quantity')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif</a></th>
            <th scope="col" class="cc-col-price x-td-num" @if($sort === 'price') aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif><a class="x-th-sort x-th-sort--num" href="{{ $sortUrl('price') }}">Price @if($sort === 'price')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif</a></th>
            <th scope="col" class="cc-col-sync">Listing</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @php

        $marks = new \App\Integrations\Listings\ListingMarks(

            'ventacart', \App\Integrations\Listings\ListingStore::id('ventacart'),

            $products->pluck('product_id')->map(fn ($v) => (int) $v)->all(),

            'ventacart_listings', 'ventacart_setting_id', 'ventacart_product_groups', 'ventacart_product_group_products', 'ventacart_product_group_id', 'ventacart_setting_id'

        );

    @endphp


    @foreach($products as $p)
        @php
            $pid = (int) $p->product_id;
            $link = $linksByProductId->get($pid);
            $listing = $listingsByProductId->get($pid);
            $listed = (bool) ($link?->ventacart_product_id);
            $attempt = $lastAttempts->get($pid);
            $pGroups = $groupsByProductId->get($pid, []);
            $optRows = $optionRowsByProductId->get($pid);
            $img = trim((string) ($p->image ?? ''));
            $thumbSrc = $marks->thumb((int) $p->product_id, $img) ?? '';
            $rowLiveStatus = $listed ? ($listing?->live_status) : null;
            $attemptFailed = $attempt && in_array($attempt->sync_status, ['error', 'failed'], true);
            $listingUrl = route('ext.ventacart.listings.edit', [$setting->id, $pid, 'back' => request()->getRequestUri()]);
        @endphp
        @include('partials.channel-error-row', ['error' => $rowErrors[$pid] ?? null, 'cols' => $colCount])
        @include('partials.channel-ready-row', ['state' => ($listingStates ?? [])[(int) $p->product_id] ?? null, 'cols' => $colCount, 'productId' => (int) $p->product_id])
        <tr>
            @if($canManage)
            <td class="cc-col-check">
                <input type="checkbox" class="cc-check" data-row-check value="{{ (string) $pid }}" x-model="selected" aria-label="Select product {{ $pid }}"
                       data-live="{{ $listed ? ($rowLiveStatus ?? '') : 'NOT_LISTED' }}">
            </td>
            @endif
            <td class="cc-col-id" data-label="ID"><span class="x-num">{{ $pid }}</span></td>
            <td data-label="Product">
                <div class="co-item">
                    <div class="cc-thumb">
                        <span class="co-item__none">No image</span>
                        @if($thumbSrc)<img src="{{ $thumbSrc }}" alt="" loading="lazy" decoding="async" data-thumb>@endif
                    </div>
                    <div class="co-item__body">
                        <a class="x-row-link co-item__name" href="{{ $listingUrl }}">{{ ($rowTitles[(int) $p->product_id] ?? $p->name) ?: 'Unnamed product' }}</a>
                        <div class="co-item__meta">
                            <span class="co-item__sku">{{ $p->sku ?: ($p->model ?: 'No SKU') }}</span>
                            @if($listed)
                                <span class="co-item__sku lsm-chanid">VentaCart {{ $link->ventacart_product_id }}</span>
                            @endif
                            @if($p->manufacturer_name)<span>{{ $p->manufacturer_name }}</span>@endif
                            @if(($groupFilter ?? 'all') === 'all' && count($pGroups) > 0)
                                <a class="co-item__var" href="{{ $indexUrl(array_merge(request()->except(['page', 'sync_status', 'group']), ['group' => $pGroups[0]['id']])) }}">Product group: {{ $pGroups[0]['name'] }}</a>
                            @endif
                        </div>
                    </div>
                </div>
            </td>
            <td class="cc-col-qty x-td-num" data-label="Stock"><span class="x-num">{{ (int) $p->quantity }}</span></td>
            <td class="cc-col-price x-td-num" data-label="Price"><x-money :php="(float) $p->price" /></td>
            <td class="cc-col-sync" data-label="Listing">
                @php $state = $listingStates[$pid] ?? null; @endphp
                @if($state)
                    @include('partials.channel-listing-state', ['state' => $state, 'listingUrl' => $listingUrl, 'canCompare' => $canManage])
                @elseif($listed)
                    <x-ui.badge tone="neutral">Not checked yet</x-ui.badge>
                @else
                    <x-ui.badge tone="neutral">Not listed</x-ui.badge>
                @endif
            </td>
            <td class="x-td-actions">
                @if($canManage || $canEditCatalog)
                @php
                    $menu = [
                        'label' => "Product {$pid} actions",
                        'store' => $storeName,
                        'edit' => $listingUrl,
                        'catalog' => $canEditCatalog ? route('products.edit', $pid) : null,
                    ];
                    if ($canManage && $setting->enabled) {
                        if (!$listed) {
                            $menu['push'] = ['href' => $listingUrl, 'label' => 'Send to ' . $storeName];
                            $menu['link'] = ['action' => route('ext.ventacart.listings.check', $setting->id), 'fields' => ['product_ids[]' => $pid]];
                        } else {
                            $menu['update'] = ['action' => route('ext.ventacart.listings.push', [$setting->id, $pid]), 'label' => 'Send to ' . $storeName, 'confirm' => "Send this listing's content and price rule to the live {$storeName} product? Name, description, price, stock, images and variations are updated in place.", 'verb' => 'Send', 'tone' => 'primary'];
                            if ($rowLiveStatus === \Extensions\ventacart\Models\VentaCartListing::STATUS_ACTIVE) {
                                $menu['switch'] = ['action' => route('ext.ventacart.listings.toggle', [$setting->id, $pid]), 'label' => 'Unlist on ' . $storeName, 'fields' => ['action' => 'unlist'], 'confirm' => "Unlist this product on {$storeName}? Buyers stop seeing it. It can be relisted at any time.", 'verb' => 'Unlist'];
                            } elseif ($rowLiveStatus === \Extensions\ventacart\Models\VentaCartListing::STATUS_INACTIVE) {
                                $menu['switch'] = ['action' => route('ext.ventacart.listings.toggle', [$setting->id, $pid]), 'label' => 'Relist on ' . $storeName, 'fields' => ['action' => 'relist'], 'confirm' => "Relist this product on {$storeName}? Buyers see it again at once.", 'verb' => 'Relist', 'tone' => 'primary'];
                            }
                            $menu['check'] = ['action' => route('ext.ventacart.listings.check', $setting->id), 'label' => 'Check against ' . $storeName, 'fields' => ['product_ids[]' => $pid]];
                            $menu['unlink'] = ['action' => route('ext.ventacart.products.unlink', [$setting->id, $pid]), 'confirm' => "Unlink this product from {$storeName}? Only the link is removed. The store product stays up and can be linked again later."];
                            $menu['delete'] = ['action' => route('ext.ventacart.products.delete', [$setting->id, $pid]), 'confirm' => "Delete this product from {$storeName}? It is removed at the storefront and this cannot be undone."];
                        }
                    }
                    if ($canManage) {
                        $menu['remove'] = ['action' => route('ext.ventacart.products.remove_from_store', [$setting->id, $pid]), 'label' => 'Remove from this channel', 'verb' => 'Remove', 'confirm' => "Remove this product from this channel? It leaves this list and its group and stops syncing. Anything already on {$storeName} stays up until you delete it there."];
                    }
                @endphp
                @include('partials.channel-row-menu', ['menu' => $menu])
                @endif
            </td>
        </tr>
        @if($optRows && count($optRows) > 0)
            @include('partials.channel-variation-rows', ['rows' => $optRows, 'check' => $canManage, 'trail' => ['cc-col-sync', 'x-td-actions']])
        @endif
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$products" />
@endif

</div>
</div>

<div id="ventacart-listings-progress" class="modal-backdrop">
    <div class="modal co-modal co-modal--sm" role="dialog" aria-modal="true"
         aria-labelledby="ventacart-listings-progress-title" aria-describedby="ventacart-listings-progress-note">
        <div class="cc-progress" role="status" aria-live="polite">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div class="cc-progress__title" id="ventacart-listings-progress-title" data-progress-title>Talking to {{ $storeName }}</div>
                <div class="cc-progress__note" id="ventacart-listings-progress-note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

@if($canManage)
    <x-channel.add-panel
        id="ventacart-add-panel"
        :search-url="route('ext.ventacart.products.catalogue_search', $setting->id)"
        :add-url="route('ext.ventacart.products.add_to_store', [$setting->id, '__PID__'])"
        :groups="\App\Integrations\Listings\StatusMenu::groupChoices($statusMenu ?? [])"
        :group="\App\Integrations\Listings\StatusMenu::chosenGroup($statusMenu ?? [])"
        sub="Search your Master Catalog. Each product you add joins this store's list, ready to configure and push to {{ $storeName }}."
        toggle-label="Show products already on this store"
        in-label="On this store"
        scope="this store"
        empty-default="Every enabled product is already on this store."
        empty-all="Your catalog has no enabled products."
        list-label="Catalog products" />
@endif
</div>
@endsection
