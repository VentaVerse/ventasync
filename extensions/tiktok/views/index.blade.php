@extends('layouts.app')
@section('breadcrumb', 'Marketplace / TikTok Shop')

@section('content')
<div class="page-header">
    <h2>TikTok Shop Settings</h2>
</div>

@php
    $currentMode = $setting->mode ?? 'live';
    $isSandbox = $currentMode === 'sandbox';

    // Live credentials
    $hasCredentials = ($setting->app_key ?? '') !== '' && ($setting->app_secret ?? '') !== '';
    $hasToken = !empty($setting->access_token ?? '');
    $tokenExpired = $hasToken && ($setting->expires_at ?? null) && \Carbon\Carbon::parse($setting->expires_at)->lt(now());
    $hasShop = !empty($setting->shop_cipher ?? '');

    // Sandbox credentials
    $hasSandboxCredentials = ($setting->sandbox_app_key ?? '') !== '' && ($setting->sandbox_app_secret ?? '') !== '';
    $hasSandboxToken = !empty($setting->sandbox_access_token ?? '');
    $sandboxTokenExpired = $hasSandboxToken && ($setting->sandbox_expires_at ?? null) && \Carbon\Carbon::parse($setting->sandbox_expires_at)->lt(now());

    $logCount = count($logs);
    $hasResult = !empty($result);
@endphp

{{-- Tabs --}}
<div class="tabs mb-12" style="justify-content:flex-start;">
    <button class="tab {{ !$hasResult ? 'active' : '' }}" data-tab="tt-tab-settings" type="button">Settings</button>
    <button class="tab" data-tab="tt-tab-explorer" type="button">API Explorer</button>
    <button class="tab {{ $hasResult ? 'active' : '' }}" data-tab="tt-tab-logs" type="button">
        API Logs
        @if($logCount > 0)
            <span style="margin-left:6px; background:var(--surface); color:var(--text-secondary); font-size:11px; padding:1px 7px; border-radius:99px; font-weight:600;">{{ $logCount }}</span>
        @endif
    </button>
</div>

{{-- TAB 1 -- SETTINGS --}}
<div id="tt-tab-settings" class="{{ $hasResult ? 'hidden' : '' }}">

    {{-- Environment Toggle --}}
    <div style="display:flex; align-items:center; gap:14px; margin-bottom:16px; padding:12px 16px; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md);">
        <span class="font-semibold text-sm">Environment:</span>
        <form method="POST" action="{{ route('ext.tiktok.toggle_mode') }}" id="tt-mode-form" style="display:flex; align-items:center; gap:10px;">
            @csrf
            <input type="hidden" name="mode" id="tt-mode-value" value="{{ $currentMode }}">
            <button type="button" id="tt-mode-toggle" style="
                position:relative; display:inline-flex; align-items:center; width:48px; height:26px;
                border-radius:13px; border:none; cursor:pointer; transition:background 0.2s;
                background:{{ $isSandbox ? '#9ca3af' : '#22c55e' }};
            ">
                <span id="tt-mode-knob" style="
                    position:absolute; top:3px; {{ $isSandbox ? 'left:3px' : 'left:25px' }};
                    width:20px; height:20px; background:#fff; border-radius:50%;
                    transition:left 0.2s; box-shadow:0 1px 3px rgba(0,0,0,.2);
                "></span>
            </button>
            <span id="tt-mode-label" class="text-sm" style="font-weight:600; color:{{ $isSandbox ? '#9ca3af' : '#22c55e' }};">
                {{ $isSandbox ? 'Sandbox' : 'Production' }}
            </span>
        </form>
    </div>

    {{-- Connection + Auth side-by-side --}}
    {{-- PRODUCTION fields --}}
    <div id="tt-env-live" class="{{ $isSandbox ? 'hidden' : '' }}">
    <div class="marketplace-settings-grid">
        {{-- Connection (Live) --}}
        <div class="card" style="margin-bottom:0;">
            <h3 class="section-title mt-0">Connection <span class="badge badge-green" style="font-size:10px; vertical-align:middle; margin-left:6px;">Production</span></h3>

            @if($hasCredentials)
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-green">Connected</span>
                    @if($setting->region ?? null)
                        <span class="text-xs text-muted">Region: {{ strtoupper($setting->region) }}</span>
                    @endif
                </div>
            @else
                <div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:14px;">
                    <span class="badge badge-gray">Not configured</span>
                </div>
            @endif

            <form method="POST" action="{{ route('ext.tiktok.save') }}">
                @csrf
                <input type="hidden" name="env" value="live">
                <div style="display:flex; flex-direction:column; gap:14px;">
                    <div>
                        <label>App Key</label>
                        <input class="input" name="app_key" autocomplete="off" value="{{ old('app_key', $setting->app_key ?? '') }}">
                        <div class="hint">From TikTok Shop Partner Center.</div>
                    </div>
                    <div>
                        <label>App Secret</label>
                        <input class="input" name="app_secret" type="password" autocomplete="off" value="{{ old('app_secret', $setting->app_secret ?? '') }}">
                        <div class="hint">Stored encrypted.</div>
                    </div>
                    <div>
                        <label>Redirect URI</label>
                        <input class="input" name="redirect_uri" value="{{ old('redirect_uri', $setting->redirect_uri ?? $defaultRedirect) }}">
                        <div class="hint">Must match the redirect URI in your TikTok Shop app settings.</div>
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
                {{-- Step 1: Authorize --}}
                <div class="detail-section">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--accent); color:#fff; font-size:12px; font-weight:700; flex-shrink:0;">1</span>
                        <span class="font-semibold text-sm">Open TikTok Shop authorization page</span>
                    </div>
                    <a class="btn small" href="{{ route('ext.tiktok.authorize') }}" target="_blank" rel="noopener">Authorize with TikTok Shop</a>
                    <div class="hint" style="margin-top:6px;">Opens TikTok Shop login in a new tab. After granting access, you'll be redirected back with an auth code.</div>
                </div>

                {{-- Step 2: Exchange Token --}}
                <div class="detail-section">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--accent); color:#fff; font-size:12px; font-weight:700; flex-shrink:0;">2</span>
                        <span class="font-semibold text-sm">Exchange auth code for access token</span>
                    </div>
                    <form method="POST" action="{{ route('ext.tiktok.token_get') }}">
                        @csrf
                        <div style="display:flex; align-items:end; gap:8px; flex-wrap:wrap;">
                            <div style="flex:1; min-width:200px;">
                                <label class="text-xs text-muted">Auth Code</label>
                                <input class="input" name="code" value="{{ old('code') }}" placeholder="Auto-fills after callback, or paste here">
                            </div>
                            <button class="btn small" type="submit">Exchange Token</button>
                        </div>
                    </form>
                    <div class="hint" style="margin-top:6px;">If the callback auto-exchanges tokens, this step is already done.</div>
                </div>

                {{-- Step 3: Get Shops --}}
                <div class="detail-section">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--accent); color:#fff; font-size:12px; font-weight:700; flex-shrink:0;">3</span>
                        <span class="font-semibold text-sm">Get authorized shops</span>
                    </div>
                    <form method="POST" action="{{ route('ext.tiktok.shops') }}" style="display:inline;">
                        @csrf
                        <button class="btn small" type="submit">Get Shops</button>
                    </form>
                    <div class="hint" style="margin-top:6px;">Fetches your authorized shops and saves the shop_cipher required for API calls.</div>

                    @if($hasShop)
                        <div style="margin-top:12px; padding:10px 14px; background:var(--surface-alt); border-radius:var(--radius-md); border:1px solid var(--border); line-height:1.7;">
                            <div class="text-xs text-muted" style="margin-bottom:6px;">Shop Info</div>
                            <div><strong>{{ $setting->shop_name ?? '—' }}</strong></div>
                            <div class="text-xs text-muted">Shop ID: {{ $setting->shop_id ?? '—' }}</div>
                            <div class="text-xs text-muted">Shop Code: {{ $setting->shop_code ?? '—' }}</div>
                            <div class="text-xs text-muted">Shop Cipher: {{ $setting->shop_cipher ?? '—' }}</div>
                            <div class="text-xs text-muted">Region: {{ strtoupper($setting->region ?? '—') }}</div>
                            <div class="text-xs text-muted">Warehouse: {{ $setting->warehouse_id ?? '—' }}</div>
                        </div>
                    @endif
                </div>

                {{-- Step 4: Refresh Token --}}
                <div class="detail-section">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--bg3, #475569); color:#fff; font-size:12px; font-weight:700; flex-shrink:0;">4</span>
                        <span class="font-semibold text-sm">Refresh token (when expired)</span>
                    </div>
                    <form method="POST" action="{{ route('ext.tiktok.token_refresh') }}" style="display:inline;">
                        @csrf
                        <button class="btn small secondary" type="submit">Refresh Access Token</button>
                    </form>
                    <div class="hint" style="margin-top:6px;">TikTok tokens expire periodically. Use this to renew without re-authorizing.</div>
                </div>
            </div>
        </div>
    </div>
    </div>

    {{-- SANDBOX fields --}}
    <div id="tt-env-sandbox" class="{{ !$isSandbox ? 'hidden' : '' }}">
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

            <form method="POST" action="{{ route('ext.tiktok.save') }}">
                @csrf
                <input type="hidden" name="env" value="sandbox">
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
                        <input class="input" name="sandbox_redirect_uri" value="{{ old('sandbox_redirect_uri', $setting->sandbox_redirect_uri ?? $defaultRedirect) }}">
                        <div class="hint">Callback URL for sandbox app.</div>
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
                        <span class="font-semibold text-sm">Open TikTok sandbox authorization page</span>
                    </div>
                    <a class="btn small" href="{{ route('ext.tiktok.authorize') }}" target="_blank" rel="noopener">Authorize Sandbox</a>
                </div>

                <div class="detail-section">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:#9ca3af; color:#fff; font-size:12px; font-weight:700; flex-shrink:0;">2</span>
                        <span class="font-semibold text-sm">Exchange auth code for sandbox token</span>
                    </div>
                    <form method="POST" action="{{ route('ext.tiktok.token_get') }}">
                        @csrf
                        <div style="display:flex; align-items:end; gap:8px; flex-wrap:wrap;">
                            <div style="flex:1; min-width:200px;">
                                <label class="text-xs text-muted">Auth Code</label>
                                <input class="input" name="code" placeholder="Paste sandbox auth code here">
                            </div>
                            <button class="btn small" type="submit">Exchange Token</button>
                        </div>
                    </form>
                </div>

                <div class="detail-section">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:#9ca3af; color:#fff; font-size:12px; font-weight:700; flex-shrink:0;">3</span>
                        <span class="font-semibold text-sm">Get sandbox shops</span>
                    </div>
                    <form method="POST" action="{{ route('ext.tiktok.shops') }}" style="display:inline;">
                        @csrf
                        <button class="btn small" type="submit">Get Shops</button>
                    </form>
                </div>

                <div class="detail-section">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--bg3, #475569); color:#fff; font-size:12px; font-weight:700; flex-shrink:0;">4</span>
                        <span class="font-semibold text-sm">Refresh sandbox token</span>
                    </div>
                    <form method="POST" action="{{ route('ext.tiktok.token_refresh') }}" style="display:inline;">
                        @csrf
                        <button class="btn small secondary" type="submit">Refresh Sandbox Token</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    </div>

    {{-- Sync Days --}}
    <div class="card mb-16">
        <h3 class="section-title mt-0">Sync Settings</h3>
        <form method="POST" action="{{ route('ext.tiktok.sync_days') }}" style="display:flex; align-items:end; gap:10px; flex-wrap:wrap;">
            @csrf
            <div style="min-width:160px;">
                <label>Sync last N days</label>
                <input class="input" type="number" name="sync_last_days" min="1" max="365" value="{{ $setting->sync_last_days ?? 15 }}" placeholder="15">
                <div class="hint">How many days of orders to sync (default 15).</div>
            </div>
            <button class="btn small" type="submit">Save</button>
        </form>
    </div>

    {{-- Order Status Mapping --}}
    <div class="card mb-16">
        <h3 class="section-title mt-0">Order Status Mapping</h3>
        <div class="hint" style="margin-bottom:14px;">
            When TikTok orders are synced, each TikTok status is mapped to an ERP order status below.
        </div>

        <form method="POST" action="{{ route('ext.tiktok.order_status_map') }}">
            @csrf
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:45%;">TikTok Status</th>
                        <th>ERP Order Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tikTokStatuses as $key => $label)
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

    {{-- Cronjob Commands --}}
    <div class="card mb-16">
        <h3 class="section-title mt-0">Cronjob Commands</h3>
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
                    <span class="text-muted">— Fetch and update TikTok Shop orders</span>
                    <span style="margin-left:auto; color:var(--text-muted);">every 15 min</span>
                </label>
                <div style="display:flex; align-items:center; gap:8px; background:#0f172a; border:1px solid #334155; border-radius:var(--radius-sm); padding:10px 12px; overflow-x:auto;">
                    <code id="tt-cron-sync" style="flex:1; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12.5px; color:#e2e8f0; white-space:nowrap; user-select:all;">{{ $artisanPath }} tiktok:sync-orders</code>
                    <button type="button" class="btn small secondary" onclick="ttCopy('tt-cron-sync', this)" title="Copy" style="flex-shrink:0; height:28px; padding:0 10px; font-size:11px;">Copy</button>
                </div>
            </div>
            <div>
                <label class="text-xs" style="margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                    <span style="font-weight:600; color:var(--text-primary);">Push Stock</span>
                    <span class="text-muted">— Push ERP stock quantities to TikTok Shop</span>
                    <span style="margin-left:auto; color:var(--text-muted);">every 30 min</span>
                </label>
                <div style="display:flex; align-items:center; gap:8px; background:#0f172a; border:1px solid #334155; border-radius:var(--radius-sm); padding:10px 12px; overflow-x:auto;">
                    <code id="tt-cron-stock" style="flex:1; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12.5px; color:#e2e8f0; white-space:nowrap; user-select:all;">{{ $artisanPath }} tiktok:push-stock</code>
                    <button type="button" class="btn small secondary" onclick="ttCopy('tt-cron-stock', this)" title="Copy" style="flex-shrink:0; height:28px; padding:0 10px; font-size:11px;">Copy</button>
                </div>
            </div>
            <div>
                <label class="text-xs" style="margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                    <span style="font-weight:600; color:var(--text-primary);">Refresh Token</span>
                    <span class="text-muted">— Refresh TikTok access token before expiry</span>
                    <span style="margin-left:auto; color:var(--text-muted);">daily at 3 AM</span>
                </label>
                <div style="display:flex; align-items:center; gap:8px; background:#0f172a; border:1px solid #334155; border-radius:var(--radius-sm); padding:10px 12px; overflow-x:auto;">
                    <code id="tt-cron-token" style="flex:1; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12.5px; color:#e2e8f0; white-space:nowrap; user-select:all;">{{ $artisanPath }} tiktok:refresh-token</code>
                    <button type="button" class="btn small secondary" onclick="ttCopy('tt-cron-token', this)" title="Copy" style="flex-shrink:0; height:28px; padding:0 10px; font-size:11px;">Copy</button>
                </div>
            </div>
        </div>

        <details>
            <summary style="cursor:pointer; font-size:12.5px; font-weight:600; color:var(--text-secondary); user-select:none;">How to set up cronjobs</summary>
            <ol style="margin:10px 0 0; padding-left:20px; font-size:13px; color:var(--text-secondary); line-height:1.8;">
                <li>Go to your hosting panel (HestiaCP, cPanel, etc.)</li>
                <li>Find <strong style="color:var(--text-primary);">Cron Jobs</strong> or <strong style="color:var(--text-primary);">Scheduled Tasks</strong></li>
                <li>Set the interval (e.g. <code style="font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12px; background:#f1f5f9; padding:1px 6px; border-radius:4px; color:#0f172a;">*/15 * * * *</code> for every 15 minutes)</li>
                <li>Paste the command into the command field</li>
                <li>Save</li>
            </ol>
        </details>

        <div class="hint" style="margin-top:12px;">
            <strong>Note:</strong> These artisan commands will be available once created. For now, use the manual buttons on the Orders and Product Groups pages.
        </div>
    </div>

    {{-- Setup Guide --}}
    <div class="card mb-16">
        <h3 class="section-title mt-0">Setup Guide</h3>
        <div style="display:flex; flex-direction:column; gap:12px;">
            <div>
                <strong>1. Register as a TikTok Shop Developer</strong>
                <div class="hint">Go to <code>partner.tiktokshop.com</code> and register for a developer account.</div>
            </div>
            <div>
                <strong>2. Create an App</strong>
                <div class="hint">In the Partner Center, create a new application. You will receive an App Key and App Secret.</div>
            </div>
            <div>
                <strong>3. Configure Redirect URI</strong>
                <div class="hint">In your TikTok app settings, set the Redirect URI to: <code>{{ url('/tiktok/callback') }}</code></div>
            </div>
            <div>
                <strong>4. Enter credentials above</strong>
                <div class="hint">Paste the App Key and App Secret in the Connection card and save.</div>
            </div>
            <div>
                <strong>5. Authorize your shop</strong>
                <div class="hint">Click "Authorize with TikTok Shop" to start the OAuth flow. After granting access, the token will be exchanged automatically.</div>
            </div>
            <div>
                <strong>6. Get authorized shops</strong>
                <div class="hint">Click "Get Shops" to fetch your shop information (shop_cipher is required for most API calls).</div>
            </div>
        </div>
        <div style="margin-top:12px; padding:10px; background:var(--surface-alt); border-radius:var(--radius-md); border:1px solid var(--border);">
            <div class="text-xs text-muted" style="margin-bottom:4px;">Important</div>
            <div class="hint">
                Use <strong>partner.tiktokshop.com</strong> (TikTok Shop Partner Center) for seller/e-commerce integrations.
                <br><strong>developers.tiktok.com</strong> is for general TikTok platform features (Login Kit, Share Kit) and is NOT used for shop/order/product sync.
            </div>
        </div>
    </div>
</div>

{{-- TAB 2 -- API EXPLORER --}}
<div id="tt-tab-explorer" class="hidden">
<div class="card">
    <h3 class="section-title mt-0">API Explorer</h3>
    <div class="hint" style="margin-bottom:10px;">
        Pick an endpoint, fill parameters, and run. Signing fields (<code>app_key</code>, <code>timestamp</code>, <code>sign</code>, <code>shop_cipher</code>) are generated automatically.
    </div>

    <div class="api-explorer">
        <div class="api-panel">
            <div class="api-search">
                <input class="input" id="api-endpoint-search" placeholder="Search endpoints (e.g. product, orders)">
            </div>
            <div class="api-hint">Click an endpoint to auto-fill method, auth, path and parameters.</div>

            <div class="api-list" id="api-endpoint-list">
                <div class="api-cat">Authorization</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Authorized Shops","method":"GET","auth":true,"path":"/authorization/202309/shops","desc":"Fetch shops authorized for your app.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/authorization/202309/shops</span><div class="api-hint">Returns shop_id, shop_cipher, shop_name</div></button>

                <div class="api-cat">Product</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Search Products","method":"POST","auth":true,"path":"/product/202309/products/search","desc":"Search products in your shop.","params":[{"k":"page_size","ph":"10"}]}'><span class="api-badge post">POST</span><span class="api-code">/product/202309/products/search</span><div class="api-hint">page_size (body)</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Product Detail","method":"GET","auth":true,"path":"/product/202309/products","desc":"Get details of a specific product.","params":[{"k":"ids","ph":"product_id"}]}'><span class="api-badge get">GET</span><span class="api-code">/product/202309/products</span><div class="api-hint">ids (query param)</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Product Categories","method":"GET","auth":true,"path":"/product/202309/categories","desc":"Get product category tree.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/product/202309/categories</span><div class="api-hint">Category tree</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Product Attributes","method":"GET","auth":true,"path":"/product/202309/categories/rules","desc":"Get required/optional attributes for a category.","params":[{"k":"category_id","ph":"600001"}]}'><span class="api-badge get">GET</span><span class="api-code">/product/202309/categories/rules</span><div class="api-hint">category_id</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Brands","method":"GET","auth":true,"path":"/product/202309/brands","desc":"Get available brands.","params":[{"k":"page_size","ph":"20"},{"k":"category_id","ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/product/202309/brands</span><div class="api-hint">page_size, category_id</div></button>

                <div class="api-cat">Order</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Search Orders","method":"POST","auth":true,"path":"/order/202309/orders/search","desc":"Search orders by date range.","params":[{"k":"page_size","ph":"10"},{"k":"create_time_ge","ph":""},{"k":"create_time_lt","ph":""}]}'><span class="api-badge post">POST</span><span class="api-code">/order/202309/orders/search</span><div class="api-hint">page_size, create_time_ge/lt (unix)</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Order Detail","method":"POST","auth":true,"path":"/order/202309/orders","desc":"Get details of specific orders.","params":[{"k":"order_ids","ph":"[\"order_id\"]"}]}'><span class="api-badge post">POST</span><span class="api-code">/order/202309/orders</span><div class="api-hint">order_ids (body)</div></button>

                <div class="api-cat">Logistics</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Delivery Options","method":"GET","auth":true,"path":"/logistics/202309/delivery_options","desc":"Get available delivery/shipping options.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/logistics/202309/delivery_options</span><div class="api-hint">Shipping options</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Warehouses","method":"GET","auth":true,"path":"/logistics/202309/warehouses","desc":"Get warehouse list.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/logistics/202309/warehouses</span><div class="api-hint">Warehouse info</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Shipping Providers","method":"GET","auth":true,"path":"/logistics/202309/shipping_providers","desc":"Get available shipping providers.","params":[{"k":"delivery_option_id","ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/logistics/202309/shipping_providers</span><div class="api-hint">delivery_option_id</div></button>

                <div class="api-cat">Finance</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Search Settlements","method":"POST","auth":true,"path":"/finance/202309/settlements/search","desc":"Search financial settlement records.","params":[{"k":"page_size","ph":"10"}]}'><span class="api-badge post">POST</span><span class="api-code">/finance/202309/settlements/search</span><div class="api-hint">page_size</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Payments","method":"POST","auth":true,"path":"/finance/202309/payments/search","desc":"Search payment records.","params":[{"k":"page_size","ph":"10"}]}'><span class="api-badge post">POST</span><span class="api-code">/finance/202309/payments/search</span><div class="api-hint">page_size</div></button>

                <div class="api-cat">Fulfillment</div>
                <button type="button" class="api-endpoint" data-ep='{"name":"Search Packages","method":"POST","auth":true,"path":"/fulfillment/202309/packages/search","desc":"Search fulfillment packages.","params":[{"k":"page_size","ph":"10"}]}'><span class="api-badge post">POST</span><span class="api-code">/fulfillment/202309/packages/search</span><div class="api-hint">page_size</div></button>
                <button type="button" class="api-endpoint" data-ep='{"name":"Get Package Detail","method":"GET","auth":true,"path":"/fulfillment/202309/packages","desc":"Get package details.","params":[{"k":"package_id","ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/fulfillment/202309/packages</span><div class="api-hint">package_id</div></button>
            </div>
        </div>

        <div class="api-panel">
            <div class="api-split" style="margin-bottom:10px;">
                <div>
                    <div style="margin-bottom:0;">
                        <label>Selected Endpoint</label>
                        <div class="api-mini" id="tt-ep-name">None selected</div>
                        <div class="api-hint" id="tt-ep-desc"></div>
                    </div>
                </div>
                <div class="api-note">
                    <div class="font-bold" style="margin-bottom:6px;">TikTok API Notes</div>
                    <div class="api-mini">
                        <div>&#8226; TikTok uses path-based versioning: <code>/product/202309/...</code></div>
                        <div>&#8226; Most endpoints require <code>shop_cipher</code> (auto-added if set).</div>
                        <div>&#8226; POST body must be JSON. Query params include <code>app_key</code>, <code>timestamp</code>, <code>sign</code>.</div>
                    </div>
                </div>
            </div>

            <form id="tt-api-explorer-form" method="POST" action="{{ route('ext.tiktok.explorer_run') }}">
                @csrf

                <div class="form-grid">
                    <div>
                        <label>Method</label>
                        <select name="method" class="input" id="api-explorer-method">
                            <option value="GET" {{ old('method', 'GET') === 'GET' ? 'selected' : '' }}>GET</option>
                            <option value="POST" {{ old('method') === 'POST' ? 'selected' : '' }}>POST</option>
                        </select>
                    </div>
                    <div>
                        <label>Auth</label>
                        <label class="d-flex items-center gap-8" style="margin-top:6px;">
                            <input type="checkbox" name="auth_required" value="1" id="api-explorer-auth" {{ old('auth_required') ? 'checked' : '' }}>
                            Use Access Token
                        </label>
                        <div class="api-mini">Enable for shop-level endpoints (products, orders, etc.).</div>
                    </div>
                </div>

                <div style="margin-top:12px;">
                    <label>API Path</label>
                    <input class="input" name="api_path" id="api-explorer-path" value="{{ old('api_path', '/authorization/202309/shops') }}" placeholder="/authorization/202309/shops">
                    <div class="api-mini">Leading slash will be added automatically.</div>
                </div>

                <div style="margin-top:12px;">
                    <label>Parameters</label>
                    <div class="api-mini" style="margin-bottom:8px;">Use this table for key-value parameters. It will generate JSON automatically.</div>
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
                        <button class="btn small" type="button" id="tt-add-row">Add Param</button>
                        <button class="btn small" type="button" id="tt-sync-to-json">Update JSON</button>
                        <span class="api-mini">(Auto updates before submit)</span>
                    </div>
                </div>

                <div style="margin-top:12px;">
                    <label>Params JSON</label>
                    <textarea class="input api-code" name="params_json_pretty" id="api-explorer-params" rows="8" placeholder='{"page_size": 10}'>{{ old('params_json_pretty', '{}') }}</textarea>
                    <input type="hidden" name="params_json" id="api-explorer-params-hidden" value="{{ e(old('params_json', '{}')) }}" />
                    <div class="api-mini">For POST requests, this becomes the JSON body. For GET, these are added to query params.</div>
                </div>

                <div class="d-flex gap-8 mt-12">
                    <button class="btn" type="submit">Run</button>
                </div>
            </form>

            {{-- Preset Packs --}}
            <div style="margin-top:16px; padding-top:16px; border-top:1px solid var(--border);">
                <label>Preset Packs</label>
                <div class="api-mini" style="margin-bottom:8px;">Quick-run common endpoint groups.</div>
                <div class="d-flex gap-8" style="flex-wrap:wrap;">
                    @foreach(['shops' => 'Shops', 'products' => 'Products', 'orders' => 'Orders', 'logistics' => 'Logistics', 'finance' => 'Finance', 'full' => 'Full'] as $pack => $label)
                        <form method="POST" action="{{ route('ext.tiktok.packs_run') }}" style="display:inline;">
                            @csrf
                            <input type="hidden" name="pack" value="{{ $pack }}">
                            <button class="btn small secondary" type="submit">{{ $label }}</button>
                        </form>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
</div>

{{-- TAB 3 -- API LOGS --}}
<div id="tt-tab-logs" class="{{ !$hasResult ? 'hidden' : '' }}">

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
        <div>
            <span class="font-semibold text-sm">API Logging</span>
            <span class="hint" style="margin-left:8px;">Save API requests & responses for debugging.</span>
        </div>
        <form method="POST" action="{{ route('ext.tiktok.toggle_logging') }}">
            @csrf
            <label class="d-flex items-center gap-10" style="cursor:pointer;">
                <span style="position:relative; display:inline-block; width:44px; height:24px;">
                    <input type="hidden" name="api_logging" value="0">
                    <input type="checkbox" name="api_logging" value="1"
                           {{ ($setting->api_logging ?? true) ? 'checked' : '' }}
                           onchange="this.form.submit()"
                           style="position:absolute; opacity:0; width:0; height:0;">
                    <span style="position:absolute; inset:0; background:{{ ($setting->api_logging ?? true) ? 'var(--accent)' : 'var(--border)' }}; border-radius:12px; transition:background 0.2s;"></span>
                    <span style="position:absolute; top:2px; left:{{ ($setting->api_logging ?? true) ? '22px' : '2px' }}; width:20px; height:20px; background:#fff; border-radius:50%; transition:left 0.2s; box-shadow:0 1px 3px rgba(0,0,0,.2);"></span>
                </span>
                <span class="text-sm {{ ($setting->api_logging ?? true) ? 'font-bold' : 'text-muted' }}">
                    {{ ($setting->api_logging ?? true) ? 'Enabled' : 'Disabled' }}
                </span>
            </label>
        </form>
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
                <form method="POST" action="{{ route('ext.tiktok.clear_api_logs') }}" data-confirm="Delete all TikTok API logs?">
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
                                <td>{{ $log->method }}</td>
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
// Copy helper for cronjob commands
function ttCopy(id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    var text = el.textContent || el.innerText;
    navigator.clipboard.writeText(text).then(function() {
        var orig = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(function() { btn.textContent = orig; }, 1500);
    });
}

// Environment toggle
(function(){
    var toggle = document.getElementById('tt-mode-toggle');
    var knob = document.getElementById('tt-mode-knob');
    var label = document.getElementById('tt-mode-label');
    var input = document.getElementById('tt-mode-value');
    var form = document.getElementById('tt-mode-form');
    var envLive = document.getElementById('tt-env-live');
    var envSandbox = document.getElementById('tt-env-sandbox');
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

// Tab switching
(function(){
    var ids = ['tt-tab-settings', 'tt-tab-explorer', 'tt-tab-logs'];
    var tabs = document.querySelectorAll('[data-tab^="tt-tab-"]');
    tabs.forEach(function(btn){
        btn.addEventListener('click', function(){
            tabs.forEach(function(t){ t.classList.remove('active'); });
            btn.classList.add('active');
            ids.forEach(function(id){
                var el = document.getElementById(id);
                if (el) el.classList.toggle('hidden', id !== btn.dataset.tab);
            });
        });
    });
})();

// Explorer JS
(function(){
    function qs(sel){ return document.querySelector(sel); }

    var methodSel = qs('#api-explorer-method');
    var authChk   = qs('#api-explorer-auth');
    var pathInp   = qs('#api-explorer-path');
    var paramsTa  = qs('#api-explorer-params');
    var paramsHidden = qs('#api-explorer-params-hidden');
    var kvBody    = qs('#api-kv-body');
    var epName    = qs('#tt-ep-name');
    var epDesc    = qs('#tt-ep-desc');
    var epSearch  = qs('#api-endpoint-search');
    var btnAddRow = qs('#tt-add-row');
    var btnSync   = qs('#tt-sync-to-json');

    function safeJsonParse(str){
        try { return JSON.parse(str); } catch(e){ return null; }
    }

    function buildRow(key, value){
        var tr = document.createElement('tr');
        var keyTd = document.createElement('td');
        var keyInput = document.createElement('input');
        keyInput.className = 'input';
        keyInput.style.width = '100%';
        keyInput.value = key;
        keyInput.setAttribute('data-role', 'key');
        keyTd.appendChild(keyInput);

        var valTd = document.createElement('td');
        var valInput = document.createElement('input');
        valInput.className = 'input';
        valInput.style.width = '100%';
        valInput.value = String(value);
        valInput.setAttribute('data-role', 'value');
        valTd.appendChild(valInput);

        var btnTd = document.createElement('td');
        var removeBtn = document.createElement('button');
        removeBtn.className = 'btn small danger';
        removeBtn.type = 'button';
        removeBtn.textContent = 'X';
        removeBtn.addEventListener('click', function(){ tr.remove(); ttSyncToJson(); });
        btnTd.appendChild(removeBtn);

        tr.appendChild(keyTd);
        tr.appendChild(valTd);
        tr.appendChild(btnTd);
        return tr;
    }

    function clearRows(){
        if (kvBody) kvBody.textContent = '';
    }

    function tableToObject(){
        var obj = {};
        if (!kvBody) return obj;
        var rows = kvBody.querySelectorAll('tr');
        rows.forEach(function(tr){
            var kInp = tr.querySelector('[data-role="key"]');
            var vInp = tr.querySelector('[data-role="value"]');
            if (kInp && vInp) {
                var k = kInp.value.trim();
                if (k !== '') obj[k] = vInp.value;
            }
        });
        return obj;
    }

    window.ttSyncToJson = function(){
        var obj = tableToObject();
        if (paramsTa) paramsTa.value = JSON.stringify(obj, null, 2);
    };

    function rebuildTableFromJson(){
        var obj = safeJsonParse((paramsTa ? paramsTa.value : '') || '{}');
        if (!obj || typeof obj !== 'object') return;
        clearRows();
        Object.keys(obj).forEach(function(k){
            var v = obj[k];
            if (typeof v === 'object') v = JSON.stringify(v);
            kvBody.appendChild(buildRow(k, String(v)));
        });
    }

    function selectEndpoint(ep){
        if (methodSel) methodSel.value = ep.method || 'GET';
        if (authChk) authChk.checked = !!ep.auth;
        if (pathInp) pathInp.value = ep.path || '';
        if (epName) epName.textContent = ep.name || '';
        if (epDesc) epDesc.textContent = ep.desc || '';

        clearRows();
        var obj = {};
        (ep.params || []).forEach(function(p){
            var val = p.ph || '';
            obj[p.k] = val;
            kvBody.appendChild(buildRow(p.k, val));
        });
        if (paramsTa) paramsTa.value = JSON.stringify(obj, null, 2);
    }

    // Click on endpoint buttons
    document.querySelectorAll('.api-endpoint').forEach(function(btn){
        btn.addEventListener('click', function(){
            var ep = safeJsonParse(btn.getAttribute('data-ep'));
            if (ep) selectEndpoint(ep);
        });
    });

    // Search endpoints
    if (epSearch) {
        epSearch.addEventListener('input', function(){
            var q = epSearch.value.toLowerCase();
            document.querySelectorAll('.api-endpoint').forEach(function(btn){
                var text = btn.textContent.toLowerCase();
                btn.style.display = text.indexOf(q) >= 0 ? '' : 'none';
            });
        });
    }

    // Add row
    if (btnAddRow) {
        btnAddRow.addEventListener('click', function(){
            kvBody.appendChild(buildRow('', ''));
        });
    }

    // Sync to JSON
    if (btnSync) {
        btnSync.addEventListener('click', function(){
            ttSyncToJson();
        });
    }

    // Before form submit: sync table -> hidden param
    var form = qs('#tt-api-explorer-form');
    if (form) {
        form.addEventListener('submit', function(){
            ttSyncToJson();
            if (paramsHidden && paramsTa) {
                paramsHidden.value = paramsTa.value;
            }
        });
    }
})();
</script>
@endpush

@endsection
