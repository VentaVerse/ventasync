@extends('layouts.app')
@section('breadcrumb', 'Marketplace API / Shopee')

@section('content')
<div class="page-header">
    <h2>Shopee</h2>
</div>

<div class="card mb-16">
    <h3 class="section-title mt-0">Connection Settings</h3>

    <form method="POST" action="{{ route('shopee.save') }}">
        @csrf

        <div class="form-grid">
            <div>
                <label>Account Mode</label>
                <select name="mode" class="input">
                    <option value="sandbox" {{ ($setting->mode ?? 'sandbox') === 'sandbox' ? 'selected' : '' }}>Sandbox</option>
                    <option value="live" {{ ($setting->mode ?? '') === 'live' ? 'selected' : '' }}>Live</option>
                </select>
            </div>

            <div>
                <label>Redirect URI</label>
                <input class="input" name="redirect_uri" value="{{ old('redirect_uri', $setting->redirect_uri ?? $defaultRedirect) }}">
                <div class="hint">Use this in Shopee Open Platform app settings.</div>
            </div>

            <div>
                <label>Partner ID</label>
                <input class="input" name="partner_id" value="{{ old('partner_id', $setting->partner_id ?? '') }}">
            </div>

            <div>
                <label>Partner Key</label>
                <input class="input" name="partner_key" value="{{ old('partner_key', $setting->partner_key ?? '') }}">
                <div class="hint">Stored encrypted.</div>
            </div>

            <div>
                <label>Shop ID</label>
                <input class="input" name="shop_id" value="{{ old('shop_id', $setting->shop_id ?? '') }}">
            </div>

            <div>
                <label>Access Token</label>
                <input class="input" name="access_token" value="{{ old('access_token', $setting->access_token ?? '') }}">
                <div class="hint">Stored encrypted.</div>
            </div>

            <div>
                <label>Region (optional)</label>
                <input class="input" name="region" value="{{ old('region', $setting->region ?? '') }}">
            </div>
        </div>

        <div class="d-flex gap-8 flex-wrap mt-12">
            <button class="btn" type="submit">Save Settings</button>
        </div>
    </form>
</div>

<div class="card mb-16">
    <h3 class="section-title mt-0">REST Proof of Concept</h3>

    <div class="d-flex gap-8 flex-wrap mt-12">
        <form method="GET" action="{{ route('shopee.authorize') }}">
            @csrf
            <button class="btn secondary" type="submit">Authorize with Shopee</button>
        </form>
    </div>

    <hr style="border:none; border-top:1px solid var(--border-light); margin:14px 0;">

    <form method="POST" action="{{ route('shopee.token_get') }}">
        @csrf
        <div>
            <label>Auth Code (from redirect callback)</label>
            <input class="input" name="code" value="{{ old('code', '') }}" placeholder="Paste code=... here">
        </div>

        <div class="d-flex gap-8 flex-wrap mt-12">
            <button class="btn" type="submit">Exchange Code &rarr; Token</button>
        </div>
    </form>

    <hr style="border:none; border-top:1px solid var(--border-light); margin:14px 0;">

    <h3 class="section-title">Generic API Caller</h3>
    <form method="POST" action="{{ route('shopee.call_api') }}">
        @csrf
        <div class="form-grid">
            <div>
                <label>Method</label>
                <select class="input" name="method">
                    <option value="GET">GET</option>
                    <option value="POST">POST</option>
                </select>
            </div>

            <div>
                <label>API Path</label>
                <input class="input" name="path" value="{{ old('path', '/api/v2/shop/get_shop_info') }}">
            </div>

            <div>
                <label>Include Access Token in signature/query</label>
                <select class="input" name="use_access_token">
                    <option value="1" selected>Yes</option>
                    <option value="0">No</option>
                </select>
            </div>

            <div>
                <label>Include Shop ID in signature/query</label>
                <select class="input" name="use_shop_id">
                    <option value="1" selected>Yes</option>
                    <option value="0">No</option>
                </select>
            </div>

            <div class="full">
                <label>Extra Query JSON (optional)</label>
                <textarea class="input" name="query_json" rows="3" placeholder='{"page_size": 10}'>{{ old('query_json','') }}</textarea>
            </div>

            <div class="full">
                <label>Body JSON (optional, for POST)</label>
                <textarea class="input" name="body_json" rows="4" placeholder='{"foo":"bar"}'>{{ old('body_json','') }}</textarea>
            </div>
        </div>

        <div class="d-flex gap-8 flex-wrap mt-12">
            <button class="btn" type="submit">Call API</button>
        </div>
    </form>
</div>

@if($result)
    <div class="card">
        <h3 class="section-title mt-0">Last Result</h3>

        @php
            $ok = (bool)($result['ok'] ?? false);
        @endphp

        <div class="alert {{ $ok ? 'success' : 'danger' }}">
            <strong>{{ $result['title'] ?? 'Result' }}</strong>
        </div>

        <pre class="pre">{{ json_encode($result['data'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
@endif

<style>
    .pre { background:#0b1220; color:#e2e8f0; padding:12px; border-radius:var(--radius-md); overflow:auto; font-size:13px; }
</style>
@endsection
