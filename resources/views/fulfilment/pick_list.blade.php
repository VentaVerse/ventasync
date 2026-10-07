@extends('layouts.print')
@section('title', 'Pick list')

@section('content')
@php
    $companyName = $appSetting->company_name ?? 'VentaSync';
@endphp
<div class="pl-page">
    <div class="pl-masthead">
        <div>
            <p class="pl-masthead__title">Pick list</p>
            <p class="pl-masthead__sub">{{ $channelLabel }} &middot; {{ count($orderRefs) }} {{ count($orderRefs) === 1 ? 'order' : 'orders' }}, {{ count($lines) }} {{ count($lines) === 1 ? 'line' : 'lines' }}</p>
        </div>
        <div class="pl-masthead__meta">
            <div class="pl-mono">{{ $printedAt }}</div>
            <div>{{ $companyName }}</div>
        </div>
    </div>

    <table class="pl-table">
        <thead>
            <tr>
                <th style="width:34px"><span class="x-sr">Picked</span></th>
                <th style="width:22%">SKU</th>
                <th>Product</th>
                <th style="width:70px" class="pl-num">Qty</th>
                <th style="width:26%">For orders</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lines as $line)
                <tr>
                    <td><span class="pl-check"></span></td>
                    <td class="pl-mono">{{ $line['sku'] !== '' ? $line['sku'] : '--' }}</td>
                    <td>{{ $line['name'] !== '' ? $line['name'] : 'Unnamed item' }}</td>
                    <td class="pl-num"><span class="pl-qty pl-mono">{{ $line['quantity'] }}</span></td>
                    <td class="pl-mono">{{ implode(', ', $line['orders']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No items on the selected orders.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="pl-foot">
        <span>Picked by ________________</span>
        <span>Checked by ________________</span>
    </div>
</div>
@endsection
