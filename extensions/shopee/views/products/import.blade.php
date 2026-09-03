@extends('layouts.channel')
@section('title', 'Import from Shopee')
@section('breadcrumb', 'Import')

{{-- The Import page (cleanup point 10, the owner 2026-09-03). Its own page
     under the Catalog menu: the workbench card it replaces sat permanently
     open saying "Nothing is waiting" to everyone except a fresh install.
     A scan compares every item on the Shopee shop against catalog SKUs;
     anything it cannot place lands here to Import (a real catalog product
     is created - details, variations, images), Link (the catalog already
     has it under another SKU) or Dismiss. --}}

@section('content')

@php
    $canManageShopee = auth()->user()?->hasPermission('manage_shopee/product') ?? false;
    $unmatched = $unmatchedItems ?? collect();
    $hasUnmatched = $unmatched->count() > 0;
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Import from Shopee</h1>
        <p class="x-page-sub">
            @if($hasUnmatched)
                {{ number_format($unmatched->count()) }} {{ $unmatched->count() === 1 ? 'item' : 'items' }} on Shopee, not in your main catalog.
            @else
                Bring items that exist on Shopee into your main catalog.
            @endif
        </p>
    </div>
    @if($canManageShopee)
        <div class="cc-head-actions">
            <form method="POST" action="{{ route('ext.shopee.products.sync_unmatched') }}" data-slow-action>
                @csrf
                <x-ui.button type="submit" variant="{{ $hasUnmatched ? 'secondary' : 'primary' }}">{{ $hasUnmatched ? 'Scan again' : 'Scan Shopee for new items' }}</x-ui.button>
            </form>
            <form method="POST" action="{{ route('ext.shopee.products.rebuild_cache') }}"
                  data-confirm="Rebuild the Shopee item cache? This reads every item on your Shopee shop and can take a minute." data-confirm-tone="primary" data-confirm-verb="Rebuild cache">
                @csrf
                <x-ui.button type="submit" variant="secondary">Rebuild item cache</x-ui.button>
            </form>
        </div>
    @endif
</div>

@if($canManageShopee)
<section class="fm-section cc-unmatched">

    @if($hasUnmatched)
        <p class="fm-section__note">These items exist on Shopee but not in your main catalog. Import one - or tick several and import them together - to create the real product in your main catalog, details, variations and images included; or link it to a catalog product it already is, or dismiss it.</p>

        {{-- Import selected (the owner, 2026-09-04): the checkboxes in the
             table belong to this form through form="", so one press
             imports every ticked row, each in its own transaction. --}}
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
                    <th scope="col" class="cc-col-chanid">Item ID</th>
                    <th scope="col" class="cc-col-link">Catalog product</th>
                    <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
                </tr>
            </x-slot:head>

            @foreach($unmatched as $ui)
                <tr>
                    <td class="cc-col-check" data-label="Select">
                        <input type="checkbox" class="cc-check" form="cc-import-selected" name="unmatched_ids[]" value="{{ $ui->id }}" data-import-check aria-label="Select {{ $ui->item_name ?: $ui->sku }} for import">
                    </td>
                    <td data-label="Shopee item">
                        <div class="co-item">
                            <div class="cc-thumb cc-thumb--sm">
                                <span class="co-item__none">None</span>
                                @if($ui->image_url)
                                    <img src="{{ $ui->image_url }}" alt="" loading="lazy" decoding="async" data-thumb>
                                @endif
                            </div>
                            <div class="co-item__body">
                                <span class="co-item__name">{{ $ui->item_name ?: 'Unnamed item' }}</span>
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
                    <td class="cc-col-chanid" data-label="Item ID">
                        <span class="x-num">{{ $ui->shopee_item_id }}</span>
                        @if($ui->shopee_model_id)
                            <span class="cc-sub cc-sub--mono">Variation {{ $ui->shopee_model_id }}</span>
                        @endif
                    </td>
                    <td class="cc-col-link" data-label="Catalog product">
                        {{-- The typeahead's wire is unchanged: type, GET
                             search-catalog?q=, pick a result, the picked id
                             lands in this form's product_id and the form
                             POSTs to link_unmatched/{id}. What changed is
                             where the behaviour lives (a bundled module, not
                             an inline script) and how the results are built
                             (createElement/textContent, never innerHTML with
                             a product name off the API). --}}
                        <form method="POST" action="{{ route('ext.shopee.products.link_unmatched', $ui->id) }}"
                              class="cc-link"
                              data-unmatched-link
                              data-search-url="{{ route('ext.shopee.products.search_catalog') }}">
                            @csrf
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
                        {{-- Import is the queue's whole point (#196); Link
                             stays for an item the catalog already has under
                             another SKU; Dismiss stays the rare way out. --}}
                        <form method="POST" action="{{ route('ext.shopee.products.import_unmatched', $ui->id) }}" data-slow-action
                              data-confirm="Import this Shopee item into your main catalog? A real product is created there - name, price, stock, variations and images - and linked to the Shopee item. Nothing changes on Shopee." data-confirm-tone="primary" data-confirm-verb="Import">
                            @csrf
                            <x-ui.button type="submit" size="sm" variant="primary">Import</x-ui.button>
                        </form>
                        <x-ui.menu label="Shopee item {{ $ui->shopee_item_id }} actions">
                            <form method="POST" action="{{ route('ext.shopee.products.dismiss_unmatched', $ui->id) }}">
                                @csrf
                                <button type="submit" class="x-menu__item">Dismiss, stop tracking it</button>
                            </form>
                        </x-ui.menu>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @else
        <p class="fm-section__note">Nothing is waiting. <x-ui.hint label="What a scan does">A scan compares every item on your Shopee shop against catalog SKUs and lists anything it could not place.</x-ui.hint></p>
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
