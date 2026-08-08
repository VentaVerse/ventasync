@extends('layouts.channel')
@section('breadcrumb', 'Orders')

{{-- Leaves the .x-legacy content boundary, the same exit Shopee's and
     Lazada's Orders pages took: this page is now built directly on the
     design tokens and the x-ui components, which is the designed way out of
     that boundary. The old .marketplace-tiktok wrapper went with it, because
     its only job was to repaint --accent with TikTok's pink, and channel
     identity belongs in the sidebar and the breadcrumb, not on every control
     of every row. The cross-marketplace tab strip went too: inside a seller
     center you see one marketplace. --}}
@section('content')
@php
    // Orders is visible to view_tiktok_orders holders; Fetch, Update and
    // Ship render only for the manage tier. The route middleware
    // (perm:manage_tiktok_orders on every write POST in
    // extensions/tiktok/routes/web.php) is the real gate; this flag only
    // stops the page offering buttons that would 403.
    //
    // The old page hardcoded this to true, the same defect Lazada's page
    // carried, which showed a read-only operator a command bar and a Ship
    // button that all 403 on submit. Reading the permission is what the
    // flag was written for.
    $canManageTiktokOrders = auth()->user()?->hasPermission('manage_tiktok_orders') ?? false;

    $tabs = $tabs ?? [];
    $active_tab = strtoupper((string) ($active_tab ?? 'ALL'));
    $pendingSub = $pending_sub ?? request()->query('pending_sub', 'to_pack');

    $pendingNav = [
        'to_pack' => 'To Pack',
        'to_handover' => 'To Handover',
    ];

    // The count in the deck is the count of what is actually listed, so it
    // names the step being listed rather than the tab above it: standing on
    // To Handover while the deck says "in To Ship" puts one number next to a
    // different one and reads as a bug.
    $scopeLabel = null;
    if ($active_tab === 'TO_SHIP') {
        $scopeLabel = $pendingNav[$pendingSub] ?? ($tabs['TO_SHIP'] ?? null);
    } elseif ($active_tab !== 'ALL') {
        $scopeLabel = $tabs[$active_tab] ?? null;
    }
@endphp

{{-- The one session value the page module needs (a waybill produced by the
     ship request that just finished) rides on the root element as a data
     attribute, escaped by Blade, rather than as a <script> block calling
     window.open with an interpolated URL. A new-UX page owns no inline
     script, the same way it owns no inline style. --}}
<div id="tiktok-orders-page" class="co-page"
     data-awb-url="{{ $last_result['awb_url'] ?? '' }}">

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
    // documented on ext-shopee::orders.index and ext-lazada::orders.index
    // for the identical three-way branch shape: the command bar
    // (unindented, one blank line above it) when manage-tier; the result
    // notice (indented four spaces inside its own @if, one blank line above
    // it) when a fetch/update/ship just ran and nothing is manage-tier; the
    // segment bar (preceded by a Blade comment, one blank line above the
    // comment) otherwise. Reproducing that only works from this side of the
    // include, and only by mirroring the same conditions the moved markup
    // itself branches on.
    if ($canManageTiktokOrders) {
        echo "\n\n";
    } elseif ($last_result) {
        echo "\n\n    ";
    } else {
        echo "\n\n\n\n";
    }
@endphp@include('ext-tiktok::orders._panel', [
    'panelBaseUrl' => route('ext.tiktok.orders.index'),
    'panelHiddenParams' => [],
])

</div>{{-- /#tiktok-orders-page --}}
@endsection
