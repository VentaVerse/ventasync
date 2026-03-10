<!DOCTYPE html>
<html>
<head>
    <title>Shop A Inventory</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
    <!-- CSS is now in resources/css/ and bundled via Vite -->

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
<body>
    <button id="sidebar-fab" class="sidebar-fab" type="button" aria-label="Open menu" title="Open menu">
        <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div id="sidebar-backdrop" class="sidebar-backdrop"></div>

<div class="layout">
    <aside id="sidebar" class="sidebar">
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

        <div class="brand">
            <a href="{{ route('dashboard') }}" class="brand-stack" style="text-decoration:none; color:inherit;">
                @if($logoPath)
                    <img class="brand-logo" src="{{ asset('storage/'.$logoPath) }}" alt="Company Logo">
                @else
                    <div class="brand-company">{{ $companyName }}</div>
                @endif
            </a>
            <button class="toggle-btn" id="sidebarToggle" type="button" aria-label="Toggle Sidebar" title="Toggle Sidebar">
                <span class="toggle-hamburger"><svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></span>
            </button>
        </div>

        <div class="sidebar-inner">
            <nav class="sidebar-nav">
                <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span>
                    <span class="label">Dashboard</span>
                </a>

                @if($canLazadaOrders)
                <a href="{{ route('lazada.orders.index', ['tab' => 'TO_SHIP']) }}" class="{{ request()->routeIs('lazada.orders.*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>
                    <span class="label">Lazada Orders</span>
                    @if(($lazadaPendingCount ?? 0) > 0)
                        <span style="margin-left:auto; background:#dc2626; color:#fff; font-weight:700; font-size:11px; min-width:20px; height:20px; line-height:20px; text-align:center; border-radius:10px; padding:0 6px; display:inline-block;">{{ $lazadaPendingCount }}</span>
                    @endif
                </a>
                @endif

                @if($canShopeeOrders)
                <a href="{{ route('shopee.orders.index', ['tab' => 'PENDING']) }}" class="{{ request()->routeIs('shopee.orders.*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>
                    <span class="label">Shopee Orders</span>
                    @if(($shopeePendingCount ?? 0) > 0)
                        <span style="margin-left:auto; background:#dc2626; color:#fff; font-weight:700; font-size:11px; min-width:20px; height:20px; line-height:20px; text-align:center; border-radius:10px; padding:0 6px; display:inline-block;">{{ $shopeePendingCount }}</span>
                    @endif
                </a>
                @endif

                @if($canOpencartOrders)
                    @foreach(($opencartStores ?? collect()) as $ocStore)
                    <a href="{{ route('opencart.orders.index', $ocStore->id) }}" class="{{ request()->routeIs('opencart.orders.*') && request()->route('store') == $ocStore->id ? 'active' : '' }}">
                        <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>
                        <span class="label">{{ $ocStore->store_name }} Orders</span>
                        @if((int) ($ocStore->pending_count ?? 0) > 0)
                            <span style="margin-left:auto; background:#dc2626; color:#fff; font-weight:700; font-size:11px; min-width:20px; height:20px; line-height:20px; text-align:center; border-radius:10px; padding:0 6px; display:inline-block;">{{ (int) $ocStore->pending_count }}</span>
                        @endif
                    </a>
                    @endforeach
                @endif

                @if($canCatalog)
                <div class="nav-group">
                    <details {{ (request()->routeIs('products.*') || request()->routeIs('categories.*') || request()->routeIs('manufacturers.*') || request()->routeIs('options.*')) ? 'open' : '' }}>
                        <summary class="{{ (request()->routeIs('products.*') || request()->routeIs('categories.*') || request()->routeIs('manufacturers.*') || request()->routeIs('options.*')) ? 'active' : '' }}">
                            <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></span>
                            <span class="label">Catalog</span>
                            <span class="chevron"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </summary>
                        <div class="nav-sub-wrap">
                            <div class="nav-sub">
                                @if($canCategories)
                                <a href="{{ route('categories.index') }}" class="{{ request()->routeIs('categories.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></span>
                                    <span class="label">Categories</span>
                                </a>
                                @endif
                                @if($canProducts)
                                <a href="{{ route('products.index') }}" class="{{ request()->routeIs('products.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></span>
                                    <span class="label">Products</span>
                                </a>
                                @endif
                                @if($canManufacturers)
                                <a href="{{ route('manufacturers.index') }}" class="{{ request()->routeIs('manufacturers.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg></span>
                                    <span class="label">Manufacturers</span>
                                </a>
                                @endif
                                @if($canOptions)
                                <a href="{{ route('options.index') }}" class="{{ request()->routeIs('options.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
                                    <span class="label">Options</span>
                                </a>
                                @endif
                            </div>
                        </div>
                    </details>
                </div>
                @endif

                @if($canSales)
                <div class="nav-group">
                    <details {{ (request()->routeIs('orders.*') || request()->routeIs('order_statuses.*')) ? 'open' : '' }}>
                        <summary class="{{ (request()->routeIs('orders.*') || request()->routeIs('order_statuses.*')) ? 'active' : '' }}">
                            <span class="nav-icon"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
                            <span class="label">Sales</span>
                            <span class="chevron"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </summary>
                        <div class="nav-sub-wrap">
                            <div class="nav-sub">
                                @if($canOrders)
                                <a href="{{ route('orders.index') }}" class="{{ request()->routeIs('orders.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></span>
                                    <span class="label">Orders</span>
                                </a>
                                @endif
                                @if($canOrderStatuses)
                                <a href="{{ route('order_statuses.index') }}" class="{{ request()->routeIs('order_statuses.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg></span>
                                    <span class="label">Order Statuses</span>
                                </a>
                                @endif
                                @if($canOrders)
                                <a href="{{ route('orders.payments_report') }}" class="{{ request()->routeIs('orders.payments_report') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></span>
                                    <span class="label">Order Payments</span>
                                </a>
                                @endif
                            </div>
                        </div>
                    </details>
                </div>
                @endif

                @if($canPurchasing)
                <div class="nav-group">
                    <details {{ (request()->routeIs('vendors.*') || request()->routeIs('purchase_orders.*') || request()->routeIs('reorder.*')) ? 'open' : '' }}>
                        <summary class="{{ (request()->routeIs('vendors.*') || request()->routeIs('purchase_orders.*') || request()->routeIs('reorder.*')) ? 'active' : '' }}">
                            <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></span>
                            <span class="label">Purchasing</span>
                            <span class="chevron"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </summary>
                        <div class="nav-sub-wrap">
                            <div class="nav-sub">
                                <a href="{{ route('vendors.index') }}" class="{{ request()->routeIs('vendors.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                                    <span class="label">Vendors</span>
                                </a>
                                <a href="{{ route('purchase_orders.index') }}" class="{{ request()->routeIs('purchase_orders.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>
                                    <span class="label">Purchase Orders</span>
                                </a>
                                <a href="{{ route('reorder.index') }}" class="{{ request()->routeIs('reorder.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span>
                                    <span class="label">Reorder Suggestions</span>
                                </a>
                            </div>
                        </div>
                    </details>
                </div>
                @endif

                @if($canMarketplace)
                <div class="nav-group">
                    @php
                        $mpActive = (request()->routeIs('shopee.*') && !request()->routeIs('shopee.orders.*'))
                                 || (request()->routeIs('lazada.*') && !request()->routeIs('lazada.orders.*'))
                                 || request()->routeIs('tiktok.*')
                                 || (request()->routeIs('pedallion.*') && !request()->routeIs('pedallion.orders.*'))
                                 || (request()->routeIs('opencart.*') && !request()->routeIs('opencart.orders.*'))
                                 || request()->routeIs('reviews.*');
                    @endphp
                    <details {{ $mpActive ? 'open' : '' }}>
                        <summary class="{{ $mpActive ? 'active' : '' }}">
                            <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg></span>
                            <span class="label">Marketplace API</span>
                            <span class="chevron"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </summary>
                        <div class="nav-sub-wrap">
                            <div class="nav-sub">
                                @if($canShopee)
                                {{-- ─── Shopee ─── --}}
                                <div class="nav-sub-divider">Shopee</div>
                                <a href="{{ route('shopee.index') }}" class="{{ request()->routeIs('shopee.index') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
                                    <span class="label">Settings</span>
                                </a>
                                <a href="{{ route('shopee.categories.index') }}" class="{{ request()->routeIs('shopee.categories.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></span>
                                    <span class="label">Categories</span>
                                </a>
                                <a href="{{ route('shopee.logistics.index') }}" class="{{ request()->routeIs('shopee.logistics.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></span>
                                    <span class="label">Logistics</span>
                                </a>
                                <a href="{{ route('shopee.profiles.index') }}" class="{{ request()->routeIs('shopee.profiles.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg></span>
                                    <span class="label">Profiles</span>
                                </a>
                                <a href="{{ route('shopee.products.index') }}" class="{{ request()->routeIs('shopee.products.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></span>
                                    <span class="label">Products</span>
                                </a>
                                @endif

                                @if($canLazada)
                                {{-- ─── Lazada ─── --}}
                                <div class="nav-sub-divider">Lazada</div>
                                <a href="{{ route('lazada.index') }}" class="{{ request()->routeIs('lazada.index') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
                                    <span class="label">Settings</span>
                                </a>
                                <a href="{{ route('lazada.categories.index') }}" class="{{ request()->routeIs('lazada.categories.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></span>
                                    <span class="label">Categories</span>
                                </a>
                                <a href="{{ route('lazada.profiles.index') }}" class="{{ request()->routeIs('lazada.profiles.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg></span>
                                    <span class="label">Profiles</span>
                                </a>
                                <a href="{{ route('lazada.products.index') }}" class="{{ request()->routeIs('lazada.products.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg></span>
                                    <span class="label">Products</span>
                                </a>
                                @endif

                                @if($canTikTok)
                                {{-- ─── TikTok Shop ─── --}}
                                <div class="nav-sub-divider">TikTok Shop</div>
                                <a href="{{ route('tiktok.index') }}" class="{{ request()->routeIs('tiktok.index') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
                                    <span class="label">Settings</span>
                                </a>
                                @endif

                                @if($canPedallion)
                                {{-- ─── Pedallion ─── --}}
                                <div class="nav-sub-divider">Pedallion</div>
                                <a href="{{ route('pedallion.index') }}" class="{{ request()->routeIs('pedallion.index') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
                                    <span class="label">Settings</span>
                                </a>
                                <a href="{{ route('pedallion.categories.index') }}" class="{{ request()->routeIs('pedallion.categories.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></span>
                                    <span class="label">Reference Data</span>
                                </a>
                                <a href="{{ route('pedallion.profiles.index') }}" class="{{ request()->routeIs('pedallion.profiles.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg></span>
                                    <span class="label">Profiles</span>
                                </a>
                                <a href="{{ route('pedallion.products.index') }}" class="{{ request()->routeIs('pedallion.products.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></span>
                                    <span class="label">Products</span>
                                </a>
                                @endif

                                @if($canOpencart)
                                {{-- ─── OpenCart ─── --}}
                                <div class="nav-sub-divider">OpenCart</div>
                                <a href="{{ route('opencart.index') }}" class="{{ request()->routeIs('opencart.index') || request()->routeIs('opencart.save') || request()->routeIs('opencart.test') || request()->routeIs('opencart.sync') || request()->routeIs('opencart.push') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
                                    <span class="label">Settings</span>
                                </a>
                                @foreach(($opencartStores ?? collect()) as $ocStore)
                                    <a href="{{ route('opencart.profiles.index', $ocStore->id) }}" class="{{ request()->routeIs('opencart.profiles.*') && request()->route('store') == $ocStore->id ? 'active' : '' }}">
                                        <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span>
                                        <span class="label">{{ $ocStore->store_name }}</span>
                                    </a>
                                @endforeach
                                @endif

                                @if($canReviews)
                                {{-- ─── Reviews ─── --}}
                                <div class="nav-sub-divider">Reviews</div>
                                <a href="{{ route('reviews.index') }}" class="{{ request()->routeIs('reviews.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></span>
                                    <span class="label">Reviews</span>
                                </a>
                                @endif
                            </div>
                        </div>
                    </details>
                </div>
                @endif

                @if($canReports)
                <div class="nav-group">
                    <details {{ request()->routeIs('reports.*') ? 'open' : '' }}>
                        <summary class="{{ request()->routeIs('reports.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg></span>
                            <span class="label">Reports</span>
                            <span class="chevron"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </summary>
                        <div class="nav-sub-wrap">
                            <div class="nav-sub">
                                <a href="{{ route('reports.orders') }}" class="{{ request()->routeIs('reports.orders*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg></span>
                                    <span class="label">Order Profitability</span>
                                </a>
                                <a href="{{ route('reports.products') }}" class="{{ request()->routeIs('reports.products*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></span>
                                    <span class="label">Product Profitability</span>
                                </a>
                                <a href="{{ route('reports.payouts') }}" class="{{ request()->routeIs('reports.payouts*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></span>
                                    <span class="label">Marketplace Payouts</span>
                                </a>
                                <a href="{{ route('reports.categories') }}" class="{{ request()->routeIs('reports.categories*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></span>
                                    <span class="label">Margin by Category</span>
                                </a>
                                <a href="{{ route('reports.fees') }}" class="{{ request()->routeIs('reports.fees*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
                                    <span class="label">Marketplace Fees</span>
                                </a>
                                <a href="{{ route('reports.inventory') }}" class="{{ request()->routeIs('reports.inventory*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></span>
                                    <span class="label">Inventory Valuation</span>
                                </a>
                            </div>
                        </div>
                    </details>
                </div>
                @endif

                @if($canSettingsParent)
                <div class="nav-group">
                    <details {{ (request()->routeIs('users.*') || request()->routeIs('user_groups.*') || request()->routeIs('settings.*') || request()->routeIs('currencies.*') || request()->routeIs('error_log.*') || request()->routeIs('activity_log.*')) ? 'open' : '' }}>
                        <summary class="{{ (request()->routeIs('users.*') || request()->routeIs('user_groups.*') || request()->routeIs('settings.*') || request()->routeIs('currencies.*') || request()->routeIs('error_log.*') || request()->routeIs('activity_log.*')) ? 'active' : '' }}">
                            <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
                            <span class="label">Settings</span>
                            <span class="chevron"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </summary>
                        <div class="nav-sub-wrap">
                            <div class="nav-sub">
                                @if($canWebsiteSettings)
                                <a href="{{ route('settings.edit') }}" class="{{ request()->routeIs('settings.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
                                    <span class="label">Website Setting</span>
                                </a>
                                @endif
                                @if($canWebsiteSettings)
                                <a href="{{ route('currencies.index') }}" class="{{ request()->routeIs('currencies.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
                                    <span class="label">Currencies</span>
                                </a>
                                @endif
                                @if($canUsers)
                                <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                                    <span class="label">Users</span>
                                </a>
                                @endif
                                @if($canUserGroups)
                                <a href="{{ route('user_groups.index') }}" class="{{ request()->routeIs('user_groups.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
                                    <span class="label">User Groups</span>
                                </a>
                                @endif
                                @if($canErrorLog)
                                <a href="{{ route('error_log.index') }}" class="{{ request()->routeIs('error_log.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span>
                                    <span class="label">Error Log</span>
                                </a>
                                @endif
                                @if($canActivityLog)
                                <a href="{{ route('activity_log.index') }}" class="{{ request()->routeIs('activity_log.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></span>
                                    <span class="label">Activity Log</span>
                                </a>
                                @endif
                            </div>
                        </div>
                    </details>
                </div>
                @endif
                {{-- Extension Nav Groups --}}
                @if(!empty($extensionNavGroups ?? []))
                    @foreach($extensionNavGroups as $extNav)
                        @php
                            $extPerm = $extNav['permission'] ?? '';
                            $canViewExt = $extPerm === '' || (auth()->user() && auth()->user()->hasPermission($extPerm));
                            $extRoutes = collect($extNav['items'] ?? [])->pluck('route')->toArray();
                            $extIsOpen = false;
                            foreach ($extRoutes as $er) {
                                if (\Route::has($er) && request()->routeIs(str_replace('.', '.', $er) . '*')) { $extIsOpen = true; break; }
                            }
                        @endphp
                        @if($canViewExt)
                        <div class="nav-group">
                            <details {{ $extIsOpen ? 'open' : '' }}>
                                <summary class="{{ $extIsOpen ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></span>
                                    <span class="label">{{ $extNav['group'] }}</span>
                                    <span class="chevron"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
                                </summary>
                                <div class="nav-sub-wrap">
                                    <div class="nav-sub">
                                        @foreach($extNav['items'] ?? [] as $extItem)
                                            @if(\Route::has($extItem['route']))
                                            <a href="{{ route($extItem['route']) }}" class="{{ request()->routeIs($extItem['route'] . '*') ? 'active' : '' }}">
                                                <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg></span>
                                                <span class="label">{{ $extItem['label'] }}</span>
                                            </a>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            </details>
                        </div>
                        @endif
                    @endforeach
                @endif
            </nav>
        </div>

        @auth
            <div class="sidebar-footer">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="logout-btn">
                        <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span>
                        <span class="label">Logout</span>
                    </button>
                </form>
            </div>
        @endauth
    </aside>

    <main id="content" class="content">
        <div class="content-inner">
        <div class="topbar">
            <div class="topbar-title">
                @php
                    $__bcRoutes = [
                        'Dashboard' => 'dashboard',
                        'Catalog' => 'products.index',
                        'Products' => 'products.index',
                        'Categories' => 'categories.index',
                        'Manufacturers' => 'manufacturers.index',
                        'Options' => 'options.index',
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
                        <a href="{{ route($__bcRoutes[$__bcPart]) }}" style="color:var(--text-muted); text-decoration:none;">{{ $__bcPart }}</a>
                        <span style="margin:0 4px; color:var(--text-muted);">/</span>
                    @elseif($__bcIdx < $__bcCount - 1)
                        <span>{{ $__bcPart }}</span>
                        <span style="margin:0 4px; color:var(--text-muted);">/</span>
                    @else
                        <span>{{ $__bcPart }}</span>
                    @endif
                @endforeach
            </div>
            @auth
            <div style="position:relative;" id="global-search-wrap">
                <input type="text" id="global-search" class="input" placeholder="Search... (press /)" autocomplete="off" style="width:280px; padding-left:36px; font-size:13px; height:36px;">
                <svg style="position:absolute; left:10px; top:50%; transform:translateY(-50%); width:16px; height:16px; color:var(--text-muted); pointer-events:none;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <div id="global-search-results" style="display:none; position:absolute; top:100%; right:0; margin-top:6px; width:420px; max-height:480px; overflow-y:auto; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md); box-shadow:var(--shadow-lg); z-index:9999;"></div>
            </div>
            @endauth
        </div>

        @if(session('status'))
            <div class="alert success">
                <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <span>{{ session('status') }}</span>
                <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert danger">
                <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span>{{ session('error') }}</span>
                <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>
            </div>
        @endif

        @if($errors->any())
            <div class="alert danger">
                <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <div>
                    <ul style="margin:0; padding-left:18px;">
                        @foreach($errors->all() as $e)
                            <li>{{ $e }}</li>
                        @endforeach
                    </ul>
                </div>
                <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>
            </div>
        @endif

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
            <div class="alert danger">
                <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span><strong>TikTok Shop:</strong> Access token expired. <a href="{{ route('tiktok.index') }}">Refresh token</a></span>
            </div>
        @endif

        @if($__lazadaExpired)
            <div class="alert danger">
                <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span><strong>Lazada:</strong> Access token expired. Order sync is paused. <a href="{{ route('lazada.index') }}">Refresh token</a></span>
            </div>
        @endif

        @if($__shopeeExpired)
            <div class="alert danger">
                <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span><strong>Shopee:</strong> Access token expired. Sync commands will fail. <a href="{{ route('shopee.index') }}">Refresh token</a></span>
            </div>
        @elseif($__shopeeExpiringSoon)
            <div class="alert warning">
                <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <span><strong>Shopee:</strong> Access token expires soon (in {{ $__shopeeExpiresInMin }} min). <a href="{{ route('shopee.index') }}">Refresh token</a></span>
            </div>
        @endif

        @if($__shopeePaused)
            <div class="alert danger">
                <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span><strong>Shopee:</strong> Order sync paused — {{ $__shopeePaused }}. <a href="{{ route('shopee.index') }}">Check settings</a></span>
            </div>
        @endif

        <div id="js-flash-container"></div>

        @yield('content')

        </div>{{-- .content-inner --}}
    </main>
</div>

<script>
(function () {
    var sidebar = document.getElementById('sidebar');
    var content = document.getElementById('content');
    var btn = document.getElementById('sidebarToggle');
    var fab = document.getElementById('sidebar-fab');
    var backdrop = document.getElementById('sidebar-backdrop');
    if (!sidebar || !content || !btn) return;

    var key = 'ngd_sidebar_collapsed';
    var mobileBreak = 768;

    function isMobile() { return window.innerWidth <= mobileBreak; }

    function applyState(isCollapsed) {
        sidebar.classList.toggle('collapsed', isCollapsed);
        content.classList.toggle('collapsed', isCollapsed);

        if (isMobile()) {
            fab.classList.toggle('show', isCollapsed);
            backdrop.style.display = isCollapsed ? 'none' : 'block';
            setTimeout(function(){ backdrop.classList.toggle('visible', !isCollapsed); }, 10);
            document.body.style.overflow = isCollapsed ? '' : 'hidden';
        } else {
            fab.classList.toggle('show', isCollapsed);
            backdrop.style.display = 'none';
            backdrop.classList.remove('visible');
            document.body.style.overflow = '';
        }

        if (!isMobile()) {
            localStorage.setItem(key, isCollapsed ? '1' : '0');
        }
    }

    function initLayout() {
        if (isMobile()) {
            applyState(true);
        } else {
            var saved = localStorage.getItem(key) === '1';
            applyState(saved);
        }
    }

    initLayout();

    btn.addEventListener('click', function () {
        applyState(!sidebar.classList.contains('collapsed'));
    });

    if (fab) {
        fab.addEventListener('click', function () {
            applyState(false);
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', function () {
            applyState(true);
        });
    }

    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(initLayout, 150);
    });

    // ── Smooth slide for nav <details> (accordion — one open at a time) ──
    var allNavDetails = Array.from(document.querySelectorAll('.nav-group details'));

    function closeDetails(details) {
        var wrap = details.querySelector('.nav-sub-wrap');
        if (!wrap || !details.open) return;
        var h = wrap.scrollHeight;
        wrap.style.height = h + 'px';
        wrap.offsetHeight;
        wrap.style.height = '0px';
        wrap.addEventListener('transitionend', function handler() {
            wrap.removeEventListener('transitionend', handler);
            details.open = false;
            wrap.style.height = '0px';
        });
    }

    allNavDetails.forEach(function (details) {
        var wrap = details.querySelector('.nav-sub-wrap');
        if (!wrap) return;

        // Set initial state
        if (details.open) {
            wrap.style.height = 'auto';
        } else {
            wrap.style.height = '0px';
        }

        details.querySelector('summary').addEventListener('click', function (e) {
            e.preventDefault();

            if (details.open) {
                // ── Closing ──
                closeDetails(details);
            } else {
                // ── Close all others first ──
                allNavDetails.forEach(function (other) {
                    if (other !== details) closeDetails(other);
                });

                // ── Opening ──
                details.open = true;
                var h = wrap.scrollHeight;
                wrap.style.height = '0px';
                wrap.offsetHeight;
                wrap.style.height = h + 'px';

                wrap.addEventListener('transitionend', function handler() {
                    wrap.removeEventListener('transitionend', handler);
                    wrap.style.height = 'auto';
                });
            }
        });
    });

    // ── Toast notifications (move inline alerts to fixed toast container) ──
    var toastContainer = document.createElement('div');
    toastContainer.className = 'toast-container';
    document.body.appendChild(toastContainer);

    document.querySelectorAll('.content .alert.success, .content .alert.warning').forEach(function(el) {
        // Move to toast container
        toastContainer.appendChild(el);
        el.style.marginBottom = '0';

        // Auto-dismiss after 5s
        setTimeout(function() {
            el.classList.add('toast-out');
            setTimeout(function(){ el.remove(); }, 350);
        }, 5000);
    });

    // ── Loading state on form submit buttons ──
    // Track which button was clicked so we only spinner that one
    var lastClickedSubmit = null;
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('button[type="submit"], button:not([type])');
        if (btn) lastClickedSubmit = btn;
    });

    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            // Delay loading state so other handlers can preventDefault first
            var btn = lastClickedSubmit;
            if (!btn || !form.contains(btn)) {
                var btns = form.querySelectorAll('button[type="submit"], button:not([type])');
                btn = btns.length === 1 ? btns[0] : null;
            }
            lastClickedSubmit = null;
            if (!btn) return;
            setTimeout(function() {
                if (e.defaultPrevented) return;
                btn.classList.add('is-loading');
                btn.disabled = true;
                setTimeout(function() {
                    btn.classList.remove('is-loading');
                    btn.disabled = false;
                }, 300000);
            }, 0);
        });
    });

    // ── Reset loading state on browser back (bfcache restore) ──
    window.addEventListener('pageshow', function(e) {
        if (e.persisted) {
            document.querySelectorAll('.is-loading').forEach(function(btn) {
                btn.classList.remove('is-loading');
                btn.disabled = false;
            });
        }
    });

    // ── Swipe-to-open sidebar on mobile ──
    (function () {
        var touchStartX = 0;
        var touchStartY = 0;
        var edgeZone = 30; // px from left edge
        var threshold = 60; // min swipe distance

        document.addEventListener('touchstart', function(e) {
            var t = e.touches[0];
            touchStartX = t.clientX;
            touchStartY = t.clientY;
        }, { passive: true });

        document.addEventListener('touchend', function(e) {
            var t = e.changedTouches[0];
            var dx = t.clientX - touchStartX;
            var dy = Math.abs(t.clientY - touchStartY);
            if (dy > Math.abs(dx)) return; // vertical scroll, ignore

            // Swipe right from left edge → open sidebar
            if (touchStartX < edgeZone && dx > threshold && sidebar.classList.contains('collapsed')) {
                applyState(false);
            }
            // Swipe left anywhere on sidebar → close it
            if (dx < -threshold && !sidebar.classList.contains('collapsed') && isMobile()) {
                applyState(true);
            }
        }, { passive: true });
    })();
})();
</script>

{{-- Global JS flash utility – replaces alert() across the app --}}
<script>
window.showFlashError = function(message) {
    var container = document.getElementById('js-flash-container');
    if (!container) return;
    var existing = container.querySelector('.alert');
    if (existing) existing.remove();
    var div = document.createElement('div');
    div.className = 'alert danger';
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('style', 'width:18px;height:18px;flex-shrink:0;margin-top:1px;');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    var c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    c.setAttribute('cx','12'); c.setAttribute('cy','12'); c.setAttribute('r','10');
    var l1 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    l1.setAttribute('x1','15'); l1.setAttribute('y1','9'); l1.setAttribute('x2','9'); l1.setAttribute('y2','15');
    var l2 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    l2.setAttribute('x1','9'); l2.setAttribute('y1','9'); l2.setAttribute('x2','15'); l2.setAttribute('y2','15');
    svg.appendChild(c); svg.appendChild(l1); svg.appendChild(l2);
    div.appendChild(svg);
    var span = document.createElement('span');
    span.textContent = message;
    div.appendChild(span);
    var btn = document.createElement('button');
    btn.className = 'alert-close';
    btn.setAttribute('aria-label', 'Close');
    btn.textContent = '\u00d7';
    btn.addEventListener('click', function() { div.remove(); });
    div.appendChild(btn);
    container.appendChild(div);
    div.scrollIntoView({ behavior: 'smooth', block: 'center' });
};
window.showFlashSuccess = function(message) {
    var container = document.getElementById('js-flash-container');
    if (!container) return;
    var existing = container.querySelector('.alert');
    if (existing) existing.remove();
    var div = document.createElement('div');
    div.className = 'alert success';
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('style', 'width:18px;height:18px;flex-shrink:0;margin-top:1px;');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    p.setAttribute('d', 'M22 11.08V12a10 10 0 1 1-5.93-9.14');
    var pl = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
    pl.setAttribute('points', '22 4 12 14.01 9 11.01');
    svg.appendChild(p); svg.appendChild(pl);
    div.appendChild(svg);
    var span = document.createElement('span');
    span.textContent = message;
    div.appendChild(span);
    var btn = document.createElement('button');
    btn.className = 'alert-close';
    btn.setAttribute('aria-label', 'Close');
    btn.textContent = '\u00d7';
    btn.addEventListener('click', function() { div.remove(); });
    div.appendChild(btn);
    container.appendChild(div);
    div.scrollIntoView({ behavior: 'smooth', block: 'center' });
};
</script>

@stack('scripts')
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
      list.innerHTML = '';
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

    function clearList(){ list.innerHTML = ''; list.style.display='none'; }
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
      chip.innerHTML = name + '<input type="hidden" name="' + opts.hiddenName + '" value="' + id + '">' +
        '<button type="button" class="tag-remove">&times;</button>';
      chip.querySelector('.tag-remove').addEventListener('click', function(){ chip.remove(); });
      tagContainer.appendChild(chip);
    }

    function render(items, query){
      list.innerHTML = '';
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
        // Strip old manufacturer prefix from name field if present
        var current = (nameField.value || '').trim();
        if (current.toLowerCase().indexOf(name.toLowerCase()) === 0) {
          nameField.value = current.substring(name.length).replace(/^\s+/, '');
        }
      } else {
        prefix.style.display = 'none';
        prefix.textContent = '';
      }
    }

    // On form submit, prepend manufacturer prefix back into the name value
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

    // Initialize prefix on page load (for edit forms with existing manufacturer)
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

    // Require a valid manufacturer selection on product forms
    var mfgHidden = document.getElementById('manufacturer_id');
    var mfgInput = document.getElementById('manufacturer_search');
    if(mfgHidden && mfgInput && mfgInput.closest('form')){
      mfgInput.closest('form').addEventListener('submit', function(e){
        var val = parseInt(mfgHidden.value, 10);
        if(!val || val <= 0){
          e.preventDefault();
          mfgInput.classList.add('input-error');
          mfgInput.focus();
          mfgInput.scrollIntoView({behavior:'smooth', block:'center'});
          // Show inline error if not already shown
          var wrap = mfgInput.closest('.typeahead');
          if(wrap && !wrap.querySelector('.field-error')){
            var err = document.createElement('div');
            err.className = 'field-error';
            err.textContent = 'Please select a manufacturer from the list.';
            wrap.appendChild(err);
          }
        }
      });
      // Clear error on valid selection
      mfgInput.addEventListener('input', function(){
        mfgInput.classList.remove('input-error');
        var wrap = mfgInput.closest('.typeahead');
        if(wrap){
          var err = wrap.querySelector('.field-error');
          if(err) err.remove();
        }
      });
    }
  });
})();
</script>
<script>
(function() {
    var input = document.getElementById('global-search');
    var wrap = document.getElementById('global-search-results');
    if (!input || !wrap) return;

    var timer = null;

    // Focus on '/' key
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
        if (wrap.innerHTML.trim() !== '' && input.value.trim().length >= 2) {
            wrap.style.display = 'block';
        }
    });

    document.addEventListener('click', function(e) {
        if (!document.getElementById('global-search-wrap').contains(e.target)) {
            wrap.style.display = 'none';
        }
    });

    function doSearch(q) {
        fetch('/search?q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var results = data.results || [];
            if (results.length === 0) {
                wrap.innerHTML = '<div style="padding:16px; text-align:center; color:var(--text-muted); font-size:13px;">No results found</div>';
                wrap.style.display = 'block';
                return;
            }

            var html = '';
            var lastCat = '';
            results.forEach(function(r) {
                if (r.category !== lastCat) {
                    lastCat = r.category;
                    html += '<div style="padding:8px 14px 4px; font-size:11px; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:.04em;">' + r.category + '</div>';
                }
                html += '<a href="' + r.url + '" style="display:block; padding:8px 14px; text-decoration:none; color:var(--text-primary); transition:background .15s;"'
                    + ' onmouseenter="this.style.background=\'var(--surface-hover)\'" onmouseleave="this.style.background=\'transparent\'">'
                    + '<div style="font-size:13px; font-weight:500;">' + escHtml(r.label) + '</div>'
                    + (r.sub ? '<div style="font-size:12px; color:var(--text-secondary);">' + escHtml(r.sub) + '</div>' : '')
                    + '</a>';
            });

            wrap.innerHTML = html;
            wrap.style.display = 'block';
        })
        .catch(function() {
            wrap.innerHTML = '<div style="padding:16px; text-align:center; color:var(--danger); font-size:13px;">Search failed</div>';
            wrap.style.display = 'block';
        });
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
})();
</script>

{{-- Global confirm modal --}}
<div class="modal-backdrop" id="confirm-modal">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <h3 id="confirm-modal-title">Confirm</h3>
            <button class="modal-close" type="button" id="confirm-modal-close">&times;</button>
        </div>
        <p id="confirm-modal-message" style="margin:0 0 20px; line-height:1.5;"></p>
        <div class="d-flex justify-end gap-8">
            <button class="btn secondary" type="button" id="confirm-modal-cancel">Cancel</button>
            <button class="btn danger" type="button" id="confirm-modal-ok">Confirm</button>
        </div>
    </div>
</div>
<script>
(function() {
    var backdrop = document.getElementById('confirm-modal');
    var msgEl = document.getElementById('confirm-modal-message');
    var okBtn = document.getElementById('confirm-modal-ok');
    var cancelBtn = document.getElementById('confirm-modal-cancel');
    var closeBtn = document.getElementById('confirm-modal-close');
    var resolver = null;

    function close(result) {
        backdrop.classList.remove('active');
        if (resolver) { resolver(result); resolver = null; }
    }

    cancelBtn.addEventListener('click', function() { close(false); });
    closeBtn.addEventListener('click', function() { close(false); });
    backdrop.addEventListener('click', function(e) {
        if (e.target === backdrop) close(false);
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && backdrop.classList.contains('active')) close(false);
    });

    window.confirmModal = function(message) {
        msgEl.textContent = message;
        backdrop.classList.add('active');
        okBtn.focus();
        return new Promise(function(resolve) { resolver = resolve; });
    };

    okBtn.addEventListener('click', function() { close(true); });

    // Global interceptor: any element with data-confirm gets the modal
    document.addEventListener('submit', function(e) {
        var form = e.target;
        var msg = form.getAttribute('data-confirm');
        if (!msg) return;
        if (form._confirmed) { form._confirmed = false; return; }
        e.preventDefault();
        confirmModal(msg).then(function(ok) {
            if (ok) { form._confirmed = true; form.requestSubmit ? form.requestSubmit() : form.submit(); }
        });
    }, true);

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-confirm]');
        if (!btn || btn.tagName === 'FORM') return;
        var msg = btn.getAttribute('data-confirm');
        var submitId = btn.getAttribute('data-confirm-submit');
        e.preventDefault();
        e.stopPropagation();
        confirmModal(msg).then(function(ok) {
            if (!ok) return;
            if (submitId) {
                var f = document.getElementById(submitId);
                if (f) { f._confirmed = true; f.requestSubmit ? f.requestSubmit() : f.submit(); }
            }
        });
    }, true);
})();
</script>
</body>
</html>
