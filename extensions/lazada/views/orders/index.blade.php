@extends('layouts.channel')
@section('title', 'Lazada orders')
@section('breadcrumb', 'Orders')

@section('content')
@php
    $canManageLazadaOrders = auth()->user()?->hasPermission('manage_lazada/order') ?? false;

    $tabs = $tabs ?? [];
    $active_tab = strtoupper((string) ($active_tab ?? 'ALL'));
    $pendingSub = $pending_subtab ?? request()->query('pending_sub', 'to_pack');
    $fdSub = $fd_subtab ?? request()->query('fd_sub', 'failed_delivery');

    $pendingNav = [
        'to_pack' => 'To Pack',
        'to_arrange' => 'To Arrange Shipment',
        'to_handover' => 'To Handover',
    ];

    $fdNav = [
        'failed_delivery' => 'Failed Delivery',
        'shipped_back' => 'Shipped Back',
        'lost_damaged' => 'Lost and Damaged',
    ];

    $scopeLabel = null;
    if ($active_tab === 'TO_SHIP') {
        $scopeLabel = $pendingNav[$pendingSub] ?? ($tabs['TO_SHIP'] ?? null);
    } elseif ($active_tab === 'FAILED_DELIVERY') {
        $scopeLabel = $fdNav[$fdSub] ?? ($tabs['FAILED_DELIVERY'] ?? null);
    } elseif ($active_tab !== 'ALL') {
        $scopeLabel = $tabs[$active_tab] ?? null;
    }
@endphp

<div id="lazada-orders-page" class="co-page" data-desk-scope
     data-awb-url="{{ session('lazada_awb_url') }}"
     data-pack-print-order="{{ session('open_pack_print_modal_order_id') }}"
     data-awb-template="{{ route('ext.lazada.orders.awb', ['orderId' => '__OID__']) }}"
     data-ship-print-template="{{ route('ext.lazada.orders.ship_print_post', ['orderId' => '__OID__']) }}"
     data-recreate-template="{{ route('ext.lazada.orders.recreate_package', ['orderId' => '__OID__']) }}">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Orders</h1>
        <p class="x-page-sub">
            {{ number_format($orders->total()) }} {{ $orders->total() === 1 ? 'order' : 'orders' }}@if($scopeLabel) in {{ $scopeLabel }}@endif
        </p>
    </div>
</div>
@php
    if ($canManageLazadaOrders) {
        echo "\n\n";
    } elseif ($last_result) {
        echo "\n\n    ";
    } else {
        echo "\n\n\n\n";
    }
@endphp@include('ext-lazada::orders._panel', [
    'panelBaseUrl' => route('ext.lazada.orders.index'),
    'panelHiddenParams' => [],
])

</div>
@endsection
