@extends('layouts.app')

@section('content')
<div class="page-header">
    <h1>Shopee</h1>
</div>

<div class="card">
    <h2>Connection Settings</h2>

    <form method="POST" action="{{ route('shopee.save') }}">
        @csrf

        <div class="grid-2">
            <div class="field">
                <label>Account Mode</label>
                <select name="mode" class="input">
                    <option value="sandbox" {{ ($setting->mode ?? 'sandbox') === 'sandbox' ? 'selected' : '' }}>Sandbox</option>
                    <option value="live" {{ ($setting->mode ?? '') === 'live' ? 'selected' : '' }}>Live</option>
                </select>
            </div>

            <div class="field">
                <label>Redirect URI</label>
                <input class="input" name="redirect_uri" value="{{ old('redirect_uri', $setting->redirect_uri ?? $defaultRedirect) }}">
                <div class="help">Use this in Shopee Open Platform app settings.</div>
            </div>

            <div class="field">
                <label>Partner ID</label>
                <input class="input" name="partner_id" value="{{ old('partner_id', $setting->partner_id ?? '') }}">
            </div>

            <div class="field">
                <label>Partner Key</label>
                <input class="input" name="partner_key" value="{{ old('partner_key', $setting->partner_key ?? '') }}">
                <div class="help">Stored encrypted.</div>
            </div>

            <div class="field">
                <label>Shop ID</label>
                <input class="input" name="shop_id" value="{{ old('shop_id', $setting->shop_id ?? '') }}">
            </div>

            <div class="field">
                <label>Access Token</label>
                <input class="input" name="access_token" value="{{ old('access_token', $setting->access_token ?? '') }}">
                <div class="help">Stored encrypted.</div>
            </div>            <div class="field">
                <label>Region (optional)</label>
                <input class="input" name="region" value="{{ old('region', $setting->region ?? '') }}">
            </div>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Save Settings</button>
        </div>
    </form>
</div>

<div class="card">
    <h2>REST Proof of Concept</h2>

    <div class="actions">
        <form method="GET" action="{{ route('shopee.authorize') }}" style="display:inline">
            @csrf
            <button class="btn" type="submit">Authorize with Shopee</button>
        </form>    </div>

    <div class="divider"></div>

    <form method="POST" action="{{ route('shopee.token_get') }}">
        @csrf
        <div class="field">
            <label>Auth Code (from redirect callback)</label>
            <input class="input" name="code" value="{{ old('code', '') }}" placeholder="Paste code=... here">
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Exchange Code → Token</button>
        </div>
    </form>

    <div class="divider"></div>

    <h3>Generic API Caller</h3>
    <form method="POST" action="{{ route('shopee.call_api') }}">
        @csrf
        <div class="grid-2">
            <div class="field">
                <label>Method</label>
                <select class="input" name="method">
                    <option value="GET">GET</option>
                    <option value="POST">POST</option>
                </select>
            </div>

            <div class="field">
                <label>API Path</label>
                <input class="input" name="path" value="{{ old('path', '/api/v2/shop/get_shop_info') }}">
            </div>

            <div class="field">
                <label>Include Access Token in signature/query</label>
                <select class="input" name="use_access_token">
                    <option value="1" selected>Yes</option>
                    <option value="0">No</option>
                </select>
            </div>

            <div class="field">
                <label>Include Shop ID in signature/query</label>
                <select class="input" name="use_shop_id">
                    <option value="1" selected>Yes</option>
                    <option value="0">No</option>
                </select>
            </div>
        </div>

        <div class="field">
            <label>Extra Query JSON (optional)</label>
            <textarea class="input" name="query_json" rows="3" placeholder='{"page_size": 10}'>{{ old('query_json','') }}</textarea>
        </div>

        <div class="field">
            <label>Body JSON (optional, for POST)</label>
            <textarea class="input" name="body_json" rows="4" placeholder='{"foo":"bar"}'>{{ old('body_json','') }}</textarea>
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

        <pre class="pre">{{ json_encode($result['data'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>    </div>
@endif

<style>
    .card { background:#fff; border-radius:16px; padding:16px; margin-bottom:16px; box-shadow:0 1px 2px rgba(0,0,0,.06); }
    .page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .grid-2 { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .field label { font-weight:600; display:block; margin-bottom:6px; }
    .help { font-size:12px; color:#64748b; margin-top:4px; }
    .actions { margin-top:12px; display:flex; gap:8px; flex-wrap:wrap; }
    .btn { border:1px solid #e2e8f0; background:#fff; padding:10px 14px; border-radius:10px; cursor:pointer; }
    .btn-primary { background:#2563eb; border-color:#2563eb; color:#fff; }
    .divider { height:1px; background:#e2e8f0; margin:14px 0; }
    .notice { padding:10px 12px; border-radius:12px; margin-bottom:10px; }
    .notice.ok { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
    .notice.bad { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
    .pre { background:#0b1220; color:#e2e8f0; padding:12px; border-radius:12px; overflow:auto; }
</style>
@endsection
