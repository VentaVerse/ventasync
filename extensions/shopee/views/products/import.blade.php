@extends('layouts.channel')
@section('title', 'Import from Shopee')
@section('breadcrumb', 'Import')

{{-- The Import page, stateless (the owner, 2026-09-04): nothing about the
     store's items is saved. The page is blank until Fetch reads the store
     live; the rows exist only in this render and act through the values
     they carry. Refreshing re-fetches; leaving forgets. --}}

@section('content')

@php
    $canManage = auth()->user()?->hasPermission('manage_shopee/product') ?? false;
    $rows = $fetched['rows'] ?? [];
    $storeLabel = 'Shopee';
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Import from {{ $storeLabel }}</h1>
        <p class="x-page-sub">
            @if($fetched !== null)
                Read {{ number_format($fetched['found']) }} {{ $fetched['found'] === 1 ? 'item' : 'items' }} on Shopee just now; {{ count($rows) === 0 ? 'all of them match your main catalog.' : number_format(count($rows)) . ' ' . (count($rows) === 1 ? 'is' : 'are') . ' not in your main catalog.' }}
            @else
                Bring items that exist on Shopee into your main catalog. Nothing here is stored: fetch to see the live list.
            @endif
        </p>
    </div>
    @if($canManage)
        <div class="cc-head-actions">
            <form method="GET" action="{{ route('ext.shopee.products.import') }}" data-slow-action>
                <input type="hidden" name="fetch" value="1">
                <x-ui.button type="submit" variant="{{ $fetched !== null ? 'secondary' : 'primary' }}">{{ $fetched !== null ? 'Fetch again' : 'Fetch from ' . $storeLabel }}</x-ui.button>
            </form>
        </div>
    @endif
</div>

@if($canManage)
<section class="fm-section cc-unmatched">

    @if($fetchError)
        <p class="fm-section__note fm-section__note--strong">{{ $fetchError }}</p>
    @elseif($fetched === null)
        <p class="fm-section__note">Nothing is fetched yet. Press Fetch and the shop's items are read live and compared against your main catalog's SKUs; anything the catalog cannot place is listed here to import or link. <x-ui.hint label="Why the page starts blank">Nothing from the store is saved on this side, so there is no stale list to mislead you - every visit reads the store as it is right now.</x-ui.hint></p>
    @elseif(count($rows) === 0)
        <p class="fm-section__note">Everything on Shopee matches a SKU in your main catalog. Nothing to import.</p>
    @else
        <p class="fm-section__note">These items exist on Shopee but not in your main catalog. Import one - or tick several and import them together - to create the real product in your main catalog, details, variations and images included; or link it to a catalog product it already is.</p>

        {{-- Import selected: the checkboxes in the table belong to this
             form through form="", so one press imports every ticked row,
             each re-read live and in its own transaction. --}}
        <form id="cc-import-selected" method="POST" action="{{ route('ext.shopee.products.import_selected') }}"
              class="cc-import-bulk" data-slow-action
              data-confirm="Import the selected items into your main catalog?"
              data-confirm-one="Import the selected item into your main catalog? A real product is created there - name, price, stock, variations and images - and linked. Nothing changes on Shopee."
              data-confirm-many="Import the :n selected items into your main catalog? A real product is created there for each one - name, price, stock, variations and images - and linked. Nothing changes on Shopee."
              data-confirm-tone="primary" data-confirm-verb="Import">
            @csrf
            <x-ui.button type="submit" size="sm" variant="primary" disabled data-import-selected-button>Import selected</x-ui.button>
        </form>

        <x-ui.table>
            <x-slot:head>
                <tr>
                    <th scope="col" class="cc-col-check"><input type="checkbox" class="cc-check" data-import-check-all aria-label="Select all for import"></th>
                    <th scope="col">Shopee item</th>
                    <th scope="col" class="cc-col-sku">SKU</th>
                    <th scope="col" class="cc-col-link">Catalog product</th>
                    <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
                </tr>
            </x-slot:head>

            @foreach($rows as $row)
                <tr>
                    <td class="cc-col-check" data-label="Select">
                        <input type="checkbox" class="cc-check" form="cc-import-selected" name="refs[]" value="{{ $row['ref'] }}" data-import-check aria-label="Select {{ $row['name'] ?: $row['sku'] }} for import">
                    </td>
                    <td data-label="Shopee item">
                        <div class="co-item">
                            <div class="cc-thumb cc-thumb--sm">
                                <span class="co-item__none">None</span>
                                @if($row['image_url'])
                                    <img src="{{ $row['image_url'] }}" alt="" loading="lazy" decoding="async" data-thumb>
                                @endif
                            </div>
                            <div class="co-item__body">
                                <span class="co-item__name">{{ $row['name'] ?: 'Unnamed item' }}</span>
                                @if(($row['variants'] ?? 0) > 0)
                                    <span class="co-item__meta">{{ $row['variants'] }} {{ $row['variants'] === 1 ? 'variation' : 'variations' }}</span>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="cc-col-sku" data-label="SKU">
                        @if($row['sku'])
                            <span class="x-num">{{ $row['sku'] }}</span>
                        @else
                            <span class="x-cell-muted">None</span>
                        @endif
                    </td>
                    <td class="cc-col-link" data-label="Catalog product">
                        <form method="POST" action="{{ route('ext.shopee.products.link_item') }}"
                              class="cc-link"
                              data-unmatched-link
                              data-search-url="{{ route('ext.shopee.products.search_catalog') }}">
                            @csrf
                            <input type="hidden" name="ref" value="{{ $row['ref'] }}">
                            <input type="hidden" name="sku" value="{{ $row['sku'] }}">
                            <div class="fm-ta">
                                <input type="text" class="x-input" data-unmatched-search
                                       placeholder="Search name, model or SKU" autocomplete="off"
                                       aria-label="Search the catalog for the product this Shopee item is">
                                <div class="fm-ta__list" data-unmatched-results role="listbox" aria-label="Catalog matches"></div>
                            </div>
                            <input type="hidden" name="product_id" value="" data-unmatched-id>
                            <x-ui.button type="submit" size="sm" disabled data-unmatched-submit>Link</x-ui.button>
                        </form>
                    </td>
                    <td class="x-td-actions">
                        <div class="cc-rowact">
                            <form method="POST" action="{{ route('ext.shopee.products.import_one') }}" data-slow-action
                                  data-confirm="Import this Shopee item into your main catalog? A real product is created there - name, price, stock, variations and images - and linked to the Shopee item. Nothing changes on Shopee." data-confirm-tone="primary" data-confirm-verb="Import">
                                @csrf
                                <input type="hidden" name="ref" value="{{ $row['ref'] }}">
                                <x-ui.button type="submit" size="sm" variant="primary">Import</x-ui.button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif

</section>
@else
<section class="fm-section">
    <p class="fm-section__note">Importing needs the Shopee manage permission.</p>
</section>
@endif

<x-channel.import-progress name="Shopee" />

</div>
@endsection
