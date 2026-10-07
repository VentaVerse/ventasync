@extends('layouts.channel')
@section('title', 'TikTok orders')
@section('breadcrumb', 'Orders')

@section('content')
@php
    $canManageTiktokOrders = auth()->user()?->hasPermission('manage_tiktok/order') ?? false;

    $tabs = $tabs ?? [];
    $active_tab = strtoupper((string) ($active_tab ?? 'ALL'));
    $pendingSub = $pending_sub ?? request()->query('pending_sub', 'to_pack');

    $pendingNav = [
        'to_pack' => 'To Pack',
        'to_handover' => 'To Handover',
    ];

    $scopeLabel = null;
    if ($active_tab === 'TO_SHIP') {
        $scopeLabel = $pendingNav[$pendingSub] ?? ($tabs['TO_SHIP'] ?? null);
    } elseif ($active_tab !== 'ALL') {
        $scopeLabel = $tabs[$active_tab] ?? null;
    }
@endphp

<div id="tiktok-orders-page" class="co-page" data-desk-scope
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

</div>
@endsection
