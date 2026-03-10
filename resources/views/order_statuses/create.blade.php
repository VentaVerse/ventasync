@extends('layouts.app')
@section('breadcrumb', 'Sales / Add Order Status')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Add Order Status</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('order_statuses.index') }}">Back</a>
            <button class="btn" type="submit" form="os-form">Save</button>
        </div>
    </div>

    <form id="os-form" method="POST" action="{{ route('order_statuses.store') }}">
        @csrf

        <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name') }}" maxlength="32">
            </div>
            <div class="full">
                <label>
                    <input type="checkbox" name="subtract_stock" value="1" {{ old('subtract_stock') ? 'checked' : '' }}>
                    Subtract Stock
                </label>
                <small class="hint">When enabled, product stock will be deducted when an order enters this status.</small>
            </div>
            <div class="full">
                <label>
                    <input type="checkbox" name="add_revenue" value="1" {{ old('add_revenue') ? 'checked' : '' }}>
                    Add Revenue
                </label>
                <small class="hint">When enabled, orders with this status will be included in revenue calculations.</small>
            </div>
        </div>
    </form>
</div>
@endsection
