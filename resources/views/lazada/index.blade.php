@extends('layouts.app')

@section('content')
<div class="page-header">
    <h1>Lazada</h1>
</div>

<div class="card">
    <h2>Connection Settings</h2>

    <form method="POST" action="{{ route('lazada.save') }}">
        @csrf

        <div class="grid-2">
            <div class="field">
                <label>Region</label>
                <select name="region" class="input">
                    <option value="">(not set)</option>
                    <option value="ph" {{ ($setting->region ?? '') === 'ph' ? 'selected' : '' }}>Philippines (ph)</option>
                    <option value="sg" {{ ($setting->region ?? '') === 'sg' ? 'selected' : '' }}>Singapore (sg)</option>
                    <option value="my" {{ ($setting->region ?? '') === 'my' ? 'selected' : '' }}>Malaysia (my)</option>
                    <option value="id" {{ ($setting->region ?? '') === 'id' ? 'selected' : '' }}>Indonesia (id)</option>
                    <option value="th" {{ ($setting->region ?? '') === 'th' ? 'selected' : '' }}>Thailand (th)</option>
                    <option value="vn" {{ ($setting->region ?? '') === 'vn' ? 'selected' : '' }}>Vietnam (vn)</option>
                </select>
            </div>

            <div class="field">
                <label>Redirect URI</label>
                <input class="input" name="redirect_uri" value="{{ old('redirect_uri', $setting->redirect_uri ?? $defaultRedirect) }}">
                <div class="help">Use this as the app callback URL in Lazada Open Platform.</div>
            </div>

            <div class="field">
                <label>App Key</label>
                <input class="input" name="app_key" value="{{ old('app_key', $setting->app_key ?? '') }}">
            </div>

            <div class="field">
                <label>App Secret</label>
                <input class="input" name="app_secret" value="{{ old('app_secret', $setting->app_secret ?? '') }}">
                <div class="help">Stored encrypted.</div>
            </div>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Save Settings</button>
        </div>
    </form>
</div>

<div class="card">
    <h2>OAuth Proof of Concept</h2>

    <div class="actions">
        <form method="GET" action="{{ route('lazada.authorize') }}" style="display:inline">
            @csrf
            <button class="btn" type="submit">Authorize with Lazada</button>
        </form>
    </div>

    <div class="divider"></div>

    <form method="POST" action="{{ route('lazada.token_create') }}">
        @csrf
        <div class="field">
            <label>Auth Code (from callback)</label>
            <input class="input" name="code" value="{{ old('code', '') }}" placeholder="Paste code=... here">
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Exchange Code → Token</button>
        </div>
    </form>

    <div class="divider"></div>

    <div class="actions">
        <form method="POST" action="{{ route('lazada.token_refresh') }}" style="display:inline">
            @csrf
            <button class="btn" type="submit">Refresh Access Token</button>
        </form>
    </div>

    <div class="divider"></div>

    <h3>Test API Call</h3>
    <form method="POST" action="{{ route('lazada.call_api') }}">
        @csrf
        <div class="field">
            <label>API Path</label>
            <input class="input" name="api_path" value="{{ old('api_path', '/seller/get') }}">
            <div class="help">Example: /seller/get</div>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Call API</button>
        </div>
    </form>
</div>

@if($result)
    <div class="card">
        <h2>Last Result</h2>

        @php
            $ok = (bool)($result['ok'] ?? false);
        @endphp

        <div class="{{ $ok ? 'notice ok' : 'notice bad' }}">
            <strong>{{ $result['title'] ?? 'Result' }}</strong>
        </div>

        <pre class="pre">{{ json_encode($result['data'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
@endif

<style>
    .card { background:#fff; border-radius:16px; padding:16px; margin-bottom:16px; box-shadow:0 1px 2px rgba(0,0,0,.06); }
    .page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .grid-2 { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .field label { font-weight:600; display:block; margin-bottom:6px; }
    .help { font-size:12px; color:#64748b; margin-top:4px; }
    .actions { margin-top:12px; display:flex; gap:8px; flex-wrap:wrap; }
    .btn { border:1px solid #e2e8f0; background:#fff; color:#0f172a; padding:10px 14px; border-radius:10px; cursor:pointer; }
    .btn-primary { background:#2563eb; border-color:#2563eb; color:#fff; }
    .divider { height:1px; background:#e2e8f0; margin:14px 0; }
    .notice { padding:10px 12px; border-radius:12px; margin-bottom:10px; }
    .notice.ok { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
    .notice.bad { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
    .pre { background:#0b1220; color:#e2e8f0; padding:12px; border-radius:12px; overflow:auto; }
</style>
@endsection
