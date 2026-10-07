@extends('layouts.channel')
@section('title', 'Shopee orders')
@section('breadcrumb', 'Orders')

@section('content')
@php
    $canManageShopeeOrders = auth()->user()?->hasPermission('manage_shopee/order') ?? false;

    $tabs = $tabs ?? [];
    $active_tab = strtoupper((string) ($active_tab ?? 'ALL'));

@endphp

<div id="shopee-orders-page" class="co-page" data-desk-scope>

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Orders</h1>
        @php
            $spHeadScope = $tabs[$active_tab] ?? $active_tab;
            if ($active_tab === 'PENDING') {
                $spHeadScope = ['to_pack' => 'To Pack', 'to_handover' => 'To Handover'][$pendingSub ?? ''] ?? $spHeadScope;
            }
        @endphp
        <p class="x-page-sub">
            {{ number_format($orders->total()) }} {{ $orders->total() === 1 ? 'order' : 'orders' }}@if($active_tab !== 'ALL' && isset($tabs[$active_tab])) in {{ $spHeadScope }}@endif
        </p>
    </div>
</div>
@php
    if ($canManageShopeeOrders) {
        echo "\n\n";
    } elseif ($last_result) {
        echo "\n\n    ";
    } else {
        echo "\n\n\n\n";
    }
@endphp@include('ext-shopee::orders._panel', [
    'panelBaseUrl' => route('ext.shopee.orders.index'),
    'panelHiddenParams' => [],
])

</div>
@endsection
