@extends('layouts.channel')
@section('title', $setting->store_name . ' Product Groups')
@section('breadcrumb', 'Product Groups')

{{-- Leaves the .x-legacy content boundary, rebuilt on the token set.

     Venta is multi-store: every self-link on this page carries the {store}
     segment it arrived on ($setting->id), and the store is named on the page
     as well as in the breadcrumb, because the trail collapses to the leaf
     alone below 768px. --}}
@section('content')
@php
    // Read tier on this route is `view_venta|manage_venta`; create, update and
    // destroy are `manage_venta`. Replaces a hardcoded `true`, which showed a
    // read-only operator four controls the routes would refuse.
    $canManageVenta = auth()->user()?->hasPermission('manage_venta/product_group') ?? false;

    $storeName = $setting->store_name ?: 'Unnamed store';
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Product Groups</h1>
        <p class="x-page-sub">
            {{ number_format($groups->count()) }} {{ $groups->count() === 1 ? 'group' : 'groups' }} on {{ $storeName }}.
            <x-ui.hint label="What a product group is">A group is a set of catalog products pushed to this Venta store together, with one price markup.</x-ui.hint>
        </p>
    </div>
    @if($canManageVenta)
        <x-ui.button variant="primary" :href="route('ext.venta.product-groups.create', $setting->id)">New group</x-ui.button>
    @endif
</div>

@if($groups->count() === 0)
    <x-ui.empty title="No product groups yet"
                :description="'Nothing has been grouped for ' . $storeName . ' yet. A group is how a batch of catalog products gets its price markup and its push schedule in one place, instead of setting them per product.'">
        @if($canManageVenta)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('ext.venta.product-groups.create', $setting->id)">New group</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th scope="col" class="cc-col-id">ID</th>
            <th scope="col">Group</th>
            <th scope="col" class="cc-col-count x-td-num">Products</th>
            <th scope="col" class="cc-col-markup">Markup</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($groups as $g)
        @php
            $pCount = $productCounts[$g->id] ?? 0;
            $productsUrl = route('ext.venta.product-groups.products', [$setting->id, $g->id]);

            // Already on the loaded model, so this costs no query. Two figures
            // in different units, so two lines rather than one string that
            // reads as a sum.
            $markupFixed = !empty($g->markup_fixed) ? (float) $g->markup_fixed : null;
            $markupPercent = !empty($g->markup_percent) ? (float) $g->markup_percent : null;
        @endphp
        <tr>
            <td class="cc-col-id" data-label="ID"><span class="x-num">{{ $g->id }}</span></td>
            <td data-label="Group">
                <a class="x-row-link" href="{{ $productsUrl }}">{{ $g->name }}</a>
            </td>
            <td class="cc-col-count x-td-num" data-label="Products"><span class="x-num">{{ number_format($pCount) }}</span></td>
            <td class="cc-col-markup" data-label="Markup">
                @if($markupFixed !== null || $markupPercent !== null)
                    @if($markupPercent !== null)
                        <span class="cc-markup__pct">{{ rtrim(rtrim(number_format($markupPercent, 2), '0'), '.') }}% of price</span>
                    @endif
                    @if($markupFixed !== null)
                        <x-money :php="$markupFixed" />
                    @endif
                @else
                    <span class="x-cell-muted">None</span>
                @endif
            </td>
            <td class="x-td-actions">
                {{-- The old row carried three standing buttons, one of them a
                     solid red Delete, on every line. The row is the link to its
                     products now, and the rest sit in the overflow. --}}
                <x-ui.menu label="Group {{ $g->name }} actions">
                    <a class="x-menu__item" href="{{ $productsUrl }}">Products in this group</a>
                    @if($canManageVenta)
                        <a class="x-menu__item" href="{{ route('ext.venta.product-groups.edit', [$setting->id, $g->id]) }}">Edit group</a>
                        <div class="x-menu__sep"></div>
                        {{-- Destroy sits in the overflow behind the shared
                             confirm dialog and drives a form outside this
                             table, because a form cannot be nested inside a row
                             that is itself a stretched link. --}}
                        <button type="button" class="x-menu__item x-menu__item--danger"
                                data-confirm="Delete the group {{ $g->name }} from {{ $storeName }}? Its product links go with it. Nothing changes in the catalog, and nothing already live on the Venta store is touched."
                                data-confirm-submit="vpg-del-{{ $g->id }}">Delete group</button>
                    @endif
                </x-ui.menu>
            </td>
        </tr>
    @endforeach
</x-ui.table>
@endif

@if($canManageVenta)
    @foreach($groups as $g)
        <form id="vpg-del-{{ $g->id }}" method="POST" action="{{ route('ext.venta.product-groups.destroy', [$setting->id, $g->id]) }}" class="x-sr">
            @csrf
            @method('DELETE')
        </form>
    @endforeach
@endif

</div>{{-- /.cc-page --}}
@endsection
