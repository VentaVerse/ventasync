@extends('layouts.channel')
@section('title', 'Unlinked items on Shopee')
@section('breadcrumb', 'Unlinked on Shopee')

@section('content')
@php
    $groupName = $group->name ?: 'this product group';
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Unlinked items on Shopee</h1>
        <p class="x-page-sub">
            @if($scanned === 0)
                Nothing was read from Shopee.
            @else
                {{ number_format($scanned) }} {{ $scanned === 1 ? 'item' : 'items' }} read from Shopee,
                {{ number_format(count($orphans)) }} of which no catalog product is linked to.
            @endif
        </p>
    </div>
    <x-ui.button variant="secondary" :href="route('ext.shopee.product-groups.products', $group->id)">
        Back to {{ $groupName }}
    </x-ui.button>
</div>

@if(! $complete)
    <div class="alert warning" role="status" aria-live="polite">
        <x-ui.icon name="alert-triangle" size="18" class="alert__icon" />
        <span>Shopee could not be read all the way through, so this list may be incomplete.</span>
    </div>
@endif

@if(count($orphans) === 0)
    <x-ui.empty title="Nothing unaccounted for"
                description="Every item read from Shopee is linked to a catalog product. Items created in Seller Centre, or left behind when a link was removed, would appear here.">
        <x-slot:action>
            <x-ui.button :href="route('ext.shopee.product-groups.products', $group->id)">Back to {{ $groupName }}</x-ui.button>
        </x-slot:action>
    </x-ui.empty>
@else
    <p class="x-page-sub cc-orphans__lede">
        These exist on Shopee and nothing in the catalog points at them. That is not automatically wrong:
        an item may have been created in Seller Centre, or its catalog twin removed. To bring one under ERP control,
        give the catalog product the same SKU and press Send on its row.
    </p>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th scope="col" class="cc-col-id">Shopee item</th>
                <th scope="col">SKUs on Shopee</th>
            </tr>
        </x-slot:head>

        @foreach($orphans as $o)
            <tr>
                <td class="cc-col-id" data-label="Shopee item"><span class="x-num">{{ $o['id'] }}</span></td>
                <td data-label="SKUs on Shopee">
                    @if(count($o['skus']) === 0)
                        <span class="x-cell-muted">No SKU</span>
                    @else
                        {{ implode(', ', $o['skus']) }}
                    @endif
                </td>
            </tr>
        @endforeach
    </x-ui.table>
@endif

</div>
@endsection
