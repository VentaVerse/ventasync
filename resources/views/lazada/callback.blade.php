@extends('layouts.app')

@section('content')
<div class="page-header">
    <h1>Lazada Callback</h1>
</div>

<div class="card">
    <h2>Returned Parameters</h2>
    <div class="field">
        <label>code</label>
        <input class="input" readonly value="{{ $code ?? '' }}">
    </div>
    <div class="actions">
        <a class="btn" href="{{ route('lazada.index') }}">Back to Lazada</a>
    </div>
</div>

<style>
    .card { background:#fff; border-radius:16px; padding:16px; margin-bottom:16px; box-shadow:0 1px 2px rgba(0,0,0,.06); }
    .page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .field label { font-weight:600; display:block; margin-bottom:6px; }
    .actions { margin-top:12px; display:flex; gap:8px; flex-wrap:wrap; }
    .btn { border:1px solid #e2e8f0; background:#fff; color:#0f172a; padding:10px 14px; border-radius:10px; cursor:pointer; text-decoration:none; }
    .input { padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 10px; width:100%; }
</style>
@endsection
