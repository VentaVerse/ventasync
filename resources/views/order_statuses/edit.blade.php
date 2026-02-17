@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit Order Status #{{ $orderStatus->order_status_id }}</h2>
        <div style="display:flex; gap:10px; align-items:center;">
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
                <input class="input" name="name" value="{{ old('name', $orderStatus->name) }}" required maxlength="32">
            </div>
            <div class="full">
                <label>
                    <input type="checkbox" name="subtract_stock" value="1" {{ old('subtract_stock', $orderStatus->subtract_stock) ? 'checked' : '' }}>
                    Subtract Stock
                </label>
                <small style="display:block; color:#888;">When enabled, product stock will be deducted when an order enters this status.</small>
            </div>
        </div>
    </form>

    <form id="delete-os-form" method="POST" action="{{ route('order_statuses.destroy', $orderStatus->order_status_id) }}" style="display:none;">
        @csrf
        @method('DELETE')
    </form>

    <div style="margin-top:16px; display:flex; justify-content:flex-start;">
        <button class="btn danger" type="button"
            onclick="if(confirm('Delete this order status?')) document.getElementById('delete-os-form').submit();">
            Delete
        </button>
    </div>
</div>
@endsection
