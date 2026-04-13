@extends('layouts.app')
@section('title', $group->name . ' — TikTok Products')
@section('breadcrumb', 'Marketplace / TikTok Shop / ' . $group->name . ' / Products')

@section('content')
    <div class="page-header">
        <div>
            <h2>{{ $group->name }} — Products</h2>
            <div class="text-muted text-sm">TikTok Shop — push catalog products, sync stock &amp; price.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('ext.tiktok.product-groups.edit', $group->id) }}">Edit Group</a>
            <a class="btn secondary" href="{{ route('ext.tiktok.product-groups.index') }}">← Back to Product Groups</a>
        </div>
    </div>

    {{-- Filter toolbar --}}
    <div class="card mb-12">
        <form method="GET" action="{{ route('ext.tiktok.product-groups.products', $group->id) }}" style="display:grid; grid-template-columns:1fr auto auto auto; gap:10px; align-items:end;">
            <div>
                <label class="text-xs text-muted">Search</label>
                <input type="text" name="q" class="input" value="{{ $q ?? '' }}" placeholder="Name, model or SKU...">
            </div>
            <div>
                <label class="text-xs text-muted">ERP Status</label>
                <select name="erp_status" class="input">
                    <option value="all" {{ ($erpStatus ?? 'all') === 'all' ? 'selected' : '' }}>All</option>
                    <option value="enabled" {{ ($erpStatus ?? '') === 'enabled' ? 'selected' : '' }}>Enabled</option>
                    <option value="disabled" {{ ($erpStatus ?? '') === 'disabled' ? 'selected' : '' }}>Disabled</option>
                </select>
            </div>
            <div>
                <label class="text-xs text-muted">Sync Status</label>
                <select name="sync_status" class="input">
                    <option value="all" {{ ($syncStatus ?? 'all') === 'all' ? 'selected' : '' }}>All</option>
                    <option value="pushed" {{ ($syncStatus ?? '') === 'pushed' ? 'selected' : '' }}>Pushed</option>
                    <option value="pending" {{ ($syncStatus ?? '') === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="error" {{ ($syncStatus ?? '') === 'error' ? 'selected' : '' }}>Error</option>
                </select>
            </div>
            <div class="d-flex gap-6">
                <button type="submit" class="btn small">Filter</button>
                @if(($q ?? '') !== '' || ($syncStatus ?? 'all') !== 'all' || ($erpStatus ?? 'all') !== 'all')
                    <a href="{{ route('ext.tiktok.product-groups.products', $group->id) }}" class="btn small secondary">Clear</a>
                @endif
            </div>
        </form>
    </div>

    <div class="card">
        {{-- Bulk action bar (hidden until items selected) --}}
        <div id="bulk-bar" style="display:none; padding:10px 16px; margin-bottom:12px; background:var(--accent-light); border:1px solid rgba(59,130,246,.2); border-radius:var(--radius-md); align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
            <span style="font-size:13px; font-weight:600; color:var(--accent);"><span id="bulk-count">0</span> selected</span>
            <div class="d-flex gap-6 flex-wrap">
                <form method="POST" action="{{ route('ext.tiktok.product-groups.push', $group->id) }}" class="bulk-action-form bulk-push-form" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small">Push to TikTok</button>
                </form>
                <form method="POST" action="{{ route('ext.tiktok.product-groups.pushPrices', $group->id) }}" class="bulk-action-form" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small">Sync Price</button>
                </form>
                <form method="POST" action="{{ route('ext.tiktok.product-groups.pushStock', $group->id) }}" class="bulk-action-form" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small">Sync Qty</button>
                </form>
                <form method="POST" action="{{ route('ext.tiktok.product-groups.massRemove', $group->id) }}" class="bulk-action-form" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small secondary">Remove from Group</button>
                </form>
                <form method="POST" action="{{ route('ext.tiktok.product-groups.deleteFromTikTok', $group->id) }}" class="bulk-action-form bulk-delete-form" data-confirm="Delete selected product(s) from TikTok Shop? This cannot be undone." style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small danger">Delete from TikTok</button>
                </form>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table" id="tiktokProductsTable">
                <thead>
                <tr>
                    <th style="width:36px;"><input type="checkbox" id="checkAll" title="Select all"></th>
                    <th style="width:60px;">ID</th>
                    <th style="width:56px;">Image</th>
                    <th>Product Name</th>
                    <th>SKU</th>
                    <th>Manufacturer</th>
                    <th style="width:60px;">Stock</th>
                    <th style="width:80px;">Price</th>
                    <th style="width:130px;">TikTok ID</th>
                    <th style="width:90px;">ERP Status</th>
                    <th style="width:100px;">Sync Status</th>
                    <th style="width:160px;">Error</th>
                    <th style="width:50px;"></th>
                </tr>
                </thead>
                <tbody>
                @forelse($products as $p)
                    @php
                        $pivot = $pivotMap->get($p->product_id);
                        $img = trim((string)($p->image ?? ''));
                        $src = $img !== '' ? asset('storage/' . ltrim($img, '/')) : '';
                        $syncState = $pivot->sync_status ?? 'pending';
                        $isUnlinked = $syncState === 'unlinked';
                        $hasTikTokId = $pivot && $pivot->tiktok_product_id && !$isUnlinked;
                        $hasError = $syncState === 'error' && ($pivot->push_error ?? null);
                        $optRows = isset($optionRowsByProductId) ? $optionRowsByProductId->get($p->product_id) : null;
                    @endphp
                    <tr>
                        <td><input type="checkbox" class="row-check" value="{{ $p->product_id }}"></td>
                        <td>{{ $p->product_id }}</td>
                        <td>
                            @if($src)
                                <span class="thumb-magnify">
                                    <img src="{{ $src }}" alt="" style="width:40px; height:40px; object-fit:cover; border-radius:4px;" loading="lazy" onerror="this.closest('.thumb-magnify').style.display='none';">
                                    <img src="{{ $src }}" alt="" class="thumb-preview">
                                </span>
                            @else
                                <span class="text-muted text-xs">-</span>
                            @endif
                        </td>
                        <td>
                            <div class="font-bold">{{ $p->name ?? '-' }}</div>
                            @if($optRows && count($optRows) > 0)
                                <div style="margin-top:4px;">
                                    @foreach($optRows as $or)
                                        @php
                                            $ovn = (string)($or->option_value_name ?? '');
                                            $osku = (string)($or->option_sku ?? '');
                                            $oqty = (int)($or->option_quantity ?? 0);
                                        @endphp
                                        <div class="text-xs" style="color:var(--text-secondary); line-height:1.6;">
                                            {{ $ovn ?: '-' }}@if($osku) <span class="text-muted">— {{ $osku }}</span>@endif
                                            <span class="text-muted">({{ $oqty }})</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td><code>{{ $p->sku ?: ($p->model ?: '-') }}</code></td>
                        <td>{{ $p->manufacturer_name ?? '-' }}</td>
                        <td>
                            <div>{{ (int)$p->quantity }}</div>
                            @if($optRows && count($optRows) > 0)
                                @foreach($optRows as $or)
                                    <div class="text-xs text-muted">{{ (int)($or->option_quantity ?? 0) }}</div>
                                @endforeach
                            @endif
                        </td>
                        <td>
                            <div>{{ number_format((float)$p->price, 2) }}</div>
                            @if($optRows && count($optRows) > 0)
                                @foreach($optRows as $or)
                                    <div class="text-xs text-muted">{{ number_format((float)($or->option_absolute_price ?? 0), 2) }}</div>
                                @endforeach
                            @endif
                        </td>
                        <td>
                            @if($hasTikTokId)
                                <span style="font-size:12px; font-family:monospace;">{{ $pivot->tiktok_product_id }}</span>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>
                            @if((int)$p->status === 1)
                                <span class="badge badge-green">Enabled</span>
                            @else
                                <span class="badge badge-red">Disabled</span>
                            @endif
                        </td>
                        <td>
                            @if($isUnlinked)
                                <span class="badge">Unlinked</span>
                            @elseif($hasTikTokId && $syncState === 'synced')
                                <span class="badge badge-green">Synced</span>
                            @elseif($hasTikTokId)
                                <span class="badge badge-green">Pushed</span>
                            @elseif($syncState === 'error')
                                <span class="badge badge-red">Error</span>
                            @else
                                <span class="badge badge-yellow">Pending</span>
                            @endif
                        </td>
                        <td>
                            @if($hasError)
                                <div class="text-xs" style="color:var(--danger); word-break:break-word; line-height:1.4;">
                                    {{ \Illuminate\Support\Str::limit($pivot->push_error, 80) }}
                                </div>
                                @if($pivot->last_pushed_at)
                                    <div class="text-xs text-muted" style="margin-top:2px;">{{ \Carbon\Carbon::parse($pivot->last_pushed_at)->diffForHumans() }}</div>
                                @endif
                            @elseif($hasTikTokId && $pivot->last_pushed_at)
                                <span class="badge badge-green">OK</span>
                                <div class="text-xs text-muted" style="margin-top:2px;">{{ \Carbon\Carbon::parse($pivot->last_pushed_at)->diffForHumans() }}</div>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>
                            <div style="position:relative;" class="row-actions">
                                <button type="button" class="btn small secondary action-toggle" style="padding:0 8px; min-width:32px;" title="Actions">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                                </button>
                                <div class="action-menu" style="display:none; position:absolute; right:0; top:100%; margin-top:4px; z-index:50; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md); box-shadow:var(--shadow-lg); min-width:170px; padding:4px 0;">
                                    <a href="{{ route('products.edit', $p->product_id) }}" class="action-item">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        Edit Product
                                    </a>
                                    @if(!$hasTikTokId)
                                        <form method="POST" action="{{ route('ext.tiktok.product-groups.syncId', [$group->id, $p->product_id]) }}" style="margin:0;" class="single-action-form">
                                            @csrf
                                            <button type="submit" class="action-item">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
                                                Sync TikTok ID
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('ext.tiktok.product-groups.push', $group->id) }}" style="margin:0;" class="single-action-form" data-pid="{{ $p->product_id }}">
                                            @csrf
                                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                                            <button type="submit" class="action-item">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                                                Push to TikTok
                                            </button>
                                        </form>
                                    @endif
                                    @if($hasTikTokId)
                                        <form method="POST" action="{{ route('ext.tiktok.product-groups.updateProduct', $group->id) }}" style="margin:0;" class="single-action-form">
                                            @csrf
                                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                                            <button type="submit" class="action-item">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                                Update on TikTok
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('ext.tiktok.product-groups.pushStock', $group->id) }}" style="margin:0;" class="single-action-form">
                                            @csrf
                                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                                            <button type="submit" class="action-item">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                                                Sync Qty
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('ext.tiktok.product-groups.pushPrices', $group->id) }}" style="margin:0;" class="single-action-form">
                                            @csrf
                                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                                            <button type="submit" class="action-item">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                                Sync Price
                                            </button>
                                        </form>
                                        <div style="border-top:1px solid var(--border); margin:4px 0;"></div>
                                        <form method="POST" action="{{ route('ext.tiktok.product-groups.deleteFromTikTok', $group->id) }}" style="margin:0;" class="single-action-form" data-confirm="Delete this product from TikTok Shop? This cannot be undone.">
                                            @csrf
                                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                                            <button type="submit" class="action-item" style="color:var(--danger);">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                                Delete from TikTok
                                            </button>
                                        </form>
                                    @endif
                                    @if($hasTikTokId)
                                        <div style="border-top:1px solid var(--border); margin:4px 0;"></div>
                                        <form method="POST" action="{{ route('ext.tiktok.product-groups.unlinkProduct', [$group->id, $p->product_id]) }}" style="margin:0;" class="single-action-form" data-confirm="Unlink this product from TikTok? The product stays in the group but sync will stop.">
                                            @csrf
                                            <button type="submit" class="action-item" style="color:var(--warning);">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>
                                                Unlink from TikTok
                                            </button>
                                        </form>
                                    @endif
                                    @if($isUnlinked)
                                        <form method="POST" action="{{ route('ext.tiktok.product-groups.linkProduct', [$group->id, $p->product_id]) }}" style="margin:0;" class="single-action-form">
                                            @csrf
                                            <button type="submit" class="action-item">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                                Link to TikTok
                                            </button>
                                        </form>
                                    @endif
                                    @if(in_array((int)$p->product_id, $manualIds))
                                        <div style="border-top:1px solid var(--border); margin:4px 0;"></div>
                                        <form method="POST" action="{{ route('ext.tiktok.product-groups.removeProduct', [$group->id, $p->product_id]) }}" style="margin:0;" data-confirm="Remove this product from the group?">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="action-item" style="color:var(--danger);">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>
                                                Remove from Group
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="13" class="text-muted">No products found. Edit the group filters or add products manually below.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-between items-center mt-12">
            <div class="text-muted text-xs">
                Showing {{ $products->firstItem() ?? 0 }} to {{ $products->lastItem() ?? 0 }} of {{ $products->total() }}
            </div>
            <div>
                {{ $products->onEachSide(1)->links('vendor.pagination.simple') }}
            </div>
        </div>
    </div>

    {{-- Add Individual Products --}}
    <div class="card mt-16">
        <h3 class="section-title mt-0">Add Products Manually</h3>
        <div class="hint" style="margin-bottom:10px;">Search for products to add individually to this product group.</div>

        <form id="add-products-form" method="POST" action="{{ route('ext.tiktok.product-groups.addProducts', $group->id) }}">
            @csrf
        </form>

        <div class="d-flex gap-8 items-center flex-wrap">
            <input type="text" class="input" id="manual-search" placeholder="Search by name or SKU..." style="min-width:260px;">
            <button type="button" class="btn" id="manual-search-btn">Search</button>
            <button type="button" class="btn" id="add-selected-btn">Add Selected</button>
        </div>

        <div class="table-wrap mt-10" id="manual-results-wrap" style="display:none;">
            <table class="table" id="manual-results-table">
                <thead>
                <tr>
                    <th style="width:36px;"><input type="checkbox" id="manual-select-all"></th>
                    <th style="width:70px;">ID</th>
                    <th>Name</th>
                    <th style="width:130px;">SKU</th>
                    <th style="width:100px;">Price</th>
                </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

<div class="bulk-loading-overlay" id="loadingOverlay">
    <div class="bulk-loading-box">
        <div class="spinner"></div>
        <div class="msg" id="loadingMsg">Processing...</div>
    </div>
</div>

@push('scripts')
<script>
(function(){
    var table = document.getElementById('tiktokProductsTable');
    if (!table) return;

    var checkAll = document.getElementById('checkAll');
    var bulkBar = document.getElementById('bulk-bar');
    var bulkCount = document.getElementById('bulk-count');

    function rowChecks() { return Array.from(table.querySelectorAll('.row-check')); }

    function updateState() {
        var boxes = rowChecks();
        var checked = boxes.filter(function(b){ return b.checked; }).length;

        if (checkAll) {
            if (checked === 0) {
                checkAll.indeterminate = false;
                checkAll.checked = false;
            } else if (checked === boxes.length) {
                checkAll.indeterminate = false;
                checkAll.checked = true;
            } else {
                checkAll.indeterminate = true;
                checkAll.checked = false;
            }
        }

        if (bulkBar) bulkBar.style.display = checked > 0 ? 'flex' : 'none';
        if (bulkCount) bulkCount.textContent = checked;
    }

    if (checkAll) {
        checkAll.addEventListener('change', function(){
            rowChecks().forEach(function(b){ b.checked = checkAll.checked; });
            updateState();
        });
    }

    rowChecks().forEach(function(b){ b.addEventListener('change', updateState); });

    // Helper: inject ids[] into a bulk form
    function injectBulkIds(form, ids) {
        form.querySelectorAll('input[name="ids[]"]').forEach(function(n){ n.remove(); });
        ids.forEach(function(id){
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'ids[]';
            inp.value = id;
            form.appendChild(inp);
        });
    }

    function submitBulkForm(form, ids) {
        injectBulkIds(form, ids);
        var overlay = document.getElementById('loadingOverlay');
        var msg = document.getElementById('loadingMsg');
        if (overlay) {
            if (msg) msg.textContent = 'Processing ' + ids.length + ' item(s)...';
            overlay.classList.add('active');
        }
        var btn = form.querySelector('button[type="submit"]');
        if (btn) btn.disabled = true;
        form.submit();
    }

    // Bulk action forms
    document.querySelectorAll('form.bulk-action-form').forEach(function(form){
        form.addEventListener('submit', function(e){
            e.preventDefault();

            var ids = rowChecks().filter(function(b){ return b.checked; }).map(function(b){ return b.value; });
            if (!ids.length) {
                showFlashError('Please select at least 1 product.');
                return;
            }

            if (form.classList.contains('bulk-push-form')) {
                confirmModal('Push ' + ids.length + ' selected product(s) to TikTok Shop? Already-synced products will be skipped.').then(function(ok) {
                    if (ok) submitBulkForm(form, ids);
                });
                return;
            }

            if (form.classList.contains('bulk-delete-form')) {
                confirmModal('Delete ' + ids.length + ' selected product(s) from TikTok? This cannot be undone.').then(function(ok) {
                    if (ok) submitBulkForm(form, ids);
                });
                return;
            }

            // No confirm needed — submit directly
            submitBulkForm(form, ids);
        });
    });

    // Loading overlay for single action forms (in action dropdown)
    document.querySelectorAll('.action-menu form.single-action-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (e.defaultPrevented) return;
            var overlay = document.getElementById('loadingOverlay');
            var msg = document.getElementById('loadingMsg');
            if (overlay) {
                if (msg) msg.textContent = 'Processing...';
                overlay.classList.add('active');
            }
        });
    });

    // Action dropdown toggle
    var openMenu = null;
    var tableWrap = table ? table.closest('.table-wrap') : null;

    function closeMenu() {
        if (openMenu) {
            openMenu.style.display = 'none';
            openMenu = null;
        }
        if (tableWrap) tableWrap.classList.remove('menu-open');
    }

    document.addEventListener('click', function(e) {
        var toggle = e.target.closest('.action-toggle');
        if (toggle) {
            e.stopPropagation();
            var menu = toggle.nextElementSibling;
            if (!menu) return;

            if (openMenu && openMenu !== menu) {
                openMenu.style.display = 'none';
            }

            if (menu.style.display === 'none') {
                menu.style.display = 'block';
                openMenu = menu;
                if (tableWrap) tableWrap.classList.add('menu-open');
            } else {
                closeMenu();
            }
            return;
        }

        if (openMenu && !e.target.closest('.action-menu')) {
            closeMenu();
        }
    });

    // ── Manual Product Search ──────────────────────────────────
    var searchInput = document.getElementById('manual-search');
    var searchBtn = document.getElementById('manual-search-btn');
    var resultsWrap = document.getElementById('manual-results-wrap');
    var resultsBody = document.querySelector('#manual-results-table tbody');
    var addBtn = document.getElementById('add-selected-btn');
    var manualSelectAll = document.getElementById('manual-select-all');
    var searchUrl = @json(route('ext.tiktok.product-groups.searchProducts'));

    function doSearch() {
        var q = (searchInput.value || '').trim();
        if (q.length < 2) return;
        fetch(searchUrl + '?q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var items = data.items || [];
            resultsBody.textContent = '';
            if (items.length === 0) {
                var tr = document.createElement('tr');
                var td = document.createElement('td');
                td.setAttribute('colspan', '5'); td.className = 'text-muted'; td.textContent = 'No results.';
                tr.appendChild(td); resultsBody.appendChild(tr);
            } else {
                items.forEach(function (item) {
                    var tr = document.createElement('tr');
                    var td1 = document.createElement('td');
                    var cb = document.createElement('input');
                    cb.type = 'checkbox'; cb.className = 'manual-row-check'; cb.value = item.product_id;
                    cb.setAttribute('form', 'add-products-form'); cb.name = 'ids[]';
                    td1.appendChild(cb); tr.appendChild(td1);

                    var td2 = document.createElement('td'); td2.textContent = item.product_id; tr.appendChild(td2);
                    var td3 = document.createElement('td'); td3.textContent = item.name || '-'; tr.appendChild(td3);
                    var td4 = document.createElement('td'); td4.textContent = item.sku || '-'; tr.appendChild(td4);
                    var td5 = document.createElement('td'); td5.textContent = item.price ? parseFloat(item.price).toFixed(2) : '0.00'; tr.appendChild(td5);
                    resultsBody.appendChild(tr);
                });
            }
            resultsWrap.style.display = '';
        })
        .catch(function () {
            resultsBody.textContent = '';
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.setAttribute('colspan', '5'); td.className = 'text-muted'; td.textContent = 'Search failed.';
            tr.appendChild(td); resultsBody.appendChild(tr);
            resultsWrap.style.display = '';
        });
    }

    if (searchBtn) searchBtn.addEventListener('click', doSearch);
    if (searchInput) searchInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });
    if (manualSelectAll) {
        manualSelectAll.addEventListener('change', function () {
            document.querySelectorAll('.manual-row-check').forEach(function (b) { b.checked = manualSelectAll.checked; });
        });
    }
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            var selected = document.querySelectorAll('.manual-row-check:checked');
            if (selected.length === 0) { showFlashError('Select at least one product to add.'); return; }
            document.getElementById('add-products-form').submit();
        });
    }
    // Inject _return field into all POST forms so redirects preserve query string
    var returnUrl = location.pathname + location.search;
    document.querySelectorAll('form[method="POST"]').forEach(function(form) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = '_return'; inp.value = returnUrl;
        form.appendChild(inp);
    });

    // Dismiss loading overlay when page is restored from bfcache
    window.addEventListener('pageshow', function(e) {
        if (e.persisted) {
            var overlay = document.getElementById('loadingOverlay');
            if (overlay) overlay.classList.remove('active');
        }
    });
})();
</script>
@endpush
@endsection
