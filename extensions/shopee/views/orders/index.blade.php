@extends('layouts.channel')
{{-- The channel belongs in the title because these tabs are kept open
     side by side. 'Orders' alone gave three identical browser tabs. --}}
@section('title', 'Shopee orders')
@section('breadcrumb', 'Orders')

{{-- Leaves the .x-legacy content boundary. This page is now built directly
     on the design tokens and the x-ui components, which is the designed
     exit path of that boundary: migrate a page by rebuilding it, then drop
     its wrapper. The old .marketplace-shopee wrapper went with it, because
     its only job was to repaint --accent with Shopee orange, and channel
     identity belongs in the sidebar and the breadcrumb, not on every
     control of every row. --}}
@section('content')
@php
    // The page is visible to view_shopee_orders holders; Fetch, Update,
    // Ship and Reset render only for the manage tier. The route middleware
    // (perm:manage_shopee_orders on every write POST) is the real gate; this
    // flag only stops the page offering buttons that would 403.
    $canManageShopeeOrders = auth()->user()?->hasPermission('manage_shopee_orders') ?? false;

    $tabs = $tabs ?? [];
    $active_tab = strtoupper((string) ($active_tab ?? 'ALL'));

    // The rest of the panel's presentation shaping (per-page, filter
    // strings, status tone and label maps, sort labels) moved into
    // orders/_panel.blade.php when the fulfilment hub became the panel's
    // second caller, so there is one copy rather than one per page. The
    // three above stay here because this view reads them itself: the two
    // below in the subtitle, and $canManageShopeeOrders in the spacing
    // block further down. Removing assignments from inside a @php block
    // changes no rendered output, which the byte-for-byte HTML diff of this
    // page confirmed.
@endphp

<div id="shopee-orders-page" class="co-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Orders</h1>
        <p class="x-page-sub">
            {{ number_format($orders->total()) }} {{ $orders->total() === 1 ? 'order' : 'orders' }}@if($active_tab !== 'ALL' && isset($tabs[$active_tab])) in {{ $tabs[$active_tab] }}@endif
        </p>
    </div>
</div>
@php
    // PhpEngine::evaluatePath() ltrim()s every view's own render() output
    // (leading whitespace, not just newlines), so whatever blank lines and
    // indentation used to precede the panel's first visible element are
    // always stripped when it is @include'd below. The monolithic page's
    // layout right here depended on which element rendered first, which
    // depended on $canManageShopeeOrders and $last_result: the command bar
    // (unindented, two blank lines above it) when manage-tier; the result
    // notice (indented 4 spaces one level inside its own @if, two blank
    // lines above it) when a fetch/update/ship just ran and nothing is
    // manage-tier; the segment bar (unindented, four blank lines above it)
    // otherwise. Reproducing that only works from this side of the
    // include, and only by mirroring the same conditions the moved markup
    // itself branches on.
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

</div>{{-- /#shopee-orders-page --}}
@endsection
