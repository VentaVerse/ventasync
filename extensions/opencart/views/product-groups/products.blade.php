@extends('layouts.channel')
@section('title', $group->name . ' Products, ' . $setting->store_name)
@section('breadcrumb', $group->name . ' Products')

@section('content')
@php
    $canManageOpencart = auth()->user()?->hasPermission('manage_opencart/product_group') ?? false;

    $storeName = $setting->store_name ?: 'Unnamed store';

    $q = (string) ($q ?? '');
    $hasSearch = $q !== '';

    $listUrl = route('ext.opencart.product-groups.products', [$setting->id, $group->id]);
@endphp

<div class="cc-page"
     x-data="{
        selected: [],
        ids: {{ \Illuminate\Support\Js::from(collect($products->items())->pluck('product_id')->map(fn ($v) => (string) $v)->values()) }}
     }">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">{{ $group->name }}</h1>
        <p class="x-page-sub">
            {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product' : 'products' }} in this product group on {{ $storeName }}
        </p>
    </div>
    @if($canManageOpencart)
        <x-ui.button :href="route('ext.opencart.product-groups.edit', [$setting->id, $group->id])">Edit product group</x-ui.button>
    @endif
</div>

<form method="GET" action="{{ $listUrl }}" class="x-filters">
    <x-ui.input type="search" name="q" class="x-filters__search"
                value="{{ $q }}" placeholder="Name, SKU or model" aria-label="Search products in this product group" />
    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
    @if($hasSearch)
        <a class="x-filters__reset" href="{{ $listUrl }}">Clear</a>
    @endif
</form>

@if($canManageOpencart)
<div class="cc-bulkbar" x-show="selected.length > 0" x-cloak data-bulkbar role="status">
    <span class="cc-bulkbar__count" aria-live="polite"><span class="x-num" x-text="selected.length"></span> selected</span>
    <div class="cc-bulkbar__actions">
        <form method="POST" action="{{ route('ext.opencart.product-groups.push', [$setting->id, $group->id]) }}" data-bulk-form
              data-bulk-confirm="Push the selected products to {{ $storeName }}? Anything already there is overwritten with the catalog's version.">
            @csrf
            <x-ui.button type="submit" size="sm">Push to {{ $storeName }}</x-ui.button>
        </form>
    </div>
</div>
@endif

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasSearch ? 'Nothing matches that search' : 'No products in this product group' }}"
                :description="$hasSearch
                    ? 'Clear the search to see everything in the product group.'
                    : 'Edit the product group to widen its category and manufacturer filter, or add products one at a time below.'">
        @if($canManageOpencart && !$hasSearch)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('ext.opencart.product-groups.edit', [$setting->id, $group->id])">Edit product group</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            @if($canManageOpencart)
            <th scope="col" class="cc-col-check">
                <input type="checkbox" class="cc-check"
                       :checked="ids.length > 0 && selected.length === ids.length"
                       x-effect="$el.indeterminate = selected.length > 0 && selected.length < ids.length"
                       @change="selected = $event.target.checked ? ids.slice() : []"
                       aria-label="Select every product on this page">
            </th>
            @endif
            <th scope="col" class="cc-col-id">ID</th>
            <th scope="col">Product</th>
            <th scope="col" class="cc-col-qty x-td-num">Stock</th>
            <th scope="col" class="cc-col-price x-td-num">Price</th>
            <th scope="col" class="cc-col-stat">Catalog</th>
            <th scope="col" class="cc-col-chanid">This store</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($products as $p)
        @php
            $link = $links->get($p->product_id);
            $isManual = in_array((int) $p->product_id, $manualIds, true);

            $img = trim((string) ($p->image ?? ''));
            $thumbSrc = $img !== '' ? \App\Services\Media\ImageCache::url($img) : null;

            $pName = html_entity_decode((string) ($p->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $pSku = (string) ($p->sku ?: ($p->model ?: ''));
        @endphp
        <tr>
            @if($canManageOpencart)
            <td class="cc-col-check">
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
                            <img src="{{ $thumbSrc }}" alt="" loading="lazy" decoding="async" data-thumb>
                        @endif
                    </div>
                    <div class="co-item__body">
                        <a class="x-row-link co-item__name" href="{{ route('products.edit', $p->product_id) }}">{{ $pName ?: 'Unnamed product' }}</a>
                        <span class="co-item__meta">
                            {{ $pSku !== '' ? $pSku : 'No SKU' }} / {{ $isManual ? 'added by hand' : 'matched by the product group filter' }}
                        </span>
                    </div>
                </div>
            </td>

            <td class="cc-col-qty x-td-num" data-label="Stock"><span class="x-num">{{ number_format((int) $p->quantity) }}</span></td>

            <td class="cc-col-price x-td-num" data-label="Price">
                <x-money :php="(float) $p->price" />
            </td>

            <td class="cc-col-stat" data-label="Catalog">
                @if((int) $p->status === 1)
                    <span class="x-cell-strong">Enabled</span>
                @else
                    <span class="x-cell-muted">Disabled</span>
                @endif
            </td>

            <td class="cc-col-chanid" data-label="This store">
                @if($link)
                    <span class="x-num">{{ $link->oc_product_id }}</span>
                @else
                    <span class="x-cell-muted">Not pushed</span>
                @endif
            </td>

            <td class="x-td-actions">
                @if($canManageOpencart)
                <x-ui.menu label="Product {{ $p->product_id }} actions">
                    <a class="x-menu__item" href="{{ route('products.edit', $p->product_id) }}">Edit in catalog</a>
                    <form method="POST" action="{{ route('ext.opencart.products.push', [$setting->id, $p->product_id]) }}" data-slow-action
                          data-confirm="Push {{ $pName ?: 'this product' }} to {{ $storeName }}? Anything already there is overwritten with the catalog's version.">
                        @csrf
                        <button type="submit" class="x-menu__item">Push to {{ $storeName }}</button>
                    </form>
                    @if($isManual)
                        <div class="x-menu__sep"></div>
                        <form method="POST" action="{{ route('ext.opencart.product-groups.removeProduct', [$setting->id, $group->id, $p->product_id]) }}"
                              data-confirm="Take {{ $pName ?: 'this product' }} out of the product group? Nothing is deleted from the catalog, and nothing already on {{ $storeName }} is touched.">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="x-menu__item x-menu__item--danger">Remove from product group</button>
                        </form>
                    @endif
                </x-ui.menu>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$products" />
@endif

@if($canManageOpencart)
<section class="fm-section cc-unmatched"
         data-group-manual-add
         data-group-id="{{ $group->id }}"
         data-search-url="{{ route('ext.opencart.product-groups.searchProducts', $setting->id) }}"
         data-form="opencart-add-products">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Add a product by name</h2>
    </div>
    <p class="fm-section__note">For a product the product group's category and manufacturer filter does not pick up. Type at least two characters.</p>

    <form id="opencart-add-products" method="POST" action="{{ route('ext.opencart.product-groups.addProducts', [$setting->id, $group->id]) }}">
        @csrf
    </form>

    <div class="cc-link">
        <x-ui.input type="search" placeholder="Product name or SKU" aria-label="Search the catalog to add a product"
                    data-manual-search />
        <x-ui.button type="button" data-manual-search-go>Search</x-ui.button>
        <x-ui.button type="submit" variant="primary" form="opencart-add-products" data-manual-add disabled>Add selected</x-ui.button>
    </div>

    <div data-manual-results></div>
</section>
@endif

<div id="opencart-group-progress" class="modal-backdrop">
    <div class="modal co-modal co-modal--sm" role="dialog" aria-modal="true"
         aria-labelledby="opencart-group-progress-title" aria-describedby="opencart-group-progress-note">
        <div class="cc-progress" role="status" aria-live="polite">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div class="cc-progress__title" id="opencart-group-progress-title" data-progress-title>Talking to OpenCart</div>
                <div class="cc-progress__note" id="opencart-group-progress-note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

</div>
@endsection
