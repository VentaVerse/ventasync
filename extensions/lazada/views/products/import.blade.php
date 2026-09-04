@extends('layouts.channel')
@section('title', 'Import from Lazada')
@section('breadcrumb', 'Import')

{{-- The Import page, stateless (the owner, 2026-09-04): nothing about the
     store's items is saved. The page is blank until Fetch reads the store
     live; the rows exist only in this render and act through the values
     they carry. Refreshing re-fetches; leaving forgets. --}}

@section('content')

@php
    $canManage = auth()->user()?->hasPermission('manage_lazada/product') ?? false;
    $rows = $fetched['rows'] ?? [];
    $storeLabel = 'Lazada';
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Import from {{ $storeLabel }}</h1>
        <p class="x-page-sub">
            @if($fetched !== null)
                Read {{ number_format($fetched['found']) }} {{ $fetched['found'] === 1 ? 'item' : 'items' }} on Lazada just now; {{ count($rows) === 0 ? 'all of them match your main catalog.' : number_format(count($rows)) . ' ' . (count($rows) === 1 ? 'is' : 'are') . ' not in your main catalog.' }}
            @else
                Bring items that exist on Lazada into your main catalog. Nothing here is stored: fetch to see the live list.
            @endif
        </p>
    </div>
    @if($canManage && ($fetched !== null || $fetchError))
        <div class="cc-head-actions">
            <form method="GET" action="{{ route('ext.lazada.products.import') }}" data-slow-action>
                <input type="hidden" name="fetch" value="1">
                <x-ui.button type="submit" variant="secondary">Fetch again</x-ui.button>
            </form>
        </div>
    @endif
</div>

@if($canManage)
<section class="fm-section cc-unmatched">

    @if($fetchError)
        <p class="fm-section__note fm-section__note--strong">{{ $fetchError }}</p>
    @elseif($fetched === null)
        {{-- The first-run state: an empty state with the Fetch CTA,
             the detail in a tooltip, not a paragraph in a blank card. --}}
        <x-ui.empty title="Nothing fetched yet"
                    description="Fetch reads Lazada live and compares it against your main catalog. Anything the catalog cannot place is listed here to import or link. Nothing is stored on this side, so every visit reads the store as it is right now.">
            <x-slot:action>
                <form method="GET" action="{{ route('ext.lazada.products.import') }}" data-slow-action>
                    <input type="hidden" name="fetch" value="1">
                    <x-ui.button type="submit" variant="primary">Fetch from {{ $storeLabel }}</x-ui.button>
                </form>
            </x-slot:action>
        </x-ui.empty>
    @elseif(count($rows) === 0)
        <x-ui.empty title="Everything matches"
                    description="Every item on Lazada already maps to a SKU in your main catalog. Nothing to import." />
    @else
        <p class="fm-section__note">These items exist on Lazada but not in your main catalog. <x-ui.hint label="How importing works">Import one, or tick several and import them together, to create the real product in your main catalog - details, variations and images included. Or link it to a catalog product it already is.</x-ui.hint></p>

        {{-- Import selected: the checkboxes in the table belong to this
             form through form="", so one press imports every ticked row,
             each re-read live and in its own transaction. --}}
        {{-- The bar appears only once a row is ticked (channel-import.js),
             the same rule the products and listings pages hold. --}}
        <div class="cc-bulkbar" data-import-bar hidden>
            <span class="cc-bulkbar__count"><span data-import-count>0</span> selected</span>
            <form id="cc-import-selected" method="POST" action="{{ route('ext.lazada.products.import_selected') }}"
                  class="cc-bulkbar__actions" data-slow-action
              data-confirm="Import the selected items into your main catalog?"
              data-confirm-one="Import the selected item into your main catalog? A real product is created there - name, price, stock, variations and images - and linked. Nothing changes on Lazada."
              data-confirm-many="Import the :n selected items into your main catalog? A real product is created there for each one - name, price, stock, variations and images - and linked. Nothing changes on Lazada."
              data-confirm-tone="primary" data-confirm-verb="Import">
                @csrf
                <x-ui.button type="submit" size="sm" variant="primary" data-import-selected-button>Import selected</x-ui.button>
            </form>
        </div>

        <x-ui.table>
            <x-slot:head>
                <tr>
                    <th scope="col" class="cc-col-check"><input type="checkbox" class="cc-check" data-import-check-all aria-label="Select all for import"></th>
                    <th scope="col">Lazada item</th>
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
                    <td data-label="Lazada item">
                        <div class="co-item">
                            <div class="cc-thumb cc-thumb--sm">
                                <x-ui.icon name="image" size="15" class="co-item__ph" />
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
                        <form method="POST" action="{{ route('ext.lazada.products.link_item') }}"
                              class="cc-link"
                              data-unmatched-link
                              data-search-url="{{ route('ext.lazada.products.search_catalog') }}">
                            @csrf
                            <input type="hidden" name="ref" value="{{ $row['ref'] }}">
                            <div class="fm-ta">
                                <input type="text" class="x-input" data-unmatched-search
                                       placeholder="Search name, model or SKU" autocomplete="off"
                                       aria-label="Search the catalog for the product this Lazada item is">
                                <div class="fm-ta__list" data-unmatched-results role="listbox" aria-label="Catalog matches"></div>
                            </div>
                            <input type="hidden" name="product_id" value="" data-unmatched-id>
                            <x-ui.button type="submit" size="sm" disabled data-unmatched-submit>Link</x-ui.button>
                        </form>
                    </td>
                    <td class="x-td-actions">
                        <div class="cc-rowact">
                            <form method="POST" action="{{ route('ext.lazada.products.import_one') }}" data-slow-action
                                  data-confirm="Import this Lazada item into your main catalog? A real product is created there - name, price, stock, variations and images - and linked to the Lazada item. Nothing changes on Lazada." data-confirm-tone="primary" data-confirm-verb="Import">
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
    <p class="fm-section__note">Importing needs the Lazada manage permission.</p>
</section>
@endif

<x-channel.import-progress name="Lazada" />

</div>
@endsection
