@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Venta / Settings')

@section('title', 'Venta Settings')

@section('content')
    <div class="page-header">
        <div>
            <h2>Venta Settings</h2>
            <div class="text-muted text-sm">Manage Venta store connections and order status mapping.</div>
        </div>
        <div class="page-header-actions">
            <a href="{{ route('integrations.module', ['module' => 'venta']) }}" class="btn secondary">← Back to Venta</a>
        </div>
    </div>

    @php
        $singleStore = $singleStore ?? false;
        $activeStoreId = (int) request('store');
        if (!$activeStoreId && $stores->isNotEmpty()) {
            $activeStoreId = $stores->first()->id;
        }
        $activeTab = request('tab', 'settings');
    @endphp

    {{-- Store Selector + Content --}}
    <div class="oc-layout {{ $singleStore ? 'oc-layout-single' : '' }}">
        @unless($singleStore)
        {{-- Left: Store selector (hidden in single-store mode — store is selected from the module page) --}}
        <div class="oc-sidebar">
            <div class="oc-sidebar-label">Stores</div>
            @foreach($stores as $store)
                <button type="button" class="oc-sidebar-item{{ $store->id === $activeStoreId ? ' active' : '' }}" data-target="store-{{ $store->id }}" onclick="switchStore(this)">
                    <div class="oc-sidebar-icon" style="background:{{ $store->enabled ? 'linear-gradient(135deg,#3b82f6,#1d4ed8)' : '#94a3b8' }};">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    </div>
                    <div class="oc-sidebar-text">
                        <div class="oc-sidebar-name">{{ $store->store_name ?: 'Unnamed Store' }}</div>
                        <div class="oc-sidebar-url">{{ parse_url($store->base_url, PHP_URL_HOST) ?: $store->base_url }}</div>
                    </div>
                    @if($store->enabled)
                        <span class="oc-sidebar-dot active" title="Active"></span>
                    @else
                        <span class="oc-sidebar-dot" title="Disabled"></span>
                    @endif
                </button>
            @endforeach
            <button type="button" class="oc-sidebar-item{{ $stores->isEmpty() ? ' active' : '' }}" data-target="store-new" onclick="switchStore(this)">
                <div class="oc-sidebar-icon" style="background:linear-gradient(135deg,#22c55e,#16a34a);">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </div>
                <div class="oc-sidebar-text">
                    <div class="oc-sidebar-name">Add New Store</div>
                </div>
            </button>
        </div>
        @endunless

        {{-- Right: Store content panels --}}
        <div class="oc-content">
            @foreach($stores as $store)
                <div class="oc-panel{{ $store->id === $activeStoreId ? ' active' : '' }}" id="store-{{ $store->id }}">
                    <div class="card">
                        {{-- Store header --}}
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px;">
                            <div>
                                <h3 style="margin:0; font-size:16px; font-weight:700;">
                                    <span class="text-muted" style="font-weight:500; font-size:13px;">#{{ $store->id }}</span>
                                    {{ $store->store_name ?: 'Unnamed Store' }}
                                </h3>
                                <div class="text-muted text-sm" style="margin-top:1px;">{{ $store->base_url }}</div>
                            </div>
                            <div style="display:flex; align-items:center; gap:8px;">
                                @if($store->enabled)
                                    <span class="badge badge-green" style="font-size:11px;">Active</span>
                                @else
                                    <span class="badge badge-gray" style="font-size:11px;">Disabled</span>
                                @endif
                            </div>
                        </div>

                        {{-- Tabs --}}
                        <div class="venta-tabs" data-store="{{ $store->id }}">
                            <div class="tab-nav" style="display:flex; border-bottom:2px solid var(--border-light); margin-bottom:16px; gap:0;">
                                <button type="button" class="tab-btn active" data-tab="tab-settings-{{ $store->id }}" onclick="switchTab(this)">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                                    Settings
                                </button>
                                <button type="button" class="tab-btn" data-tab="tab-mapping-{{ $store->id }}" onclick="switchTab(this)">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/></svg>
                                    Status Mapping
                                </button>
                                <button type="button" class="tab-btn" data-tab="tab-cron-{{ $store->id }}" onclick="switchTab(this)">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    Cronjob
                                </button>
                                <button type="button" class="tab-btn" data-tab="tab-api-logs-{{ $store->id }}" onclick="switchTab(this)">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                                    API Logs
                                </button>
                                <button type="button" class="tab-btn" data-tab="tab-logs-{{ $store->id }}" onclick="switchTab(this)">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                    Sync Logs
                                </button>
                            </div>

                            {{-- Tab: Settings --}}
                            <div class="tab-pane active" id="tab-settings-{{ $store->id }}">
                                {{-- Last Sync Summary --}}
                                @if($store->enabled)
                                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:10px; margin-bottom:20px;">
                                        <div class="oc-stat-card">
                                            <div class="oc-stat-label">Last Order Pull</div>
                                            <div class="oc-stat-value">{{ $store->last_order_sync_at ? $store->last_order_sync_at->format('M d, H:i') : '—' }}</div>
                                        </div>
                                        <div class="oc-stat-card">
                                            <div class="oc-stat-label">Last Product Push</div>
                                            <div class="oc-stat-value">{{ $store->last_product_sync_at ? $store->last_product_sync_at->format('M d, H:i') : '—' }}</div>
                                        </div>
                                        <div class="oc-stat-card">
                                            <div class="oc-stat-label">Last Stock Push</div>
                                            <div class="oc-stat-value">{{ $store->last_stock_push_at ? $store->last_stock_push_at->format('M d, H:i') : '—' }}</div>
                                        </div>
                                    </div>
                                @endif

                                <form method="POST" action="{{ route('ext.venta.save') }}">
                                    @csrf
                                    <input type="hidden" name="store_id" value="{{ $store->id }}">

                                    <div class="form-grid">
                                        <div>
                                            <label>Store Name</label>
                                            <input type="text" name="store_name" class="input" value="{{ $store->store_name }}" placeholder="e.g. My Venta Store">
                                        </div>
                                        <div>
                                            <label class="required">Base URL</label>
                                            <input type="text" name="base_url" class="input" value="{{ $store->base_url }}" placeholder="https://store.example.com">
                                        </div>
                                        <div class="full">
                                            <label class="required">API Token</label>
                                            <input type="password" name="api_token" class="input" value="{{ $store->api_token }}">
                                            <div class="hint">The API token used to authenticate with your Venta store.</div>
                                        </div>
                                        <div>
                                            <label>Warehouse ID</label>
                                            <input type="number" name="warehouse_id" class="input" value="{{ $store->warehouse_id }}" placeholder="Optional" min="1">
                                            <div class="hint">Link to a specific warehouse for stock operations.</div>
                                        </div>
                                        <div>
                                            <label>Sync Last N Days</label>
                                            <div style="display:flex; align-items:center; gap:6px;">
                                                <input type="number" name="sync_last_days" class="input" value="{{ $store->sync_last_days }}" placeholder="e.g. 30" min="1" max="365" style="width:100px;">
                                                <span class="text-muted text-xs">days</span>
                                            </div>
                                            <div class="hint">Rolling window — takes priority over fixed date.</div>
                                        </div>
                                        <div>
                                            <label>Sync Orders From</label>
                                            <input type="date" name="sync_orders_from" class="input" value="{{ $store->sync_orders_from ? $store->sync_orders_from->format('Y-m-d') : '' }}" style="width:180px;">
                                            <div class="hint">Fixed cutoff date — only orders after this date will be synced. Used as fallback when "Sync Last N Days" is not set.</div>
                                        </div>
                                        <div>
                                            <label class="d-flex items-center gap-8" style="margin-bottom:0;">
                                                <input type="checkbox" name="enabled" value="1" {{ $store->enabled ? 'checked' : '' }}>
                                                <span>Enabled</span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="d-flex gap-8 mt-16 items-center">
                                        <button class="btn" type="submit">Save</button>
                                        <button class="btn secondary" type="button" onclick="ventaTestStore({{ $store->id }}, this)">Test Connection</button>
                                        <div class="test-result" data-store="{{ $store->id }}" style="display:none; flex:1;"></div>
                                    </div>
                                </form>

                                <div style="margin-top:12px; padding-top:12px; border-top:1px solid var(--border-light); display:flex; justify-content:flex-end;">
                                    <form method="POST" action="{{ route('ext.venta.destroy', $store->id) }}" style="display:inline;" data-confirm="Delete this store and all its product links? This cannot be undone.">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn small danger" type="submit">Delete Store</button>
                                    </form>
                                </div>
                            </div>

                            {{-- Tab: Status Mapping --}}
                            <div class="tab-pane" id="tab-mapping-{{ $store->id }}">
                                @if(!$store->enabled)
                                    <div style="padding:20px; background:var(--surface-alt); border-radius:var(--radius-sm); border:1px solid var(--border-light); text-align:center;">
                                        <div class="text-muted" style="font-size:13px;">Enable this store in Settings to configure status mapping.</div>
                                    </div>
                                @else
                                    <div class="hint" style="margin-bottom:12px;">
                                        Map Venta order statuses to ERP statuses. Click "Fetch Statuses" to load available statuses from the Venta store, then select the corresponding ERP status for each.
                                    </div>

                                    <div style="margin-bottom:12px;">
                                        <button class="btn small secondary venta-fetch-statuses-btn" type="button" data-store="{{ $store->id }}">
                                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                                            Fetch Statuses from Venta
                                        </button>
                                    </div>

                                    <div class="venta-status-map-table" data-store="{{ $store->id }}">
                                        @php $storeMap = $orderStatusMaps->get($store->id, collect()); @endphp
                                        @if($storeMap->isNotEmpty())
                                            <table class="table" style="margin-bottom:12px;">
                                                <thead>
                                                <tr>
                                                    <th style="width:80px;">Venta ID</th>
                                                    <th>Venta Status Name</th>
                                                    <th style="width:30px; text-align:center;">&rarr;</th>
                                                    <th>ERP Status</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                @foreach($storeMap as $map)
                                                    <tr>
                                                        <td style="font-variant-numeric:tabular-nums;">{{ $map->venta_status_id }}</td>
                                                        <td>{{ $map->venta_status_name }}</td>
                                                        <td style="text-align:center; color:var(--text-muted);">&rarr;</td>
                                                        <td>
                                                            <select class="input venta-map-select" data-venta-id="{{ $map->venta_status_id }}" data-venta-name="{{ $map->venta_status_name }}" style="width:100%;">
                                                                <option value="0">&mdash; Unmapped (use raw ID) &mdash;</option>
                                                                @foreach($erpOrderStatuses as $erp)
                                                                    <option value="{{ $erp->order_status_id }}" {{ $map->order_status_id == $erp->order_status_id ? 'selected' : '' }}>{{ $erp->name }} (ID: {{ $erp->order_status_id }})</option>
                                                                @endforeach
                                                            </select>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        @else
                                            <div class="text-muted text-sm" style="padding:12px; background:var(--surface); border:1px dashed var(--border); border-radius:var(--radius-sm); text-align:center;">
                                                No status mappings yet. Click "Fetch Statuses" to load from Venta.
                                            </div>
                                        @endif
                                    </div>

                                    @if($storeMap->isNotEmpty())
                                        <button class="btn small venta-save-status-map-btn" type="button" data-store="{{ $store->id }}">Save Mapping</button>
                                    @endif
                                @endif
                            </div>

                            {{-- Tab: Cronjob --}}
                            <div class="tab-pane" id="tab-cron-{{ $store->id }}">
                                @if(!$store->enabled)
                                    <div style="padding:20px; background:var(--surface-alt); border-radius:var(--radius-sm); border:1px solid var(--border-light); text-align:center;">
                                        <div class="text-muted" style="font-size:13px;">Enable this store in Settings to set up cronjobs.</div>
                                    </div>
                                @else
                                    <div class="hint" style="margin-bottom:10px;">
                                        Add the following commands to your hosting panel's cronjob scheduler.
                                    </div>

                                    @php $artisanPath = '/usr/bin/php ' . base_path('artisan'); @endphp

                                    <div style="display:flex; flex-direction:column; gap:8px;">
                                        <div>
                                            <div class="text-xs text-muted" style="margin-bottom:4px;">Pull Orders</div>
                                            <div class="oc-cron-box">
                                                <code id="venta-cron-orders-{{ $store->id }}">{{ $artisanPath }} venta:sync orders --store={{ $store->id }}</code>
                                                <button type="button" class="btn small secondary oc-copy-btn" onclick="ventaCopyCron('venta-cron-orders-{{ $store->id }}')" title="Copy to clipboard">
                                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                                    Copy
                                                </button>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-xs text-muted" style="margin-bottom:4px;">Push Stock — every 15 min</div>
                                            <div class="oc-cron-box">
                                                <code id="venta-cron-stock-{{ $store->id }}">{{ $artisanPath }} venta:push-stock --store={{ $store->id }}</code>
                                                <button type="button" class="btn small secondary oc-copy-btn" onclick="ventaCopyCron('venta-cron-stock-{{ $store->id }}')" title="Copy to clipboard">
                                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                                    Copy
                                                </button>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-xs text-muted" style="margin-bottom:4px;">Push Reviews — daily</div>
                                            <div class="oc-cron-box">
                                                <code id="venta-cron-reviews-{{ $store->id }}">{{ $artisanPath }} venta:push-reviews --store={{ $store->id }}</code>
                                                <button type="button" class="btn small secondary oc-copy-btn" onclick="ventaCopyCron('venta-cron-reviews-{{ $store->id }}')" title="Copy to clipboard">
                                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                                    Copy
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="oc-cron-help">
                                        <div class="oc-cron-help-title">How to set up</div>
                                        <ol>
                                            <li>Go to your hosting panel (HestiaCP, cPanel, etc.)</li>
                                            <li>Find <strong>Cron Jobs</strong> or <strong>Scheduled Tasks</strong></li>
                                            <li>Set the interval (e.g. every 5 minutes: <code>*/5 * * * *</code>)</li>
                                            <li>Paste the command above into the command field</li>
                                            <li>Save the cronjob</li>
                                        </ol>
                                    </div>
                                @endif
                            </div>

                            {{-- Tab: API Logs --}}
                            <div class="tab-pane" id="tab-api-logs-{{ $store->id }}">
                                <div class="d-flex items-center justify-between mb-12" style="flex-wrap:wrap; gap:8px;">
                                    <form method="POST" action="{{ route('ext.venta.toggle_api_logging', $store->id) }}">
                                        @csrf
                                        <label class="d-flex items-center gap-8" style="cursor:pointer;">
                                            <button type="submit" class="btn small {{ $store->api_logging ? 'danger' : '' }}">
                                                {{ $store->api_logging ? 'Disable Logging' : 'Enable Logging' }}
                                            </button>
                                            <span class="text-xs {{ $store->api_logging ? 'text-success' : 'text-muted' }}">
                                                Logging is {{ $store->api_logging ? 'ON' : 'OFF' }}
                                            </span>
                                        </label>
                                    </form>
                                    <form method="POST" action="{{ route('ext.venta.clear_api_logs', $store->id) }}" data-confirm="Delete all API logs for this store?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn small danger">Delete All Logs</button>
                                    </form>
                                </div>

                                @php $storeApiLogs = ($apiLogs ?? collect())->where('venta_setting_id', $store->id); @endphp
                                @if($storeApiLogs->count() > 0)
                                    <div class="table-wrap">
                                        <table class="table">
                                            <thead>
                                            <tr>
                                                <th>Time</th>
                                                <th style="width:60px;">Method</th>
                                                <th>Endpoint</th>
                                                <th style="width:60px;">Status</th>
                                                <th style="width:70px;">Time (ms)</th>
                                                <th style="width:50px;">OK</th>
                                                <th>Response</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            @foreach($storeApiLogs->take(30) as $alog)
                                                @php
                                                    $reqJson = $alog->request_body ? json_encode($alog->request_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null;
                                                    $resJson = $alog->response_body ? json_encode($alog->response_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null;
                                                @endphp
                                                <tr class="api-log-row" style="cursor:pointer;" onclick="this.nextElementSibling.classList.toggle('hidden')">
                                                    <td style="white-space:nowrap; font-size:12px;">{{ $alog->created_at->format('M d, H:i:s') }}</td>
                                                    <td>
                                                        @if($alog->method === 'GET')
                                                            <span class="badge badge-blue">{{ $alog->method }}</span>
                                                        @elseif($alog->method === 'POST')
                                                            <span class="badge badge-green">{{ $alog->method }}</span>
                                                        @else
                                                            <span class="badge badge-yellow">{{ $alog->method }}</span>
                                                        @endif
                                                    </td>
                                                    <td style="font-size:12px; font-family:monospace; word-break:break-all;">{{ $alog->endpoint }}</td>
                                                    <td style="font-variant-numeric:tabular-nums; {{ $alog->status_code >= 400 ? 'color:var(--danger); font-weight:600;' : '' }}">{{ $alog->status_code }}</td>
                                                    <td style="font-variant-numeric:tabular-nums;">{{ $alog->response_time_ms }}</td>
                                                    <td>
                                                        @if($alog->ok)
                                                            <span class="badge badge-green">OK</span>
                                                        @else
                                                            <span class="badge badge-red">FAIL</span>
                                                        @endif
                                                    </td>
                                                    <td style="font-size:11px;">
                                                        @if(!$alog->ok && $alog->response_body)
                                                            <span style="color:var(--danger);">{{ \Illuminate\Support\Str::limit(json_encode($alog->response_body), 60) }}</span>
                                                        @else
                                                            <span class="text-muted">click to expand</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                                <tr class="hidden">
                                                    <td colspan="7" style="padding:0; border-top:none;">
                                                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; padding:12px 16px; background:var(--surface-alt); border-top:1px dashed var(--border-light);">
                                                            <div>
                                                                <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:6px;">Request Body</div>
                                                                @if($reqJson)
                                                                    <pre style="font-size:11px; line-height:1.5; margin:0; padding:10px; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-sm); overflow-x:auto; max-height:300px; white-space:pre-wrap; word-break:break-word;">{{ $reqJson }}</pre>
                                                                @else
                                                                    <div class="text-muted text-xs" style="padding:10px; background:var(--surface); border:1px dashed var(--border); border-radius:var(--radius-sm);">No request body (GET request)</div>
                                                                @endif
                                                            </div>
                                                            <div>
                                                                <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:6px;">Response Body</div>
                                                                @if($resJson)
                                                                    <pre style="font-size:11px; line-height:1.5; margin:0; padding:10px; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-sm); overflow-x:auto; max-height:300px; white-space:pre-wrap; word-break:break-word; {{ !$alog->ok ? 'color:var(--danger);' : '' }}">{{ $resJson }}</pre>
                                                                @else
                                                                    <div class="text-muted text-xs" style="padding:10px; background:var(--surface); border:1px dashed var(--border); border-radius:var(--radius-sm);">Empty response</div>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @else
                                    <div class="text-muted text-sm" style="padding:20px; background:var(--surface); border:1px dashed var(--border); border-radius:var(--radius-sm); text-align:center;">
                                        No API logs yet for this store.
                                    </div>
                                @endif
                            </div>

                            {{-- Tab: Sync Logs --}}
                            <div class="tab-pane" id="tab-logs-{{ $store->id }}">
                                @php $storeLogs = $recentLogs->where('venta_setting_id', $store->id); @endphp
                                @if($storeLogs->count() > 0)
                                    <div class="table-wrap">
                                        <table class="table">
                                            <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Entity</th>
                                                <th>Direction</th>
                                                <th>Status</th>
                                                <th style="text-align:right;">Processed</th>
                                                <th style="text-align:right;">Created</th>
                                                <th style="text-align:right;">Updated</th>
                                                <th style="text-align:right;">Failed</th>
                                                <th>Duration</th>
                                                <th>Error</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            @foreach($storeLogs->take(15) as $log)
                                                <tr>
                                                    <td style="white-space:nowrap;">{{ $log->started_at ? $log->started_at->format('M d, H:i') : $log->created_at->format('M d, H:i') }}</td>
                                                    <td><span class="badge badge-default">{{ $log->entity_type }}</span></td>
                                                    <td>
                                                        @if($log->direction === 'pull')
                                                            <span class="badge badge-blue">pull</span>
                                                        @else
                                                            <span class="badge badge-yellow">push</span>
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if($log->status === 'completed')
                                                            <span class="badge badge-green">completed</span>
                                                        @elseif($log->status === 'failed')
                                                            <span class="badge badge-red">failed</span>
                                                        @else
                                                            <span class="badge">{{ $log->status }}</span>
                                                        @endif
                                                    </td>
                                                    <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ $log->records_processed }}</td>
                                                    <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ $log->records_created }}</td>
                                                    <td style="text-align:right; font-variant-numeric:tabular-nums;">{{ $log->records_updated }}</td>
                                                    <td style="text-align:right; font-variant-numeric:tabular-nums; {{ $log->records_failed > 0 ? 'color:var(--danger); font-weight:600;' : '' }}">{{ $log->records_failed }}</td>
                                                    <td>
                                                        @if($log->started_at && $log->completed_at)
                                                            {{ $log->started_at->diffInSeconds($log->completed_at) }}s
                                                        @else
                                                            &mdash;
                                                        @endif
                                                    </td>
                                                    <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="{{ $log->error_message }}">
                                                        @if($log->error_message)
                                                            <span style="color:var(--danger);">{{ \Illuminate\Support\Str::limit($log->error_message, 60) }}</span>
                                                        @else
                                                            &mdash;
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @else
                                    <div class="text-muted text-sm" style="padding:20px; background:var(--surface); border:1px dashed var(--border); border-radius:var(--radius-sm); text-align:center;">
                                        No sync logs yet for this store.
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach

            {{-- Add New Store panel — hidden in single-store mode (use the modal on the module page) --}}
            <div class="oc-panel{{ $stores->isEmpty() ? ' active' : '' }} {{ $singleStore ? 'd-none' : '' }}" id="store-new">
                <div class="card">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:16px;">
                        <div style="width:36px; height:36px; border-radius:8px; background:linear-gradient(135deg,#22c55e,#16a34a); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        </div>
                        <div>
                            <h3 style="margin:0; font-size:16px; font-weight:700;">Add New Store</h3>
                            <div class="text-muted text-sm">Connect a new Venta store to the ERP.</div>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('ext.venta.save') }}">
                        @csrf

                        <div class="form-grid">
                            <div>
                                <label>Store Name</label>
                                <input type="text" name="store_name" class="input" value="" placeholder="e.g. My Venta Store">
                            </div>
                            <div>
                                <label class="required">Base URL</label>
                                <input type="text" name="base_url" class="input" value="" placeholder="https://store.example.com">
                            </div>
                            <div class="full">
                                <label class="required">API Token</label>
                                <input type="text" name="api_token" class="input" value="" placeholder="Your API token">
                                <div class="hint">The token used to authenticate requests to the Venta API.</div>
                            </div>
                            <div>
                                <label>Warehouse ID</label>
                                <input type="number" name="warehouse_id" class="input" value="" placeholder="Optional" min="1">
                                <div class="hint">Link to a specific warehouse for stock operations.</div>
                            </div>
                            <div>
                                <label>Sync Last N Days</label>
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <input type="number" name="sync_last_days" class="input" value="30" placeholder="e.g. 30" min="1" max="365" style="width:100px;">
                                    <span class="text-muted text-xs">days</span>
                                </div>
                            </div>
                            <div>
                                <label class="d-flex items-center gap-8" style="margin-bottom:0;">
                                    <input type="checkbox" name="enabled" value="1" checked>
                                    <span>Enabled</span>
                                </label>
                            </div>
                        </div>

                        <div class="d-flex gap-8 mt-16 items-center">
                            <button class="btn" type="submit">Add Store</button>
                            <button class="btn secondary" type="button" onclick="ventaTestNewStore(this)">Test Connection</button>
                            <div class="test-result" data-store="new" style="display:none; flex:1;"></div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

@push('scripts')
<script>
    /* Store switcher */
    function switchStore(btn) {
        var target = btn.getAttribute('data-target');
        document.querySelectorAll('.oc-sidebar-item').forEach(function(item) {
            item.classList.remove('active');
        });
        btn.classList.add('active');
        document.querySelectorAll('.oc-panel').forEach(function(panel) {
            panel.classList.remove('active');
        });
        var panel = document.getElementById(target);
        if (panel) panel.classList.add('active');
    }

    /* Tab switcher */
    function switchTab(btn) {
        var tabId = btn.getAttribute('data-tab');
        var tabContainer = btn.closest('.venta-tabs');

        tabContainer.querySelectorAll('.tab-btn').forEach(function(b) {
            b.classList.remove('active');
        });
        btn.classList.add('active');

        tabContainer.querySelectorAll('.tab-pane').forEach(function(p) {
            p.classList.remove('active');
        });
        var pane = document.getElementById(tabId);
        if (pane) pane.classList.add('active');
    }

    /* Copy cron command */
    function ventaCopyCron(id) {
        var el = document.getElementById(id);
        if (!el) return;
        var text = el.textContent || el.innerText;
        navigator.clipboard.writeText(text.trim()).then(function() {
            showFlashSuccess('Copied to clipboard.');
        });
    }

    /* Test existing store */
    function ventaTestStore(storeId, btn) {
        var resultEl = document.querySelector('.test-result[data-store="' + storeId + '"]');
        resultEl.style.display = 'block';
        resultEl.textContent = 'Testing...';
        resultEl.style.color = 'var(--text-muted)';
        btn.disabled = true;

        fetch(@json(route('ext.venta.test')), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ store_id: storeId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.ok) {
                resultEl.textContent = 'Connection successful.';
                resultEl.style.color = 'var(--success)';
            } else {
                resultEl.textContent = 'Failed: ' + (data.error || 'Unknown error');
                resultEl.style.color = 'var(--danger)';
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            resultEl.textContent = 'Error: ' + err.message;
            resultEl.style.color = 'var(--danger)';
        });
    }

    /* Test new store */
    function ventaTestNewStore(btn) {
        var form = btn.closest('form');
        var baseUrl = form.querySelector('[name="base_url"]').value;
        var apiToken = form.querySelector('[name="api_token"]').value;
        var resultEl = document.querySelector('.test-result[data-store="new"]');

        if (!baseUrl || !apiToken) {
            resultEl.style.display = 'block';
            resultEl.textContent = 'Base URL and API Token are required.';
            resultEl.style.color = 'var(--danger)';
            return;
        }

        resultEl.style.display = 'block';
        resultEl.textContent = 'Testing...';
        resultEl.style.color = 'var(--text-muted)';
        btn.disabled = true;

        fetch(@json(route('ext.venta.test')), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ base_url: baseUrl, api_token: apiToken })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.ok) {
                resultEl.textContent = 'Connection successful.';
                resultEl.style.color = 'var(--success)';
            } else {
                resultEl.textContent = 'Failed: ' + (data.error || 'Unknown error');
                resultEl.style.color = 'var(--danger)';
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            resultEl.textContent = 'Error: ' + err.message;
            resultEl.style.color = 'var(--danger)';
        });
    }

    /* Fetch Venta statuses */
    document.querySelectorAll('.venta-fetch-statuses-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var storeId = btn.getAttribute('data-store');
            btn.disabled = true;
            btn.textContent = 'Fetching...';

            fetch(@json(route('ext.venta.fetch_statuses')), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ store_id: storeId })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                btn.textContent = 'Fetch Statuses from Venta';

                if (data.error) {
                    showFlashError(data.error);
                    return;
                }

                var statuses = data.statuses || [];
                if (statuses.length === 0) {
                    showFlashError('No statuses returned from Venta.');
                    return;
                }

                var container = document.querySelector('.venta-status-map-table[data-store="' + storeId + '"]');
                var erpOptions = @json($erpOrderStatuses->map(fn($s) => ['id' => $s->order_status_id, 'name' => $s->name]));

                container.textContent = '';

                var table = document.createElement('table');
                table.className = 'table';
                table.style.marginBottom = '12px';

                var thead = document.createElement('thead');
                var headerRow = document.createElement('tr');
                ['Venta ID', 'Venta Status Name', '\u2192', 'ERP Status'].forEach(function(text, idx) {
                    var th = document.createElement('th');
                    th.textContent = text;
                    if (idx === 0) th.style.width = '80px';
                    if (idx === 2) { th.style.width = '30px'; th.style.textAlign = 'center'; }
                    headerRow.appendChild(th);
                });
                thead.appendChild(headerRow);
                table.appendChild(thead);

                var tbody = document.createElement('tbody');
                statuses.forEach(function(s) {
                    var sid = s.id || s.order_status_id || 0;
                    var sname = s.name || s.label || '';
                    // Existing mapping to pre-select (preserved across fetches).
                    var mappedErpId = parseInt(s.order_status_id || 0, 10);
                    var tr = document.createElement('tr');

                    var td1 = document.createElement('td');
                    td1.style.fontVariantNumeric = 'tabular-nums';
                    td1.textContent = sid;
                    tr.appendChild(td1);

                    var td2 = document.createElement('td');
                    td2.textContent = sname;
                    tr.appendChild(td2);

                    var td3 = document.createElement('td');
                    td3.style.textAlign = 'center';
                    td3.style.color = 'var(--text-muted)';
                    td3.textContent = '\u2192';
                    tr.appendChild(td3);

                    var td4 = document.createElement('td');
                    var select = document.createElement('select');
                    select.className = 'input venta-map-select';
                    select.setAttribute('data-venta-id', sid);
                    select.setAttribute('data-venta-name', sname);
                    select.style.width = '100%';

                    var defaultOpt = document.createElement('option');
                    defaultOpt.value = '0';
                    defaultOpt.textContent = '\u2014 Unmapped (use raw ID) \u2014';
                    if (mappedErpId === 0) defaultOpt.selected = true;
                    select.appendChild(defaultOpt);

                    erpOptions.forEach(function(erp) {
                        var opt = document.createElement('option');
                        opt.value = erp.id;
                        opt.textContent = erp.name + ' (ID: ' + erp.id + ')';
                        if (parseInt(erp.id, 10) === mappedErpId) opt.selected = true;
                        select.appendChild(opt);
                    });

                    td4.appendChild(select);
                    tr.appendChild(td4);
                    tbody.appendChild(tr);
                });
                table.appendChild(tbody);
                container.appendChild(table);

                var section = container.closest('.tab-pane');
                if (!section.querySelector('.venta-save-status-map-btn')) {
                    var saveBtn = document.createElement('button');
                    saveBtn.className = 'btn small venta-save-status-map-btn';
                    saveBtn.type = 'button';
                    saveBtn.setAttribute('data-store', storeId);
                    saveBtn.textContent = 'Save Mapping';
                    section.appendChild(saveBtn);
                    bindSaveStatusMap(saveBtn);
                }

                showFlashSuccess('Fetched ' + statuses.length + ' statuses.');
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.textContent = 'Fetch Statuses from Venta';
                showFlashError('Fetch failed: ' + err.message);
            });
        });
    });

    /* Save status mapping */
    function bindSaveStatusMap(btn) {
        btn.addEventListener('click', function() {
            var storeId = btn.getAttribute('data-store');
            var selects = document.querySelectorAll('.venta-status-map-table[data-store="' + storeId + '"] .venta-map-select');
            var mappings = [];
            selects.forEach(function(sel) {
                mappings.push({
                    venta_status_id: sel.getAttribute('data-venta-id'),
                    venta_status_name: sel.getAttribute('data-venta-name'),
                    order_status_id: sel.value
                });
            });

            btn.disabled = true;
            btn.textContent = 'Saving...';

            fetch(@json(route('ext.venta.save_status_map')), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ store_id: storeId, mappings: mappings })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                btn.textContent = 'Save Mapping';
                if (data.status) {
                    showFlashSuccess(data.status);
                } else if (data.error) {
                    showFlashError(data.error);
                }
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.textContent = 'Save Mapping';
                showFlashError('Save failed: ' + err.message);
            });
        });
    }

    document.querySelectorAll('.venta-save-status-map-btn').forEach(bindSaveStatusMap);
</script>
@endpush
@endsection
