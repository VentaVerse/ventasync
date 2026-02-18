@extends('layouts.app')
@section('breadcrumb', 'Marketplace / OpenCart / Settings')

@section('title', 'OpenCart Settings')

@section('content')
    <div class="page-header">
        <div>
            <h2>OpenCart Settings</h2>
            <div class="text-muted text-sm">Manage store connections and sync operations.</div>
        </div>
    </div>

    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif

    @if(session('error'))
        <div class="alert danger">{{ session('error') }}</div>
    @endif

    {{-- ─── Store Cards ─── --}}
    @foreach($stores as $store)
        <div class="card mt-16" id="store-{{ $store->id }}">
            {{-- Store header --}}
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:36px; height:36px; border-radius:8px; background:{{ $store->enabled ? 'linear-gradient(135deg,#3b82f6,#1d4ed8)' : '#94a3b8' }}; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    </div>
                    <div>
                        <h3 style="margin:0; font-size:16px; font-weight:700;">{{ $store->store_name ?: 'Store #' . $store->id }}</h3>
                        <div class="text-muted text-sm" style="margin-top:1px;">{{ $store->base_url }}</div>
                    </div>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    @if($store->enabled)
                        <span class="badge badge-green" style="font-size:11px;">Active</span>
                    @else
                        <span class="badge badge-gray" style="font-size:11px;">Disabled</span>
                    @endif
                    <button class="btn small secondary" type="button" onclick="this.closest('.card').querySelector('.store-settings').classList.toggle('hidden')">Edit</button>
                </div>
            </div>

            {{-- Connection settings (collapsed by default for existing stores) --}}
            <div class="store-settings hidden" style="border-top:1px solid var(--border-light); padding-top:16px; margin-bottom:16px;">
                <form method="POST" action="{{ route('opencart.save') }}">
                    @csrf
                    <input type="hidden" name="store_id" value="{{ $store->id }}">

                    <div class="form-grid">
                        <div>
                            <label>Store Name</label>
                            <input type="text" name="store_name" class="input" value="{{ $store->store_name }}" placeholder="e.g. Shop A">
                        </div>
                        <div>
                            <label class="required">Base URL</label>
                            <input type="text" name="base_url" class="input" value="{{ $store->base_url }}" placeholder="https://store.example.com" required>
                        </div>
                        <div class="full">
                            <label class="required">API Key</label>
                            <input type="password" name="api_key" class="input" value="{{ $store->api_key }}" required>
                            <div class="hint">Must match the key in OpenCart's ERP Sync module.</div>
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
                        <button class="btn secondary" type="button" onclick="testStore({{ $store->id }}, this)">Test Connection</button>
                        <div class="test-result" data-store="{{ $store->id }}" style="display:none; flex:1;"></div>
                    </div>
                </form>

                <div style="margin-top:12px; padding-top:12px; border-top:1px solid var(--border-light);">
                    <form method="POST" action="{{ route('opencart.destroy', $store->id) }}" style="display:inline;" onsubmit="return confirm('Delete this store and all its product links? This cannot be undone.')">
                        @csrf
                        @method('DELETE')
                        <button class="btn small danger" type="submit">Delete Store</button>
                    </form>
                </div>
            </div>

            {{-- Quick status bar --}}
            @if($store->enabled)
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-bottom:16px;">
                    <div style="padding:12px; background:var(--surface-alt); border-radius:var(--radius-sm); border:1px solid var(--border-light);">
                        <div class="text-muted" style="font-size:11px; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px;">Last Order Pull</div>
                        <div style="font-weight:600; font-size:13px;">{{ $store->last_order_sync_at ? $store->last_order_sync_at->format('M d, H:i') : '—' }}</div>
                    </div>
                    <div style="padding:12px; background:var(--surface-alt); border-radius:var(--radius-sm); border:1px solid var(--border-light);">
                        <div class="text-muted" style="font-size:11px; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px;">Last Product Push</div>
                        <div style="font-weight:600; font-size:13px;">{{ $store->last_product_sync_at ? $store->last_product_sync_at->format('M d, H:i') : '—' }}</div>
                    </div>
                    <div style="padding:12px; background:var(--surface-alt); border-radius:var(--radius-sm); border:1px solid var(--border-light);">
                        <div class="text-muted" style="font-size:11px; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px;">Categories</div>
                        <div style="font-weight:600; font-size:13px;">{{ $store->last_category_sync_at ? $store->last_category_sync_at->format('M d, H:i') : '—' }}</div>
                    </div>
                    <div style="padding:12px; background:var(--surface-alt); border-radius:var(--radius-sm); border:1px solid var(--border-light);">
                        <div class="text-muted" style="font-size:11px; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px;">Manufacturers</div>
                        <div style="font-weight:600; font-size:13px;">{{ $store->last_manufacturer_sync_at ? $store->last_manufacturer_sync_at->format('M d, H:i') : '—' }}</div>
                    </div>
                </div>

                {{-- Action buttons --}}
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <form method="POST" action="{{ route('opencart.sync') }}" style="display:inline;">
                        @csrf
                        <input type="hidden" name="store_id" value="{{ $store->id }}">
                        <input type="hidden" name="entity" value="orders">
                        <button class="btn small" type="submit">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                            Pull Orders
                        </button>
                    </form>

                    <form method="POST" action="{{ route('opencart.push') }}" style="display:inline;" onsubmit="return confirm('Push ALL products to {{ $store->store_name }}? Use profiles for selective push instead.')">
                        @csrf
                        <input type="hidden" name="store_id" value="{{ $store->id }}">
                        <button class="btn small secondary" type="submit">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                            Push All Products
                        </button>
                    </form>

                    <button class="btn small secondary" type="button" onclick="this.closest('.card').querySelector('.initial-import').classList.toggle('hidden')" style="margin-left:auto;">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Initial Import
                    </button>
                </div>

                {{-- Initial Import (hidden by default) --}}
                <div class="initial-import hidden" style="margin-top:12px; padding:14px; background:var(--surface-alt); border-radius:var(--radius-sm); border:1px solid var(--border-light);">
                    <div style="font-weight:600; font-size:13px; margin-bottom:4px;">One-Time Import</div>
                    <div class="hint" style="margin-bottom:10px;">Pull data from OpenCart into Laravel. Typically only needed during initial setup.</div>
                    <div class="d-flex gap-8 flex-wrap">
                        @foreach(['categories', 'manufacturers', 'options', 'products'] as $entity)
                            <form method="POST" action="{{ route('opencart.sync') }}" style="display:inline;">
                                @csrf
                                <input type="hidden" name="store_id" value="{{ $store->id }}">
                                <input type="hidden" name="entity" value="{{ $entity }}">
                                <button class="btn small" type="submit">Pull {{ ucfirst($entity) }}</button>
                            </form>
                        @endforeach
                    </div>
                </div>
            @else
                <div style="padding:16px; background:var(--surface-alt); border-radius:var(--radius-sm); border:1px solid var(--border-light); text-align:center;">
                    <div class="text-muted" style="font-size:13px;">This store is disabled. Enable it to access sync operations.</div>
                </div>
            @endif
        </div>
    @endforeach

    {{-- ─── Add New Store ─── --}}
    <div class="card mt-16">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:16px;">
            <div style="width:36px; height:36px; border-radius:8px; background:linear-gradient(135deg,#22c55e,#16a34a); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </div>
            <div>
                <h3 style="margin:0; font-size:16px; font-weight:700;">Add New Store</h3>
                <div class="text-muted text-sm">The ERP API module must be installed on the OpenCart server first.</div>
            </div>
        </div>

        <form method="POST" action="{{ route('opencart.save') }}">
            @csrf

            <div class="form-grid">
                <div>
                    <label>Store Name</label>
                    <input type="text" name="store_name" class="input" value="" placeholder="e.g. Shop A">
                </div>
                <div>
                    <label class="required">Base URL</label>
                    <input type="text" name="base_url" class="input" value="" placeholder="https://store.example.com" required>
                </div>
                <div class="full">
                    <label class="required">API Key</label>
                    <input type="text" name="api_key" class="input" value="" placeholder="Your shared secret key" required>
                    <div class="hint">Must match the key set in OpenCart's ERP Sync module settings.</div>
                </div>
                <div>
                    <label class="d-flex items-center gap-8" style="margin-bottom:0;">
                        <input type="checkbox" name="enabled" value="1" checked>
                        <span>Enabled</span>
                    </label>
                </div>
            </div>

            <div class="mt-16">
                <button class="btn" type="submit">Add Store</button>
            </div>
        </form>
    </div>

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

    <script>
        function testStore(storeId, btn) {
            var result = document.querySelector('.test-result[data-store="' + storeId + '"]');
            if (!result) return;

            var origText = btn.textContent;
            btn.classList.add('is-loading');
            btn.disabled = true;
            result.style.display = 'block';
            result.innerHTML = '<span class="text-muted text-sm">Connecting...</span>';

            fetch("{{ route('opencart.test') }}", {
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
    </script>
@endsection
