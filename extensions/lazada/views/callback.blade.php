@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Lazada / Callback')

@section('content')
<div class="page-header">
    <h1>Lazada Callback</h1>
    <div class="page-header-actions">
        <a class="btn" href="{{ route('ext.lazada.index') }}">Back to Lazada</a>
    </div>
</div>

<div class="card">
    <h2>Returned Parameters</h2>

    @if(!empty($message))
        <div class="notice">{{ $message }}</div>
    @endif

    <div class="form-grid">
        <div>
            <label>code</label>
            <input class="input" readonly value="{{ $code ?? '' }}">
        </div>

        <div>
            <label>state</label>
            <input class="input" readonly value="{{ $state ?? '' }}">
        </div>

        <div>
            <label>state check</label>
            <input class="input" readonly value="{{ isset($state_ok) && $state_ok ? 'OK' : 'MISMATCH' }}">
        </div>

        <div>
            <label>saved to DB</label>
            <input class="input" readonly value="{{ isset($saved) && $saved ? 'YES' : 'NO' }}">
        </div>
    </div>

    @if(!empty($save_error))
        <div class="alert warning mt-12">
            Save error: {{ $save_error }}<br>
            If this looks like an SQL error about a missing column, run: <b>php artisan migrate --force</b>
        </div>
    @endif
</div>

<div class="card mt-16">
    <h2>Token Exchange Result</h2>

    @if(is_array($token_result))
        <pre class="pre">{{ json_encode($token_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    @else
        <div class="text-muted">No token exchange attempted (missing code, state mismatch, or missing settings).</div>
    @endif
</div>

<div class="card mt-16">
    <h2>Raw Query</h2>
    <pre class="pre">{{ json_encode($query ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
</div>
@endsection
