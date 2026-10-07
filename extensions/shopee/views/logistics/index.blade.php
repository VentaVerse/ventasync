@extends('layouts.channel')
@section('breadcrumb', 'Logistics')

@section('title', 'Shopee Logistics Channels')

@section('content')
@php
    $canManageShopee = auth()->user()?->hasPermission('manage_shopee/logistics') ?? false;

    $enabledCount = $channels->filter(fn ($c) => $c->enabled || $c->force_enable)->count();
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Logistics</h1>
        <p class="x-page-sub">
            @if($channels->count() === 0)
                No couriers cached yet
            @else
                {{ number_format($channels->count()) }} {{ $channels->count() === 1 ? 'courier' : 'couriers' }} on file, {{ number_format($enabledCount) }} switched on for this shop
            @endif
        </p>
    </div>
</div>

@if($canManageShopee)
<div class="x-cmdbar">
    <form method="POST" action="{{ route('ext.shopee.logistics.fetch') }}" class="x-cmdbar__row">
        @csrf
        <div class="x-cmdbar__actions">
            <x-ui.button type="submit" variant="primary">Fetch couriers</x-ui.button>
            <x-ui.hint>Reads the courier list Shopee has switched on for your shop. Presets pick their couriers from this list, so refresh it after changing anything in Seller Centre.</x-ui.hint>
        </div>
    </form>
</div>
@endif

@if($channels->count() === 0)
    <x-ui.empty title="No couriers here"
                :description="$canManageShopee
                    ? 'Nothing has been cached yet. Click Fetch couriers to read the list from Shopee.'
                    : 'Nothing has been cached yet. Someone with the manage tier needs to read the list from Shopee.'" />
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th scope="col" class="cc-col-chanid">Channel ID</th>
            <th scope="col">Courier</th>
            <th scope="col" class="cc-col-stat">Status</th>
            <th scope="col" class="cc-col-flag">Cash on delivery</th>
            <th scope="col" class="cc-col-limit">Weight limit</th>
            <th scope="col" class="cc-col-limit">Size limit</th>
            <th scope="col" class="cc-col-flag cc-col-flag--sm">Pre-order</th>
        </tr>
    </x-slot:head>

    @foreach($channels as $ch)
        @php
            $wl = $ch->weight_limit;
            $dim = $ch->item_max_dimension;
            $hasWeight = !empty($wl) && ($wl['item_max_weight'] ?? 0) > 0;
            $hasDim = !empty($dim) && ($dim['length'] ?? 0) > 0;

            if ($ch->force_enable) {
                $statusTone = 'info';
                $statusLabel = 'Always on';
            } elseif ($ch->enabled) {
                $statusTone = 'success';
                $statusLabel = 'Enabled';
            } else {
                $statusTone = 'neutral';
                $statusLabel = 'Disabled';
            }
        @endphp
        <tr>
            <td class="cc-col-chanid" data-label="Channel ID"><span class="x-num">{{ $ch->logistics_channel_id }}</span></td>
            <td data-label="Courier">
                <span class="x-cell-strong">{{ $ch->logistics_channel_name }}</span>
                @if($ch->mask_channel_id > 0)
                    <span class="cc-sub cc-sub--mono">Stands in for channel {{ $ch->mask_channel_id }}</span>
                @endif
            </td>
            <td class="cc-col-stat" data-label="Status">
                <x-ui.badge :tone="$statusTone">{{ $statusLabel }}</x-ui.badge>
            </td>
            <td class="cc-col-flag" data-label="Cash on delivery">
                @if($ch->cod_enabled)
                    <span class="x-cell-strong">Yes</span>
                @else
                    <span class="x-cell-muted">No</span>
                @endif
            </td>
            <td class="cc-col-limit" data-label="Weight limit">
                @if($hasWeight)
                    <span class="x-num">{{ $wl['item_min_weight'] ?? 0 }} to {{ $wl['item_max_weight'] }} kg</span>
                @else
                    <span class="x-cell-muted">Not stated</span>
                @endif
            </td>
            <td class="cc-col-limit" data-label="Size limit">
                @if($hasDim)
                    <span class="x-num">{{ $dim['length'] }} &times; {{ $dim['width'] }} &times; {{ $dim['height'] }} {{ $dim['unit'] ?? 'cm' }}</span>
                    @if(($dim['dimension_sum'] ?? 0) > 0)
                        <span class="cc-sub cc-sub--mono">{{ $dim['dimension_sum'] }} {{ $dim['unit'] ?? 'cm' }} total of all three sides</span>
                    @endif
                @else
                    <span class="x-cell-muted">Not stated</span>
                @endif
            </td>
            <td class="cc-col-flag cc-col-flag--sm" data-label="Pre-order">
                @if($ch->support_pre_order)
                    <span class="x-cell-strong">Yes</span>
                @else
                    <span class="x-cell-muted">No</span>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>
@endif

</div>
@endsection
