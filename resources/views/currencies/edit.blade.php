@extends('layouts.app')
@section('breadcrumb', 'Settings / Currencies / Edit')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit Currency — {{ $currency->code }}</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('currencies.index') }}">Back</a>
            <button class="btn" type="submit" form="currency-form">Update</button>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert danger">{{ $errors->first() }}</div>
    @endif

    <form id="currency-form" method="POST" action="{{ route('currencies.update', $currency->id) }}">
        @csrf
        @method('PUT')

        <div class="form-grid">
            <div>
                <label class="required">Code</label>
                <input class="input" name="code" value="{{ old('code', $currency->code) }}" maxlength="3" style="text-transform:uppercase;">
            </div>

            <div>
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name', $currency->name) }}">
            </div>

            <div>
                <label class="required">Symbol</label>
                <input class="input" name="symbol" value="{{ old('symbol', $currency->symbol) }}" maxlength="8">
            </div>

            <div>
                <label class="required">Exchange Rate</label>
                <input class="input" name="exchange_rate" value="{{ old('exchange_rate', $currency->exchange_rate) }}">
                <div class="hint">Rate relative to base currency (PHP)</div>
            </div>

            <div>
                <label>Default Currency</label>
                <select class="input" name="is_default">
                    <option value="0" {{ old('is_default', $currency->is_default) == false ? 'selected' : '' }}>No</option>
                    <option value="1" {{ old('is_default', $currency->is_default) == true ? 'selected' : '' }}>Yes</option>
                </select>
            </div>

            <div>
                <label class="required">Status</label>
                <select class="input" name="status">
                    <option value="1" {{ old('status', (string) $currency->status) == '1' ? 'selected' : '' }}>Active</option>
                    <option value="0" {{ old('status', (string) $currency->status) == '0' ? 'selected' : '' }}>Inactive</option>
                </select>
            </div>
        </div>
    </form>

    @if(!$currency->is_default)
    <form id="delete-currency-form" method="POST" action="{{ route('currencies.destroy', $currency->id) }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>

    <div class="mt-16 d-flex justify-start">
        <button class="btn danger" type="button"
            data-confirm="Delete this currency?" data-confirm-submit="delete-currency-form">
            Delete
        </button>
    </div>
    @endif
</div>
@endsection
