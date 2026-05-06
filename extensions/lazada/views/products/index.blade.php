@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Lazada / Products')

@section('title', 'Lazada Products')

@section('content')
    <div class="page-header">
        <div>
            <h2>Lazada Products</h2>
            <div class="text-muted text-sm">Map catalog products to Lazada category + required attributes.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('ext.lazada.product-groups.index') }}">Product Groups</a>
            <a class="btn" href="{{ route('ext.lazada.products.create') }}">Add Product</a>
        </div>
    </div>

    {{-- Unmatched Items Section --}}
    @if(isset($unmatchedItems) && $unmatchedItems->count() > 0)
        <div class="card mb-12" id="lazada-unmatched-section">
            <div class="d-flex justify-between items-center" style="margin-bottom:12px;">
                <div>
                    <h3 class="section-title mt-0 mb-0">Unmatched Lazada Items</h3>
                    <div class="text-muted text-xs" style="margin-top:2px;">{{ $unmatchedItems->count() }} item(s) on Lazada are not linked to any ERP product. Link them manually or dismiss.</div>
                </div>
                <form method="POST" action="{{ route('ext.lazada.products.sync_unmatched') }}" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small secondary">Re-scan</button>
                </form>
            </div>
            <div class="table-wrap" style="min-height:auto;">
                <table class="table">
                    <thead>
                    <tr>
                        <th style="width:56px;">Image</th>
                        <th>Lazada Item</th>
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
                                <span class="text-xs text-muted">{{ $ui->lazada_item_id }}</span>
                            </td>
                            <td>
                                <div class="lazada-unmatched-link-row" data-id="{{ $ui->id }}" style="display:flex; gap:6px; align-items:center; position:relative;">
                                    <input type="text" class="input lazada-unmatched-search" placeholder="Search product..." style="flex:1; font-size:12px; padding:4px 8px;" autocomplete="off">
                                    <div class="lazada-unmatched-results" style="display:none; position:absolute; z-index:60; top:100%; left:0; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md); box-shadow:var(--shadow-lg); max-height:200px; overflow-y:auto; width:300px; margin-top:4px;"></div>
                                    <form method="POST" action="{{ route('ext.lazada.products.link_unmatched', $ui->id) }}" class="lazada-unmatched-link-form" style="margin:0; display:flex; gap:4px;">
                                        @csrf
                                        <input type="hidden" name="product_id" class="lazada-unmatched-product-id" value="">
                                        <button type="submit" class="btn small" disabled title="Select a product first">Link</button>
                                    </form>
                                </div>
                            </td>
                            <td>
                                <form method="POST" action="{{ route('ext.lazada.products.dismiss_unmatched', $ui->id) }}" style="margin:0;">
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

    {{-- Sync Unmatched button in header area --}}
    @if(!isset($unmatchedItems) || $unmatchedItems->count() === 0)
        <div style="margin-bottom:12px;">
            <form method="POST" action="{{ route('ext.lazada.products.sync_unmatched') }}" style="display:inline;">
                @csrf
                <button type="submit" class="btn small secondary">Scan for Unmatched Lazada Items</button>
            </form>
        </div>
    @endif

    <div class="card">
        {{-- Filter toolbar --}}
        <form method="GET" action="{{ route('ext.lazada.products.index') }}" style="display:grid; grid-template-columns:1fr auto auto auto auto auto; gap:10px; align-items:end; margin-bottom:16px;">
            <div>
                <label class="text-xs text-muted">Search</label>
                <input type="text" name="q" class="input" value="{{ $q ?? '' }}" placeholder="Name, model or SKU...">
            </div>
            <div>
                <label class="text-xs text-muted">Manufacturer</label>
                <select name="manufacturer" class="input">
                    <option value="all">All Manufacturers</option>
                    @foreach(($allManufacturers ?? collect()) as $mId => $mName)
                        <option value="{{ $mId }}" {{ (string)($manufacturerFilter ?? '') === (string)$mId ? 'selected' : '' }}>{{ $mName }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-muted">Product Group</label>
                <select name="group" class="input">
                    <option value="all" {{ ($groupFilter ?? 'all') === 'all' ? 'selected' : '' }}>All Groups</option>
                    <option value="none" {{ ($groupFilter ?? '') === 'none' ? 'selected' : '' }}>No Group</option>
                    @foreach(($allGroups ?? collect()) as $gId => $gName)
                        <option value="{{ $gId }}" {{ (string)($groupFilter ?? '') === (string)$gId ? 'selected' : '' }}>{{ $gName }}</option>
                    @endforeach
                </select>
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
                    <option value="uploaded" {{ ($syncStatus ?? '') === 'uploaded' ? 'selected' : '' }}>Uploaded</option>
                    <option value="not_uploaded" {{ ($syncStatus ?? '') === 'not_uploaded' ? 'selected' : '' }}>Pending</option>
                    <option value="deleted" {{ ($syncStatus ?? '') === 'deleted' ? 'selected' : '' }}>Deleted</option>
                </select>
            </div>
            @if(!empty($sort))<input type="hidden" name="sort" value="{{ $sort }}">@endif
            @if(!empty($dir))<input type="hidden" name="dir" value="{{ $dir }}">@endif
            <div class="d-flex gap-6">
                <button type="submit" class="btn small">Filter</button>
                @if(($q ?? '') !== '' || ($syncStatus ?? 'all') !== 'all' || ($manufacturerFilter ?? 'all') !== 'all' || ($groupFilter ?? 'all') !== 'all' || ($erpStatus ?? 'all') !== 'all')
                    <a href="{{ route('ext.lazada.products.index') }}" class="btn small secondary">Clear</a>
                @endif
            </div>
        </form>

        @php
            $bulkUploadUrl = \Illuminate\Support\Facades\Route::has('lazada.products.bulk_upload')
                ? route('ext.lazada.products.bulk_upload')
                : url('/lazada/listings/bulk/upload');
            $bulkSyncQtyUrl = \Illuminate\Support\Facades\Route::has('lazada.products.bulk_sync_quantity')
                ? route('ext.lazada.products.bulk_sync_quantity')
                : url('/lazada/listings/bulk/sync/quantity');
            $bulkSyncPriceUrl = \Illuminate\Support\Facades\Route::has('lazada.products.bulk_sync_price')
                ? route('ext.lazada.products.bulk_sync_price')
                : url('/lazada/listings/bulk/sync/price');
            $bulkSyncLazadaIdUrl = \Illuminate\Support\Facades\Route::has('lazada.products.bulk_sync_lazada_id')
                ? route('ext.lazada.products.bulk_sync_lazada_id')
                : url('/lazada/products/bulk/sync/lazada-id');
            $bulkDeleteUrl = \Illuminate\Support\Facades\Route::has('lazada.products.bulk_delete')
                ? route('ext.lazada.products.bulk_delete')
                : url('/lazada/products/bulk/delete');
        @endphp

        {{-- Bulk action bar (hidden until items selected) --}}
        <div id="bulk-bar" style="display:none; padding:10px 16px; margin-bottom:12px; background:var(--accent-light); border:1px solid rgba(59,130,246,.2); border-radius:var(--radius-md); align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
            <span style="font-size:13px; font-weight:600; color:var(--accent);"><span id="bulk-count">0</span> selected</span>
            <div class="d-flex gap-6 flex-wrap">
                <form method="POST" action="{{ $bulkUploadUrl }}" class="bulk-action-form" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small">Upload</button>
                </form>
                <form method="POST" action="{{ $bulkSyncLazadaIdUrl }}" class="bulk-action-form" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small">Sync Lazada ID</button>
                </form>
                <form method="POST" action="{{ $bulkSyncQtyUrl }}" class="bulk-action-form" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small">Sync Qty</button>
                </form>
                <form method="POST" action="{{ $bulkSyncPriceUrl }}" class="bulk-action-form" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small">Sync Price</button>
                </form>
                <form method="POST" action="{{ $bulkDeleteUrl }}" class="bulk-action-form bulk-delete-form" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn small danger">Delete from Lazada</button>
                </form>
            </div>
        </div>


        <div class="table-wrap">
<table class="table" id="lazadaListingsTable">
    <thead>
    <tr>
        @php
            $sort = $sort ?? 'id';
            $dir = $dir ?? 'desc';
            $toggleDir = function(string $col) use ($sort, $dir) {
                if ($sort !== $col) return 'asc';
                return $dir === 'asc' ? 'desc' : 'asc';
            };
            $sortIcon = function(string $col) use ($sort, $dir) {
                if ($sort !== $col) return '';
                return $dir === 'asc' ? ' ▲' : ' ▼';
            };
            $sortUrl = function(string $col) use ($toggleDir) {
                $qp = request()->query();
                $qp['sort'] = $col;
                $qp['dir'] = $toggleDir($col);
                unset($qp['page']);
                return url()->current() . '?' . http_build_query($qp);
            };
        @endphp

        <th style="width:36px;"><input type="checkbox" id="checkAll" title="Select all"></th>
        <th style="width:60px;"><a href="{{ $sortUrl('id') }}" style="text-decoration:none; color:inherit;">ID{!! $sortIcon('id') !!}</a></th>
        <th style="width:56px;">Image</th>
        <th><a href="{{ $sortUrl('product') }}" style="text-decoration:none; color:inherit;">Product Name{!! $sortIcon('product') !!}</a></th>
        <th>SKU</th>
        <th><a href="{{ $sortUrl('manufacturer') }}" style="text-decoration:none; color:inherit;">Manufacturer{!! $sortIcon('manufacturer') !!}</a></th>
        <th style="width:120px;"><a href="{{ $sortUrl('group') }}" style="text-decoration:none; color:inherit;">Group{!! $sortIcon('group') !!}</a></th>
        <th style="width:60px;"><a href="{{ $sortUrl('quantity') }}" style="text-decoration:none; color:inherit;">Stock{!! $sortIcon('quantity') !!}</a></th>
        <th style="width:80px;"><a href="{{ $sortUrl('price') }}" style="text-decoration:none; color:inherit;">Price{!! $sortIcon('price') !!}</a></th>
        <th style="width:130px;"><a href="{{ $sortUrl('lazada_item_id') }}" style="text-decoration:none; color:inherit;">Lazada ID{!! $sortIcon('lazada_item_id') !!}</a></th>
        <th style="width:90px;"><a href="{{ $sortUrl('product_status') }}" style="text-decoration:none; color:inherit;">ERP Status{!! $sortIcon('product_status') !!}</a></th>
        <th style="width:100px;"><a href="{{ $sortUrl('lazada_status') }}" style="text-decoration:none; color:inherit;">Sync Status{!! $sortIcon('lazada_status') !!}</a></th>
        <th style="width:160px;">Error</th>
        <th style="width:50px;"></th>
    </tr>
    </thead>
    <tbody>
    @forelse($listings as $l)
        @php
            $p = $productsById->get($l->product_id);
            $thumb = $productThumbsById->get($l->product_id);
            $pName = $p ? html_entity_decode((string)($p->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
            $pSku = $p ? (string)($p->sku ?? '') : '';
            $pModel = $p ? (string)($p->model ?? '') : '';
            $pQty = $p ? (int)($p->quantity ?? 0) : null;
            $pStatus = $p ? (int)($p->status ?? 0) : null;
            $pMfg = $p ? (string)($p->manufacturer_name ?? '') : '';

            $isDeleted = !is_null($l->lazada_deleted_at);
            $hasItemId = !is_null($l->lazada_item_id) && (string)$l->lazada_item_id !== '';
            $isUnlinked = !is_null($l->unlinked_at);

            $hasSyncInfo = !is_null($l->last_synced_at) || !is_null($l->last_sync_ok);
            $hasError = $hasSyncInfo && !is_null($l->last_sync_ok) && !$l->last_sync_ok;
            $errCode = (string)($l->last_sync_error_code ?? '');
            $errMsg = (string)($l->last_sync_error_message ?? '');

            $optRows = isset($optionRowsByProductId) ? $optionRowsByProductId->get($l->product_id) : null;
        @endphp
        <tr>
            <td><input type="checkbox" class="row-check" value="{{ $l->id }}"></td>
            <td>{{ $l->product_id ?? '-' }}</td>
            <td>
                @if($thumb)
                    <img src="{{ $thumb }}" alt="" class="thumb thumb-sm">
                @else
                    <span class="text-muted">-</span>
                @endif
            </td>
            <td>
                <a href="{{ route('ext.lazada.products.edit', $l->id) }}" class="font-bold" style="text-decoration:none; color:var(--text-primary);">{{ $pName ?: '-' }}</a>
                @if($isUnlinked)
                    <span class="badge badge-gray">Unlinked</span>
                @endif
                @if($optRows && count($optRows) > 0)
                    <div style="margin-top:4px;">
                        @foreach($optRows as $or)
                            @php
                                $ovn = (string)($or->option_value_name ?? '');
                                $osku = (string)($or->option_sku ?? '');
                            @endphp
                            <div class="text-xs" style="color:var(--text-secondary); line-height:1.6;">{{ $ovn ?: '-' }}@if($osku) <span class="text-muted">— {{ $osku }}</span>@endif</div>
                        @endforeach
                    </div>
                @endif
            </td>
            <td><code>{{ $pSku ?: '-' }}</code></td>
            <td>{{ $pMfg ?: '-' }}</td>
            <td>
                @if($l->groups->count() > 0)
                    @php $firstGrp = $l->groups->first(); @endphp
                    <a href="{{ route('ext.lazada.product-groups.edit', $firstGrp->id) }}" class="text-xs">{{ $firstGrp->name }}@if($l->groups->count() > 1) <span class="text-muted">(+{{ $l->groups->count() - 1 }})</span>@endif</a>
                @else
                    <span class="text-muted">-</span>
                @endif
            </td>
            <td>
                <div>{{ is_null($pQty) ? '-' : $pQty }}</div>
                @if($optRows && count($optRows) > 0)
                    @foreach($optRows as $or)
                        @php
                            $oqtyRaw = $or->option_quantity ?? null;
                            $oqty = is_null($oqtyRaw) ? '-' : (is_numeric($oqtyRaw) ? (string)((int)$oqtyRaw) : (string)$oqtyRaw);
                        @endphp
                        <div class="text-xs text-muted">{{ $oqty }}</div>
                    @endforeach
                @endif
            </td>
            <td>
                <div>{{ $p ? number_format((float)$p->price, 2) : '-' }}</div>
                @if($optRows && count($optRows) > 0)
                    @foreach($optRows as $or)
                        <div class="text-xs text-muted">{{ number_format((float)($or->option_absolute_price ?? 0), 2) }}</div>
                    @endforeach
                @endif
            </td>
            <td>
                @if($hasItemId)
                    <span style="font-size:12px; font-family:monospace;">{{ $l->lazada_item_id }}</span>
                @else
                    <span class="text-muted">-</span>
                @endif
            </td>
            <td>
                @if($p === null)
                    <span class="text-muted">-</span>
                @elseif($pStatus)
                    <span class="badge badge-green">Enabled</span>
                @else
                    <span class="badge badge-red">Disabled</span>
                @endif
            </td>
            <td>
                @if($hasItemId)
                    <span class="badge badge-green">Uploaded</span>
                @elseif($isDeleted)
                    <span class="badge badge-gray">Deleted</span>
                @else
                    <span class="badge badge-yellow">Pending</span>
                @endif
            </td>
            <td>
                @if($hasError)
                    <div class="text-xs" style="color:var(--danger); word-break:break-word; line-height:1.4;">
                        @if($errCode)<strong>{{ $errCode }}</strong>@endif
                        @if($errMsg){{ $errCode ? ': ' : '' }}{{ Str::limit($errMsg, 80) }}@endif
                    </div>
                    @if($l->last_synced_at)
                        <div class="text-xs text-muted" style="margin-top:2px;">{{ \Carbon\Carbon::parse($l->last_synced_at)->diffForHumans() }}</div>
                    @endif
                @elseif($hasSyncInfo && $l->last_sync_ok)
                    <span class="badge badge-green">OK</span>
                    @if($l->last_synced_at)
                        <div class="text-xs text-muted" style="margin-top:2px;">{{ \Carbon\Carbon::parse($l->last_synced_at)->diffForHumans() }}</div>
                    @endif
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
                        <a href="{{ route('ext.lazada.products.edit', $l->id) }}" class="action-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            Edit
                        </a>
                        @if(!$hasItemId)
                        <form method="POST" action="{{ route('ext.lazada.products.upload', $l->id) }}" style="margin:0;" data-confirm="Upload this product to Lazada?">
                            @csrf
                            <button type="submit" class="action-item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                                Upload
                            </button>
                        </form>
                        <form method="POST" action="{{ route('ext.lazada.products.sync_lazada_id', $l->id) }}" style="margin:0;">
                            @csrf
                            <button type="submit" class="action-item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                                Sync Lazada ID
                            </button>
                        </form>
                        @endif
                        @if(!$isUnlinked && $hasItemId)
                        <form method="POST" action="{{ route('ext.lazada.products.sync_quantity', $l->id) }}" style="margin:0;">
                            @csrf
                            <button type="submit" class="action-item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                                Sync Qty
                            </button>
                        </form>
                        <form method="POST" action="{{ route('ext.lazada.products.sync_price', $l->id) }}" style="margin:0;">
                            @csrf
                            <button type="submit" class="action-item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                Sync Price
                            </button>
                        </form>
                        <div style="border-top:1px solid var(--border-light); margin:4px 0;"></div>
                        <form method="POST" action="{{ route('ext.lazada.products.unlink', $l->id) }}" style="margin:0;" data-confirm="Unlink this product? Sync operations will stop but the listing stays visible.">
                            @csrf
                            <button type="submit" class="action-item" style="color:var(--danger);">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>
                                Unlink from Lazada
                            </button>
                        </form>
                        <form method="POST" action="{{ route('ext.lazada.products.delete_lazada', $l->id) }}" style="margin:0;" data-confirm="Delete this product from Lazada? This cannot be undone.">
                            @csrf
                            <button type="submit" class="action-item" style="color:var(--danger);">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                Delete from Lazada
                            </button>
                        </form>
                        @endif
                        @if($isUnlinked)
                        <div style="border-top:1px solid var(--border-light); margin:4px 0;"></div>
                        <form method="POST" action="{{ route('ext.lazada.products.remove', $l->id) }}" style="margin:0;" data-confirm="Remove this listing from the local list? This does NOT delete anything on Lazada.">
                            @csrf
                            <button type="submit" class="action-item" style="color:var(--danger);">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                Remove from list
                            </button>
                        </form>
                        @endif
                    </div>
                </div>
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="14" class="text-muted">No products found. <a href="{{ route('ext.lazada.products.create') }}">Add your first product</a>.</td>
        </tr>
    @endforelse
    </tbody>
</table>
        </div>

        @if(isset($paginator) && $paginator->lastPage() > 1)
            <div class="mt-16">
                {{ $paginator->onEachSide(1)->links('vendor.pagination.simple') }}
            </div>
        @endif
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
    var table = document.getElementById('lazadaListingsTable');
    if (!table) return;

    var checkAll = document.getElementById('checkAll');
    var bulkBar = document.getElementById('bulk-bar');
    var bulkCount = document.getElementById('bulk-count');

    function rowChecks() { return Array.from(table.querySelectorAll('.row-check')); }

    function updateState() {
        var boxes = rowChecks();
        var checked = boxes.filter(function(b){ return b.checked; }).length;

        // Update checkAll state
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

        // Show/hide bulk bar
        if (bulkBar) {
            bulkBar.style.display = checked > 0 ? 'flex' : 'none';
        }
        if (bulkCount) {
            bulkCount.textContent = checked;
        }
    }

    if (checkAll) {
        checkAll.addEventListener('change', function(){
            rowChecks().forEach(function(b){ b.checked = checkAll.checked; });
            updateState();
        });
    }

    rowChecks().forEach(function(b){ b.addEventListener('change', updateState); });

    // Helper: inject listing_ids[] into a bulk form
    function injectBulkIds(form, ids) {
        form.querySelectorAll('input[name="listing_ids[]"]').forEach(function(n){ n.remove(); });
        ids.forEach(function(id){
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'listing_ids[]';
            inp.value = id;
            form.appendChild(inp);
        });
    }

    // Bulk action forms
    document.querySelectorAll('form.bulk-action-form').forEach(function(form){
        // data-confirm forms: inject IDs on first submit, show overlay only on confirmed re-submit
        if (form.getAttribute('data-confirm')) {
            form.addEventListener('submit', function(e) {
                var hasIds = form.querySelectorAll('input[name="listing_ids[]"]').length > 0;

                if (hasIds) {
                    // Re-submit after confirm — show loading overlay and let through
                    var overlay = document.getElementById('loadingOverlay');
                    var msg = document.getElementById('loadingMsg');
                    var count = form.querySelectorAll('input[name="listing_ids[]"]').length;
                    if (overlay) {
                        if (msg) msg.textContent = 'Processing ' + count + ' item(s)...';
                        overlay.classList.add('active');
                    }
                    return;
                }

                // First submit — inject IDs before global data-confirm handler shows modal
                var ids = rowChecks().filter(function(b){ return b.checked; }).map(function(b){ return b.value; });
                if (!ids.length) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    showFlashError('Please select at least 1 product.');
                    return;
                }
                injectBulkIds(form, ids);
            });
            return;
        }

        form.addEventListener('submit', function(e){
            form.querySelectorAll('input[name="listing_ids[]"]').forEach(function(n){ n.remove(); });

            var ids = rowChecks().filter(function(b){ return b.checked; }).map(function(b){ return b.value; });
            if (!ids.length) {
                e.preventDefault();
                showFlashError('Please select at least 1 product.');
                return;
            }

            if (form.classList.contains('bulk-delete-form')) {
                e.preventDefault();
                confirmModal('Delete ' + ids.length + ' selected product(s) from Lazada? This cannot be undone.').then(function(ok) {
                    if (!ok) return;
                    injectBulkIds(form, ids);
                    var overlay = document.getElementById('loadingOverlay');
                    var msg = document.getElementById('loadingMsg');
                    if (overlay) {
                        if (msg) msg.textContent = 'Processing ' + ids.length + ' item(s)...';
                        overlay.classList.add('active');
                    }
                    form.querySelector('button[type="submit"]').disabled = true;
                    form._confirmed = true;
                    form.requestSubmit ? form.requestSubmit() : form.submit();
                });
                return;
            }

            injectBulkIds(form, ids);

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

            // Close any other open menu
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

        // Click outside closes menu
        if (openMenu && !e.target.closest('.action-menu')) {
            closeMenu();
        }
    });
    // ── Loading overlay for individual action forms ─────────────
    document.querySelectorAll('.action-menu form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            // Skip if submission was prevented (e.g. confirm modal cancelled)
            if (e.defaultPrevented) return;
            var overlay = document.getElementById('loadingOverlay');
            var msg = document.getElementById('loadingMsg');
            if (overlay) {
                if (msg) msg.textContent = 'Processing...';
                overlay.classList.add('active');
            }
        });
    });

    // ── Unmatched Items: Product Search ──────────────────────────
    var lazSearchUrl = @json(route('ext.lazada.products.search_catalog'));
    var lazDebounceTimers = {};

    document.querySelectorAll('.lazada-unmatched-link-row').forEach(function(row) {
        var searchInput = row.querySelector('.lazada-unmatched-search');
        var resultsDiv = row.querySelector('.lazada-unmatched-results');
        var productIdInput = row.querySelector('.lazada-unmatched-product-id');
        var linkBtn = row.querySelector('.lazada-unmatched-link-form button[type="submit"]');
        var rowId = row.dataset.id;

        if (!searchInput || !resultsDiv) return;

        searchInput.addEventListener('input', function() {
            var q = searchInput.value.trim();
            if (q.length < 2) {
                resultsDiv.style.display = 'none';
                resultsDiv.innerHTML = '';
                return;
            }

            clearTimeout(lazDebounceTimers[rowId]);
            lazDebounceTimers[rowId] = setTimeout(function() {
                fetch(lazSearchUrl + '?q=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(items) {
                        if (!items.length) {
                            resultsDiv.innerHTML = '<div style="padding:8px 10px; font-size:12px; color:var(--text-muted);">No products found</div>';
                            resultsDiv.style.display = 'block';
                            return;
                        }
                        resultsDiv.innerHTML = items.map(function(item) {
                            var imgHtml = item.image ? '<img src="' + item.image + '" alt="" style="width:28px; height:28px; object-fit:cover; border-radius:3px;">' : '';
                            var optsHtml = '';
                            if (item.options && item.options.length) {
                                optsHtml = '<div style="margin-top:2px;">' + item.options.map(function(o) {
                                    return '<div style="font-size:10px; color:var(--text-muted);">' + (o.name ? o.name + ' - ' : '') + 'SKU: ' + o.sku + ' (Qty: ' + o.qty + ')</div>';
                                }).join('') + '</div>';
                            }
                            return '<div class="laz-result-item" data-pid="' + item.product_id + '" style="display:flex; align-items:center; gap:8px; padding:6px 10px; cursor:pointer; font-size:12px;">'
                                + imgHtml
                                + '<div style="flex:1; min-width:0;">'
                                + '<div style="font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' + item.name + '</div>'
                                + '<div style="color:var(--text-muted); font-size:11px;">SKU: ' + (item.sku || '-') + ' | ID: ' + item.product_id + '</div>'
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
            var item = e.target.closest('.laz-result-item');
            if (!item) return;
            var pid = item.dataset.pid;
            var nameEl = item.querySelector('div[style*="font-weight"]');
            productIdInput.value = pid;
            searchInput.value = (nameEl ? nameEl.textContent : '') + ' (#' + pid + ')';
            resultsDiv.style.display = 'none';
            if (linkBtn) linkBtn.disabled = false;
        });

        document.addEventListener('click', function(e) {
            if (!row.contains(e.target)) {
                resultsDiv.style.display = 'none';
            }
        });
    });

    // Close bulk update dropdown on outside click
    document.addEventListener('click', function(e) {
        var dd = document.getElementById('bulk-update-dropdown');
        if (dd && !dd.contains(e.target)) dd.classList.remove('open');
    });
})();
</script>
@endpush
@endsection
