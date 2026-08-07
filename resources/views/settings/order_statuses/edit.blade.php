@extends('layouts.app')
@section('breadcrumb', 'Sales / Edit Order Status')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit Order Status #{{ $orderStatus->order_status_id }}</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('order_statuses.index') }}">Back</a>
            <button class="btn" type="submit" form="os-form">Update</button>
        </div>
    </div>

    <form id="os-form" method="POST" action="{{ route('order_statuses.update', $orderStatus->order_status_id) }}">
        @csrf
        @method('PUT')

        <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name', $orderStatus->name) }}" maxlength="32">
            </div>
            <div class="full">
                <label>
                    <input type="checkbox" name="subtract_stock" value="1" {{ old('subtract_stock', $orderStatus->subtract_stock) ? 'checked' : '' }}>
                    Subtract Stock
                </label>
                <small class="hint">When enabled, product stock will be deducted when an order enters this status.</small>
            </div>
            <div class="full">
                <label>
                    <input type="checkbox" name="add_revenue" value="1" {{ old('add_revenue', $orderStatus->add_revenue) ? 'checked' : '' }}>
                    Add Revenue
                </label>
                <small class="hint">When enabled, orders with this status will be included in revenue calculations.</small>
            </div>
        </div>
    </form>

    <form id="delete-os-form" method="POST" action="{{ route('order_statuses.destroy', $orderStatus->order_status_id) }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>

    <div class="mt-16 d-flex">
        <button class="btn danger" type="button"
            data-confirm="Delete this order status?" data-confirm-submit="delete-os-form">
            Delete
        </button>
    </div>
</div>
@endsection
