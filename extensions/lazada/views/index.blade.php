@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Lazada')

@section('content')
<div class="page-header">
    <h2>Lazada Settings</h2>
    <div class="page-header-actions">
        <a class="btn small" href="{{ route('ext.lazada.orders.index') }}">Orders</a>
        <a class="btn" href="{{ route('ext.lazada.product-groups.index') }}">Product Groups</a>
    </div>
</div>

@php
    $currentMode = $setting->mode ?? 'live';
    $isSandbox = $currentMode === 'sandbox';

    // Live credentials
    $hasCredentials = ($setting->region ?? '') !== '' && ($setting->app_key ?? '') !== '' && ($setting->app_secret ?? '') !== '';
    $hasToken = !empty($setting->access_token ?? '');
    $tokenExpired = $hasToken && ($setting->expires_at ?? null) && \Carbon\Carbon::parse($setting->expires_at)->lt(now());

    // Sandbox credentials
    $hasSandboxCredentials = ($setting->sandbox_app_key ?? '') !== '' && ($setting->sandbox_app_secret ?? '') !== '';
    $hasSandboxToken = !empty($setting->sandbox_access_token ?? '');
    $sandboxTokenExpired = $hasSandboxToken && ($setting->sandbox_expires_at ?? null) && \Carbon\Carbon::parse($setting->sandbox_expires_at)->lt(now());

    $logCount = count($logs);
    $hasResult = !empty($result);
@endphp

{{-- ═══ Tabs ═══ --}}
<div class="tabs mb-12" style="justify-content:flex-start;">
    <button class="tab active" data-tab="lz-tab-settings" type="button">Settings</button>
    <button class="tab" data-tab="lz-tab-status" type="button">Status Mapping</button>
    <button class="tab" data-tab="lz-tab-explorer" type="button">API Explorer</button>
    <button class="tab" data-tab="lz-tab-logs" type="button">
        API Logs
        @if($logCount > 0)
            <span style="margin-left:6px; background:var(--surface); color:var(--text-secondary); font-size:11px; padding:1px 7px; border-radius:99px; font-weight:600;">{{ $logCount }}</span>
        @endif
    </button>
</div>

{{-- ╔══════════════════════════════════════════════════════════════════╗
     ║  TAB 1 — SETTINGS                                              ║
     ╚══════════════════════════════════════════════════════════════════╝ --}}
<div id="lz-tab-settings">

    {{-- Environment Toggle --}}
    <div style="display:flex; align-items:center; gap:14px; margin-bottom:16px; padding:12px 16px; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md);">
        <span class="font-semibold text-sm">Environment:</span>
        <form method="POST" action="{{ route('ext.lazada.toggle_mode') }}" id="lz-mode-form" style="display:flex; align-items:center; gap:10px;">
            @csrf
            <input type="hidden" name="mode" id="lz-mode-value" value="{{ $currentMode }}">
            <button type="button" id="lz-mode-toggle" style="
                position:relative; display:inline-flex; align-items:center; width:48px; height:26px;
                border-radius:13px; border:none; cursor:pointer; transition:background 0.2s;
                background:{{ $isSandbox ? '#9ca3af' : '#22c55e' }};
            ">
                <span id="lz-mode-knob" style="
                    position:absolute; top:3px; {{ $isSandbox ? 'left:3px' : 'left:25px' }};
                    width:20px; height:20px; background:#fff; border-radius:50%;
                    transition:left 0.2s; box-shadow:0 1px 3px rgba(0,0,0,.2);
                "></span>
            </button>
            <span id="lz-mode-label" class="text-sm" style="font-weight:600; color:{{ $isSandbox ? '#9ca3af' : '#22c55e' }};">
                {{ $isSandbox ? 'Sandbox' : 'Production' }}
            </span>
        </form>
    </div>

    {{-- Connection + Auth side-by-side --}}
    {{-- ═══ PRODUCTION fields ═══ --}}
    <div id="lz-env-live" class="{{ $isSandbox ? 'hidden' : '' }}">
    <div class="marketplace-settings-grid">
        {{-- Connection (Live) --}}
        <div class="card" style="margin-bottom:0;">
            <h3 class="section-title mt-0">Connection <span class="badge badge-green" style="font-size:10px; vertical-align:middle; margin-left:6px;">Production</span></h3>

            @if($hasCredentials)
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-green">Connected</span>
                    <span class="text-xs text-muted">Region: {{ strtoupper($setting->region ?? '—') }}</span>
                </div>
            @else
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-gray">Not configured</span>
                </div>
            @endif

            <form method="POST" action="{{ route('ext.lazada.save') }}">
                @csrf
                <input type="hidden" name="env" value="live">
                <div style="display:flex; flex-direction:column; gap:14px;">
                    <div>
                        <label>Region</label>
                        <select name="region" class="input">
                            <option value="">(not set)</option>
                            @foreach(['ph' => 'Philippines', 'sg' => 'Singapore', 'my' => 'Malaysia', 'id' => 'Indonesia', 'th' => 'Thailand', 'vn' => 'Vietnam'] as $code => $name)
                                <option value="{{ $code }}" {{ ($setting->region ?? '') === $code ? 'selected' : '' }}>{{ $name }} ({{ $code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label>App Key</label>
                        <input class="input" name="app_key" autocomplete="off" value="{{ old('app_key', $setting->app_key ?? '') }}">
                    </div>
                    <div>
                        <label>App Secret</label>
                        <input class="input" name="app_secret" type="password" autocomplete="off" value="{{ old('app_secret', $setting->app_secret ?? '') }}">
                        <div class="hint">Stored encrypted.</div>
                    </div>
                    <div>
                        <label>Redirect URI</label>
                        <input class="input" value="{{ $defaultRedirect }}" readonly onclick="this.select()" style="background:#f3f4f6; cursor:text;">
                        <div class="hint">Register this exact URL (HTTPS, derived from APP_URL) in your Lazada Open Platform live app. Read-only — edit <code>APP_URL</code> in <code>.env</code> to change.</div>
                    </div>
                </div>
                <div style="margin-top:14px;">
                    <button class="btn" type="submit">Save Settings</button>
                </div>
            </form>
        </div>

        {{-- Authorization (Live) --}}
        <div class="card" style="margin-bottom:0;">
            <h3 class="section-title mt-0">Authorization <span class="badge badge-green" style="font-size:10px; vertical-align:middle; margin-left:6px;">Production</span></h3>

            @if($hasToken && !$tokenExpired)
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-green">Token Active</span>
                    @if($setting->expires_at ?? null)
                        <span class="text-xs text-muted">Expires {{ \Carbon\Carbon::parse($setting->expires_at)->diffForHumans() }}</span>
                    @endif
                </div>
            @elseif($hasToken && $tokenExpired)
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-red">Token Expired</span>
                    <span class="text-xs text-muted">Use "Refresh Token" below to renew.</span>
                </div>
            @else
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-yellow">No Token</span>
                    <span class="text-xs text-muted">Follow the steps below to authorize.</span>
                </div>
            @endif

            <div style="display:flex; flex-direction:column; gap:16px;">
                {{-- Step 1 --}}
                <div class="detail-section">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--accent); color:#fff; font-size:12px; font-weight:700; flex-shrink:0;">1</span>
                        <span class="font-semibold text-sm">Open Lazada authorization page</span>
                    </div>
                    <a class="btn small" href="{{ route('ext.lazada.authorize') }}" target="_blank" rel="noopener">Authorize with Lazada</a>
                    <div class="hint" style="margin-top:6px;">Opens Lazada login in a new tab. After granting access, you'll be redirected back with an auth code.</div>
                </div>

                {{-- Step 2 --}}
                <div class="detail-section">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--accent); color:#fff; font-size:12px; font-weight:700; flex-shrink:0;">2</span>
                        <span class="font-semibold text-sm">Exchange auth code for access token</span>
                    </div>
                    <form method="POST" action="{{ route('ext.lazada.token_create') }}">
                        @csrf
                        <div style="display:flex; align-items:end; gap:8px; flex-wrap:wrap;">
                            <div style="flex:1; min-width:200px;">
                                <label class="text-xs text-muted">Auth Code</label>
                                <input class="input" name="code" value="{{ old('code', $setting->auth_code ?? session('lazada_last_auth_code') ?? '') }}" placeholder="Auto-fills after callback, or paste here">
                            </div>
                            <button class="btn small" type="submit">Exchange Token</button>
                        </div>
                    </form>
                </div>

                {{-- Step 3 --}}
                <div class="detail-section">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--bg3, #475569); color:#fff; font-size:12px; font-weight:700; flex-shrink:0;">3</span>
                        <span class="font-semibold text-sm">Refresh token (when expired)</span>
                    </div>
                    <form method="POST" action="{{ route('ext.lazada.token_refresh') }}" style="display:inline;">
                        @csrf
                        <button class="btn small secondary" type="submit">Refresh Access Token</button>
                    </form>
                    <div class="hint" style="margin-top:6px;">Lazada tokens expire periodically. Use this to renew without re-authorizing.</div>
                </div>
            </div>
        </div>
    </div>
    </div>

    {{-- ═══ SANDBOX fields ═══ --}}
    <div id="lz-env-sandbox" class="{{ !$isSandbox ? 'hidden' : '' }}">
    <div class="marketplace-settings-grid">
        {{-- Connection (Sandbox) --}}
        <div class="card" style="margin-bottom:0;">
            <h3 class="section-title mt-0">Connection <span class="badge badge-gray" style="font-size:10px; vertical-align:middle; margin-left:6px;">Sandbox</span></h3>

            @if($hasSandboxCredentials)
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-green">Connected</span>
                </div>
            @else
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-gray">Not configured</span>
                </div>
            @endif

            <form method="POST" action="{{ route('ext.lazada.save') }}">
                @csrf
                <input type="hidden" name="env" value="sandbox">
                <input type="hidden" name="region" value="{{ $setting->region ?? '' }}">
                <div style="display:flex; flex-direction:column; gap:14px;">
                    <div>
                        <label>Sandbox App Key</label>
                        <input class="input" name="sandbox_app_key" autocomplete="off" value="{{ old('sandbox_app_key', $setting->sandbox_app_key ?? '') }}">
                    </div>
                    <div>
                        <label>Sandbox App Secret</label>
                        <input class="input" name="sandbox_app_secret" type="password" autocomplete="off" value="{{ old('sandbox_app_secret', $setting->sandbox_app_secret ?? '') }}">
                        <div class="hint">Stored encrypted.</div>
                    </div>
                    <div>
                        <label>Sandbox Redirect URI</label>
                        <input class="input" value="{{ $defaultRedirect }}" readonly onclick="this.select()" style="background:#f3f4f6; cursor:text;">
                        <div class="hint">Register this exact URL (HTTPS, derived from APP_URL) in your Lazada Open Platform sandbox app. Read-only — edit <code>APP_URL</code> in <code>.env</code> to change.</div>
                    </div>
                </div>
                <div style="margin-top:14px;">
                    <button class="btn" type="submit">Save Sandbox Settings</button>
                </div>
            </form>
        </div>

        {{-- Authorization (Sandbox) --}}
        <div class="card" style="margin-bottom:0;">
            <h3 class="section-title mt-0">Authorization <span class="badge badge-gray" style="font-size:10px; vertical-align:middle; margin-left:6px;">Sandbox</span></h3>

            @if($hasSandboxToken && !$sandboxTokenExpired)
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-green">Token Active</span>
                    @if($setting->sandbox_expires_at ?? null)
                        <span class="text-xs text-muted">Expires {{ \Carbon\Carbon::parse($setting->sandbox_expires_at)->diffForHumans() }}</span>
                    @endif
                </div>
            @elseif($hasSandboxToken && $sandboxTokenExpired)
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-red">Token Expired</span>
                </div>
            @else
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-yellow">No Token</span>
                    <span class="text-xs text-muted">Configure sandbox credentials, then authorize.</span>
                </div>
            @endif

            <div style="display:flex; flex-direction:column; gap:16px;">
                <div class="detail-section">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:#9ca3af; color:#fff; font-size:12px; font-weight:700; flex-shrink:0;">1</span>
                        <span class="font-semibold text-sm">Open Lazada sandbox authorization page</span>
                    </div>
                    <a class="btn small" href="{{ route('ext.lazada.authorize') }}?sandbox=1" target="_blank" rel="noopener">Authorize Sandbox</a>
                    <div class="hint" style="margin-top:6px;">Uses sandbox credentials to authorize with the Lazada test environment.</div>
                </div>

                <div class="detail-section">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:#9ca3af; color:#fff; font-size:12px; font-weight:700; flex-shrink:0;">2</span>
                        <span class="font-semibold text-sm">Exchange auth code for sandbox token</span>
                    </div>
                    <form method="POST" action="{{ route('ext.lazada.token_create') }}">
                        @csrf
                        <input type="hidden" name="sandbox" value="1">
                        <div style="display:flex; align-items:end; gap:8px; flex-wrap:wrap;">
                            <div style="flex:1; min-width:200px;">
                                <label class="text-xs text-muted">Auth Code</label>
                                <input class="input" name="code" value="{{ old('code', $setting->sandbox_auth_code ?? '') }}" placeholder="Paste sandbox auth code here">
                            </div>
                            <button class="btn small" type="submit">Exchange Token</button>
                        </div>
                    </form>
                </div>

                <div class="detail-section">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--bg3, #475569); color:#fff; font-size:12px; font-weight:700; flex-shrink:0;">3</span>
                        <span class="font-semibold text-sm">Refresh sandbox token</span>
                    </div>
                    <form method="POST" action="{{ route('ext.lazada.token_refresh') }}" style="display:inline;">
                        @csrf
                        <input type="hidden" name="sandbox" value="1">
                        <button class="btn small secondary" type="submit">Refresh Sandbox Token</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    </div>

    {{-- Order Sync & Cronjob Setup --}}
    <div class="card mb-16">
        <h3 class="section-title mt-0">Order Sync & Cronjob Setup</h3>

        <div class="detail-section" style="margin-bottom:16px;">
            <label class="font-semibold text-sm" style="margin-bottom:8px; display:block;">Sync Last N Days</label>
            <div class="hint" style="margin-bottom:10px;">
                How far back the cronjob looks for orders. Defaults to 14 days.
            </div>
            <form method="POST" action="{{ route('ext.lazada.sync_days') }}">
                @csrf
                <div style="display:flex; align-items:center; gap:8px;">
                    <input type="number" name="sync_last_days" class="input"
                           value="{{ $setting->sync_last_days ?? '' }}"
                           placeholder="e.g. 7" min="1" max="365" style="width:100px;">
                    <span class="text-muted text-xs">days</span>
                    <button class="btn small" type="submit">Save</button>
                </div>
            </form>
            @if(($setting->sync_last_days ?? null) > 0)
                <div class="hint" style="margin-top:8px;">
                    Fetching orders from the <strong>last {{ $setting->sync_last_days }} days</strong>.
                </div>
            @else
                <div class="hint" style="margin-top:8px;">
                    Using default window of <strong>14 days</strong>.
                </div>
            @endif
        </div>

        <div class="detail-section" style="margin-bottom:16px;">
            <label class="font-semibold text-sm" style="margin-bottom:8px; display:block;">Sync Last N Days (Returns)</label>
            <div class="hint" style="margin-bottom:10px;">
                How far back the cronjob looks for <strong>return/refund</strong> orders. Independent from order sync. Defaults to 14 days.
            </div>
            <form method="POST" action="{{ route('ext.lazada.sync_days_returns') }}">
                @csrf
                <div style="display:flex; align-items:center; gap:8px;">
                    <input type="number" name="sync_last_days_returns" class="input"
                           value="{{ $setting->sync_last_days_returns ?? '' }}"
                           placeholder="e.g. 7" min="1" max="365" style="width:100px;">
                    <span class="text-muted text-xs">days</span>
                    <button class="btn small" type="submit">Save</button>
                </div>
            </form>
            @if(($setting->sync_last_days_returns ?? null) > 0)
                <div class="hint" style="margin-top:8px;">
                    Fetching returns from the <strong>last {{ $setting->sync_last_days_returns }} days</strong>.
                </div>
            @else
                <div class="hint" style="margin-top:8px;">
                    Using default window of <strong>14 days</strong>.
                </div>
            @endif
        </div>

        <h3 class="section-title">Cronjob Commands</h3>
        <div class="hint" style="margin-bottom:14px;">
            Add these commands to your hosting panel's scheduled tasks (HestiaCP, cPanel, etc.).
        </div>

        @php
            $artisanPath = '/usr/bin/php ' . base_path('artisan');
        @endphp

        <div style="display:flex; flex-direction:column; gap:12px; margin-bottom:16px;">
            <div>
                <label class="text-xs" style="margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                    <span style="font-weight:600; color:var(--text-primary);">Sync Orders</span>
                    <span class="text-muted">— Create new + update existing orders (no returns)</span>
                    <span style="margin-left:auto; color:var(--text-muted);">every 1 min</span>
                </label>
                <div style="display:flex; align-items:center; gap:8px; background:#0f172a; border:1px solid #334155; border-radius:var(--radius-sm); padding:10px 12px; overflow-x:auto;">
                    <code id="laz-cron-sync" style="flex:1; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12.5px; color:#e2e8f0; white-space:nowrap; user-select:all;">{{ $artisanPath }} lazada:sync-orders --no-returns</code>
                    <button type="button" class="btn small secondary" onclick="lazCopy('laz-cron-sync', this)" title="Copy" style="flex-shrink:0; height:28px; padding:0 10px; font-size:11px;">Copy</button>
                </div>
            </div>
            <div>
                <label class="text-xs" style="margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                    <span style="font-weight:600; color:var(--text-primary);">Push Stock</span>
                    <span class="text-muted">— Push ERP stock quantities to Lazada</span>
                    <span style="margin-left:auto; color:var(--text-muted);">every 30 min</span>
                </label>
                <div style="display:flex; align-items:center; gap:8px; background:#0f172a; border:1px solid #334155; border-radius:var(--radius-sm); padding:10px 12px; overflow-x:auto;">
                    <code id="laz-cron-push" style="flex:1; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12.5px; color:#e2e8f0; white-space:nowrap; user-select:all;">{{ $artisanPath }} lazada:push-stock</code>
                    <button type="button" class="btn small secondary" onclick="lazCopy('laz-cron-push', this)" title="Copy" style="flex-shrink:0; height:28px; padding:0 10px; font-size:11px;">Copy</button>
                </div>
            </div>
            <div>
                <label class="text-xs" style="margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                    <span style="font-weight:600; color:var(--text-primary);">Sync Returns</span>
                    <span class="text-muted">— Fetch return/refund orders from Lazada</span>
                    <span style="margin-left:auto; color:var(--text-muted);">every 5 min</span>
                </label>
                <div style="display:flex; align-items:center; gap:8px; background:#0f172a; border:1px solid #334155; border-radius:var(--radius-sm); padding:10px 12px; overflow-x:auto;">
                    <code id="laz-cron-returns" style="flex:1; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12.5px; color:#e2e8f0; white-space:nowrap; user-select:all;">{{ $artisanPath }} lazada:sync-orders --returns</code>
                    <button type="button" class="btn small secondary" onclick="lazCopy('laz-cron-returns', this)" title="Copy" style="flex-shrink:0; height:28px; padding:0 10px; font-size:11px;">Copy</button>
                </div>
            </div>
            <div>
                <label class="text-xs" style="margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                    <span style="font-weight:600; color:var(--text-primary);">Refresh Token</span>
                    <span class="text-muted">— Auto-refresh access token before expiry (~2 days)</span>
                    <span style="margin-left:auto; color:var(--text-muted);">every 6 hours</span>
                </label>
                <div style="display:flex; align-items:center; gap:8px; background:#0f172a; border:1px solid #334155; border-radius:var(--radius-sm); padding:10px 12px; overflow-x:auto;">
                    <code id="laz-cron-refresh" style="flex:1; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12.5px; color:#e2e8f0; white-space:nowrap; user-select:all;">{{ $artisanPath }} lazada:refresh-token</code>
                    <button type="button" class="btn small secondary" onclick="lazCopy('laz-cron-refresh', this)" title="Copy" style="flex-shrink:0; height:28px; padding:0 10px; font-size:11px;">Copy</button>
                </div>
            </div>
        </div>

        <details>
            <summary style="cursor:pointer; font-size:12.5px; font-weight:600; color:var(--text-secondary); user-select:none;">How to set up cronjobs</summary>
            <ol style="margin:10px 0 0; padding-left:20px; font-size:13px; color:var(--text-secondary); line-height:1.8;">
                <li>Go to your hosting panel (HestiaCP, cPanel, etc.)</li>
                <li>Find <strong style="color:var(--text-primary);">Cron Jobs</strong> or <strong style="color:var(--text-primary);">Scheduled Tasks</strong></li>
                <li>Set the interval (e.g. <code style="font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12px; background:#f1f5f9; padding:1px 6px; border-radius:4px; color:#0f172a;">* * * * *</code> for every minute)</li>
                <li>Paste the command into the command field</li>
                <li>Save</li>
            </ol>
        </details>
    </div>
</div>

{{-- ╔══════════════════════════════════════════════════════════════════╗
     ║  TAB 2 — API EXPLORER                                          ║
     ╚══════════════════════════════════════════════════════════════════╝ --}}
<div id="lz-tab-explorer" class="hidden">
<div class="card">
    <h3 class="section-title mt-0">API Explorer</h3>
    <div class="hint" style="margin-bottom:10px;">
        Pick an endpoint, fill parameters, and run. Base signing fields are generated automatically.
        <br><span class="api-code">E005: Invalid Request Format</span> usually means the endpoint expects a specific payload format (e.g., <span class="api-code">payload</span> as a JSON/XML string).
    </div>

    <div class="api-explorer">
        <div class="api-panel">
            <div class="api-search">
                <input class="input" id="api-endpoint-search" placeholder="Search endpoints (e.g. product/create, orders/get)">
            </div>
            <div class="api-hint">Click an endpoint to auto-fill method, auth, path and parameters.</div>

            <div class="api-list" id="api-endpoint-list">
                <div class="api-cat">Seller</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Seller","method":"GET","auth":true,"path":"/seller/get","desc":"Fetch seller/account info.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/seller/get</span><div class="api-hint">Seller/account info</div></button>

                <div class="api-cat">Catalog</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Category Tree","method":"GET","auth":false,"path":"/category/tree/get","desc":"Fetch category tree.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/category/tree/get</span><div class="api-hint">Public</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Category Attributes","method":"GET","auth":false,"path":"/category/attributes/get","desc":"Get attributes for a primary category.","params":[{"k":"primary_category_id","req":true,"ph":"9257"}]}'><span class="api-badge get">GET</span><span class="api-code">/category/attributes/get</span><div class="api-hint">Requires primary_category_id</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Brands Query","method":"GET","auth":false,"path":"/category/brands/query","desc":"Brands list (uses startRow/pageSize internally).","params":[{"k":"page_no","req":false,"ph":"1"},{"k":"page_size","req":false,"ph":"50"},{"k":"brand_name","req":false,"ph":"Morley"}]}'><span class="api-badge get">GET</span><span class="api-code">/category/brands/query</span><div class="api-hint">Paging + optional brand_name</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Products (List)","method":"GET","auth":true,"path":"/products/get","desc":"List products.","params":[{"k":"filter","req":false,"ph":"all"},{"k":"limit","req":false,"ph":"10"},{"k":"offset","req":false,"ph":"0"}]}'><span class="api-badge get">GET</span><span class="api-code">/products/get</span><div class="api-hint">Auth required</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"QC Status","method":"GET","auth":true,"path":"/product/qc/status/get","desc":"QC status by seller_sku.","params":[{"k":"seller_sku","req":true,"ph":"MY-SKU"}]}'><span class="api-badge get">GET</span><span class="api-code">/product/qc/status/get</span><div class="api-hint">seller_sku</div></button>

                <div class="api-cat">Products</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Create Product","method":"POST","auth":true,"path":"/product/create","desc":"Create product. Lazada expects payload as a JSON/XML string (depends on API).","params":[{"k":"payload","req":true,"type":"payload"}]}'><span class="api-badge post">POST</span><span class="api-code">/product/create</span><div class="api-hint">payload required</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Update Product","method":"POST","auth":true,"path":"/product/update","desc":"Update product. payload required.","params":[{"k":"payload","req":true,"type":"payload"}]}'><span class="api-badge post">POST</span><span class="api-code">/product/update</span><div class="api-hint">payload required</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Remove Product","method":"POST","auth":true,"path":"/product/remove","desc":"Remove product/SKUs. Lazada expects seller_sku_list and/or sku_id_list as JSON-array strings (max 50).","params":[{"k":"seller_sku_list","req":false,"ph":"[\\\"test00111\\\",\\\"test00222\\\"]"},{"k":"sku_id_list","req":false,"ph":"[\\\"SkuId_123_456\\\",\\\"SkuId_123_789\\\"]"}]}'><span class="api-badge post">POST</span><span class="api-code">/product/remove</span><div class="api-hint">seller_sku_list / sku_id_list</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Update Price/Qty","method":"POST","auth":true,"path":"/product/price_quantity/update","desc":"Update price + quantity.","params":[{"k":"seller_sku","req":true,"ph":"MY-SKU"},{"k":"price","req":true,"ph":"999"},{"k":"quantity","req":true,"ph":"10"}]}'><span class="api-badge post">POST</span><span class="api-code">/product/price_quantity/update</span><div class="api-hint">seller_sku, price, quantity</div></button>

                <div class="api-cat">Orders</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Orders (Range)","method":"GET","auth":true,"path":"/orders/get","desc":"Orders list.","params":[{"k":"update_after","req":false,"ph":""},{"k":"sort_direction","req":false,"ph":"DESC"},{"k":"limit","req":false,"ph":"10"},{"k":"offset","req":false,"ph":"0"}]}'><span class="api-badge get">GET</span><span class="api-code">/orders/get</span><div class="api-hint">update_after, limit, offset</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Order (Single)","method":"GET","auth":true,"path":"/order/get","desc":"Fetch one order.","params":[{"k":"order_id","req":true,"ph":"123"}]}'><span class="api-badge get">GET</span><span class="api-code">/order/get</span><div class="api-hint">order_id</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Order Items","method":"GET","auth":true,"path":"/order/items/get","desc":"Items for an order.","params":[{"k":"order_id","req":true,"ph":"123"}]}'><span class="api-badge get">GET</span><span class="api-code">/order/items/get</span><div class="api-hint">order_id</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Pack Order","method":"POST","auth":true,"path":"/order/pack","desc":"Pack order items.","params":[{"k":"delivery_type","req":true,"ph":"dropship"},{"k":"shipping_provider","req":true,"ph":""},{"k":"order_item_ids","req":true,"ph":"<comma-separated>"}]}'><span class="api-badge post">POST</span><span class="api-code">/order/pack</span><div class="api-hint">delivery_type, shipping_provider, order_item_ids</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Ready To Ship (RTS)","method":"POST","auth":true,"path":"/order/rts","desc":"Mark packed items as ready to ship.","params":[{"k":"delivery_type","req":true,"ph":"dropship"},{"k":"shipping_provider","req":true,"ph":""},{"k":"tracking_number","req":false,"ph":""},{"k":"order_item_ids","req":true,"ph":"<comma-separated>"}]}'><span class="api-badge post">POST</span><span class="api-code">/order/rts</span><div class="api-hint">delivery_type, shipping_provider, order_item_ids</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Shipping Label (AWB)","method":"GET","auth":true,"path":"/order/package/document/get","desc":"Fetch shipping label/document for an order.","params":[{"k":"order_id","req":true,"ph":"123"},{"k":"doc_type","req":false,"ph":"shippingLabel"}]}'><span class="api-badge get">GET</span><span class="api-code">/order/package/document/get</span><div class="api-hint">order_id, doc_type</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Cancel Order Item","method":"POST","auth":true,"path":"/order/cancel","desc":"Cancel an order item.","params":[{"k":"reason_id","req":true,"ph":""},{"k":"order_item_id","req":true,"ph":""},{"k":"reason_detail","req":false,"ph":""}]}'><span class="api-badge post">POST</span><span class="api-code">/order/cancel</span><div class="api-hint">reason_id, order_item_id</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Validate Cancel","method":"GET","auth":true,"path":"/order/reverse/cancel/validate","desc":"Validate whether an order can be cancelled.","params":[{"k":"order_id","req":true,"ph":"123"}]}'><span class="api-badge get">GET</span><span class="api-code">/order/reverse/cancel/validate</span><div class="api-hint">order_id</div></button>

                <div class="api-cat">Images</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Image Upload (Base64)","method":"POST","auth":true,"path":"/image/upload","desc":"Upload base64 image (returns Lazada inlink).","params":[{"k":"image","req":true,"ph":"<base64>"}]}'><span class="api-badge post">POST</span><span class="api-code">/image/upload</span><div class="api-hint">image (base64)</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Images Migrate","method":"POST","auth":true,"path":"/images/migrate","desc":"Migrate public URLs to Lazada-hosted images. payload required.","params":[{"k":"payload","req":true,"type":"payload"}]}'><span class="api-badge post">POST</span><span class="api-code">/images/migrate</span><div class="api-hint">payload required</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Image Response Get","method":"GET","auth":true,"path":"/image/response/get","desc":"Fetch migrate results by batch_id.","params":[{"k":"batch_id","req":true,"ph":"<batch_id>"}]}'><span class="api-badge get">GET</span><span class="api-code">/image/response/get</span><div class="api-hint">batch_id</div></button>

                <div class="api-cat">Finance</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Transaction Details","method":"GET","auth":true,"path":"/finance/transaction/details/get","desc":"Transaction/fees details.","params":[{"k":"start_time","req":true,"ph":""},{"k":"end_time","req":true,"ph":""},{"k":"limit","req":false,"ph":"10"},{"k":"offset","req":false,"ph":"0"}]}'><span class="api-badge get">GET</span><span class="api-code">/finance/transaction/details/get</span><div class="api-hint">start_time, end_time</div></button>

                <div class="api-cat">Reviews</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Review History List","method":"GET","auth":true,"path":"/review/seller/history/list","desc":"List review history for the seller within a date range (max 7-day window).","params":[{"k":"start_date","req":true,"ph":"2026-02-23"},{"k":"end_date","req":true,"ph":"2026-03-02"},{"k":"page_no","req":false,"ph":"1"},{"k":"page_size","req":false,"ph":"50"}]}'><span class="api-badge get">GET</span><span class="api-code">/review/seller/history/list</span><div class="api-hint">start_date, end_date, page_no, page_size</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Review Detail (Batch)","method":"GET","auth":true,"path":"/review/seller/list/v2","desc":"Get detailed review info by review ID list (JSON array of integers, max 20).","params":[{"k":"review_id_list","req":true,"ph":"[123456]"}]}'><span class="api-badge get">GET</span><span class="api-code">/review/seller/list/v2</span><div class="api-hint">review_id_list (JSON array)</div></button>

                <div class="api-cat">Returns / Reverse Orders</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Reverse Orders List","method":"GET","auth":true,"path":"/reverse/getreverseordersforseller","desc":"List reverse orders (returns/refunds) for seller with pagination.","params":[{"k":"pageNo","req":false,"ph":"1"},{"k":"pageSize","req":false,"ph":"50"},{"k":"create_time_start","req":false,"ph":""},{"k":"create_time_end","req":false,"ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/reverse/getreverseordersforseller</span><div class="api-hint">pageNo, pageSize, date filters</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Reverse Order Detail","method":"GET","auth":true,"path":"/order/reverse/return/detail/list","desc":"Get detail for a specific reverse order.","params":[{"k":"reverseOrderId","req":true,"ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/order/reverse/return/detail/list</span><div class="api-hint">reverseOrderId</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Reverse Order History","method":"GET","auth":true,"path":"/order/reverse/return/history/list","desc":"Communication history for a reverse order line.","params":[{"k":"reverseOrderLineId","req":true,"ph":""},{"k":"pageSize","req":false,"ph":"10"},{"k":"pageNumber","req":false,"ph":"1"}]}'><span class="api-badge get">GET</span><span class="api-code">/order/reverse/return/history/list</span><div class="api-hint">reverseOrderLineId</div></button>
            </div>
        </div>

        <div class="api-panel">
            <div class="api-split" style="margin-bottom:10px;">
                <div>
                    <div style="margin-bottom:0;">
                        <label>Selected Endpoint</label>
                        <div class="api-mini" id="lz-ep-name">None selected</div>
                        <div class="api-hint" id="lz-ep-desc"></div>
                    </div>
                </div>
                <div class="api-note">
                    <div class="font-bold" style="margin-bottom:6px;">Common pitfalls</div>
                    <div class="api-mini">
                        <div>&#8226; <span class="api-code">payload</span> must be a single string. If you paste JSON object/array, the server will encode it, but Lazada may require a specific structure.</div>
                        <div>&#8226; If you see repeated timestamps, you're reading an old log entry. Always check the newest row.</div>
                    </div>
                </div>
            </div>

            <form id="lazada-api-explorer-form" method="POST" action="{{ route('ext.lazada.explorer_run') }}">
                @csrf

                <div class="form-grid">
                    <div>
                        <label>Method</label>
                        <select name="method" class="input" id="lazada-explorer-method">
                            <option value="GET" {{ old('method', 'GET') === 'GET' ? 'selected' : '' }}>GET</option>
                            <option value="POST" {{ old('method') === 'POST' ? 'selected' : '' }}>POST</option>
                        </select>
                    </div>
                    <div>
                        <label>Auth</label>
                        <label class="d-flex items-center gap-8" style="margin-top:6px;">
                            <input type="checkbox" name="auth_required" value="1" id="lazada-explorer-auth" {{ old('auth_required') ? 'checked' : '' }}>
                            Use Access Token
                        </label>
                        <div class="api-mini">Enable for seller/product/order/finance endpoints.</div>
                    </div>
                </div>

                <div style="margin-top:12px;">
                    <label>API Path</label>
                    <input class="input" name="api_path" id="lazada-explorer-path" value="{{ old('api_path', '/seller/get') }}" placeholder="/seller/get">
                    <div class="api-mini">Leading slash will be added automatically.</div>
                </div>

                <div style="margin-top:12px;">
                    <label>Parameters</label>
                    <div class="api-mini" style="margin-bottom:8px;">Use this table for most calls. It will generate JSON automatically.</div>
                    <table class="api-kv" id="api-kv-table">
                        <thead>
                            <tr>
                                <th style="width:34%;">Key</th>
                                <th>Value</th>
                                <th style="width:70px;"></th>
                            </tr>
                        </thead>
                        <tbody id="api-kv-body"></tbody>
                    </table>
                    <div class="api-row-actions" style="margin-top:10px;">
                        <button class="btn small" type="button" id="lz-add-row">Add Param</button>
                        <button class="btn small hidden" type="button" id="lz-use-sample-payload">Insert Sample Payload</button>
                        <button class="btn small" type="button" id="lz-sync-to-json">Update JSON</button>
                        <span class="api-mini">(Auto updates before submit)</span>
                    </div>
                </div>

                <div style="margin-top:12px;">
                    <label>Params JSON</label>
                    <textarea class="input api-code" name="params_json_pretty" id="lazada-explorer-params" rows="10" placeholder='{"limit":10,"offset":0}'>{{ old('params_json_pretty', "{\n  \"payload\": {\n    \"Request\": {}\n  }\n}") }}</textarea>
                    <input type="hidden" name="params_json" id="lazada-explorer-params-hidden" value="{{ e(old('params_json', '{"payload":"<xml_or_json_payload_here>"}')) }}" />
                    <div class="api-mini">If you prefer, edit JSON directly. The table can be rebuilt from JSON using "Update JSON".</div>
                </div>

                <div class="d-flex gap-8 mt-12">
                    <button class="btn" type="submit">Run</button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>

{{-- ╔══════════════════════════════════════════════════════════════════╗
     ║  TAB 3 — STATUS MAPPING                                        ║
     ╚══════════════════════════════════════════════════════════════════╝ --}}
<div id="lz-tab-status" class="hidden">

    {{-- Order Status Mapping --}}
    <div class="card mb-16">
        <h3 class="section-title mt-0">Order Status Mapping</h3>
        <div class="hint" style="margin-bottom:14px;">
            When Lazada orders are synced, each Lazada status is mapped to an ERP order status below.
        </div>

        <form method="POST" action="{{ route('ext.lazada.order_status_map') }}">
            @csrf
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:45%;">Lazada Status</th>
                        <th>ERP Order Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($lazadaStatuses as $key => $label)
                    <tr>
                        <td style="vertical-align:middle;">
                            <code style="font-size:11.5px; background:var(--surface-alt); padding:2px 8px; border-radius:4px; border:1px solid var(--border-light);">{{ $key }}</code>
                            <span class="text-muted" style="margin-left:6px;">{{ $label }}</span>
                        </td>
                        <td>
                            <select name="map[{{ $key }}]" class="input" style="max-width:260px;">
                                @foreach($erpOrderStatuses as $os)
                                    <option value="{{ $os->order_status_id }}" {{ (int)($orderStatusMap[$key] ?? 0) === (int)$os->order_status_id ? 'selected' : '' }}>
                                        {{ $os->name }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            <div style="margin-top:14px;">
                <button class="btn" type="submit">Save Mapping</button>
            </div>
        </form>
    </div>

    {{-- Reverse Order Status Mapping --}}
    <div class="card mb-16">
        <h3 class="section-title mt-0">Reverse Order Status Mapping</h3>
        <div class="hint" style="margin-bottom:14px;">
            Map each Lazada reverse order status to an ERP order status.
        </div>

        <form method="POST" action="{{ route('ext.lazada.reverse_status_map') }}">
            @csrf
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:45%;">Reverse Status</th>
                        <th>ERP Order Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reverseStatuses as $key => $label)
                    <tr>
                        <td style="vertical-align:middle;">
                            <code style="font-size:11.5px; background:var(--surface-alt); padding:2px 8px; border-radius:4px; border:1px solid var(--border-light);">{{ $key }}</code>
                            <span class="text-muted" style="margin-left:6px;">{{ $label }}</span>
                        </td>
                        <td>
                            <select name="map[{{ $key }}]" class="input" style="max-width:260px;">
                                <option value="">(not mapped)</option>
                                @foreach($erpOrderStatuses as $os)
                                    <option value="{{ $os->order_status_id }}" {{ (int)($reverseStatusMap[$key] ?? 0) === (int)$os->order_status_id ? 'selected' : '' }}>
                                        {{ $os->name }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            <div style="margin-top:14px;">
                <button class="btn" type="submit">Save Mapping</button>
            </div>
        </form>
    </div>

</div>

{{-- ╔══════════════════════════════════════════════════════════════════╗
     ║  TAB 4 — API LOGS                                              ║
     ╚══════════════════════════════════════════════════════════════════╝ --}}
<div id="lz-tab-logs" class="hidden">

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
        <div>
            <span class="font-semibold text-sm">API Logging</span>
            <span class="hint" style="margin-left:8px;">Save API requests & responses for debugging.</span>
        </div>
        <label class="d-flex items-center gap-10" style="cursor:pointer;" id="lz-log-toggle">
            <span style="position:relative; display:inline-block; width:44px; height:24px;">
                <input type="checkbox" id="lz-log-cb" value="1"
                       {{ ($setting->api_logging ?? true) ? 'checked' : '' }}
                       style="position:absolute; opacity:0; width:0; height:0;">
                <span id="lz-log-track" style="position:absolute; inset:0; background:{{ ($setting->api_logging ?? true) ? 'var(--accent)' : 'var(--border)' }}; border-radius:12px; transition:background 0.2s;"></span>
                <span id="lz-log-knob" style="position:absolute; top:2px; left:{{ ($setting->api_logging ?? true) ? '22px' : '2px' }}; width:20px; height:20px; background:#fff; border-radius:50%; transition:left 0.2s; box-shadow:0 1px 3px rgba(0,0,0,.2);"></span>
            </span>
            <span id="lz-log-label" class="text-sm {{ ($setting->api_logging ?? true) ? 'font-bold' : 'text-muted' }}">
                {{ ($setting->api_logging ?? true) ? 'Enabled' : 'Disabled' }}
            </span>
        </label>
    </div>

    @if($result)
        <div class="card mb-16">
            <h3 class="section-title mt-0">Last Result</h3>
            @php $ok = (bool)($result['ok'] ?? false); @endphp
            <div class="alert {{ $ok ? 'success' : 'danger' }}">
                <strong>{{ $result['title'] ?? 'Result' }}</strong>
            </div>
            <pre style="background:#0b1220; color:#e2e8f0; padding:12px; border-radius:var(--radius-md); overflow:auto; font-size:13px; max-height:400px;">{{ json_encode($result['data'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif

    <div class="card">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
            <h3 class="section-title mt-0" style="margin-bottom:0; border:none; padding:0;">API Logs</h3>
            @if($logCount > 0)
                <form method="POST" action="{{ route('ext.lazada.clear_api_logs') }}" data-confirm="Delete all Lazada API logs?">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn small danger">Delete All Logs</button>
                </form>
            @endif
        </div>

        @if($logCount > 0)
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:140px;">Time</th>
                            <th style="width:80px;">Method</th>
                            <th>API Path</th>
                            <th style="width:90px;">Auth</th>
                            <th style="width:90px;">Status</th>
                            <th style="width:70px;">OK</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($logs as $log)
                            <tr>
                                <td>{{ $log->created_at }}</td>
                                <td><span class="badge {{ $log->ok ? 'badge-green' : 'badge-red' }}">{{ $log->method }}</span></td>
                                <td>
                                    @if(!empty($log->pack))
                                        <div class="hint" style="margin-bottom:2px;">Pack: <strong>{{ strtoupper($log->pack) }}</strong></div>
                                    @endif
                                    <div><code>{{ $log->api_path }}</code></div>
                                    <details style="margin-top:6px;">
                                        <summary>View</summary>
                                        <div style="margin-top:8px;">
                                            <div class="hint" style="margin-bottom:6px;">Request Params</div>
                                            <pre style="background:#0b1220; color:#e2e8f0; padding:12px; border-radius:var(--radius-md); overflow:auto; font-size:13px; max-height:220px;">{{ json_encode($log->request_params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                            <div class="hint" style="margin:10px 0 6px;">Response</div>
                                            <pre style="background:#0b1220; color:#e2e8f0; padding:12px; border-radius:var(--radius-md); overflow:auto; font-size:13px; max-height:220px;">{{ json_encode($log->response_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>
                                    </details>
                                </td>
                                <td>{{ $log->auth_required ? 'Yes' : 'No' }}</td>
                                <td>{{ $log->response_status }}</td>
                                <td>{{ $log->ok ? 'Yes' : 'No' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="text-center text-muted" style="padding:32px 0;">No API logs yet.</div>
        @endif
    </div>
</div>

@push('scripts')
<script>
// Environment toggle
(function(){
    var toggle = document.getElementById('lz-mode-toggle');
    var knob = document.getElementById('lz-mode-knob');
    var label = document.getElementById('lz-mode-label');
    var input = document.getElementById('lz-mode-value');
    var form = document.getElementById('lz-mode-form');
    var envLive = document.getElementById('lz-env-live');
    var envSandbox = document.getElementById('lz-env-sandbox');
    if (!toggle) return;

    toggle.addEventListener('click', function(){
        var isNowSandbox = input.value === 'live';
        input.value = isNowSandbox ? 'sandbox' : 'live';
        toggle.style.background = isNowSandbox ? '#9ca3af' : '#22c55e';
        knob.style.left = isNowSandbox ? '3px' : '25px';
        label.textContent = isNowSandbox ? 'Sandbox' : 'Production';
        label.style.color = isNowSandbox ? '#9ca3af' : '#22c55e';
        if (envLive) envLive.classList.toggle('hidden', isNowSandbox);
        if (envSandbox) envSandbox.classList.toggle('hidden', !isNowSandbox);
        form.submit();
    });
})();

function lazCopy(id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    var text = el.textContent;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
            btn.textContent = 'Copied!';
            setTimeout(function(){ btn.textContent = 'Copy'; }, 1500);
        });
    }
}

// Tab switching
(function(){
    var ids = ['lz-tab-settings', 'lz-tab-explorer', 'lz-tab-status', 'lz-tab-logs'];
    var tabs = document.querySelectorAll('[data-tab^="lz-tab-"]');
    var storageKey = 'lz-active-tab';

    function switchTab(tabId) {
        tabs.forEach(function(t){ t.classList.toggle('active', t.dataset.tab === tabId); });
        ids.forEach(function(id){
            var el = document.getElementById(id);
            if (el) el.classList.toggle('hidden', id !== tabId);
        });
    }

    tabs.forEach(function(btn){
        btn.addEventListener('click', function(){
            switchTab(btn.dataset.tab);
            localStorage.setItem(storageKey, btn.dataset.tab);
        });
    });

    // Restore tab: URL param > localStorage > $hasResult default
    var urlTab = new URLSearchParams(window.location.search).get('tab');
    var saved = urlTab ? ('lz-tab-' + urlTab) : localStorage.getItem(storageKey);
    if (!saved && {{ $hasResult ? 'true' : 'false' }}) saved = 'lz-tab-logs';
    if (saved && ids.indexOf(saved) !== -1) switchTab(saved);
})();

// Explorer JS
(function(){
    function qs(sel){ return document.querySelector(sel); }
    function qsa(sel){ return Array.prototype.slice.call(document.querySelectorAll(sel)); }

    var methodSel = qs('#lazada-explorer-method');
    var authChk   = qs('#lazada-explorer-auth');
    var pathInp   = qs('#lazada-explorer-path');
    var paramsTa  = qs('#lazada-explorer-params');
    var paramsHidden = qs('#lazada-explorer-params-hidden');
    var kvBody    = qs('#api-kv-body');
    var epName    = qs('#lz-ep-name');
    var epDesc    = qs('#lz-ep-desc');
    var epSearch  = qs('#api-endpoint-search');
    var btnAddRow = qs('#lz-add-row');
    var btnSync   = qs('#lz-sync-to-json');
    var btnSample = qs('#lz-use-sample-payload');

    function safeJsonParse(str){
        try { return JSON.parse(str); } catch(e){ return null; }
    }

    function tryParsePayloadString(pv){
        if (typeof pv !== 'string') return null;
        var parsed = safeJsonParse(pv);
        if (parsed !== null) return { parsed: parsed, normalized: pv };
        if (
            pv.indexOf('\\n') >= 0 || pv.indexOf('\\t') >= 0 ||
            pv.indexOf('\\r') >= 0 || pv.indexOf('\\"') >= 0 ||
            pv.indexOf('\\\\') >= 0
        ) {
            var unescaped = pv.replace(/\\r/g, '\r').replace(/\\n/g, '\n').replace(/\\t/g, '\t').replace(/\\"/g, '"').replace(/\\\\/g, '\\');
            var parsed2 = safeJsonParse(unescaped);
            if (parsed2 !== null) return { parsed: parsed2, normalized: unescaped };
        }
        return null;
    }

    function isObject(val){ return val !== null && typeof val === 'object'; }

    function buildPrettyAndHiddenParams(obj){
        var pretty = JSON.parse(JSON.stringify(obj || {}));
        var hidden = JSON.parse(JSON.stringify(obj || {}));
        if (obj && Object.prototype.hasOwnProperty.call(obj, 'payload')) {
            var pv = obj.payload;
            if (isObject(pv) || Array.isArray(pv)) {
                hidden.payload = JSON.stringify(pv, null, 2);
                pretty.payload = pv;
                return { pretty: pretty, hidden: hidden };
            }
            if (typeof pv === 'string') {
                var attempt = tryParsePayloadString(pv);
                if (attempt && attempt.parsed !== null && (isObject(attempt.parsed) || Array.isArray(attempt.parsed))) {
                    pretty.payload = attempt.parsed;
                    hidden.payload = attempt.normalized;
                    return { pretty: pretty, hidden: hidden };
                }
            }
        }
        return { pretty: pretty, hidden: hidden };
    }

    function syncHiddenFromPretty(){
        if(!paramsTa || !paramsHidden) return;
        var obj = safeJsonParse(paramsTa.value || '');
        if(!obj || typeof obj !== 'object') return;
        var conv = buildPrettyAndHiddenParams(obj);
        paramsTa.value = JSON.stringify(conv.pretty, null, 2);
        paramsHidden.value = JSON.stringify(conv.hidden, null, 2);
    }

    function buildRow(key, value){
        var tr = document.createElement('tr');
        var tdK = document.createElement('td');
        var tdV = document.createElement('td');
        var tdA = document.createElement('td');
        var k = document.createElement('input');
        k.className = 'input'; k.placeholder = 'key'; k.value = key || '';
        var v;
        if ((key || '').toLowerCase() === 'payload') {
            v = document.createElement('textarea');
            v.className = 'input api-code'; v.rows = 6; v.placeholder = 'payload (JSON/XML string)';
            if (isObject(value)) { v.value = JSON.stringify(value, null, 2); } else { v.value = value || ''; }
        } else {
            v = document.createElement('input');
            v.className = 'input'; v.placeholder = 'value';
            v.value = (value === null || typeof value === 'undefined') ? '' : String(value);
        }
        var del = document.createElement('button');
        del.type = 'button'; del.className = 'btn small'; del.textContent = 'Remove';
        del.addEventListener('click', function(){ tr.remove(); syncToJson(); });
        tdK.appendChild(k); tdV.appendChild(v); tdA.appendChild(del);
        tr.appendChild(tdK); tr.appendChild(tdV); tr.appendChild(tdA);
        kvBody.appendChild(tr);
    }

    function clearRows(){ while(kvBody.firstChild){ kvBody.removeChild(kvBody.firstChild); } }

    function tableToObject(){
        var obj = {};
        qsa('#api-kv-body tr').forEach(function(tr){
            var keyEl = tr.querySelector('td:nth-child(1) .input');
            var valEl = tr.querySelector('td:nth-child(2) .input, td:nth-child(2) textarea');
            var k = keyEl ? String(keyEl.value || '').trim() : '';
            if(!k) return;
            var vRaw = valEl ? valEl.value : '';
            if (String(k).toLowerCase() === 'payload') { obj[k] = vRaw; }
            else { var parsed = safeJsonParse(vRaw); obj[k] = parsed !== null ? parsed : vRaw; }
        });
        return obj;
    }

    function syncToJson(){
        if(!paramsTa) return;
        var obj = tableToObject();
        var conv = buildPrettyAndHiddenParams(obj);
        paramsTa.value = JSON.stringify(conv.pretty, null, 2);
        if (paramsHidden) { paramsHidden.value = JSON.stringify(conv.hidden, null, 2); }
    }

    function rebuildTableFromJson(){
        if(!paramsTa) return;
        var obj = safeJsonParse(paramsTa.value || '');
        if(!obj || typeof obj !== 'object') return;
        var conv = buildPrettyAndHiddenParams(obj);
        paramsTa.value = JSON.stringify(conv.pretty, null, 2);
        if (paramsHidden) { paramsHidden.value = JSON.stringify(conv.hidden, null, 2); }
        clearRows();
        Object.keys(conv.pretty).forEach(function(k){ buildRow(k, conv.pretty[k]); });
    }

    function selectEndpoint(ep){
        if (methodSel) methodSel.value = (ep.method || 'GET').toUpperCase();
        if (authChk) authChk.checked = !!ep.auth;
        if (pathInp) pathInp.value = ep.path || '';
        if (epName) epName.textContent = ep.name || 'Selected';
        if (epDesc) epDesc.textContent = ep.desc || '';
        clearRows();
        (ep.params || []).forEach(function(p){ buildRow(p.k, p.ph || ''); });
        var hasPayload = (ep.params || []).some(function(p){ return String(p.k).toLowerCase() === 'payload'; });
        var hasSample = hasPayload || (String(ep.path || '') === '/product/remove');
        if (btnSample) { if (hasSample) { btnSample.classList.remove('hidden'); } else { btnSample.classList.add('hidden'); } }
        syncToJson();
    }

    function getSamplePayloadFor(path){
        if (path === '/product/create' || path === '/product/update') {
            return JSON.stringify({ Request: { Product: { PrimaryCategory: 0, Images: { Image: ["https://<your-domain>/path/to/image.jpg"] }, Attributes: { name: "Sample Product Name", description: "Sample description", brand: "No Brand", model: "MODEL-001", package_height: "10", package_length: "10", package_width: "10", package_weight: "1" }, Skus: { Sku: [ { SellerSku: "SAMPLE-SKU", price: 100, quantity: 1 } ] } } } }, null, 2);
        }
        if (path === '/images/migrate') {
            return JSON.stringify({ Request: { Images: { Url: ["https://<your-domain>/path/to/image1.jpg"] } } }, null, 2);
        }
        if (path === '/image/migrate') {
            return JSON.stringify({ Request: { Image: { Url: "https://<your-domain>/path/to/image1.jpg" } } }, null, 2);
        }
        return '{\n  "Request": {}\n}';
    }

    qsa('.api-endpoint').forEach(function(btn){
        btn.addEventListener('click', function(){
            var ep = safeJsonParse(btn.getAttribute('data-ep') || '{}') || {};
            selectEndpoint(ep);
        });
    });

    if (epSearch) {
        epSearch.addEventListener('input', function(){
            var q = String(epSearch.value || '').toLowerCase();
            qsa('.api-endpoint').forEach(function(btn){
                var raw = (btn.getAttribute('data-ep') || '').toLowerCase();
                var t = (btn.textContent || '').toLowerCase() + ' ' + raw;
                btn.style.display = t.indexOf(q) >= 0 ? '' : 'none';
            });
        });
    }

    if (btnAddRow) btnAddRow.addEventListener('click', function(){ buildRow('', ''); });
    if (btnSync) btnSync.addEventListener('click', function(){ rebuildTableFromJson(); syncToJson(); });

    if (btnSample) {
        btnSample.addEventListener('click', function(){
            var p = (pathInp && pathInp.value) ? String(pathInp.value) : '';
            if (p === '/product/remove') {
                var sampleSellerSkuList = '["test00111","test00222","test00333"]';
                var sampleSkuIdList = '["SkuId_1269656765_5230534246","SkuId_1269656765_5230534247","SkuId_1269656765_5230534248"]';
                qsa('#api-kv-body tr').forEach(function(tr){
                    var keyEl = tr.querySelector('td:nth-child(1) .input');
                    if (!keyEl) return;
                    var k = String(keyEl.value || '').trim();
                    var valEl = tr.querySelector('td:nth-child(2) textarea, td:nth-child(2) .input');
                    if (!valEl) return;
                    if (k === 'seller_sku_list') valEl.value = sampleSellerSkuList;
                    if (k === 'sku_id_list') valEl.value = sampleSkuIdList;
                });
            } else {
                qsa('#api-kv-body tr').forEach(function(tr){
                    var keyEl = tr.querySelector('td:nth-child(1) .input');
                    if (!keyEl) return;
                    if (String(keyEl.value || '').trim().toLowerCase() !== 'payload') return;
                    var valEl = tr.querySelector('td:nth-child(2) textarea, td:nth-child(2) .input');
                    if (valEl) valEl.value = getSamplePayloadFor(p);
                });
            }
            syncToJson();
        });
    }

    var lastEdited = 'table';

    try { syncHiddenFromPretty(); rebuildTableFromJson(); syncToJson(); } catch(e) {}

    var kvBody2 = qs('#api-kv-body');
    if (kvBody2) {
        kvBody2.addEventListener('input', function(e){
            var t = e && e.target ? e.target : null;
            if (!t || !kvBody2.contains(t)) return;
            lastEdited = 'table';
            try { syncToJson(); } catch(err) {}
        });
    }

    if (paramsTa) { paramsTa.addEventListener('input', function(){ lastEdited = 'json'; }); }

    var form = qs('#lazada-api-explorer-form');
    if (form) form.addEventListener('submit', function(){
        if (lastEdited === 'json') { try { rebuildTableFromJson(); } catch(e) {} }
        try { syncToJson(); } catch(e2) {}
    });
})();

// API Logging toggle (no refresh)
(function(){
    var cb = document.getElementById('lz-log-cb');
    if (!cb) return;
    var track = document.getElementById('lz-log-track');
    var knob = document.getElementById('lz-log-knob');
    var label = document.getElementById('lz-log-label');

    cb.addEventListener('change', function(){
        var enabled = cb.checked;
        track.style.background = enabled ? 'var(--accent)' : 'var(--border)';
        knob.style.left = enabled ? '22px' : '2px';
        label.textContent = enabled ? 'Enabled' : 'Disabled';
        label.className = 'text-sm ' + (enabled ? 'font-bold' : 'text-muted');

        fetch('{{ route("ext.lazada.toggle_logging") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ api_logging: enabled ? 1 : 0 })
        }).catch(function(){
            cb.checked = !enabled;
            track.style.background = !enabled ? 'var(--accent)' : 'var(--border)';
            knob.style.left = !enabled ? '22px' : '2px';
            label.textContent = !enabled ? 'Enabled' : 'Disabled';
            label.className = 'text-sm ' + (!enabled ? 'font-bold' : 'text-muted');
        });
    });
})();
</script>
@endpush
@endsection
