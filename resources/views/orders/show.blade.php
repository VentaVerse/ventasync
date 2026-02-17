@extends('layouts.app')
@section('breadcrumb', 'Sales / Orders / View')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Order #{{ $order->order_id }}</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('orders.index') }}">Back</a>
            <a class="btn" href="{{ route('orders.edit', $order->order_id) }}">Edit</a>
        </div>
    </div>

    {{-- Order Meta Bar --}}
    <div class="meta-bar">
        <div class="meta-item">
            <span class="meta-label">Source:</span>
            @if($order->marketplace_source === 'lazada')
                <span class="badge badge-indigo">Lazada</span>
            @elseif($order->marketplace_source !== '')
                <span class="badge badge-dark">{{ ucfirst($order->marketplace_source) }}</span>
            @else
                <span class="badge badge-default">Manual</span>
            @endif
        </div>
        <div class="meta-item">
            <span class="meta-label">Status:</span>
            <span class="badge badge-blue">{{ $order->status->name ?? '-' }}</span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Date:</span>
            {{ $order->date_added }}
        </div>
        <div class="meta-item">
            <span class="meta-label">Total:</span>
            {{ $order->currency_code }} {{ number_format($order->total, 2) }}
        </div>
        @if($order->tracking_number)
        <div class="meta-item">
            <span class="meta-label">Tracking:</span>
            {{ $order->tracking_number }}
        </div>
        @endif
    </div>

    {{-- Customer + Addresses --}}
    <div class="detail-grid">
        <div class="detail-section">
            <h4>Customer Details</h4>
            <div class="detail-body">
                <strong>{{ $order->firstname }} {{ $order->lastname }}</strong><br>
                @if($order->email)<span>{{ $order->email }}</span><br>@endif
                @if($order->telephone)<span>{{ $order->telephone }}</span><br>@endif
            </div>
        </div>

        <div class="detail-section">
            <h4>Payment Address</h4>
            <div class="detail-body">
                @if($order->payment_firstname || $order->payment_lastname)
                    <strong>{{ $order->payment_firstname }} {{ $order->payment_lastname }}</strong><br>
                @endif
                @if($order->payment_company)<span>{{ $order->payment_company }}</span><br>@endif
                @if($order->payment_address_1)<span>{{ $order->payment_address_1 }}</span><br>@endif
                @if($order->payment_address_2)<span>{{ $order->payment_address_2 }}</span><br>@endif
                @if($order->payment_city || $order->payment_postcode)
                    <span>{{ $order->payment_city }} {{ $order->payment_postcode }}</span><br>
                @endif
                @if($order->payment_zone)<span>{{ $order->payment_zone }}</span><br>@endif
                @if($order->payment_country)<span>{{ $order->payment_country }}</span>@endif
                @if($order->payment_method)
                    <br><br><span class="meta-label">Payment Method:</span> {{ $order->payment_method }}
                @endif
            </div>
        </div>

        <div class="detail-section">
            <h4>Shipping Address</h4>
            <div class="detail-body">
                @if($order->shipping_firstname || $order->shipping_lastname)
                    <strong>{{ $order->shipping_firstname }} {{ $order->shipping_lastname }}</strong><br>
                @endif
                @if($order->shipping_company)<span>{{ $order->shipping_company }}</span><br>@endif
                @if($order->shipping_address_1)<span>{{ $order->shipping_address_1 }}</span><br>@endif
                @if($order->shipping_address_2)<span>{{ $order->shipping_address_2 }}</span><br>@endif
                @if($order->shipping_city || $order->shipping_postcode)
                    <span>{{ $order->shipping_city }} {{ $order->shipping_postcode }}</span><br>
                @endif
                @if($order->shipping_zone)<span>{{ $order->shipping_zone }}</span><br>@endif
                @if($order->shipping_country)<span>{{ $order->shipping_country }}</span>@endif
                @if($order->shipping_method)
                    <br><br><span class="meta-label">Shipping Method:</span> {{ $order->shipping_method }}
                @endif
            </div>
        </div>

        @if($order->comment)
        <div class="detail-section">
            <h4>Order Comment</h4>
            <div class="detail-body">{{ $order->comment }}</div>
        </div>
        @endif
    </div>

    {{-- Products --}}
    <h3 class="section-title">Products</h3>
    @if($products->count())
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th style="width:60px;">Image</th>
            <th>Product</th>
            <th>Model / SKU</th>
            <th class="text-right">Qty</th>
            <th class="text-right">Unit Price</th>
            <th class="text-right">Total</th>
        </tr>
        </thead>
        <tbody>
        @foreach($products as $p)
            @php
                $catalogProduct = $catalogProducts[$p->order_product_id] ?? null;
                $img = $catalogProduct ? trim((string)($catalogProduct->image ?? '')) : '';
                $imgSrc = $img !== '' ? asset('storage/' . ltrim($img, '/')) : '';
            @endphp
            <tr>
                <td>
                    @if($imgSrc)
                        <img src="{{ $imgSrc }}" alt="" class="thumb" loading="lazy" decoding="async" onerror="this.style.display='none';">
                    @else
                        <span class="thumb-placeholder" style="width:48px;height:48px;"></span>
                    @endif
                </td>
                <td>
                    <div class="font-semibold">{{ $p->name }}</div>
                    @if($p->options->count())
                        @foreach($p->options as $opt)
                            <small class="text-secondary">{{ $opt->name }}: {{ $opt->value }}</small><br>
                        @endforeach
                    @endif
                </td>
                <td class="text-sm text-secondary">{{ $p->model }}</td>
                <td class="text-right">{{ $p->quantity }}</td>
                <td class="text-right">{{ number_format($p->price, 2) }}</td>
                <td class="text-right font-semibold">{{ number_format($p->total, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    @else
        <p class="text-muted">No products on this order.</p>
    @endif

    {{-- Order Totals --}}
    @if($orderTotals->count())
    <div class="d-flex justify-end mt-12">
        <table class="max-w-300">
            <tbody>
            @foreach($orderTotals as $ot)
                <tr @if($ot->code === 'total') class="font-bold" style="font-size:15px;" @endif>
                    <td>{{ $ot->title }}</td>
                    <td class="text-right">{{ number_format($ot->value, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- Status Update --}}
    <h3 class="section-title mt-24">Update Status</h3>
    <form method="POST" action="{{ route('orders.update_status', $order->order_id) }}">
        @csrf
        <div class="d-flex gap-12 items-center flex-wrap">
            <div>
                <label class="font-semibold">Order Status</label>
                <select class="input" name="order_status_id" style="min-width:200px;">
                    @foreach($statuses as $s)
                        <option value="{{ $s->order_status_id }}" {{ $order->order_status_id == $s->order_status_id ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex:1; min-width:200px;">
                <label class="font-semibold">Comment</label>
                <input class="input" name="comment" value="" placeholder="Optional note">
            </div>
            <div style="padding-top:22px;">
                <button class="btn" type="submit">Update Status</button>
            </div>
        </div>
    </form>

    {{-- Order History --}}
    <h3 class="section-title mt-24">Order History</h3>
    @if($history->count())
    <div>
        @foreach($history as $h)
        <div class="history-item">
            <div class="history-date">{{ $h->date_added }}</div>
            <div>
                <div class="history-status">{{ $h->status->name ?? '-' }}</div>
                @if($h->comment)
                    <div class="history-comment">{{ $h->comment }}</div>
                @endif
            </div>
        </div>
        @endforeach
    </div>
    @else
        <p class="text-muted">No history records.</p>
    @endif
</div>
@endsection