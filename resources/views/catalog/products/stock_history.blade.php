@extends('layouts.blotter')
@section('title', 'Stock history: ' . $product->name)
@section('breadcrumb', 'Stock history')

@section('content')
@php
    $backUrl = request('back', route('products.index'));

    $typeTone = fn (string $t) => match ($t) {
        'deduct'  => 'danger',
        'restore' => 'success',
        default   => 'neutral',
    };
    $typeLabel = fn (string $t) => match ($t) {
        'deduct'  => 'Deducted',
        'restore' => 'Restored',
        'set'     => 'Set',
        default   => ucfirst($t),
    };
    $sourceLabel = fn (string $s) => match ($s) {
        'manual'        => 'Manual',
        'order'         => 'Order',
        'lazada_sync'   => 'Lazada',
        'shopee_sync'   => 'Shopee',
        'opencart_sync' => 'OpenCart',
        'purchase_order' => 'Purchase order',
        default         => $s,
    };
@endphp

<div class="fm-page">
    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ $backUrl }}">
                <x-ui.icon name="chevron-left" size="14" /> Products
            </a>
            <h1 class="fm-title">Stock history</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Product</span>
                    <span class="fm-stamp__v">{{ $product->name }}</span>
                </span>
                @if($product->sku)
                    <span class="fm-stamp">
                        <span class="fm-stamp__k">SKU</span>
                        <span class="fm-stamp__v">{{ $product->sku }}</span>
                    </span>
                @endif
            </div>
        </div>
        <div class="fm-head__actions">
            <x-ui.button :href="route('products.edit', $product->product_id)">Edit product</x-ui.button>
        </div>
    </div>

    <div class="fm-stats">
        <div class="fm-stat">
            <span class="fm-stat__k">On hand</span>
            <span class="fm-stat__v">{{ number_format($product->quantity) }}</span>
        </div>
        <div class="fm-stat">
            <span class="fm-stat__k">Added, all time</span>
            <span class="fm-stat__v">+{{ number_format($totalAdded) }}</span>
        </div>
        <div class="fm-stat">
            <span class="fm-stat__k">Deducted, all time</span>
            <span class="fm-stat__v fm-neg">{{ number_format($totalDeducted) }}</span>
        </div>
    </div>

@if($history->isEmpty())
    <x-ui.empty title="No stock movements yet"
                description="Every change to this product's quantity, from an order, a sync or a manual edit, is recorded here." />
@else
    <x-ui.table>
        <x-slot:head>
            <tr>
                <th scope="col" class="fm-vcol-opt">When</th>
                <th scope="col" class="fm-vcol-qty">Type</th>
                <th scope="col" class="fm-vcol-name">Variation</th>
                <th scope="col" class="x-td-num fm-vcol-qty">Before</th>
                <th scope="col" class="x-td-num fm-vcol-qty">Change</th>
                <th scope="col" class="x-td-num fm-vcol-qty">After</th>
                <th scope="col" class="fm-vcol-qty">Order</th>
                <th scope="col" class="fm-vcol-sku">Source</th>
                <th scope="col">Note</th>
                <th scope="col" class="fm-col-who">By</th>
            </tr>
        </x-slot:head>

        @foreach($history as $h)
            <tr>
                <td data-label="When"><span class="x-num">{{ \Carbon\Carbon::parse($h->created_at)->format('Y-m-d H:i') }}</span></td>
                <td data-label="Type">
                    <x-ui.badge :tone="$typeTone($h->type)">{{ $typeLabel($h->type) }}</x-ui.badge>
                </td>
                <td data-label="Variation">
                    @if($h->product_option_value_id)
                        {{ $optionValueNames[$h->product_option_value_id] ?? 'Variation #'.$h->product_option_value_id }}
                    @else
                        <span class="fm-hint">Whole product</span>
                    @endif
                </td>
                <td class="x-td-num" data-label="Before"><span class="x-num">{{ number_format($h->quantity_before) }}</span></td>
                <td class="x-td-num" data-label="Change">
                    <span class="x-num{{ $h->quantity_change < 0 ? ' fm-neg' : '' }}">{{ $h->quantity_change >= 0 ? '+' : '' }}{{ number_format($h->quantity_change) }}</span>
                </td>
                <td class="x-td-num" data-label="After"><span class="x-num">{{ number_format($h->quantity_after) }}</span></td>
                <td data-label="Order">
                    @if($h->order_id)
                        <a class="x-row-link x-num" href="{{ route('orders.show', $h->order_id) }}">{{ $h->order_id }}</a>
                    @else
                        <span class="fm-hint">None</span>
                    @endif
                </td>
                <td data-label="Source">{{ $sourceLabel((string) $h->source) }}</td>
                <td data-label="Note">{{ $h->note ?? '' }}</td>
                <td class="fm-col-who" data-label="By">{{ $h->user_name ?? 'System' }}</td>
            </tr>
        @endforeach
    </x-ui.table>

    <x-ui.pager :paginator="$history" />
@endif
</div>
@endsection
