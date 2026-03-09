<!DOCTYPE html>
<html lang="en">
<head>
    <title>Shop A Inventory</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/modern.css', 'resources/js/modern.js'])
    @stack('styles')

    <!-- WYSIWYG (Summernote) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        if (window.jQuery && jQuery.fn && jQuery.fn.summernote) {
          jQuery('textarea.wysiwyg').summernote({
            height: 420,
            toolbar: [
              ['style', ['style']],
              ['font', ['bold', 'italic', 'underline', 'clear']],
              ['para', ['ul', 'ol', 'paragraph']],
              ['insert', ['link', 'table']],
              ['view', ['codeview']]
            ]
          });
        }
      });
    </script>
</head>
<body class="bg-gray-50 font-sans antialiased">

@php
    $u = auth()->user();
    $s = $appSetting ?? null;
    $companyName = $s->company_name ?? 'Company';
    $logoPath = $s->logo_path ?? null;

    $canCatalog = $u && $u->hasPermission('manage_catalog');
    $canProducts = $u && $u->hasPermission('manage_products');
    $canCategories = $u && $u->hasPermission('manage_categories');
    $canManufacturers = $u && $u->hasPermission('manage_manufacturers');
    $canOptions = $u && $u->hasPermission('manage_options');

    $canSales = $u && $u->hasPermission('manage_sales');
    $canOrders = $u && $u->hasPermission('manage_orders');
    $canOrderStatuses = $u && $u->hasPermission('manage_order_statuses');
    $canReports = $u && $u->hasPermission('manage_reports');

    $canMarketplace = $u && $u->hasPermission('manage_marketplace_api');
    $canShopee = $u && $u->hasPermission('manage_shopee');
    $canLazada = $u && $u->hasPermission('manage_lazada');
    $canShopeeOrders = $u && $u->hasPermission('manage_shopee_orders');
    $canLazadaOrders = $u && $u->hasPermission('manage_lazada_orders');
    $canTikTok = $u && $u->hasPermission('manage_tiktok');
    $canOpencart = $u && $u->hasPermission('manage_opencart');
    $canOpencartOrders = $u && $u->hasPermission('manage_opencart_orders');
    $canReviews = $u && $u->hasPermission('manage_reviews');
    $canPedallion = $u && $u->hasPermission('manage_pedallion');
    $canPedallionOrders = $u && $u->hasPermission('manage_pedallion_orders');

    $canPurchasing = $u && $u->hasPermission('manage_purchasing');

    $canSettingsParent = $u && $u->hasPermission('manage_settings');
    $canUsers = $u && $u->hasPermission('manage_users');
    $canUserGroups = $u && $u->hasPermission('manage_user_groups');
    $canWebsiteSettings = $u && $u->hasPermission('manage_website_settings');
    $canErrorLog = $u && $u->hasPermission('manage_error_log');
    $canActivityLog = $u && $u->hasPermission('manage_activity_log');
@endphp

{{-- Mobile backdrop --}}
<div x-data x-show="$store.sidebar.mobileOpen" x-transition:enter="transition-opacity duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition-opacity duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @click="$store.sidebar.mobileOpen = false" class="fixed inset-0 bg-black/50 z-40 lg:hidden" style="display:none;"></div>

{{-- Mobile hamburger --}}
<button x-data @click="$store.sidebar.mobileOpen = true" class="fixed top-4 left-4 z-30 lg:hidden bg-white shadow-lg rounded-lg p-2 text-gray-600 hover:text-gray-900 transition-colors" :class="{ 'hidden': $store.sidebar.mobileOpen }">
    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
</button>

<div class="flex min-h-screen" x-data>
    {{-- ════════════════════════ SIDEBAR ════════════════════════ --}}
    <aside
        :class="{
            'w-64': !$store.sidebar.collapsed,
            'w-16': $store.sidebar.collapsed,
            'translate-x-0': $store.sidebar.mobileOpen,
            '-translate-x-full': !$store.sidebar.mobileOpen
        }"
        class="fixed inset-y-0 left-0 z-50 bg-gray-900 text-gray-300 flex flex-col transition-all duration-300 ease-in-out lg:translate-x-0 overflow-hidden"
    >
        {{-- Brand --}}
        <div class="flex items-center justify-between h-16 px-4 border-b border-gray-800 flex-shrink-0">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2 text-white no-underline overflow-hidden" :class="{ 'justify-center w-full': $store.sidebar.collapsed }">
                @if($logoPath)
                    <img src="{{ asset('storage/'.$logoPath) }}" alt="Logo" class="h-8 w-8 rounded object-cover flex-shrink-0">
                    <span class="font-semibold text-sm whitespace-nowrap transition-opacity duration-300" :class="{ 'opacity-0 w-0': $store.sidebar.collapsed }">{{ $companyName }}</span>
                @else
                    <span class="font-semibold text-sm whitespace-nowrap transition-opacity duration-300" :class="{ 'opacity-0 w-0': $store.sidebar.collapsed }">{{ $companyName }}</span>
                @endif
            </a>
            <button @click="$store.sidebar.toggle()" class="text-gray-400 hover:text-white transition-colors hidden lg:block flex-shrink-0" :class="{ 'mx-auto': $store.sidebar.collapsed }">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            {{-- Mobile close button --}}
            <button @click="$store.sidebar.mobileOpen = false" class="text-gray-400 hover:text-white transition-colors lg:hidden flex-shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        {{-- Scrollable nav --}}
        <nav class="flex-1 overflow-y-auto overflow-x-hidden py-4 space-y-1 sidebar-nav-scroll">

            {{-- Dashboard --}}
            <a href="{{ route('dashboard') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors duration-150 {{ request()->routeIs('dashboard') ? 'bg-gray-800 text-white border-l-4 border-blue-500 pl-3' : 'hover:bg-gray-800 hover:text-white border-l-4 border-transparent pl-3' }}">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span class="whitespace-nowrap transition-opacity duration-300" :class="{ 'opacity-0 w-0 overflow-hidden': $store.sidebar.collapsed }">Dashboard</span>
            </a>

            {{-- Lazada Orders --}}
            @if($canLazadaOrders)
            <a href="{{ route('lazada.orders.index', ['tab' => 'TO_SHIP']) }}" class="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors duration-150 {{ request()->routeIs('lazada.orders.*') ? 'bg-gray-800 text-white border-l-4 border-blue-500 pl-3' : 'hover:bg-gray-800 hover:text-white border-l-4 border-transparent pl-3' }}">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <span class="whitespace-nowrap transition-opacity duration-300" :class="{ 'opacity-0 w-0 overflow-hidden': $store.sidebar.collapsed }">Lazada Orders</span>
                @if(($lazadaPendingCount ?? 0) > 0)
                    <span class="ml-auto bg-red-600 text-white text-xs font-bold min-w-[20px] h-5 leading-5 text-center rounded-full px-1.5 flex-shrink-0" :class="{ 'hidden': $store.sidebar.collapsed }">{{ $lazadaPendingCount }}</span>
                @endif
            </a>
            @endif

            {{-- Shopee Orders --}}
            @if($canShopeeOrders)
            <a href="{{ route('shopee.orders.index', ['tab' => 'PENDING']) }}" class="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors duration-150 {{ request()->routeIs('shopee.orders.*') ? 'bg-gray-800 text-white border-l-4 border-blue-500 pl-3' : 'hover:bg-gray-800 hover:text-white border-l-4 border-transparent pl-3' }}">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <span class="whitespace-nowrap transition-opacity duration-300" :class="{ 'opacity-0 w-0 overflow-hidden': $store.sidebar.collapsed }">Shopee Orders</span>
                @if(($shopeePendingCount ?? 0) > 0)
                    <span class="ml-auto bg-red-600 text-white text-xs font-bold min-w-[20px] h-5 leading-5 text-center rounded-full px-1.5 flex-shrink-0" :class="{ 'hidden': $store.sidebar.collapsed }">{{ $shopeePendingCount }}</span>
                @endif
            </a>
            @endif

            {{-- OpenCart Orders (per store) --}}
            @if($canOpencartOrders)
                @foreach(($opencartStores ?? collect()) as $ocStore)
                <a href="{{ route('opencart.orders.index', $ocStore->id) }}" class="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors duration-150 {{ request()->routeIs('opencart.orders.*') && request()->route('store') == $ocStore->id ? 'bg-gray-800 text-white border-l-4 border-blue-500 pl-3' : 'hover:bg-gray-800 hover:text-white border-l-4 border-transparent pl-3' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    <span class="whitespace-nowrap transition-opacity duration-300" :class="{ 'opacity-0 w-0 overflow-hidden': $store.sidebar.collapsed }">{{ $ocStore->store_name }} Orders</span>
                    @if((int) ($ocStore->pending_count ?? 0) > 0)
                        <span class="ml-auto bg-red-600 text-white text-xs font-bold min-w-[20px] h-5 leading-5 text-center rounded-full px-1.5 flex-shrink-0" :class="{ 'hidden': $store.sidebar.collapsed }">{{ (int) $ocStore->pending_count }}</span>
                    @endif
                </a>
                @endforeach
            @endif

            {{-- ── Catalog ── --}}
            @if($canCatalog)
            @php $catalogActive = request()->routeIs('products.*') || request()->routeIs('categories.*') || request()->routeIs('manufacturers.*'); @endphp
            <div x-data="{ open: {{ $catalogActive ? 'true' : 'false' }} }" class="nav-group-modern">
                <button @click="open = !open" class="flex items-center gap-3 w-full px-4 py-2.5 text-sm transition-colors duration-150 text-left {{ $catalogActive ? 'bg-gray-800 text-white border-l-4 border-blue-500 pl-3' : 'hover:bg-gray-800 hover:text-white border-l-4 border-transparent pl-3' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                    <span class="whitespace-nowrap transition-opacity duration-300" :class="{ 'opacity-0 w-0 overflow-hidden': $store.sidebar.collapsed }">Catalog</span>
                    <svg class="w-4 h-4 ml-auto flex-shrink-0 transition-transform duration-200" :class="{ 'rotate-90': open, 'opacity-0': $store.sidebar.collapsed }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
                <div x-show="open && !$store.sidebar.collapsed" x-collapse class="bg-gray-950/50">
                    @if($canCategories)
                    <a href="{{ route('categories.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('categories.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        <span>Categories</span>
                    </a>
                    @endif
                    @if($canProducts)
                    <a href="{{ route('products.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('products.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                        <span>Products</span>
                    </a>
                    @endif
                    @if($canManufacturers)
                    <a href="{{ route('manufacturers.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('manufacturers.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                        <span>Manufacturers</span>
                    </a>
                    @endif
                </div>
            </div>
            @endif

            {{-- ── Sales ── --}}
            @if($canSales)
            @php $salesActive = request()->routeIs('orders.*') || request()->routeIs('order_statuses.*'); @endphp
            <div x-data="{ open: {{ $salesActive ? 'true' : 'false' }} }" class="nav-group-modern">
                <button @click="open = !open" class="flex items-center gap-3 w-full px-4 py-2.5 text-sm transition-colors duration-150 text-left {{ $salesActive ? 'bg-gray-800 text-white border-l-4 border-blue-500 pl-3' : 'hover:bg-gray-800 hover:text-white border-l-4 border-transparent pl-3' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    <span class="whitespace-nowrap transition-opacity duration-300" :class="{ 'opacity-0 w-0 overflow-hidden': $store.sidebar.collapsed }">Sales</span>
                    <svg class="w-4 h-4 ml-auto flex-shrink-0 transition-transform duration-200" :class="{ 'rotate-90': open, 'opacity-0': $store.sidebar.collapsed }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
                <div x-show="open && !$store.sidebar.collapsed" x-collapse class="bg-gray-950/50">
                    @if($canOrders)
                    <a href="{{ route('orders.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('orders.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                        <span>Orders</span>
                    </a>
                    @endif
                    @if($canOrderStatuses)
                    <a href="{{ route('order_statuses.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('order_statuses.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>
                        <span>Order Statuses</span>
                    </a>
                    @endif
                </div>
            </div>
            @endif

            {{-- ── Purchasing ── --}}
            @if($canPurchasing)
            @php $purchasingActive = request()->routeIs('vendors.*') || request()->routeIs('purchase_orders.*') || request()->routeIs('reorder.*'); @endphp
            <div x-data="{ open: {{ $purchasingActive ? 'true' : 'false' }} }" class="nav-group-modern">
                <button @click="open = !open" class="flex items-center gap-3 w-full px-4 py-2.5 text-sm transition-colors duration-150 text-left {{ $purchasingActive ? 'bg-gray-800 text-white border-l-4 border-blue-500 pl-3' : 'hover:bg-gray-800 hover:text-white border-l-4 border-transparent pl-3' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                    <span class="whitespace-nowrap transition-opacity duration-300" :class="{ 'opacity-0 w-0 overflow-hidden': $store.sidebar.collapsed }">Purchasing</span>
                    <svg class="w-4 h-4 ml-auto flex-shrink-0 transition-transform duration-200" :class="{ 'rotate-90': open, 'opacity-0': $store.sidebar.collapsed }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
                <div x-show="open && !$store.sidebar.collapsed" x-collapse class="bg-gray-950/50">
                    <a href="{{ route('vendors.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('vendors.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <span>Vendors</span>
                    </a>
                    <a href="{{ route('purchase_orders.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('purchase_orders.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        <span>Purchase Orders</span>
                    </a>
                    <a href="{{ route('reorder.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('reorder.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <span>Reorder Suggestions</span>
                    </a>
                </div>
            </div>
            @endif

            {{-- ── Marketplace API ── --}}
            @if($canMarketplace)
            @php
                $mpActive = (request()->routeIs('shopee.*') && !request()->routeIs('shopee.orders.*'))
                         || (request()->routeIs('lazada.*') && !request()->routeIs('lazada.orders.*'))
                         || request()->routeIs('tiktok.*')
                         || (request()->routeIs('pedallion.*') && !request()->routeIs('pedallion.orders.*'))
                         || (request()->routeIs('opencart.*') && !request()->routeIs('opencart.orders.*'))
                         || request()->routeIs('reviews.*');
            @endphp
            <div x-data="{ open: {{ $mpActive ? 'true' : 'false' }} }" class="nav-group-modern">
                <button @click="open = !open" class="flex items-center gap-3 w-full px-4 py-2.5 text-sm transition-colors duration-150 text-left {{ $mpActive ? 'bg-gray-800 text-white border-l-4 border-blue-500 pl-3' : 'hover:bg-gray-800 hover:text-white border-l-4 border-transparent pl-3' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                    <span class="whitespace-nowrap transition-opacity duration-300" :class="{ 'opacity-0 w-0 overflow-hidden': $store.sidebar.collapsed }">Marketplace API</span>
                    <svg class="w-4 h-4 ml-auto flex-shrink-0 transition-transform duration-200" :class="{ 'rotate-90': open, 'opacity-0': $store.sidebar.collapsed }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
                <div x-show="open && !$store.sidebar.collapsed" x-collapse class="bg-gray-950/50">
                    @if($canShopee)
                    {{-- Shopee --}}
                    <div class="px-12 pt-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">Shopee</div>
                    <a href="{{ route('shopee.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('shopee.index') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        <span>Settings</span>
                    </a>
                    <a href="{{ route('shopee.categories.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('shopee.categories.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        <span>Categories</span>
                    </a>
                    <a href="{{ route('shopee.logistics.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('shopee.logistics.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                        <span>Logistics</span>
                    </a>
                    <a href="{{ route('shopee.profiles.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('shopee.profiles.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                        <span>Profiles</span>
                    </a>
                    <a href="{{ route('shopee.products.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('shopee.products.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                        <span>Products</span>
                    </a>
                    @endif

                    @if($canLazada)
                    {{-- Lazada --}}
                    <div class="px-12 pt-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">Lazada</div>
                    <a href="{{ route('lazada.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('lazada.index') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        <span>Settings</span>
                    </a>
                    <a href="{{ route('lazada.categories.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('lazada.categories.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        <span>Categories</span>
                    </a>
                    <a href="{{ route('lazada.profiles.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('lazada.profiles.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                        <span>Profiles</span>
                    </a>
                    <a href="{{ route('lazada.products.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('lazada.products.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
                        <span>Products</span>
                    </a>
                    @endif

                    @if($canTikTok)
                    {{-- TikTok Shop --}}
                    <div class="px-12 pt-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">TikTok Shop</div>
                    <a href="{{ route('tiktok.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('tiktok.index') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        <span>Settings</span>
                    </a>
                    @endif

                    @if($canPedallion)
                    {{-- Pedallion --}}
                    <div class="px-12 pt-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">Pedallion</div>
                    <a href="{{ route('pedallion.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('pedallion.index') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        <span>Settings</span>
                    </a>
                    <a href="{{ route('pedallion.categories.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('pedallion.categories.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        <span>Reference Data</span>
                    </a>
                    <a href="{{ route('pedallion.profiles.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('pedallion.profiles.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                        <span>Profiles</span>
                    </a>
                    <a href="{{ route('pedallion.products.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('pedallion.products.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                        <span>Products</span>
                    </a>
                    @endif

                    @if($canOpencart)
                    {{-- OpenCart --}}
                    <div class="px-12 pt-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">OpenCart</div>
                    <a href="{{ route('opencart.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('opencart.index') || request()->routeIs('opencart.save') || request()->routeIs('opencart.test') || request()->routeIs('opencart.sync') || request()->routeIs('opencart.push') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        <span>Settings</span>
                    </a>
                    @foreach(($opencartStores ?? collect()) as $ocStore)
                        <a href="{{ route('opencart.profiles.index', $ocStore->id) }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('opencart.profiles.*') && request()->route('store') == $ocStore->id ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                            <span>{{ $ocStore->store_name }}</span>
                        </a>
                    @endforeach
                    @endif

                    @if($canReviews)
                    {{-- Reviews --}}
                    <div class="px-12 pt-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">Reviews</div>
                    <a href="{{ route('reviews.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('reviews.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        <span>Reviews</span>
                    </a>
                    @endif
                </div>
            </div>
            @endif

            {{-- ── Reports ── --}}
            @if($canReports)
            @php $reportsActive = request()->routeIs('reports.*'); @endphp
            <div x-data="{ open: {{ $reportsActive ? 'true' : 'false' }} }" class="nav-group-modern">
                <button @click="open = !open" class="flex items-center gap-3 w-full px-4 py-2.5 text-sm transition-colors duration-150 text-left {{ $reportsActive ? 'bg-gray-800 text-white border-l-4 border-blue-500 pl-3' : 'hover:bg-gray-800 hover:text-white border-l-4 border-transparent pl-3' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                    <span class="whitespace-nowrap transition-opacity duration-300" :class="{ 'opacity-0 w-0 overflow-hidden': $store.sidebar.collapsed }">Reports</span>
                    <svg class="w-4 h-4 ml-auto flex-shrink-0 transition-transform duration-200" :class="{ 'rotate-90': open, 'opacity-0': $store.sidebar.collapsed }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
                <div x-show="open && !$store.sidebar.collapsed" x-collapse class="bg-gray-950/50">
                    <a href="{{ route('reports.orders') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('reports.orders*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>
                        <span>Order Profitability</span>
                    </a>
                    <a href="{{ route('reports.products') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('reports.products*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                        <span>Product Profitability</span>
                    </a>
                    <a href="{{ route('reports.payouts') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('reports.payouts*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                        <span>Marketplace Payouts</span>
                    </a>
                    <a href="{{ route('reports.categories') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('reports.categories*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                        <span>Margin by Category</span>
                    </a>
                    <a href="{{ route('reports.fees') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('reports.fees*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        <span>Marketplace Fees</span>
                    </a>
                    <a href="{{ route('reports.inventory') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('reports.inventory*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                        <span>Inventory Valuation</span>
                    </a>
                </div>
            </div>
            @endif

            {{-- ── Settings ── --}}
            @if($canSettingsParent)
            @php $settingsActive = request()->routeIs('users.*') || request()->routeIs('user_groups.*') || request()->routeIs('settings.*') || request()->routeIs('currencies.*') || request()->routeIs('error_log.*') || request()->routeIs('activity_log.*'); @endphp
            <div x-data="{ open: {{ $settingsActive ? 'true' : 'false' }} }" class="nav-group-modern">
                <button @click="open = !open" class="flex items-center gap-3 w-full px-4 py-2.5 text-sm transition-colors duration-150 text-left {{ $settingsActive ? 'bg-gray-800 text-white border-l-4 border-blue-500 pl-3' : 'hover:bg-gray-800 hover:text-white border-l-4 border-transparent pl-3' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <span class="whitespace-nowrap transition-opacity duration-300" :class="{ 'opacity-0 w-0 overflow-hidden': $store.sidebar.collapsed }">Settings</span>
                    <svg class="w-4 h-4 ml-auto flex-shrink-0 transition-transform duration-200" :class="{ 'rotate-90': open, 'opacity-0': $store.sidebar.collapsed }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
                <div x-show="open && !$store.sidebar.collapsed" x-collapse class="bg-gray-950/50">
                    @if($canWebsiteSettings)
                    <a href="{{ route('settings.edit') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('settings.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <span>Website Setting</span>
                    </a>
                    @endif
                    @if($canWebsiteSettings)
                    <a href="{{ route('currencies.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('currencies.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        <span>Currencies</span>
                    </a>
                    @endif
                    @if($canUsers)
                    <a href="{{ route('users.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('users.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span>Users</span>
                    </a>
                    @endif
                    @if($canUserGroups)
                    <a href="{{ route('user_groups.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('user_groups.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <span>User Groups</span>
                    </a>
                    @endif
                    @if($canErrorLog)
                    <a href="{{ route('error_log.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('error_log.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <span>Error Log</span>
                    </a>
                    @endif
                    @if($canActivityLog)
                    <a href="{{ route('activity_log.index') }}" class="flex items-center gap-3 pl-12 pr-4 py-2 text-sm transition-colors duration-150 {{ request()->routeIs('activity_log.*') ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                        <span>Activity Log</span>
                    </a>
                    @endif
                </div>
            </div>
            @endif

        </nav>

        {{-- Sidebar footer: theme toggle + logout --}}
        <div class="flex-shrink-0 border-t border-gray-800">
            <form method="POST" action="{{ route('theme.toggle') }}" class="px-4 py-3 border-b border-gray-800/50">
                @csrf
                <button type="submit" class="w-full text-xs text-gray-500 hover:text-gray-300 bg-transparent border-0 cursor-pointer text-center transition-colors duration-150" :class="{ 'px-0': $store.sidebar.collapsed }">
                    <span :class="{ 'hidden': $store.sidebar.collapsed }">Switch to Classic</span>
                    <svg x-show="$store.sidebar.collapsed" class="w-4 h-4 mx-auto" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                </button>
            </form>
            @auth
            <form method="POST" action="{{ route('logout') }}" class="px-4 py-3">
                @csrf
                <button type="submit" class="flex items-center gap-3 w-full text-sm text-gray-400 hover:text-white bg-transparent border-0 cursor-pointer transition-colors duration-150" :class="{ 'justify-center': $store.sidebar.collapsed }">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    <span class="whitespace-nowrap transition-opacity duration-300" :class="{ 'opacity-0 w-0 overflow-hidden': $store.sidebar.collapsed }">Logout</span>
                </button>
            </form>
            @endauth
        </div>
    </aside>

    {{-- ════════════════════════ MAIN CONTENT ════════════════════════ --}}
    <main
        class="flex-1 min-h-screen transition-all duration-300"
        :class="{
            'lg:ml-64': !$store.sidebar.collapsed,
            'lg:ml-16': $store.sidebar.collapsed
        }"
    >
        {{-- Topbar --}}
        <header class="sticky top-0 z-20 bg-white shadow-sm border-b border-gray-200">
            <div class="flex items-center justify-between px-4 sm:px-6 lg:px-8 h-16">
                {{-- Breadcrumb --}}
                <div class="flex items-center text-sm font-medium text-gray-900">
                    @php
                        $__bcRoutes = [
                            'Dashboard' => 'dashboard',
                            'Catalog' => 'products.index',
                            'Products' => 'products.index',
                            'Categories' => 'categories.index',
                            'Manufacturers' => 'manufacturers.index',
                            'Sales' => 'orders.index',
                            'Orders' => 'orders.index',
                            'Order Statuses' => 'order_statuses.index',
                            'Purchasing' => 'purchase_orders.index',
                            'Purchase Orders' => 'purchase_orders.index',
                            'Vendors' => 'vendors.index',
                            'Reorder Suggestions' => 'reorder.index',
                            'Settings' => 'settings.edit',
                            'Users' => 'users.index',
                            'User Groups' => 'user_groups.index',
                            'Currencies' => 'currencies.index',
                            'Marketplace' => 'shopee.orders.index',
                            'Marketplace API' => 'shopee.orders.index',
                            'Shopee' => 'shopee.orders.index',
                            'Lazada' => 'lazada.orders.index',
                            'TikTok Shop' => 'tiktok.index',
                            'Pedallion' => 'pedallion.index',
                            'OpenCart' => 'opencart.index',
                            'Reports' => 'reports.orders',
                        ];
                        $__bcRaw = trim($__env->yieldContent('breadcrumb', 'Dashboard'));
                        $__bcParts = array_map('trim', explode('/', $__bcRaw));
                        $__bcCount = count($__bcParts);
                    @endphp
                    @foreach($__bcParts as $__bcIdx => $__bcPart)
                        @if($__bcIdx < $__bcCount - 1 && isset($__bcRoutes[$__bcPart]))
                            <a href="{{ route($__bcRoutes[$__bcPart]) }}" class="text-gray-500 hover:text-gray-700 transition-colors">{{ $__bcPart }}</a>
                            <svg class="w-4 h-4 mx-1 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                        @elseif($__bcIdx < $__bcCount - 1)
                            <span class="text-gray-500">{{ $__bcPart }}</span>
                            <svg class="w-4 h-4 mx-1 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                        @else
                            <span>{{ $__bcPart }}</span>
                        @endif
                    @endforeach
                </div>

                {{-- Global search --}}
                @auth
                <div class="relative" id="global-search-wrap">
                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="global-search" placeholder="Search... (press /)" autocomplete="off" class="w-56 sm:w-72 h-9 pl-9 pr-4 text-sm bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                    </div>
                    <div id="global-search-results" style="display:none;" class="absolute top-full right-0 mt-2 w-96 max-h-[480px] overflow-y-auto bg-white border border-gray-200 rounded-xl shadow-xl z-50"></div>
                </div>
                @endauth
            </div>
        </header>

        {{-- Content area --}}
        <div class="px-4 sm:px-6 lg:px-8 py-6">

            {{-- Flash alerts --}}
            @if(session('status'))
                <div class="mb-4 flex items-start gap-3 p-4 bg-green-50 border border-green-200 text-green-800 rounded-xl" role="alert">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <span class="text-sm">{{ session('status') }}</span>
                    <button onclick="this.parentElement.remove()" class="ml-auto text-green-400 hover:text-green-600 text-lg leading-none">&times;</button>
                </div>
            @endif

            @if(session('error'))
                <div class="mb-4 flex items-start gap-3 p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl" role="alert">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <span class="text-sm">{{ session('error') }}</span>
                    <button onclick="this.parentElement.remove()" class="ml-auto text-red-400 hover:text-red-600 text-lg leading-none">&times;</button>
                </div>
            @endif

            @if($errors->any())
                <div class="mb-4 flex items-start gap-3 p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl" role="alert">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <div>
                        <ul class="text-sm list-disc pl-4 space-y-1">
                            @foreach($errors->all() as $e)
                                <li>{{ $e }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <button onclick="this.parentElement.remove()" class="ml-auto text-red-400 hover:text-red-600 text-lg leading-none">&times;</button>
                </div>
            @endif

            {{-- Marketplace token expiry warnings --}}
            @php
                $__lazadaExpired = false;
                $__shopeePaused = false;
                $__shopeeExpired = false;
                $__shopeeExpiringSoon = false;
                $__shopeeExpiresInMin = null;
                $__tiktokExpired = false;
                try {
                    $__tt = \App\Models\TikTokSetting::query()->first();
                    $__tiktokExpired = $__tt && $__tt->expires_at && strtotime($__tt->expires_at) <= time();
                } catch (\Throwable $e) {}
                try {
                    $__laz = \App\Models\LazadaSetting::query()->first();
                    $__lazadaExpired = $__laz && $__laz->expires_at && strtotime($__laz->expires_at) <= time();
                } catch (\Throwable $e) {}
                try {
                    $__sp = \App\Models\ShopeeSetting::query()->first();
                    if ($__sp && $__sp->expires_at) {
                        $__spExp = \Carbon\Carbon::parse($__sp->expires_at);
                        if ($__spExp->lte(now())) {
                            $__shopeeExpired = true;
                        } elseif ($__spExp->lte(now()->addMinutes(30))) {
                            $__shopeeExpiringSoon = true;
                            $__shopeeExpiresInMin = (int) now()->diffInMinutes($__spExp);
                        }
                    }
                } catch (\Throwable $e) {}
                $__shopeePaused = \Illuminate\Support\Facades\Cache::get('shopee_sync_paused');
            @endphp

            @if($__tiktokExpired)
                <div class="mb-4 flex items-start gap-3 p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl" role="alert">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <span class="text-sm"><strong>TikTok Shop:</strong> Access token expired. <a href="{{ route('tiktok.index') }}" class="underline font-medium hover:text-red-900">Refresh token</a></span>
                </div>
            @endif

            @if($__lazadaExpired)
                <div class="mb-4 flex items-start gap-3 p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl" role="alert">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <span class="text-sm"><strong>Lazada:</strong> Access token expired. Order sync is paused. <a href="{{ route('lazada.index') }}" class="underline font-medium hover:text-red-900">Refresh token</a></span>
                </div>
            @endif

            @if($__shopeeExpired)
                <div class="mb-4 flex items-start gap-3 p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl" role="alert">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <span class="text-sm"><strong>Shopee:</strong> Access token expired. Sync commands will fail. <a href="{{ route('shopee.index') }}" class="underline font-medium hover:text-red-900">Refresh token</a></span>
                </div>
            @elseif($__shopeeExpiringSoon)
                <div class="mb-4 flex items-start gap-3 p-4 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-xl" role="alert">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-yellow-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <span class="text-sm"><strong>Shopee:</strong> Access token expires soon (in {{ $__shopeeExpiresInMin }} min). <a href="{{ route('shopee.index') }}" class="underline font-medium hover:text-yellow-900">Refresh token</a></span>
                </div>
            @endif

            @if($__shopeePaused)
                <div class="mb-4 flex items-start gap-3 p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl" role="alert">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <span class="text-sm"><strong>Shopee:</strong> Order sync paused -- {{ $__shopeePaused }}. <a href="{{ route('shopee.index') }}" class="underline font-medium hover:text-red-900">Check settings</a></span>
                </div>
            @endif

            @yield('content')

        </div>
    </main>
</div>

{{-- ════════════════════════ SCRIPTS ════════════════════════ --}}
<script>
document.addEventListener('alpine:init', () => {
    Alpine.store('sidebar', {
        collapsed: localStorage.getItem('ngd_sidebar_collapsed') === '1',
        mobileOpen: false,

        toggle() {
            this.collapsed = !this.collapsed;
            localStorage.setItem('ngd_sidebar_collapsed', this.collapsed ? '1' : '0');
        },

        init() {
            if (window.innerWidth < 1024) {
                this.collapsed = false;
                this.mobileOpen = false;
            }
        }
    });
});
</script>

<script>
(function () {
    // Toast notifications: move success/warning alerts to fixed toast container
    var toastContainer = document.createElement('div');
    toastContainer.style.cssText = 'position:fixed; top:20px; right:20px; z-index:9999; display:flex; flex-direction:column; gap:8px; max-width:420px;';
    document.body.appendChild(toastContainer);

    document.querySelectorAll('[role="alert"]').forEach(function(el) {
        if (el.classList.contains('bg-green-50') || el.classList.contains('bg-yellow-50')) {
            toastContainer.appendChild(el);
            el.style.marginBottom = '0';
            setTimeout(function() {
                el.style.transition = 'opacity 0.3s, transform 0.3s';
                el.style.opacity = '0';
                el.style.transform = 'translateX(20px)';
                setTimeout(function(){ el.remove(); }, 350);
            }, 5000);
        }
    });

    // Loading state on form submit buttons
    var lastClickedSubmit = null;
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('button[type="submit"], button:not([type])');
        if (btn) lastClickedSubmit = btn;
    });

    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            var btn = lastClickedSubmit;
            if (!btn || !form.contains(btn)) {
                var btns = form.querySelectorAll('button[type="submit"], button:not([type])');
                btn = btns.length === 1 ? btns[0] : null;
            }
            lastClickedSubmit = null;
            if (!btn) return;
            setTimeout(function() {
                if (e.defaultPrevented) return;
                btn.classList.add('opacity-50', 'pointer-events-none');
                btn.disabled = true;
                setTimeout(function() {
                    btn.classList.remove('opacity-50', 'pointer-events-none');
                    btn.disabled = false;
                }, 300000);
            }, 0);
        });
    });
})();
</script>

@stack('scripts')

{{-- Manufacturer search typeahead --}}
<script>
(function(){
  function setupManufacturerSearch(){
    var input = document.getElementById('manufacturer-search');
    var dd = document.getElementById('manufacturer-search-dd');
    var list = document.getElementById('manufacturer-search-list');
    if(!input || !dd || !list) return;

    var last = '';
    var timer = null;

    function hide(){ dd.style.display='none'; }
    function show(){ dd.style.display='block'; }
    function render(items){
      list.textContent = '';
      if(!items || !items.length){ hide(); return; }
      items.forEach(function(it){
        var li=document.createElement('li');
        li.textContent = it.name;
        li.addEventListener('mousedown', function(e){
          e.preventDefault();
          input.value = it.name;
          hide();
        });
        list.appendChild(li);
      });
      show();
    }

    async function fetchItems(q){
      try{
        var res = await fetch('/api/catalog/manufacturers?term=' + encodeURIComponent(q), {headers:{'Accept':'application/json'}, credentials:'same-origin'});
        if(!res.ok) return [];
        return await res.json();
      }catch(e){ return []; }
    }

    input.addEventListener('input', function(){
      var q = (input.value || '').trim();
      if(q.length < 1){ hide(); return; }
      if(q === last) return;
      last = q;
      clearTimeout(timer);
      timer = setTimeout(async function(){
        var items = await fetchItems(q);
        render(items, q);
      }, 120);
    });

    document.addEventListener('click', function(e){
      if(!dd.contains(e.target) && e.target !== input){ hide(); }
    });
  }

  document.addEventListener('DOMContentLoaded', setupManufacturerSearch);
})();
</script>

{{-- Generic typeahead setup --}}
<script>
(function(){
  function setupTypeahead(opts){
    var input = document.getElementById(opts.inputId);
    var hidden = opts.hiddenId ? document.getElementById(opts.hiddenId) : null;
    var list = document.getElementById(opts.listId);
    var isMulti = !!opts.multi;
    var tagContainer = isMulti ? document.getElementById(opts.tagContainerId) : null;
    if(!input || !list) return;
    if(!isMulti && !hidden) return;

    var last = '';
    var timer = null;

    function clearList(){ list.textContent = ''; list.style.display='none'; }
    function showList(){ list.style.display='block'; }
    function hideList(){ list.style.display='none'; }

    if(!isMulti){
      input.addEventListener('input', function(){
        hidden.value = '0';
      });
    }

    function getSelectedIds(){
      if(!tagContainer) return [];
      return Array.from(tagContainer.querySelectorAll('.tag-chip')).map(function(c){ return String(c.dataset.id); });
    }

    function addTag(id, name){
      if(!tagContainer) return;
      var chip = document.createElement('span');
      chip.className = 'tag-chip';
      chip.dataset.id = id;
      var text = document.createTextNode(name);
      chip.appendChild(text);
      var hiddenInput = document.createElement('input');
      hiddenInput.type = 'hidden';
      hiddenInput.name = opts.hiddenName;
      hiddenInput.value = id;
      chip.appendChild(hiddenInput);
      var removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'tag-remove';
      removeBtn.textContent = '\u00D7';
      removeBtn.addEventListener('click', function(){ chip.remove(); });
      chip.appendChild(removeBtn);
      tagContainer.appendChild(chip);
    }

    function render(items, query){
      list.textContent = '';
      var selectedIds = isMulti ? getSelectedIds() : [];
      var filtered = isMulti ? items.filter(function(it){ return selectedIds.indexOf(String(it.id)) === -1; }) : items;
      if(!filtered || !filtered.length){
        if(query && query.length >= 1){
          var empty = document.createElement('div');
          empty.style.cssText = 'padding:8px 12px;color:#94a3b8;font-size:13px;';
          empty.textContent = isMulti && items.length > 0 ? 'All matches already selected' : 'No results found';
          list.appendChild(empty);
          showList();
        } else { hideList(); }
        return;
      }
      filtered.forEach(function(it){
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = it.name;
        btn.addEventListener('mousedown', function(e){
          e.preventDefault();
          if(isMulti){
            addTag(it.id, it.name);
            input.value = '';
            clearList();
          } else {
            input.value = it.name;
            hidden.value = String(it.id);
            clearList();
            input.classList.remove('input-error');
            if(opts.onSelect) opts.onSelect(it);
          }
        });
        list.appendChild(btn);
      });
      showList();
    }

    async function fetchItems(q){
      try{
        var res = await fetch(opts.url + encodeURIComponent(q), {headers:{'Accept':'application/json'}, credentials:'same-origin'});
        if(!res.ok) return [];
        var data = await res.json();
        return (data || []).map(function(r){
          var item = Object.assign({}, r);
          item.id = r.id ?? r.manufacturer_id ?? r.category_id ?? r.option_id ?? r.value_id ?? r.option_value_id;
          item.name = r.name ?? r.path;
          return item;
        }).filter(function(r){ return r.id !== undefined && r.name; });
      }catch(e){ return []; }
    }

    input.addEventListener('focus', function(){
      var q = (input.value || '').trim();
      if(q.length >= 1 || opts.showAllOnFocus){
        last = '';
        input.dispatchEvent(new Event('keyup'));
      }
    });

    input.addEventListener('keyup', function(){
      var q = (input.value || '').trim();
      if(q.length < 1 && !opts.showAllOnFocus){ hideList(); return; }
      if(q === last) return;
      last = q;
      clearTimeout(timer);
      timer = setTimeout(async function(){
        var items = await fetchItems(q);
        render(items, q);
      }, 120);
    });

    document.addEventListener('click', function(e){
      if(e.target !== input && !list.contains(e.target)) hideList();
    });
  }

  window.setupTypeahead = setupTypeahead;

  document.addEventListener('DOMContentLoaded', function(){
    function updateMfgPrefix(mfgName) {
      var prefix = document.getElementById('name_mfg_prefix');
      var nameField = document.getElementById('product_name');
      if (!prefix || !nameField) return;
      var name = (mfgName || '').trim();
      if (name) {
        prefix.textContent = name;
        prefix.style.display = '';
        var current = (nameField.value || '').trim();
        if (current.toLowerCase().indexOf(name.toLowerCase()) === 0) {
          nameField.value = current.substring(name.length).replace(/^\s+/, '');
        }
      } else {
        prefix.style.display = 'none';
        prefix.textContent = '';
      }
    }

    var prodForm = document.getElementById('product_name');
    if (prodForm && prodForm.closest('form')) {
      prodForm.closest('form').addEventListener('submit', function() {
        var prefix = document.getElementById('name_mfg_prefix');
        var nameField = document.getElementById('product_name');
        if (!prefix || !nameField) return;
        var mfg = (prefix.textContent || '').trim();
        var current = (nameField.value || '').trim();
        if (mfg && current && current.toLowerCase().indexOf(mfg.toLowerCase()) !== 0) {
          nameField.value = mfg + ' ' + current;
        } else if (mfg && !current) {
          nameField.value = mfg;
        }
      });
    }

    setupTypeahead({
      inputId: 'manufacturer_search',
      hiddenId: 'manufacturer_id',
      listId: 'manufacturer_list',
      url: '/api/catalog/manufacturers?term=',
      onSelect: function(item) {
        updateMfgPrefix(item.name);
        var nameField = document.getElementById('product_name');
        if (nameField && !(nameField.value || '').trim()) {
          nameField.focus();
        }
      }
    });

    var mfgInput = document.getElementById('manufacturer_search');
    if (mfgInput && (mfgInput.value || '').trim()) {
      updateMfgPrefix(mfgInput.value.trim());
    }
    setupTypeahead({
      inputId: 'category_search',
      listId: 'category_list',
      url: '/api/catalog/categories?term=',
      multi: true,
      tagContainerId: 'category_tags',
      hiddenName: 'category_ids[]',
    });

    var mfgHidden = document.getElementById('manufacturer_id');
    var mfgInput2 = document.getElementById('manufacturer_search');
    if(mfgHidden && mfgInput2 && mfgInput2.closest('form')){
      mfgInput2.closest('form').addEventListener('submit', function(e){
        var val = parseInt(mfgHidden.value, 10);
        if(!val || val <= 0){
          e.preventDefault();
          mfgInput2.classList.add('input-error');
          mfgInput2.focus();
          mfgInput2.scrollIntoView({behavior:'smooth', block:'center'});
          var wrap = mfgInput2.closest('.typeahead');
          if(wrap && !wrap.querySelector('.field-error')){
            var err = document.createElement('div');
            err.className = 'field-error';
            err.textContent = 'Please select a manufacturer from the list.';
            wrap.appendChild(err);
          }
        }
      });
      mfgInput2.addEventListener('input', function(){
        mfgInput2.classList.remove('input-error');
        var wrap = mfgInput2.closest('.typeahead');
        if(wrap){
          var err = wrap.querySelector('.field-error');
          if(err) err.remove();
        }
      });
    }
  });
})();
</script>

{{-- Global search --}}
<script>
(function() {
    var input = document.getElementById('global-search');
    var wrap = document.getElementById('global-search-results');
    if (!input || !wrap) return;

    var timer = null;

    document.addEventListener('keydown', function(e) {
        if (e.key === '/' && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) {
            e.preventDefault();
            input.focus();
            input.select();
        }
        if (e.key === 'Escape') {
            wrap.style.display = 'none';
            input.blur();
        }
    });

    input.addEventListener('input', function() {
        clearTimeout(timer);
        var q = input.value.trim();
        if (q.length < 2) { wrap.style.display = 'none'; return; }
        timer = setTimeout(function() { doSearch(q); }, 300);
    });

    input.addEventListener('focus', function() {
        if (wrap.children.length > 0 && input.value.trim().length >= 2) {
            wrap.style.display = 'block';
        }
    });

    document.addEventListener('click', function(e) {
        var searchWrap = document.getElementById('global-search-wrap');
        if (searchWrap && !searchWrap.contains(e.target)) {
            wrap.style.display = 'none';
        }
    });

    function doSearch(q) {
        fetch('/search?q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            wrap.textContent = '';
            var results = data.results || [];
            if (results.length === 0) {
                var emptyDiv = document.createElement('div');
                emptyDiv.className = 'p-4 text-center text-gray-400 text-sm';
                emptyDiv.textContent = 'No results found';
                wrap.appendChild(emptyDiv);
                wrap.style.display = 'block';
                return;
            }

            var lastCat = '';
            results.forEach(function(r) {
                if (r.category !== lastCat) {
                    lastCat = r.category;
                    var catDiv = document.createElement('div');
                    catDiv.className = 'px-4 pt-3 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider';
                    catDiv.textContent = r.category;
                    wrap.appendChild(catDiv);
                }
                var link = document.createElement('a');
                link.href = r.url;
                link.className = 'block px-4 py-2 hover:bg-gray-50 transition-colors no-underline';
                var labelDiv = document.createElement('div');
                labelDiv.className = 'text-sm font-medium text-gray-900';
                labelDiv.textContent = r.label;
                link.appendChild(labelDiv);
                if (r.sub) {
                    var subDiv = document.createElement('div');
                    subDiv.className = 'text-xs text-gray-500';
                    subDiv.textContent = r.sub;
                    link.appendChild(subDiv);
                }
                wrap.appendChild(link);
            });

            wrap.style.display = 'block';
        })
        .catch(function() {
            wrap.textContent = '';
            var errDiv = document.createElement('div');
            errDiv.className = 'p-4 text-center text-red-500 text-sm';
            errDiv.textContent = 'Search failed';
            wrap.appendChild(errDiv);
            wrap.style.display = 'block';
        });
    }
})();
</script>

</body>
</html>
