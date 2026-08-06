@extends('layouts.channel')
@section('breadcrumb', 'Orders')

{{-- Leaves the .x-legacy content boundary, the same exit Shopee's Orders
     page took: this page is now built directly on the design tokens and the
     x-ui components, which is the designed way out of that boundary. The old
     .marketplace-lazada wrapper went with it, because its only job was to
     repaint --accent with Lazada's blue, and channel identity belongs in the
     sidebar and the breadcrumb, not on every control of every row. The
     cross-marketplace tab strip went too: inside a seller center you see one
     marketplace. --}}
@section('content-class', '')

@section('content')
@php
    // Orders is visible to view_lazada_orders holders; Fetch, Update, Pack,
    // Arrange shipment and Recreate package render only for the manage tier.
    // The route middleware (perm:manage_lazada_orders on every write POST in
    // extensions/lazada/routes/web.php) is the real gate; this flag only
    // stops the page offering buttons that would 403.
    //
    // The old page hardcoded this to true, which left its own read-only
    // branch (written right below it, for exactly this permission) dead and
    // showed a viewer four buttons that all 403 on submit. Reading the
    // permission is what that branch was written for.
    //
    // The rest of the panel's presentation shaping (per-page, filter
    // strings, tab/sub-tab counts, sort labels) moved into
    // orders/_panel.blade.php when the fulfilment hub became the panel's
    // second caller, so there is one copy rather than one per page. The vars
    // below stay here because this view reads them itself before the
    // include: $canManageLazadaOrders in the ltrim whitespace mirror further
    // down, and the rest in the page title's subtitle.
    $canManageLazadaOrders = auth()->user()?->hasPermission('manage_lazada_orders') ?? false;

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

    // The count in the deck is the count of what is actually listed, so it
    // names the step being listed. Saying "in To Ship" while standing on To
    // Arrange Shipment put a 1 next to a tab reading 2, which reads as a bug
    // rather than as two different questions being answered.
    $scopeLabel = null;
    if ($active_tab === 'TO_SHIP') {
        $scopeLabel = $pendingNav[$pendingSub] ?? ($tabs['TO_SHIP'] ?? null);
    } elseif ($active_tab === 'FAILED_DELIVERY') {
        $scopeLabel = $fdNav[$fdSub] ?? ($tabs['FAILED_DELIVERY'] ?? null);
    } elseif ($active_tab !== 'ALL') {
        $scopeLabel = $tabs[$active_tab] ?? null;
    }
@endphp

{{-- The session values and route templates the page module needs ride on the
     root element as data attributes, escaped by Blade, rather than as a
     <script> block of interpolated PHP. A new-UX page owns no inline script,
     the same way it owns no inline style. --}}
<div id="lazada-orders-page" class="co-page"
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
    // PhpEngine::evaluatePath() ltrim()s every view's own render() output
    // (leading whitespace, not just newlines), so whatever blank lines and
    // indentation used to precede the panel's first visible element are
    // always stripped when it is @include'd below. Mirrors the same trap
    // documented on ext-shopee::orders.index for the identical three-way
    // branch shape: the command bar (unindented, one blank line above it)
    // when manage-tier; the result notice (unindented, one blank line above
    // it) when a fetch/update/pack/rts/recreate just ran and nothing is
    // manage-tier; the segment bar (preceded by a Blade comment, one blank
    // line above the comment) otherwise. Reproducing that only works from
    // this side of the include, and only by mirroring the same conditions
    // the moved markup itself branches on.
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

</div>{{-- /#lazada-orders-page --}}
@endsection
