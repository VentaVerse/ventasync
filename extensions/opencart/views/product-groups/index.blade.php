@extends('layouts.channel')
@section('title', $setting->store_name . ' Product Groups')
@section('breadcrumb', 'Product Groups')

{{-- Leaves the .x-legacy content boundary, rebuilt on the token set.

     OpenCart is multi-store: every self-link on this page carries the {store}
     segment it arrived on ($setting->id), and the store is named on the page
     as well as in the breadcrumb, because the trail collapses to the leaf
     alone below 768px. --}}
@section('content-class', '')

@section('content')
@php
    // Read tier on this route is `view_opencart|manage_opencart`; create,
    // update and destroy are `manage_opencart`. Replaces a hardcoded `true`.
    $canManageOpencart = auth()->user()?->hasPermission('manage_opencart') ?? false;

    $storeName = $setting->store_name ?: 'Unnamed store';
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Product Groups</h1>
        <p class="x-page-sub">
            {{ number_format($groups->count()) }} {{ $groups->count() === 1 ? 'group' : 'groups' }} on {{ $storeName }}. A group picks catalogue products by category and manufacturer, then pushes them to this store together.
        </p>
    </div>
    @if($canManageOpencart)
        <x-ui.button variant="primary" :href="route('ext.opencart.product-groups.create', $setting->id)">New group</x-ui.button>
    @endif
</div>

@if($groups->count() === 0)
    <x-ui.empty title="No product groups yet"
                :description="'Nothing has been grouped for ' . $storeName . ' yet. A group picks catalogue products by category and manufacturer so a whole batch can be pushed at once, instead of one product at a time.'">
        @if($canManageOpencart)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('ext.opencart.product-groups.create', $setting->id)">New group</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th class="cc-col-id">ID</th>
            <th>Group</th>
            <th class="cc-col-count x-td-num">Products</th>
            <th class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($groups as $g)
        @php
            $pCount = $productCounts[$g->id] ?? 0;
            $productsUrl = route('ext.opencart.product-groups.products', [$setting->id, $g->id]);

            // The same two lists the old view joined into one
            // "Cat: ... | Mfg: ..." cell. They are what decides which
            // catalogue products fall into the group, so they read as a
            // sentence under the group's own name.
            $catNames = collect($g->catalog_category_ids ?? [])->map(fn ($id) => $categoryNames->get($id))->filter()->values();
            $mfgNames = collect($g->manufacturer_ids ?? [])->map(fn ($id) => $manufacturerNames->get($id))->filter()->values();

            $filterParts = [];
            if ($catNames->isNotEmpty()) {
                $filterParts[] = 'Categories: ' . $catNames->implode(', ');
            }
            if ($mfgNames->isNotEmpty()) {
                $filterParts[] = 'Manufacturers: ' . $mfgNames->implode(', ');
            }
        @endphp
        <tr>
            <td class="cc-col-id" data-label="ID"><span class="x-num">{{ $g->id }}</span></td>
            <td data-label="Group">
                <a class="x-row-link" href="{{ $productsUrl }}">{{ $g->name }}</a>
                @if(!empty($filterParts))
                    <span class="cc-sub">{{ implode('. ', $filterParts) }}</span>
                @else
                    <span class="cc-sub">Products are added by hand, not by a filter</span>
                @endif
            </td>
            <td class="cc-col-count x-td-num" data-label="Products"><span class="x-num">{{ number_format($pCount) }}</span></td>
            <td class="x-td-actions">
                {{-- The old row carried three standing buttons, one of them a
                     solid red Delete, on every line. The row is the link to its
                     products now, and the rest sit in the overflow. --}}
                <x-ui.menu label="Group {{ $g->name }} actions">
                    <a class="x-menu__item" href="{{ $productsUrl }}">Products in this group</a>
                    @if($canManageOpencart)
                        <a class="x-menu__item" href="{{ route('ext.opencart.product-groups.edit', [$setting->id, $g->id]) }}">Edit group</a>
                        <div class="x-menu__sep"></div>
                        {{-- Destroy sits in the overflow behind the shared
                             confirm dialog and drives a form outside this
                             table, because a form cannot be nested inside a row
                             that is itself a stretched link. --}}
                        <button type="button" class="x-menu__item x-menu__item--danger"
                                data-confirm="Delete the group {{ $g->name }} from {{ $storeName }}? Its product links go with it. Nothing changes in the catalogue, and nothing already on the OpenCart store is touched."
                                data-confirm-submit="ocpg-del-{{ $g->id }}">Delete group</button>
                    @endif
                </x-ui.menu>
            </td>
        </tr>
    @endforeach
</x-ui.table>
@endif

@if($canManageOpencart)
    @foreach($groups as $g)
        <form id="ocpg-del-{{ $g->id }}" method="POST" action="{{ route('ext.opencart.product-groups.destroy', [$setting->id, $g->id]) }}" class="x-sr">
            @csrf
            @method('DELETE')
        </form>
    @endforeach
@endif

</div>{{-- /.cc-page --}}
@endsection
