@extends('layouts.channel')
@section('breadcrumb', 'Product Groups')

@section('title', 'Shopee Product Groups')

@section('content')
@php
    $canManageShopee = auth()->user()?->hasPermission('manage_shopee/product_group') ?? false;
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Product Groups</h1>
        <p class="x-page-sub">
            {{ number_format($groups->count()) }} {{ $groups->count() === 1 ? 'product group' : 'product groups' }}.
            <x-ui.hint label="What a product group is">A product group holds the shared Shopee settings a push starts from - category, attributes, couriers, markup. Load it from the push review on the Listings page, or keep products under it and push them from here.</x-ui.hint>
        </p>
    </div>
    @if($canManageShopee)
        <x-ui.button variant="primary" :href="route('ext.shopee.product-groups.create')">New product group</x-ui.button>
    @endif
</div>

@if($groups->count() === 0)
    <x-ui.empty title="No product groups yet">
        @if($canManageShopee)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('ext.shopee.product-groups.create')">New product group</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th scope="col" class="cc-col-id">ID</th>
            <th scope="col">Product group</th>
            <th scope="col" class="cc-col-count x-td-num">Products</th>
            <th scope="col">Shopee category</th>
            <th scope="col" class="cc-col-group">Couriers</th>
            <th scope="col" class="cc-col-markup">Markup</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($groups as $p)
        @php
            $pCount = $productCounts->get($p->id, 0);
            $logCount = count($p->logistic_ids ?? []);
            $productsUrl = route('ext.shopee.product-groups.products', $p->id);

            $markupFixed = !empty($p->markup_fixed) ? (float) $p->markup_fixed : null;
            $markupPercent = !empty($p->markup_percent) ? (float) $p->markup_percent : null;
        @endphp
        <tr>
            <td class="cc-col-id" data-label="ID"><span class="x-num">{{ $p->id }}</span></td>
            <td data-label="Product group">
                <a class="x-row-link" href="{{ $productsUrl }}">{{ $p->name }}</a>
            </td>
            <td class="cc-col-count x-td-num" data-label="Products"><span class="x-num">{{ number_format($pCount) }}</span></td>
            <td data-label="Shopee category">
                @if($p->shopee_category_id)
                    <span class="x-cell-strong">{{ $shopeeCategoryNames->get($p->shopee_category_id, $p->shopee_category_id) }}</span>
                    <span class="cc-sub cc-sub--mono">{{ $p->shopee_category_id }}</span>
                @else
                    <span class="x-cell-muted">Not set</span>
                @endif
            </td>
            <td class="cc-col-group" data-label="Couriers">
                @if($logCount > 0)
                    <span class="x-num">{{ $logCount }}</span> {{ $logCount === 1 ? 'courier' : 'couriers' }}
                @else
                    <span class="x-cell-muted">Not set</span>
                @endif
            </td>
            <td class="cc-col-markup" data-label="Markup">
                @if($markupFixed !== null || $markupPercent !== null)
                    @if($markupFixed !== null)
                        <x-money :php="$markupFixed" />
                    @endif
                    @if($markupPercent !== null)
                        <span class="cc-markup__pct">{{ $markupFixed !== null ? 'plus ' : '' }}{{ rtrim(rtrim(number_format($markupPercent, 2), '0'), '.') }}% of price</span>
                    @endif
                @else
                    <span class="x-cell-muted">None</span>
                @endif
            </td>
            <td class="x-td-actions">
                <x-ui.menu label="Product group {{ $p->name }} actions">
                    <a class="x-menu__item" href="{{ $productsUrl }}">Products in this product group</a>
                    @if($canManageShopee)
                        <a class="x-menu__item" href="{{ route('ext.shopee.product-groups.edit', $p->id) }}">Edit product group</a>
                        <div class="x-menu__sep"></div>
                        <button type="button" class="x-menu__item x-menu__item--danger"
                                data-confirm="Delete the product group {{ $p->name }}? Its products stay in the catalog and stay listed on Shopee. Only the grouping is removed."
                                data-confirm-submit="spg-del-{{ $p->id }}">Delete product group</button>
                    @endif
                </x-ui.menu>
            </td>
        </tr>
    @endforeach
</x-ui.table>
@endif

@if($canManageShopee)
    @foreach($groups as $p)
        <form id="spg-del-{{ $p->id }}" method="POST" action="{{ route('ext.shopee.product-groups.destroy', $p->id) }}" class="x-sr">
            @csrf
            @method('DELETE')
        </form>
    @endforeach
@endif

</div>
@endsection
