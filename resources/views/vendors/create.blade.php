@extends('layouts.app')
@section('breadcrumb', 'Purchasing / Vendors / Add')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Add Vendor</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('vendors.index') }}">Back</a>
            <button class="btn" type="submit" form="vendor-form">Save</button>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert danger">{{ $errors->first() }}</div>
    @endif

    <form id="vendor-form" method="POST" action="{{ route('vendors.store') }}">
        @csrf

        <h3 class="section-title">General</h3>
        <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name') }}" required>
            </div>

            <div>
                <label>Contact Person</label>
                <input class="input" name="contact_person" value="{{ old('contact_person') }}">
            </div>

            <div>
                <label>Email</label>
                <input class="input" type="email" name="email" value="{{ old('email') }}">
            </div>

            <div>
                <label>Phone</label>
                <input class="input" name="phone" value="{{ old('phone') }}">
            </div>

            <div>
                <label>Default Currency</label>
                <select class="input" name="currency_code">
                    @foreach($currencies as $c)
                        <option value="{{ $c->code }}" {{ old('currency_code', 'PHP') === $c->code ? 'selected' : '' }}>{{ $c->code }} — {{ $c->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="required">Status</label>
                <select class="input" name="status" required>
                    <option value="1" {{ old('status', '1') == '1' ? 'selected' : '' }}>Active</option>
                    <option value="0" {{ old('status') == '0' ? 'selected' : '' }}>Inactive</option>
                </select>
            </div>

            <div>
                <label>Payment Terms</label>
                <input class="input" name="payment_terms" value="{{ old('payment_terms') }}" placeholder="e.g. Net 30" maxlength="64">
            </div>

            <div>
                <label>Email PO Enabled</label>
                <label style="display:flex; align-items:center; gap:8px; font-weight:normal; cursor:pointer;">
                    <input type="checkbox" name="email_po_enabled" value="1" {{ old('email_po_enabled') ? 'checked' : '' }}>
                    Allow sending POs to this vendor via email
                </label>
            </div>
        </div>

        <h3 class="section-title mt-24">Address</h3>
        <div class="form-grid">
            <div class="full">
                <label>Address Line 1</label>
                <input class="input" name="address_line1" value="{{ old('address_line1') }}">
            </div>

            <div class="full">
                <label>Address Line 2</label>
                <input class="input" name="address_line2" value="{{ old('address_line2') }}">
            </div>

            <div>
                <label>City</label>
                <input class="input" name="city" value="{{ old('city') }}">
            </div>

            <div>
                <label>State / Province</label>
                <input class="input" name="state" value="{{ old('state') }}">
            </div>

            <div>
                <label>Country</label>
                <input class="input" name="country" value="{{ old('country') }}">
            </div>

            <div>
                <label>Postal Code</label>
                <input class="input" name="postal_code" value="{{ old('postal_code') }}">
            </div>
        </div>

        <h3 class="section-title mt-24">Notes</h3>
        <div class="form-grid">
            <div class="full">
                <textarea class="input" name="notes" rows="4">{{ old('notes') }}</textarea>
            </div>
        </div>
    </form>
</div>
@endsection
