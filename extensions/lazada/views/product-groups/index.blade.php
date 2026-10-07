@extends('layouts.channel')
@section('breadcrumb', 'Product Groups')

@section('title', 'Lazada Product Groups')

@section('content')
@php
    $canManageLazada = auth()->user()?->hasPermission('manage_lazada/product_group') ?? false;
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Product Groups</h1>
        <p class="x-page-sub">
            {{ number_format($groups->count()) }} {{ $groups->count() === 1 ? 'product group' : 'product groups' }}.
            <x-ui.hint label="What a product group is">A product group holds the shared Lazada settings a push starts from - category, brand, attributes, markup. Keep products under it and push them from here, or work per listing on the Listings page.</x-ui.hint>
        </p>
    </div>
    @if($canManageLazada)
        <x-ui.button variant="primary" :href="route('ext.lazada.product-groups.create')">New product group</x-ui.button>
    @endif
</div>

@if($groups->count() === 0)
    <x-ui.empty title="No product groups yet">
        @if($canManageLazada)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('ext.lazada.product-groups.create')">New product group</x-ui.button>
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
            <th scope="col">Lazada category</th>
            <th scope="col" class="cc-col-markup">Markup</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($groups as $g)
        @php
            $pCount = $productCounts->get($g->id, 0);
            $productsUrl = route('ext.lazada.product-groups.products', $g->id);

            $markupFixed = !empty($g->markup_fixed) ? (float) $g->markup_fixed : null;
            $markupPercent = !empty($g->markup_percent) ? (float) $g->markup_percent : null;
        @endphp
        <tr>
            <td class="cc-col-id" data-label="ID"><span class="x-num">{{ $g->id }}</span></td>
            <td data-label="Product group">
                <a class="x-row-link" href="{{ $productsUrl }}">{{ $g->name }}</a>
            </td>
            <td class="cc-col-count x-td-num" data-label="Products"><span class="x-num">{{ number_format($pCount) }}</span></td>
            <td data-label="Lazada category">
                @if($g->lazada_category_id)
                    <span class="x-cell-strong">{{ $lazadaCategoryNames->get($g->lazada_category_id, $g->lazada_category_id) }}</span>
                    <span class="cc-sub cc-sub--mono">{{ $g->lazada_category_id }}</span>
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
                <x-ui.menu label="Product group {{ $g->name }} actions">
                    <a class="x-menu__item" href="{{ $productsUrl }}">Products in this product group</a>
                    @if($canManageLazada)
                        <a class="x-menu__item" href="{{ route('ext.lazada.product-groups.edit', $g->id) }}">Edit product group</a>
                        <div class="x-menu__sep"></div>
                        <button type="button" class="x-menu__item x-menu__item--danger"
                                data-confirm="Delete the product group {{ $g->name }}? Listings in no other product group that were never uploaded to Lazada are removed from this list with it. Nothing changes in the catalog, and nothing already live on Lazada is touched."
                                data-confirm-submit="lpg-del-{{ $g->id }}">Delete product group</button>
                    @endif
                </x-ui.menu>
            </td>
        </tr>
    @endforeach
</x-ui.table>
@endif

@if($canManageLazada)
    @foreach($groups as $g)
        <form id="lpg-del-{{ $g->id }}" method="POST" action="{{ route('ext.lazada.product-groups.destroy', $g->id) }}" class="x-sr">
            @csrf
            @method('DELETE')
        </form>
    @endforeach
@endif

</div>
@endsection
