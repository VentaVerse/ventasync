@extends('layouts.print')
@section('title', 'Packing lists')

@section('content')
@php
    // The same row every layout reads, shared with every view by
    // AppServiceProvider. Not config('app.name'), which is still "Laravel"
    // in this install and would be printed on paper.
    $companyName = $appSetting->company_name ?? 'VentaSync';
@endphp
{{-- ONE SHEET PER ORDER, unlike the pick list.
     A packing list goes in the box, so it can only ever describe the order
     it travels with. The print stylesheet forces a page break between
     sheets for exactly that reason. --}}
@foreach($orders as $o)
    <div class="pl-page">
        <div class="pl-masthead">
            <div>
                <p class="pl-masthead__title">Packing list</p>
                <p class="pl-masthead__sub">{{ $channelLabel }} &middot; <span class="pl-mono">{{ $o['reference'] }}</span></p>
            </div>
            <div class="pl-masthead__meta">
                <div class="pl-mono">{{ $printedAt }}</div>
                <div>{{ $companyName }}</div>
            </div>
        </div>

        @if($o['buyer'] !== '')
            <p class="pl-orders">Buyer: {{ $o['buyer'] }}</p>
        @endif

        <table class="pl-table">
            <thead>
                <tr>
                    <th style="width:34px"><span class="x-sr">Packed</span></th>
                    <th style="width:26%">SKU</th>
                    <th>Product</th>
                    <th style="width:70px" class="pl-num">Qty</th>
                </tr>
            </thead>
            <tbody>
                @forelse($o['items'] as $item)
                    <tr>
                        <td><span class="pl-check"></span></td>
                        <td class="pl-mono">{{ $item['sku'] !== '' ? $item['sku'] : '--' }}</td>
                        <td>{{ $item['name'] !== '' ? $item['name'] : 'Unnamed item' }}</td>
                        <td class="pl-num"><span class="pl-qty pl-mono">{{ $item['quantity'] }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="4">No items recorded for this order.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="pl-foot">
            <span>Packed by ________________</span>
            <span class="pl-mono">{{ $o['reference'] }}</span>
        </div>
    </div>
@endforeach
@endsection
