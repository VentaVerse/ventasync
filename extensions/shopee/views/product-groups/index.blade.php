@extends('layouts.channel')
@section('breadcrumb', 'Presets')

@section('title', 'Shopee Presets')

{{-- Leaves the .x-legacy content boundary, rebuilt on the token set. --}}
@section('content')
@php
    // Read tier is `view_shopee|manage_shopee`; create, edit and destroy are
    // all `manage_shopee`. Replaces a hardcoded `true`.
    $canManageShopee = auth()->user()?->hasPermission('manage_shopee/product_group') ?? false;
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Presets</h1>
        <p class="x-page-sub">
            {{ number_format($groups->count()) }} {{ $groups->count() === 1 ? 'preset' : 'presets' }}.
            <x-ui.hint label="What a preset is">A preset holds the shared Shopee settings a push starts from - category, attributes, couriers, markup. Load it from the push review on the Listings page, or keep products under it and push them from here.</x-ui.hint>
        </p>
    </div>
    @if($canManageShopee)
        <x-ui.button variant="primary" :href="route('ext.shopee.product-groups.create')">New preset</x-ui.button>
    @endif
</div>

@if($groups->count() === 0)
    <x-ui.empty title="No presets yet"
                description="A preset holds the Shopee category, attributes, couriers and price markup a batch of products shares, so a push sets them once instead of per listing. The Listings page can also start a push from one.">
        @if($canManageShopee)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('ext.shopee.product-groups.create')">New preset</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th scope="col" class="cc-col-id">ID</th>
            <th scope="col">Preset</th>
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

            // Same two figures the old view printed, kept as separate lines
            // rather than joined with an ampersand: they are a fixed amount
            // and a percentage of the selling price, which are different
            // units and do not belong on one line reading as a sum.
            $markupFixed = !empty($p->markup_fixed) ? (float) $p->markup_fixed : null;
            $markupPercent = !empty($p->markup_percent) ? (float) $p->markup_percent : null;
        @endphp
        <tr>
            <td class="cc-col-id" data-label="ID"><span class="x-num">{{ $p->id }}</span></td>
            <td data-label="Preset">
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
                <x-ui.menu label="Preset {{ $p->name }} actions">
                    <a class="x-menu__item" href="{{ $productsUrl }}">Products in this preset</a>
                    @if($canManageShopee)
                        <a class="x-menu__item" href="{{ route('ext.shopee.product-groups.edit', $p->id) }}">Edit preset</a>
                        <div class="x-menu__sep"></div>
                        {{-- Destroy is never a standing control: it sits in the
                             overflow menu behind the shared confirm dialog and
                             drives a form outside this table, because a form
                             cannot be nested inside a row that is itself a
                             stretched link. --}}
                        <button type="button" class="x-menu__item x-menu__item--danger"
                                data-confirm="Delete the preset {{ $p->name }}? Its products stay in the catalog and stay listed on Shopee. Only the grouping is removed."
                                data-confirm-submit="spg-del-{{ $p->id }}">Delete preset</button>
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

</div>{{-- /.cc-page --}}
@endsection
