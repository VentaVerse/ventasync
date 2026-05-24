@extends('layouts.app')
@section('breadcrumb', 'Integrations / OpenCart / Settings')

@section('title', 'OpenCart Settings')

@section('content')
@php
    // Phase 4 (view/manage tier split): operators with only `view_opencart`
    // get here through the read-tier route gate. Save/Test/Delete/Sync/Push
    // actions are hidden; routes still 403 if probed directly.
    $canManageOpencart = auth()->user()?->hasPermission('manage_opencart') ?? false;
@endphp
    <div class="page-header">
        <div>
            <h2>OpenCart Settings</h2>
            <div class="text-muted text-sm">Manage store connections, order sync, and cronjob setup.</div>
        </div>
        <div class="page-header-actions">
            <a href="{{ route('integrations.module', ['module' => 'opencart']) }}" class="btn secondary">← Back to OpenCart</a>
        </div>
    </div>

    @unless($canManageOpencart)
    <div class="alert" style="background:#fef3c7; color:#92400e; border:1px solid #fcd34d; padding:10px 14px; border-radius:var(--radius-md); margin-bottom:16px; font-size:13px;">
        <strong>Read-only view.</strong> You can browse OpenCart store settings and mappings. To edit connections, run syncs, or push products, your user group needs the <code>manage_opencart</code> permission tier.
    </div>
    @endunless

    @php
        $singleStore = $singleStore ?? false;
        $activeStoreId = (int) request('store');
        if (!$activeStoreId && $stores->isNotEmpty()) {
            $activeStoreId = $stores->first()->id;
        }
    @endphp

    {{-- ─── Store Selector + Content ─── --}}
    <div class="oc-layout {{ $singleStore ? 'oc-layout-single' : '' }}">
        @unless($singleStore)
        {{-- Left: Store selector (hidden in single-store mode — store is selected from the module page) --}}
        <div class="oc-sidebar">
            <div class="oc-sidebar-label">Stores</div>
            @foreach($stores as $i => $store)
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
            @foreach($stores as $i => $store)
                <div class="oc-panel{{ $store->id === $activeStoreId ? ' active' : '' }}" id="store-{{ $store->id }}">
                    <div class="card">
                        {{-- Store header --}}
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px;">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <div>
                                    <h3 style="margin:0; font-size:16px; font-weight:700;">
                                        <span class="text-muted" style="font-weight:500; font-size:13px;">#{{ $store->id }}</span>
                                        {{ $store->store_name ?: 'Unnamed Store' }}
                                    </h3>
                                    <div class="text-muted text-sm" style="margin-top:1px;">{{ $store->base_url }}</div>
                                </div>
                            </div>
                            <div style="display:flex; align-items:center; gap:8px;">
                                @if($store->enabled)
                                    <span class="badge badge-green" style="font-size:11px;">Active</span>
                                @else
                                    <span class="badge badge-gray" style="font-size:11px;">Disabled</span>
                                @endif
                                <button class="btn small secondary" type="button" onclick="this.closest('.card').querySelector('.store-settings').classList.toggle('hidden')">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                                    Settings
                                </button>
                            </div>
                        </div>

                        {{-- Connection settings (collapsed by default) --}}
                        <div class="store-settings hidden" style="border-top:1px solid var(--border-light); padding-top:16px; margin-bottom:20px;">
                            <form method="POST" action="{{ route('ext.opencart.save') }}">
                                @csrf
                                <input type="hidden" name="store_id" value="{{ $store->id }}">

                                <div class="form-grid">
                                    <div>
                                        <label>Store Name</label>
                                        <input type="text" name="store_name" class="input" value="{{ $store->store_name }}" placeholder="e.g. My Store">
                                    </div>
                                    <div>
                                        <label class="required">Base URL</label>
                                        <input type="text" name="base_url" class="input" value="{{ $store->base_url }}" placeholder="https://store.example.com">
                                    </div>
                                    <div class="full">
                                        <label class="required">API Key</label>
                                        <input type="password" name="api_key" class="input" value="{{ $store->api_key }}">
                                        <div class="hint">Must match the key in OpenCart's ERP Sync module.</div>
                                    </div>
                                    <div>
                                        <label class="d-flex items-center gap-8" style="margin-bottom:0;">
                                            <input type="checkbox" name="enabled" value="1" {{ $store->enabled ? 'checked' : '' }}>
                                            <span>Enabled</span>
                                        </label>
                                    </div>
                                </div>

                                @if($canManageOpencart)
                                <div class="d-flex gap-8 mt-16 items-center">
                                    <button class="btn" type="submit">Save</button>
                                    <button class="btn secondary" type="button" onclick="testStore({{ $store->id }}, this)">Test Connection</button>
                                    <div class="test-result" data-store="{{ $store->id }}" style="display:none; flex:1;"></div>
                                </div>
                                @endif
                            </form>

                            @if($canManageOpencart)
                            <div style="margin-top:12px; padding-top:12px; border-top:1px solid var(--border-light); display:flex; justify-content:flex-end;">
                                <form method="POST" action="{{ route('ext.opencart.destroy', $store->id) }}" style="display:inline;" data-confirm="Delete this store and all its product links? This cannot be undone.">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn small danger" type="submit">Delete Store</button>
                                </form>
                            </div>
                            @endif
                        </div>

                        @if($store->enabled)
                            {{-- ── Last Sync Summary ── --}}
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
                                    <div class="oc-stat-label">Categories</div>
                                    <div class="oc-stat-value">{{ $store->last_category_sync_at ? $store->last_category_sync_at->format('M d, H:i') : '—' }}</div>
                                </div>
                                <div class="oc-stat-card">
                                    <div class="oc-stat-label">Manufacturers</div>
                                    <div class="oc-stat-value">{{ $store->last_manufacturer_sync_at ? $store->last_manufacturer_sync_at->format('M d, H:i') : '—' }}</div>
                                </div>
                                <div class="oc-stat-card">
                                    <div class="oc-stat-label">Last Review Push</div>
                                    <div class="oc-stat-value">{{ $store->last_review_push_at ? $store->last_review_push_at->format('M d, H:i') : '—' }}</div>
                                </div>
                            </div>

                            {{-- ── Order Sync Section ── --}}
                            <div class="oc-section">
                                <div class="oc-section-header">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                                    Order Sync
                                </div>

                                <form method="POST" action="{{ route('ext.opencart.sync_date') }}">
                                    @csrf
                                    <input type="hidden" name="store_id" value="{{ $store->id }}">

                                    <div style="display:flex; align-items:end; gap:16px; flex-wrap:wrap;">
                                        <div>
                                            <label class="text-xs text-muted">Sync Last N Days</label>
                                            <div style="display:flex; align-items:center; gap:6px;">
                                                <input type="number" name="sync_last_days" class="input" value="{{ $store->sync_last_days }}" placeholder="e.g. 7" min="1" max="365" style="width:100px;">
                                                <span class="text-muted text-xs">days</span>
                                            </div>
                                        </div>

                                        <div style="color:var(--text-light); font-size:13px; padding-bottom:6px;">or</div>

                                        <div>
                                            <label class="text-xs text-muted">Fixed Date</label>
                                            <input type="date" name="sync_orders_from" class="input" value="{{ $store->sync_orders_from ? $store->sync_orders_from->format('Y-m-d') : '' }}" style="width:180px;">
                                        </div>

                                        @if($canManageOpencart)
                                        <button class="btn small" type="submit">Save</button>
                                        @endif
                                    </div>
                                </form>

                                @if($store->sync_last_days)
                                    <div class="hint" style="margin-top:8px;">
                                        Cronjob will always fetch orders from the <strong>last {{ $store->sync_last_days }} {{ Str::plural('day', $store->sync_last_days) }}</strong>.
                                        @if($store->sync_orders_from)
                                            <span class="text-muted">(Fixed date is ignored when "Sync Last N Days" is set.)</span>
                                        @endif
                                    </div>
                                @elseif($store->sync_orders_from)
                                    <div class="hint" style="margin-top:8px;">
                                        Orders modified on or after <strong>{{ $store->sync_orders_from->format('M d, Y') }}</strong> will be synced.
                                    </div>
                                @else
                                    <div class="hint" style="margin-top:8px; color:var(--warning); font-weight:600;">
                                        No sync window set — "Pull Orders" and the cronjob will fetch all orders from the beginning.
                                    </div>
                                @endif
                            </div>

                            {{-- ── Order Status Mapping ── --}}
                            <div class="oc-section">
                                <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                                    <div class="oc-section-header" style="margin-bottom:0;">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/></svg>
                                        Order Status Mapping
                                    </div>
                                    <button class="btn small secondary" type="button" onclick="this.closest('.oc-section').querySelector('.oc-status-map-body').classList.toggle('hidden')">
                                        Show
                                    </button>
                                </div>

                                <div class="oc-status-map-body hidden" style="margin-top:12px;">
                                    <div class="hint" style="margin-bottom:12px;">
                                        Map OpenCart order statuses to ERP statuses. Click "Fetch Statuses" to load available statuses from the OpenCart store, then select the corresponding ERP status for each.
                                    </div>

                                    @if($canManageOpencart)
                                    <div style="margin-bottom:12px;">
                                        <button class="btn small secondary oc-fetch-statuses-btn" type="button" data-store="{{ $store->id }}">
                                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                                            Fetch Statuses from OpenCart
                                        </button>
                                    </div>
                                    @endif

                                    <div class="oc-status-map-table" data-store="{{ $store->id }}">
                                        @php $storeMap = $orderStatusMaps->get($store->id, collect()); @endphp
                                        @if($storeMap->isNotEmpty())
                                            <table class="table" style="margin-bottom:12px;">
                                                <thead>
                                                <tr>
                                                    <th style="width:80px;">OC ID</th>
                                                    <th>OC Status Name</th>
                                                    <th style="width:30px; text-align:center;">→</th>
                                                    <th>ERP Status</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                @foreach($storeMap as $map)
                                                    <tr>
                                                        <td style="font-variant-numeric:tabular-nums;">{{ $map->oc_status_id }}</td>
                                                        <td>{{ $map->oc_status_name }}</td>
                                                        <td style="text-align:center; color:var(--text-muted);">→</td>
                                                        <td>
                                                            <select class="input oc-map-select" data-oc-id="{{ $map->oc_status_id }}" data-oc-name="{{ $map->oc_status_name }}" style="width:100%;" {{ $canManageOpencart ? '' : 'disabled' }}>
                                                                <option value="0">— Unmapped (use raw ID) —</option>
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
                                                No status mappings yet. Click "Fetch Statuses" to load from OpenCart.
                                            </div>
                                        @endif
                                    </div>

                                    @if($storeMap->isNotEmpty() && $canManageOpencart)
                                        <button class="btn small oc-save-status-map-btn" type="button" data-store="{{ $store->id }}">Save Mapping</button>
                                    @endif
                                </div>
                            </div>

                            {{-- ── Review Sync ── --}}
                            <div class="oc-section">
                                <div class="oc-section-header">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                    Review Sync
                                </div>

                                <form method="POST" action="{{ route('ext.opencart.save_review_settings') }}">
                                    @csrf
                                    <input type="hidden" name="store_id" value="{{ $store->id }}">

                                    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                                        <label class="d-flex items-center gap-8" style="margin-bottom:0;">
                                            <input type="checkbox" name="review_auto_approve" value="1" {{ $store->review_auto_approve ? 'checked' : '' }} {{ $canManageOpencart ? '' : 'disabled' }}>
                                            <span>Auto-approve pushed reviews</span>
                                        </label>
                                        @if($canManageOpencart)
                                        <button class="btn small" type="submit">Save</button>
                                        @endif
                                    </div>
                                    <div class="hint" style="margin-top:6px;">
                                        When enabled, reviews pushed from marketplace platforms will appear on the storefront immediately. When disabled, they will be set to "Pending" in OpenCart for manual approval.
                                    </div>
                                </form>
                            </div>

                            {{-- ── Cronjob Setup ── --}}
                            <div class="oc-section">
                                <div class="oc-section-header">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    Cronjob Setup
                                </div>

                                <div class="hint" style="margin-bottom:10px;">
                                    Add the following commands to your hosting panel's cronjob scheduler.
                                </div>

                                @php
                                    $artisanPath = '/usr/bin/php ' . base_path('artisan');
                                @endphp

                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <div>
                                        <div class="text-xs text-muted" style="margin-bottom:4px;">Pull Orders</div>
                                        <div class="oc-cron-box">
                                            <code id="cron-url-{{ $store->id }}">{{ $artisanPath }} opencart:sync orders --store={{ $store->id }}</code>
                                            <button type="button" class="btn small secondary oc-copy-btn" onclick="copyCron({{ $store->id }})" title="Copy to clipboard">
                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                                Copy
                                            </button>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-xs text-muted" style="margin-bottom:4px;">Push Qty</div>
                                        <div class="oc-cron-box">
                                            <code id="cron-push-url-{{ $store->id }}">{{ $artisanPath }} opencart:push-qty --store={{ $store->id }}</code>
                                            <button type="button" class="btn small secondary oc-copy-btn" onclick="copyText(document.getElementById('cron-push-url-{{ $store->id }}'))" title="Copy to clipboard">
                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                                Copy
                                            </button>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-xs text-muted" style="margin-bottom:4px;">Push Reviews</div>
                                        <div class="oc-cron-box">
                                            <code id="cron-push-reviews-{{ $store->id }}">{{ $artisanPath }} opencart:push-reviews --store={{ $store->id }}</code>
                                            <button type="button" class="btn small secondary oc-copy-btn" onclick="copyText(document.getElementById('cron-push-reviews-{{ $store->id }}'))" title="Copy to clipboard">
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
                            </div>

                            {{-- ── Quick Actions ── --}}
                            @if($canManageOpencart)
                            <div class="oc-section">
                                <div class="oc-section-header">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                    Quick Actions
                                </div>

                                <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
                                    <div>
                                        <label class="text-xs text-muted">From</label>
                                        <input type="date" class="input oc-pull-from" data-store="{{ $store->id }}" style="width:auto;">
                                    </div>
                                    <div>
                                        <label class="text-xs text-muted">To</label>
                                        <input type="date" class="input oc-pull-to" data-store="{{ $store->id }}" style="width:auto;">
                                    </div>
                                    <button class="btn small secondary oc-sync-btn" type="button" data-store="{{ $store->id }}" data-entity="orders" data-full="0">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                                        Pull Orders
                                    </button>

                                    <button class="btn small secondary oc-push-qty-btn" type="button" data-store="{{ $store->id }}">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                                        Push Qty
                                    </button>
                                </div>
                                <div class="hint" style="margin-top:6px;">Leave dates empty to use the Sync Last N Days setting.</div>
                            </div>
                            @endif

                            {{-- ── Initial Import / Full Sync ── --}}
                            @if($canManageOpencart)
                            <div class="oc-section">
                                <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                                    <div class="oc-section-header" style="margin-bottom:0;">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        Initial Import / Full Sync
                                    </div>
                                    <button class="btn small secondary" type="button" onclick="this.closest('.oc-section').querySelector('.initial-import-body').classList.toggle('hidden')">
                                        Show
                                    </button>
                                </div>

                                <div class="initial-import-body hidden" style="margin-top:12px;">
                                    <div style="padding:12px; background:#fef2f2; border:1px solid #fecaca; border-radius:var(--radius-sm); margin-bottom:14px;">
                                        <div style="font-weight:600; font-size:13px; color:#991b1b; margin-bottom:4px;">Use with caution</div>
                                        <div style="font-size:12.5px; color:#991b1b; line-height:1.6;">
                                            Full import ignores date filters and syncs all data from the beginning.
                                            Only use this when the ERP or OpenCart store is new.
                                            <strong>Follow the steps in order.</strong>
                                        </div>
                                    </div>

                                    <div style="margin-bottom:14px;">
                                        <label class="text-xs text-muted" style="margin-bottom:4px; display:block;">Enter your password to unlock</label>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <input type="password" class="input oc-import-password" data-store="{{ $store->id }}" placeholder="Account password" style="width:220px;">
                                            <button class="btn small oc-import-unlock" type="button" data-store="{{ $store->id }}">Unlock</button>
                                        </div>
                                        <div class="oc-import-error" data-store="{{ $store->id }}" style="display:none; margin-top:6px; font-size:12px; color:var(--danger);"></div>
                                    </div>

                                    <div class="oc-import-actions" data-store="{{ $store->id }}" style="display:none;">
                                        {{-- Pull from OpenCart — Sequential Stepper --}}
                                        <div style="margin-bottom:16px;">
                                            <div style="font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); margin-bottom:10px;">
                                                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px; margin-right:4px;"><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/></svg>
                                                Pull from OpenCart
                                            </div>

                                            <div class="oc-stepper" data-store="{{ $store->id }}">
                                                @php
                                                    $steps = [
                                                        ['entity' => 'categories',    'label' => 'Categories',    'step' => 1],
                                                        ['entity' => 'manufacturers', 'label' => 'Manufacturers', 'step' => 2],
                                                        ['entity' => 'options',       'label' => 'Options',       'step' => 3],
                                                        ['entity' => 'products',      'label' => 'Products',      'step' => 4],
                                                        ['entity' => 'status_map',    'label' => 'Map Order Statuses', 'step' => 5],
                                                        ['entity' => 'orders',        'label' => 'Orders',        'step' => 6],
                                                    ];
                                                @endphp
                                                @foreach($steps as $s)
                                                    <div class="oc-step{{ $s['step'] <= 2 ? ' oc-step-ready' : '' }}" data-store="{{ $store->id }}" data-step="{{ $s['step'] }}" data-entity="{{ $s['entity'] }}">
                                                        <div class="oc-step-number">{{ $s['step'] }}</div>
                                                        <div class="oc-step-label">{{ $s['label'] }}</div>
                                                        <div class="oc-step-status"></div>
                                                        @if($s['entity'] === 'status_map')
                                                            <button class="btn small oc-step-btn oc-status-map-step-btn" type="button"
                                                                    data-store="{{ $store->id }}"
                                                                    disabled>
                                                                Fetch &amp; Map
                                                            </button>
                                                        @else
                                                            <button class="btn small oc-step-btn oc-sync-btn" type="button"
                                                                    data-store="{{ $store->id }}"
                                                                    data-entity="{{ $s['entity'] }}"
                                                                    data-full="1"
                                                                    {{ $s['step'] > 2 ? 'disabled' : '' }}>
                                                                Sync
                                                            </button>
                                                        @endif
                                                    </div>
                                                    @if($s['entity'] === 'status_map')
                                                        {{-- Inline mapping panel (hidden until Fetch & Map clicked) --}}
                                                        <div class="oc-step-map-panel" data-store="{{ $store->id }}" style="display:none; padding:12px 12px 12px 46px; border-bottom:1px solid var(--border-light); background:#fafbfc;">
                                                            <div class="hint" style="margin-bottom:10px;">Map each OpenCart order status to the corresponding ERP status before importing orders.</div>
                                                            <div class="oc-step-map-table" data-store="{{ $store->id }}"></div>
                                                        </div>
                                                    @endif
                                                @endforeach
                                                <div style="margin-top:8px; text-align:right;">
                                                    <button class="btn small secondary oc-stepper-reset" type="button" data-store="{{ $store->id }}" style="font-size:11px;">Reset Steps</button>
                                                </div>
                                            </div>
                                        </div>

                                        {{-- Push to OpenCart --}}
                                        <div>
                                            <div style="font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); margin-bottom:8px;">
                                                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px; margin-right:4px;"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                                Push to OpenCart
                                            </div>
                                            <div class="d-flex gap-8 flex-wrap">
                                                <button class="btn small oc-push-btn" type="button" data-store="{{ $store->id }}" data-confirm="Push ALL products to {{ $store->store_name }}? This will overwrite product data on OpenCart.">Push All Products</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @endif
                        @else
                            <div style="padding:20px; background:var(--surface-alt); border-radius:var(--radius-sm); border:1px solid var(--border-light); text-align:center;">
                                <div class="text-muted" style="font-size:13px;">This store is disabled. Enable it in Settings to access sync operations.</div>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach

            {{-- Add New Store panel — hidden in single-store mode (use the modal on the module page) --}}
            {{-- Also hidden entirely for read-only operators — they cannot create new stores. --}}
            <div class="oc-panel{{ ($stores->isEmpty() && $canManageOpencart) ? ' active' : '' }} {{ $singleStore || !$canManageOpencart ? 'd-none' : '' }}" id="store-new">
                <div class="card">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:16px;">
                        <div style="width:36px; height:36px; border-radius:8px; background:linear-gradient(135deg,#22c55e,#16a34a); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        </div>
                        <div>
                            <h3 style="margin:0; font-size:16px; font-weight:700;">Add New Store</h3>
                            <div class="text-muted text-sm">The ERP API module must be installed on the OpenCart server first.</div>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('ext.opencart.save') }}">
                        @csrf

                        <div class="form-grid">
                            <div>
                                <label>Store Name</label>
                                <input type="text" name="store_name" class="input" value="" placeholder="e.g. My Store">
                            </div>
                            <div>
                                <label class="required">Base URL</label>
                                <input type="text" name="base_url" class="input" value="" placeholder="https://store.example.com">
                            </div>
                            <div class="full">
                                <label class="required">API Key</label>
                                <input type="text" name="api_key" class="input" value="" placeholder="Your shared secret key">
                                <div class="hint">Must match the key set in OpenCart's ERP Sync module settings.</div>
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
                            <button class="btn secondary" type="button" onclick="testNewStore(this)">Test Connection</button>
                            <div class="test-result" data-store="new" style="display:none; flex:1;"></div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- ─── Cronjob: All Stores ─── --}}
    @if($stores->where('enabled', true)->count() > 1)
        @php
            $artisanPath = '/usr/bin/php ' . base_path('artisan');
        @endphp
        <div class="card mt-16">
            <div class="oc-section" style="margin-bottom:0;">
                <div class="oc-section-header">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Cronjob — All Stores
                </div>
                <div class="hint" style="margin-bottom:10px;">
                    These cronjobs run for all enabled stores. Use these instead of individual store cronjobs if you want one command for everything.
                </div>
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <div>
                        <div class="text-xs text-muted" style="margin-bottom:4px;">Pull Orders</div>
                        <div class="oc-cron-box">
                            <code id="cron-url-all">{{ $artisanPath }} opencart:sync orders</code>
                            <button type="button" class="btn small secondary oc-copy-btn" onclick="copyText(document.getElementById('cron-url-all'))" title="Copy to clipboard">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                Copy
                            </button>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-muted" style="margin-bottom:4px;">Push Qty</div>
                        <div class="oc-cron-box">
                            <code id="cron-push-url-all">{{ $artisanPath }} opencart:push-qty</code>
                            <button type="button" class="btn small secondary oc-copy-btn" onclick="copyText(document.getElementById('cron-push-url-all'))" title="Copy to clipboard">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                Copy
                            </button>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-muted" style="margin-bottom:4px;">Push Reviews</div>
                        <div class="oc-cron-box">
                            <code id="cron-push-reviews-all">{{ $artisanPath }} opencart:push-reviews</code>
                            <button type="button" class="btn small secondary oc-copy-btn" onclick="copyText(document.getElementById('cron-push-reviews-all'))" title="Copy to clipboard">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                Copy
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ─── Sync Log ─── --}}
    @if($recentLogs->count() > 0)
        <div class="card mt-16">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px;">
                <h3 style="margin:0; font-size:15px; font-weight:600;">Sync Log</h3>
                <span class="text-muted text-sm">Last {{ $recentLogs->count() }} operations</span>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Store</th>
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
                    @foreach($recentLogs as $log)
                        @php
                            $logStore = $log->opencart_setting_id
                                ? $stores->firstWhere('id', $log->opencart_setting_id)
                                : null;
                        @endphp
                        <tr>
                            <td style="white-space:nowrap;">{{ $log->started_at ? $log->started_at->format('M d, H:i') : $log->created_at->format('M d, H:i') }}</td>
                            <td>{{ $logStore ? ($logStore->store_name ?: '#' . $logStore->id) : '—' }}</td>
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
                                    —
                                @endif
                            </td>
                            <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="{{ $log->error_message }}">
                                @if($log->error_message)
                                    <span style="color:var(--danger);">{{ \Illuminate\Support\Str::limit($log->error_message, 60) }}</span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

@push('scripts')
<div class="oc-toast-container" id="oc-toast-container"></div>

<script>
    /* ── Store switcher ── */
    function switchStore(btn) {
        var target = btn.getAttribute('data-target');

        // Update sidebar active state
        document.querySelectorAll('.oc-sidebar-item').forEach(function(item) {
            item.classList.remove('active');
        });
        btn.classList.add('active');

        // Show target panel, hide others
        document.querySelectorAll('.oc-panel').forEach(function(panel) {
            panel.classList.remove('active');
        });
        var panel = document.getElementById(target);
        if (panel) panel.classList.add('active');
    }

    /* ── Toast system ── */
    var _toastIcons = {
        info:    '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
        success: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        error:   '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        warning: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
    };

    function ocToast(type, message, duration) {
        var container = document.getElementById('oc-toast-container');
        var el = document.createElement('div');
        el.className = 'oc-toast ' + type;
        el.innerHTML = '<span class="oc-toast-icon">' + (_toastIcons[type] || '') + '</span>'
            + '<span class="oc-toast-body">' + message + '</span>'
            + '<button class="oc-toast-close" type="button">&times;</button>';
        container.appendChild(el);

        el.querySelector('.oc-toast-close').addEventListener('click', function() { dismissToast(el); });

        if (duration !== 0) {
            setTimeout(function() { dismissToast(el); }, duration || 6000);
        }

        return el;
    }

    function dismissToast(el) {
        if (!el || !el.parentNode) return;
        el.style.animation = 'oc-toast-out .2s ease-in forwards';
        setTimeout(function() { if (el.parentNode) el.parentNode.removeChild(el); }, 200);
    }

    /* ── Running process tracker ── */
    var _runningProcesses = {};

    function processKey(store, action, entity) {
        return store + ':' + action + ':' + (entity || 'all');
    }

    /* ── ERP order statuses (for mapping dropdowns) ── */
    var _erpStatuses = @json($erpOrderStatuses);

    /* ── Stepper step dependency rules ── */
    // Step 3 (options) requires 1+2; step 4 (products) requires 3; step 5 (status map) requires 4; step 6 (orders) requires 5
    var _stepDeps = { 3: [1, 2], 4: [3], 5: [4], 6: [5] };
    var _stepDone = {}; // key: storeId:step → true

    function checkStepperUnlocks(storeId) {
        var stepper = document.querySelector('.oc-stepper[data-store="' + storeId + '"]');
        if (!stepper) return;

        stepper.querySelectorAll('.oc-step').forEach(function(stepEl) {
            var step = parseInt(stepEl.getAttribute('data-step'));
            var deps = _stepDeps[step];
            if (!deps) return; // steps 1,2 always enabled

            var allDone = deps.every(function(d) { return _stepDone[storeId + ':' + d]; });
            var btn = stepEl.querySelector('.oc-step-btn');

            if (allDone) {
                stepEl.classList.add('oc-step-ready');
                if (btn && !stepEl.classList.contains('oc-step-done')) btn.disabled = false;
            } else {
                stepEl.classList.remove('oc-step-ready');
                if (btn) btn.disabled = true;
            }
        });
    }

    function markStepDone(storeId, entity) {
        var stepper = document.querySelector('.oc-stepper[data-store="' + storeId + '"]');
        if (!stepper) return;
        var stepEl = stepper.querySelector('.oc-step[data-entity="' + entity + '"]');
        if (!stepEl) return;
        var step = parseInt(stepEl.getAttribute('data-step'));

        stepEl.classList.add('oc-step-done', 'oc-step-ready');
        stepEl.querySelector('.oc-step-status').innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#16a34a" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Done';
        var btn = stepEl.querySelector('.oc-step-btn');
        if (btn) btn.disabled = true;

        _stepDone[storeId + ':' + step] = true;
        checkStepperUnlocks(storeId);
    }

    // Reset button
    document.querySelectorAll('.oc-stepper-reset').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var storeId = btn.getAttribute('data-store');
            var stepper = document.querySelector('.oc-stepper[data-store="' + storeId + '"]');
            if (!stepper) return;

            stepper.querySelectorAll('.oc-step').forEach(function(stepEl) {
                var step = parseInt(stepEl.getAttribute('data-step'));
                stepEl.classList.remove('oc-step-done');
                stepEl.querySelector('.oc-step-status').innerHTML = '';
                delete _stepDone[storeId + ':' + step];

                if (step <= 2) {
                    stepEl.classList.add('oc-step-ready');
                    stepEl.querySelector('.oc-step-btn').disabled = false;
                } else {
                    stepEl.classList.remove('oc-step-ready');
                    stepEl.querySelector('.oc-step-btn').disabled = true;
                }
            });

            // Hide inline mapping panels
            stepper.querySelectorAll('.oc-step-map-panel').forEach(function(p) { p.style.display = 'none'; });
        });
    });

    /* ── Status Map step ("Fetch & Map" button in stepper) ── */
    document.querySelectorAll('.oc-status-map-step-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var storeId = btn.getAttribute('data-store');
            var panel = document.querySelector('.oc-step-map-panel[data-store="' + storeId + '"]');
            var tableWrap = panel ? panel.querySelector('.oc-step-map-table') : null;
            if (!panel || !tableWrap) return;

            // Toggle panel visibility if already loaded
            if (panel.style.display !== 'none' && tableWrap.querySelector('table')) {
                panel.style.display = 'none';
                return;
            }

            panel.style.display = '';
            btn.classList.add('is-loading');
            btn.disabled = true;

            fetch("{{ route('ext.opencart.fetch_oc_statuses') }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ store_id: storeId })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.error) { ocToast('error', data.error); return; }
                var statuses = data.statuses || [];
                if (!statuses.length) { ocToast('info', 'No order statuses found in this store.'); return; }

                // Pre-load existing mappings
                var existingMap = {};
                @foreach($orderStatusMaps as $sid => $maps)
                    @foreach($maps as $m)
                        existingMap['{{ $sid }}_{{ $m->oc_status_id }}'] = {{ $m->order_status_id }};
                    @endforeach
                @endforeach

                var html = '<table class="table" style="margin-bottom:12px;">';
                html += '<thead><tr><th style="width:60px;">OC ID</th><th>OC Status</th><th style="width:40px;"></th><th>ERP Status</th></tr></thead><tbody>';
                statuses.forEach(function(s) {
                    var ocId = s.order_status_id || s.id;
                    var ocName = s.name || '';
                    var existingVal = existingMap[storeId + '_' + ocId] || '';

                    // Auto-match by name if no existing mapping
                    var matched = existingVal;
                    if (!matched) {
                        for (var i = 0; i < _erpStatuses.length; i++) {
                            if (_erpStatuses[i].name.toLowerCase().trim() === ocName.toLowerCase().trim()) {
                                matched = _erpStatuses[i].order_status_id;
                                break;
                            }
                        }
                    }

                    html += '<tr>';
                    html += '<td>' + ocId + '</td>';
                    html += '<td>' + ocName + '</td>';
                    html += '<td style="text-align:center; color:var(--text-muted);">&rarr;</td>';
                    html += '<td><select class="input" name="map_' + ocId + '" data-oc-id="' + ocId + '" data-oc-name="' + ocName.replace(/"/g, '&quot;') + '" style="min-width:140px;">';
                    html += '<option value="">-- skip --</option>';
                    _erpStatuses.forEach(function(es) {
                        var sel = (matched && parseInt(matched) === es.order_status_id) ? ' selected' : '';
                        html += '<option value="' + es.order_status_id + '"' + sel + '>' + es.name + '</option>';
                    });
                    html += '</select></td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
                html += '<button class="btn small oc-step-save-map-btn" type="button" data-store="' + storeId + '">Save Mapping</button>';

                tableWrap.innerHTML = html;

                // Bind save button
                var saveBtn = tableWrap.querySelector('.oc-step-save-map-btn');
                if (saveBtn) bindStepSaveMapBtn(saveBtn);

                ocToast('success', statuses.length + ' statuses fetched. Review the mapping and click Save.');
            })
            .catch(function(e) {
                ocToast('error', 'Failed to fetch statuses: ' + e.message);
            })
            .finally(function() {
                btn.classList.remove('is-loading');
                btn.disabled = false;
            });
        });
    });

    function bindStepSaveMapBtn(btn) {
        btn.addEventListener('click', function() {
            var storeId = btn.getAttribute('data-store');
            var panel = document.querySelector('.oc-step-map-panel[data-store="' + storeId + '"]');
            if (!panel) return;

            var mappings = [];
            panel.querySelectorAll('select[data-oc-id]').forEach(function(sel) {
                var erpVal = sel.value;
                if (!erpVal) return;
                mappings.push({
                    oc_status_id: parseInt(sel.getAttribute('data-oc-id')),
                    oc_status_name: sel.getAttribute('data-oc-name'),
                    order_status_id: parseInt(erpVal)
                });
            });

            if (!mappings.length) { ocToast('error', 'No statuses mapped. Select at least one.'); return; }

            btn.classList.add('is-loading');
            btn.disabled = true;

            fetch("{{ route('ext.opencart.save_status_map') }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ store_id: storeId, mappings: mappings })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.error) { ocToast('error', data.error); return; }
                ocToast('success', data.status || 'Mapping saved.');

                // Mark step as done and unlock Orders
                markStepDone(storeId, 'status_map');
                panel.style.display = 'none';
            })
            .catch(function(e) {
                ocToast('error', 'Save failed: ' + e.message);
            })
            .finally(function() {
                btn.classList.remove('is-loading');
                btn.disabled = false;
            });
        });
    }

    /* ── Sync buttons (Pull Orders Now + Initial Import entities) ── */
    document.querySelectorAll('.oc-sync-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var storeId = btn.getAttribute('data-store');
            var entity  = btn.getAttribute('data-entity');
            var full    = btn.getAttribute('data-full') === '1';
            var key     = processKey(storeId, 'sync', entity);
            var label   = entity.charAt(0).toUpperCase() + entity.slice(1);

            if (_runningProcesses[key]) {
                ocToast('warning', '<strong>' + label + '</strong> sync is already running for this store. Please wait for it to finish.');
                return;
            }

            _runningProcesses[key] = true;
            btn.classList.add('is-loading');
            btn.disabled = true;

            // Update stepper status while running
            var stepEl = btn.closest('.oc-step');
            if (stepEl) {
                stepEl.querySelector('.oc-step-status').innerHTML = '<span style="color:var(--primary);">Syncing...</span>';
            }

            var startToast = ocToast('info', (full ? 'Full import' : 'Sync') + ' started: <strong>' + label + '</strong>. This may take a while...', 0);

            fetch("{{ route('ext.opencart.sync') }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    store_id: storeId,
                    entity: entity,
                    full: full ? 1 : 0,
                    no_stock: (full && entity === 'orders') ? 1 : 0,
                    date_from: (entity === 'orders' && !full) ? (document.querySelector('.oc-pull-from[data-store="' + storeId + '"]') || {}).value || '' : '',
                    date_to: (entity === 'orders' && !full) ? (document.querySelector('.oc-pull-to[data-store="' + storeId + '"]') || {}).value || '' : ''
                })
            })
            .then(function(res) { return res.json().then(function(json) { return { status: res.status, json: json }; }); })
            .then(function(r) {
                dismissToast(startToast);
                if (r.json.status) {
                    ocToast('success', r.json.status);
                    // Mark stepper step as done on success
                    if (full) markStepDone(storeId, entity);
                } else if (r.json.error) {
                    ocToast('error', r.json.error);
                    if (stepEl) stepEl.querySelector('.oc-step-status').innerHTML = '<span style="color:var(--danger);">Failed</span>';
                } else {
                    ocToast('success', label + ' sync completed.');
                    if (full) markStepDone(storeId, entity);
                }
            })
            .catch(function(e) {
                dismissToast(startToast);
                ocToast('error', label + ' sync failed: ' + e.message);
                if (stepEl) stepEl.querySelector('.oc-step-status').innerHTML = '<span style="color:var(--danger);">Failed</span>';
            })
            .finally(function() {
                delete _runningProcesses[key];
                btn.classList.remove('is-loading');
                // Only re-enable if not marked done
                if (!stepEl || !stepEl.classList.contains('oc-step-done')) {
                    btn.disabled = false;
                }
            });
        });
    });

    /* ── Push All Products button ── */
    document.querySelectorAll('.oc-push-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var storeId = btn.getAttribute('data-store');
            var confirmMsg = btn.getAttribute('data-confirm');
            var key = processKey(storeId, 'push', 'products');

            if (_runningProcesses[key]) {
                ocToast('warning', '<strong>Push All Products</strong> is already running for this store. Please wait for it to finish.');
                return;
            }

            function doPush() {
                _runningProcesses[key] = true;
                btn.classList.add('is-loading');
                btn.disabled = true;

                var startToast = ocToast('info', 'Push All Products started. This may take a while...', 0);

                fetch("{{ route('ext.opencart.push') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ store_id: storeId })
                })
                .then(function(res) { return res.json().then(function(json) { return { status: res.status, json: json }; }); })
                .then(function(r) {
                    dismissToast(startToast);
                    if (r.json.status) {
                        ocToast('success', r.json.status);
                    } else if (r.json.error) {
                        ocToast('error', r.json.error);
                    } else {
                        ocToast('success', 'Push All Products completed.');
                    }
                })
                .catch(function(e) {
                    dismissToast(startToast);
                    ocToast('error', 'Push failed: ' + e.message);
                })
                .finally(function() {
                    delete _runningProcesses[key];
                    btn.classList.remove('is-loading');
                    btn.disabled = false;
                });
            }

            if (confirmMsg) {
                confirmModal(confirmMsg).then(function(ok) { if (ok) doPush(); });
                return;
            }
            doPush();
        });
    });

    /* ── Push Qty Only button ── */
    document.querySelectorAll('.oc-push-qty-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var storeId = btn.getAttribute('data-store');
            var key = processKey(storeId, 'push', 'qty');

            if (_runningProcesses[key]) {
                ocToast('warning', '<strong>Push Qty</strong> is already running for this store. Please wait for it to finish.');
                return;
            }

            _runningProcesses[key] = true;
            btn.classList.add('is-loading');
            btn.disabled = true;

            var startToast = ocToast('info', 'Push Qty started...', 0);

            fetch("{{ route('ext.opencart.push_qty') }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ store_id: storeId })
            })
            .then(function(res) {
                return res.text().then(function(text) {
                    try { var json = JSON.parse(text); } catch(e) { json = { error: 'Server error (HTTP ' + res.status + '): ' + text.substring(0, 200) }; }
                    return { status: res.status, json: json };
                });
            })
            .then(function(r) {
                dismissToast(startToast);
                if (r.json.error) {
                    ocToast('error', r.json.error);
                } else if (r.json.status) {
                    ocToast('success', r.json.status);
                } else {
                    ocToast('success', 'Push Qty completed.');
                }
            })
            .catch(function(e) {
                dismissToast(startToast);
                ocToast('error', 'Push Qty failed: ' + e.message);
            })
            .finally(function() {
                delete _runningProcesses[key];
                btn.classList.remove('is-loading');
                btn.disabled = false;
            });
        });
    });

    /* ── Initial Import password unlock ── */
    document.querySelectorAll('.oc-import-unlock').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var storeId = btn.getAttribute('data-store');
            var pwInput = document.querySelector('.oc-import-password[data-store="' + storeId + '"]');
            var errEl   = document.querySelector('.oc-import-error[data-store="' + storeId + '"]');
            var actions = document.querySelector('.oc-import-actions[data-store="' + storeId + '"]');
            var password = pwInput ? pwInput.value : '';

            if (!password) {
                if (errEl) { errEl.textContent = 'Please enter your password.'; errEl.style.display = 'block'; }
                return;
            }

            btn.classList.add('is-loading');
            btn.disabled = true;
            if (errEl) errEl.style.display = 'none';

            fetch("{{ route('ext.opencart.verify_password') }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ password: password })
            })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.ok) {
                    if (actions) actions.style.display = 'block';
                    if (pwInput) pwInput.closest('div').parentElement.style.display = 'none';
                } else {
                    if (errEl) { errEl.textContent = json.error || 'Incorrect password.'; errEl.style.display = 'block'; }
                    if (pwInput) { pwInput.value = ''; pwInput.focus(); }
                }
            })
            .catch(function(e) {
                if (errEl) { errEl.textContent = 'Verification failed: ' + e.message; errEl.style.display = 'block'; }
            })
            .finally(function() {
                btn.classList.remove('is-loading');
                btn.disabled = false;
            });
        });
    });

    // Allow Enter key to trigger unlock
    document.querySelectorAll('.oc-import-password').forEach(function(input) {
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var storeId = input.getAttribute('data-store');
                var btn = document.querySelector('.oc-import-unlock[data-store="' + storeId + '"]');
                if (btn) btn.click();
            }
        });
    });

    /* ── Test connection (existing) ── */
    function testStore(storeId, btn) {
        var result = document.querySelector('.test-result[data-store="' + storeId + '"]');
        if (!result) return;

        btn.classList.add('is-loading');
        btn.disabled = true;
        result.style.display = 'block';
        result.innerHTML = '<span class="text-muted text-sm">Connecting...</span>';

        fetch("{{ route('ext.opencart.test') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ store_id: storeId })
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.ok && json.body && json.body.success) {
                var info = json.body.data || {};
                result.innerHTML = '<span class="badge badge-green" style="font-size:12px;">Connected — ' + (info.store || 'OK') + ' (OC ' + (info.version || '?') + ')</span>';
            } else {
                var err = (json.body && json.body.error) ? json.body.error : 'HTTP ' + (json.status || '?');
                result.innerHTML = '<span class="badge badge-red" style="font-size:12px;">Failed: ' + err + '</span>';
            }
        })
        .catch(function(e) {
            result.innerHTML = '<span class="badge badge-red" style="font-size:12px;">Error: ' + e.message + '</span>';
        })
        .finally(function() {
            btn.classList.remove('is-loading');
            btn.disabled = false;
        });
    }

    function testNewStore(btn) {
        var panel = document.getElementById('store-new');
        var baseUrl = panel.querySelector('input[name="base_url"]').value.trim();
        var apiKey = panel.querySelector('input[name="api_key"]').value.trim();
        var result = panel.querySelector('.test-result[data-store="new"]');

        if (!baseUrl || !apiKey) {
            if (result) {
                result.style.display = 'block';
                result.innerHTML = '<span class="badge badge-red" style="font-size:12px;">Please enter Base URL and API Key first.</span>';
            }
            return;
        }

        btn.classList.add('is-loading');
        btn.disabled = true;
        result.style.display = 'block';
        result.innerHTML = '<span class="text-muted text-sm">Connecting...</span>';

        fetch("{{ route('ext.opencart.test') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ base_url: baseUrl, api_key: apiKey })
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.ok && json.body && json.body.success) {
                var info = json.body.data || {};
                result.innerHTML = '<span class="badge badge-green" style="font-size:12px;">Connected — ' + (info.store || 'OK') + ' (OC ' + (info.version || '?') + ')</span>';
            } else {
                var err = (json.body && json.body.error) ? json.body.error : 'HTTP ' + (json.status || '?');
                result.innerHTML = '<span class="badge badge-red" style="font-size:12px;">Failed: ' + err + '</span>';
            }
        })
        .catch(function(e) {
            result.innerHTML = '<span class="badge badge-red" style="font-size:12px;">Error: ' + e.message + '</span>';
        })
        .finally(function() {
            btn.classList.remove('is-loading');
            btn.disabled = false;
        });
    }

    /* ── Copy helpers ── */
    function copyCron(storeId) {
        var el = document.getElementById('cron-url-' + storeId);
        copyText(el);
    }

    function copyCronAll(storeId) {
        var el = document.getElementById('cron-url-all-' + storeId);
        copyText(el);
    }

    function copyText(el) {
        if (!el) return;
        var text = el.textContent;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function() {
                showCopied(el);
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showCopied(el);
        }
    }

    function showCopied(el) {
        var btn = el.parentElement.querySelector('.oc-copy-btn');
        if (!btn) return;
        var orig = btn.innerHTML;
        btn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Copied';
        setTimeout(function(){ btn.innerHTML = orig; }, 1500);
    }

    /* ── Fetch OC Statuses (separate section outside stepper) ── */
    document.querySelectorAll('.oc-fetch-statuses-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var storeId = btn.getAttribute('data-store');
            var tableWrap = document.querySelector('.oc-status-map-table[data-store="' + storeId + '"]');
            if (!tableWrap) return;

            btn.classList.add('is-loading');
            btn.disabled = true;

            fetch("{{ route('ext.opencart.fetch_oc_statuses') }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ store_id: storeId })
            })
            .then(function(res) { return res.json().then(function(json) { return { status: res.status, json: json }; }); })
            .then(function(r) {
                if (r.json.error) {
                    ocToast('error', r.json.error);
                    return;
                }

                var statuses = r.json.statuses || [];
                if (!statuses.length) {
                    ocToast('warning', 'No order statuses returned from OpenCart.');
                    return;
                }

                // Get existing selections if any
                var existingSelections = {};
                tableWrap.querySelectorAll('.oc-map-select').forEach(function(sel) {
                    existingSelections[sel.getAttribute('data-oc-id')] = sel.value;
                });

                // Build table
                var html = '<table class="table" style="margin-bottom:12px;">'
                    + '<thead><tr><th style="width:80px;">OC ID</th><th>OC Status Name</th><th style="width:30px; text-align:center;">→</th><th>ERP Status</th></tr></thead>'
                    + '<tbody>';

                statuses.forEach(function(s) {
                    var ocId = s.order_status_id || s.id || 0;
                    var ocName = s.name || '';

                    // Persisted mapping wins (server is the source of truth after the
                    // delta-merge fetch). Fall back to any unsaved DOM selection, then
                    // a name-match heuristic for brand-new statuses, finally Unmapped.
                    var serverErpId = parseInt(s.erp_order_status_id || 0, 10);
                    var selectedVal = serverErpId > 0 ? String(serverErpId) : (existingSelections[ocId] || '0');

                    if (selectedVal === '0') {
                        _erpStatuses.forEach(function(erp) {
                            if (erp.name.toLowerCase().trim() === ocName.toLowerCase().trim()) {
                                selectedVal = String(erp.order_status_id);
                            }
                        });
                    }

                    var options = '<option value="0">— Unmapped (use raw ID) —</option>';
                    _erpStatuses.forEach(function(erp) {
                        var sel = String(erp.order_status_id) === String(selectedVal) ? ' selected' : '';
                        options += '<option value="' + erp.order_status_id + '"' + sel + '>' + erp.name + ' (ID: ' + erp.order_status_id + ')</option>';
                    });

                    html += '<tr>'
                        + '<td style="font-variant-numeric:tabular-nums;">' + ocId + '</td>'
                        + '<td>' + ocName + '</td>'
                        + '<td style="text-align:center; color:var(--text-muted);">→</td>'
                        + '<td><select class="input oc-map-select" data-oc-id="' + ocId + '" data-oc-name="' + ocName.replace(/"/g, '&quot;') + '" style="width:100%;">' + options + '</select></td>'
                        + '</tr>';
                });

                html += '</tbody></table>';
                tableWrap.innerHTML = html;

                // Ensure save button exists
                var section = tableWrap.closest('.oc-status-map-body');
                if (section && !section.querySelector('.oc-save-status-map-btn')) {
                    var saveBtn = document.createElement('button');
                    saveBtn.className = 'btn small oc-save-status-map-btn';
                    saveBtn.type = 'button';
                    saveBtn.setAttribute('data-store', storeId);
                    saveBtn.textContent = 'Save Mapping';
                    section.appendChild(saveBtn);
                    bindSaveMapBtn(saveBtn);
                }

                ocToast('success', statuses.length + ' order statuses fetched from OpenCart.');
            })
            .catch(function(e) {
                ocToast('error', 'Failed to fetch statuses: ' + e.message);
            })
            .finally(function() {
                btn.classList.remove('is-loading');
                btn.disabled = false;
            });
        });
    });

    /* ── Save Status Mapping ── */
    function bindSaveMapBtn(btn) {
        btn.addEventListener('click', function() {
            var storeId = btn.getAttribute('data-store');
            var tableWrap = document.querySelector('.oc-status-map-table[data-store="' + storeId + '"]');
            if (!tableWrap) return;

            var mappings = [];
            tableWrap.querySelectorAll('.oc-map-select').forEach(function(sel) {
                mappings.push({
                    oc_status_id: parseInt(sel.getAttribute('data-oc-id')),
                    oc_status_name: sel.getAttribute('data-oc-name') || '',
                    order_status_id: parseInt(sel.value)
                });
            });

            if (!mappings.length) {
                ocToast('warning', 'No mappings to save. Fetch statuses first.');
                return;
            }

            btn.classList.add('is-loading');
            btn.disabled = true;

            fetch("{{ route('ext.opencart.save_status_map') }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ store_id: storeId, mappings: mappings })
            })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.error) {
                    ocToast('error', json.error);
                } else {
                    ocToast('success', json.status || 'Mapping saved.');
                }
            })
            .catch(function(e) {
                ocToast('error', 'Save failed: ' + e.message);
            })
            .finally(function() {
                btn.classList.remove('is-loading');
                btn.disabled = false;
            });
        });
    }

    // Bind existing save buttons
    document.querySelectorAll('.oc-save-status-map-btn').forEach(bindSaveMapBtn);
</script>
@endpush
@endsection
