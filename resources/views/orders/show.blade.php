@extends('layouts.app')

@section('content')
<style>
    .order-overview { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; }
    .order-section { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; }
    .order-section h4 { margin:0 0 12px; font-size:14px; color:#334155; text-transform:uppercase; letter-spacing:0.5px; }
    .order-detail { font-size:13px; line-height:1.7; color:#475569; }
    .order-detail strong { color:#1e293b; }
    .order-meta { display:flex; gap:16px; align-items:center; flex-wrap:wrap; margin-bottom:20px; padding:12px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; }
    .order-meta .meta-item { font-size:13px; color:#475569; }
    .order-meta .meta-label { font-weight:600; color:#334155; margin-right:4px; }
    .status-badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:600; }
    .source-badge { display:inline-block; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600; }
    .prod-thumb { width:48px; height:48px; object-fit:cover; border-radius:8px; border:1px solid rgba(0,0,0,0.08); display:block; background:#fff; }
    .history-timeline { position:relative; }
    .history-item { display:flex; gap:12px; padding:10px 0; border-bottom:1px solid #f1f5f9; }
    .history-item:last-child { border-bottom:none; }
    .history-date { font-size:12px; color:#94a3b8; white-space:nowrap; min-width:140px; }
    .history-status { font-weight:600; font-size:13px; color:#334155; }
    .history-comment { font-size:13px; color:#64748b; margin-top:2px; }
    @media (max-width:768px) { .order-overview { grid-template-columns:1fr; } }
</style>

<div class="card">
    <div class="page-header">
        <h2>Order #{{ $order->order_id }}</h2>
        <div style="display:flex; gap:10px; align-items:center;">
            <a class="btn secondary" href="{{ route('orders.index') }}">Back</a>
            <a class="btn" href="{{ route('orders.edit', $order->order_id) }}">Edit</a>
        </div>
    </div>

    {{-- Order Meta Bar --}}
    <div class="order-meta">
        <div class="meta-item">
            <span class="meta-label">Source:</span>
            @if($order->marketplace_source === 'lazada')
                <span class="source-badge" style="background:#0f1689;color:#fff;">Lazada</span>
            @elseif($order->marketplace_source !== '')
                <span class="source-badge" style="background:#64748b;color:#fff;">{{ ucfirst($order->marketplace_source) }}</span>
            @else
                <span class="source-badge" style="background:#e2e8f0;color:#475569;">Manual</span>
            @endif
        </div>
        <div class="meta-item">
            <span class="meta-label">Status:</span>
            <span class="status-badge" style="background:#dbeafe;color:#1e40af;">{{ $order->status->name ?? '-' }}</span>
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
    <div class="order-overview">
        <div class="order-section">
            <h4>Customer Details</h4>
            <div class="order-detail">
                <strong>{{ $order->firstname }} {{ $order->lastname }}</strong><br>
                @if($order->email)<span>{{ $order->email }}</span><br>@endif
                @if($order->telephone)<span>{{ $order->telephone }}</span><br>@endif
            </div>
        </div>

        <div class="order-section">
            <h4>Payment Address</h4>
            <div class="order-detail">
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

        <div class="order-section">
            <h4>Shipping Address</h4>
            <div class="order-detail">
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
        <div class="order-section">
            <h4>Order Comment</h4>
            <div class="order-detail">{{ $order->comment }}</div>
        </div>
        @endif
    </div>

    {{-- Products --}}
    <h3 style="margin:24px 0 10px; font-size:15px; color:#334155; border-bottom:1px solid #e5e7eb; padding-bottom:6px;">Products</h3>
    @if($products->count())
    <table>
        <thead>
        <tr>
            <th style="width:60px;">Image</th>
            <th>Product</th>
            <th>Model / SKU</th>
            <th style="text-align:right;">Qty</th>
            <th style="text-align:right;">Unit Price</th>
            <th style="text-align:right;">Total</th>
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
                        <img src="{{ $imgSrc }}" alt="" class="prod-thumb" loading="lazy" decoding="async" onerror="this.style.display='none';">
                    @else
                        <span style="display:inline-block;width:48px;height:48px;border-radius:8px;background:#f1f5f9;border:1px solid #e2e8f0;"></span>
                    @endif
                </td>
                <td>
                    <div style="font-weight:600;">{{ $p->name }}</div>
                    @if($p->options->count())
                        @foreach($p->options as $opt)
                            <small style="color:#64748b;">{{ $opt->name }}: {{ $opt->value }}</small><br>
                        @endforeach
                    @endif
                </td>
                <td style="font-size:13px; color:#64748b;">{{ $p->model }}</td>
                <td style="text-align:right;">{{ $p->quantity }}</td>
                <td style="text-align:right;">{{ number_format($p->price, 2) }}</td>
                <td style="text-align:right; font-weight:600;">{{ number_format($p->total, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @else
        <p style="color:#94a3b8;">No products on this order.</p>
    @endif

    {{-- Order Totals --}}
    @if($orderTotals->count())
    <div style="display:flex; justify-content:flex-end; margin-top:12px;">
        <table style="width:300px;">
            <tbody>
            @foreach($orderTotals as $ot)
                <tr @if($ot->code === 'total') style="font-weight:700; font-size:15px;" @endif>
                    <td>{{ $ot->title }}</td>
                    <td style="text-align:right;">{{ number_format($ot->value, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- Status Update --}}
    <h3 style="margin:30px 0 10px; font-size:15px; color:#334155; border-bottom:1px solid #e5e7eb; padding-bottom:6px;">Update Status</h3>
    <form method="POST" action="{{ route('orders.update_status', $order->order_id) }}">
        @csrf
        <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <div>
                <label style="font-size:13px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Order Status</label>
                <select class="input" name="order_status_id" style="min-width:200px;">
                    @foreach($statuses as $s)
                        <option value="{{ $s->order_status_id }}" {{ $order->order_status_id == $s->order_status_id ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex:1; min-width:200px;">
                <label style="font-size:13px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Comment</label>
                <input class="input" name="comment" value="" placeholder="Optional note" style="width:100%; box-sizing:border-box;">
            </div>
            <button class="btn" type="submit">Update Status</button>
        </div>
    </form>

    {{-- Order History --}}
    <h3 style="margin:30px 0 10px; font-size:15px; color:#334155; border-bottom:1px solid #e5e7eb; padding-bottom:6px;">Order History</h3>
    @if($history->count())
    <div class="history-timeline">
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
        <p style="color:#94a3b8;">No history records.</p>
    @endif
</div>
@endsection
