@extends('layouts.channel')
@section('breadcrumb', 'Listings')

@section('title', 'Lazada Listings')

@section('content')
@php
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
        || ($lazadaTab ?? 'all') !== 'all'
        || ($failedFlag ?? false)
        || ($changeFlag ?? false)
        || $manufacturerFilter !== 'all'
        || $groupFilter !== 'all'
        || $erpStatus !== 'all';

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
            @if($hasFilters)
                {{ number_format($paginator->total()) }} {{ $paginator->total() === 1 ? 'product matches' : 'products match' }}@if($statusMenu['label'] ?? null): {{ $statusMenu['label'] }}@endif
            @else
                {{ number_format($catalogueTotal) }} {{ $catalogueTotal === 1 ? 'product' : 'products' }} on this store
                &middot; {{ number_format($listedTotal) }} live on Lazada
            @endif
        </p>
    </div>
    @if($canManageLazada)
        <div class="cc-head-actions">
            <x-ui.button type="button" variant="primary" data-add-panel-open>
                <x-ui.icon name="plus" size="14" /> Add products
            </x-ui.button>
        </div>
    @endif
</div>

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
            'violation' => 'Rejected',
            'soldout' => 'Sold out',
            'deleted' => 'Deleted',
        ];
        $tabQuery = request()->except('page', 'lazada_tab');
    @endphp
    <div class="x-segment-bar">
        <div class="x-segment-groups">
            <div class="x-segment-group">
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
                    <form method="POST" action="{{ route('ext.lazada.products.refresh_status') }}" class="x-segment-group__refresh" data-slow-action>
                        @csrf
                        <x-ui.button type="submit"><x-ui.icon name="refresh-cw" size="14" /> Refresh from Lazada</x-ui.button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endif

@include('partials.channel-state-filter', [
    'bucket' => $troubleFilter ?? null,
    'count' => $products->total(),
    'clear' => route('ext.lazada.products.index', request()->except(['state', 'page'])),
])

<div class="lsm-layout">
@include('partials.listing-status-menu', ['menu' => $statusMenu])
<div class="lsm-layout__main">

@php
    $canManageGroups = auth()->user()?->hasPermission('manage_lazada/product_group') ?? false;
    $chosenGroup = collect($statusMenu['groups'])->first(fn ($g) => $g['active'] && $g['key'] !== 'none');
    $groupLine = ($chosenGroup && $canManageGroups) ? [
        'return' => request()->getRequestUri(),
        'manage' => route('ext.lazada.product-groups.edit', $chosenGroup['key']),
        'send' => [
            'action' => route('ext.lazada.product-groups.push', $chosenGroup['key']),
            'confirm' => 'Send the ticked products to ' . 'Lazada' . ' on the settings of ' . $chosenGroup['label'] . ', rather than their own?',
        ],
        'send_all' => [
            'begin' => route('ext.lazada.product-groups.send_run_begin', ['id' => $chosenGroup['key']]),
            'step' => route('ext.lazada.product-groups.send_run_step', ['id' => $chosenGroup['key'], 'run' => 0]),
            'stop' => route('ext.lazada.product-groups.send_run_stop', ['id' => $chosenGroup['key'], 'run' => 0]),
            'name' => 'Send ' . $chosenGroup['label'],
            'count' => (int) ($groupSize ?? 0),
            'confirm' => 'Send all ' . number_format((int) ($groupSize ?? 0)) . ' ' . \Illuminate\Support\Str::plural('product', (int) ($groupSize ?? 0)) . ' in ' . $chosenGroup['label'] . ' to ' . 'Lazada' . ' on group settings, rather than their own?',
        ],
    ] : null;
@endphp
@include('partials.listing-stand-segment', ['menu' => $statusMenu, 'group' => $groupLine])

<form method="GET" action="{{ route('ext.lazada.products.index') }}" class="x-filters" id="lazada-listings-filter">
    @if(($lazadaTab ?? 'all') !== 'all')
        <input type="hidden" name="lazada_tab" value="{{ $lazadaTab }}">
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
        <a class="x-filters__reset" href="{{ route('ext.lazada.products.index') }}">Clear</a>
    @endif
</form>

@php
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
@php
    $bulk = [
        'move' => $canManageGroups ? [
            'action' => route('ext.lazada.product-groups.move'),
            'groups' => collect($statusMenu['groups'])->reject(fn ($g) => $g['key'] === 'none')
                ->map(fn ($g) => ['id' => $g['key'], 'name' => $g['label'], 'count' => $g['count']])->values()->all(),
            'current' => $groupFilter ?? 'all',
            'return' => request()->getRequestUri(),
        ] : null,
        'ungroup' => ($canManageGroups && $chosenGroup) ? [
            'action' => route('ext.lazada.product-groups.massRemove', $chosenGroup['key']),
            'label' => 'Remove from group',
            'confirm' => 'Take these products out of ' . $chosenGroup['label'] . '? They become ungrouped and stop taking its settings on the next push.',
            'return' => request()->getRequestUri(),
        ] : null,
        'push' => ['action' => route('ext.lazada.products.bulk_push'), 'label' => 'Send to Lazada',
            'confirm' => 'Send the selected products to Lazada? Each goes up on its own settings, and anything not ready is named rather than sent.'],
        'switch' => [
            ['action' => route('ext.lazada.products.bulk_toggle'), 'verb' => 'deactivate', 'codes' => 'active', 'label' => 'Deactivate :n on Lazada', 'button' => 'Deactivate on Lazada', 'fields' => ['action' => 'deactivate'], 'confirm' => 'Deactivate the selected items on Lazada? Buyers stop seeing them. They stay here and can be activated again at any time.'],
            ['action' => route('ext.lazada.products.bulk_toggle'), 'verb' => 'activate', 'codes' => 'inactive', 'label' => 'Activate :n on Lazada', 'button' => 'Activate on Lazada', 'fields' => ['action' => 'activate'], 'confirm' => 'Activate the selected items on Lazada? Buyers see them again once Lazada applies it.'],
        ],
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
                        : 'This store carries nothing yet. Add products from your Master Catalog and they will land here, ready to configure and push to Lazada.')">
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
@php $colCount = 6 + ($canManageLazada ? 1 : 0); @endphp
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
            <th scope="col" class="cc-col-price x-td-num">Price</th>
            <th scope="col" class="cc-col-sync" @if($sort === 'lazada_status') aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                <a class="x-th-sort" href="{{ $sortUrl('lazada_status') }}">
                    Listing
                    @if($sort === 'lazada_status')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($products as $row)
        @php
            $pid = (int) $row->product_id;
            $listing = $row->listing_id ? $listingsById->get((int) $row->listing_id) : null;
            $rowState = $listingStates[$pid] ?? null;
            $stateClass = \App\Integrations\Listings\ListingState::class;

            $thumbSrc = $productThumbsById->get($pid);
            $pName = (string) ($rowTitles[(int) $row->product_id] ?? $row->name ?? '');
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

            $editUrl = route('ext.lazada.products.edit', ['productId' => $pid, 'back' => request()->getRequestUri()]);

            $rowLabel = $pName !== '' ? $pName : ($pSku !== '' ? $pSku : 'product ' . $pid);
        @endphp
        @include('partials.channel-error-row', ['error' => ($rowErrors ?? [])[(int) $pid] ?? null, 'cols' => $colCount])
        @include('partials.channel-ready-row', ['state' => $rowState, 'cols' => $colCount, 'productId' => (int) $pid])
        <tr>
            @if($canManageLazada)
            <td class="cc-col-check">
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
                        @if($canManageLazada)
                            <a class="x-row-link co-item__name" href="{{ $editUrl }}">{{ $pName ?: 'Unnamed product' }}</a>
                        @else
                            <span class="co-item__name">{{ $pName ?: 'Unnamed product' }}</span>
                        @endif
                        <div class="co-item__meta">
                            <x-ui.badge :tone="$pStatus ? 'success' : 'neutral'">{{ $pStatus ? 'Enabled' : 'Disabled' }}</x-ui.badge>
                            <span class="co-item__sku">{{ $pSku ?: 'No SKU' }}</span>
                            @if($hasItemId)
                                <span class="co-item__sku lsm-chanid">Lazada {{ $row->lazada_item_id }}</span>
                            @endif
                            @if($pMfg !== '')
                                <span>{{ $pMfg }}</span>
                            @endif
                            @if(($groupFilter ?? 'all') === 'all' && $listing && $listing->groups->count() > 0)
                                <a class="co-item__var" href="{{ route('ext.lazada.products.index', array_merge(request()->except(['page', 'sync_status', 'group']), ['group' => $listing->groups->first()->id])) }}">Product group: {{ $listing->groups->first()->name }}</a>
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



            <td class="cc-col-sync" data-label="Listing">
                @if($rowState === null)
                    <x-ui.badge tone="neutral">Not listed</x-ui.badge>
                @elseif($rowState->state === $stateClass::UNKNOWN && $hasItemId && ($liveCounts ?? null) === null)
                    <x-ui.badge tone="success">Listed</x-ui.badge>
                @elseif($rowState->state === $stateClass::UNKNOWN)
                    <x-ui.badge tone="neutral" title="Press Refresh from Lazada to read its status">{{ $rowState->label() }}</x-ui.badge>
                @elseif($rowState->state === $stateClass::NOT_LISTED)
                    <x-ui.badge :tone="$rowState->tone()">{{ $rowState->label() }}</x-ui.badge>
                @else
                    <span @if($rowState->checkedAt) title="Checked {{ $rowState->checkedAt->diffForHumans() }}" @endif>
                        <x-ui.badge :tone="$rowState->tone()">{{ $rowState->label() }}</x-ui.badge>
                    </span>
                    @if($rowState->reasons)
                        <x-ui.hint label="What Lazada says">{{ implode(' ', $rowState->reasons) }}</x-ui.hint>
                    @endif
                @endif
                @if($rowState?->driftLabel() && $canManageLazada)
                    @include('partials.catalog-change-button', ['href' => $editUrl . (str_contains($editUrl, '?') ? '&' : '?') . 'compare=1'])
                @endif
            </td>

            <td class="x-td-actions">
                @if($canManageLazada)
                @php
                    $menu = [
                        'label' => "{$rowLabel} actions",
                        'store' => 'Lazada',
                        'edit' => $editUrl,
                        'catalog' => $canEditCatalog ? route('products.edit', $pid) : null,
                    ];
                    if ($canManageLazada) {
                        if (!$listing || !$hasItemId || $isUnlinked) {
                            $menu['push'] = ['href' => $editUrl, 'label' => 'Send to Lazada'];
                            if ($listing) {
                                $menu['link'] = ['action' => route('ext.lazada.products.sync_lazada_id', $pid)];
                            }
                        } else {
                            $menu['update'] = ['action' => route('ext.lazada.products.upload', $pid), 'label' => 'Send to Lazada', 'confirm' => "Send this listing's content and settings to the live Lazada item? Name, description, package, category, brand, attributes, price and stock are updated.", 'verb' => 'Send', 'tone' => 'primary'];
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
            @include('partials.channel-variation-rows', ['rows' => $optRows, 'check' => $canManageLazada, 'trail' => ['cc-col-sync', 'x-td-actions']])
        @endif
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$paginator" />

@endif

</div>
</div>

<div id="lazada-listings-progress" class="modal-backdrop">
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

@if($canManageLazada)
    @include('ext-lazada::products._add-panel')
@endif

</div>
@endsection
