@extends('layouts.channel')
@section('breadcrumb', 'Listings')
@section('title', 'Add products to Lazada')

@section('content')
@php
    $canManageLazada = auth()->user()?->hasPermission('manage_lazada/product') ?? false;
    $canEditCatalog = auth()->user()?->hasPermission('manage_catalog/product') ?? false;

    $q = (string) ($q ?? '');
    $manufacturerFilter = (string) ($manufacturerFilter ?? 'all');
    $groupFilter = (string) ($groupFilter ?? 'all');
    $onStore = (string) ($onStore ?? 'all');
    $hasFilters = $q !== '' || $manufacturerFilter !== 'all' || $groupFilter !== 'all' || $onStore !== 'all';
@endphp

<div class="cc-page"
     x-data="{
        selected: [],
        ids: {{ \Illuminate\Support\Js::from(collect($products->items())->filter(fn ($r) => ! $r->listing_id)->pluck('product_id')->map(fn ($v) => (string) $v)->values()) }}
     }">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Add products</h1>
        <p class="x-page-sub">
            Your Master Catalog &middot; {{ number_format((int) ($onStoreTotal ?? 0)) }} already on this store
        </p>
    </div>
    <div class="cc-head-actions">
        <x-ui.button variant="secondary" :href="route('ext.lazada.products.index')">Back to Listings</x-ui.button>
    </div>
</div>

<form method="GET" action="{{ route('ext.lazada.products.index') }}" class="x-filters" id="lazada-add-filter">
    <input type="hidden" name="list" value="add">
    <x-ui.input type="search" name="q" class="x-filters__search"
                value="{{ $q }}" placeholder="Name, model or SKU" aria-label="Search your catalogue" />

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
        <select name="on_store" class="x-input" data-autosubmit aria-label="Filter by whether it is on this store">
            <option value="all" @selected($onStore === 'all')>On store or not</option>
            <option value="off" @selected($onStore === 'off')>Not on this store</option>
            <option value="on" @selected($onStore === 'on')>Already on this store</option>
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <input type="hidden" name="sort" value="{{ $sort ?? 'id' }}">
    <input type="hidden" name="dir" value="{{ $dir ?? 'desc' }}">
    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
    @if($hasFilters)
        <a class="x-filters__reset" href="{{ route('ext.lazada.products.index', ['list' => 'add']) }}">Clear</a>
    @endif
</form>

@if($canManageLazada)
<div class="cc-bulkbar" x-show="selected.length > 0" x-cloak role="status">
    <span class="cc-bulkbar__count" aria-live="polite"><span class="x-num" x-text="selected.length"></span> selected</span>
    <div class="cc-bulkbar__actions">
        <form method="POST" action="{{ route('ext.lazada.products.add_to_store_bulk') }}">
            @csrf
            <template x-for="pid in selected" :key="pid"><input type="hidden" name="product_ids[]" :value="pid"></template>
            <x-ui.button type="submit" variant="primary" size="sm">Add to this store</x-ui.button>
        </form>
    </div>
</div>
@endif

@if($products->count() === 0)
    <x-ui.empty
        title="{{ $hasFilters ? 'Nothing matches those filters' : ($onStore === 'off' ? 'Every product is already on this store' : 'Your catalogue is empty') }}"
        :description="$hasFilters
            ? 'Try clearing the search, or widening the manufacturer and group filters.'
            : ($onStore === 'off'
                ? 'There is nothing left to add. Every catalogue product is already on this store.'
                : 'Add a product to your Master Catalog and it will be ready to add to this store.')">
        @if($hasFilters)
            <x-slot:action>
                <x-ui.button :href="route('ext.lazada.products.index', ['list' => 'add'])">Clear filters</x-ui.button>
            </x-slot:action>
        @elseif($onStore === 'off')
            <x-slot:action>
                <x-ui.button :href="route('ext.lazada.products.index')">Back to this store</x-ui.button>
            </x-slot:action>
        @elseif($canEditCatalog)
            <x-slot:action>
                <x-ui.button variant="primary" :href="route('products.create')">Add a product</x-ui.button>
            </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table id="lazada-add-table" class="cc-add-table">
    <x-slot:head>
        <tr>
            @if($canManageLazada)
            <th scope="col" class="cc-col-check">
                <input type="checkbox" class="cc-check"
                       :checked="ids.length > 0 && selected.length === ids.length"
                       x-effect="$el.indeterminate = selected.length > 0 && selected.length < ids.length"
                       @change="selected = $event.target.checked ? ids.slice() : []"
                       aria-label="Select every product not yet on this store">
            </th>
            @endif
            <th scope="col" class="cc-col-id">ID</th>
            <th scope="col">Product</th>
            <th scope="col" class="cc-col-qty x-td-num">Stock</th>
            <th scope="col" class="cc-col-price x-td-num">Price</th>
            <th scope="col" class="cc-col-sync">On this store</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($products as $row)
        @php
            $pid = (int) $row->product_id;
            $onThisStore = ! is_null($row->listing_id);
            $thumbSrc = $productThumbsById->get($pid);
            $pName = (string) ($row->name ?? '');
            $pSku = (string) ($row->sku ?? '');
            $pMfg = (string) ($row->manufacturer_name ?? '');
            $pStatus = (int) ($row->status ?? 0);
            $rowLabel = $pName !== '' ? $pName : ($pSku !== '' ? $pSku : 'product ' . $pid);
        @endphp
        <tr @class(['cc-row--muted' => $onThisStore])>
            @if($canManageLazada)
            <td class="cc-col-check">
                @unless($onThisStore)
                    <input type="checkbox" class="cc-check" value="{{ (string) $pid }}"
                           x-model="selected" aria-label="Select {{ $rowLabel }} to add">
                @endunless
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
                        <span class="co-item__name">{{ $pName ?: 'Unnamed product' }}</span>
                        <div class="co-item__meta">
                            <x-ui.badge :tone="$pStatus ? 'success' : 'neutral'">{{ $pStatus ? 'Enabled' : 'Disabled' }}</x-ui.badge>
                            <span class="co-item__sku">{{ $pSku ?: 'No SKU' }}</span>
                            @if($pMfg !== '')<span>{{ $pMfg }}</span>@endif
                        </div>
                    </div>
                </div>
            </td>

            <td class="cc-col-qty x-td-num" data-label="Stock"><span class="x-num">{{ (int) ($row->quantity ?? 0) }}</span></td>
            <td class="cc-col-price x-td-num" data-label="Price"><x-money :php="(float) $row->price" /></td>

            <td class="cc-col-sync" data-label="On this store">
                @if($onThisStore)
                    <span class="cc-onstore"><x-ui.icon name="check" size="14" /> On this store</span>
                @else
                    <span class="x-cell-muted">Not yet</span>
                @endif
            </td>

            <td class="x-td-actions">
                @if($canManageLazada)
                    @if($onThisStore)
                        <a class="cc-onstore-link" href="{{ route('ext.lazada.products.index') }}">Manage</a>
                    @else
                        <form method="POST" action="{{ route('ext.lazada.products.add_to_store', $pid) }}">
                            @csrf
                            <x-ui.button type="submit" size="sm" variant="primary">Add to store</x-ui.button>
                        </form>
                    @endif
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$paginator" />
@endif

</div>
@endsection
