@extends('layouts.app')
@section('breadcrumb', 'Settings / Currencies / Add')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Add Currency</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('currencies.index') }}">Back</a>
            <button class="btn" type="submit" form="currency-form">Save</button>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert danger">{{ $errors->first() }}</div>
    @endif

    <form id="currency-form" method="POST" action="{{ route('currencies.store') }}">
        @csrf

        <div class="form-grid">
            <div>
                <label class="required">Code</label>
                <input class="input" name="code" value="{{ old('code') }}" maxlength="3" placeholder="e.g. USD" style="text-transform:uppercase;">
            </div>

            <div>
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name') }}" placeholder="e.g. US Dollar">
            </div>

            <div>
                <label class="required">Symbol</label>
                <input class="input" name="symbol" value="{{ old('symbol') }}" maxlength="8" placeholder="e.g. $">
            </div>

            <div>
                <label class="required">Exchange Rate</label>
                <input class="input" name="exchange_rate" value="{{ old('exchange_rate', '1.00000000') }}">
                <div class="hint">Rate relative to base currency (PHP)</div>
            </div>

            <div>
                <label>Default Currency</label>
                <select class="input" name="is_default">
                    <option value="0" {{ old('is_default') == '0' ? 'selected' : '' }}>No</option>
                    <option value="1" {{ old('is_default') == '1' ? 'selected' : '' }}>Yes</option>
                </select>
            </div>

            <div>
                <label class="required">Status</label>
                <select class="input" name="status">
                    <option value="1" {{ old('status', '1') == '1' ? 'selected' : '' }}>Active</option>
                    <option value="0" {{ old('status') == '0' ? 'selected' : '' }}>Inactive</option>
                </select>
            </div>
        </div>
    </form>
</div>
@endsection
