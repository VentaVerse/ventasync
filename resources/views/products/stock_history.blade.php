@extends('layouts.app')
@section('breadcrumb', 'Catalog / Products / Stock History')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Stock History: {{ $product->name }}</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ request('back', route('products.index')) }}">Back to Products</a>
            <a class="btn secondary" href="{{ route('products.edit', $product->product_id) }}">Edit Product</a>
        </div>
    </div>

    @if($product->sku)
        <div class="mb-8 text-secondary text-sm">SKU: {{ $product->sku }}</div>
    @endif

    <div style="display:flex; gap:16px; margin-bottom:20px; flex-wrap:wrap;">
        <div class="report-stat-card" style="flex:1; min-width:140px;">
            <div class="report-stat-label">Current Stock</div>
            <div class="report-stat-value">{{ number_format($product->quantity) }}</div>
        </div>
        <div class="report-stat-card" style="flex:1; min-width:140px;">
            <div class="report-stat-label">Total Added</div>
            <div class="report-stat-value" style="color:var(--success);">+{{ number_format($totalAdded) }}</div>
        </div>
        <div class="report-stat-card" style="flex:1; min-width:140px;">
            <div class="report-stat-label">Total Deducted</div>
            <div class="report-stat-value" style="color:var(--danger);">{{ number_format($totalDeducted) }}</div>
        </div>
    </div>

    @if($history->isEmpty())
        <div class="text-secondary text-center py-32">No stock history for this product yet.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Option</th>
                    <th class="text-right">Before</th>
                    <th class="text-right">Change</th>
                    <th class="text-right">After</th>
                    <th>Order</th>
                    <th>Source</th>
                    <th>Note</th>
                    <th>By</th>
                </tr>
                </thead>
                <tbody>
                @foreach($history as $h)
                    <tr>
                        <td class="text-nowrap text-sm text-secondary">{{ \Carbon\Carbon::parse($h->created_at)->format('M d, Y H:i') }}</td>
                        <td>
                            @if($h->type === 'deduct')
                                <span class="badge badge-red">Deduct</span>
                            @elseif($h->type === 'restore')
                                <span class="badge badge-green">Restore</span>
                            @elseif($h->type === 'set')
                                <span class="badge badge-blue">Set</span>
                            @else
                                <span class="badge badge-default">{{ ucfirst($h->type) }}</span>
                            @endif
                        </td>
                        <td class="text-sm">
                            @if($h->product_option_value_id)
                                {{ $optionValueNames[$h->product_option_value_id] ?? 'Option #'.$h->product_option_value_id }}
                            @else
                                <span class="text-secondary">—</span>
                            @endif
                        </td>
                        <td class="text-right">{{ number_format($h->quantity_before) }}</td>
                        <td class="text-right font-semibold" style="color: {{ $h->quantity_change >= 0 ? 'var(--success)' : 'var(--danger)' }}">
                            {{ $h->quantity_change >= 0 ? '+' : '' }}{{ number_format($h->quantity_change) }}
                        </td>
                        <td class="text-right font-semibold">{{ number_format($h->quantity_after) }}</td>
                        <td>
                            @if($h->order_id)
                                <a href="{{ route('orders.show', $h->order_id) }}" class="font-semibold" style="color:var(--accent);">#{{ $h->order_id }}</a>
                            @else
                                <span class="text-secondary">—</span>
                            @endif
                        </td>
                        <td>
                            @if($h->source === 'manual')
                                <span class="badge badge-default">Manual</span>
                            @elseif($h->source === 'order')
                                <span class="badge badge-blue">Order</span>
                            @elseif($h->source === 'lazada_sync')
                                <span class="badge badge-indigo">Lazada</span>
                            @elseif($h->source === 'shopee_sync')
                                <span class="badge badge-orange">Shopee</span>
                            @elseif($h->source === 'opencart_sync')
                                <span class="badge badge-dark">OpenCart</span>
                            @else
                                <span class="badge badge-default">{{ $h->source }}</span>
                            @endif
                        </td>
                        <td class="text-sm text-secondary">{{ $h->note ?? '' }}</td>
                        <td class="text-sm text-nowrap">{{ $h->user_name ?? 'System' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-16">
            {{ $history->onEachSide(1)->links('vendor.pagination.simple') }}
        </div>
    @endif
</div>
@endsection
