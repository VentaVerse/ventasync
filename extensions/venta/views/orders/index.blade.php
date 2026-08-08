@extends('layouts.channel')
@section('title', $storeLabel . ' Orders')
@section('breadcrumb', 'Orders')

{{-- Leaves the .x-legacy content boundary, the same exit Shopee's, Lazada's
     and TikTok's Orders pages took: this page is now built directly on the
     design tokens and the x-ui components. The cross-marketplace tab strip
     went with it. Inside a store's own workspace you are working one store,
     and the workspace sidebar (which names the store in its head) and the
     fulfilment hub are the two ways to reach another one. --}}
@section('content')
@php
    // Orders is visible to view_venta_orders holders; Fetch and the bulk
    // delete render only for the manage tier. The route middleware
    // (perm:manage_venta_orders on every write in
    // extensions/venta/routes/web.php) is the real gate; this flag only
    // stops the page offering controls that would 403.
    //
    // The old page hardcoded this to true, the same defect Lazada's and
    // TikTok's pages carried, which showed a read-only operator a fetch bar
    // and a row of Delete buttons that all fail on submit.
    //
    // Read here rather than left to the panel because the ltrim mirror below
    // branches on it, and because the deck above needs the scope label.
    $canManageVentaOrders = auth()->user()?->hasPermission('manage_venta_orders') ?? false;

    // The count in the deck names what is actually listed, so standing on a
    // status filter it says that status rather than the whole store.
    $vtStatus = (string) ($filters['status'] ?? '');
    $vtScopeLabel = $vtStatus !== '' ? ucwords(str_replace('_', ' ', $vtStatus)) : null;
@endphp

<div id="venta-orders-page" class="co-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Orders</h1>
        <p class="x-page-sub">
            {{ number_format($orders->total()) }} {{ $orders->total() === 1 ? 'order' : 'orders' }}@if($vtScopeLabel) in {{ $vtScopeLabel }}@endif
        </p>
    </div>
</div>
@php
    // PhpEngine::evaluatePath() ltrim()s every view's own render() output
    // (leading whitespace, not just newlines), so whatever blank lines and
    // indentation used to precede the panel's first visible element are
    // always stripped when it is @include'd below. Mirrors the same trap
    // documented on the other three channels' index views: the command bar
    // (unindented, one blank line above it) when manage-tier, the segment
    // bar (preceded by a Blade comment, one blank line above the comment)
    // otherwise. Reproducing that only works from this side of the include,
    // and only by mirroring the same condition the moved markup branches on.
    //
    // The counts below were arrived at by HTML diff against the
    // pre-extraction page, on both tiers and both stores, not by reading the
    // file: the viewer branch is one newline longer because the command
    // bar's own @if/@endif pair still contributed a line break when it
    // rendered nothing.
    if ($canManageVentaOrders) {
        echo "\n\n";
    } else {
        echo "\n\n\n";
    }
@endphp@include('ext-venta::orders._panel', [
    'panelBaseUrl' => route('ext.venta.orders.index', $storeId),
    'panelHiddenParams' => [],
])

</div>{{-- /#venta-orders-page --}}
@endsection
