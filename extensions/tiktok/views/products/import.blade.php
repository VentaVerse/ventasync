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
                {{ number_format($unmatched->count()) }} {{ $unmatched->count() === 1 ? 'item' : 'items' }} on TikTok Shop, not in the catalog.
            @else
                Bring items that exist on TikTok Shop into the catalog.
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
        <p class="fm-section__note">These products exist on TikTok Shop but not in the catalog. Import one to create the real product here - details, variations and images included - or link it to a catalog product it already is, or dismiss it.</p>

        <x-ui.table>
            <x-slot:head>
                <tr>
                    <th scope="col">TikTok Shop product</th>
                    <th scope="col" class="cc-col-sku">SKU</th>
                    <th scope="col" class="cc-col-chanid">Product ID</th>
                    <th scope="col" class="cc-col-link">Catalog product</th>
                    <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
                </tr>
            </x-slot:head>

            @foreach($unmatched as $ui)
                <tr>
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
                        <form method="POST" action="{{ route('ext.tiktok.products.import_unmatched', $ui->id) }}" data-slow-action
                              data-confirm="Import this TikTok Shop product into the catalog? A real product is created here - name, price, stock, variations and images - and linked. Nothing changes on TikTok Shop." data-confirm-tone="primary" data-confirm-verb="Import">
                            @csrf
                            <x-ui.button type="submit" size="sm" variant="primary">Import</x-ui.button>
                        </form>
                        <x-ui.menu label="TikTok Shop product {{ $ui->tiktok_product_id }} actions">
                            <form method="POST" action="{{ route('ext.tiktok.products.dismiss_unmatched', $ui->id) }}">
                                @csrf
                                <button type="submit" class="x-menu__item">Dismiss, stop tracking it</button>
                            </form>
                        </x-ui.menu>
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
