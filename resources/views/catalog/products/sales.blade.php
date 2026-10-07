@extends('layouts.blotter')
@section('title', 'Sales history: ' . $product->name)
@section('breadcrumb', 'Sales history')

@section('content')
@php
    $backUrl = request('back', route('products.index'));

    $tone = fn ($pct) => $pct >= 20 ? '' : ($pct >= 10 ? ' fm-warn' : ' fm-neg');
@endphp

<div class="fm-page">
    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ $backUrl }}">
                <x-ui.icon name="chevron-left" size="14" /> Products
            </a>
            <h1 class="fm-title">Sales history</h1>
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

@if($sales->isEmpty())
    <x-ui.empty title="No sales yet"
                description="Once this product sells, every order line shows up here with its cost and margin." />
@else
    @php
        $totalQty = 0; $totalSales = 0; $totalCost = 0; $totalProfit = 0;
        foreach ($sales as $s) {
            $totalQty += (int) $s->quantity;
            $totalSales += (float) $s->total;
            $totalCost += $s->line_cost;
            $totalProfit += $s->profit;
        }
    @endphp

    <div class="fm-stats">
        <div class="fm-stat">
            <span class="fm-stat__k">Units sold</span>
            <span class="fm-stat__v">{{ number_format($totalQty) }}</span>
        </div>
        <div class="fm-stat">
            <span class="fm-stat__k">Revenue</span>
            <span class="fm-stat__v"><x-money :php="(float) $totalSales" /></span>
        </div>
        <div class="fm-stat">
            <span class="fm-stat__k">Cost</span>
            <span class="fm-stat__v"><x-money :php="(float) $totalCost" /></span>
        </div>
        <div class="fm-stat">
            <span class="fm-stat__k">Profit</span>
            <span class="fm-stat__v{{ $totalProfit < 0 ? ' fm-neg' : '' }}"><x-money :php="(float) $totalProfit" /></span>
        </div>
        <div class="fm-stat">
            <span class="fm-stat__k">Margin</span>
            <span class="fm-stat__v">{{ $totalSales > 0 ? round(($totalProfit / $totalSales) * 100, 1) : 0 }}%</span>
        </div>
    </div>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th scope="col" class="fm-vcol-qty">Order</th>
                <th scope="col" class="fm-vcol-price">Date</th>
                <th scope="col" class="x-col-chan">Channel</th>
                <th scope="col">Product</th>
                <th scope="col" class="x-td-num fm-vcol-qty">Qty</th>
                <th scope="col" class="x-td-num fm-vcol-price">Sales</th>
                <th scope="col" class="x-td-num fm-vcol-cost">Cost</th>
                <th scope="col" class="x-td-num fm-vcol-price">Profit</th>
                <th scope="col" class="x-td-num fm-vcol-num">Margin</th>
                <th scope="col" class="x-td-num fm-vcol-num">Markup</th>
            </tr>
        </x-slot:head>

        @foreach($sales as $s)
            <tr>
                <td data-label="Order"><a class="x-row-link x-num" href="{{ route('orders.show', $s->order_id) }}">{{ $s->order_id }}</a></td>
                <td data-label="Date"><span class="x-num">{{ \Carbon\Carbon::parse($s->date_added)->format('Y-m-d') }}</span></td>
                @php
                    $src = trim((string) ($s->marketplace_source ?? ''));
                    $chanKey = $src === '' ? '' : strtok($src, ':');
                    $chanTone = $src === '' ? 'neutral' : $chanKey;
                    $chanOpt = $src === '' ? null : (($sourceLabelsMap ?? [])[$src] ?? null);
                    $chanLabel = $src === '' ? 'Manual' : ($chanOpt['label'] ?? ucfirst($chanKey));
                @endphp
                <td data-label="Channel"><x-ui.badge :tone="$chanTone" :dot="false">{{ $chanLabel }}</x-ui.badge></td>
                <td data-label="Product">
                    <div class="co-item__name">{{ $s->name }}</div>
                    @if(count($s->options))
                        <div class="co-item__meta">
                            @foreach($s->options as $opt)
                                <span class="co-item__var">{{ $opt->name }}: {{ $opt->value }}</span>
                            @endforeach
                        </div>
                    @endif
                </td>
                <td class="x-td-num" data-label="Qty"><span class="x-num">{{ $s->quantity }}</span></td>
                <td class="x-td-num" data-label="Sales"><span class="x-num"><x-money :php="(float) $s->total" /></span></td>
                <td class="x-td-num" data-label="Cost">
                    <span class="x-num">{{ $s->line_cost > 0 ? number_format($s->line_cost, 2) : '-' }}</span>
                </td>
                <td class="x-td-num" data-label="Profit">
                    <span class="x-num{{ $s->profit < 0 ? ' fm-neg' : '' }}">{{ $s->line_cost > 0 ? number_format($s->profit, 2) : '-' }}</span>
                </td>
                <td class="x-td-num" data-label="Margin">
                    <span class="x-num{{ $s->line_cost > 0 ? $tone($s->margin) : '' }}">{{ $s->line_cost > 0 ? $s->margin.'%' : '-' }}</span>
                </td>
                <td class="x-td-num" data-label="Markup">
                    <span class="x-num{{ $s->line_cost > 0 ? $tone($s->markup) : '' }}">{{ $s->line_cost > 0 ? $s->markup.'%' : '-' }}</span>
                </td>
            </tr>
        @endforeach
    </x-ui.table>

    <x-ui.pager :paginator="$sales" />
@endif
</div>
@endsection
