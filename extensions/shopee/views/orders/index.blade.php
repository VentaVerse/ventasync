@extends('layouts.channel')
@section('breadcrumb', 'Orders')

{{-- Leaves the .x-legacy content boundary. This page is now built directly
     on the design tokens and the x-ui components, which is the designed
     exit path of that boundary: migrate a page by rebuilding it, then drop
     its wrapper. The old .marketplace-shopee wrapper went with it, because
     its only job was to repaint --accent with Shopee orange, and channel
     identity belongs in the sidebar and the breadcrumb, not on every
     control of every row. --}}
@section('content-class', '')

@section('content')
@php
    // The page is visible to view_shopee_orders holders; Fetch, Update,
    // Ship and Reset render only for the manage tier. The route middleware
    // (perm:manage_shopee_orders on every write POST) is the real gate; this
    // flag only stops the page offering buttons that would 403.
    $canManageShopeeOrders = auth()->user()?->hasPermission('manage_shopee_orders') ?? false;

    $tabs = $tabs ?? [];
    $active_tab = strtoupper((string) ($active_tab ?? 'ALL'));
    $tab_counts = $tab_counts ?? [];
    $pending_sub_counts = $pending_sub_counts ?? [];
    $perPage = (int) ($per_page ?? request()->query('per_page', 10));
    $orderNumberFilter = (string) ($filters['order_number'] ?? '');
    $buyerNameFilter = (string) ($filters['buyer_name'] ?? '');
    $hasSearch = $orderNumberFilter !== '' || $buyerNameFilter !== '';
    $pendingSub = $pending_subtab ?? request()->query('pending_sub', 'to_pack');

    // Status tone map. Colour has exactly one job on this page: telling the
    // operator which orders are waiting on them. Accent marks the single
    // status a human has to act on right now; everything already handled
    // recedes to neutral; the two failure states take danger; the two
    // waiting-on-someone-else states take warning and info.
    //
    // One deliberate reading of the spec's tone list: COMPLETED is mapped to
    // neutral rather than the success tone, because x-badge--success and the
    // accent tone resolve to the same token, which would have made "ready to
    // ship" and "completed" the identical colour and undone the point of the
    // map. A finished order is the one state that should recede.
    //
    // This array is the pattern to extract into a helper (alongside
    // App\Support\OrderStatusTone) when the second channel converts.
    $spStatusTones = [
        'UNPAID'             => 'warning',
        'READY_TO_SHIP'      => 'success',
        'PROCESSED'          => 'info',
        'SHIPPED'            => 'info',
        'TO_CONFIRM_RECEIVE' => 'info',
        'COMPLETED'          => 'neutral',
        'CANCELLED'          => 'danger',
        'IN_CANCEL'          => 'danger',
        'RETRY_SHIP'         => 'warning',
    ];

    $spStatusLabels = [
        'UNPAID'             => 'Unpaid',
        'READY_TO_SHIP'      => 'Ready to ship',
        'PROCESSED'          => 'To handover',
        'SHIPPED'            => 'Shipped',
        'TO_CONFIRM_RECEIVE' => 'Awaiting confirmation',
        'COMPLETED'          => 'Completed',
        'CANCELLED'          => 'Cancelled',
        'IN_CANCEL'          => 'Cancelling',
        'RETRY_SHIP'         => 'Delivery failed',
    ];

    $spSortLabels = [
        'created_desc'          => 'Order date, newest first',
        'created_asc'           => 'Order date, oldest first',
        'confirmed_pay_desc'    => 'Payment time, newest first',
        'confirmed_pay_asc'     => 'Payment time, oldest first',
        'confirmed_update_desc' => 'Last update, newest first',
        'confirmed_update_asc'  => 'Last update, oldest first',
        'confirmed_create_desc' => 'Shopee create time, newest first',
        'confirmed_create_asc'  => 'Shopee create time, oldest first',
    ];
@endphp

<div id="shopee-orders-page">

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
