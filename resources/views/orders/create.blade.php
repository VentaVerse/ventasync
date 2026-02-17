@extends('layouts.app')
@section('breadcrumb', 'Sales / Orders / Add')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Add Order</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('orders.index') }}">Back</a>
            <button class="btn" type="submit" form="order-form">Save</button>
        </div>
    </div>

    <form id="order-form" method="POST" action="{{ route('orders.store') }}">
        @csrf

        <h3 class="section-title">Customer Details</h3>
        <div class="form-grid">
            <div>
                <label class="required">First Name</label>
                <input class="input" name="firstname" value="{{ old('firstname') }}" required maxlength="32">
            </div>
            <div>
                <label>Last Name</label>
                <input class="input" name="lastname" value="{{ old('lastname') }}" maxlength="32">
            </div>
            <div>
                <label>Email</label>
                <input class="input" name="email" value="{{ old('email') }}" maxlength="96">
            </div>
            <div>
                <label>Telephone</label>
                <input class="input" name="telephone" value="{{ old('telephone') }}" maxlength="32">
            </div>
        </div>

        <h3 class="section-title">Payment Details</h3>
        <div class="form-grid">
            <div>
                <label>First Name</label>
                <input class="input" name="payment_firstname" value="{{ old('payment_firstname') }}" maxlength="32">
            </div>
            <div>
                <label>Last Name</label>
                <input class="input" name="payment_lastname" value="{{ old('payment_lastname') }}" maxlength="32">
            </div>
            <div>
                <label>Company</label>
                <input class="input" name="payment_company" value="{{ old('payment_company') }}" maxlength="40">
            </div>
            <div>
                <label>Address 1</label>
                <input class="input" name="payment_address_1" value="{{ old('payment_address_1') }}" maxlength="128">
            </div>
            <div>
                <label>Address 2</label>
                <input class="input" name="payment_address_2" value="{{ old('payment_address_2') }}" maxlength="128">
            </div>
            <div>
                <label>City</label>
                <input class="input" name="payment_city" value="{{ old('payment_city') }}" maxlength="128">
            </div>
            <div>
                <label>Postcode</label>
                <input class="input" name="payment_postcode" value="{{ old('payment_postcode') }}" maxlength="10">
            </div>
            <div>
                <label>Country</label>
                <input class="input" name="payment_country" value="{{ old('payment_country') }}" maxlength="128">
            </div>
            <div>
                <label>Zone / Region</label>
                <input class="input" name="payment_zone" value="{{ old('payment_zone') }}" maxlength="128">
            </div>
            <div>
                <label>Payment Method</label>
                <input class="input" name="payment_method" value="{{ old('payment_method') }}" maxlength="128">
            </div>
        </div>

        <h3 class="section-title">Shipping Details</h3>
        <div class="form-grid">
            <div>
                <label>First Name</label>
                <input class="input" name="shipping_firstname" value="{{ old('shipping_firstname') }}" maxlength="32">
            </div>
            <div>
                <label>Last Name</label>
                <input class="input" name="shipping_lastname" value="{{ old('shipping_lastname') }}" maxlength="32">
            </div>
            <div>
                <label>Company</label>
                <input class="input" name="shipping_company" value="{{ old('shipping_company') }}" maxlength="40">
            </div>
            <div>
                <label>Address 1</label>
                <input class="input" name="shipping_address_1" value="{{ old('shipping_address_1') }}" maxlength="128">
            </div>
            <div>
                <label>Address 2</label>
                <input class="input" name="shipping_address_2" value="{{ old('shipping_address_2') }}" maxlength="128">
            </div>
            <div>
                <label>City</label>
                <input class="input" name="shipping_city" value="{{ old('shipping_city') }}" maxlength="128">
            </div>
            <div>
                <label>Postcode</label>
                <input class="input" name="shipping_postcode" value="{{ old('shipping_postcode') }}" maxlength="10">
            </div>
            <div>
                <label>Country</label>
                <input class="input" name="shipping_country" value="{{ old('shipping_country') }}" maxlength="128">
            </div>
            <div>
                <label>Zone / Region</label>
                <input class="input" name="shipping_zone" value="{{ old('shipping_zone') }}" maxlength="128">
            </div>
            <div>
                <label>Shipping Method</label>
                <input class="input" name="shipping_method" value="{{ old('shipping_method') }}">
            </div>
        </div>

        <h3 class="section-title">Order Info</h3>
        <div class="form-grid">
            <div>
                <label>Total</label>
                <input class="input" name="total" type="number" step="0.01" min="0" value="{{ old('total', '0.00') }}">
            </div>
            <div>
                <label>Currency Code</label>
                <input class="input" name="currency_code" value="{{ old('currency_code', 'PHP') }}" maxlength="3">
            </div>
            <div>
                <label class="required">Order Status</label>
                <select class="input" name="order_status_id">
                    @foreach($statuses as $s)
                        <option value="{{ $s->order_status_id }}" {{ (int)old('order_status_id', 1) === $s->order_status_id ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="full">
                <label>Comment</label>
                <textarea class="input" name="comment">{{ old('comment') }}</textarea>
            </div>
        </div>
    </form>
</div>
@endsection