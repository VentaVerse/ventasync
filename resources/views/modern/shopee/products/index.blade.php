@extends('layouts.modern.app')
@section('breadcrumb', 'Marketplace / Shopee / Products')

@section('content')

{{-- Page header --}}
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Shopee Products</h1>
        <p class="mt-1 text-sm text-gray-500">Push catalog products to Shopee and sync stock/price.</p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        <form method="POST" action="{{ route('shopee.products.rebuild_cache') }}" class="inline">
            @csrf
            <button type="submit"
                    class="inline-flex items-center gap-1.5 px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition"
                    onclick="return confirm('Rebuild the Shopee product cache? This scans all Shopee items and may take a minute.')">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Rebuild Cache
            </button>
        </form>
        <a href="{{ route('shopee.profiles.index') }}"
           class="inline-flex items-center gap-1.5 px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
            </svg>
            Profiles
        </a>
        <a href="{{ route('shopee.products.add') }}"
           class="inline-flex items-center gap-1.5 bg-[#EE4D2D] hover:bg-[#D73211] text-white px-4 py-2 rounded-lg text-sm font-medium transition shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add Product
        </a>
    </div>
</div>

{{-- Flash messages --}}
@if(session('status'))
    <div class="mb-4 flex items-center gap-3 bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">
        <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        {{ session('status') }}
    </div>
@endif

@if(session('error'))
    <div class="mb-4 flex items-center gap-3 bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm">
        <svg class="w-5 h-5 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        {{ session('error') }}
    </div>
@endif

{{-- Unmatched Items Section --}}
@if(isset($unmatchedItems) && $unmatchedItems->count() > 0)
    <div class="bg-amber-50 border-l-4 border-amber-400 rounded-xl p-4 mb-6" id="unmatched-section">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
            <div>
                <h3 class="text-base font-semibold text-amber-900 flex items-center gap-2">
                    <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    </svg>
                    Unmatched Shopee Items
                </h3>
                <p class="text-sm text-amber-700 mt-1">{{ $unmatchedItems->count() }} item(s) on Shopee could not be matched by SKU. Link them manually or dismiss.</p>
            </div>
            <form method="POST" action="{{ route('shopee.products.sync_unmatched') }}" class="flex-shrink-0">
                @csrf
                <button type="submit"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-amber-800 bg-amber-100 hover:bg-amber-200 border border-amber-300 rounded-lg transition">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    Re-scan
                </button>
            </form>
        </div>

        <div class="overflow-x-auto rounded-lg border border-amber-200 bg-white">
            <table class="min-w-full divide-y divide-amber-100">
                <thead class="bg-amber-50/60">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium text-amber-800 uppercase w-14">Image</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-amber-800 uppercase">Shopee Item</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-amber-800 uppercase">SKU</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-amber-800 uppercase w-14">Item ID</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-amber-800 uppercase" style="width:320px;">Link to Catalog Product</th>
                        <th class="px-3 py-2 w-20"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-amber-50">
                    @foreach($unmatchedItems as $ui)
                        <tr class="hover:bg-amber-50/40 transition">
                            <td class="px-3 py-2">
                                @if($ui->image_url)
                                    <img src="{{ $ui->image_url }}" alt="" class="w-10 h-10 object-cover rounded-lg">
                                @else
                                    <span class="text-xs text-gray-400">-</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <div class="text-sm font-semibold text-gray-900">{{ $ui->item_name ?? '-' }}</div>
                            </td>
                            <td class="px-3 py-2">
                                <code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded text-gray-700">{{ $ui->sku ?? '-' }}</code>
                            </td>
                            <td class="px-3 py-2">
                                <span class="text-xs text-gray-500">{{ $ui->shopee_item_id }}</span>
                                @if($ui->shopee_model_id)
                                    <br><span class="text-xs text-gray-400">M: {{ $ui->shopee_model_id }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <div class="unmatched-link-row relative" data-id="{{ $ui->id }}" style="display:flex; gap:6px; align-items:center;">
                                    <input type="text" class="unmatched-search flex-1 border border-gray-300 rounded-lg text-xs px-2.5 py-1.5 focus:ring-2 focus:ring-[#EE4D2D]/30 focus:border-[#EE4D2D] transition" placeholder="Search product..." autocomplete="off">
                                    <div class="unmatched-results" style="display:none; position:absolute; z-index:60; top:100%; left:0; margin-top:4px; background:#fff; border:1px solid #e5e7eb; border-radius:0.5rem; box-shadow:0 10px 15px -3px rgba(0,0,0,.1); max-height:200px; overflow-y:auto; width:300px;"></div>
                                    <form method="POST" action="{{ route('shopee.products.link_unmatched', $ui->id) }}" class="unmatched-link-form flex gap-1">
                                        @csrf
                                        <input type="hidden" name="product_id" class="unmatched-product-id" value="">
                                        <button type="submit" disabled title="Select a product first"
                                                class="inline-flex items-center px-2.5 py-1.5 text-xs font-medium rounded-lg text-white bg-[#EE4D2D] hover:bg-[#D73211] transition disabled:opacity-40 disabled:cursor-not-allowed">
                                            Link
                                        </button>
                                    </form>
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <form method="POST" action="{{ route('shopee.products.dismiss_unmatched', $ui->id) }}">
                                    @csrf
                                    <button type="submit" title="Dismiss"
                                            class="inline-flex items-center px-2.5 py-1.5 text-xs font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition">
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
@endif

@if(!isset($unmatchedItems) || $unmatchedItems->count() === 0)
    <div class="mb-4">
        <form method="POST" action="{{ route('shopee.products.sync_unmatched') }}" class="inline">
            @csrf
            <button type="submit"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 bg-white border border-gray-300 hover:bg-gray-50 rounded-lg transition">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                Scan for Unmatched Shopee Items
            </button>
        </form>
    </div>
@endif

{{-- Filter toolbar --}}
<div class="bg-white rounded-xl shadow-sm border p-4 mb-6">
    <form method="GET" action="{{ route('shopee.products.index') }}"
          class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Search</label>
            <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Name, model or SKU..."
                   class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-[#EE4D2D]/30 focus:border-[#EE4D2D] transition">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Manufacturer</label>
            <select name="manufacturer"
                    class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-[#EE4D2D]/30 focus:border-[#EE4D2D] transition">
                <option value="all">All Manufacturers</option>
                @foreach(($allManufacturers ?? collect()) as $mId => $mName)
                    <option value="{{ $mId }}" {{ (string)($manufacturerFilter ?? '') === (string)$mId ? 'selected' : '' }}>{{ $mName }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Profile</label>
            <select name="profile"
                    class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-[#EE4D2D]/30 focus:border-[#EE4D2D] transition">
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
                    class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-[#EE4D2D]/30 focus:border-[#EE4D2D] transition">
                <option value="all" {{ ($erpStatus ?? 'all') === 'all' ? 'selected' : '' }}>All</option>
                <option value="enabled" {{ ($erpStatus ?? '') === 'enabled' ? 'selected' : '' }}>Enabled</option>
                <option value="disabled" {{ ($erpStatus ?? '') === 'disabled' ? 'selected' : '' }}>Disabled</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Sync Status</label>
            <select name="sync_status"
                    class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-[#EE4D2D]/30 focus:border-[#EE4D2D] transition">
                <option value="all" {{ ($syncStatus ?? 'all') === 'all' ? 'selected' : '' }}>All</option>
                <option value="listed" {{ ($syncStatus ?? '') === 'listed' ? 'selected' : '' }}>Listed</option>
                <option value="not_listed" {{ ($syncStatus ?? '') === 'not_listed' ? 'selected' : '' }}>Not Listed</option>
            </select>
        </div>
        <div class="flex items-center gap-2">
            <button type="submit"
                    class="bg-[#EE4D2D] hover:bg-[#D73211] text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                Filter
            </button>
            @if(($q ?? '') !== '' || ($syncStatus ?? 'all') !== 'all' || ($manufacturerFilter ?? 'all') !== 'all' || ($profileFilter ?? 'all') !== 'all' || ($erpStatus ?? 'all') !== 'all')
                <a href="{{ route('shopee.products.index') }}"
                   class="text-sm text-gray-500 hover:text-gray-700 transition">Clear</a>
            @endif
        </div>
    </form>
</div>

{{-- Main product card --}}
<div class="bg-white rounded-xl shadow-sm border overflow-hidden">

    {{-- Bulk action bar --}}
    <div id="bulk-bar" class="hidden items-center justify-between gap-3 flex-wrap px-4 py-3 bg-orange-50 border-b border-orange-200">
        <span class="text-sm font-semibold text-[#EE4D2D]"><span id="bulk-count">0</span> selected</span>
        <div class="flex gap-2 flex-wrap">
            <form method="POST" action="{{ route('shopee.products.bulk_push') }}" class="bulk-action-form bulk-push-form">
                @csrf
                <button type="submit"
                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg text-white bg-[#EE4D2D] hover:bg-[#D73211] transition shadow-sm">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
                    </svg>
                    Push to Shopee
                </button>
            </form>
            <form method="POST" action="{{ route('shopee.products.sync_ids') }}" class="bulk-action-form">
                @csrf
                <button type="submit"
                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition">
                    Sync Product IDs
                </button>
            </form>
            <form method="POST" action="{{ route('shopee.products.bulk_sync_quantity') }}" class="bulk-action-form">
                @csrf
                <button type="submit"
                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition">
                    Sync Qty
                </button>
            </form>
            <form method="POST" action="{{ route('shopee.products.bulk_sync_price') }}" class="bulk-action-form">
                @csrf
                <button type="submit"
                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition">
                    Sync Price
                </button>
            </form>
            <form method="POST" action="{{ route('shopee.products.bulk_delete') }}" class="bulk-action-form bulk-delete-form">
                @csrf
                <button type="submit"
                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg text-red-600 bg-red-50 border border-red-200 hover:bg-red-100 transition">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    Delete from Shopee
                </button>
            </form>
        </div>
    </div>

    {{-- Table --}}
    <div class="overflow-x-auto" id="shopeeTableWrap">
        <table class="min-w-full divide-y divide-gray-200" id="shopeeProductsTable">
            <thead class="bg-gray-50">
                <tr>
                    <th class="w-10 px-4 py-3"><input type="checkbox" id="checkAll" title="Select all" class="rounded border-gray-300 text-[#EE4D2D] focus:ring-[#EE4D2D]"></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-16">ID</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-14">Image</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SKU</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Manufacturer</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-28">Profile</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-16">Stock</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-20">Price</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Shopee ID</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">ERP Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">Sync</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-40">Error</th>
                    <th class="px-4 py-3 w-12"></th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($products as $p)
                    @php
                        $links = $shopeeLinks->get($p->product_id, collect());
                        $link = $links->first();
                        $pProfiles = isset($profilesByProductId) ? ($profilesByProductId[$p->product_id] ?? []) : [];
                    @endphp
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-4 py-3"><input type="checkbox" class="row-check rounded border-gray-300 text-[#EE4D2D] focus:ring-[#EE4D2D]" value="{{ $p->product_id }}"></td>
                        <td class="px-4 py-3 text-sm text-gray-600 font-mono">{{ $p->product_id }}</td>
                        <td class="px-4 py-3">
                            @if($p->image)
                                <img src="{{ asset('storage/' . ltrim($p->image, '/')) }}" alt="" class="w-10 h-10 object-cover rounded-lg" loading="lazy" decoding="async">
                            @else
                                <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center text-gray-400">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-sm font-semibold text-gray-900">{{ $p->name }}</div>
                            @php
                                $optRows = isset($optionRowsByProductId) ? $optionRowsByProductId->get($p->product_id) : null;
                            @endphp
                            @if($optRows && count($optRows) > 0)
                                <div class="mt-1 space-y-0.5">
                                    @foreach($optRows as $or)
                                        @php
                                            $ovn = (string)($or->option_value_name ?? '');
                                            $osku = (string)($or->option_sku ?? '');
                                            $oqty = (int)($or->option_quantity ?? 0);
                                        @endphp
                                        <div class="text-xs text-gray-500 leading-relaxed">
                                            {{ $ovn ?: '-' }}@if($osku) <span class="text-gray-400">&mdash; {{ $osku }}</span>@endif
                                            <span class="text-gray-400">({{ $oqty }})</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded text-gray-700">{{ $p->sku ?: '-' }}</code>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">{{ $p->manufacturer_name ?? '-' }}</td>
                        <td class="px-4 py-3">
                            @if(count($pProfiles) > 0)
                                <span class="text-xs text-gray-700">{{ $pProfiles[0] }}@if(count($pProfiles) > 1) <span class="text-gray-400">(+{{ count($pProfiles) - 1 }})</span>@endif</span>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">{{ (int)$p->quantity }}</td>
                        <td class="px-4 py-3">
                            <div class="text-sm text-gray-700">{{ number_format((float)$p->price, 2) }}</div>
                            @if($optRows && count($optRows) > 0)
                                @foreach($optRows as $or)
                                    <div class="text-xs text-gray-400">{{ number_format((float)($or->option_absolute_price ?? 0), 2) }}</div>
                                @endforeach
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($link)
                                <code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded text-gray-600">{{ $link->shopee_item_id }}</code>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if((int)$p->status === 1)
                                <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">Enabled</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">Disabled</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($link)
                                <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">Listed</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">Not Listed</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($link && !is_null($link->last_sync_ok) && !$link->last_sync_ok)
                                <div class="text-xs text-red-600 leading-snug break-words max-w-[160px]">
                                    @if($link->last_sync_error_code)<strong>{{ $link->last_sync_error_code }}</strong>@endif
                                    @if($link->last_sync_error_message){{ $link->last_sync_error_code ? ': ' : '' }}{{ Str::limit($link->last_sync_error_message, 80) }}@endif
                                </div>
                                @if($link->last_synced_at)
                                    <div class="text-xs text-gray-400 mt-0.5">{{ $link->last_synced_at->diffForHumans() }}</div>
                                @endif
                            @elseif($link && $link->last_sync_ok)
                                <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">OK</span>
                                @if($link->last_synced_at)
                                    <div class="text-xs text-gray-400 mt-0.5">{{ $link->last_synced_at->diffForHumans() }}</div>
                                @endif
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="relative row-actions">
                                <button type="button" class="action-toggle p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition" title="Actions">
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                                        <circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>
                                    </svg>
                                </button>
                                <div class="action-menu hidden absolute right-0 top-full mt-1 z-50 bg-white border border-gray-200 rounded-xl shadow-lg min-w-[180px] py-1">
                                    <a href="{{ route('products.edit', $p->product_id) }}"
                                       class="action-item flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                        Edit Product
                                    </a>
                                    @if(!$link)
                                        <a href="{{ route('shopee.products.push', $p->product_id) }}"
                                           class="action-item flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition">
                                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                            </svg>
                                            Edit Push Settings
                                        </a>
                                        <form method="POST" action="{{ route('shopee.products.push_direct', $p->product_id) }}">
                                            @csrf
                                            <button type="submit" class="action-item w-full flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition text-left">
                                                <svg class="w-4 h-4 text-[#EE4D2D]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <polyline points="16 16 12 12 8 16" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="12" y1="12" x2="12" y2="21" stroke-width="2" stroke-linecap="round"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                                Push to Shopee
                                            </button>
                                        </form>
                                    @endif
                                    @if(!$link)
                                        <form method="POST" action="{{ route('shopee.products.sync_shopee_id', $p->product_id) }}">
                                            @csrf
                                            <button type="submit" class="action-item w-full flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition text-left">
                                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <polyline points="23 4 23 10 17 10" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                                Sync Shopee ID
                                            </button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('shopee.products.sync_quantity', $p->product_id) }}">
                                        @csrf
                                        <button type="submit" class="action-item w-full flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition text-left">
                                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                            </svg>
                                            Sync Qty
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('shopee.products.sync_price', $p->product_id) }}">
                                        @csrf
                                        <button type="submit" class="action-item w-full flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition text-left">
                                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <line x1="12" y1="1" x2="12" y2="23" stroke-width="2" stroke-linecap="round"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                            Sync Price
                                        </button>
                                    </form>
                                    @if($link)
                                        <div class="border-t border-gray-100 my-1"></div>
                                        <form method="POST" action="{{ route('shopee.products.unlink', $p->product_id) }}"
                                              onsubmit="return confirm('Unlink this product from Shopee? This removes the link only \u2014 the Shopee listing is not deleted. You can re-sync afterwards.')">
                                            @csrf
                                            <button type="submit" class="action-item w-full flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 transition text-left">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12"/>
                                                </svg>
                                                Unlink from Shopee
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('shopee.products.delete', $p->product_id) }}"
                                              onsubmit="return confirm('Delete this product from Shopee? This cannot be undone.')">
                                            @csrf
                                            <button type="submit" class="action-item w-full flex items-center gap-2 px-3 py-2 text-sm text-red-600 hover:bg-red-50 transition text-left">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                                Delete from Shopee
                                            </button>
                                        </form>
                                    @endif
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
                                <p class="text-sm font-medium">No products found.</p>
                                <p class="text-xs mt-1">Create a <a href="{{ route('shopee.profiles.index') }}" class="text-[#EE4D2D] hover:underline">Shopee Profile</a> to filter catalog products.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    <div class="flex items-center justify-between px-4 py-3 border-t border-gray-200">
        <div class="text-xs text-gray-500">
            Showing {{ $products->firstItem() ?? 0 }} to {{ $products->lastItem() ?? 0 }} of {{ $products->total() }}
        </div>
        <div>
            {{ $products->links('vendor.pagination.simple') }}
        </div>
    </div>
</div>

{{-- Loading overlay --}}
<div class="fixed inset-0 z-[9999] bg-black/40 items-center justify-center" id="loadingOverlay" style="display:none;">
    <div class="bg-white rounded-2xl shadow-xl p-8 flex flex-col items-center gap-4 min-w-[200px]">
        <svg class="animate-spin w-8 h-8 text-[#EE4D2D]" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <div class="text-sm font-medium text-gray-700" id="loadingMsg">Processing...</div>
    </div>
</div>

@push('scripts')
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

        if (bulkBar) {
            if (checked > 0) {
                bulkBar.style.display = 'flex';
                bulkBar.classList.remove('hidden');
            } else {
                bulkBar.style.display = 'none';
                bulkBar.classList.add('hidden');
            }
        }
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

            if (form.classList.contains('bulk-push-form')) {
                if (!confirm('Push ' + ids.length + ' selected product(s) to Shopee? Already-linked products will be skipped.')) {
                    e.preventDefault();
                    return;
                }
            }

            if (form.classList.contains('bulk-delete-form')) {
                if (!confirm('Delete ' + ids.length + ' selected product(s) from Shopee? This cannot be undone.')) {
                    e.preventDefault();
                    return;
                }
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
                overlay.style.display = 'flex';
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
                overlay.style.display = 'flex';
            }
        });
    });

    // Action dropdown toggle
    var openMenu = null;
    var tableWrap = document.getElementById('shopeeTableWrap');

    function closeMenu() {
        if (openMenu) {
            openMenu.style.display = 'none';
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

            if (openMenu && openMenu !== menu) {
                openMenu.style.display = 'none';
                openMenu.classList.add('hidden');
            }

            if (menu.style.display === 'none' || menu.classList.contains('hidden')) {
                menu.style.display = 'block';
                menu.classList.remove('hidden');
                openMenu = menu;
            } else {
                closeMenu();
            }
            return;
        }

        if (openMenu && !e.target.closest('.action-menu')) {
            closeMenu();
        }
    });

    // Unmatched Items: Product Search typeahead
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
                            resultsDiv.textContent = '';
                            var emptyDiv = document.createElement('div');
                            emptyDiv.style.cssText = 'padding:8px 10px; font-size:12px; color:#9ca3af;';
                            emptyDiv.textContent = 'No products found';
                            resultsDiv.appendChild(emptyDiv);
                            resultsDiv.style.display = 'block';
                            return;
                        }
                        resultsDiv.textContent = '';
                        items.forEach(function(item) {
                            var el = document.createElement('div');
                            el.className = 'unmatched-result-item';
                            el.dataset.pid = item.product_id;
                            el.style.cssText = 'display:flex;gap:8px;align-items:center;padding:8px 10px;cursor:pointer;transition:background .15s;';
                            el.addEventListener('mouseover', function(){ el.style.background = '#f9fafb'; });
                            el.addEventListener('mouseout', function(){ el.style.background = 'transparent'; });

                            if (item.image) {
                                var img = document.createElement('img');
                                img.src = item.image;
                                img.alt = '';
                                img.style.cssText = 'width:32px;height:32px;object-fit:cover;border-radius:6px;flex-shrink:0;';
                                el.appendChild(img);
                            }

                            var infoDiv = document.createElement('div');
                            infoDiv.style.cssText = 'flex:1;min-width:0;';

                            var nameDiv = document.createElement('div');
                            nameDiv.className = 'result-name';
                            nameDiv.style.cssText = 'font-size:12px;font-weight:600;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
                            nameDiv.textContent = item.name;
                            infoDiv.appendChild(nameDiv);

                            var skuDiv = document.createElement('div');
                            skuDiv.className = 'result-sku';
                            skuDiv.style.cssText = 'font-size:11px;color:#6b7280;';
                            skuDiv.textContent = (item.model || '-') + (item.sku && item.sku !== item.model ? ' / ' + item.sku : '') + ' | ID: ' + item.product_id;
                            infoDiv.appendChild(skuDiv);

                            if (item.options && item.options.length) {
                                var optsDiv = document.createElement('div');
                                optsDiv.style.marginTop = '2px';
                                item.options.forEach(function(o) {
                                    var optLine = document.createElement('div');
                                    optLine.style.cssText = 'font-size:10px; color:#9ca3af;';
                                    optLine.textContent = (o.name ? o.name + ' - ' : '') + 'SKU: ' + o.sku + ' (Qty: ' + o.qty + ')';
                                    optsDiv.appendChild(optLine);
                                });
                                infoDiv.appendChild(optsDiv);
                            }

                            el.appendChild(infoDiv);
                            resultsDiv.appendChild(el);
                        });
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
