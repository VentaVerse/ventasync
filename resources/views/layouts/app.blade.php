<!DOCTYPE html>
<html>
<head>
    <title>{{ optional(\App\Models\Setting::query()->first())->company_name ?? 'VentaSync' }}</title>
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
            $canMarketplace = $u && $u->hasPermission('manage_marketplace_api');
            $canShopee = $u && $u->hasPermission('manage_shopee');
            $canLazada = $u && $u->hasPermission('manage_lazada');
            $canShopeeOrders = $u && $u->hasPermission('manage_shopee_orders');
            $canLazadaOrders = $u && $u->hasPermission('manage_lazada_orders');
            $canTikTok = $u && $u->hasPermission('manage_tiktok');
            $canTikTokOrders = $u && $u->hasPermission('manage_tiktok_orders');
            $canOpencart = $u && $u->hasPermission('manage_opencart');
            $canOpencartOrders = $u && $u->hasPermission('manage_opencart_orders');
            $canVenta = $u && $u->hasPermission('manage_venta');
            $canVentaOrders = $u && $u->hasPermission('manage_venta_orders');
            $canSettingsParent = $u && $u->hasPermission('manage_settings');
            $canUsers = $u && $u->hasPermission('manage_users');
            $canUserGroups = $u && $u->hasPermission('manage_user_groups');
            $canWebsiteSettings = $u && $u->hasPermission('manage_website_settings');
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
            @php
                // Contextual sidebar: replace the main nav with a section-scoped one
                // when the operator is inside Integrations (any marketplace extension
                // page) or inside the unified Orders page (marketplace order tabs).
                // Visibility rules match the main sidebar — same permission gates,
                // same enable/license gates via IntegrationRegistry.
                $__route = request()->route();
                $__rn = $__route ? ((string)($__route->getName() ?? '')) : '';
                $__navContext = null;
                if ($__rn === 'integrations.orders' || preg_match('/^ext\.(lazada|shopee|tiktok|venta|opencart|pedallion)\.orders\b/', $__rn)) {
                    $__navContext = 'orders';
                } elseif (
                    $__rn === 'integrations.index'
                    || $__rn === 'integrations.module'
                    || preg_match('/^ext\.(lazada|shopee|tiktok|venta|opencart|pedallion)\./', $__rn)
                ) {
                    $__navContext = 'integrations';
                }

                // Hoisted from later in the file so both the default + contextual
                // navs share one lookup.
                $__orderTabs = app(\App\Integrations\IntegrationRegistry::class)->visibleOrderTabs($u);
                $__intCards = app(\App\Integrations\IntegrationRegistry::class)->visibleCards($u);
            @endphp

            {{-- Sliding sidebar track: layer 1 (main nav) and layer 2 (contextual nav)
                 are both always rendered side-by-side in a flex track. The
                 `.sidebar-track--layer2` class shifts the track left by 50%
                 (= 100% of one pane) so layer 2 takes over. CSS handles the
                 sweep; JS intercepts navigation to delay the page jump until
                 the slide finishes. Both layers move together — neither
                 simply disappears. --}}
            <div class="sidebar-track {{ $__navContext ? 'sidebar-track--layer2' : '' }}" data-sidebar-track>
            @php
                // Layer-2 content rendered on every page. On a layer-1 page we
                // default to the integrations variant (the more common forward
                // destination); when the operator clicks Orders from layer 1
                // we re-render after navigation.
                $__layer2Variant = $__navContext === 'orders' ? 'orders' : 'integrations';
            @endphp
            <nav class="sidebar-nav sidebar-nav--layer1">
                <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span>
                    <span class="label">Dashboard</span>
                </a>

                {{-- Unified Marketplace Orders — replaces the per-marketplace
                     "Lazada Orders / Shopee Orders / ..." sidebar items. Tab
                     visibility (and the badge total) is driven by IntegrationRegistry,
                     gated by license + enabled + per-tab permission. --}}
                @php
                    $__orderBadge = 0;
                    foreach ($__orderTabs as $__t) {
                        $__c = $__t->unprocessedCount();
                        if ($__c !== null) { $__orderBadge += $__c; }
                    }
                @endphp
                @if(!empty($__orderTabs))
                <a href="{{ route('integrations.orders') }}" data-layer2-enter="orders" class="{{ request()->routeIs('integrations.orders') || request()->routeIs('ext.shopee.orders.*') || request()->routeIs('ext.lazada.orders.*') || request()->routeIs('ext.tiktok.orders.*') || request()->routeIs('ext.opencart.orders.*') || request()->routeIs('ext.venta.orders.*') || request()->routeIs('ext.pedallion.orders.*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>
                    <span class="label">Orders</span>
                    @if($__orderBadge > 0)
                        <span class="nav-badge">{{ $__orderBadge }}</span>
                    @endif
                </a>
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
                                <a href="{{ route('orders.index') }}" class="{{ request()->routeIs('orders.*') && !request()->routeIs('orders.payments_report') ? 'active' : '' }}">
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


                {{-- Integrations hub — replaces the per-marketplace nav groups
                     (Marketplace API, OpenCart, Venta Store). Visibility of cards
                     and order tabs is driven by IntegrationRegistry, gated by
                     license + enabled + permission. --}}
                @if(!empty($__intCards))
                {{-- Active state spans the full Integrations layer-2 scope so the
                     operator can see which layer-1 entry "owns" their current
                     page after they hit Back. Mirrors the Orders link above
                     (line 148) but excludes the ext.*.orders.* routes — those
                     belong to the Orders layer-1 entry. --}}
                <a href="{{ route('integrations.index') }}" data-layer2-enter="integrations" class="{{ (request()->routeIs('integrations.index') || request()->routeIs('integrations.module') || (preg_match('/^ext\\.(lazada|shopee|tiktok|venta|opencart|pedallion)\\./', $__rn) && !preg_match('/^ext\\.(lazada|shopee|tiktok|venta|opencart|pedallion)\\.orders\\./', $__rn))) ? 'active' : '' }}">
                    <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg></span>
                    <span class="label">Integrations</span>
                </a>
                @endif

                {{-- Extension Nav Groups --}}
                @if(!empty($extensionNavGroups ?? []))
                    @foreach($extensionNavGroups as $extNav)
                        @php
                            $extPerm = $extNav['permission'] ?? '';
                            $canViewExt = $extPerm === '' || (auth()->user() && auth()->user()->hasPermission($extPerm));
                            // Also show group if user has permission for any child item
                            if (!$canViewExt && auth()->user()) {
                                foreach ($extNav['items'] ?? [] as $_item) {
                                    if (!empty($_item['permission']) && auth()->user()->hasPermission($_item['permission'])) {
                                        $canViewExt = true;
                                        break;
                                    }
                                }
                            }
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
                                            @php
                                                $itemPerm = $extItem['permission'] ?? '';
                                                $canViewItem = $itemPerm === '' || (auth()->user() && auth()->user()->hasPermission($itemPerm));
                                            @endphp
                                            @if(\Route::has($extItem['route']) && $canViewItem)
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

                {{-- Settings — always last --}}
                @if($canSettingsParent)
                <div class="nav-group">
                    <details {{ (request()->routeIs('users.*') || request()->routeIs('user_groups.*') || request()->routeIs('settings.*') || request()->routeIs('currencies.*') || request()->routeIs('extensions.*') || request()->routeIs('error_log.*')) ? 'open' : '' }}>
                        <summary class="{{ (request()->routeIs('users.*') || request()->routeIs('user_groups.*') || request()->routeIs('settings.*') || request()->routeIs('currencies.*') || request()->routeIs('extensions.*') || request()->routeIs('error_log.*')) ? 'active' : '' }}">
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
                                @if($canWebsiteSettings)
                                <a href="{{ route('extensions.index') }}" class="{{ request()->routeIs('extensions.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></span>
                                    <span class="label">Extensions</span>
                                </a>
                                <a href="{{ route('error_log.index') }}" class="{{ request()->routeIs('error_log.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span>
                                    <span class="label">Error Log</span>
                                </a>
                                @endif
                            </div>
                        </div>
                    </details>
                </div>
                @endif
            </nav>

            {{-- Layer 2: contextual sub-sidebar. Rendered on every page so it
                 can slide IN/OUT alongside layer 1 — neither layer disappears
                 abruptly. The Blade $__layer2Variant chooses orders vs
                 integrations content based on $__navContext (default
                 integrations on layer-1 pages). --}}
            <nav class="sidebar-nav sidebar-nav--layer2" data-layer2 data-variant="{{ $__layer2Variant ?? 'integrations' }}">
                <div class="layer2-variant layer2-variant--orders">
                    <div class="sidebar-layer2-title">
                        <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="9" x2="15" y2="9"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg></span>
                        <span class="label">Orders</span>
                    </div>
                    <a href="{{ route('integrations.orders') }}" class="{{ $__rn === 'integrations.orders' ? 'active' : '' }}">
                        <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="9" x2="15" y2="9"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg></span>
                        <span class="label">Summary</span>
                    </a>
                    @foreach($__orderTabs as $__tab)
                        @php
                            $__tabActive = $__rn === $__tab->routeName;
                            $__tabCount = $__tab->unprocessedCount();
                        @endphp
                        <a href="{{ route($__tab->routeName, $__tab->routeParams ?? []) }}" class="{{ $__tabActive ? 'active' : '' }}">
                            <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                            <span class="label">{{ $__tab->label }}</span>
                            @if($__tabCount !== null && $__tabCount > 0)
                                <span class="nav-badge">{{ $__tabCount }}</span>
                            @endif
                        </a>
                    @endforeach
                    {{-- Back sits at the BOTTOM of the list (inline, not a
                         sticky footer) so it appears right after the last
                         menu item the operator scrolled past. --}}
                    <a href="{{ route('dashboard') }}" data-layer2-back class="layer2-back-bottom">
                        <span class="nav-icon"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></span>
                        <span class="label">Back</span>
                    </a>
                </div>{{-- /.layer2-variant--orders --}}

                <div class="layer2-variant layer2-variant--integrations">
                    <div class="sidebar-layer2-title">
                        <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg></span>
                        <span class="label">Integrations</span>
                    </div>
                    {{-- The Integrations Hub (summary card grid). Lives here
                         in layer 2 because the top-level "Integrations" link
                         in layer 1 is now a pure nav-expander (no navigate). --}}
                    <a href="{{ route('integrations.index') }}" class="{{ $__rn === 'integrations.index' ? 'active' : '' }}">
                        <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg></span>
                        <span class="label">Integrations Hub</span>
                    </a>
                    @foreach($__intCards as $__card)
                        @php
                            $__cardId = (string) $__card->id;
                            $__cardPrefix = 'ext.' . (str_contains($__cardId, ':') ? strstr($__cardId, ':', true) : $__cardId) . '.';
                            $__cardActive = str_starts_with($__rn, $__cardPrefix);
                            $__cardMenu = collect($__card->menu ?? [])
                                ->filter(fn($m) => !$m->permission || ($u && $u->hasPermission($m->permission)))
                                ->values();
                            $__cardIsMulti = !empty($__card->stores) || !empty($__card->addStore);
                        @endphp
                        <div class="nav-group">
                            <details {{ $__cardActive ? 'open' : '' }}>
                                <summary class="{{ $__cardActive ? 'active' : '' }}">
                                    <span class="label">{{ $__card->name }}</span>
                                    <span class="chevron"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
                                </summary>
                                <div class="nav-sub-wrap">
                                    <div class="nav-sub">
                                        @foreach($__cardMenu as $__item)
                                            <a href="{{ route($__item->routeName, $__item->routeParams ?? []) }}" class="{{ $__rn === $__item->routeName ? 'active' : '' }}">
                                                <span class="label">{{ $__item->label }}</span>
                                            </a>
                                        @endforeach
                                        @if($__cardIsMulti)
                                            {{-- Manage Stores sits at the TOP of the card's
                                                 sub-list, before any per-store divider,
                                                 so it reads as a card-level action and
                                                 isn't visually tied to the last store. --}}
                                            <a href="{{ route('integrations.module', ['module' => $__cardId]) }}" class="nav-sub-action {{ $__rn === 'integrations.module' && request()->route('module') === $__cardId ? 'active' : '' }}">
                                                <span class="nav-sub-action-icon" aria-hidden="true">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M3 9l3-6h12l3 6"/>
                                                        <path d="M3 9v11a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V9"/>
                                                        <path d="M9 22V12h6v10"/>
                                                    </svg>
                                                </span>
                                                <span class="label">Manage Stores</span>
                                            </a>
                                            @php
                                                // The current store id (from the URL) is needed so per-store
                                                // menu links highlight only the active store. Multi-store
                                                // extensions reuse the same route name across stores —
                                                // matching on name alone would light up every store's row.
                                                $__currentStoreId = (string) (request()->route('store') ?? '');
                                            @endphp
                                            @foreach($__card->stores ?? [] as $__store)
                                                @php
                                                    $__storeMenu = collect($__store->menu ?? [])
                                                        ->filter(fn($m) => !$m->permission || ($u && $u->hasPermission($m->permission)))
                                                        ->values();
                                                @endphp
                                                @if($__storeMenu->isNotEmpty())
                                                    <div class="nav-sub-divider">{{ $__store->label }}</div>
                                                    @foreach($__storeMenu as $__item)
                                                        @php
                                                            $__itemStoreId = (string) ($__item->routeParams['store'] ?? '');
                                                            $__itemActive = $__rn === $__item->routeName
                                                                && ($__itemStoreId === '' || $__itemStoreId === $__currentStoreId);
                                                        @endphp
                                                        <a href="{{ route($__item->routeName, $__item->routeParams ?? []) }}" class="{{ $__itemActive ? 'active' : '' }}">
                                                            <span class="label">{{ $__item->label }}</span>
                                                        </a>
                                                    @endforeach
                                                @endif
                                            @endforeach
                                        @endif
                                    </div>
                                </div>
                            </details>
                        </div>
                    @endforeach
                    {{-- Back at the BOTTOM of the marketplace list (inline,
                         not a sticky footer) so it appears immediately after
                         the last card the operator scrolled past. --}}
                    <a href="{{ route('dashboard') }}" data-layer2-back class="layer2-back-bottom">
                        <span class="nav-icon"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></span>
                        <span class="label">Back</span>
                    </a>
                </div>{{-- /.layer2-variant--integrations --}}
            </nav>
            </div> {{-- /.sidebar-track --}}
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
                        'Purchasing' => 'ext.purchasing.purchase_orders.index',
                        'Purchase Orders' => 'ext.purchasing.purchase_orders.index',
                        'Vendors' => 'ext.purchasing.vendors.index',
                        'Reorder Suggestions' => 'ext.purchasing.reorder.index',
                        'Settings' => 'settings.edit',
                        'Users' => 'users.index',
                        'User Groups' => 'user_groups.index',
                        'Currencies' => 'currencies.index',
                        'Integrations' => 'integrations.index',
                        'Shopee' => 'ext.shopee.index',
                        'Lazada' => 'ext.lazada.index',
                        'TikTok Shop' => 'ext.tiktok.index',
                        'Pedallion' => 'ext.pedallion.index',
                        'OpenCart' => 'ext.opencart.index',
                        'Venta' => 'ext.venta.index',
                        'Reports' => 'ext.reports.orders',
                    ];
                    $__bcRaw = trim($__env->yieldContent('breadcrumb', 'Dashboard'));
                    $__bcParts = array_map('trim', explode('/', $__bcRaw));
                    $__bcCount = count($__bcParts);

                    // Context-aware segment resolver: when the operator is
                    // inside an extension (route name `ext.{marketplace}...`),
                    // resolve generic labels like "Product Groups" / "Orders"
                    // / "Categories" against the active marketplace so each
                    // crumb links back to the correct module subsection
                    // instead of being unclickable.
                    $__bcRn = (string)(request()->route()?->getName() ?? '');
                    $__bcExt = null;
                    if (preg_match('/^ext\.([a-z0-9_-]+)\./', $__bcRn, $__bcM)) {
                        $__bcExt = $__bcM[1];
                    }
                    $__bcExtSubMap = [
                        'Product Groups' => 'product-groups.index',
                        'Orders'         => 'orders.index',
                        'Categories'     => 'categories.index',
                        'Brands'         => 'brands.index',
                        'Logistics'      => 'logistics.index',
                        'Products'       => 'products.index',
                    ];
                    $__bcResolve = function (string $label) use ($__bcRoutes, $__bcExt, $__bcExtSubMap): ?string {
                        if (isset($__bcRoutes[$label])) {
                            return \Route::has($__bcRoutes[$label]) ? $__bcRoutes[$label] : null;
                        }
                        if ($__bcExt && isset($__bcExtSubMap[$label])) {
                            $candidate = "ext.{$__bcExt}.{$__bcExtSubMap[$label]}";
                            return \Route::has($candidate) ? $candidate : null;
                        }
                        return null;
                    };
                @endphp
                @foreach($__bcParts as $__bcIdx => $__bcPart)
                    @php
                        $__bcTarget = $__bcIdx < $__bcCount - 1 ? $__bcResolve($__bcPart) : null;
                    @endphp
                    @if($__bcTarget)
                        <a href="{{ route($__bcTarget) }}">{{ $__bcPart }}</a>
                        <span class="bc-sep">/</span>
                    @elseif($__bcIdx < $__bcCount - 1)
                        <span>{{ $__bcPart }}</span>
                        <span class="bc-sep">/</span>
                    @else
                        <span class="bc-current">{{ $__bcPart }}</span>
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
            // Layout banners contributed by every enabled+licensed integration.
            // Token expiry warnings, sync pauses, etc. each marketplace knows
            // its own state and contributes via LayoutBannerContributor.
            $__layoutBanners = [];
            try {
                foreach (app(\App\Integrations\IntegrationRegistry::class)->layoutBannerContributors() as $__c) {
                    foreach ($__c->layoutBanners() as $__b) { $__layoutBanners[] = $__b; }
                }
            } catch (\Throwable $e) {}
        @endphp

        @foreach($__layoutBanners as $__banner)
            <div class="alert {{ ($__banner['severity'] ?? 'info') === 'error' ? 'danger' : (($__banner['severity'] ?? 'info') === 'warning' ? 'warning' : 'info') }}">
                <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span>
                    {{ $__banner['label'] ?? '' }}
                    @if(!empty($__banner['href']))
                        <a href="{{ $__banner['href'] }}">Open settings</a>
                    @endif
                </span>
            </div>
        @endforeach

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
    var lastWidth = window.innerWidth;
    window.addEventListener('resize', function () {
        // Only react to width changes — mobile browsers fire resize when
        // the address bar hides/shows during scroll (height-only change)
        if (window.innerWidth === lastWidth) return;
        lastWidth = window.innerWidth;
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

/* Thumb magnifier — position preview near cursor */
document.addEventListener('mousemove', function(e) {
    var mag = e.target.closest('.thumb-magnify');
    if (!mag) return;
    var preview = mag.querySelector('.thumb-preview');
    if (!preview) return;
    var x = e.clientX + 16;
    var y = e.clientY - 120;
    if (x + 250 > window.innerWidth) x = e.clientX - 256;
    if (y < 8) y = 8;
    if (y + 248 > window.innerHeight) y = window.innerHeight - 248;
    preview.style.left = x + 'px';
    preview.style.top = y + 'px';
});
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
          if(typeof opts.onEmpty === 'function'){
            var action = opts.onEmpty(query, {
              select: function(id, name){
                input.value = name;
                if(hidden) hidden.value = String(id);
                clearList();
                input.classList.remove('input-error');
                if(opts.onSelect) opts.onSelect({id: id, name: name});
              },
              close: clearList,
            });
            if(action && action.nodeType === 1){
              list.appendChild(action);
            }
          }
          showList();
        } else { hideList(); }
        return;
      }
      filtered.forEach(function(it){
        var displayLabel = it.display_name || it.name;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = displayLabel;
        btn.addEventListener('mousedown', function(e){
          e.preventDefault();
          if(isMulti){
            addTag(it.id, displayLabel);
            input.value = '';
            clearList();
          } else {
            input.value = displayLabel;
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
          item.name = r.display_name ?? r.name ?? r.path;
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

    function doSearch(){
      var q = (input.value || '').trim();
      if(q.length < 1 && !opts.showAllOnFocus){ hideList(); return; }
      if(q === last) return;
      last = q;
      clearTimeout(timer);
      timer = setTimeout(async function(){
        var items = await fetchItems(q);
        render(items, q);
      }, 120);
    }
    input.addEventListener('keyup', doSearch);
    input.addEventListener('input', doSearch);

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
      },
      onEmpty: function(query, api){
        var row = document.createElement('div');
        row.style.cssText = 'padding:8px 12px;border-top:1px solid var(--border-light,#e2e8f0);';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn secondary';
        btn.style.cssText = 'font-size:12px;padding:6px 10px;width:100%;justify-content:flex-start;text-align:left;';
        btn.textContent = 'Add manufacturer: "' + query + '"';
        btn.addEventListener('mousedown', async function(e){
          e.preventDefault();
          btn.disabled = true;
          btn.textContent = 'Creating…';
          try{
            var res = await fetch(@json(route('api.catalog.manufacturers.store')), {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': @json(csrf_token()),
                'X-Requested-With': 'XMLHttpRequest',
              },
              credentials: 'same-origin',
              body: JSON.stringify({name: query}),
            });
            var data = await res.json().catch(function(){ return {}; });
            if(!res.ok || !data.ok){
              btn.disabled = false;
              btn.textContent = (data && data.message) ? data.message : 'Failed to create — click to retry';
              return;
            }
            api.select(data.id, data.name);
          } catch(err){
            btn.disabled = false;
            btn.textContent = 'Network error — click to retry';
          }
        });

        row.appendChild(btn);
        return row;
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
<script>
// Sidebar layer-1 <-> layer-2 sliding track. Both layers always live in
// the DOM inside [data-sidebar-track]; the .sidebar-track--layer2 class
// shifts the track left so layer 2 takes over.
//
// Behaviour rules:
//   - "Integrations" link in layer 1 and "Back" button in layer 2 are
//     pure nav-expanders — they slide the track and do NOT navigate.
//     The current page content stays put; the operator picks a real
//     destination from the revealed layer.
//   - Any other [data-layer2-enter] (e.g. "Orders") still navigates
//     after the slide so it remains a real destination link.
//   - The user-controlled layer state is remembered in sessionStorage
//     so a page reload preserves the operator's choice rather than
//     snapping back to the URL-derived default.
(function() {
    var SLIDE_MS = 280;
    var STORAGE_KEY = 'sidebar.layer';
    var VARIANT_KEY = 'sidebar.layer2.variant';
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // On page load, restore the operator's last-chosen layer + variant.
    // Server already picks the matching layer for marketplace pages;
    // we only override when the operator manually expanded/collapsed
    // or switched between Orders/Integrations variants.
    try {
        var stored = sessionStorage.getItem(STORAGE_KEY);
        var storedVariant = sessionStorage.getItem(VARIANT_KEY);
        var track0 = document.querySelector('[data-sidebar-track]');
        var layer2 = document.querySelector('.sidebar-nav--layer2');
        if (track0 && stored) {
            var isCurrentlyLayer2 = track0.classList.contains('sidebar-track--layer2');
            if (stored === 'layer2' && !isCurrentlyLayer2) {
                track0.classList.add('sidebar-track--layer2');
            } else if (stored === 'layer1' && isCurrentlyLayer2) {
                track0.classList.remove('sidebar-track--layer2');
            }
        }
        if (layer2 && storedVariant && (storedVariant === 'orders' || storedVariant === 'integrations')) {
            layer2.setAttribute('data-variant', storedVariant);
        }
    } catch (e) { /* sessionStorage may be unavailable */ }

    function rememberLayer(name) {
        try { sessionStorage.setItem(STORAGE_KEY, name); } catch (e) {}
    }
    function rememberVariant(name) {
        try { sessionStorage.setItem(VARIANT_KEY, name); } catch (e) {}
    }

    document.addEventListener('click', function(e) {
        var track = document.querySelector('[data-sidebar-track]');
        if (!track) return;

        // Forward: layer 1 -> layer 2. Pure nav-expander — no navigation.
        // Both "Integrations" and "Orders" links in layer 1 just slide the
        // track open; the operator picks the real destination (a marketplace
        // sub-link, a per-tab Orders link, or the explicit Summary/Hub link)
        // from the revealed layer-2 nav.
        var enter = e.target.closest && e.target.closest('[data-layer2-enter]');
        if (enter) {
            if (reduce) return;
            e.preventDefault();
            // Pick the matching variant inside layer 2 BEFORE the slide
            // starts so it animates with the correct content visible.
            var kind = enter.getAttribute('data-layer2-enter');
            var layer2 = document.querySelector('.sidebar-nav--layer2');
            if (layer2 && (kind === 'orders' || kind === 'integrations')) {
                layer2.setAttribute('data-variant', kind);
                rememberVariant(kind);
            }
            if (!track.classList.contains('sidebar-track--layer2')) {
                track.classList.add('sidebar-track--layer2');
                rememberLayer('layer2');
            }
            return;
        }

        // Back: layer 2 -> layer 1. Pure collapser — no navigation,
        // the operator's current page stays loaded and they can pick
        // a fresh destination from the now-visible main nav.
        var back = e.target.closest && e.target.closest('[data-layer2-back]');
        if (back) {
            if (reduce) return;
            e.preventDefault();
            track.classList.remove('sidebar-track--layer2');
            rememberLayer('layer1');
            return;
        }
    });
})();
</script>
</body>
</html>
