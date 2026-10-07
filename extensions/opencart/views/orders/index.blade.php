@extends('layouts.channel')
@section('title', $storeLabel . ' Orders')
@section('breadcrumb', 'Orders')

@section('content')
@php
    $canManageOcOrders = auth()->user()?->hasPermission('manage_sales/order') ?? false;

    $ocScopeLabel = null;
    if ((int) ($filters['status'] ?? 0) > 0) {
        foreach ($statuses as $ocS) {
            if ((int) $ocS->order_status_id === (int) $filters['status']) {
                $ocScopeLabel = $ocS->name;
                break;
            }
        }
    }
@endphp

<div id="opencart-orders-page" class="co-page" data-desk-scope>

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Orders</h1>
        <p class="x-page-sub">
            {{ number_format($orders->total()) }} {{ $orders->total() === 1 ? 'order' : 'orders' }}@if($ocScopeLabel) in {{ $ocScopeLabel }}@endif
        </p>
    </div>
    @if($canManageOcOrders)
        <x-ui.button variant="primary" :href="route('orders.create')">New order</x-ui.button>
    @endif
</div>
@php
    echo "\n\n";
@endphp@include('ext-opencart::orders._panel', [
    'panelBaseUrl' => route('ext.opencart.orders.index', $storeId),
    'panelHiddenParams' => [],
])

</div>
@endsection
