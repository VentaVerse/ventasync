@extends('layouts.modern.app')
@section('breadcrumb', 'Marketplace / Lazada / Products')

@section('content')
@php
    $sort = $sort ?? 'id';
    $dir = $dir ?? 'desc';
    $toggleDir = function(string $col) use ($sort, $dir) {
        if ($sort !== $col) return 'asc';
        return $dir === 'asc' ? 'desc' : 'asc';
    };
    $sortIcon = function(string $col) use ($sort, $dir) {
        if ($sort !== $col) return '';
        return $dir === 'asc'
            ? '<svg class="inline w-3 h-3 ml-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>'
            : '<svg class="inline w-3 h-3 ml-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>';
    };
    $sortUrl = function(string $col) use ($toggleDir) {
        $qp = request()->query();
        $qp['sort'] = $col;
        $qp['dir'] = $toggleDir($col);
        unset($qp['page']);
        return url()->current() . '?' . http_build_query($qp);
    };
@endphp

{{-- Page header --}}
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Lazada Products</h1>
        <p class="text-sm text-gray-500 mt-1">Map catalog products to Lazada category + required attributes.</p>
    </div>
    <div class="flex items-center gap-2">
        <a href="{{ route('lazada.profiles.index') }}"
           class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium transition">
            Profiles
        </a>
        <a href="{{ route('lazada.products.create') }}"
           class="bg-[#0F146D] hover:bg-[#0b1058] text-white px-4 py-2 rounded-lg font-medium transition inline-flex items-center gap-2 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add Product
        </a>
    </div>
</div>

{{-- Status alerts --}}
@if(session('status'))
    <div class="rounded-xl border px-4 py-3 mb-6 text-sm flex items-start gap-3 bg-green-50 border-green-200 text-green-800">
        <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <div>{{ session('status') }}</div>
    </div>
@endif

{{-- ═══════════════════════ Unmatched Items Section ═══════════════════════ --}}
@if(isset($unmatchedItems) && $unmatchedItems->count() > 0)
    <div class="bg-amber-50 border-l-4 border-amber-400 rounded-xl p-4 mb-6" id="lazada-unmatched-section">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
            <div>
                <h3 class="text-base font-semibold text-amber-900">Unmatched Lazada Items</h3>
                <p class="text-xs text-amber-700 mt-0.5">{{ $unmatchedItems->count() }} item(s) on Lazada are not linked to any ERP product. Link them manually or dismiss.</p>
            </div>
            <form method="POST" action="{{ route('lazada.products.sync_unmatched') }}">
                @csrf
                <button type="submit"
                        class="bg-white border border-amber-300 text-amber-800 hover:bg-amber-100 px-3 py-1.5 rounded-lg text-xs font-medium transition">
                    Re-scan
                </button>
            </form>
        </div>

        <div class="bg-white rounded-lg border border-amber-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-amber-100">
                    <thead class="bg-amber-50/60">
                        <tr>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-amber-700 uppercase tracking-wider w-14">Image</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-amber-700 uppercase tracking-wider">Lazada Item</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-amber-700 uppercase tracking-wider">SKU</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-amber-700 uppercase tracking-wider w-20">Item ID</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-amber-700 uppercase tracking-wider" style="min-width:320px;">Link to Catalog Product</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-amber-700 uppercase tracking-wider w-20"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-amber-50">
                        @foreach($unmatchedItems as $ui)
                            <tr class="hover:bg-amber-50/40 transition">
                                <td class="px-4 py-3">
                                    @if($ui->image_url)
                                        <img src="{{ $ui->image_url }}" alt="" class="w-10 h-10 rounded-lg object-cover">
                                    @else
                                        <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center text-gray-400">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm font-semibold text-gray-900">{{ $ui->item_name ?? '-' }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <code class="text-xs bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded font-mono">{{ $ui->sku ?? '-' }}</code>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-xs text-gray-400 font-mono">{{ $ui->lazada_item_id }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="lazada-unmatched-link-row flex items-center gap-2 relative" data-id="{{ $ui->id }}">
                                        <input type="text" class="lazada-unmatched-search flex-1 border border-gray-300 rounded-lg text-xs px-3 py-1.5 focus:ring-2 focus:ring-[#0F146D]/30 focus:border-[#0F146D]" placeholder="Search product..." autocomplete="off">
                                        <div class="lazada-unmatched-results hidden absolute z-[60] top-full left-0 bg-white border border-gray-200 rounded-lg shadow-lg max-h-[200px] overflow-y-auto w-[300px] mt-1"></div>
                                        <form method="POST" action="{{ route('lazada.products.link_unmatched', $ui->id) }}" class="lazada-unmatched-link-form flex gap-1">
                                            @csrf
                                            <input type="hidden" name="product_id" class="lazada-unmatched-product-id" value="">
                                            <button type="submit" disabled title="Select a product first"
                                                    class="bg-[#0F146D] hover:bg-[#0b1058] disabled:bg-gray-300 disabled:cursor-not-allowed text-white px-3 py-1.5 rounded-lg text-xs font-medium transition">
                                                Link
                                            </button>
                                        </form>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <form method="POST" action="{{ route('lazada.products.dismiss_unmatched', $ui->id) }}">
                                        @csrf
                                        <button type="submit" title="Dismiss"
                                                class="bg-white border border-gray-300 text-gray-600 hover:bg-gray-50 px-3 py-1.5 rounded-lg text-xs font-medium transition">
                                            Dismiss
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif

{{-- Scan button (when no unmatched items) --}}
@if(!isset($unmatchedItems) || $unmatchedItems->count() === 0)
    <div class="mb-4">
        <form method="POST" action="{{ route('lazada.products.sync_unmatched') }}" class="inline">
            @csrf
            <button type="submit"
                    class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium transition inline-flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                Scan for Unmatched Lazada Items
            </button>
        </form>
    </div>
@endif

{{-- ═══════════════════════ Filter Toolbar ═══════════════════════ --}}
<div class="bg-white rounded-xl shadow-sm border p-4 mb-4">
    <form method="GET" action="{{ route('lazada.products.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Search</label>
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Name, model or SKU..."
                       class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#0F146D]/30 focus:border-[#0F146D]">
            </div>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Manufacturer</label>
            <select name="manufacturer"
                    class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-[#0F146D]/30 focus:border-[#0F146D]">
                <option value="all">All Manufacturers</option>
                @foreach(($allManufacturers ?? collect()) as $mId => $mName)
                    <option value="{{ $mId }}" {{ (string)($manufacturerFilter ?? '') === (string)$mId ? 'selected' : '' }}>{{ $mName }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Profile</label>
            <select name="profile"
                    class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-[#0F146D]/30 focus:border-[#0F146D]">
                <option value="all" {{ ($profileFilter ?? 'all') === 'all' ? 'selected' : '' }}>All Profiles</option>
                <option value="none" {{ ($profileFilter ?? '') === 'none' ? 'selected' : '' }}>No Profile</option>
                @foreach(($allProfiles ?? collect()) as $pId => $pName)
                    <option value="{{ $pId }}" {{ (string)($profileFilter ?? '') === (string)$pId ? 'selected' : '' }}>{{ $pName }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">ERP Status</label>
            <select name="erp_status"
                    class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-[#0F146D]/30 focus:border-[#0F146D]">
                <option value="all" {{ ($erpStatus ?? 'all') === 'all' ? 'selected' : '' }}>All</option>
                <option value="enabled" {{ ($erpStatus ?? '') === 'enabled' ? 'selected' : '' }}>Enabled</option>
                <option value="disabled" {{ ($erpStatus ?? '') === 'disabled' ? 'selected' : '' }}>Disabled</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Sync Status</label>
            <select name="sync_status"
                    class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-[#0F146D]/30 focus:border-[#0F146D]">
                <option value="all" {{ ($syncStatus ?? 'all') === 'all' ? 'selected' : '' }}>All</option>
                <option value="uploaded" {{ ($syncStatus ?? '') === 'uploaded' ? 'selected' : '' }}>Uploaded</option>
                <option value="not_uploaded" {{ ($syncStatus ?? '') === 'not_uploaded' ? 'selected' : '' }}>Pending</option>
                <option value="deleted" {{ ($syncStatus ?? '') === 'deleted' ? 'selected' : '' }}>Deleted</option>
            </select>
        </div>
        @if(!empty($sort))<input type="hidden" name="sort" value="{{ $sort }}">@endif
        @if(!empty($dir))<input type="hidden" name="dir" value="{{ $dir }}">@endif
        <div class="flex items-end gap-2">
            <button type="submit"
                    class="bg-[#0F146D] hover:bg-[#0b1058] text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                Filter
            </button>
            @if(($q ?? '') !== '' || ($syncStatus ?? 'all') !== 'all' || ($manufacturerFilter ?? 'all') !== 'all' || ($profileFilter ?? 'all') !== 'all' || ($erpStatus ?? 'all') !== 'all')
                <a href="{{ route('lazada.products.index') }}"
                   class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium transition">
                    Clear
                </a>
            @endif
        </div>
    </form>
</div>

{{-- ═══════════════════════ Bulk URLs ═══════════════════════ --}}
@php
    $bulkUploadUrl = \Illuminate\Support\Facades\Route::has('lazada.products.bulk_upload')
        ? route('lazada.products.bulk_upload')
        : url('/lazada/listings/bulk/upload');
    $bulkSyncQtyUrl = \Illuminate\Support\Facades\Route::has('lazada.products.bulk_sync_quantity')
        ? route('lazada.products.bulk_sync_quantity')
        : url('/lazada/listings/bulk/sync/quantity');
    $bulkSyncPriceUrl = \Illuminate\Support\Facades\Route::has('lazada.products.bulk_sync_price')
        ? route('lazada.products.bulk_sync_price')
        : url('/lazada/listings/bulk/sync/price');
    $bulkSyncLazadaIdUrl = \Illuminate\Support\Facades\Route::has('lazada.products.bulk_sync_lazada_id')
        ? route('lazada.products.bulk_sync_lazada_id')
        : url('/lazada/products/bulk/sync/lazada-id');
    $bulkDeleteUrl = \Illuminate\Support\Facades\Route::has('lazada.products.bulk_delete')
        ? route('lazada.products.bulk_delete')
        : url('/lazada/products/bulk/delete');
@endphp

{{-- Bulk action bar (hidden until items selected) --}}
<div id="bulk-bar" class="hidden items-center justify-between gap-3 flex-wrap rounded-xl border border-[#0F146D]/20 bg-[#0F146D]/5 px-4 py-3 mb-4">
    <span class="text-sm font-semibold text-[#0F146D]"><span id="bulk-count">0</span> selected</span>
    <div class="flex gap-2 flex-wrap">
        <form method="POST" action="{{ $bulkUploadUrl }}" class="bulk-action-form">
            @csrf
            <button type="submit" class="bg-[#0F146D] hover:bg-[#0b1058] text-white px-3 py-1.5 rounded-lg text-xs font-medium transition">Upload</button>
        </form>
        <form method="POST" action="{{ $bulkSyncLazadaIdUrl }}" class="bulk-action-form">
            @csrf
            <button type="submit" class="bg-[#0F146D] hover:bg-[#0b1058] text-white px-3 py-1.5 rounded-lg text-xs font-medium transition">Sync Lazada ID</button>
        </form>
        <form method="POST" action="{{ $bulkSyncQtyUrl }}" class="bulk-action-form">
            @csrf
            <button type="submit" class="bg-[#0F146D] hover:bg-[#0b1058] text-white px-3 py-1.5 rounded-lg text-xs font-medium transition">Sync Qty</button>
        </form>
        <form method="POST" action="{{ $bulkSyncPriceUrl }}" class="bulk-action-form">
            @csrf
            <button type="submit" class="bg-[#0F146D] hover:bg-[#0b1058] text-white px-3 py-1.5 rounded-lg text-xs font-medium transition">Sync Price</button>
        </form>
        <form method="POST" action="{{ $bulkDeleteUrl }}" class="bulk-action-form bulk-delete-form">
            @csrf
            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium transition">Delete from Lazada</button>
        </form>
    </div>
</div>

{{-- ═══════════════════════ Main Product Table ═══════════════════════ --}}
<div class="bg-white rounded-xl shadow-sm border overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200" id="lazadaListingsTable">
            <thead class="bg-gray-50">
                <tr>
                    <th class="w-10 px-4 py-3">
                        <input type="checkbox" id="checkAll" title="Select all"
                               class="rounded border-gray-300 text-[#0F146D] focus:ring-[#0F146D]">
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-16">
                        <a href="{{ $sortUrl('id') }}" class="hover:text-gray-700 transition">ID{!! $sortIcon('id') !!}</a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-14">
                        Image
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <a href="{{ $sortUrl('product') }}" class="hover:text-gray-700 transition">Product Name{!! $sortIcon('product') !!}</a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        SKU
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <a href="{{ $sortUrl('manufacturer') }}" class="hover:text-gray-700 transition">Manufacturer{!! $sortIcon('manufacturer') !!}</a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-28">
                        <a href="{{ $sortUrl('profile') }}" class="hover:text-gray-700 transition">Profile{!! $sortIcon('profile') !!}</a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-16">
                        <a href="{{ $sortUrl('quantity') }}" class="hover:text-gray-700 transition">Stock{!! $sortIcon('quantity') !!}</a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-20">
                        <a href="{{ $sortUrl('price') }}" class="hover:text-gray-700 transition">Price{!! $sortIcon('price') !!}</a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-28">
                        <a href="{{ $sortUrl('lazada_item_id') }}" class="hover:text-gray-700 transition">Lazada ID{!! $sortIcon('lazada_item_id') !!}</a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">
                        <a href="{{ $sortUrl('product_status') }}" class="hover:text-gray-700 transition">ERP Status{!! $sortIcon('product_status') !!}</a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">
                        <a href="{{ $sortUrl('lazada_status') }}" class="hover:text-gray-700 transition">Sync Status{!! $sortIcon('lazada_status') !!}</a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-40">
                        Error
                    </th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider w-12">
                    </th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
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

                        $hasSyncInfo = !is_null($l->last_synced_at) || !is_null($l->last_sync_ok);
                        $hasError = $hasSyncInfo && !is_null($l->last_sync_ok) && !$l->last_sync_ok;
                        $errCode = (string)($l->last_sync_error_code ?? '');
                        $errMsg = (string)($l->last_sync_error_message ?? '');

                        $optRows = isset($optionRowsByProductId) ? $optionRowsByProductId->get($l->product_id) : null;
                    @endphp
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-4 py-3">
                            <input type="checkbox" class="row-check rounded border-gray-300 text-[#0F146D] focus:ring-[#0F146D]" value="{{ $l->id }}">
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 font-mono">{{ $l->product_id ?? '-' }}</td>
                        <td class="px-4 py-3">
                            @if($thumb)
                                <img src="{{ $thumb }}" alt="" class="w-10 h-10 rounded-lg object-cover" loading="lazy" decoding="async">
                            @else
                                <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center text-gray-400">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <a href="{{ route('lazada.products.edit', $l->id) }}" class="text-sm font-semibold text-gray-900 hover:text-[#0F146D] transition">
                                {{ $pName ?: '-' }}
                            </a>
                            @if($optRows && count($optRows) > 0)
                                <div class="mt-1 space-y-0.5">
                                    @foreach($optRows as $or)
                                        @php
                                            $ovn = (string)($or->option_value_name ?? '');
                                            $osku = (string)($or->option_sku ?? '');
                                        @endphp
                                        <div class="text-xs text-gray-500 leading-relaxed">
                                            {{ $ovn ?: '-' }}@if($osku) <span class="text-gray-400">&mdash; {{ $osku }}</span>@endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <code class="text-xs bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded font-mono">{{ $pSku ?: '-' }}</code>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">{{ $pMfg ?: '-' }}</td>
                        <td class="px-4 py-3">
                            @if($l->profiles->count() > 0)
                                @php $firstProf = $l->profiles->first(); @endphp
                                <a href="{{ route('lazada.profiles.edit', $firstProf->id) }}" class="text-xs text-[#0F146D] hover:underline">
                                    {{ $firstProf->name }}@if($l->profiles->count() > 1) <span class="text-gray-400">(+{{ $l->profiles->count() - 1 }})</span>@endif
                                </a>
                            @else
                                <span class="text-gray-400">&mdash;</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-sm text-gray-700">{{ is_null($pQty) ? '-' : $pQty }}</div>
                            @if($optRows && count($optRows) > 0)
                                @foreach($optRows as $or)
                                    @php
                                        $oqtyRaw = $or->option_quantity ?? null;
                                        $oqty = is_null($oqtyRaw) ? '-' : (is_numeric($oqtyRaw) ? (string)((int)$oqtyRaw) : (string)$oqtyRaw);
                                    @endphp
                                    <div class="text-xs text-gray-400">{{ $oqty }}</div>
                                @endforeach
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-sm text-gray-700">{{ $p ? number_format((float)$p->price, 2) : '-' }}</div>
                            @if($optRows && count($optRows) > 0)
                                @foreach($optRows as $or)
                                    <div class="text-xs text-gray-400">{{ number_format((float)($or->option_absolute_price ?? 0), 2) }}</div>
                                @endforeach
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($hasItemId)
                                <span class="text-xs font-mono text-gray-600">{{ $l->lazada_item_id }}</span>
                            @else
                                <span class="text-gray-400">&mdash;</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($p === null)
                                <span class="text-gray-400">&mdash;</span>
                            @elseif($pStatus)
                                <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">Enabled</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800">Disabled</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($hasItemId)
                                <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">Uploaded</span>
                            @elseif($isDeleted)
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">Deleted</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-medium text-yellow-800">Pending</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($hasError)
                                <div class="text-xs text-red-600 break-words leading-snug">
                                    @if($errCode)<strong>{{ $errCode }}</strong>@endif
                                    @if($errMsg){{ $errCode ? ': ' : '' }}{{ Str::limit($errMsg, 80) }}@endif
                                </div>
                                @if($l->last_synced_at)
                                    <div class="text-xs text-gray-400 mt-0.5">{{ \Carbon\Carbon::parse($l->last_synced_at)->diffForHumans() }}</div>
                                @endif
                            @elseif($hasSyncInfo && $l->last_sync_ok)
                                <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">OK</span>
                                @if($l->last_synced_at)
                                    <div class="text-xs text-gray-400 mt-0.5">{{ \Carbon\Carbon::parse($l->last_synced_at)->diffForHumans() }}</div>
                                @endif
                            @else
                                <span class="text-gray-400">&mdash;</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="relative row-actions">
                                <button type="button" class="action-toggle inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition" title="Actions">
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                                        <circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>
                                    </svg>
                                </button>
                                <div class="action-menu hidden absolute right-0 top-full mt-1 z-50 bg-white border border-gray-200 rounded-lg shadow-lg min-w-[180px] py-1">
                                    <a href="{{ route('lazada.products.edit', $l->id) }}"
                                       class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                        </svg>
                                        Edit
                                    </a>
                                    <form method="POST" action="{{ route('lazada.products.upload', $l->id) }}" onsubmit="return confirm('Upload this product to Lazada?');">
                                        @csrf
                                        <button type="submit" class="flex items-center gap-2 w-full px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition text-left">
                                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                                <polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/>
                                                <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
                                            </svg>
                                            Upload
                                        </button>
                                    </form>
                                    @if(empty($l->lazada_item_id))
                                    <form method="POST" action="{{ route('lazada.products.sync_lazada_id', $l->id) }}">
                                        @csrf
                                        <button type="submit" class="flex items-center gap-2 w-full px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition text-left">
                                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                                <polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                                            </svg>
                                            Sync Lazada ID
                                        </button>
                                    </form>
                                    @endif
                                    <form method="POST" action="{{ route('lazada.products.sync_quantity', $l->id) }}">
                                        @csrf
                                        <button type="submit" class="flex items-center gap-2 w-full px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition text-left">
                                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                                <line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/>
                                                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                                            </svg>
                                            Sync Qty
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('lazada.products.sync_price', $l->id) }}">
                                        @csrf
                                        <button type="submit" class="flex items-center gap-2 w-full px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition text-left">
                                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                                <line x1="12" y1="1" x2="12" y2="23"/>
                                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                                            </svg>
                                            Sync Price
                                        </button>
                                    </form>
                                    <hr class="my-1 border-gray-100">
                                    <form method="POST" action="{{ route('lazada.products.unlink', $l->id) }}" onsubmit="return confirm('Unlink this product from Lazada ERP? This removes the ERP link only \u2014 the Lazada listing is not deleted. You can re-sync afterwards.');">
                                        @csrf
                                        <button type="submit" class="flex items-center gap-2 w-full px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition text-left">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                                <path d="M18 6L6 18"/><path d="M6 6l12 12"/>
                                            </svg>
                                            Unlink from Lazada
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('lazada.products.delete_lazada', $l->id) }}" onsubmit="return confirm('Delete this product from Lazada? This cannot be undone.');">
                                        @csrf
                                        <button type="submit" class="flex items-center gap-2 w-full px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition text-left">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                                <polyline points="3 6 5 6 21 6"/>
                                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                            </svg>
                                            Delete from Lazada
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="14" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center text-gray-400">
                                <svg class="w-12 h-12 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                </svg>
                                <p class="text-sm font-medium">No products found</p>
                                <p class="text-xs mt-1">
                                    <a href="{{ route('lazada.products.create') }}" class="text-[#0F146D] hover:underline">Add your first product</a>.
                                </p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Pagination --}}
@if(isset($paginator) && $paginator->lastPage() > 1)
    <div class="mt-6">
        {{ $paginator->onEachSide(1)->links() }}
    </div>
@endif

{{-- Loading overlay --}}
<div class="fixed inset-0 z-[9999] bg-black/40 backdrop-blur-sm items-center justify-center hidden" id="loadingOverlay">
    <div class="bg-white rounded-2xl shadow-2xl px-8 py-6 flex flex-col items-center gap-3">
        <svg class="animate-spin w-8 h-8 text-[#0F146D]" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <div class="text-sm font-medium text-gray-700" id="loadingMsg">Processing...</div>
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
            if (checked > 0) {
                bulkBar.classList.remove('hidden');
                bulkBar.classList.add('flex');
            } else {
                bulkBar.classList.add('hidden');
                bulkBar.classList.remove('flex');
            }
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

    // Bulk action forms
    document.querySelectorAll('form.bulk-action-form').forEach(function(form){
        form.addEventListener('submit', function(e){
            form.querySelectorAll('input[name="listing_ids[]"]').forEach(function(n){ n.remove(); });

            var ids = rowChecks().filter(function(b){ return b.checked; }).map(function(b){ return b.value; });
            if (!ids.length) {
                e.preventDefault();
                alert('Please select at least 1 product.');
                return;
            }

            if (form.classList.contains('bulk-delete-form')) {
                if (!confirm('Delete ' + ids.length + ' selected product(s) from Lazada? This cannot be undone.')) {
                    e.preventDefault();
                    return;
                }
            }

            ids.forEach(function(id){
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'listing_ids[]';
                inp.value = id;
                form.appendChild(inp);
            });

            // Show loading overlay
            var overlay = document.getElementById('loadingOverlay');
            var msg = document.getElementById('loadingMsg');
            if (overlay) {
                if (msg) msg.textContent = 'Processing ' + ids.length + ' item(s)...';
                overlay.classList.remove('hidden');
                overlay.classList.add('flex');
            }
            form.querySelector('button[type="submit"]').disabled = true;
        });
    });

    // Action dropdown toggle
    var openMenu = null;

    function closeMenu() {
        if (openMenu) {
            openMenu.classList.add('hidden');
            openMenu = null;
        }
    }

    document.addEventListener('click', function(e) {
        var toggle = e.target.closest('.action-toggle');
        if (toggle) {
            e.stopPropagation();
            var menu = toggle.nextElementSibling;
            if (!menu) return;

            // Close any other open menu
            if (openMenu && openMenu !== menu) {
                openMenu.classList.add('hidden');
            }

            if (menu.classList.contains('hidden')) {
                menu.classList.remove('hidden');
                openMenu = menu;
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

    // Loading overlay for individual action forms
    document.querySelectorAll('.action-menu form').forEach(function(form) {
        form.addEventListener('submit', function() {
            var overlay = document.getElementById('loadingOverlay');
            var msg = document.getElementById('loadingMsg');
            if (overlay) {
                if (msg) msg.textContent = 'Processing...';
                overlay.classList.remove('hidden');
                overlay.classList.add('flex');
            }
        });
    });

    // Unmatched Items: Product Search (typeahead)
    var lazSearchUrl = @json(route('lazada.products.search_catalog'));
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
                resultsDiv.classList.add('hidden');
                resultsDiv.textContent = '';
                return;
            }

            clearTimeout(lazDebounceTimers[rowId]);
            lazDebounceTimers[rowId] = setTimeout(function() {
                fetch(lazSearchUrl + '?q=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(items) {
                        resultsDiv.textContent = '';
                        if (!items.length) {
                            var noResult = document.createElement('div');
                            noResult.className = 'px-3 py-2 text-xs text-gray-400';
                            noResult.textContent = 'No products found';
                            resultsDiv.appendChild(noResult);
                            resultsDiv.classList.remove('hidden');
                            return;
                        }
                        items.forEach(function(item) {
                            var el = document.createElement('div');
                            el.className = 'laz-result-item flex items-center gap-2 px-3 py-2 cursor-pointer text-xs hover:bg-gray-50 transition';
                            el.dataset.pid = item.product_id;

                            if (item.image) {
                                var img = document.createElement('img');
                                img.src = item.image;
                                img.alt = '';
                                img.className = 'w-7 h-7 rounded object-cover flex-shrink-0';
                                el.appendChild(img);
                            }

                            var info = document.createElement('div');
                            info.className = 'flex-1 min-w-0';

                            var nameDiv = document.createElement('div');
                            nameDiv.className = 'font-semibold text-gray-900 truncate';
                            nameDiv.textContent = item.name;
                            info.appendChild(nameDiv);

                            var metaDiv = document.createElement('div');
                            metaDiv.className = 'text-gray-400 text-[11px]';
                            metaDiv.textContent = 'SKU: ' + (item.sku || '-') + ' | ID: ' + item.product_id;
                            info.appendChild(metaDiv);

                            if (item.options && item.options.length) {
                                var optsWrap = document.createElement('div');
                                optsWrap.className = 'mt-0.5';
                                item.options.forEach(function(o) {
                                    var optLine = document.createElement('div');
                                    optLine.className = 'text-[10px] text-gray-400';
                                    optLine.textContent = (o.name ? o.name + ' - ' : '') + 'SKU: ' + o.sku + ' (Qty: ' + o.qty + ')';
                                    optsWrap.appendChild(optLine);
                                });
                                info.appendChild(optsWrap);
                            }

                            el.appendChild(info);
                            resultsDiv.appendChild(el);
                        });
                        resultsDiv.classList.remove('hidden');
                    })
                    .catch(function() {
                        resultsDiv.classList.add('hidden');
                    });
            }, 300);
        });

        resultsDiv.addEventListener('click', function(e) {
            var item = e.target.closest('.laz-result-item');
            if (!item) return;
            var pid = item.dataset.pid;
            var nameEl = item.querySelector('.font-semibold');
            productIdInput.value = pid;
            searchInput.value = (nameEl ? nameEl.textContent : '') + ' (#' + pid + ')';
            resultsDiv.classList.add('hidden');
            if (linkBtn) linkBtn.disabled = false;
        });

        document.addEventListener('click', function(e) {
            if (!row.contains(e.target)) {
                resultsDiv.classList.add('hidden');
            }
        });
    });
})();
</script>
@endpush
@endsection
