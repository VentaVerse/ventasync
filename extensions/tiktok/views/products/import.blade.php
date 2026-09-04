@extends('layouts.channel')
@section('title', 'Import from TikTok Shop')
@section('breadcrumb', 'Import')

{{-- The Import page (cleanup point 10, the owner 2026-09-03). Its own page
     under the Catalog menu: the workbench card it replaces sat permanently
     open saying "Nothing is waiting" to everyone except a fresh install. --}}

@section('content')

@php
    $canManageTiktok = auth()->user()?->hasPermission('manage_tiktok/product') ?? false;
    $unmatched = $unmatchedItems ?? collect();
    $hasUnmatched = $unmatched->count() > 0;
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Import from TikTok Shop</h1>
        <p class="x-page-sub">
            @if($hasUnmatched)
                {{ number_format($unmatched->count()) }} {{ $unmatched->count() === 1 ? 'item' : 'items' }} on TikTok Shop, not in your main catalog.
            @else
                Bring items that exist on TikTok Shop into your main catalog.
            @endif
        </p>
    </div>
    @if($canManageTiktok)
        <div class="cc-head-actions">
            <form method="POST" action="{{ route('ext.tiktok.products.sync_unmatched') }}" data-slow-action>
                @csrf
                <x-ui.button type="submit" variant="{{ $hasUnmatched ? 'secondary' : 'primary' }}">{{ $hasUnmatched ? 'Scan again' : 'Scan TikTok Shop for new items' }}</x-ui.button>
            </form>
        </div>
    @endif
</div>

@if($canManageTiktok)
<section class="fm-section cc-unmatched">

    @if($hasUnmatched)
        <p class="fm-section__note">These products exist on TikTok Shop but not in your main catalog. Import one - or tick several and import them together - to create the real product in your main catalog, details, variations and images included; or link it to a catalog product it already is, or dismiss it.</p>

        {{-- Import selected (the owner, 2026-09-04): the checkboxes in the
             table belong to this form through form="", so one press
             imports every ticked row, each in its own transaction. --}}
        <form id="cc-import-selected" method="POST" action="{{ route('ext.tiktok.products.import_selected') }}"
              class="cc-import-bulk" data-slow-action
              data-confirm="Import the selected items into your main catalog?"
              data-confirm-one="Import the selected item into your main catalog? A real product is created there - name, price, stock, variations and images - and linked. Nothing changes on TikTok Shop."
              data-confirm-many="Import the :n selected items into your main catalog? A real product is created there for each one - name, price, stock, variations and images - and linked. Nothing changes on TikTok Shop."
              data-confirm-tone="primary" data-confirm-verb="Import">
            @csrf
            <x-ui.button type="submit" size="sm" variant="primary" disabled data-import-selected-button>Import selected</x-ui.button>
        </form>

        <x-ui.table>
            <x-slot:head>
                <tr>
                    <th scope="col" class="cc-col-check"><input type="checkbox" class="cc-check" data-import-check-all aria-label="Select all for import"></th>
                    <th scope="col">TikTok Shop product</th>
                    <th scope="col" class="cc-col-sku">SKU</th>
                    <th scope="col" class="cc-col-chanid">Product ID</th>
                    <th scope="col" class="cc-col-link">Catalog product</th>
                    <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
                </tr>
            </x-slot:head>

            @foreach($unmatched as $ui)
                <tr>
                    <td class="cc-col-check" data-label="Select">
                        <input type="checkbox" class="cc-check" form="cc-import-selected" name="unmatched_ids[]" value="{{ $ui->id }}" data-import-check aria-label="Select {{ $ui->item_name ?: $ui->sku }} for import">
                    </td>
                    <td data-label="TikTok Shop product">
                        <div class="co-item">
                            <div class="co-item__body">
                                <span class="co-item__name">{{ $ui->item_name ?: 'Unnamed product' }}</span>
                            </div>
                        </div>
                    </td>
                    <td class="cc-col-sku" data-label="SKU">
                        @if($ui->sku)
                            <span class="x-num">{{ $ui->sku }}</span>
                        @else
                            <span class="x-cell-muted">None</span>
                        @endif
                    </td>
                    <td class="cc-col-chanid" data-label="Product ID">
                        <span class="x-num">{{ $ui->tiktok_product_id }}</span>
                    </td>
                    <td class="cc-col-link" data-label="Catalog product">
                        <form method="POST" action="{{ route('ext.tiktok.products.link_unmatched', $ui->id) }}"
                              class="cc-link"
                              data-unmatched-link
                              data-search-url="{{ route('ext.tiktok.products.search_catalog') }}">
                            @csrf
                            <div class="fm-ta">
                                <input type="text" class="x-input" data-unmatched-search
                                       placeholder="Search name, model or SKU" autocomplete="off"
                                       aria-label="Search the catalog for the product this TikTok Shop item is">
                                <div class="fm-ta__list" data-unmatched-results role="listbox" aria-label="Catalog matches"></div>
                            </div>
                            <input type="hidden" name="product_id" value="" data-unmatched-id>
                            <x-ui.button type="submit" size="sm" disabled data-unmatched-submit>Link</x-ui.button>
                        </form>
                    </td>
                    <td class="x-td-actions">
                        {{-- One baseline (the owner, 2026-09-04): the kebab that
                             held a single action is gone; Dismiss stands beside
                             Import as the quiet way out it is. --}}
                        <div class="cc-rowact">
                            <form method="POST" action="{{ route('ext.tiktok.products.import_unmatched', $ui->id) }}" data-slow-action
                                  data-confirm="Import this TikTok Shop product into your main catalog? A real product is created there - name, price, stock, variations and images - and linked. Nothing changes on TikTok Shop." data-confirm-tone="primary" data-confirm-verb="Import">
                                @csrf
                                <x-ui.button type="submit" size="sm" variant="primary">Import</x-ui.button>
                            </form>
                        
                            <form method="POST" action="{{ route('ext.tiktok.products.dismiss_unmatched', $ui->id) }}"
                                  data-confirm="Stop tracking this TikTok Shop product? Scans will not list it again." data-confirm-verb="Dismiss">
                                @csrf
                                <button type="submit" class="cc-quiet">Dismiss</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @else
        <p class="fm-section__note">Nothing is waiting. <x-ui.hint>Scans your TikTok Shop for products the catalog does not hold, so they can be imported here or linked to what they already are.</x-ui.hint></p>
    @endif

</section>
@else
<section class="fm-section">
    <p class="fm-section__note">Importing needs the TikTok Shop manage permission.</p>
</section>
@endif

<x-channel.import-progress :name="'TikTok Shop'" />

</div>
@endsection
