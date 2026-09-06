@extends('layouts.channel')
@section('title', 'Import from TikTok Shop')
@section('breadcrumb', 'Import')

{{-- The Import page, stateless (the owner, 2026-09-04): nothing about the
     store's items is saved. The page is blank until Fetch reads the store
     live; the rows exist only in this render and act through the values
     they carry. Refreshing re-fetches; leaving forgets. --}}

@section('content')

@php
    $canManage = auth()->user()?->hasPermission('manage_tiktok/product') ?? false;
    $rows = $fetched['rows'] ?? [];
    $storeLabel = 'TikTok Shop';
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Import from {{ $storeLabel }}</h1>
        @if($fetched !== null)
            <p class="x-page-sub">
                Read {{ number_format($fetched['found']) }} {{ $fetched['found'] === 1 ? 'item' : 'items' }} on TikTok Shop just now; {{ count($rows) === 0 ? 'all of them match your main catalog.' : number_format(count($rows)) . ' ' . (count($rows) === 1 ? 'is' : 'are') . ' not in your main catalog.' }}
            @if(!empty($fetched['truncated'])) The store holds more than that; only the first {{ number_format($fetched['found']) }} were read. Import or link some of these, then fetch again for the rest.@endif
            </p>
        @endif
    </div>
    @if($canManage && ($fetched !== null || $fetchError))
        <div class="cc-head-actions">
            <form method="GET" action="{{ route('ext.tiktok.products.import') }}" data-slow-action>
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
        {{-- First-run: a shaped initial look, not a paragraph. The
             action is self-evident, so one orienting line stands in
             for the explanation a tooltip used to carry. --}}
        <x-ui.empty title="Nothing fetched yet"
                    description="Read what is listed on TikTok Shop and bring anything missing into your main catalog.">
            <x-slot:icon><x-ui.icon name="download" size="22" /></x-slot:icon>
            <x-slot:action>
                <form method="GET" action="{{ route('ext.tiktok.products.import') }}" data-slow-action>
                    <input type="hidden" name="fetch" value="1">
                    <x-ui.button type="submit" variant="primary">Fetch from {{ $storeLabel }}</x-ui.button>
                </form>
            </x-slot:action>
        </x-ui.empty>
    @elseif(count($rows) === 0)
        <x-ui.empty title="Everything matches"
                    description="Every item on TikTok Shop already maps to a SKU in your main catalog. Nothing to import." />
    @else
        <p class="fm-section__note">These items exist on TikTok Shop but not in your main catalog. <x-ui.hint label="How importing works">Import one, or tick several and import them together, to create the real product in your main catalog - details, variations and images included. Or link it to a catalog product it already is.</x-ui.hint></p>

        {{-- Import selected: the checkboxes in the table belong to this
             form through form="", so one press imports every ticked row,
             each re-read live and in its own transaction. --}}
        {{-- The bar appears only once a row is ticked (channel-import.js),
             the same rule the products and listings pages hold. --}}
        <div class="cc-bulkbar" data-import-bar hidden>
            <span class="cc-bulkbar__count"><span data-import-count>0</span> selected</span>
            <form id="cc-import-selected" method="POST" action="{{ route('ext.tiktok.products.import_selected') }}"
                  class="cc-bulkbar__actions" data-slow-action
              data-confirm="Import the selected items into your main catalog?"
              data-confirm-one="Import the selected item into your main catalog? A real product is created there - name, price, stock, variations and images - and linked. Nothing changes on TikTok Shop."
              data-confirm-many="Import the :n selected items into your main catalog? A real product is created there for each one - name, price, stock, variations and images - and linked. Nothing changes on TikTok Shop."
              data-confirm-tone="primary" data-confirm-verb="Import">
                @csrf
                <x-ui.button type="submit" size="sm" variant="primary" data-import-selected-button>Import selected</x-ui.button>
            </form>
        </div>

        <x-ui.table>
            <x-slot:head>
                <tr>
                    <th scope="col" class="cc-col-check"><input type="checkbox" class="cc-check" data-import-check-all aria-label="Select all for import"></th>
                    <th scope="col">TikTok Shop product</th>
                    <th scope="col" class="cc-col-sku">SKU</th>
                    <th scope="col" class="cc-col-link">Catalog product</th>
                    <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
                </tr>
            </x-slot:head>

            @foreach($rows as $row)
                <tr data-import-row>
                    <td class="cc-col-check" data-label="Select">
                        <input type="checkbox" class="cc-check" form="cc-import-selected" name="refs[]" value="{{ $row['ref'] }}" data-import-check aria-label="Select {{ $row['name'] ?: $row['sku'] }} for import">
                    </td>
                    <td data-label="TikTok Shop product">
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
                        <form method="POST" action="{{ route('ext.tiktok.products.link_item') }}"
                              class="cc-link"
                              data-unmatched-link
                              data-search-url="{{ route('ext.tiktok.products.search_catalog') }}">
                            @csrf
                            <input type="hidden" name="ref" value="{{ $row['ref'] }}">
                            <div class="fm-ta">
                                <input type="text" class="x-input" data-unmatched-search
                                       placeholder="Search name, model or SKU" autocomplete="off"
                                       aria-label="Search the catalog for the product this TikTok Shop product is">
                                <div class="fm-ta__list" data-unmatched-results role="listbox" aria-label="Catalog matches"></div>
                            </div>
                            <input type="hidden" name="product_id" value="" data-unmatched-id>
                            <x-ui.button type="submit" size="sm" disabled data-unmatched-submit>Link</x-ui.button>
                        </form>
                    </td>
                    <td class="x-td-actions">
                        <div class="cc-rowact">
                            <form method="POST" action="{{ route('ext.tiktok.products.import_one') }}" data-slow-action
                                  data-confirm="Import this TikTok Shop product into your main catalog? A real product is created there - name, price, stock, variations and images - and linked to the TikTok Shop product. Nothing changes on TikTok Shop." data-confirm-tone="primary" data-confirm-verb="Import">
                                @csrf
                                <input type="hidden" name="ref" value="{{ $row['ref'] }}">
                                <x-ui.button type="submit" size="sm" variant="primary">Import</x-ui.button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        @include('partials.channel-import-pager')
    @endif

</section>
@else
<section class="fm-section">
    <p class="fm-section__note">Importing needs the TikTok Shop manage permission.</p>
</section>
@endif

<x-channel.import-progress name="TikTok Shop" />

</div>
@endsection
