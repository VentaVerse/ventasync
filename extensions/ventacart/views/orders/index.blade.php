@extends('layouts.channel')
@section('title', $storeLabel . ' Orders')
@section('breadcrumb', 'Orders')

@section('content')
@php
    $canManageVentaOrders = auth()->user()?->hasPermission('manage_ventacart/order') ?? false;

    $vtStatus = (string) ($filters['status'] ?? '');
    $vtTab = strtoupper((string) ($active_tab ?? 'TO_SHIP'));
    $vtScopeLabel = $vtStatus !== ''
        ? \App\Support\ChannelStatusTone::labelFor('ventacart.orders', $vtStatus)
        : ($vtTab === 'TO_SHIP'
            ? (['to_pack' => 'To Pack', 'to_handover' => 'To Handover'][$pending_subtab ?? 'to_pack'] ?? 'To Ship')
            : ($vtTab === 'ALL' ? null : ($tabs[$vtTab] ?? null)));
@endphp

<div id="ventacart-orders-page" class="co-page" data-desk-scope>

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Orders</h1>
        <p class="x-page-sub">
            {{ number_format($orders->total()) }} {{ $orders->total() === 1 ? 'order' : 'orders' }}@if($vtScopeLabel) in {{ $vtScopeLabel }}@endif
        </p>
    </div>
</div>
@php
    if ($canManageVentaOrders) {
        echo "\n\n";
    } else {
        echo "\n\n\n";
    }
@endphp@include('ext-ventacart::orders._panel', [
    'panelBaseUrl' => route('ext.ventacart.orders.index', $storeId),
    'panelHiddenParams' => [],
])

</div>
@endsection
