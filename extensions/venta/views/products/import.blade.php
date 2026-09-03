@extends('layouts.channel')
@section('title', 'Import from ' . ($setting->store_name ?: 'Venta'))
@section('breadcrumb', 'Import')

{{-- The Import page (cleanup point 10, the owner 2026-09-03). Its own page
     under the Catalog menu: the workbench card it replaces sat permanently
     open saying "Nothing is waiting" to everyone except a fresh install.
     A scan compares every item on the store against catalog SKUs;
     anything it cannot place lands here to Import (a real catalog product
     is created - details, variations, images), Link (the catalog already
     has it under another SKU) or Dismiss. --}}

@section('content')

@php
    $canManageVenta = auth()->user()?->hasPermission('manage_venta/listing') ?? false;
    $unmatched = $unmatchedItems ?? collect();
    $hasUnmatched = $unmatched->count() > 0;
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Import from {{ $setting->store_name ?: 'Venta' }}</h1>
        <p class="x-page-sub">
            @if($hasUnmatched)
                {{ number_format($unmatched->count()) }} {{ $unmatched->count() === 1 ? 'item' : 'items' }} on the store, not in the catalog.
            @else
                Bring items that exist on the store into the catalog.
            @endif
        </p>
    </div>
    @if($canManageVenta)
        <div class="cc-head-actions">
            <form method="POST" action="{{ route('ext.venta.products.sync_unmatched', $store) }}" data-slow-action>
                @csrf
                <x-ui.button type="submit" variant="{{ $hasUnmatched ? 'secondary' : 'primary' }}">{{ $hasUnmatched ? 'Scan again' : 'Scan ' . ($setting->store_name ?: 'Venta') . ' for new items' }}</x-ui.button>
            </form>
        </div>
    @endif
</div>

@if($canManageVenta)
<section class="fm-section cc-unmatched">


    @if($hasUnmatched)
        <p class="fm-section__note">These items exist on the store but not in the catalog. Import one to create the real product here - details, variations and images included - or link it to a catalog product it already is, or dismiss it.</p>

        <x-ui.table>
            <x-slot:head>
                <tr>
                    <th scope="col">Store item</th>
                    <th scope="col" class="cc-col-sku">SKU</th>
                    <th scope="col" class="cc-col-chanid">Item ID</th>
                    <th scope="col" class="cc-col-link">Catalog product</th>
                    <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
                </tr>
            </x-slot:head>

            @foreach($unmatched as $ui)
                <tr>
                    <td data-label="Store item">
                        <div class="co-item">
                            <div class="cc-thumb cc-thumb--sm">
                                <span class="co-item__none">None</span>
                                @if($ui->image_url)
                                    <img src="{{ $ui->image_url }}" alt="" loading="lazy" decoding="async" data-thumb>
                                @endif
                            </div>
                            <div class="co-item__body">
                                <span class="co-item__name">{{ $ui->name ?: 'Unnamed item' }}</span>
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
                        <span class="x-num">{{ $ui->venta_product_id ?: '–' }}</span>
                        @if(false)
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
                        <form method="POST" action="{{ route('ext.venta.products.link_unmatched', [$store, $ui->id]) }}"
                              class="cc-link"
                              data-unmatched-link
                              data-search-url="{{ route('ext.venta.products.search_catalog', $store) }}">
                            @csrf
                            <div class="fm-ta">
                                <input type="text" class="x-input" data-unmatched-search
                                       placeholder="Search name, model or SKU" autocomplete="off"
                                       aria-label="Search the catalog for the product this store item is">
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
                        <form method="POST" action="{{ route('ext.venta.products.import_unmatched', [$store, $ui->id]) }}" data-slow-action
                              data-confirm="Import this store item into the catalog? A real product is created here - name, price, stock, variations and images - and linked to the store item. Nothing changes on the store." data-confirm-tone="primary" data-confirm-verb="Import">
                            @csrf
                            <x-ui.button type="submit" size="sm" variant="primary">Import</x-ui.button>
                        </form>
                        <x-ui.menu label="Store item {{ $ui->sku }} actions">
                            <form method="POST" action="{{ route('ext.venta.products.dismiss_unmatched', [$store, $ui->id]) }}">
                                @csrf
                                <button type="submit" class="x-menu__item">Dismiss, stop tracking it</button>
                            </form>
                        </x-ui.menu>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @else
        <p class="fm-section__note">Nothing is waiting. <x-ui.hint label="What a scan does">A scan compares every item on the store against catalog SKUs and lists anything it could not place.</x-ui.hint></p>
    @endif

</section>
@else
<section class="fm-section">
    <p class="fm-section__note">Importing needs the Venta manage permission.</p>
</section>
@endif

</div>
@endsection
