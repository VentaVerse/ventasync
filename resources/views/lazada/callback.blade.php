@extends('layouts.app')

@section('content')
<div class="page-header">
    <h1>Lazada Callback</h1>
    <div class="actions">
        <a class="btn" href="{{ route('lazada.index') }}">Back to Lazada</a>
    </div>
</div>

<div class="card">
    <h2>Returned Parameters</h2>

    @if(!empty($message))
        <div class="notice">{{ $message }}</div>
    @endif

    <div class="grid">
        <div class="field">
            <label>code</label>
            <input class="input" readonly value="{{ $code ?? '' }}">
        </div>

        <div class="field">
            <label>state</label>
            <input class="input" readonly value="{{ $state ?? '' }}">
        </div>

        <div class="field">
            <label>state check</label>
            <input class="input" readonly value="{{ isset($state_ok) && $state_ok ? 'OK' : 'MISMATCH' }}">
        </div>

        <div class="field">
            <label>saved to DB</label>
            <input class="input" readonly value="{{ isset($saved) && $saved ? 'YES' : 'NO' }}">
        </div>
    </div>

    @if(!empty($save_error))
        <div class="notice">
            Save error: {{ $save_error }}<br>
            If this looks like an SQL error about a missing column, run: <b>php artisan migrate --force</b>
        </div>
    @endif
</div>

<div class="card">
    <h2>Token Exchange Result</h2>

    @if(is_array($token_result))
        <pre class="pre">{{ json_encode($token_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    @else
        <div class="muted">No token exchange attempted (missing code, state mismatch, or missing settings).</div>
    @endif
</div>

<div class="card">
    <h2>Raw Query</h2>
    <pre class="pre">{{ json_encode($query ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
</div>

<style>
    .card { background:#fff; border-radius:16px; padding:16px; margin-bottom:16px; box-shadow:0 1px 2px rgba(0,0,0,.06); }
    .page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; gap:10px; flex-wrap:wrap; }
    .notice { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; padding:10px 12px; border-radius:12px; margin-bottom:12px; }
    .muted { color:#64748b; }
    .grid { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:12px; }
    @media(max-width: 900px){ .grid{ grid-template-columns: 1fr; } }
    .field label { font-weight:600; display:block; margin-bottom:6px; }
    .actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .btn { border:1px solid #e2e8f0; background:#fff; color:#0f172a; padding:10px 14px; border-radius:10px; cursor:pointer; text-decoration:none; }
    .input { padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 10px; width:100%; }
    .pre { background:#0b1020; color:#e2e8f0; padding:12px; border-radius:12px; overflow:auto; font-size:12px; }
</style>
@endsection
