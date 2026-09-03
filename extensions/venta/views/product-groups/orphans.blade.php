@extends('layouts.channel')
@section('title', 'Unlinked products on ' . ($setting->store_name ?: 'Venta'))
@section('breadcrumb', 'Unlinked on Venta')

{{-- The mirror of the products page. That one lists what the ERP holds and
     asks whether Venta still has it; this lists what Venta holds and asks
     whether the ERP knows about it. Neither question answers the other, and
     until now only the first could be asked at all.

     Read-only by design. Every row here is a judgement call - a SKU that
     looks familiar may be a different product - so this page reports and the
     operator decides. There is no Adopt button and there should not be one. --}}
@section('content')
@php
    $groupName = $group->name ?: 'this preset';
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
    <x-ui.button variant="secondary" :href="route('ext.venta.product-groups.products', [$setting->id, $group->id])">
        Back to {{ $groupName }}
    </x-ui.button>
</div>

{{-- Same `alert warning` shape the flash partial uses, minus the close
     button: these describe the state of the report on screen rather than the
     result of an action, so dismissing one would leave a partial list looking
     complete. --}}
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
                :description="'Every product read from ' . $storeName . ' is linked to a catalog product. Products created directly in Venta\'s admin, or left behind when a link was removed, would appear here.'">
        <x-slot:action>
            <x-ui.button :href="route('ext.venta.product-groups.products', [$setting->id, $group->id])">Back to {{ $groupName }}</x-ui.button>
        </x-slot:action>
    </x-ui.empty>
@else
    <p class="x-page-sub cc-orphans__lede">
        These exist on {{ $storeName }} and nothing in the catalog points at them. That is not automatically wrong:
        a product may have been created in Venta directly, or its catalog twin removed. To bring one under ERP control,
        give the catalog product the same SKU and press Send on its row.
    </p>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th scope="col" class="cc-col-id">Venta ID</th>
                <th scope="col">SKU</th>
                <th scope="col">Name</th>
            </tr>
        </x-slot:head>

        @foreach($orphans as $o)
            <tr>
                <td class="cc-col-id" data-label="Venta ID"><span class="x-num">{{ $o['id'] }}</span></td>
                <td data-label="SKU">@if($o['sku'] !== ''){{ $o['sku'] }}@else<span class="x-cell-muted">No SKU</span>@endif</td>
                <td data-label="Name">{{ $o['name'] !== '' ? $o['name'] : 'Unnamed product' }}</td>
            </tr>
        @endforeach
    </x-ui.table>
@endif

</div>
@endsection
