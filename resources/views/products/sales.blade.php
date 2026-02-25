@extends('layouts.app')
@section('breadcrumb', 'Catalog / Products / Sales History')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Sales History: {{ $product->name }}</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ request('back', route('products.index')) }}">Back to Products</a>
            <a class="btn secondary" href="{{ route('products.edit', $product->product_id) }}">Edit Product</a>
        </div>
    </div>

    @if($product->sku)
        <div class="mb-12 text-secondary text-sm">SKU: {{ $product->sku }}</div>
    @endif

    @if($sales->isEmpty())
        <div class="text-secondary text-center py-32">No sales history for this product.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th style="width:90px;">Order ID</th>
                    <th>Date Added</th>
                    <th>Product</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Product Sales</th>
                    <th class="text-right">Product Cost</th>
                    <th class="text-right">Product Profit</th>
                    <th class="text-right">Profit Margin %</th>
                    <th class="text-right">Profit Markup %</th>
                </tr>
                </thead>
                <tbody>
                @php
                    $totalQty = 0;
                    $totalSales = 0;
                    $totalCost = 0;
                    $totalProfit = 0;
                @endphp
                @foreach($sales as $s)
                    @php
                        $totalQty += (int) $s->quantity;
                        $totalSales += (float) $s->total;
                        $totalCost += $s->line_cost;
                        $totalProfit += $s->profit;
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('orders.show', $s->order_id) }}" class="font-semibold" style="color:var(--accent);">{{ $s->order_id }}</a>
                        </td>
                        <td class="text-nowrap text-secondary text-sm">{{ \Carbon\Carbon::parse($s->date_added)->format('M d, Y') }}</td>
                        <td>
                            <div class="font-semibold">{{ $s->name }}</div>
                            @foreach($s->options as $opt)
                                <small class="text-secondary">{{ $opt->name }}: {{ $opt->value }}</small><br>
                            @endforeach
                        </td>
                        <td class="text-right">{{ $s->quantity }}</td>
                        <td class="text-right font-semibold">{{ number_format($s->total, 2) }}</td>
                        <td class="text-right text-secondary">{{ $s->line_cost > 0 ? number_format($s->line_cost, 2) : '-' }}</td>
                        <td class="text-right font-semibold" style="color: {{ $s->profit >= 0 ? 'var(--success)' : 'var(--danger)' }}">
                            {{ $s->line_cost > 0 ? number_format($s->profit, 2) : '-' }}
                        </td>
                        <td class="text-right">
                            @if($s->line_cost > 0)
                                <span style="color: {{ $s->margin >= 20 ? 'var(--success)' : ($s->margin >= 10 ? 'var(--warning, #e6a700)' : 'var(--danger)') }}">{{ $s->margin }}%</span>
                            @else
                                -
                            @endif
                        </td>
                        <td class="text-right">
                            @if($s->line_cost > 0)
                                <span style="color: {{ $s->markup >= 20 ? 'var(--success)' : ($s->markup >= 10 ? 'var(--warning, #e6a700)' : 'var(--danger)') }}">{{ $s->markup }}%</span>
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                    <tr style="font-weight:600;">
                        <td colspan="3">Total</td>
                        <td class="text-right">{{ number_format($totalQty) }}</td>
                        <td class="text-right">{{ number_format($totalSales, 2) }}</td>
                        <td class="text-right">{{ number_format($totalCost, 2) }}</td>
                        <td class="text-right" style="color: {{ $totalProfit >= 0 ? 'var(--success)' : 'var(--danger)' }}">{{ number_format($totalProfit, 2) }}</td>
                        <td class="text-right">{{ $totalSales > 0 ? round(($totalProfit / $totalSales) * 100, 1) : 0 }}%</td>
                        <td class="text-right">{{ $totalCost > 0 ? round(($totalProfit / $totalCost) * 100, 1) : 0 }}%</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="mt-16">
            {{ $sales->onEachSide(1)->links('vendor.pagination.simple') }}
        </div>
    @endif
</div>
@endsection
