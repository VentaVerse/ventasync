@extends('layouts.channel')
@section('title', $storeLabel . ' Orders')
@section('breadcrumb', 'Orders')

{{-- Leaves the .x-legacy content boundary, the same exit the other four
     channels' Orders pages took: this page is now built directly on the
     design tokens and the x-ui components. The cross-marketplace tab strip
     went with it. Inside a storefront's own workspace you are working one
     storefront, and the workspace sidebar (which names the store in its
     head) and the fulfilment hub are the two ways to reach another one. --}}
@section('content')
@php
    // This surface is read-only on the channel's own routes: OpenCart
    // declares no order write routes at all (see the "WRITES (none defined
    // currently)" note in extensions/opencart/routes/web.php). New order,
    // Edit and Delete are CORE order routes, so they are gated on the core
    // orders permission rather than on an opencart_orders one that nothing
    // would check.
    //
    // Read here rather than left to the panel because the page header below
    // needs it for its own button.
    $canManageOcOrders = auth()->user()?->hasPermission('manage_sales/order') ?? false;

    // The count in the deck names what is actually listed, so standing on a
    // status filter it says that status rather than the whole storefront.
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
    // PhpEngine::evaluatePath() ltrim()s every view's own render() output
    // (leading whitespace, not just newlines), so whatever blank lines and
    // indentation used to precede the panel's first visible element are
    // always stripped when it is @include'd below. Mirrors the same trap
    // documented on the other four channels' index views.
    //
    // One branch only: this panel has no permission-gated first element, so
    // its first visible element is always the filter row, written unindented
    // with one blank line above its own comment. Verified by HTML diff on
    // both tiers, both stores, not by reading the file.
    echo "\n\n";
@endphp@include('ext-opencart::orders._panel', [
    'panelBaseUrl' => route('ext.opencart.orders.index', $storeId),
    'panelHiddenParams' => [],
])

</div>{{-- /#opencart-orders-page --}}
@endsection
