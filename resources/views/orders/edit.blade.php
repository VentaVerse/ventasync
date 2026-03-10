@extends('layouts.app')
@section('breadcrumb', 'Sales / Orders / Edit')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit Order #{{ $order->order_id }}</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('orders.index') }}">Back</a>
            <button class="btn" type="submit" form="order-form">Update</button>
        </div>
    </div>

    <form id="order-form" method="POST" action="{{ route('orders.update', $order->order_id) }}">
        @csrf
        @method('PUT')

        <h3 class="section-title">Customer Details</h3>
        <div class="form-grid">
            <div>
                <label class="required">First Name</label>
                <input class="input" name="firstname" value="{{ old('firstname', $order->firstname) }}" maxlength="32">
            </div>
            <div>
                <label>Last Name</label>
                <input class="input" name="lastname" value="{{ old('lastname', $order->lastname) }}" maxlength="32">
            </div>
            <div>
                <label>Email</label>
                <input class="input" name="email" value="{{ old('email', $order->email) }}" maxlength="96">
            </div>
            <div>
                <label>Telephone</label>
                <input class="input" name="telephone" value="{{ old('telephone', $order->telephone) }}" maxlength="32">
            </div>
        </div>

        <h3 class="section-title">Payment Details</h3>
        <div class="form-grid">
            <div>
                <label>First Name</label>
                <input class="input" name="payment_firstname" value="{{ old('payment_firstname', $order->payment_firstname) }}" maxlength="32">
            </div>
            <div>
                <label>Last Name</label>
                <input class="input" name="payment_lastname" value="{{ old('payment_lastname', $order->payment_lastname) }}" maxlength="32">
            </div>
            <div>
                <label>Company</label>
                <input class="input" name="payment_company" value="{{ old('payment_company', $order->payment_company) }}" maxlength="40">
            </div>
            <div>
                <label>Address 1</label>
                <input class="input" name="payment_address_1" value="{{ old('payment_address_1', $order->payment_address_1) }}" maxlength="128">
            </div>
            <div>
                <label>Address 2</label>
                <input class="input" name="payment_address_2" value="{{ old('payment_address_2', $order->payment_address_2) }}" maxlength="128">
            </div>
            <div>
                <label>City</label>
                <input class="input" name="payment_city" value="{{ old('payment_city', $order->payment_city) }}" maxlength="128">
            </div>
            <div>
                <label>Postcode</label>
                <input class="input" name="payment_postcode" value="{{ old('payment_postcode', $order->payment_postcode) }}" maxlength="10">
            </div>
            <div>
                <label>Country</label>
                <input class="input" name="payment_country" value="{{ old('payment_country', $order->payment_country) }}" maxlength="128">
            </div>
            <div>
                <label>Zone / Region</label>
                <input class="input" name="payment_zone" value="{{ old('payment_zone', $order->payment_zone) }}" maxlength="128">
            </div>
            <div>
                <label>Payment Method</label>
                <input class="input" name="payment_method" value="{{ old('payment_method', $order->payment_method) }}" maxlength="128">
            </div>
        </div>

        <h3 class="section-title">Shipping Details</h3>
        <div class="form-grid">
            <div>
                <label>First Name</label>
                <input class="input" name="shipping_firstname" value="{{ old('shipping_firstname', $order->shipping_firstname) }}" maxlength="32">
            </div>
            <div>
                <label>Last Name</label>
                <input class="input" name="shipping_lastname" value="{{ old('shipping_lastname', $order->shipping_lastname) }}" maxlength="32">
            </div>
            <div>
                <label>Company</label>
                <input class="input" name="shipping_company" value="{{ old('shipping_company', $order->shipping_company) }}" maxlength="40">
            </div>
            <div>
                <label>Address 1</label>
                <input class="input" name="shipping_address_1" value="{{ old('shipping_address_1', $order->shipping_address_1) }}" maxlength="128">
            </div>
            <div>
                <label>Address 2</label>
                <input class="input" name="shipping_address_2" value="{{ old('shipping_address_2', $order->shipping_address_2) }}" maxlength="128">
            </div>
            <div>
                <label>City</label>
                <input class="input" name="shipping_city" value="{{ old('shipping_city', $order->shipping_city) }}" maxlength="128">
            </div>
            <div>
                <label>Postcode</label>
                <input class="input" name="shipping_postcode" value="{{ old('shipping_postcode', $order->shipping_postcode) }}" maxlength="10">
            </div>
            <div>
                <label>Country</label>
                <input class="input" name="shipping_country" value="{{ old('shipping_country', $order->shipping_country) }}" maxlength="128">
            </div>
            <div>
                <label>Zone / Region</label>
                <input class="input" name="shipping_zone" value="{{ old('shipping_zone', $order->shipping_zone) }}" maxlength="128">
            </div>
            <div>
                <label>Shipping Method</label>
                <input class="input" name="shipping_method" value="{{ old('shipping_method', $order->shipping_method) }}">
            </div>
        </div>

        <h3 class="section-title">Order Info</h3>
        <div class="form-grid">
            <div>
                <label>Total</label>
                <input class="input" name="total" type="number" step="0.01" min="0" value="{{ old('total', $order->total) }}">
            </div>
            <div>
                <label>Tracking Number</label>
                <input class="input" name="tracking_number" value="{{ old('tracking_number', $order->tracking_number) }}" maxlength="32">
            </div>
            <div>
                <label class="required">Order Status</label>
                <select class="input" name="order_status_id">
                    @foreach($statuses as $s)
                        <option value="{{ $s->order_status_id }}" {{ (int)old('order_status_id', $order->order_status_id) === $s->order_status_id ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Status Change Comment</label>
                <input class="input" name="history_comment" value="" placeholder="Optional note for status change">
            </div>
            <div class="full">
                <label>Order Comment</label>
                <textarea class="input" name="comment">{{ old('comment', $order->comment) }}</textarea>
            </div>
        </div>
    </form>

    {{-- Order Products --}}
    @if($products->count())
    <h3 class="section-title">Products</h3>
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Product</th>
            <th>Model</th>
            <th class="text-right">Qty</th>
            <th class="text-right">Unit Price</th>
            <th class="text-right">Total</th>
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
                            <small class="text-secondary">{{ $opt->name }}: {{ $opt->value }}</small><br>
                        @endforeach
                    @endif
                </td>
                <td>{{ $p->model }}</td>
                <td class="text-right">{{ $p->quantity }}</td>
                <td class="text-right">{{ number_format($p->price, 2) }}</td>
                <td class="text-right">{{ number_format($p->total, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    @endif

    {{-- Order Totals --}}
    @if($orderTotals->count())
    <h3 class="section-title">Totals</h3>
    <table>
        <tbody>
        @foreach($orderTotals as $ot)
            <tr>
                <td>{{ $ot->title }}</td>
                <td class="text-right" style="width:140px;">{{ number_format($ot->value, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif

    {{-- Order History --}}
    <h3 class="section-title">Order History</h3>
    @if($history->count())
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Date</th>
            <th>User</th>
            <th>Status</th>
            <th>Comment</th>
        </tr>
        </thead>
        <tbody>
        @foreach($history as $h)
            <tr>
                <td class="text-nowrap">{{ $h->date_added }}</td>
                <td>{{ $h->user_name ?? '-' }}</td>
                <td>{{ $h->status->name ?? '-' }}</td>
                <td>{{ $h->comment }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    @else
        <p class="text-muted">No history records.</p>
    @endif

    {{-- Delete --}}
    <form id="delete-order-form" method="POST" action="{{ route('orders.destroy', $order->order_id) }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>

    <div class="mt-20 d-flex">
        <button class="btn danger" type="button"
            data-confirm="Delete this order and its history?" data-confirm-submit="delete-order-form">
            Delete Order
        </button>
    </div>
</div>
@endsection