@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit Order #{{ $order->order_id }}</h2>
        <div style="display:flex; gap:10px; align-items:center;">
            <a class="btn secondary" href="{{ route('orders.index') }}">Back</a>
            <button class="btn" type="submit" form="order-form">Update</button>
        </div>
    </div>

    <form id="order-form" method="POST" action="{{ route('orders.update', $order->order_id) }}">
        @csrf
        @method('PUT')

        {{-- Customer Details --}}
        <h3 style="margin:18px 0 10px; font-size:15px; color:#334155; border-bottom:1px solid #e5e7eb; padding-bottom:6px;">Customer Details</h3>
        <div class="form-grid">
            <div>
                <label class="required">First Name</label>
                <input class="input" name="firstname" value="{{ old('firstname', $order->firstname) }}" required maxlength="32" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Last Name</label>
                <input class="input" name="lastname" value="{{ old('lastname', $order->lastname) }}" maxlength="32" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Email</label>
                <input class="input" name="email" value="{{ old('email', $order->email) }}" maxlength="96" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Telephone</label>
                <input class="input" name="telephone" value="{{ old('telephone', $order->telephone) }}" maxlength="32" style="width:100%; box-sizing:border-box;">
            </div>
        </div>

        {{-- Payment Details --}}
        <h3 style="margin:18px 0 10px; font-size:15px; color:#334155; border-bottom:1px solid #e5e7eb; padding-bottom:6px;">Payment Details</h3>
        <div class="form-grid">
            <div>
                <label>First Name</label>
                <input class="input" name="payment_firstname" value="{{ old('payment_firstname', $order->payment_firstname) }}" maxlength="32" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Last Name</label>
                <input class="input" name="payment_lastname" value="{{ old('payment_lastname', $order->payment_lastname) }}" maxlength="32" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Company</label>
                <input class="input" name="payment_company" value="{{ old('payment_company', $order->payment_company) }}" maxlength="40" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Address 1</label>
                <input class="input" name="payment_address_1" value="{{ old('payment_address_1', $order->payment_address_1) }}" maxlength="128" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Address 2</label>
                <input class="input" name="payment_address_2" value="{{ old('payment_address_2', $order->payment_address_2) }}" maxlength="128" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>City</label>
                <input class="input" name="payment_city" value="{{ old('payment_city', $order->payment_city) }}" maxlength="128" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Postcode</label>
                <input class="input" name="payment_postcode" value="{{ old('payment_postcode', $order->payment_postcode) }}" maxlength="10" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Country</label>
                <input class="input" name="payment_country" value="{{ old('payment_country', $order->payment_country) }}" maxlength="128" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Zone / Region</label>
                <input class="input" name="payment_zone" value="{{ old('payment_zone', $order->payment_zone) }}" maxlength="128" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Payment Method</label>
                <input class="input" name="payment_method" value="{{ old('payment_method', $order->payment_method) }}" maxlength="128" style="width:100%; box-sizing:border-box;">
            </div>
        </div>

        {{-- Shipping Details --}}
        <h3 style="margin:18px 0 10px; font-size:15px; color:#334155; border-bottom:1px solid #e5e7eb; padding-bottom:6px;">Shipping Details</h3>
        <div class="form-grid">
            <div>
                <label>First Name</label>
                <input class="input" name="shipping_firstname" value="{{ old('shipping_firstname', $order->shipping_firstname) }}" maxlength="32" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Last Name</label>
                <input class="input" name="shipping_lastname" value="{{ old('shipping_lastname', $order->shipping_lastname) }}" maxlength="32" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Company</label>
                <input class="input" name="shipping_company" value="{{ old('shipping_company', $order->shipping_company) }}" maxlength="40" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Address 1</label>
                <input class="input" name="shipping_address_1" value="{{ old('shipping_address_1', $order->shipping_address_1) }}" maxlength="128" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Address 2</label>
                <input class="input" name="shipping_address_2" value="{{ old('shipping_address_2', $order->shipping_address_2) }}" maxlength="128" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>City</label>
                <input class="input" name="shipping_city" value="{{ old('shipping_city', $order->shipping_city) }}" maxlength="128" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Postcode</label>
                <input class="input" name="shipping_postcode" value="{{ old('shipping_postcode', $order->shipping_postcode) }}" maxlength="10" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Country</label>
                <input class="input" name="shipping_country" value="{{ old('shipping_country', $order->shipping_country) }}" maxlength="128" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Zone / Region</label>
                <input class="input" name="shipping_zone" value="{{ old('shipping_zone', $order->shipping_zone) }}" maxlength="128" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Shipping Method</label>
                <input class="input" name="shipping_method" value="{{ old('shipping_method', $order->shipping_method) }}" style="width:100%; box-sizing:border-box;">
            </div>
        </div>

        {{-- Order Info --}}
        <h3 style="margin:18px 0 10px; font-size:15px; color:#334155; border-bottom:1px solid #e5e7eb; padding-bottom:6px;">Order Info</h3>
        <div class="form-grid">
            <div>
                <label>Total</label>
                <input class="input" name="total" type="number" step="0.01" min="0" value="{{ old('total', $order->total) }}" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label>Tracking Number</label>
                <input class="input" name="tracking_number" value="{{ old('tracking_number', $order->tracking_number) }}" maxlength="32" style="width:100%; box-sizing:border-box;">
            </div>
            <div>
                <label class="required">Order Status</label>
                <select class="input" name="order_status_id" style="width:100%; box-sizing:border-box;">
                    @foreach($statuses as $s)
                        <option value="{{ $s->order_status_id }}" {{ (int)old('order_status_id', $order->order_status_id) === $s->order_status_id ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Status Change Comment</label>
                <input class="input" name="history_comment" value="" placeholder="Optional note for status change" style="width:100%; box-sizing:border-box;">
            </div>
            <div class="full">
                <label>Order Comment</label>
                <textarea class="input" name="comment" style="width:100%; box-sizing:border-box;">{{ old('comment', $order->comment) }}</textarea>
            </div>
        </div>
    </form>

    {{-- Order Products --}}
    @if($products->count())
    <h3 style="margin:24px 0 10px; font-size:15px; color:#334155; border-bottom:1px solid #e5e7eb; padding-bottom:6px;">Products</h3>
    <table>
        <thead>
        <tr>
            <th>Product</th>
            <th>Model</th>
            <th style="text-align:right;">Qty</th>
            <th style="text-align:right;">Unit Price</th>
            <th style="text-align:right;">Total</th>
        </tr>
        </thead>
        <tbody>
        @foreach($products as $p)
            <tr>
                <td>
                    {{ $p->name }}
                    @if($p->options->count())
                        <br>
                        @foreach($p->options as $opt)
                            <small style="color:#64748b;">{{ $opt->name }}: {{ $opt->value }}</small><br>
                        @endforeach
                    @endif
                </td>
                <td>{{ $p->model }}</td>
                <td style="text-align:right;">{{ $p->quantity }}</td>
                <td style="text-align:right;">{{ number_format($p->price, 2) }}</td>
                <td style="text-align:right;">{{ number_format($p->total, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif

    {{-- Order Totals --}}
    @if($orderTotals->count())
    <h3 style="margin:24px 0 10px; font-size:15px; color:#334155; border-bottom:1px solid #e5e7eb; padding-bottom:6px;">Totals</h3>
    <table>
        <tbody>
        @foreach($orderTotals as $ot)
            <tr>
                <td>{{ $ot->title }}</td>
                <td style="text-align:right; width:140px;">{{ number_format($ot->value, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif

    {{-- Order History --}}
    <h3 style="margin:24px 0 10px; font-size:15px; color:#334155; border-bottom:1px solid #e5e7eb; padding-bottom:6px;">Order History</h3>
    @if($history->count())
    <table>
        <thead>
        <tr>
            <th>Date</th>
            <th>Status</th>
            <th>Comment</th>
        </tr>
        </thead>
        <tbody>
        @foreach($history as $h)
            <tr>
                <td style="white-space:nowrap;">{{ $h->date_added }}</td>
                <td>{{ $h->status->name ?? '-' }}</td>
                <td>{{ $h->comment }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @else
        <p style="color:#94a3b8;">No history records.</p>
    @endif

    {{-- Delete --}}
    <form id="delete-order-form" method="POST" action="{{ route('orders.destroy', $order->order_id) }}" style="display:none;">
        @csrf
        @method('DELETE')
    </form>

    <div style="margin-top:20px; display:flex; justify-content:flex-start;">
        <button class="btn danger" type="button"
            onclick="if(confirm('Delete this order and its history?')) document.getElementById('delete-order-form').submit();">
            Delete Order
        </button>
    </div>
</div>
@endsection
