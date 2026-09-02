@extends('layouts.blotter')
@section('title', 'All Listings')
@section('breadcrumb', 'All listings')

{{-- All Listings (Phase D, #184): rows are the catalogue, columns are
     whichever enabled channels implement ListingStateSource. Every badge
     and every link is the channel's own answer, rendered verbatim - this
     page holds no channel knowledge and no label map of its own. --}}
@section('content')
@php
    $q = (string) ($q ?? '');
    $manufacturerFilter = (string) ($manufacturerFilter ?? 'all');
    $erpStatus = (string) ($erpStatus ?? 'all');
    $hasFilters = $q !== '' || $manufacturerFilter !== 'all' || $erpStatus !== 'all';
    $stateClass = \App\Integrations\Listings\ListingState::class;
@endphp

<div class="al-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">All listings</h1>
        <p class="x-page-sub">
            @if($hasFilters)
                {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product matches' : 'products match' }}
            @else
                {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product' : 'products' }}
            @endif
            across {{ count($columns) }} {{ count($columns) === 1 ? 'channel' : 'channels' }}
        </p>
    </div>
</div>

@if(count($columns) === 0)
    {{-- Both "no channel connected" and "no channel visible to this
         operator" land here, and neither may see the catalogue table: the
         columns ARE this page's permission gate, per the Integrations
         controllers' own-check bargain (PermissionCatalogue::NEVER_GATED). --}}
    <x-ui.empty title="Nothing to show here yet"
                description="Listings appear once a marketplace channel is connected and visible to you, side by side with every other channel's.">
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('channels.index')">Open channels</x-ui.button>
        </x-slot:action>
    </x-ui.empty>
@else

<form method="GET" action="{{ route('channels.listings') }}" class="x-filters">
    <x-ui.input type="search" name="q" class="x-filters__search"
                value="{{ $q }}" placeholder="Name, model or SKU" aria-label="Search products" />

    <div class="x-select-wrap x-filters__select">
        <select name="manufacturer" class="x-input" data-autosubmit aria-label="Filter by manufacturer">
            <option value="all">All manufacturers</option>
            @foreach(($allManufacturers ?? collect()) as $mId => $mName)
                <option value="{{ $mId }}" @selected($manufacturerFilter === (string) $mId)>{{ $mName }}</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="erp_status" class="x-input" data-autosubmit aria-label="Filter by catalog status">
            <option value="all" @selected($erpStatus === 'all')>Any catalog status</option>
            <option value="enabled" @selected($erpStatus === 'enabled')>Enabled</option>
            <option value="disabled" @selected($erpStatus === 'disabled')>Disabled</option>
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>

    @if($hasFilters)
        <a class="x-filters__reset" href="{{ route('channels.listings') }}">Clear</a>
    @endif
</form>

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : 'No products yet' }}"
                :description="$hasFilters
                    ? 'Try clearing the search, or widening the filters.'
                    : 'Every catalog product appears here with its standing on every channel. Add your first product and it will be waiting.'">
        @if($hasFilters)
        <x-slot:action>
            <x-ui.button :href="route('channels.listings')">Clear filters</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else

<x-ui.table id="all-listings-table">
    <x-slot:head>
        <tr>
            <th scope="col" class="al-col-id">ID</th>
            <th scope="col">Product</th>
            <th scope="col" class="al-col-qty x-td-num">Stock</th>
            @foreach($columns as $col)
                <th scope="col" class="al-col-chan">{{ $col['name'] }}</th>
            @endforeach
        </tr>
    </x-slot:head>

    @foreach($products as $row)
        @php
            $pid = (int) $row->product_id;
            $pName = (string) ($row->name ?? '');
            $pSku = (string) ($row->sku ?: ($row->model ?: ''));
        @endphp
        <tr>
            <td class="al-col-id" data-label="ID">
                <span class="x-num">{{ $pid }}</span>
            </td>

            <td data-label="Product">
                <div class="co-item">
                    <div class="al-thumb">
                        <span class="co-item__none">No image</span>
                        @if(trim((string) ($row->image ?? '')) !== '')
                            <img src="{{ asset('storage/' . ltrim($row->image, '/')) }}" alt="" loading="lazy" decoding="async">
                        @endif
                    </div>
                    <div class="co-item__body">
                        <span class="co-item__name">{{ $pName ?: 'Unnamed product' }}</span>
                        <div class="co-item__meta">
                            <x-ui.badge :tone="(int) $row->status === 1 ? 'success' : 'neutral'">{{ (int) $row->status === 1 ? 'Enabled' : 'Disabled' }}</x-ui.badge>
                            <span class="co-item__sku">{{ $pSku ?: 'No SKU' }}</span>
                            @if((string) ($row->manufacturer_name ?? '') !== '')
                                <span>{{ $row->manufacturer_name }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </td>

            <td class="al-col-qty x-td-num" data-label="Stock">
                <span class="x-num">{{ (int) $row->quantity }}</span>
            </td>

            @foreach($columns as $col)
                @php
                    $cellState = $col['states'][$pid] ?? null;
                    $cellUrl = $col['urls'][$pid] ?? null;
                @endphp
                <td class="al-col-chan" data-label="{{ $col['name'] }}">
                    {{-- The cell is the channel's own answer, and the whole
                         cell is the way in: clicking any state lands on that
                         channel's workbench for this product. --}}
                    @if($cellState === null)
                        <span class="al-cell"><x-ui.badge tone="neutral">Not listed</x-ui.badge></span>
                    @else
                        @php $badge = $cellState->badge(); @endphp
                        @if($cellUrl)
                            <a class="al-cell al-cell--link" href="{{ $cellUrl }}"
                               aria-label="{{ $pName ?: 'Product ' . $pid }} on {{ $col['name'] }}: {{ $badge[0] }}">
                                <x-ui.badge :tone="$badge[1]">{{ $badge[0] }}</x-ui.badge>
                                @if($cellState->driftLabel())
                                    <span class="al-cell__drift"><x-ui.badge tone="warning">{{ $cellState->driftLabel() }}</x-ui.badge></span>
                                @elseif($cellState->state === $stateClass::NOT_LISTED && $cellState->ready)
                                    <span class="al-cell__note">Ready to push</span>
                                @endif
                            </a>
                        @else
                            <span class="al-cell">
                                <x-ui.badge :tone="$badge[1]">{{ $badge[0] }}</x-ui.badge>
                                @if($cellState->driftLabel())
                                    <span class="al-cell__drift"><x-ui.badge tone="warning">{{ $cellState->driftLabel() }}</x-ui.badge></span>
                                @endif
                            </span>
                        @endif
                    @endif
                </td>
            @endforeach
        </tr>
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$products" />

@endif
@endif

</div>{{-- /.al-page --}}
@endsection
