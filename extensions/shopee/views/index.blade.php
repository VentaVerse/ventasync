@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Shopee')

@section('content')
<div class="page-header">
    <h2>Shopee Settings</h2>
</div>

@php
    $currentMode = $setting->mode ?? 'sandbox';
    $isSandbox = $currentMode === 'sandbox';

    // Live credentials
    $__hasToken = !empty($setting->access_token ?? '');
    $__tokenExpired = $__hasToken && ($setting->expires_at ?? null) && \Carbon\Carbon::parse($setting->expires_at)->lt(now());

    // Sandbox credentials
    $__hasSandboxCredentials = ($setting->sandbox_partner_id ?? '') !== '' && ($setting->sandbox_partner_key ?? '') !== '';
    $__hasSandboxToken = !empty($setting->sandbox_access_token ?? '');
    $__sandboxTokenExpired = $__hasSandboxToken && ($setting->sandbox_expires_at ?? null) && \Carbon\Carbon::parse($setting->sandbox_expires_at)->lt(now());

    $logCount = count($logs);
    $hasResult = !empty($result);
@endphp

{{-- ═══ Tabs ═══ --}}
<div class="tabs mb-12" style="justify-content:flex-start;">
    <button class="tab active" data-tab="sp-tab-settings" type="button">Settings</button>
    <button class="tab" data-tab="sp-tab-status" type="button">Status Mapping</button>
    <button class="tab" data-tab="sp-tab-explorer" type="button">API Explorer</button>
    <button class="tab" data-tab="sp-tab-logs" type="button">
        API Logs
        @if($logCount > 0)
            <span style="margin-left:6px; background:var(--surface); color:var(--text-secondary); font-size:11px; padding:1px 7px; border-radius:99px; font-weight:600;">{{ $logCount }}</span>
        @endif
    </button>
</div>

{{-- ╔══════════════════════════════════════════════════════════════════╗
     ║  TAB 1 — SETTINGS                                              ║
     ╚══════════════════════════════════════════════════════════════════╝ --}}
<div id="sp-tab-settings">

    {{-- Environment Toggle --}}
    <div style="display:flex; align-items:center; gap:14px; margin-bottom:16px; padding:12px 16px; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md);">
        <span class="font-semibold text-sm">Environment:</span>
        <form method="POST" action="{{ route('ext.shopee.toggle_mode') }}" id="sp-mode-form" style="display:flex; align-items:center; gap:10px;">
            @csrf
            <input type="hidden" name="mode" id="sp-mode-value" value="{{ $currentMode }}">
            <button type="button" id="sp-mode-toggle" style="
                position:relative; display:inline-flex; align-items:center; width:48px; height:26px;
                border-radius:13px; border:none; cursor:pointer; transition:background 0.2s;
                background:{{ $isSandbox ? '#9ca3af' : '#22c55e' }};
            ">
                <span id="sp-mode-knob" style="
                    position:absolute; top:3px; {{ $isSandbox ? 'left:3px' : 'left:25px' }};
                    width:20px; height:20px; background:#fff; border-radius:50%;
                    transition:left 0.2s; box-shadow:0 1px 3px rgba(0,0,0,.2);
                "></span>
            </button>
            <span id="sp-mode-label" class="text-sm" style="font-weight:600; color:{{ $isSandbox ? '#9ca3af' : '#22c55e' }};">
                {{ $isSandbox ? 'Sandbox' : 'Production' }}
            </span>
        </form>
    </div>

    {{-- Connection + Auth side-by-side on wide screens --}}
    {{-- ═══ PRODUCTION fields ═══ --}}
    <div id="sp-env-live" class="{{ $isSandbox ? 'hidden' : '' }}">
    <div class="marketplace-settings-grid">

        {{-- Connection (Live) --}}
        <div class="card" style="margin-bottom:0;">
            <h3 class="section-title mt-0">Connection <span class="badge badge-green" style="font-size:10px; vertical-align:middle; margin-left:6px;">Production</span></h3>

            <form method="POST" action="{{ route('ext.shopee.save') }}">
                @csrf
                <input type="hidden" name="env" value="live">
                <div style="display:flex; flex-direction:column; gap:14px;">
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
                        <input class="input" name="shop_id" autocomplete="off" value="{{ old('shop_id', $setting->shop_id ?? '') }}">
                    </div>
                    <div>
                        <label>Access Token</label>
                        <input class="input" type="password" autocomplete="off" name="access_token" value="{{ old('access_token', $setting->access_token ?? '') }}">
                        <div class="hint">Stored encrypted.</div>
                    </div>
                    <div class="form-grid">
                        <div>
                            <label>Redirect URI</label>
                            <input class="input" value="{{ $defaultRedirect }}" readonly onclick="this.select()" style="background:#f3f4f6; cursor:text;">
                            <div class="hint">Register this exact URL in your Shopee Open Platform live app. Read-only — edit <code>APP_URL</code> in <code>.env</code> to change.</div>
                        </div>
                        <div>
                            <label>Region</label>
                            <input class="input" name="region" value="{{ old('region', $setting->region ?? '') }}" placeholder="PH, SG, MY...">
                        </div>
                    </div>
                </div>
                <div style="margin-top:14px;">
                    <button class="btn" type="submit">Save Settings</button>
                </div>
            </form>
        </div>

        {{-- Authentication (Live) --}}
        <div class="card" style="margin-bottom:0;">
            <h3 class="section-title mt-0">Authentication <span class="badge badge-green" style="font-size:10px; vertical-align:middle; margin-left:6px;">Production</span></h3>

            @if($__hasToken && !$__tokenExpired)
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-green">Token Active</span>
                    @if($setting->expires_at ?? null)
                        <span class="text-xs text-muted">Expires {{ \Carbon\Carbon::parse($setting->expires_at)->diffForHumans() }}</span>
                    @endif
                </div>
            @elseif($__hasToken && $__tokenExpired)
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-red">Token Expired</span>
                    <span class="text-xs text-muted">Use "Refresh Token" below to renew.</span>
                </div>
            @else
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-yellow">No Token</span>
                    <span class="text-xs text-muted">Follow steps below to authorize.</span>
                </div>
            @endif

            <div style="display:flex; flex-direction:column; gap:16px;">
                {{-- Step 1 --}}
                <div class="detail-section">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--accent); color:#fff; font-size:12px; font-weight:700; flex-shrink:0;">1</span>
                        <span class="font-semibold text-sm">Authorize with Shopee</span>
                    </div>
                    <form method="GET" action="{{ route('ext.shopee.authorize') }}">
                        @csrf
                        <button class="btn small" type="submit">Authorize with Shopee</button>
                    </form>
                    <div class="hint" style="margin-top:6px;">Opens Shopee login. After granting access, you'll be redirected back with an auth code.</div>
                </div>

                {{-- Step 2 --}}
                <div class="detail-section">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--accent); color:#fff; font-size:12px; font-weight:700; flex-shrink:0;">2</span>
                        <span class="font-semibold text-sm">Exchange auth code for token</span>
                    </div>
                    <form method="POST" action="{{ route('ext.shopee.token_get') }}">
                        @csrf
                        <div style="display:flex; align-items:end; gap:8px; flex-wrap:wrap;">
                            <div style="flex:1; min-width:200px;">
                                <label class="text-xs text-muted">Auth Code</label>
                                <input class="input" name="code" value="{{ old('code', '') }}" placeholder="Paste code=... here">
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
                    <form method="POST" action="{{ route('ext.shopee.token_refresh') }}" style="display:inline;">
                        @csrf
                        <button class="btn small secondary" type="submit">Refresh Access Token</button>
                    </form>
                    <div class="hint" style="margin-top:6px;">Shopee tokens expire every ~4 hours. Use this to renew without re-authorizing.</div>
                </div>
            </div>
        </div>
    </div>
    </div>

    {{-- ═══ SANDBOX fields ═══ --}}
    <div id="sp-env-sandbox" class="{{ !$isSandbox ? 'hidden' : '' }}">
    <div class="marketplace-settings-grid">

        {{-- Connection (Sandbox) --}}
        <div class="card" style="margin-bottom:0;">
            <h3 class="section-title mt-0">Connection <span class="badge badge-gray" style="font-size:10px; vertical-align:middle; margin-left:6px;">Sandbox</span></h3>

            @if($__hasSandboxCredentials)
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-green">Connected</span>
                </div>
            @else
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-gray">Not configured</span>
                </div>
            @endif

            <form method="POST" action="{{ route('ext.shopee.save') }}">
                @csrf
                <input type="hidden" name="env" value="sandbox">
                <div style="display:flex; flex-direction:column; gap:14px;">
                    <div>
                        <label>Sandbox Partner ID</label>
                        <input class="input" name="sandbox_partner_id" value="{{ old('sandbox_partner_id', $setting->sandbox_partner_id ?? '') }}">
                    </div>
                    <div>
                        <label>Sandbox Partner Key</label>
                        <input class="input" name="sandbox_partner_key" value="{{ old('sandbox_partner_key', $setting->sandbox_partner_key ?? '') }}">
                        <div class="hint">Stored encrypted.</div>
                    </div>
                    <div>
                        <label>Sandbox Shop ID</label>
                        <input class="input" name="sandbox_shop_id" autocomplete="off" value="{{ old('sandbox_shop_id', $setting->sandbox_shop_id ?? '') }}">
                    </div>
                    <div>
                        <label>Sandbox Access Token</label>
                        <input class="input" type="password" autocomplete="off" name="sandbox_access_token" value="{{ old('sandbox_access_token', $setting->sandbox_access_token ?? '') }}">
                        <div class="hint">Stored encrypted.</div>
                    </div>
                    <div class="form-grid">
                        <div>
                            <label>Redirect URI</label>
                            <input class="input" value="{{ $defaultRedirect }}" readonly onclick="this.select()" style="background:#f3f4f6; cursor:text;">
                            <div class="hint">Register this exact URL in your Shopee Open Platform sandbox app. Read-only — edit <code>APP_URL</code> in <code>.env</code> to change.</div>
                        </div>
                        <div>
                            <label>Region</label>
                            <input class="input" name="sandbox_region" value="{{ old('sandbox_region', $setting->sandbox_region ?? '') }}" placeholder="PH, SG, MY...">
                        </div>
                    </div>
                </div>
                <div style="margin-top:14px;">
                    <button class="btn" type="submit">Save Sandbox Settings</button>
                </div>
            </form>
        </div>

        {{-- Authentication (Sandbox) --}}
        <div class="card" style="margin-bottom:0;">
            <h3 class="section-title mt-0">Authentication <span class="badge badge-gray" style="font-size:10px; vertical-align:middle; margin-left:6px;">Sandbox</span></h3>

            @if($__hasSandboxToken && !$__sandboxTokenExpired)
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-green">Token Active</span>
                    @if($setting->sandbox_expires_at ?? null)
                        <span class="text-xs text-muted">Expires {{ \Carbon\Carbon::parse($setting->sandbox_expires_at)->diffForHumans() }}</span>
                    @endif
                </div>
            @elseif($__hasSandboxToken && $__sandboxTokenExpired)
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
                        <span class="font-semibold text-sm">Authorize with Shopee Sandbox</span>
                    </div>
                    <form method="GET" action="{{ route('ext.shopee.authorize') }}">
                        @csrf
                        <button class="btn small" type="submit">Authorize Sandbox</button>
                    </form>
                    <div class="hint" style="margin-top:6px;">Uses sandbox credentials to authorize with the Shopee test environment.</div>
                </div>

                <div class="detail-section">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:#9ca3af; color:#fff; font-size:12px; font-weight:700; flex-shrink:0;">2</span>
                        <span class="font-semibold text-sm">Exchange auth code for sandbox token</span>
                    </div>
                    <form method="POST" action="{{ route('ext.shopee.token_get') }}">
                        @csrf
                        <div style="display:flex; align-items:end; gap:8px; flex-wrap:wrap;">
                            <div style="flex:1; min-width:200px;">
                                <label class="text-xs text-muted">Auth Code</label>
                                <input class="input" name="code" value="{{ old('code', '') }}" placeholder="Paste sandbox code here">
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
                    <form method="POST" action="{{ route('ext.shopee.token_refresh') }}" style="display:inline;">
                        @csrf
                        <button class="btn small secondary" type="submit">Refresh Sandbox Token</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    </div>

    {{-- Logistics Channels --}}
    <div class="card mb-16">
        <h3 class="section-title mt-0">Logistics Channels</h3>
        <div class="hint" style="margin-bottom:12px;">
            Fetch available logistics channels from Shopee to see which are enabled for your shop.
        </div>
        <form method="POST" action="{{ route('ext.shopee.logistics_channels') }}" style="display:inline;">
            @csrf
            <button class="btn" type="submit">Fetch Logistics Channels</button>
        </form>
    </div>

    {{-- Order Sync & Cronjob Setup --}}
    <div class="card mb-16">
        <h3 class="section-title mt-0">Order Sync & Cronjob Setup</h3>

        <div class="detail-section" style="margin-bottom:16px;">
            <label class="font-semibold text-sm" style="margin-bottom:8px; display:block;">Sync Last N Days</label>
            <div class="hint" style="margin-bottom:10px;">
                How far back the cronjob looks for orders. Defaults to 14 days.
            </div>
            <form method="POST" action="{{ route('ext.shopee.sync_days') }}">
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
            <form method="POST" action="{{ route('ext.shopee.sync_days_returns') }}">
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
                    <code id="sp-cron-sync" style="flex:1; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12.5px; color:#e2e8f0; white-space:nowrap; user-select:all;">{{ $artisanPath }} shopee:sync-orders --no-returns</code>
                    <button type="button" class="btn small secondary" onclick="spCopy('sp-cron-sync', this)" title="Copy" style="flex-shrink:0; height:28px; padding:0 10px; font-size:11px;">Copy</button>
                </div>
            </div>
            <div>
                <label class="text-xs" style="margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                    <span style="font-weight:600; color:var(--text-primary);">Push Stock</span>
                    <span class="text-muted">— Push ERP stock quantities to Shopee</span>
                    <span style="margin-left:auto; color:var(--text-muted);">every 30 min</span>
                </label>
                <div style="display:flex; align-items:center; gap:8px; background:#0f172a; border:1px solid #334155; border-radius:var(--radius-sm); padding:10px 12px; overflow-x:auto;">
                    <code id="sp-cron-push" style="flex:1; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12.5px; color:#e2e8f0; white-space:nowrap; user-select:all;">{{ $artisanPath }} shopee:push-stock</code>
                    <button type="button" class="btn small secondary" onclick="spCopy('sp-cron-push', this)" title="Copy" style="flex-shrink:0; height:28px; padding:0 10px; font-size:11px;">Copy</button>
                </div>
            </div>
            <div>
                <label class="text-xs" style="margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                    <span style="font-weight:600; color:var(--text-primary);">Sync Returns</span>
                    <span class="text-muted">— Fetch return/refund records from Shopee</span>
                    <span style="margin-left:auto; color:var(--text-muted);">every 30 min</span>
                </label>
                <div style="display:flex; align-items:center; gap:8px; background:#0f172a; border:1px solid #334155; border-radius:var(--radius-sm); padding:10px 12px; overflow-x:auto;">
                    <code id="sp-cron-returns" style="flex:1; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12.5px; color:#e2e8f0; white-space:nowrap; user-select:all;">{{ $artisanPath }} shopee:sync-orders --returns</code>
                    <button type="button" class="btn small secondary" onclick="spCopy('sp-cron-returns', this)" title="Copy" style="flex-shrink:0; height:28px; padding:0 10px; font-size:11px;">Copy</button>
                </div>
            </div>
            <div>
                <label class="text-xs" style="margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                    <span style="font-weight:600; color:var(--text-primary);">Token Refresh</span>
                    <span class="text-muted">— Auto-refresh access token before expiry</span>
                    <span style="margin-left:auto; color:var(--text-muted);">every 3 hours</span>
                </label>
                <div style="display:flex; align-items:center; gap:8px; background:#0f172a; border:1px solid #334155; border-radius:var(--radius-sm); padding:10px 12px; overflow-x:auto;">
                    <code id="sp-cron-token" style="flex:1; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12.5px; color:#e2e8f0; white-space:nowrap; user-select:all;">{{ $artisanPath }} shopee:refresh-token</code>
                    <button type="button" class="btn small secondary" onclick="spCopy('sp-cron-token', this)" title="Copy" style="flex-shrink:0; height:28px; padding:0 10px; font-size:11px;">Copy</button>
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
<div id="sp-tab-explorer" class="hidden">
<div class="card">
    <h3 class="section-title mt-0">API Explorer</h3>
    <div class="hint" style="margin-bottom:10px;">
        Pick an endpoint, fill parameters, and run. Signing fields are generated automatically.
    </div>

    <div class="api-explorer">
        <div class="api-panel">
            <div class="api-search">
                <input class="input" id="api-endpoint-search" placeholder="Search endpoints...">
            </div>
            <div class="api-hint">Click an endpoint to auto-fill method, auth, path and parameters.</div>

            <div class="api-list" id="api-endpoint-list">
                <div class="api-cat">Shop</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Shop Info","method":"GET","auth":true,"shop":true,"path":"/api/v2/shop/get_shop_info","desc":"Fetch shop info.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/shop/get_shop_info</span><div class="api-hint">Shop info</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Profile","method":"GET","auth":true,"shop":true,"path":"/api/v2/shop/get_profile","desc":"Fetch shop profile.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/shop/get_profile</span><div class="api-hint">Shop profile</div></button>

                <div class="api-cat">Product</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Item List","method":"GET","auth":true,"shop":true,"path":"/api/v2/product/get_item_list","desc":"List products. Requires update_time_from and update_time_to (Unix timestamps).","params":[{"k":"offset","req":false,"ph":"0"},{"k":"page_size","req":false,"ph":"50"},{"k":"update_time_from","req":true,"ph":""},{"k":"update_time_to","req":true,"ph":""},{"k":"item_status","req":false,"ph":"NORMAL"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/product/get_item_list</span><div class="api-hint">offset, page_size, update_time_from/to, item_status</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Item Base Info","method":"GET","auth":true,"shop":true,"path":"/api/v2/product/get_item_base_info","desc":"Get base info for item(s).","params":[{"k":"item_id_list","req":true,"ph":"123,456"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/product/get_item_base_info</span><div class="api-hint">item_id_list (comma-separated)</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Model List","method":"GET","auth":true,"shop":true,"path":"/api/v2/product/get_model_list","desc":"Get model/variant list for an item.","params":[{"k":"item_id","req":true,"ph":"123"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/product/get_model_list</span><div class="api-hint">item_id</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Category","method":"GET","auth":true,"shop":true,"path":"/api/v2/product/get_category","desc":"Fetch category tree.","params":[{"k":"language","req":false,"ph":"en"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/product/get_category</span><div class="api-hint">language</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Attributes","method":"GET","auth":true,"shop":true,"path":"/api/v2/product/get_attribute_tree","desc":"Get attributes for a category.","params":[{"k":"category_id","req":true,"ph":"100001"},{"k":"language","req":false,"ph":"en"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/product/get_attribute_tree</span><div class="api-hint">category_id</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Brand List","method":"GET","auth":true,"shop":true,"path":"/api/v2/product/get_brand_list","desc":"Get brands for a category.","params":[{"k":"category_id","req":true,"ph":"100001"},{"k":"offset","req":false,"ph":"0"},{"k":"page_size","req":false,"ph":"100"},{"k":"status","req":false,"ph":"1"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/product/get_brand_list</span><div class="api-hint">category_id, offset, page_size</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Update Stock","method":"POST","auth":true,"shop":true,"path":"/api/v2/product/update_stock","desc":"Update stock for an item.","params":[{"k":"item_id","req":true,"ph":"123"},{"k":"stock_list","req":true,"ph":"[{\"model_id\":0,\"normal_stock\":10}]"}]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/product/update_stock</span><div class="api-hint">item_id, stock_list</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Update Price","method":"POST","auth":true,"shop":true,"path":"/api/v2/product/update_price","desc":"Update price for an item.","params":[{"k":"item_id","req":true,"ph":"123"},{"k":"price_list","req":true,"ph":"[{\"model_id\":0,\"original_price\":100}]"}]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/product/update_price</span><div class="api-hint">item_id, price_list</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Category Recommend","method":"GET","auth":true,"shop":true,"path":"/api/v2/product/category_recommend","desc":"Get recommended categories for an item name.","params":[{"k":"item_name","req":true,"ph":"Guitar Pedal"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/product/category_recommend</span><div class="api-hint">item_name</div></button>

                <div class="api-cat">Order</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Order List","method":"GET","auth":true,"shop":true,"path":"/api/v2/order/get_order_list","desc":"List orders.","params":[{"k":"order_status","req":false,"ph":"READY_TO_SHIP"},{"k":"time_range_field","req":true,"ph":"create_time"},{"k":"time_from","req":true,"ph":""},{"k":"time_to","req":true,"ph":""},{"k":"page_size","req":false,"ph":"20"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/order/get_order_list</span><div class="api-hint">order_status, time range</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Order Detail","method":"GET","auth":true,"shop":true,"path":"/api/v2/order/get_order_detail","desc":"Get order details.","params":[{"k":"order_sn_list","req":true,"ph":"2502011234ABCD"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/order/get_order_detail</span><div class="api-hint">order_sn_list</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Cancel Order","method":"POST","auth":true,"shop":true,"path":"/api/v2/order/cancel_order","desc":"Cancel an order.","params":[{"k":"order_sn","req":true,"ph":""},{"k":"cancel_reason","req":true,"ph":"OUT_OF_STOCK"},{"k":"item_list","req":false,"ph":""}]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/order/cancel_order</span><div class="api-hint">order_sn, cancel_reason</div></button>

                <div class="api-cat">Logistics</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Channel List","method":"GET","auth":true,"shop":true,"path":"/api/v2/logistics/get_channel_list","desc":"Fetch available logistics channels.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/logistics/get_channel_list</span><div class="api-hint">Logistics channels</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Shipping Parameter","method":"GET","auth":true,"shop":true,"path":"/api/v2/logistics/get_shipping_parameter","desc":"Get shipping parameter for an order.","params":[{"k":"order_sn","req":true,"ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/logistics/get_shipping_parameter</span><div class="api-hint">order_sn</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Ship Order","method":"POST","auth":true,"shop":true,"path":"/api/v2/logistics/ship_order","desc":"Ship an order. Use pickup with address_id from get_shipping_parameter, or dropoff with empty object.","params":[{"k":"order_sn","req":true,"ph":""},{"k":"pickup","req":false,"ph":"{\"address_id\":0}"}]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/logistics/ship_order</span><div class="api-hint">order_sn, pickup/dropoff</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Tracking Number","method":"GET","auth":true,"shop":true,"path":"/api/v2/logistics/get_tracking_number","desc":"Get tracking number.","params":[{"k":"order_sn","req":true,"ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/logistics/get_tracking_number</span><div class="api-hint">order_sn</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Tracking Info","method":"GET","auth":true,"shop":true,"path":"/api/v2/logistics/get_tracking_info","desc":"Get tracking info for an order.","params":[{"k":"order_sn","req":true,"ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/logistics/get_tracking_info</span><div class="api-hint">order_sn</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Shipping Doc Parameter","method":"POST","auth":true,"shop":true,"path":"/api/v2/logistics/get_shipping_document_parameter","desc":"Get shipping document parameter (doc type, package number).","params":[{"k":"order_list","req":true,"ph":"[{\"order_sn\":\"\"}]"}]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/logistics/get_shipping_document_parameter</span><div class="api-hint">order_list</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Create Shipping Document","method":"POST","auth":true,"shop":true,"path":"/api/v2/logistics/create_shipping_document","desc":"Create shipping document.","params":[{"k":"order_list","req":true,"ph":"[{\"order_sn\":\"\",\"shipping_document_type\":\"THERMAL_AIR_WAYBILL\",\"tracking_number\":\"\"}]"}]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/logistics/create_shipping_document</span><div class="api-hint">order_list</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Shipping Doc Result","method":"POST","auth":true,"shop":true,"path":"/api/v2/logistics/get_shipping_document_result","desc":"Poll document creation status. Status: READY, FAILED, or PROCESSING.","params":[{"k":"order_list","req":true,"ph":"[{\"order_sn\":\"\"}]"}]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/logistics/get_shipping_document_result</span><div class="api-hint">order_list — check READY/FAILED</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Download Shipping Document","method":"POST","auth":true,"shop":true,"path":"/api/v2/logistics/download_shipping_document","desc":"Download shipping document as PDF. Response is binary — will show as raw data in explorer.","params":[{"k":"order_list","req":true,"ph":"[{\"order_sn\":\"\"}]"},{"k":"shipping_document_type","req":false,"ph":"THERMAL_AIR_WAYBILL"}]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/logistics/download_shipping_document</span><div class="api-hint">order_list — returns binary PDF</div></button>

                <div class="api-cat">Media</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Upload Image","method":"POST","auth":true,"shop":true,"path":"/api/v2/media_space/upload_image","desc":"Upload an image.","params":[]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/media_space/upload_image</span><div class="api-hint">Multipart upload</div></button>

                <div class="api-cat">Reviews</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Item Comments","method":"GET","auth":true,"shop":true,"path":"/api/v2/product/get_comment","desc":"Get reviews/comments for a specific item. Uses cursor-based pagination via comment_id.","params":[{"k":"item_id","req":true,"ph":"10076385934"},{"k":"comment_id","req":false,"ph":"0"},{"k":"page_size","req":false,"ph":"50"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/product/get_comment</span><div class="api-hint">item_id, comment_id, page_size</div></button>

                <div class="api-cat">Returns</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Return List","method":"GET","auth":true,"shop":true,"path":"/api/v2/returns/get_return_list","desc":"List returns.","params":[{"k":"page_no","req":false,"ph":"1"},{"k":"page_size","req":false,"ph":"20"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/returns/get_return_list</span><div class="api-hint">page_no, page_size</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Return Detail","method":"GET","auth":true,"shop":true,"path":"/api/v2/returns/get_return_detail","desc":"Get return details.","params":[{"k":"return_sn","req":true,"ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/returns/get_return_detail</span><div class="api-hint">return_sn</div></button>
            </div>
        </div>

        <div class="api-panel">
            <div class="api-split" style="margin-bottom:10px;">
                <div>
                    <label>Selected Endpoint</label>
                    <div class="api-mini" id="sp-ep-name">None selected</div>
                    <div class="api-hint" id="sp-ep-desc"></div>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:start;">
                    <form method="POST" action="{{ route('ext.shopee.packs_run') }}" style="display:inline;">@csrf<input type="hidden" name="pack" value="shop_info"><button class="btn small secondary" type="submit">Shop Info</button></form>
                    <form method="POST" action="{{ route('ext.shopee.packs_run') }}" style="display:inline;">@csrf<input type="hidden" name="pack" value="catalog"><button class="btn small secondary" type="submit">Catalog</button></form>
                    <form method="POST" action="{{ route('ext.shopee.packs_run') }}" style="display:inline;">@csrf<input type="hidden" name="pack" value="orders"><button class="btn small secondary" type="submit">Orders</button></form>
                    <form method="POST" action="{{ route('ext.shopee.packs_run') }}" style="display:inline;">@csrf<input type="hidden" name="pack" value="logistics"><button class="btn small secondary" type="submit">Logistics</button></form>
                    <form method="POST" action="{{ route('ext.shopee.packs_run') }}" style="display:inline;">@csrf<input type="hidden" name="pack" value="full"><button class="btn small" type="submit">Full</button></form>
                </div>
            </div>

            <form id="api-explorer-form" method="POST" action="{{ route('ext.shopee.explorer_run') }}">
                @csrf

                <div class="form-grid">
                    <div>
                        <label>Method</label>
                        <select name="method" class="input" id="api-explorer-method">
                            <option value="GET">GET</option>
                            <option value="POST">POST</option>
                        </select>
                    </div>
                    <div>
                        <label>Options</label>
                        <label class="d-flex items-center gap-8" style="margin-top:6px;">
                            <input type="checkbox" name="use_access_token" value="1" id="api-explorer-auth" checked>
                            Access Token
                        </label>
                        <label class="d-flex items-center gap-8" style="margin-top:4px;">
                            <input type="checkbox" name="use_shop_id" value="1" id="api-explorer-shop" checked>
                            Shop ID
                        </label>
                    </div>
                </div>

                <div style="margin-top:12px;">
                    <label>API Path</label>
                    <input class="input" name="api_path" id="api-explorer-path" value="/api/v2/shop/get_shop_info" placeholder="/api/v2/shop/get_shop_info">
                </div>

                <div style="margin-top:12px;">
                    <label>Parameters</label>
                    <div class="api-mini" style="margin-bottom:8px;">Use this table for most calls. JSON is generated automatically.</div>
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
                        <button class="btn small" type="button" id="sp-add-row">Add Param</button>
                        <button class="btn small" type="button" id="sp-sync-to-json">Update JSON</button>
                        <button class="btn small secondary" type="button" id="sp-sample-data">Sample Data</button>
                    </div>
                </div>

                <div style="margin-top:12px;">
                    <label>Params JSON</label>
                    <textarea class="input api-code" name="params_json" id="api-explorer-params" rows="6" placeholder='{"page_size":10}'>{}</textarea>
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
<div id="sp-tab-status" class="hidden">

    {{-- Order Status Mapping --}}
    <div class="card mb-16">
        <h3 class="section-title mt-0">Order Status Mapping</h3>
        <div class="hint" style="margin-bottom:14px;">
            When Shopee orders are synced, each Shopee status is mapped to an ERP order status below.
        </div>

        <form method="POST" action="{{ route('ext.shopee.order_status_map') }}">
            @csrf
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:45%;">Shopee Status</th>
                        <th>ERP Order Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($shopeeStatuses as $key => $label)
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

    {{-- Return Status Mapping --}}
    <div class="card mb-16">
        <h3 class="section-title mt-0">Return Status Mapping</h3>
        <div class="hint" style="margin-bottom:14px;">
            When Shopee returns are synced, each return status is mapped to an ERP order status below.
        </div>

        <form method="POST" action="{{ route('ext.shopee.return_status_map') }}">
            @csrf
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:45%;">Shopee Return Status</th>
                        <th>ERP Order Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($shopeeReturnStatuses as $key => $label)
                    <tr>
                        <td style="vertical-align:middle;">
                            <code style="font-size:11.5px; background:var(--surface-alt); padding:2px 8px; border-radius:4px; border:1px solid var(--border-light);">{{ $key }}</code>
                            <span class="text-muted" style="margin-left:6px;">{{ $label }}</span>
                        </td>
                        <td>
                            <select name="map[{{ $key }}]" class="input" style="max-width:260px;">
                                <option value="">(not mapped)</option>
                                @foreach($erpOrderStatuses as $os)
                                    <option value="{{ $os->order_status_id }}" {{ (int)($returnStatusMap[$key] ?? 0) === (int)$os->order_status_id ? 'selected' : '' }}>
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
<div id="sp-tab-logs" class="hidden">

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
        <div>
            <span class="font-semibold text-sm">API Logging</span>
            <span class="hint" style="margin-left:8px;">Save API requests & responses for debugging.</span>
        </div>
        <label class="d-flex items-center gap-10" style="cursor:pointer;" id="sp-log-toggle">
            <span style="position:relative; display:inline-block; width:44px; height:24px;">
                <input type="checkbox" id="sp-log-cb" value="1"
                       {{ ($setting->api_logging ?? true) ? 'checked' : '' }}
                       style="position:absolute; opacity:0; width:0; height:0;">
                <span id="sp-log-track" style="position:absolute; inset:0; background:{{ ($setting->api_logging ?? true) ? 'var(--accent)' : 'var(--border)' }}; border-radius:12px; transition:background 0.2s;"></span>
                <span id="sp-log-knob" style="position:absolute; top:2px; left:{{ ($setting->api_logging ?? true) ? '22px' : '2px' }}; width:20px; height:20px; background:#fff; border-radius:50%; transition:left 0.2s; box-shadow:0 1px 3px rgba(0,0,0,.2);"></span>
            </span>
            <span id="sp-log-label" class="text-sm {{ ($setting->api_logging ?? true) ? 'font-bold' : 'text-muted' }}">
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
                <form method="POST" action="{{ route('ext.shopee.clear_api_logs') }}" data-confirm="Delete all Shopee API logs?">
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
                                <td>{{ $log->response_status ?? '-' }}</td>
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
    var toggle = document.getElementById('sp-mode-toggle');
    var knob = document.getElementById('sp-mode-knob');
    var label = document.getElementById('sp-mode-label');
    var input = document.getElementById('sp-mode-value');
    var form = document.getElementById('sp-mode-form');
    var envLive = document.getElementById('sp-env-live');
    var envSandbox = document.getElementById('sp-env-sandbox');
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

// API Logging toggle (no refresh)
(function(){
    var cb = document.getElementById('sp-log-cb');
    if (!cb) return;
    var track = document.getElementById('sp-log-track');
    var knob = document.getElementById('sp-log-knob');
    var label = document.getElementById('sp-log-label');

    cb.addEventListener('change', function(){
        var enabled = cb.checked;
        track.style.background = enabled ? 'var(--accent)' : 'var(--border)';
        knob.style.left = enabled ? '22px' : '2px';
        label.textContent = enabled ? 'Enabled' : 'Disabled';
        label.className = 'text-sm ' + (enabled ? 'font-bold' : 'text-muted');

        fetch('{{ route("ext.shopee.toggle_logging") }}', {
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

function spCopy(id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    var text = el.textContent || el.innerText;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function () {
            btn.textContent = 'Copied!';
            setTimeout(function () { btn.textContent = 'Copy'; }, 1500);
        });
    }
}

// Tab switching
(function(){
    var ids = ['sp-tab-settings', 'sp-tab-explorer', 'sp-tab-status', 'sp-tab-logs'];
    var tabs = document.querySelectorAll('[data-tab^="sp-tab-"]');
    var storageKey = 'sp-active-tab';

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
    var saved = urlTab ? ('sp-tab-' + urlTab) : localStorage.getItem(storageKey);
    if (!saved && {{ $hasResult ? 'true' : 'false' }}) saved = 'sp-tab-logs';
    if (saved && ids.indexOf(saved) !== -1) switchTab(saved);
})();

// Explorer JS
(function(){
    function qs(sel){ return document.querySelector(sel); }
    function qsa(sel){ return Array.prototype.slice.call(document.querySelectorAll(sel)); }

    var methodSel = qs('#api-explorer-method');
    var authChk   = qs('#api-explorer-auth');
    var shopChk   = qs('#api-explorer-shop');
    var pathInp   = qs('#api-explorer-path');
    var paramsTa  = qs('#api-explorer-params');
    var kvBody    = qs('#api-kv-body');
    var epName    = qs('#sp-ep-name');
    var epDesc    = qs('#sp-ep-desc');
    var epSearch  = qs('#api-endpoint-search');
    var btnAddRow = qs('#sp-add-row');
    var btnSync   = qs('#sp-sync-to-json');

    function safeJsonParse(str){
        try { return JSON.parse(str); } catch(e){ return null; }
    }

    function buildRow(key, value){
        var tr = document.createElement('tr');
        var tdK = document.createElement('td');
        var tdV = document.createElement('td');
        var tdA = document.createElement('td');
        var k = document.createElement('input');
        k.className = 'input';
        k.placeholder = 'key';
        k.value = key || '';
        var v = document.createElement('input');
        v.className = 'input';
        v.placeholder = 'value';
        v.value = (value === null || typeof value === 'undefined') ? '' : String(value);
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn small';
        del.textContent = 'Remove';
        del.addEventListener('click', function(){
            tr.remove();
            syncToJson();
        });
        tdK.appendChild(k);
        tdV.appendChild(v);
        tdA.appendChild(del);
        tr.appendChild(tdK);
        tr.appendChild(tdV);
        tr.appendChild(tdA);
        kvBody.appendChild(tr);
    }

    function clearRows(){
        while(kvBody.firstChild){ kvBody.removeChild(kvBody.firstChild); }
    }

    function tableToObject(){
        var obj = {};
        qsa('#api-kv-body tr').forEach(function(tr){
            var keyEl = tr.querySelector('td:nth-child(1) .input');
            var valEl = tr.querySelector('td:nth-child(2) .input');
            var k = keyEl ? String(keyEl.value || '').trim() : '';
            if(!k) return;
            var vRaw = valEl ? valEl.value : '';
            var parsed = safeJsonParse(vRaw);
            obj[k] = parsed !== null ? parsed : vRaw;
        });
        return obj;
    }

    function syncToJson(){
        if(!paramsTa) return;
        var obj = tableToObject();
        paramsTa.value = JSON.stringify(obj, null, 2);
    }

    function rebuildTableFromJson(){
        if(!paramsTa) return;
        var obj = safeJsonParse(paramsTa.value || '');
        if(!obj || typeof obj !== 'object') return;
        clearRows();
        Object.keys(obj).forEach(function(k){
            var v = obj[k];
            buildRow(k, typeof v === 'object' ? JSON.stringify(v) : v);
        });
    }

    function selectEndpoint(ep){
        if (methodSel) methodSel.value = (ep.method || 'GET').toUpperCase();
        if (authChk) authChk.checked = ep.auth !== false;
        if (shopChk) shopChk.checked = ep.shop !== false;
        if (pathInp) pathInp.value = ep.path || '';
        if (epName) epName.textContent = ep.name || 'Selected';
        if (epDesc) epDesc.textContent = ep.desc || '';
        clearRows();
        (ep.params || []).forEach(function(p){ buildRow(p.k, p.ph || ''); });
        syncToJson();
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

    var sampleData = {
        '/api/v2/product/get_item_list': function(){
            var now = Math.floor(Date.now() / 1000);
            var ago = now - (15 * 86400);
            return {offset: '0', page_size: '50', update_time_from: String(ago), update_time_to: String(now), item_status: 'NORMAL'};
        },
        '/api/v2/order/get_order_list': function(){
            var now = Math.floor(Date.now() / 1000);
            var ago = now - (15 * 86400);
            return {order_status: 'READY_TO_SHIP', time_range_field: 'create_time', time_from: String(ago), time_to: String(now), page_size: '20'};
        },
        '/api/v2/product/get_item_base_info': function(){ return {item_id_list: '123456'}; },
        '/api/v2/product/get_model_list': function(){ return {item_id: '123456'}; },
        '/api/v2/product/get_category': function(){ return {language: 'en'}; },
        '/api/v2/product/get_brand_list': function(){ return {category_id: '100001', offset: '0', page_size: '100', status: '1'}; },
        '/api/v2/product/get_attribute_tree': function(){ return {category_id: '100001', language: 'en'}; },
        '/api/v2/product/category_recommend': function(){ return {item_name: 'Guitar Pedal'}; },
        '/api/v2/returns/get_return_list': function(){ return {page_no: '1', page_size: '20'}; }
    };

    var btnSample = qs('#sp-sample-data');
    if (btnSample) btnSample.addEventListener('click', function(){
        var path = pathInp ? pathInp.value.trim() : '';
        var fn = sampleData[path];
        if (fn) {
            var data = fn();
            clearRows();
            Object.keys(data).forEach(function(k){ buildRow(k, data[k]); });
            syncToJson();
        } else {
            showFlashError('No sample data for: ' + path + '. Select an endpoint first.');
        }
    });

    if (btnAddRow) btnAddRow.addEventListener('click', function(){ buildRow('', ''); });
    if (btnSync) btnSync.addEventListener('click', function(){ rebuildTableFromJson(); syncToJson(); });

    if (kvBody) {
        kvBody.addEventListener('input', function(){ try { syncToJson(); } catch(e){} });
    }

    var form = qs('#api-explorer-form');
    if (form) form.addEventListener('submit', function(){
        try { syncToJson(); } catch(e) {}
    });
})();
</script>
@endpush
@endsection
