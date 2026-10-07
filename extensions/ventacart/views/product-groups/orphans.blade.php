@extends('layouts.channel')
@section('title', 'Unlinked products on ' . ($setting->store_name ?: 'VentaCart'))
@section('breadcrumb', 'Unlinked on VentaCart')

@section('content')
@php
    $groupName = $group->name ?: 'this product group';
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Unlinked products on {{ $storeName }}</h1>
        <p class="x-page-sub">
            @if($scanned === 0)
                Nothing was read from {{ $storeName }}.
            @else
                {{ number_format($scanned) }} {{ $scanned === 1 ? 'product' : 'products' }} read from {{ $storeName }},
                {{ number_format(count($orphans)) }} of which no catalog product is linked to.
            @endif
        </p>
    </div>
    <x-ui.button variant="secondary" :href="route('ext.ventacart.product-groups.products', [$setting->id, $group->id])">
        Back to {{ $groupName }}
    </x-ui.button>
</div>

@if($error)
    <div class="alert warning" role="status" aria-live="polite">
        <x-ui.icon name="alert-triangle" size="18" class="alert__icon" />
        <span>{{ $error }}</span>
    </div>
@endif

@if($truncated)
    <div class="alert warning" role="status" aria-live="polite">
        <x-ui.icon name="alert-triangle" size="18" class="alert__icon" />
        <span>Reading stopped after {{ number_format($scanned) }} products, so {{ $storeName }} may hold more than is listed here.</span>
    </div>
@endif

@if(count($orphans) === 0)
    <x-ui.empty title="Nothing unaccounted for"
                :description="'Every product read from ' . $storeName . ' is linked to a catalog product. Products created directly in VentaCart\'s admin, or left behind when a link was removed, would appear here.'">
        <x-slot:action>
            <x-ui.button :href="route('ext.ventacart.product-groups.products', [$setting->id, $group->id])">Back to {{ $groupName }}</x-ui.button>
        </x-slot:action>
    </x-ui.empty>
@else
    <p class="x-page-sub cc-orphans__lede">
        These exist on {{ $storeName }} and nothing in the catalog points at them. That is not automatically wrong:
        a product may have been created in VentaCart directly, or its catalog twin removed. To bring one under ERP control,
        give the catalog product the same SKU and press Send on its row.
    </p>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th scope="col" class="cc-col-id">VentaCart ID</th>
                <th scope="col">SKU</th>
                <th scope="col">Name</th>
            </tr>
        </x-slot:head>

        @foreach($orphans as $o)
            <tr>
                <td class="cc-col-id" data-label="VentaCart ID"><span class="x-num">{{ $o['id'] }}</span></td>
                <td data-label="SKU">@if($o['sku'] !== ''){{ $o['sku'] }}@else<span class="x-cell-muted">No SKU</span>@endif</td>
                <td data-label="Name">{{ $o['name'] !== '' ? $o['name'] : 'Unnamed product' }}</td>
            </tr>
        @endforeach
    </x-ui.table>
@endif

</div>
@endsection
