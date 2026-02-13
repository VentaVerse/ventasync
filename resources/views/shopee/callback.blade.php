@extends('layouts.app')

@section('content')
<div class="card">
    <h1>Shopee Callback</h1>
    <p>Copy the values below into the Shopee page to exchange for tokens.</p>

    <div class="grid-2">
        <div class="field">
            <label>code</label>
            <input class="input" value="{{ $code }}" readonly>
        </div>

        <div class="field">
            <label>shop_id</label>
            <input class="input" value="{{ $shop_id }}" readonly>
        </div>
    </div>

    <div class="actions">
        <a class="btn btn-primary" href="{{ route('shopee.index') }}">Back to Shopee</a>
    </div>
</div>

<style>
    .card { background:#fff; border-radius:16px; padding:16px; margin-bottom:16px; box-shadow:0 1px 2px rgba(0,0,0,.06); }
    .grid-2 { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 12px; }
    .field label { font-weight:600; display:block; margin-bottom:6px; }
    .actions { margin-top:12px; display:flex; gap:8px; }
    .btn { border:1px solid #e2e8f0; background:#fff; padding:10px 14px; border-radius:10px; cursor:pointer; text-decoration:none; display:inline-block; }
    .btn-primary { background:#2563eb; border-color:#2563eb; color:#fff; }
</style>
@endsection
