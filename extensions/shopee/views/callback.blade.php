@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Shopee / Callback')

@section('content')
<div class="card">
    <h3 class="section-title mt-0">Shopee Callback</h3>
    <p class="text-secondary">Copy the values below into the Shopee page to exchange for tokens.</p>

    <div class="form-grid mt-12">
        <div>
            <label>code</label>
            <input class="input" value="{{ $code }}" readonly>
        </div>

        <div>
            <label>shop_id</label>
            <input class="input" value="{{ $shop_id }}" readonly>
        </div>
    </div>

    <div class="d-flex gap-8 flex-wrap mt-12">
        <a class="btn" href="{{ route('ext.shopee.index') }}">Back to Shopee</a>
    </div>
</div>
@endsection
