@extends('layouts.channel')
@section('title', 'Unlinked items on TikTok Shop')
@section('breadcrumb', 'Unlinked on TikTok Shop')

@section('content')
@php
    $groupName = $group->name ?: 'this product group';
@endphp
<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Unlinked items on TikTok Shop</h1>
        <p class="x-page-sub">
            @if($scanned === 0)
                Nothing was read from TikTok Shop.
            @else
                {{ number_format($scanned) }} {{ $scanned === 1 ? 'item' : 'items' }} read from TikTok Shop,
                {{ number_format(count($orphans)) }} of which no catalog product is linked to.
            @endif
        </p>
    </div>
    <x-ui.button variant="secondary" :href="route('ext.tiktok.product-groups.products', $group->id)">Back to {{ $groupName }}</x-ui.button>
</div>

@if(! $complete)
    <div class="alert warning" role="status" aria-live="polite">
        <x-ui.icon name="alert-triangle" size="18" class="alert__icon" />
        <span>TikTok Shop could not be read all the way through, so this list may be incomplete.</span>
    </div>
@endif

@if(count($orphans) === 0)
    <x-ui.empty title="Nothing unaccounted for"
                description="Every item read from TikTok Shop is linked to a catalog product. Items created in the seller center, or left behind when a link was removed, would appear here.">
        <x-slot:action>
            <x-ui.button :href="route('ext.tiktok.product-groups.products', $group->id)">Back to {{ $groupName }}</x-ui.button>
        </x-slot:action>
    </x-ui.empty>
@else
    <p class="x-page-sub cc-orphans__lede">
        These exist on TikTok Shop and nothing in the catalog points at them. That is not automatically wrong:
        an item may have been created in the seller center, or its catalog twin removed. To bring one under ERP control,
        give the catalog product the same SKU and run Check against TikTok Shop on its product group.
    </p>
    <x-ui.table>
        <x-slot:head>
            <tr>
                <th scope="col" class="cc-col-id">TikTok Shop item</th>
                <th scope="col">Title on TikTok Shop</th>
                <th scope="col">SKUs on TikTok Shop</th>
            </tr>
        </x-slot:head>
        @foreach($orphans as $o)
            <tr>
                <td class="cc-col-id" data-label="TikTok Shop item"><span class="x-num">{{ $o['id'] }}</span></td>
                <td data-label="Title on TikTok Shop">{{ $o['title'] !== '' ? $o['title'] : 'Untitled' }} <span class="x-cell-muted">{{ $o['status'] }}</span></td>
                <td data-label="SKUs on TikTok Shop">
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
