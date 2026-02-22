@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Shopee / Products')

@section('title', 'Shopee Products')

@section('content')
    <div class="page-header">
        <div>
            <h2>Shopee Products</h2>
            <div class="text-muted text-sm">Push catalog products to Shopee and sync stock/price.</div>
        </div>
        <div class="page-header-actions">
            <form method="POST" action="{{ route('shopee.products.rebuild_cache') }}" style="margin:0; display:inline;">
                @csrf
                <button type="submit" class="btn secondary" onclick="return confirm('Rebuild the Shopee product cache? This scans all Shopee items and may take a minute.')">Rebuild Cache</button>
            </form>
            <a class="btn secondary" href="{{ route('shopee.profiles.index') }}">Profiles</a>
            <a class="btn secondary" href="{{ route('shopee.index') }}">Shopee Settings</a>
        </div>
    </div>

    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif

    @if(session('error'))
        <div class="alert danger">{{ session('error') }}</div>
    @endif

    {{-- Unmatched Items Section --}}
    @if(isset($unmatchedItems) && $unmatchedItems->count() > 0)
        <div class="card mb-12" id="unmatched-section">
            <div class="d-flex justify-between items-center" style="margin-bottom:12px;">
                <div>
                    <h3 class="section-title mt-0 mb-0">Unmatched Shopee Items</h3>
                    <div class="text-muted text-xs" style="margin-top:2px;">{{ $unmatchedItems->count() }} item(s) on Shopee could not be matched by SKU. Link them manually or dismiss.</div>
                </div>
            </div>
            <div class="table-wrap" style="min-height:auto;">
                <table class="table">
                    <thead>
                    <tr>
                        <th style="width:56px;">Image</th>
                        <th>Shopee Item</th>
                        <th>SKU</th>
                        <th style="width:50px;">Item ID</th>
                        <th style="width:320px;">Link to Catalog Product</th>
                        <th style="width:80px;"></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($unmatchedItems as $ui)
                        <tr>
                            <td>
                                @if($ui->image_url)
                                    <img src="{{ $ui->image_url }}" alt="" style="width:40px; height:40px; object-fit:cover; border-radius:4px;">
                                @else
                                    <span class="text-muted text-xs">-</span>
                                @endif
                            </td>
                            <td>
                                <div class="font-bold">{{ $ui->item_name ?? '-' }}</div>
                            </td>
                            <td><code>{{ $ui->sku ?? '-' }}</code></td>
                            <td>
                                <span class="text-xs text-muted">{{ $ui->shopee_item_id }}</span>
                                @if($ui->shopee_model_id)
                                    <br><span class="text-xs text-muted">M: {{ $ui->shopee_model_id }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="unmatched-link-row" data-id="{{ $ui->id }}" style="display:flex; gap:6px; align-items:center;">
                                    <input type="text" class="input unmatched-search" placeholder="Search product..." style="flex:1; font-size:12px; padding:4px 8px;" autocomplete="off">
                                    <div class="unmatched-results" style="display:none; position:absolute; z-index:60; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md); box-shadow:var(--shadow-lg); max-height:200px; overflow-y:auto; width:300px; margin-top:4px;"></div>
                                    <form method="POST" action="{{ route('shopee.products.link_unmatched', $ui->id) }}" class="unmatched-link-form" style="margin:0; display:flex; gap:4px;">
                                        @csrf
                                        <input type="hidden" name="product_id" class="unmatched-product-id" value="">
                                        <button type="submit" class="btn small" disabled title="Select a product first">Link</button>
                                    </form>
                                </div>
                            </td>
                            <td>
                                <form method="POST" action="{{ route('shopee.products.dismiss_unmatched', $ui->id) }}" style="margin:0;">
                                    @csrf
                                    <button type="submit" class="btn small secondary" title="Dismiss">Dismiss</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Filter toolbar --}}
    <div class="card mb-12">
        <form method="GET" action="{{ route('shopee.products.index') }}" style="display:grid; grid-template-columns:1fr auto auto; gap:10px; align-items:end;">
            <div>
                <label class="text-xs text-muted">Search</label>
                <input type="text" name="q" class="input" value="{{ $q ?? '' }}" placeholder="Product name or SKU...">
            </div>
            <div>
                <label class="text-xs text-muted">Shopee Status</label>
                <select name="shopee_status" class="input">
                    <option value="all" {{ ($shopeeStatus ?? 'all') === 'all' ? 'selected' : '' }}>All</option>
                    <option value="listed" {{ ($shopeeStatus ?? '') === 'listed' ? 'selected' : '' }}>Listed</option>
                    <option value="not_listed" {{ ($shopeeStatus ?? '') === 'not_listed' ? 'selected' : '' }}>Not Listed</option>
                </select>
            </div>
            <div class="d-flex gap-6">
                <button type="submit" class="btn small">Filter</button>
                @if(($q ?? '') !== '' || ($shopeeStatus ?? 'all') !== 'all')
                    <a href="{{ route('shopee.products.index') }}" class="btn small secondary">Clear</a>
                @endif
            </div>
        </form>
    </div>

    <div class="card">
        {{-- Bulk action bar --}}
        <div id="bulk-bar" style="display:none; padding:10px 16px; margin-bottom:12px; background:var(--accent-light); border:1px solid rgba(59,130,246,.2); border-radius:var(--radius-md); align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
            <span style="font-size:13px; font-weight:600; color:var(--accent);"><span id="bulk-count">0</span> selected</span>
            <div class="d-flex gap-6 flex-wrap">
                <form method="POST" action="{{ route('shopee.products.sync_ids') }}" class="bulk-action-form" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small">Sync Product IDs</button>
                </form>
                <form method="POST" action="{{ route('shopee.products.bulk_sync_quantity') }}" class="bulk-action-form" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small">Sync Qty</button>
                </form>
                <form method="POST" action="{{ route('shopee.products.bulk_sync_price') }}" class="bulk-action-form" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small">Sync Price</button>
                </form>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table" id="shopeeProductsTable">
                <thead>
                <tr>
                    <th style="width:36px;"><input type="checkbox" id="checkAll" title="Select all"></th>
                    <th style="width:56px;">Image</th>
                    <th>Name</th>
                    <th>Model / SKU</th>
                    <th style="width:70px;">Price</th>
                    <th style="width:60px;">Stock</th>
                    <th>Manufacturer</th>
                    <th>Shopee Item ID</th>
                    <th style="width:120px;">Shopee Status</th>
                    <th style="width:140px;">Sync</th>
                    <th style="width:50px;"></th>
                </tr>
                </thead>
                <tbody>
                @forelse($products as $p)
                    @php
                        $links = $shopeeLinks->get($p->product_id, collect());
                        $link = $links->first();
                    @endphp
                    <tr>
                        <td><input type="checkbox" class="row-check" value="{{ $p->product_id }}"></td>
                        <td>
                            @if($p->image)
                                <img src="{{ asset('storage/' . ltrim($p->image, '/')) }}" alt="" style="width:40px; height:40px; object-fit:cover; border-radius:4px;">
                            @else
                                <span class="text-muted text-xs">-</span>
                            @endif
                        </td>
                        <td>
                            <div class="font-bold">{{ $p->name }}</div>
                            @php
                                $optRows = isset($optionRowsByProductId) ? $optionRowsByProductId->get($p->product_id) : null;
                            @endphp
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
                        <td>
                            <code>{{ $p->model ?: '-' }}</code>
                            @if($p->sku && $p->sku !== $p->model)
                                <div class="text-xs text-muted" style="margin-top:2px;">{{ $p->sku }}</div>
                            @endif
                        </td>
                        <td>{{ number_format((float)$p->price, 2) }}</td>
                        <td>{{ (int)$p->quantity }}</td>
                        <td>{{ $p->manufacturer_name ?? '-' }}</td>
                        <td>
                            @if($link)
                                <code class="text-xs">{{ $link->shopee_item_id }}</code>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>
                            @if($link)
                                <span class="badge badge-green">Listed</span>
                            @else
                                <span class="badge badge-yellow">Not Listed</span>
                            @endif
                        </td>
                        <td>
                            @if(!$link || is_null($link->last_sync_ok))
                                <span class="text-muted">-</span>
                            @else
                                <div style="line-height:1.4;">
                                    @if(!$link->last_sync_ok)
                                        <span class="badge badge-red">Err</span>
                                        @if($link->last_sync_error_code || $link->last_sync_error_message)
                                            <div class="text-xs" style="margin-top:3px; color:var(--danger); word-break:break-word;">{{ $link->last_sync_error_code }}@if($link->last_sync_error_message): {{ Str::limit($link->last_sync_error_message, 60) }}@endif</div>
                                        @endif
                                    @else
                                        <span class="badge badge-green">OK</span>
                                    @endif
                                    @if($link->last_sync_action)
                                        <div class="text-xs text-muted" style="margin-top:2px;">{{ $link->last_sync_action }}</div>
                                    @endif
                                    @if($link->last_synced_at)
                                        <div class="text-xs text-muted" style="margin-top:1px;">{{ $link->last_synced_at->diffForHumans() }}</div>
                                    @endif
                                </div>
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
                                        Edit
                                    </a>
                                    @if(!$link)
                                        <a href="{{ route('shopee.products.push', $p->product_id) }}" class="action-item">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                                            Push to Shopee
                                        </a>
                                    @endif
                                    @if(!$link)
                                        <form method="POST" action="{{ route('shopee.products.sync_shopee_id', $p->product_id) }}" style="margin:0;">
                                            @csrf
                                            <button type="submit" class="action-item">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                                                Sync Shopee ID
                                            </button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('shopee.products.sync_quantity', $p->product_id) }}" style="margin:0;">
                                        @csrf
                                        <button type="submit" class="action-item">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                                            Sync Qty
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('shopee.products.sync_price', $p->product_id) }}" style="margin:0;">
                                        @csrf
                                        <button type="submit" class="action-item">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                            Sync Price
                                        </button>
                                    </form>
                                    @if($link)
                                        <div style="border-top:1px solid var(--border); margin:4px 0;"></div>
                                        <form method="POST" action="{{ route('shopee.products.unlink', $p->product_id) }}" style="margin:0;" onsubmit="return confirm('Unlink this product from Shopee? This removes the link only — the Shopee listing is not deleted. You can re-sync afterwards.')">
                                            @csrf
                                            <button type="submit" class="action-item" style="color:var(--danger);">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>
                                                Unlink from Shopee
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="text-muted">No products found. Create a <a href="{{ route('shopee.profiles.index') }}">Shopee Profile</a> to filter catalog products.</td>
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
                {{ $products->links('vendor.pagination.simple') }}
            </div>
        </div>
    </div>

<div class="bulk-loading-overlay" id="loadingOverlay">
    <div class="bulk-loading-box">
        <div class="spinner"></div>
        <div class="msg" id="loadingMsg">Processing...</div>
    </div>
</div>

@push('scripts')
<style>
    .action-item {
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
        padding: 8px 14px;
        border: none;
        background: none;
        cursor: pointer;
        font-family: inherit;
        font-size: 13px;
        font-weight: 500;
        color: var(--text-primary);
        text-decoration: none;
        text-align: left;
        transition: background var(--transition-fast);
        white-space: nowrap;
    }
    .action-item:hover { background: var(--surface-hover); }
    .action-item svg { flex-shrink: 0; opacity: .6; }
    .table-wrap.menu-open { overflow: visible; }
    .table-wrap { min-height: 280px; }

    /* Bulk action loading overlay */
    .bulk-loading-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9999;
        background: rgba(0,0,0,.35);
        align-items: center;
        justify-content: center;
    }
    .bulk-loading-overlay.active { display: flex; }
    .bulk-loading-box {
        background: var(--surface);
        border-radius: var(--radius-lg);
        padding: 28px 36px;
        box-shadow: var(--shadow-lg);
        text-align: center;
        max-width: 320px;
    }
    .bulk-loading-box .spinner {
        display: inline-block;
        width: 28px; height: 28px;
        border: 3px solid var(--border);
        border-top-color: var(--accent);
        border-radius: 50%;
        animation: spin .7s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .bulk-loading-box .msg {
        margin-top: 12px;
        font-size: 14px;
        font-weight: 600;
        color: var(--text-primary);
    }

    .unmatched-link-row { position: relative; }
    .unmatched-results {
        top: 100%;
        left: 0;
    }
    .unmatched-result-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 10px;
        cursor: pointer;
        font-size: 12px;
        transition: background 0.1s;
    }
    .unmatched-result-item:hover { background: var(--surface-hover); }
    .unmatched-result-item img { width: 28px; height: 28px; object-fit: cover; border-radius: 3px; flex-shrink: 0; }
    .unmatched-result-item .result-info { flex: 1; min-width: 0; }
    .unmatched-result-item .result-name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .unmatched-result-item .result-sku { color: var(--text-muted); font-size: 11px; }
</style>
<script>
(function(){
    var table = document.getElementById('shopeeProductsTable');
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

    // Bulk action forms: inject selected product IDs as hidden inputs
    document.querySelectorAll('form.bulk-action-form').forEach(function(form){
        form.addEventListener('submit', function(e){
            form.querySelectorAll('input[name="product_ids[]"]').forEach(function(n){ n.remove(); });

            var ids = rowChecks().filter(function(b){ return b.checked; }).map(function(b){ return b.value; });
            if (!ids.length) {
                e.preventDefault();
                alert('Please select at least 1 product.');
                return;
            }

            ids.forEach(function(id){
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'product_ids[]';
                inp.value = id;
                form.appendChild(inp);
            });

            // Show loading overlay
            var overlay = document.getElementById('loadingOverlay');
            var msg = document.getElementById('loadingMsg');
            if (overlay) {
                if (msg) msg.textContent = 'Processing ' + ids.length + ' item(s)...';
                overlay.classList.add('active');
            }
            form.querySelector('button[type="submit"]').disabled = true;
        });
    });

    // Loading overlay for individual action forms
    document.querySelectorAll('.action-menu form').forEach(function(form) {
        form.addEventListener('submit', function() {
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

    // ── Unmatched Items: Product Search ──────────────────────────
    var searchUrl = @json(route('shopee.products.search_catalog'));
    var debounceTimers = {};

    document.querySelectorAll('.unmatched-link-row').forEach(function(row) {
        var searchInput = row.querySelector('.unmatched-search');
        var resultsDiv = row.querySelector('.unmatched-results');
        var productIdInput = row.querySelector('.unmatched-product-id');
        var linkBtn = row.querySelector('.unmatched-link-form button[type="submit"]');
        var rowId = row.dataset.id;

        if (!searchInput || !resultsDiv) return;

        searchInput.addEventListener('input', function() {
            var q = searchInput.value.trim();
            if (q.length < 2) {
                resultsDiv.style.display = 'none';
                resultsDiv.innerHTML = '';
                return;
            }

            clearTimeout(debounceTimers[rowId]);
            debounceTimers[rowId] = setTimeout(function() {
                fetch(searchUrl + '?q=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(items) {
                        if (!items.length) {
                            resultsDiv.innerHTML = '<div style="padding:8px 10px; font-size:12px; color:var(--text-muted);">No products found</div>';
                            resultsDiv.style.display = 'block';
                            return;
                        }
                        resultsDiv.innerHTML = items.map(function(item) {
                            var imgHtml = item.image ? '<img src="' + item.image + '" alt="">' : '';
                            var optsHtml = '';
                            if (item.options && item.options.length) {
                                optsHtml = '<div style="margin-top:2px;">' + item.options.map(function(o) {
                                    return '<div style="font-size:10px; color:var(--text-muted);">' + (o.name ? o.name + ' - ' : '') + 'SKU: ' + o.sku + ' (Qty: ' + o.qty + ')</div>';
                                }).join('') + '</div>';
                            }
                            return '<div class="unmatched-result-item" data-pid="' + item.product_id + '">'
                                + imgHtml
                                + '<div class="result-info">'
                                + '<div class="result-name">' + item.name + '</div>'
                                + '<div class="result-sku">' + (item.model || '-') + (item.sku && item.sku !== item.model ? ' / ' + item.sku : '') + ' | ID: ' + item.product_id + '</div>'
                                + optsHtml
                                + '</div></div>';
                        }).join('');
                        resultsDiv.style.display = 'block';
                    })
                    .catch(function() {
                        resultsDiv.style.display = 'none';
                    });
            }, 300);
        });

        resultsDiv.addEventListener('click', function(e) {
            var item = e.target.closest('.unmatched-result-item');
            if (!item) return;
            var pid = item.dataset.pid;
            var name = item.querySelector('.result-name');
            productIdInput.value = pid;
            searchInput.value = (name ? name.textContent : '') + ' (#' + pid + ')';
            resultsDiv.style.display = 'none';
            if (linkBtn) linkBtn.disabled = false;
        });

        // Close results on outside click
        document.addEventListener('click', function(e) {
            if (!row.contains(e.target)) {
                resultsDiv.style.display = 'none';
            }
        });
    });
})();
</script>
@endpush
@endsection
