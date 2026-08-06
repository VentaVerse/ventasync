<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Xenon')</title>
    @include('partials.theme-boot')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="x-app">
<div class="x-shell">
    <aside class="x-sidebar">
        <div class="x-sidebar__brand">
            <span class="x-sidebar__mark"></span>
            <span class="x-sidebar__name">{{ $appSetting->company_name ?? 'Xenon' }}</span>
        </div>
        <nav class="x-nav">
            <a class="x-nav__item {{ request()->routeIs('dashboard') ? 'is-active' : '' }}" href="{{ route('dashboard') }}">
                <x-ui.icon name="monitor" /> Dashboard
            </a>
            <a class="x-nav__item {{ request()->routeIs('orders.index') ? 'is-active' : '' }}" href="{{ route('orders.index') }}">
                <x-ui.icon name="receipt" /> Orders
            </a>
            <a class="x-nav__item {{ request()->routeIs('products.index') ? 'is-active' : '' }}" href="{{ route('products.index') }}">
                <x-ui.icon name="package" /> Products
            </a>
            {{-- settings.hub is created by Task 6, which has not landed yet.
                 Guard with Route::has() so this layout does not throw a
                 RouteNotFoundException before that task ships. --}}
            @if (Route::has('settings.hub'))
                <a class="x-nav__item {{ request()->routeIs('settings.hub') ? 'is-active' : '' }}" href="{{ route('settings.hub') }}">
                    <x-ui.icon name="shield" /> Settings
                </a>
            @endif
        </nav>
    </aside>

    <div class="x-main">
        <header class="x-topbar">
            <div class="x-topbar__crumb">@yield('breadcrumb')</div>
            <div class="x-theme" x-data="{ mode: (window.xenonTheme && window.xenonTheme.get()) || 'light' }">
                <button type="button" class="x-theme__btn" :class="mode === 'light' && 'is-on'" @click="mode='light'; window.xenonTheme.set('light')" aria-label="Light theme"><x-ui.icon name="sun" size="14" /></button>
                <button type="button" class="x-theme__btn" :class="mode === 'dark' && 'is-on'" @click="mode='dark'; window.xenonTheme.set('dark')" aria-label="Dark theme"><x-ui.icon name="moon" size="14" /></button>
                <button type="button" class="x-theme__btn" :class="mode === 'system' && 'is-on'" @click="mode='system'; window.xenonTheme.set('system')" aria-label="Match system theme"><x-ui.icon name="monitor" size="14" /></button>
            </div>
        </header>
        <main class="x-content">@yield('content')</main>
    </div>
</div>
@include('partials.confirm-modal')
</body>
</html>
